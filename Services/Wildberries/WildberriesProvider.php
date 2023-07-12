<?php

namespace App\Services\Wildberries;

use App\Contracts\MarketPlace;
use App\DTO\ExportInfoDTO;
use App\Enums\DictionaryTypes;
use App\Enums\Import\ImportProductErrors;
use App\Enums\Import\ImportProductStatuses;
use App\Enums\Import\ImportTaskStatuses;
use App\Events\UserAlert;
use App\Exceptions\BusinessException;
use App\Facades\SyncHelper;
use App\Jobs\Export\ExportImagesToMarketplace;
use App\Jobs\Import\ImportGrouping;
use App\Jobs\ProductsInMarketplaces;
use App\Models\Dictionary;
use App\Models\ExportInfo;
use App\Models\Import\ImportProduct;
use App\Models\Import\ImportTask;
use App\Models\Integration;
use App\Models\MarketplaceAttributeValue;
use App\Models\MarketplaceProduct;
use App\Models\Orders\Order;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductVariation;
use App\Models\ProductVariationItem;
use App\Models\Supplies\Supply;
use App\Models\System\Attribute;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\Export\DuplicateSkuError;
use App\Notifications\Export\UserSetExportMainProduct;
use App\Notifications\UserAlertNotification;
use App\Services\Shop\CategoryService;
use App\Services\Shop\OrderService;
use App\Services\Shop\ProductService;
use App\Services\Shop\SupplyService;
use App\Services\Wildberries\Exceptions\ResponseException;
use App\Services\Wildberries\Exceptions\TokenRequiredException;
use App\Traits\ApiResponser;
use App\Traits\DictionaryHelper;
use App\Traits\ImportFromUrlTrait;
use App\Traits\IntegrationHelper;
use App\Traits\MarketplaceProductHelper;
use App\Traits\ProductHelper;
use App\Traits\ProductImportHelper;
use Cache;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Str;
use Throwable;

class WildberriesProvider implements MarketPlace
{
    use ExportHelper;
    use MarketplaceProductHelper;
    use ProductHelper;
    use DictionaryHelper;
    use ApiResponser;
    use ProductImportHelper;
    use ImportHelper;
    use ImportFromUrlTrait;
    use IntegrationHelper;

    protected string $marketPlace = 'wildberries';

    /**
     * @param Dictionary $category
     *
     * @return Collection
     */
    public function getCategoryAttributes(Dictionary $category): Collection
    {
        $attributeIds         = $category->settings['attributes'] ?? [];
        $attributeRequiredIds = $category->settings['required_attributes'] ?? [];

        $attributes = collect();

        if ($attributeIds) {
            $attributesArr = Dictionary::where([
                'type'        => DictionaryTypes::ATTRIBUTE,
                'marketplace' => $this->marketPlace
            ])->whereIn('id', $attributeIds)->get();

            foreach ($attributesArr as $attribute) {
                if (empty($attribute->settings['is_available']) || in_array($attribute->value,
                        $this->getExcludedAttributes())) {
                    continue;
                }

                $required = in_array($attribute->id, $attributeRequiredIds);

                $attribute->settings = array_merge($attribute->settings, ['required' => $required]);
                $attributes->push($attribute);
            }
        }

        return $attributes->sortByDesc(function ($item) { return (int)($item->settings['required'] ?? 0); });
    }

    /**
     * @param array $args
     *
     * @return Collection
     */
    public function getDictionaryValues(array $args): Collection
    {
        $values = collect();

        try {
            $cacheKey = sprintf('wb_attribute_values_%s_%s_%s', $args['dictionary'], $args['external'] ?? '',
                $args['pattern'] ?? '');
            $api      = new WbClient(config('exports.wb_token'));

            $result = Cache::remember($cacheKey, 3600, function () use ($api, $args) {
                return $api->getDictionary($args['dictionary'], $args['pattern'] ?? '', 5000);
            });

            foreach ($result as $item) {
                if (empty($item['name'])) {
                    continue;
                }

                $attributeValue = MarketplaceAttributeValue::firstOrCreate([
                    'marketplace' => $this->marketPlace,
                    'external_id' => $item['id'] ?? $item['name'],
                ], ['value' => $item['name']]);

                if ( ! empty($args['pattern'])
                    && ! stristr(mb_strtolower($item['translate']), mb_strtolower($args['pattern']))) {
                    continue;
                }

                $values->push([
                    'id'   => $attributeValue->id,
                    'name' => $attributeValue->value,
                ]);
            }
        } catch (TokenRequiredException $e) {
            logger()->critical($e);
        }

        return $values;
    }


