<?php

namespace App\Services\Wildberries;

use App\Enums\DictionaryTypes;
use App\Exceptions\BusinessException;
use App\Facades\SyncHelper;
use App\Models\Dictionary;
use App\Models\Integration;
use App\Models\MarketplaceAttributeValue;
use App\Models\MarketplaceProduct;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductVariation;
use App\Models\ProductVariationItem;
use App\Models\System\Attribute;
use App\Models\System\AttributeValue;
use App\Models\Warehouse;
use App\Notifications\Export\DuplicateNotVariationsError;
use App\Notifications\UserAlertNotification;
use App\Services\Shop\AttributeService;
use App\Services\Wildberries\Exceptions\TokenRequiredException;
use App\Traits\IntegrationHelper;
use App\Traits\MarketplaceProductHelper;
use App\Traits\ProductHelper;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use SystemCategory;

trait ExportHelper
{
    use MarketplaceProductHelper;
    use ProductHelper;
    use IntegrationHelper;

    protected string $marketPlace = 'wildberries';

    /**
     * @param Product $product
     * @param Dictionary $marketplaceCategory
     * @param MarketplaceProduct|null $marketplaceProduct
     * @param Integration $integration
     * @param array $productsToExport
     *
     * @return void
     */
    private function prepareProductToExport(
        Product $product,
        Dictionary $marketplaceCategory,
        MarketplaceProduct|null $marketplaceProduct,
        Integration $integration,
        array &$productsToExport
    ): void {
        $variations = $product->variations_active;

        if ($variations->count() === 0) {
            return;
        }

        $hasVariationAttributes    = ! empty($product->category->system_category->settings['variation_attributes']);
        $hasModificationAttributes = ! empty($product->category->system_category->settings['modification_attributes']);

        $wbAttributeIds       = $marketplaceCategory->settings['attributes'] ?? [];
        $attributeRequiredIds = $marketplaceCategory->settings['required_attributes'] ?? [];

        /** Коллекция характеристик товара */
        $productAttributes = collect();
        foreach ($product->attribute_values as $attribute) {
            $productAttribute = $productAttributes->where('id', $attribute->id)->first();
            /** Если характеристика уже была добавлена в коллекцию - добавим ей новые значения */
            if ( ! empty($productAttribute)) {
                $productAttributes = $productAttributes->filter(function ($item) use ($attribute) {
                    return $item['id'] !== $attribute->id;
                });

                $values = collect($productAttribute['value']);
                $values->push($attribute->pivot->value);
                $systemValues = collect($productAttribute['system_value_id']);
                $systemValues->push($attribute->pivot->system_value_id);
                $productAttribute['value']           = $values->toArray();
                $productAttribute['system_value_id'] = $systemValues->toArray();
                $productAttributes->push($productAttribute);
            } else {
                $productAttributes->push([
                    'id'              => $attribute->id,
                    'name'            => $attribute->name,
                    'value'           => $attribute->pivot->value,
                    'system_value_id' => $attribute->pivot->system_value_id,
                ]);
            }
        }

        /** Если у товара есть состав - нужно его обработать и отдать как характеристику */
        $compositions         = $product->compositions ?? [];
        $compositionAttribute = [];
        if ( ! empty($compositions)) {
            $compositionDictionary = Attribute::where([
                'settings->system_name' => 'composition'
            ])->firstOrFail();

            if ($compositionDictionary) {
                $compositionList = AttributeValue::where('attribute_id', $compositionDictionary->id)
                    ->pluck('title', 'id');

                if ( ! empty($compositionList)) {
                    usort($compositions, fn($a, $b) => $a['value'] < $b['value']);

                    foreach ($compositions as $composition) {
                        $compositionName = $compositionList[$composition['id']] ?? null;
                        if ( ! empty($compositionName)) {
                            $compositionAttribute[] = sprintf(
                                '%s %s%%',
                                \Str::lower($compositionName),
                                $composition['value']);
                        }
                    }
                }
            }
        }

        /**
         * Коллекция связей характеристик магазина и маркетплейса (кешируем на 1 минуту)
         * @var Collection $attributesMarketplace
         */
        $attributesMarketplace = \Cache::remember(
            'attributesMarketplace'.md5($product->user_id.$this->marketPlace),
            60,
            function () use ($product) {
                return SyncHelper::getAttributesToMarketPlace($this->marketPlace, $product->user_id);
            }
        );

        $wbAttributes = $wbAttributeIds ? Dictionary::where([
            'type'        => DictionaryTypes::ATTRIBUTE,
            'marketplace' => $this->marketPlace
        ])->whereIn('id', $wbAttributeIds)->get()->keyBy('id') : [];

        $productToExport = [];
        /** собираем вариации */
        foreach ($variations as $variation) {
            $variationProduct = [];

            $variationMarketplaceProduct = $this->getProductMarketplaceInfo($variation->id, $this->marketPlace,
                ProductVariation::PRICE_TYPE);

            $imtID = $variationMarketplaceProduct->data['imtID'] ?? null;

            /** если товар уже есть в wildberries */
            if ( ! empty($imtID)) {
                $variationProduct = [
                    'imtID' => $imtID,
                    'nmID'  => $variationMarketplaceProduct->data['nmID'],
                ];
            }

            $variationProduct = array_merge($variationProduct, [
                'vendorCode' => $variation->sku[$this->marketPlace] ?? $variation->vendor_code,
                'images'     => $variation->image_urls,
                'sizes'      => [],
            ]);

            /** Обрабатываем файлы видео, если есть */
            if ( ! empty($variation->files)) {
                $videos = [];
                foreach ($variation->file_urls as $file) {
                    if ( ! empty($file['path']) && $file['type'] === 'video') {
                        $videos[] = $file['path'];
                    }
                }

                $variationProduct['video'] = $videos;
            }

            $commonAttributes = collect([]);
            $attributes       = collect($productAttributes->toArray());

            /** добавление характеристик вариаций */
            if ($hasVariationAttributes) {
                $variationAttributes = $variation->getAttribute('attributes') ?? [];
                foreach ($variationAttributes as $variationAttributeId => $valueId) {
                    $variationAttribute = $attributesMarketplace->where('system_attribute_id', $variationAttributeId)
                        ->first();
                    if ($variationAttribute) {
                        $this->addOrReplaceAttribute($attributes, [
                            'id'              => (int)$variationAttribute['attribute_id'],
                            'name'            => $variationAttribute['name'] ?? '',
                            'value'           => $valueId,
                            'system_value_id' => is_array($valueId) ? $valueId : (int)$valueId,
                        ]);
                    }
                }
            }

            /** характеристики модификаций */
            $modificationAttributes    = $hasModificationAttributes ? $product->category->system_category->settings['modification_attributes'] : [];
            $modificationAttributesIds = [];
            foreach ($modificationAttributes as $modificationAttributeId) {
                $modificationAttribute = $attributesMarketplace->where('system_attribute_id', $modificationAttributeId)
                    ->first();
                if ( ! empty($modificationAttribute)) {
                    $modificationAttributesIds[] = $modificationAttribute['marketplace_attribute_id'];
                }
            }

            /** собираем общие характеристики, не хранящиеся в атрибутах */
            foreach ($this->getExcludedAttributes() as $attrName) {
                $attrValue = null;

                switch ($attrName) {
                    case WbAttributes::TITLE->value:
                        $attrValue = getProductMarketplaceTitle($product, $this->marketPlace);
                        break;
                    case WbAttributes::DESCRIPTION->value:
                        $attrValue = $product->description;
                        break;
                    case WbAttributes::KEYWORDS->value:
                        $attrValue = $product->meta_keywords;
                        break;
                    case WbAttributes::CATEGORY->value:
                        $mpKey               = \App\Facades\SystemCategory::getMarketPlaceIdKey($this->marketPlace);
                        $marketPlaceCategory = Dictionary::find($product->category->system_category->settings[$mpKey]);
                        if ($marketPlaceCategory) {
                            $attrValue = $marketPlaceCategory->value;
                        }
                        break;
                    case WbAttributes::COMPOSITION->value:
                        $attrValue = $compositionAttribute;
                        break;
                    case WbAttributes::COUNTRY->value:
                        $attrValue = $product->country?->title ?? null;
                        break;
                    case WbAttributes::WEIGHT->value:
                        $attrValue = $product->weight ? (int)$product->weight : null;
                        break;
                    case WbAttributes::WIDTH->value:
                        $attrValue = $product->width ? ceil((int)$product->width / 10) : null;
                        break;
                    case WbAttributes::HEIGHT->value:
                        $attrValue = $product->height ? ceil((int)$product->height / 10) : null;
                        break;
                    case WbAttributes::LENGTH->value:
                        $attrValue = $product->length ? ceil((int)$product->length / 10) : null;
                        break;
                    case WbAttributes::TNVED->value:
                        $attrValue = $product->settings['tnved'] ?? null;
                        break;
                }

                if ($attrValue !== null) {
                    if ( ! in_array($attrName, [
                        WbAttributes::WEIGHT->value, WbAttributes::WIDTH->value, WbAttributes::HEIGHT->value,
                        WbAttributes::LENGTH->value, WbAttributes::BRAND->value, WbAttributes::TNVED->value,
                        WbAttributes::COMPOSITION->value, WbAttributes::COUNTRY->value, WbAttributes::KEYWORDS->value,
                        WbAttributes::DESCRIPTION->value, WbAttributes::TITLE->value
                    ])) {
                        $attrValue = [$attrValue];
                    }

                    $commonAttributes->put($attrName, [$attrName => $attrValue]);
                }
            }

            /** атрибуты маркетплейса из справочника */
            foreach ($wbAttributes as $attribute) {
                /** wildberries Характеристики, которые не хранятся в атрибутах */
                if (in_array($attribute->external_id, $modificationAttributesIds)) {
                    continue;
                }

                $required                  = in_array($attribute->external_id, $attributeRequiredIds);
                $attributeRule             = $attribute->settings;
                $attributeRule['required'] = $required;
                $attributeRule['id']       = $attribute->external_id;

                $exportAttribute = $this->getAttributeObject(
                    $attributeRule,
                    $attributesMarketplace,
                    $attributes
                );

                if ($exportAttribute) {
                    $commonAttributes->put(array_key_first($exportAttribute), $exportAttribute);
                } elseif ($attributeRule['required']) {
                    $this->addIntegrationLog($integration, 'error',
                        [
                            'msg' => sprintf('%s:  %s - %s', $product->sku, $attribute->value,
                                trans('syncs.required_attribute'))
                        ]);

                    return;
                }
            }

            if ($hasModificationAttributes && $variation->items->count() === 0) {
                continue;
            }

            $variationProduct['characteristics'] = $commonAttributes->values()->toArray();

            if ($hasModificationAttributes) {
                foreach ($variation->items as $variationItem) {
                    $size                    = null;
                    $variationItemAttributes = collect($attributes->toArray());

                    $variationItemMarketplaceProduct = $this->getProductMarketplaceInfo($variationItem->id,
                        $this->marketPlace,
                        ProductVariationItem::PRICE_TYPE);

                    $modificationAttributes = $variationItem->getAttribute('attributes') ?? [];
                    foreach ($modificationAttributes as $modificationAttributeId => $valueId) {
                        $modificationAttribute = $attributesMarketplace->where('system_attribute_id',
                            $modificationAttributeId)
                            ->first();
                        if ($modificationAttribute) {
                            $this->addOrReplaceAttribute($variationItemAttributes, [
                                'id'              => (int)$modificationAttribute['attribute_id'],
                                'name'            => $modificationAttribute['name'] ?? '',
                                'value'           => $valueId,
                                'system_value_id' => (int)$valueId,
                            ]);
                        }
                    }

                    foreach ($wbAttributes as $attribute) {
                        if ( ! in_array($attribute->external_id, $modificationAttributesIds)) {
                            continue;
                        }

                        $required                  = in_array($attribute->external_id, $attributeRequiredIds);
                        $attributeRule             = $attribute->settings;
                        $attributeRule['required'] = $required;
                        $attributeRule['id']       = $attribute->external_id;

                        $exportAttribute = $this->getAttributeObject(
                            $attributeRule,
                            $attributesMarketplace,
                            $variationItemAttributes
                        );

                        if ($exportAttribute) {
                            $size = $exportAttribute[array_key_first($exportAttribute)];
                            $size = is_array($size) ? current($size) : $size;
                        } else {
                            if ($attributeRule['required']) {
                                $this->addIntegrationLog($integration, 'error',
                                    [
                                        'msg' => sprintf('%s:  %s - %s', $product->sku, $attribute->value,
                                            trans('syncs.required_attribute'))
                                    ]);

                                continue 2;
                            }
                        }
                    }

                    $price = $this->getProductVariationItemPriceByType($variationItem, $this->marketPlace, 'base',
                        $integration->price_list);

                    $barcodes = array_merge_unique(
                        $variationItemMarketplaceProduct->data['barcodes'] ?? [], [$variationItem->barcode]
                    );

                    $sizeProductData = [
                        'techSize' => (string)$size,
                        'skus'     => $barcodes,
                        'wbSize'   => (string)$size,
                        'price'    => $price,
                    ];

                    if ( ! empty($variationItemMarketplaceProduct->data['id']) && ! empty($marketplaceProduct)) {
                        $sizeProductData['chrtID'] = $variationItemMarketplaceProduct->data['id'];
                    }

                    $variationProduct['sizes'][] = $sizeProductData;
                }
            } else {
                $variationItem = $variation->items->first();

                if ($variationItem) {
                    $variationItemMarketplaceProduct = $this->getProductMarketplaceInfo($variationItem->id,
                        $this->marketPlace,
                        ProductVariationItem::PRICE_TYPE);

                    $barcodes = array_merge_unique(
                        $variationItemMarketplaceProduct->data['barcodes'] ?? [], [$variationItem->barcode]
                    );

                    $sizeProductData = [
                        'techSize' => $variationItem->value,
                        'skus'     => $barcodes,
                        'price'    => $price ?? 0,
                    ];

                    if ( ! empty($variationItemMarketplaceProduct->data['id']) && ! empty($marketplaceProduct)) {
                        $sizeProductData['chrtID'] = $variationItemMarketplaceProduct->data['id'];
                    }

                    $variationProduct['sizes'][] = $sizeProductData;
                }
            }

            $productsToExport[] = $variationProduct;
        }

        if (empty($marketplaceProduct) && ! empty($productToExport)) {
            $productsToExport[] = $productToExport;
        }
    }