    /**
     * @param array $productIds
     * @param Integration $integration
     *
     * @return void
     */
    public function exportProducts(array $productIds, Integration $integration): void
    {
        try {
            $api = new WbClient(getIntegrationSetting($integration, 'api_token'));
        } catch (BusinessException $e) {
            $integration->user->notify(
                new UserAlertNotification(
                    trans('exports.export_error'),
                    $e->getUserMessage(),
                    'danger'
                )
            );

            $this->addIntegrationLog($integration, 'error', ['msg' => $e->getUserMessage()]);

            return;
        }

        /** @var Collection $categoriesToMarketPlace */
        Auth::setUser($integration->user);
        $categoriesToMarketPlace = SyncHelper::getCategoriesToMarketPlace($this->marketPlace);
        if ( ! $categoriesToMarketPlace || count($categoriesToMarketPlace) === 0) {
            $integration->user->notify(
                new UserAlertNotification(
                    trans('exports.export_error'),
                    trans('exports.errors.categories_empty'),
                    'danger'
                )
            );

            $this->addIntegrationLog($integration, 'error', ['msg' => trans('exports.errors.categories_empty')]);

            return;
        }

        /**
         * @var Collection $marketplaceCategories
         */
        $marketplaceCategories = Cache::remember('marketplaceCategories'.$this->marketPlace, 3600,
            function () {
                return Dictionary::where([
                    'type'        => 'category',
                    'marketplace' => $this->marketPlace,
                ])->get();
            });

        $productsToExport = [];
        $productsToUpdate = [];

        $products = Product::active()->with('variations_active', 'variations')->find($productIds);

        $needGrupedProducts = getIntegrationExportSetting($integration, 'products_group_active', 0);
        $productGroups      = [];
        foreach ($products as $product) {
            $productGroups[$product->sku][] = $product;
        }

        $excludedProductIds = [];
        $productsGroupInDb  = ProductGroup::where(['user_id' => $integration->user_id])->get();

        foreach ($productGroups as $sku => $productsInGroup) {
            $countProductInGroup = count($productsInGroup);

            /** Если есть одинаковые товары - проверяем, установлен ли главный товар */
            if ($countProductInGroup > 1) {
                $excludedProducts = Arr::pluck($productsInGroup, 'id');
                $productGroupInDb = $productsGroupInDb->where('sku', $sku)->first();

                $products = $products->reject(function ($item) use ($excludedProducts) {
                    return in_array($item['id'], $excludedProducts);
                });

                $hasMainProduct = false;
                /** Если группа уже есть в базе - проверяем установлена ли у нее Главный товар  */
                if ($productGroupInDb) {
                    $hasMainProduct = (bool)$productGroupInDb->main_product;
                    $productGroupInDb->update(['products' => $excludedProducts]);
                } else {
                    /** Иначе создаем группу */
                    ProductGroup::create([
                        'sku'      => $sku,
                        'user_id'  => $integration->user_id,
                        'products' => $excludedProducts
                    ]);
                }

                /** Если у группы нет Главного товара - исключаем товары группы из выгрузки */
                if ( ! $hasMainProduct || ! $needGrupedProducts) {
                    $excludedProductIds = array_merge($excludedProductIds, $excludedProducts);
                    unset($productGroups[$sku]);
                }
            } else {
                unset($productGroups[$sku]);
            }
        }

        if ($needGrupedProducts) {
            if ($productsGroupInDb->count() && $productGroups) {
                foreach ($productGroups as $sku => $productsInGroup) {
                    $productGroupInDb = $productsGroupInDb->where('sku', $sku)->first();
                    if ($productGroupInDb) {
                        $groupToExport = $this->prepareProductGroupToExport($productGroupInDb, $productsInGroup);
                        if ($groupToExport) {
                            $products->push($groupToExport);
                        }
                    }
                }
            }
        }

        /** Если есть исключенные товары - уведомляем пользователя о необходимости назначить Главный товар */
        if ($excludedProductIds) {
            $needGrupedProducts
                ? $integration->user->notify(new UserSetExportMainProduct($excludedProductIds))
                : $integration->user->notify(new DuplicateSkuError($excludedProductIds));
        }

        foreach ($products as $product) {
            $productId = $product->id;
            if ( ! $product) {
                $this->addIntegrationLog($integration, 'error', [
                    'msg' => trans('exports.errors.product_not_found',
                        ['product' => route('products.edit', $productId)])
                ]);

                continue;
            }

            if ( ! $product->category || ! $categoriesToMarketPlace->has($product->category_id)) {
                $this->addIntegrationLog($integration, 'error', [
                    'msg' => trans('exports.errors.category_without_sync',
                        ['category' => $product->category->title ?? $productId])
                ]);
                continue;
            }

            if ($product->category->status !== 'published') {
                $this->addIntegrationLog($integration, 'error', [
                    'msg' => trans('exports.errors.category_unpublished',
                        ['category' => $product->category->title])
                ]);
                continue;
            }

            $marketplaceCategoryId = (int)$categoriesToMarketPlace->get($product->category_id)['id'];
            $marketplaceCategory   = $marketplaceCategories->filter(function ($item) use ($marketplaceCategoryId) {
                return (int)$item['id'] === $marketplaceCategoryId;
            })->first();


            if ( ! $marketplaceCategory) {
                $this->addIntegrationLog($integration, 'error', [
                    'msg' => trans('exports.errors.category_not_marketplace',
                        ['category' => $product->category->title])
                ]);
                continue;
            }

            $marketplaceProduct = $this->getProductMarketplaceInfo($product->id, $this->marketPlace);

            if ($marketplaceProduct) {
                $this->prepareProductToExport($product, $marketplaceCategory, $marketplaceProduct, $integration,
                    $productsToUpdate);
            } else {
                $this->prepareProductToExport($product, $marketplaceCategory, $marketplaceProduct, $integration,
                    $productsToExport);
            }
        }

        $successExportProducts = false;
        $successUpdateProducts = false;

        $productImages = [];

        /** Обновление номенклатур */
        if ($productsToUpdate) {
            foreach (collect($productsToUpdate)->sort()->chunk(100) as $chunkExport) {
                try {
                    $products = $chunkExport->values()->toArray();
                    $result   = $api->updateCard($products);

                    if (empty($result['error'])) {
                        $successUpdateProducts = true;

                        foreach ($products as $product) {
                            if ( ! empty($product['images'])) {
                                $productImages[$product['vendorCode']] = $product['images'];
                            }

                            if ( ! empty($product['video'])) {
                                $productImages[$product['vendorCode']] = array_merge(
                                    $productImages[$product['vendorCode']] ?? [], $product['video']
                                );
                            }
                        }

                        $this->addIntegrationLog($integration, 'success',
                            ['msg' => trans('exports.update_products_count', ['count' => count($productsToUpdate)])]);
                    } else {
                        $this->addIntegrationLog($integration, 'error', ['msg' => trans($result['error'])]);
                    }
                } catch (BusinessException $e) {
                    $this->addIntegrationLog($integration, 'error', ['msg' => trans($e->getUserMessage())]);
                } catch (Exception $e) {
                    $this->addIntegrationLog($integration, 'error', ['msg' => trans($e->getMessage())]);
                }
            }
        }

        /** добавление номенклатур к карточкам товоров */
        if ($productsToExport) {
            foreach (collect($productsToExport)->sort()->chunk(100) as $chunkExport) {
                try {
                    $products = $chunkExport->values()->toArray();
                    $result   = $api->createCard($products);

                    if (empty($result['error'])) {
                        $successExportProducts = true;

                        foreach ($products as $product) {
                            if ( ! empty($product['images'])) {
                                $productImages[$product['vendorCode']] = $product['images'];
                            }

                            if ( ! empty($product['video'])) {
                                $productImages[$product['vendorCode']] = array_merge(
                                    $productImages[$product['vendorCode']] ?? [], $product['video']
                                );
                            }
                        }

                        $this->addIntegrationLog($integration, 'success',
                            ['msg' => trans('exports.update_products_count', ['count' => count($productsToExport)])]);
                    } else {
                        $this->addIntegrationLog($integration, 'error', ['msg' => trans($result['error'])]);
                    }
                } catch (BusinessException $e) {
                    $this->addIntegrationLog($integration, 'error', ['msg' => trans($e->getUserMessage())]);
                } catch (Exception $e) {
                    $this->addIntegrationLog($integration, 'error', ['msg' => trans($e->getMessage())]);
                }
            }
        }

        /** Если нет товаров для обновления и создания */
        if ( ! $productsToExport && ! $successExportProducts && ! $successUpdateProducts) {
            $integration->user->notify(
                new UserAlertNotification(
                    trans('exports.export_error'),
                    trans('exports.errors.products_empty'),
                    'danger'
                )
            );

            $this->addIntegrationLog($integration, 'error', ['msg' => trans('exports.errors.products_empty')]);
        }

        if ($successExportProducts || $successUpdateProducts) {
            /** Загружаем фотографии */
            ExportImagesToMarketplace::dispatch($productImages, $integration)
                ->delay(now()->addMinutes(config('exports.delay_products_status')));

            /** Если экспорт был выполнен - нужно запросить с маркетплейса инфу с результатом выгрузки */
            ProductsInMarketplaces::dispatch($productIds, $integration)
                ->delay(now()->addMinutes(config('exports.delay_products_status')));
        }
    }

    /**
     * @param array $productIds
     * @param Integration $integration
     *
     * @return void
     */
    public function productsStatus(array $productIds, Integration $integration): void
    {
        Auth::setUser($integration->user);
        $products = Product::whereIn('id', $productIds)->get();

        try {
            $api = new WbClient(getIntegrationSetting($integration, 'api_token'));

            /** @var Product $product */
            foreach ($products as $product) {
                $vendorCodes = [$product->sku];
                if ( ! empty($product->variations->count())) {
                    $vendorCodes = array_merge($vendorCodes, $product->variations->pluck('vendor_code')->toArray());
                }

                /** Получаем загруженные товары */
                $nomenclatures = collect($api->getCardBySupplierVendorCode($vendorCodes));

                /** Получаем ошибки по этим товарам */
                $errors = collect($api->getErrorsBySupplierVendorCode($vendorCodes));

                /** Сохраняем информацию о вариациях */
                if ($product->variations->count()) {
                    /** @var ProductVariation $variation */
                    foreach ($product->variations as $variation) {
                        $errorData = $errors->get($variation->vendor_code);
                        /** Если есть ошибка - сохраняем инфу */
                        if ($errorData) {
                            $this->addIntegrationLog($integration, 'error', [
                                'msg' => sprintf('%s<br>%s', $variation->vendor_code,
                                    implode('<br>', $errorData['errors']))
                            ]);

                            $this->setProductMarketplace(
                                $variation->id,
                                $variation->barcode,
                                $integration->user_id,
                                $this->marketPlace,
                                [],
                                'error',
                                ProductVariation::PRICE_TYPE
                            );
                        } else {
                            $nomenclature = $nomenclatures->filter(function ($item) use ($variation) {
                                return $item['vendorCode'] == $variation->vendor_code;
                            })->first();

                            $this->setProductMarketplace(
                                $variation->id,
                                $variation->barcode,
                                $integration->user_id,
                                $this->marketPlace,
                                $this->prepareMarketplaceProductData($nomenclature, ProductVariation::PRICE_TYPE),
                                'success',
                                ProductVariation::PRICE_TYPE
                            );

                            if ( ! empty($variation->items) && count($nomenclature['sizes']) > 0) {
                                $sizes = collect($nomenclature['sizes']);
                                foreach ($variation->items as $variationItem) {
                                    $size = $sizes->first(function ($size) use ($variationItem) {
                                        return in_array($variationItem->barcode, $size['skus'] ?? []);
                                    });

                                    /** Если нашли размер - сохраняем инфу */
                                    if ( ! empty($size)) {
                                        $nomenclature['barcodes'] = $size['skus'];
                                        $nomenclature['chrtID']   = $size['chrtID'];
                                        $nomenclature['wbSize']   = $size['wbSize'];
                                        $nomenclature['techSize'] = $size['techSize'];

                                        $this->setProductMarketplace(
                                            $variationItem->id,
                                            $variationItem->barcode,
                                            $integration->user_id,
                                            $this->marketPlace,
                                            $this->prepareMarketplaceProductData(
                                                $nomenclature, ProductVariationItem::PRICE_TYPE
                                            ),
                                            'success',
                                            ProductVariationItem::PRICE_TYPE
                                        );
                                    }
                                }
                            }
                        }
                    }

                    if ($errors->count()) {
                        $this->setProductMarketplace(
                            $product->id,
                            $product->barcode,
                            $integration->user_id,
                            $this->marketPlace,
                            [],
                            'error',
                        );
                    } else {
                        $this->setProductMarketplace(
                            $product->id,
                            $product->barcode,
                            $integration->user_id,
                            $this->marketPlace
                        );
                    }
                }
            }
        } catch (BusinessException $e) {
            $this->addIntegrationLog($integration, 'error', ['msg' => $e->getUserMessage()]);
        }
    }


    /**
     * @param ExportInfo $exportInfo
     *
     * @return ExportInfoDTO
     * @throws \Spatie\DataTransferObject\Exceptions\UnknownProperties
     */
    public function exportStat(ExportInfo $exportInfo): ExportInfoDTO
    {
        return new ExportInfoDTO([]);
    }

    /**
     * @param array $productIds
     * @param Integration $integration
     *
     * @return void
     */
    public function productsUpdatePricesAndStocks(array $productIds, Integration $integration)
    {
        Auth::setUser($integration->user);

        $products            = Product::whereIn('id', $productIds)->where('user_id', $integration->user_id)->get();
        $marketPlaceProducts = $this->getProductMarketplaceInfos($productIds, $this->marketPlace);

        if ( ! $marketPlaceProducts->count()) {
            return;
        }

        $warehousesIds = getIntegrationExportSetting($integration, 'warehouses');
        $warehouses    = ! empty($warehousesIds) ? Warehouse::whereIn('warehouse_id', $warehousesIds)->where([
            'user_id'     => $integration->user_id,
            'marketplace' => $this->marketPlace
        ])->get() : collect([]);

        $prices = collect();
        $stocks = collect();

        /** @var MarketplaceProduct $marketPlaceProduct */
        foreach ($products as $product) {
            $marketPlaceProduct = $marketPlaceProducts->get($product->id);

            /**  Только если товар успешно выгружен в wb */
            if ( ! $marketPlaceProduct) {
                continue;
            }

            foreach ($product->variations as $variation) {
                $marketPlaceVariation = $this->getProductMarketplaceInfo(
                    $variation->id,
                    $this->marketPlace,
                    ProductVariation::PRICE_TYPE
                );

                /**  Только если вариация успешно выгружена в wb */
                if ( ! $marketPlaceVariation) {
                    continue;
                }

                /** Помечаем локально, что вариация на маркетплейсе включена */
                $marketPlaceVariation->fill([
                    'data' => mergeMixedValues($marketPlaceVariation->data, ['status' => 'published'])
                ]);
                $marketPlaceVariation->save();

                $nomenclatureWithPrice = $this->getNomenclatureWithPrice(
                    $product,
                    $variation->vendor_code,
                    $marketPlaceVariation->data['nmID'],
                    $integration->price_list
                );

                if ($nomenclatureWithPrice) {
                    $prices[] = $nomenclatureWithPrice;
                }

                /** Проходим по модификациям (размерам) каждой вариации */
                foreach ($variation->items as $variationItem) {
                    $marketPlaceVariationItem = $this->getProductMarketplaceInfo(
                        $variationItem->id,
                        $this->marketPlace,
                        ProductVariationItem::PRICE_TYPE
                    );

                    /**  Только если модификация успешно выгружена в wb */
                    if ( ! $marketPlaceVariationItem) {
                        continue;
                    }

                    /** Если есть штрихкоды - выбираем первый */
                    $barcode = is_array($marketPlaceVariationItem->data['barcodes']) && ! empty($marketPlaceVariationItem->data['barcodes'])
                        ? current($marketPlaceVariationItem->data['barcodes']) : null;

                    if ( ! $barcode) {
                        continue;
                    }

                    /** Для каждого склада обновим остаток */
                    foreach ($warehouses as $warehouse) {
                        $stocks[] = [
                            'sku'    => $barcode,
                            'amount' => $this->getProductVariationItemStock($variationItem,
                                $this->marketPlace, $integration->price_list, $warehouse->id),
                        ];
                    }
                }
            }
        }

        if ( ! $prices->count() && ! $stocks->count()) {
            return;
        }

        if ( ! empty(getIntegrationExportSetting($integration, 'update_prices'))) {
            foreach ($prices->chunk(1000) as $priceChunk) {
                $this->updatePrices($integration, $priceChunk);
            }
        }

        if ( ! empty(getIntegrationExportSetting($integration, 'update_stocks'))) {
            foreach ($stocks->chunk(1000) as $stockChunk) {
                $this->updateStocks($integration, $stockChunk);
            }
        }
    }

    /**
     * @param array $productIds
     * @param Integration $integration
     *
     * @return void
     */
    public function productsUnpublished(array $productIds, Integration $integration)
    {
        if (empty(getIntegrationExportSetting($integration, 'update_stocks', 0))) {
            return;
        }

        Auth::setUser($integration->user);

        $products            = Product::whereIn('id', $productIds)->where('user_id', $integration->user_id)->get();
        $marketPlaceProducts = $this->getProductMarketplaceInfos($productIds, $this->marketPlace);

        if ( ! $marketPlaceProducts->count()) {
            return;
        }

        $stocks = collect();

        foreach ($products as $product) {
            $marketPlaceProduct = $marketPlaceProducts->get($product->id);

            /**  Только если товар успешно выгружен в wb */
            if ( ! $marketPlaceProduct) {
                continue;
            }

            foreach ($product->variations as $variation) {
                $marketPlaceVariation = $this->getProductMarketplaceInfo(
                    $variation->id,
                    $this->marketPlace,
                    ProductVariation::PRICE_TYPE
                );

                /**  Только если вариация успешно выгружена в wb */
                if ( ! $marketPlaceVariation) {
                    continue;
                }

                /** Помечаем локально, что вариация на маркетплейсе включена */
                $marketPlaceVariation->fill([
                    'data' => mergeMixedValues($marketPlaceVariation->data, ['status' => 'unpublished'])
                ]);
                $marketPlaceVariation->save();

                /** Проходим по модификациям (размерам) каждой вариации */
                foreach ($variation->items as $variationItem) {
                    $marketPlaceVariationItem = $this->getProductMarketplaceInfo(
                        $variationItem->id,
                        $this->marketPlace,
                        ProductVariationItem::PRICE_TYPE
                    );

                    /**  Только если модификация успешно выгружена в wb */
                    if ( ! $marketPlaceVariationItem) {
                        continue;
                    }

                    /** Если есть штрихкоды - выбираем первый */
                    $barcode = is_array($marketPlaceVariationItem->data['barcodes']) && ! empty($marketPlaceVariationItem->data['barcodes'])
                        ? current($marketPlaceVariationItem->data['barcodes']) : null;

                    if ( ! $barcode) {
                        continue;
                    }

                    $stocks[] = [
                        'sku'    => $barcode,
                        'amount' => 0
                    ];
                }
            }
        }

        $this->updateStocks($integration, $stocks);
    }

    /**
     * Get user warehouses
     *
     * @param Integration $integration
     *
     * @return JsonResponse
     */
    public function getWarehouses(Integration $integration): JsonResponse
    {
        try {
            $warehouses = (new WbWarehouseService())->warehousesFromApi($integration);
        } catch (BusinessException $e) {
            return $this->errorResponse([[$e->getUserMessage()]]);
        }

        return $this->successResponse($warehouses);
    }