    /**
     * Формирует объект атрибута для отправки
     *
     * @param array $attributeRule
     * @param Collection $attributesMarketplace
     * @param Collection $attributes
     *
     * @return array|null
     */
    private function getAttributeObject(
        array $attributeRule,
        Collection $attributesMarketplace,
        Collection $attributes
    ): ?array {
        $attributeObj = null;

        /** Ищем по коллекции связей характеристик нужную нам */
        $attributesSync = $attributesMarketplace->filter(function ($attribute) use ($attributeRule) {
            return $attribute['marketplace_attribute_id'] == $attributeRule['id'];
        });

        /** Если есть - будем использовать для сопоставления характеристик товара */
        if ($attributesSync->count() && $attributeRule['is_available']) {
            $attributeObj = [];
            $isInteger    = $attributeRule['charcType'] === 4;

            $attributeSync = $attributesSync->first();

            $systemAttribute = Attribute::find($attributeSync['system_attribute_id']);

            /** Ищем характеристики товара по id */
            $attributes = $attributes->filter(function ($item) use ($attributeSync) {
                return (int)$item['id'] === $attributeSync['attribute_id'];
            });

            $values = [];
            foreach ($attributes as $attribute) {
                /** Если для данной характеристики требуются значения из справочника - пытаемся найти в справочнике */
                if ( ! empty($attributeRule['dictionary_id']) || $systemAttribute->type === 'dictionary') {
                    /** У характеристики может быть указано как одно значение так и несколько */
                    $system_value_ids = is_array($attribute['system_value_id'])
                        ? array_map(fn($item) => (int)$item, $attribute['system_value_id'])
                        : [$attribute['system_value_id']];

                    $attributeValues = AttributeService::getService()->getSystemAttributeValuesById(
                        $system_value_ids, $this->marketPlace
                    );

                    if ( ! empty($attributeValues->count())) {
                        $count = 0;
                        foreach ($attributeValues as $attributeValue) {
                            /** Если значение не пустое и еще не достигли лимита на кол-во значений в характеристики */
                            if ( ! empty($attributeValue) && (empty($attributeRule['max_count']) || $count < $attributeRule['max_count'])) {
                                $values[] = $attributeValue->value;
                                $count++;
                            }
                        }
                    } elseif ($attributeRule['id'] == WbAttributes::BRAND->value && ! empty($attribute['value'])) {
                        $values[] = $attribute['value'];
                    }
                } elseif ( ! empty($attribute['value'])) {
                    $values[] = $isInteger ? intval($attribute['value']) : $attribute['value'];
                }
            }

            if ($values) {
                $attributeObj[$attributeSync['marketplace_attribute_id']] = $isInteger ? $values[0] : $values;
            }
        }

        if (empty($attributeObj)) {
            return null;
        }

        return $attributeObj;
    }