    /**
     * @param array $productVariationIds
     * @param Integration $integration
     *
     * @return void
     */
    public function productVariationsUnpublished(
        array $productVariationIds,
        Integration $integration
    ) {
        Auth::setUser($integration->user);

        $productVariations = ProductVariation::whereIn('id', $productVariationIds)
            ->byUserId($integration->user_id)
            ->get();

        $marketPlaceVariations = $this->getProductMarketplaceInfos(
            $productVariationIds,
            $this->marketPlace,
            ProductVariation::PRICE_TYPE
        );

        if ( ! $marketPlaceVariations->count()) {
            return;
        }

        $stocks = collect();

        foreach ($productVariations as $productVariation) {
            $marketPlaceVariation = $marketPlaceVariations->get($productVariation->id);

            /**  Только если вариация успешно выгружена в wb */
            if ( ! $marketPlaceVariation) {
                continue;
            }

            /** Помечаем локально, что вариация на маркетплейсе включена */
            $marketPlaceVariation->fill([
                'data' => mergeMixedValues($marketPlaceVariation->data, ['status' => 'unpublished'])
            ]);
            $marketPlaceVariation->save();

            /** Проходим по модификациям (размерам) каждой вариации */
            foreach ($productVariation->items as $variationItem) {
                $marketPlaceVariationItem = $this->getProductMarketplaceInfo(
                    $variationItem->id,
                    $this->marketPlace,
                    ProductVariationItem::PRICE_TYPE
                );

                /**  Только если модификация успешно выгружена в wb */
                if ( ! $marketPlaceVariationItem) {
                    continue;
                }

                /** Если есть штрихкоды - выбираем первый */
                $barcode = is_array($marketPlaceVariationItem->data['barcodes']) && ! empty($marketPlaceVariationItem->data['barcodes'])
                    ? current($marketPlaceVariationItem->data['barcodes']) : null;

                if ( ! $barcode) {
                    continue;
                }

                $stocks[] = [
                    'sku'    => $barcode,
                    'amount' => 0
                ];
            }
        }

        $this->updateStocks($integration, $stocks);
    }

    /**
     * @param array $productVariationIds
     * @param Integration $integration
     *
     * @return void
     */
    public function productVariationsUpdatePricesAndStocks(
        array $productVariationIds,
        Integration $integration
    ) {
        Auth::setUser($integration->user);

        $productVariations = ProductVariation::whereIn('id', $productVariationIds)
            ->byUserId($integration->user_id)
            ->get();

        $marketPlaceVariations = $this->getProductMarketplaceInfos(
            $productVariationIds,
            $this->marketPlace,
            ProductVariation::PRICE_TYPE
        );

        if ( ! $marketPlaceVariations->count()) {
            return;
        }

        $warehousesIds = getIntegrationExportSetting($integration, 'warehouses');
        $warehouses    = ! empty($warehousesIds) ? Warehouse::whereIn('warehouse_id', $warehousesIds)->where([
            'user_id' => $integration->user_id, 'marketplace' => $this->marketPlace
        ])->get() : collect([]);

        $prices = collect();
        $stocks = collect();

        foreach ($productVariations as $productVariation) {
            $marketPlaceVariation = $marketPlaceVariations->get($productVariation->id);

            /**  Только если вариация успешно выгружена в wb */
            if ( ! $marketPlaceVariation) {
                continue;
            }

            /** Помечаем локально, что вариация на маркетплейсе включена */
            $marketPlaceVariation->fill([
                'data' => mergeMixedValues($marketPlaceVariation->data, ['status' => 'published'])
            ]);
            $marketPlaceVariation->save();

            $nomenclatureWithPrice = $this->getNomenclatureWithPrice(
                $productVariation->product, $productVariation->vendor_code, $marketPlaceVariation->data['nmID'],
                $integration->price_list
            );

            if ($nomenclatureWithPrice) {
                $prices[] = $nomenclatureWithPrice;
            }

            /** Проходим по модификациям (размерам) каждой вариации */
            foreach ($productVariation->items as $variationItem) {
                $marketPlaceVariationItem = $this->getProductMarketplaceInfo(
                    $variationItem->id,
                    $this->marketPlace,
                    ProductVariationItem::PRICE_TYPE
                );

                /**  Только если модификация успешно выгружена в wb */
                if ( ! $marketPlaceVariationItem) {
                    continue;
                }

                /** Если есть штрихкоды - выбираем первый */
                $barcode = is_array($marketPlaceVariationItem->data['barcodes']) && ! empty($marketPlaceVariationItem->data['barcodes'])
                    ? current($marketPlaceVariationItem->data['barcodes']) : null;

                if ( ! $barcode) {
                    continue;
                }

                foreach ($warehouses as $warehouse) {
                    $stocks[] = [
                        'sku'    => $barcode,
                        'amount' => $this->getProductVariationItemStock($variationItem,
                            $this->marketPlace, $integration->price_list, $warehouse->id),
                    ];
                }
            }
        }

        if ( ! $prices->count() && ! $stocks->count()) {
            return;
        }

        if ( ! empty(getIntegrationExportSetting($integration, 'update_prices'))) {
            foreach ($prices->chunk(1000) as $priceChunk) {
                $this->updatePrices($integration, $priceChunk);
            }
        }

        if ( ! empty(getIntegrationExportSetting($integration, 'update_stocks'))) {
            foreach ($stocks->chunk(1000) as $stockChunk) {
                $this->updateStocks($integration, $stockChunk);
            }
        }
    }

    /**
     * @param Integration $integration
     *
     * @return int
     * @throws ResponseException
     * @throws TokenRequiredException
     * @throws HttpClientException
     * @throws BusinessException
     */
    public function checkConnection(Integration $integration)
    {
        try {
            $api = new WbClient(getIntegrationSetting($integration, 'api_token'));

            \Auth::login($integration->user);

            return $api->getProductsTotalCount();
        } catch (BusinessException $e) {
            $this->addIntegrationLog($integration, 'error', ['msg' => $e->getUserMessage()]);

            throw $e;
        }
    }

    /**
     * @param Integration $integration
     *
     * @return void
     */
    public function importProducts(Integration $integration)
    {
        try {
            $api = new WbClient(getIntegrationSetting($integration, 'api_token'));

            \Auth::login($integration->user);

            $importTask = ImportTask::create([
                'marketplace' => $this->marketPlace,
                'user_id'     => $integration->user_id
            ]);

            (new WbWarehouseService())->warehousesFromApi($integration);

            sleep(1);
            logger()->info(sprintf('Запущен импорт товаров из WB для пользователя %s', \auth()->id()));
            UserAlert::dispatch(auth()->user(), 'Получаем все товары из WB', 'top');

            $marketPlaceProducts = $api->getAllProducts();
            if ( ! empty($marketPlaceProducts)) {
                $importTask->update(['status' => ImportTaskStatuses::PROCESSING]);

                foreach ($marketPlaceProducts as $marketPlaceProduct) {
                    $gruppedBy = $marketPlaceProduct['card']['imtID'];
                    foreach ($marketPlaceProduct['card']['sizes'] ?? [] as $size) {
                        ImportProduct::create(
                            [
                                'import_task_id' => $importTask->id,
                                'uid'            => $marketPlaceProduct['vendorCode'],
                                'barcode'        => $size['skus'][0] ?? '',
                                'grupped_by'     => $gruppedBy,
                                'data'           => $marketPlaceProduct,
                                'additionalInfo' => $size,
                                'status'         => ImportProductStatuses::PENDING,
                            ]);
                    }
                }

                $countProducts = $importTask->products->count();
                logger()->info(sprintf('Получили из WB товаров: %s', $countProducts.PHP_EOL));
                UserAlert::dispatch(
                    auth()->user(),
                    sprintf('Получили из WB товаров: %s, начинаем обработку', $countProducts.PHP_EOL),
                    'top'
                );

                ImportGrouping::dispatch($importTask, $integration);
            } else {
                /** Если нет товаров - задачу закрываем */
                $importTask->update(['status' => ImportTaskStatuses::SUCCESS]);
            }
        } catch (TokenRequiredException $e) {
            $this->addIntegrationLog($integration, 'error', ['msg' => $e->getMessage()]);
        } catch (Throwable $e) {
            $this->addIntegrationLog(
                $integration,
                'error',
                ['msg' => 'Произошла ошибка с кодом WB001, сообщите пожалуйста в техподдержку']
            );

            logger()->critical($e);
        }
    }