    /**
     * @param array $nomenclatureAddins
     * @param ProductVariation $variation
     *
     * @return Collection
     */
    private function setNomenclatureVariationMainColor(
        array $nomenclatureAddins,
        ProductVariation $variation
    ): Collection {
        $hasColor  = false;
        $mainColor = mb_strtolower($variation->vendor_code);
        $key       = SystemCategory::getMarketPlaceIdKey($this->marketPlace);

        /** Ищем в базе сопоставления для характеристики Основной цвет */
        $attribute = Dictionary::where([
                'marketplace' => $this->marketPlace,
                'value'       => 'Основной цвет',
                'type'        => DictionaryTypes::ATTRIBUTE,
            ]
        )->first();

        if ($attribute) {
            foreach ($attribute->system_attributes as $system_attribute) {
                /** Если нашли - пытаемся найти сопоставление для цвета вариации */
                $colorArr = Arr::first($system_attribute->attribute_values->toArray() ?? [],
                    function ($item) use ($variation) {
                        return mb_strtolower($item['title']) == mb_strtolower($variation->vendor_code);
                    });
            }
        }

        if ( ! empty($colorArr['settings'][$key])) {
            $marketplaceAttributeValue = MarketplaceAttributeValue::find((int)$colorArr['settings'][$key]);
            /** Если цвет найден - ставим его основным, иначе используем название цвета из вариации (может не принять) */

            if ($marketplaceAttributeValue) {
                $mainColor = $marketplaceAttributeValue->external_id;
            }
        }

        $nomenclatureAddins = collect($nomenclatureAddins)->map(function ($item) use ($mainColor, &$hasColor) {
            if ($item['type'] === 'Основной цвет') {
                $item['params'] = [
                    [
                        'value' => $mainColor
                    ]
                ];

                $hasColor = true;
            }

            return $item;
        });

        if ( ! $hasColor) {
            $nomenclatureAddins->push([
                'type'   => 'Основной цвет',
                'params' => [
                    [
                        'value' => $mainColor
                    ]
                ]
            ]);
        }

        return $nomenclatureAddins;
    }