    /**
     * @param array $importedData
     *
     * @return void
     */
    public function saveImportedProducts(array $importedData)
    {
        /** @var Collection $marketPlaceProducts */
        $importTask          = $importedData['importTask'];
        $marketPlaceProducts = $importedData['products'];
        $integration         = $importedData['integration'];

        if (empty($importTask) || ($importTask instanceof ImportTask === false)
            || empty($integration) || ($integration instanceof Integration === false)
            || empty($marketPlaceProducts->count())) {
            logger()->error('переданы не все данные для импорта: '.json_encode($importedData));

            return;
        }

        $categoryService = new CategoryService();

        \Auth::login($integration->user);

        $prices = collect();

        try {
            $api    = new WbClient(getIntegrationSetting($integration, 'api_token'));
            $prices = $api->getPrices()->keyBy('nmId');
        } catch (TokenRequiredException $e) {
            $this->addIntegrationLog($integration, 'error', ['msg' => $e->getMessage()]);
        } catch (Throwable $e) {
            $this->addIntegrationLog(
                $integration,
                'error',
                ['msg' => 'Произошла ошибка с кодом WB002, сообщите пожалуйста в техподдержку']
            );

            logger()->critical($e);
        }

        /** Подготавливаем товары для импорта */
        $created  = [];
        $updated  = [];
        $products = [];
        $errors   = [];

        $barcodes = $marketPlaceProducts->pluck('barcode')->unique()->toArray();

        /**
         * @var Collection $existProducts
         * @var Collection $existVariations
         * @var Collection $existVariationItems
         */
        [
            'existProducts'       => $existProducts,
            'existVariations'     => $existVariations,
            'existVariationItems' => $existVariationItems,
        ] = $this->getUserProductsByBarcodes($importTask->user_id, $barcodes);

        /** Если найдено несколько Главных товаров - значит у пользователя есть дубли, сохраняем ошибку */
        if ($existProducts->unique()->count() > 1) {
            /** @var ImportProduct $marketPlaceProduct */
            foreach ($marketPlaceProducts as $marketPlaceProduct) {
                $marketPlaceProduct->update([
                    'status' => ImportProductStatuses::FAILED,
                    'data'   => array_merge($marketPlaceProduct->data ?? [],
                        [
                            'errorCode'     => ImportProductErrors::DUPLICATED,
                            'errorProducts' => $existProducts->pluck('id')
                        ])
                ]);
            }

            return;
        }

        $mainProduct = $existProducts->count() ? $existProducts->first() : new Product();
        if ($mainProduct->exists) {
            $mainProduct->importData = [];
        }

        /** @var ImportProduct $marketPlaceProduct */
        foreach ($marketPlaceProducts as $marketPlaceProduct) {
            $marketPlaceProduct->update(['status' => ImportProductStatuses::PROCESSING]);
        }

        $category = $this->getDictionaryCategoryByMarketPlaceExternalId(
            $this->marketPlace, $marketPlaceProduct->data['object']
        );

        if ( ! $category || empty($category->settings['attributes'])) {
            logger()->critical("wildberries not found category {$marketPlaceProduct->data['object']}");

            return;
        }

        $wbAttributes = Dictionary::where([
            'marketplace' => $this->marketPlace, 'type' => DictionaryTypes::ATTRIBUTE
        ])->whereIn('id', $category->settings['attributes'])->get([
            'id', 'parent_id', 'value', 'settings'
        ])->toArray();

        /** @var ImportProduct $marketPlaceProduct */
        foreach ($marketPlaceProducts as $marketPlaceProduct) {
            /** Если у товара нет штрихкода - ставим статус Ошибка */
            if (empty($marketPlaceProduct->barcode)) {
                logger()->info("not found barcode for {$marketPlaceProduct->uid}");

                $marketPlaceProduct->update([
                    'status' => ImportProductStatuses::FAILED,
                    'data'   => array_merge($marketPlaceProduct->data ?? [],
                        ['errorCode' => ImportProductErrors::NO_BARCODE])
                ]);
                continue;
            }

            $marketPlaceProduct['price'] = $prices->get($marketPlaceProduct->data['nmID']);

            $product = $this->prepareForImportProduct($marketPlaceProduct, $integration, $wbAttributes);

            $products[] = $product;

            /** Если это Главный товар - установим ему importData */
            if ($product->barcode === $mainProduct->barcode) {
                $mainProduct->importData  = $product->importData;
                $mainProduct->category_id = $product->category_id;
                $mainProduct->country_id  = $product->country_id;
                $mainProduct->height      = $product->height;
                $mainProduct->width       = $product->width;
                $mainProduct->length      = $product->length;
                $mainProduct->weight      = $product->weight;
                $mainProduct->description = $product->description;
                $mainProduct->errors      = [];

                /** Если из WB пришел ТН ВЭД */
                if ( ! empty($product->settings['tnved'])) {
                    $oldSettings = $mainProduct->settings ?? [];
                    unset($oldSettings['tnvedWildberries']);

                    /** Если у товара был указан другой ТН ВЭД */
                    if ( ! empty($mainProduct->settings['tnved']) && $mainProduct->settings['tnved'] !== $product->settings['tnved']) {
                        $mainProduct->errors = array_merge_unique($mainProduct->errors ?? [], [
                            trans('products.errors.tnved_diff')
                        ]);

                        $mainProduct->settings = array_merge_unique($mainProduct->settings ?? [], [
                            'tnvedWildberries' => $product->settings['tnved']
                        ]);
                    } elseif (empty($mainProduct->settings['tnved'])) {
                        $mainProduct->settings = array_merge_unique($mainProduct->settings ?? [], [
                            'tnved' => $product->settings['tnved']
                        ]);
                    }
                }
            }

            /** Если не был выбран Главный товар - выбираем первый подходящий */
            if (empty($mainProduct->barcode)) {
                $mainProduct = $product;
            }
        }

        /** Если в группе были все ошибочные товары - пропускаем группу */
        if (empty($products) && empty($mainProduct->user_id)) {
            return;
        }

        $price_list_ids = PriceList::getPriceListsByIntegration($integration);
        /** Если в импорте указаны прайс-листы - привязываем товары */
        $priceLists = $price_list_ids ?
            PriceList::whereIn('id', $price_list_ids)->where('user_id', $integration->user_id)->get() : collect([]);

        /** Сортируем по barcode, чтобы всегда были одинаковые группы */
        usort($products, fn(Product $a, Product $b) => $a->barcode > $b->barcode);

        $variations = [];
        foreach ($products as $key => $product) {
            $systemCategorySettings = $categoryService->getProductSystemCategorySettings($product);

            if ( ! empty($systemCategorySettings['variation_attributes']) && empty($product->importData['variationAttributes'])) {
                $product->importData['variationAttributes'] = ['default' => 0];
                $this->addIntegrationLog($integration, 'error',
                    ['msg' => trans('imports.errors.no_variation_attributes', ['sku' => $product->sku])]);

                UserAlert::dispatch(auth()->user(),
                    trans('imports.errors.no_variation_attributes', ['sku' => $product->sku]), 'toast', 'danger');

                $importProduct = ImportProduct::whereBarcode($product->barcode)->whereImportTaskId($importTask->id)->first();
                $importProduct?->update([
                    'status' => ImportProductStatuses::FAILED,
                    'data'   => array_merge($importProduct->data ?? [],
                        ['errorCode' => ImportProductErrors::NO_VARIATION_ATTRIBUTES]
                    )
                ]);
                continue;
            }

            if ( ! empty($systemCategorySettings['modification_attributes'])
                && empty($product->importData['size']) && empty($product->importData['size_ru'])
            ) {
                $this->addIntegrationLog($integration, 'error',
                    ['msg' => trans('imports.errors.no_modification_attributes', ['sku' => $product->sku])]);

                UserAlert::dispatch(auth()->user(),
                    trans('imports.errors.no_modification_attributes', ['sku' => $product->sku]), 'toast',
                    'danger');

                $errors[$product->barcode][] = trans(
                    'imports.errors.no_modification_attributes', ['sku' => $product->sku]
                );

                ImportProduct::whereBarcode($product->barcode)->whereImportTaskId($importTask->id)->update([
                    'status' => ImportProductStatuses::WARNING,
                    'data'   => array_merge($marketPlaceProduct->data ?? [],
                        ['errorCode' => ImportProductErrors::NO_MODIFICATION_ATTRIBUTES]
                    )
                ]);
            }

            $variationKey = implode(';', $product->importData['variationAttributes']);
            $size         = $product->importData['size_ru'] ?? $product->importData['size'] ?? $key;

            $variations[$variationKey][$size] = $product;
        }

        unset($marketPlaceProducts, $products);

        $updateStocks        = [Product::PRICE_TYPE => [], ProductVariationItem::PRICE_TYPE => []];
        $need_update_product = (bool)getIntegrationImportSetting($integration, 'update_exists_products');

        /** Если товар существует и не включена настройка "Обновлять товары" - пропускаем товар */
        if ( ! $need_update_product && $mainProduct->exists) {
            return;
        }

        if ( ! empty($mainProduct->importData['compositions'])) {
            $compositions = $this->prepareCompositionsToImport($mainProduct->importData['compositions']);
            if ( ! empty($compositions)) {
                $mainProduct->compositions = $compositions;
            }
        }

        if ( ! $mainProduct->exists) {
            $mainProduct->titles = [['type' => $this->marketPlace, 'value' => $mainProduct->title]];
            $productInDb         = Product::create($mainProduct->attributesToArray());

            $created[] = $productInDb->barcode;
        } else {
            $productInDb = $mainProduct;

            $titles = [['type' => $this->marketPlace, 'value' => $mainProduct->title]];
            /** Проходим по сохраненным ранее названиям, если есть от других мп - сохраняем их */
            foreach ($productInDb->titles ?? [] as $title) {
                if ($title['type'] === $this->marketPlace) {
                    continue;
                }

                $titles[] = [
                    'type'  => $title['type'],
                    'value' => $title['value']
                ];
            }

            $mainProduct->title  = $productInDb->title;
            $mainProduct->titles = $titles;
            $productInDb->update($mainProduct->attributesToArray());

            $updated[] = $productInDb->barcode;
        }

        if ( ! $productInDb) {
            $this->addIntegrationLog($integration, 'error',
                ['msg' => trans('imports.errors.product_create_error', ['sku' => $productInDb->sku])]);

            UserAlert::dispatch(auth()->user(),
                trans('imports.errors.product_create_error', ['sku' => $productInDb->sku]),
                'toast', 'danger');

            return;
        }

        $systemCategorySettings = $categoryService->getProductSystemCategorySettings($mainProduct);
        $hasModifications       = ! empty($systemCategorySettings['modification_attributes']);

        $key = 0;
        foreach ($variations as $color => $sizeProducts) {
            $productVariation = null;
            /** @var Product $importedVariation */
            $importedVariation = current($sizeProducts);

            /**
             * Проходим по всем модификациям и пытаемся найти вариацию из существующих
             *
             * @var ProductVariation $productVariation
             * @var Product $sizeProduct
             */
            foreach ($sizeProducts as $sizeProduct) {
                $productVariation = $existVariations->first(function (ProductVariation $item) use ($sizeProduct) {
                    return $item->barcode === $sizeProduct->barcode;
                });

                if ( ! empty($productVariation)) {
                    $importedVariation = $sizeProduct;
                    break;
                }
            }

            /** Если не нашли вариацию, ищем модификации */
            if (empty($productVariation)) {
                foreach ($sizeProducts as $sizeProduct) {
                    /** @var ProductVariationItem $variationItem */
                    $variationItem = $existVariationItems->first(function (ProductVariationItem $item) use ($sizeProduct
                    ) {
                        return $item->barcode === $sizeProduct->barcode;
                    });

                    /** Если нашли - значит у нас есть и вариация */
                    if ( ! empty($variationItem)) {
                        $importedVariation = $sizeProduct;
                        $productVariation  = $variationItem->product_variation;
                        break;
                    }
                }
            }

            $isMain = $key === 0;

            /** Если у вариаиции есть ошибки - запишем их */
            if ( ! empty($productVariation->barcode) && ! empty($errors[$productVariation->barcode])) {
                $productVariation->errors = array_merge_unique(
                    $productVariation->errors ?? [], $errors[$productVariation->barcode]
                );
            }

            $files = [];
            foreach ($sizeProduct->importData['video'] as $video) {
                $files[] = [
                    'path' => $video,
                    'type' => 'video'
                ];
            }

            /** Если вариация не была найдена - создаем ее */
            if (empty($productVariation)) {
                $productVariation = new ProductVariation();
                $productVariation->fill([
                    'barcode'     => $sizeProduct->barcode,
                    'product_id'  => $productInDb->id,
                    'vendor_code' => $sizeProduct->importData['vendorCode'] ?? '',
                    'uuid'        => Str::uuid(),
                    'status'      => 'published',
                    'images'      => $sizeProduct->importData['images'],
                    'data'        => $isMain ? ['isMain' => true] : [],
                    'files'       => $files,
                ]);
            } elseif ( ! empty($need_update_product)) {
                $productVariation->fill([
                    'images' => $sizeProduct->importData['images'],
                    'files'  => $files,
                ]);
            }

            /** Сохраняем значение вариантообразующей характеристики */
            $productVariationAttributes = [];
            foreach ($systemCategorySettings['variation_attributes'] ?? [] as $variationAttributeId) {
                $systemAttribute = Attribute::find($variationAttributeId, ['settings']);
                $is_collection   = ! empty($systemAttribute->settings['is_collection']);
                $this->getVariationModificationAttributes(
                    $importedVariation, $variationAttributeId, $this->marketPlace, $productVariationAttributes,
                    $is_collection
                );
            }

            /** Для статистики товар был создан или обновлен */
            if (empty($hasModifications)) {
                if ($productVariation->exists) {
                    $updated[] = $productVariation->barcode;
                } else {
                    $created[] = $productVariation->barcode;
                }
            }

            $productVariation->fill(['attributes' => $productVariationAttributes]);
            $productVariation->save();

            $this->setProductMarketplace(
                $productVariation->id,
                $productVariation->barcode,
                $integration->user_id,
                $this->marketPlace,
                $this->prepareMarketplaceProductData($importedVariation->importData),
                'success',
                ProductVariation::PRICE_TYPE
            );

            $key++;

            $productVariationPrice = ['base' => null];

            if ($hasModifications) {
                /**
                 * @var  $size
                 * @var Product $product
                 */
                foreach ($sizeProducts as $size => $product) {
                    /** Ищем модификацию по штрихкоду */
                    /** @var ProductVariationItem $productVariationItem */
                    $productVariationItem = $existVariationItems->first(function (ProductVariationItem $item) use (
                        $product
                    ) {
                        return $item->barcode === $product->barcode;
                    });

                    $itemAttributes = [];
                    /** Сохраняем значения размерных характеристик */
                    foreach ($systemCategorySettings['modification_attributes'] ?? [] as $modificationAttributeId) {
                        $this->getVariationModificationAttributes(
                            $product, $modificationAttributeId, $this->marketPlace, $itemAttributes
                        );
                    }

                    /** Генерируем артикул для Озона */
                    $sku = $this->prepareSku(
                        $product->sku,
                        $product->user_id,
                        'ozon',
                        ProductVariationItem::PRICE_TYPE,
                        ($productVariation->attributes ?? []) + ($itemAttributes ?? [])
                    );

                    if ($sku === $product->sku) {
                        $sku = sprintf('%s_%s', $product->sku, $size);
                    }

                    /** Если не нашли - создаем новую */
                    if (empty($productVariationItem)) {
                        $productVariationItem = new ProductVariationItem();
                        $productVariationItem->fill([
                            'uuid'                 => Str::uuid(),
                            'product_variation_id' => $productVariation->id,
                            'barcode'              => $product->barcode,
                            'value'                => $size,
                            'settings'             => [
                                'sku' => $sku,
                            ]
                        ]);
                    } /** Если артикул Озона пустой - обновим его */
                    elseif (empty($productVariationItem->settings['sku'])) {
                        $productVariationItem->settings = array_merge($productVariationItem->settings ?? [], [
                            'sku' => $sku,
                        ]);
                    }

                    $productVariationItem->attributes = mergeMixedValues($productVariationItem->attributes ?? [],
                        $itemAttributes);

                    /** Для статистики товар был создан или обновлен */
                    if ($productVariationItem->exists) {
                        $updated[] = $productVariationItem->barcode;
                    } else {
                        $created[] = $productVariationItem->barcode;
                    }

                    $productVariationItem->save();

                    $this->setProductMarketplace(
                        $productVariationItem->id,
                        $productVariationItem->barcode,
                        $integration->user_id,
                        $this->marketPlace,
                        $this->prepareMarketplaceProductData($product->importData),
                        'success',
                        ProductVariationItem::PRICE_TYPE
                    );

                    $updateStocks[ProductVariationItem::PRICE_TYPE][] = $productVariationItem;

                    /** Обновляем цены у размера */
                    $basePrice = $product->importData['price']['price'] ?? null;
                    $price     = [
                        'base' => $basePrice
                    ];

                    $discount = $product->importData['price']['discount'] ?? null;
                    if ( ! empty($discount)) {
                        $price['presale']  = round($basePrice - ($basePrice / 100) * $discount);
                        $price['discount'] = $discount;
                    }

                    $this->savePriceListsAndPrices(
                        $productVariationItem,
                        [ProductVariationItem::PRICE_TYPE => $price],
                        $priceLists
                    );

                    $productVariationPrice = $price;
                }
            } else {
                $basePrice = $importedVariation->importData['price']['price'] ?? null;
                $price     = [
                    'base' => $basePrice
                ];

                $discount = $importedVariation->importData['price']['discount'] ?? null;
                if ( ! empty($discount)) {
                    $price['presale']  = round($basePrice - ($basePrice / 100) * $discount);
                    $price['discount'] = $discount;
                }

                $productVariationPrice = $price;
            }

            $updateStocks[ProductVariation::PRICE_TYPE][] = $productVariation;

            /** Обновляем цены у вариации */
            $this->savePriceListsAndPrices(
                $productVariation,
                [ProductVariation::PRICE_TYPE => $productVariationPrice],
                $priceLists
            );
        }

        $mainProduct->id = $productInDb->id;
        $this->saveProductAdditionalData($integration, $mainProduct, $priceLists);

        ProductService::getService()->checkErrors($mainProduct, false);
        ProductService::getService()->syncSystemAttributesWithUserAttributes($mainProduct);

        $updateStocks[Product::PRICE_TYPE][] = $productInDb;

        /** Обновляем остатки у товаров */
        if ( ! empty($updateStocks[Product::PRICE_TYPE])) {
            $this->updateProductStocksFromMarketplace($integration, $updateStocks[Product::PRICE_TYPE], $priceLists);
        }

        /** Обновляем остатки у вариаций */
        if ( ! empty($updateStocks[ProductVariation::PRICE_TYPE])) {
            $this->updateProductStocksFromMarketplace($integration, $updateStocks[ProductVariation::PRICE_TYPE],
                $priceLists);
        }

        /** Обновляем остатки у размеров */
        if ( ! empty($updateStocks[ProductVariationItem::PRICE_TYPE])) {
            $this->updateProductStocksFromMarketplace($integration, $updateStocks[ProductVariationItem::PRICE_TYPE],
                $priceLists);
        }

        logger()->info(sprintf('Сохранили группу товаров %s', $mainProduct->sku));

        UserAlert::dispatch(auth()->user(),
            sprintf('Импорт из wildberries: сохранили товар %s', $mainProduct->sku),
            'line', 'secondary');

        /** Записываем статус у товаров Создано или Обновлено для статистики */
        ImportProduct::whereIn('barcode',
            $created)->whereImportTaskId($importTask->id)->update(['status' => ImportProductStatuses::CREATED]);
        ImportProduct::whereIn('barcode',
            $updated)->whereImportTaskId($importTask->id)->update(['status' => ImportProductStatuses::UPDATED]);

        /** Подчищаем за собой */
        unset($products, $product, $productInDb, $mainProduct, $price_list_ids, $priceLists, $variations, $color, $created, $updated, $current, $productVariation, $productVariationAttributes, $productVariationItem, $sizeProducts, $size, $systemCategorySettings, $importedVariation, $itemAttributes, $integration, $importTask, $importedData, $existVariationItems, $existVariations, $existProducts);

        gc_collect_cycles();
    }

    public function importMarketplaceAttributes()
    {
        // TODO: Implement importMarketplaceAttributes() method.
    }

    /**
     * Метод получает заказа из мп за последние 24 часа и сохраняет только те, которых еще нет в базе
     *
     * @param int $user_id
     * @param array $keyData
     *
     * @return void
     */
    public function getLastOrders(int $user_id, array $keyData): void
    {
        try {
            $user = User::find($user_id);
            if (empty($user) || empty($keyData['api_token'])) {
                return;
            }

            $api = new WbClient($keyData['api_token']);

            /** Получаем заказы за последние 7 дней */
            $orders        = $api->getOrders(Carbon::now()->modify('-7 DAYS')->unix());
            $orderStatuses = [];

            if ( ! empty($orders)) {
                $orderIds = collect($orders)->filter(fn($item) => ! empty($item['id']))
                    ->map(fn($item) => (int)$item['id'])->all();
                if ( ! empty($orderIds)) {
                    /** Получаем все статусы заказов (не больше 1000 за раз) */
                    foreach (collect($orderIds)->chunk(500) as $ordersChunk) {
                        $orderIdsSorted = $ordersChunk->all();
                        sort($orderIdsSorted);
                        $chunkStatuses = collect($api->getOrderStatuses($orderIdsSorted));
                        foreach ($chunkStatuses as $chunkStatus) {
                            $orderStatuses[$chunkStatus['id']] = $chunkStatus;
                        }
                    }
                }
            }

            $this->saveOrders($user->id, $orders, $orderStatuses);
        } catch (Exception $e) {
            logger()->critical($e);
        }
    }