    /**
     * Добавление или замена уже существующего атрибута в массиве атрибутов
     *
     * @param Collection $attributes
     * @param array $attribute
     *
     * @return void
     */
    private function addOrReplaceAttribute(Collection &$attributes, array $attribute)
    {
        $key = $attributes->search(function ($item) use ($attribute) {
            return $item['id'] === $attribute['id'];
        });

        if ($key) {
            $attributes = $attributes->replace([$key => $attribute]);
        } else {
            $attributes->push($attribute);
        }
    }

    /**
     * @param Collection $products
     * @param string $vendorCode
     * @param string $nmId
     * @param PriceList $price_list
     *
     * @return array|null
     */
    private function getNomenclatureWithPrice(
        Product $product,
        string $vendorCode,
        string $nmID,
        PriceList $price_list
    ): ?array {
        $productVariation = ProductVariation::where([
            'vendor_code' => $vendorCode, 'product_id' => $product->id
        ])->active()->first();

        if ($productVariation) {
            return [
                'nmID'  => (int)$nmID,
                'price' => $this->getProductVariationPriceByType($productVariation, $this->marketPlace,
                    'base',
                    $price_list)
            ];
        }

        return null;
    }

    /**
     * @param ProductGroup $productGroup
     * @param array $productsInGroup
     *
     * @return Product|null
     */
    private function prepareProductGroupToExport(ProductGroup $productGroup, array $productsInGroup = []): ?Product
    {
        $product = null;

        /** @var ?Product $mainProduct */
        $mainProduct = Arr::first($productsInGroup, function ($item) use ($productGroup) {
            return (int)$item['id'] === $productGroup->main_product;
        });

        $childProducts = Arr::where($productsInGroup, function ($item) use ($productGroup) {
            return (int)$item['id'] !== $productGroup->main_product;
        });

        if ( ! $mainProduct) {
            return null;
        }

        /** Если у главного товара есть вариации - в его АКТИВНЫЕ вариации добавляем активные вариации других товаров группы */
        if ($mainProduct->variations->count()) {
            /** @var Product $childProduct */
            foreach ($childProducts as $childProduct) {
                if ($childProduct->variations_active->count()) {
                    foreach ($childProduct->variations_active as $variation) {
                        $mainProduct->variations_active->push($variation);
                    }
                }
            }

            $product = $mainProduct;
        } else {
            /** Иначе уведомляем о том, что такие товары нельзя выгружать */
            $productGroup->user->notify(new DuplicateNotVariationsError($productGroup->sku));
        }

        return $product;
    }