    /**
     * @param int $user_id
     *
     * @return string|null
     * @throws HttpClientException
     * @throws ResponseException
     * @throws TokenRequiredException
     */
    public function openSupply(int $user_id): ?string
    {
        try {
            $integration = Integration::where([
                'user_id' => $user_id,
                'type'    => $this->marketPlace
            ])->active()->firstOrFail();

            $api = new WbClient(getIntegrationSetting($integration, 'api_token'));

            return $api->openSupply();
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function closeSupply(Supply $supply)
    {
        try {
            $integration = Integration::where([
                'user_id' => $supply->user_id,
                'type'    => $supply->marketplace
            ])->active()->firstOrFail();

            $api = new WbClient(getIntegrationSetting($integration, 'api_token'));
            $api->deleteSupply($supply->marketplace_uid);
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * @param int $user_id
     * @param array $keyData
     *
     * @return void
     */
    public function getSupplies(int $user_id, array $keyData)
    {
        try {
            $user = User::find($user_id);
            if (empty($user) || empty($keyData['api_token'])) {
                return;
            }

            $supplyService = new SupplyService();

            $api      = new WbClient($keyData['api_token']);
            $supplies = $api->getSupplies();
            logger()->info(sprintf('получили поставок: %s', count($supplies)));

            foreach ($supplies as $supply) {
                $created = Carbon::createFromTimeString($supply['createdAt'], 'UTC');

                /** Если поставке больше 6 месяцев - пропустим ее */
                if ($created < Carbon::now()->subMonths(6)) {
                    continue;
                }

                logger()->info(sprintf('получаем заказы поставки: %s', $supply['id']));
                $orders        = $api->getSupplyOrders($supply['id']);
                $orderStatuses = [];

                if ( ! empty($orders)) {
                    $orderIds = array_map(fn($item) => $item['id'], $orders);
                    /** Получаем все статусы заказов (не больше 1000 за раз) */
                    foreach (collect($orderIds)->chunk(1000) as $ordersChunk) {
                        $chunkStatuses = collect($api->getOrderStatuses($ordersChunk->all()));
                        foreach ($chunkStatuses as $chunkStatus) {
                            $orderStatuses[$chunkStatus['id']] = $chunkStatus;
                        }
                    }
                }
                $this->saveOrders($user->id, $orders, $orderStatuses);

                $supplyService->saveSupplyWithOrders($user->id, $supply, $orders);
                logger()->info(sprintf('сохранили поставку: %s', $supply['id']));
            }
        } catch (Throwable $e) {
            logger()->critical($e);
        }

        gc_collect_cycles();
    }

    /**
     * Обновляем статусы "зависших" заказов
     *
     * @param int $user_id
     * @param array $keyData
     *
     * @return void
     */
    public function updateOrderStatuses(int $user_id, array $keyData): void
    {
        try {
            $orderService = new OrderService();

            $user = User::find($user_id);
            if (empty($user) || empty($keyData['api_token'])) {
                return;
            }

            $orderIds = Order::where([
                'marketplace' => $this->marketPlace,
                'user_id'     => $user_id,
            ])->where(function (Builder $query) {
                return $query->whereNotIn('status', ['cancel'])
                    ->whereNotIn('additional_data->wbStatus', ['sold', 'canceled_by_client']);
            })->pluck('order_uid');

            $api           = new WbClient($keyData['api_token']);
            $orderStatuses = [];

            if ( ! empty($orderIds)) {
                $orderIds = $orderIds->map(fn($item) => (int)$item);
                /** Получаем все статусы заказов (не больше 1000 за раз) */
                foreach ($orderIds->chunk(1000) as $ordersChunk) {
                    $chunkStatuses = collect($api->getOrderStatuses($ordersChunk->all()));
                    foreach ($chunkStatuses as $chunkStatus) {
                        $orderStatuses[$chunkStatus['id']] = $chunkStatus;
                    }
                }
            }

            foreach ($orderStatuses as $orderStatus) {
                $order = Order::where([
                    'order_uid'   => $orderStatus['id'],
                    'marketplace' => $this->marketPlace,
                    'user_id'     => $user_id
                ])->first();

                /** Если поменялся статус заказа - обновляем */
                if ( ! empty($orderStatus['supplierStatus']) && ! empty($orderStatus['wbStatus']) &&
                    (
                        $orderStatus['supplierStatus'] !== $order->status
                        || $orderStatus['wbStatus'] !== $order->additional_data['wbStatus']
                    )
                ) {
                    $order->status          = $orderStatus['supplierStatus'];
                    $order->additional_data = array_merge(
                        $order->additional_data ?? [], ['wbStatus' => $orderStatus['wbStatus']]
                    );

                    $order->save();
                    $orderService->addOrderHistory($order);
                }
            }
        } catch (Exception $e) {
            logger()->critical($e);
        }
    }

    /**
     * Обновление цен для определенных товаров (вариаций)
     *
     * @param array $productIds
     * @param string $priceType
     * @param Integration $integration
     *
     * @return void
     */
    public function productsUpdatePrices(array $productIds, string $priceType, Integration $integration)
    {
        /** В wildberries можно обновить цены только у вариаций (номенклатур) */
        if ($priceType !== ProductVariation::PRICE_TYPE) {
            return;
        }

        $prices   = collect();
        $products = $this->getUserProductsByTypeAndIds($integration->user_id, $priceType, $productIds);

        $marketPlaceProducts = $this->getProductMarketplaceInfos($productIds, $this->marketPlace, $priceType);

        if ( ! $marketPlaceProducts->count()) {
            return;
        }

        /** @var ProductVariation[] $products */
        foreach ($products as $product) {
            /** @var MarketplaceProduct $marketPlaceProduct */
            $marketPlaceProduct = $marketPlaceProducts->get($product->id);

            /**  Только если товар успешно выгружен в wb */
            if ( ! $marketPlaceProduct) {
                continue;
            }

            $nomenclatureWithPrice = $this->getNomenclatureWithPrice(
                $product->product,
                $product->vendor_code,
                $marketPlaceProduct->data['nmID'],
                $integration->price_list
            );

            if ($nomenclatureWithPrice) {
                $prices[] = $nomenclatureWithPrice;
            }
        }

        if ( ! empty(getIntegrationExportSetting($integration, 'update_prices')) && $prices->count() > 0) {
            foreach ($prices->chunk(1000) as $priceChunk) {
                $this->updatePrices($integration, $priceChunk);
            }
        }
    }

    /**
     * Обновление остатков для определенных товаров (модификаций)
     *
     * @param array $productIds
     * @param string $priceType
     * @param Integration $integration
     *
     * @return void
     */
    public function productsUpdateStocks(array $productIds, string $priceType, Integration $integration)
    {
        /** В wildberries можно обновить остатки только у модификаций */
        if ($priceType !== ProductVariationItem::PRICE_TYPE) {
            return;
        }

        $stocks = collect();

        $products            = $this->getUserProductsByTypeAndIds($integration->user_id, $priceType, $productIds);
        $marketPlaceProducts = $this->getProductMarketplaceInfos($productIds, $this->marketPlace, $priceType);

        if ( ! $marketPlaceProducts->count()) {
            return;
        }

        $warehousesIds = getIntegrationExportSetting($integration, 'warehouses');
        $warehouses    = ! empty($warehousesIds) ? Warehouse::whereIn('warehouse_id', $warehousesIds)->where([
            'user_id'     => $integration->user_id,
            'marketplace' => $this->marketPlace
        ])->get() : collect([]);

        if (empty($warehouses)) {
            return;
        }

        /** @var MarketplaceProduct $marketPlaceProduct */
        foreach ($products as $product) {
            $marketPlaceProduct = $marketPlaceProducts->get($product->id);

            /**  Только если товар успешно выгружен в wb */
            if ( ! $marketPlaceProduct) {
                continue;
            }

            /** Если есть штрихкоды - выбираем первый */
            $barcode = is_array($marketPlaceProduct->data['barcodes']) && ! empty($marketPlaceProduct->data['barcodes'])
                ? current($marketPlaceProduct->data['barcodes']) : null;

            if ( ! $barcode) {
                continue;
            }

            /** Для каждого склада обновим остаток */
            foreach ($warehouses as $warehouse) {
                $stocks[] = [
                    'sku'    => $barcode,
                    'amount' => $this->getProductStock(
                        $product, $this->marketPlace, $integration->price_list, $warehouse->id, $priceType
                    ),
                ];
            }
        }

        if ( ! empty(getIntegrationExportSetting($integration, 'update_stocks')) && $stocks->count() > 0) {
            foreach ($stocks->chunk(1000) as $stockChunk) {
                $this->updateStocks($integration, $stockChunk);
            }
        }
    }

    public function exportProductImages(array $imagesData, Integration $integration)
    {
        try {
            $api = new WbClient(getIntegrationSetting($integration, 'api_token'));

            foreach ($imagesData as $vendorCode => $images) {
                if ( ! empty($images)) {
                    $api->mediaSave(['vendorCode' => $vendorCode, 'data' => $images]);
                }
            }
        } catch (BusinessException $e) {
            $integration->user->notify(
                new UserAlertNotification(
                    trans('exports.export_error'),
                    $e->getUserMessage(),
                    'danger'
                )
            );

            $this->addIntegrationLog($integration, 'error', ['msg' => $e->getUserMessage()]);
        } catch (HttpClientException $e) {
            $integration->user->notify(
                new UserAlertNotification(
                    trans('exports.export_error'),
                    $e->getMessage(),
                    'danger'
                )
            );

            $this->addIntegrationLog($integration, 'error', ['msg' => $e->getMessage()]);
        }
    }
}