    /**
     * @param Integration $integration
     * @param Collection $stocks
     *
     * @return void
     */
    private function updateStocks(Integration $integration, Collection $stocks): void
    {
        if ( ! $stocks->count()) {
            return;
        }

        $warehousesIds = getIntegrationExportSetting($integration, 'warehouses');
        $warehouses    = ! empty($warehousesIds) ? Warehouse::whereIn('warehouse_id', $warehousesIds)
            ->where(['user_id' => $integration->user_id, 'marketplace' => $this->marketPlace])->get() : collect([]);

        try {
            $api = new WbClient(getIntegrationSetting($integration, 'api_token'));

            /** Для каждого склада обновим остаток */
            foreach ($warehouses as $warehouse) {
                $warehouse_id = (int)$warehouse->warehouse_id;
                if ( ! empty($warehouse_id)) {
                    try {
                        $api->updateStocks($warehouse_id, $stocks->toArray());
                    } catch (BusinessException|RequestException $e) {
                        $integration->user->notify(
                            new UserAlertNotification(
                                trans('errors.error'),
                                trans('exports.errors.stocks_not_updated', ['marketplace' => $this->marketPlace]),
                                'danger'
                            )
                        );

                        logger()->error($e, ['marketplace' => $this->marketPlace]);
                    }
                }
            }
        } catch (TokenRequiredException $e) {
            $integration->user->notify(
                new UserAlertNotification(
                    trans('errors.error'),
                    $e->getUserMessage(),
                    'danger'
                )
            );
        }
    }

    /**
     * @param Integration $integration
     * @param Collection $prices
     *
     * @return void
     */
    private function updatePrices(Integration $integration, Collection $prices): void
    {
        if ( ! $prices->count()) {
            return;
        }

        try {
            $api = new WbClient(getIntegrationSetting($integration, 'api_token'));

            try {
                $api->updatePrices($prices->toArray());
            } catch (BusinessException|RequestException $e) {
                $integration->user->notify(
                    new UserAlertNotification(
                        trans('errors.error'),
                        trans('exports.errors.prices_not_updated', ['marketplace' => $this->marketPlace]),
                        'danger'
                    )
                );

                logger()->error($e, ['marketplace' => $this->marketPlace]);
            }
        } catch (TokenRequiredException $e) {
            $integration->user->notify(
                new UserAlertNotification(
                    trans('errors.error'),
                    $e->getUserMessage(),
                    'danger'
                )
            );
        }
    }
}
