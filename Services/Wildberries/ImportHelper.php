<?php

namespace App\Services\Wildberries;

use App\Enums\DictionaryTypes;
use App\Exceptions\BusinessException;
use App\Facades\SystemCategory;
use App\Models\Attribute;
use App\Models\Currency;
use App\Models\Import\ImportProduct;
use App\Models\Integration;
use App\Models\MarketplaceAttributeValue;
use App\Models\MarketplaceProduct;
use App\Models\Orders\Order;
use App\Models\Orders\OrderTotal;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductStock;
use App\Models\ProductVariation;
use App\Models\ProductVariationItem;
use App\Models\Synonym;
use App\Models\System\AttributeValue;
use App\Models\SystemAttributesMarketplace;
use App\Models\Warehouse;
use App\Services\Shop\AttributeService;
use App\Services\Shop\OrderService;
use App\Traits\CategoryHelper;
use Arr;
use Auth;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Str;

trait ImportHelper
{
    protected string $marketPlace = 'wildberries';

    use CategoryHelper;
    use ExportHelper;

    /**
     * Подготовка товара из импорта для последующего добавления в БД
     *
     * @param ImportProduct $productData
     * @param Integration $integration
     * @param array|null $wbAttributes
     *
     * @return Product
     */
    private function prepareForImportProduct(
        ImportProduct $productData,
        Integration $integration,
        ?array $wbAttributes
    ): Product {
        Auth::login($integration->user);

        $product          = new Product();
        $product->user_id = $integration->user_id;
        $product->barcode = $productData->barcode;
        $product->sku     = $productData->data['vendorCode'];

        /** Получаем категорию */
        $category = $this->getUserCategoryByExternalIdAndMarketPlace(
            $this->marketPlace,
            $productData->data['object'] ?? '',
            $integration->user_id,
            $product->sku
        );

        $product->category_id = $category?->id;

        $attributeService = new AttributeService();

        /** Получаем связи пользовательских характеристик с системными */
        $userAttributesWithSync = $attributeService->getUserAttributesWithSync($this->marketPlace,
            $integration->user_id);

        /** Получаем id характеристик маркетплейса с их external_id */
        $marketplaceAttributesId = $attributeService->getMarketplaceAttributesId($this->marketPlace);

        $attributes = [];

        $characteristics = collect($productData->data['card']['characteristics'] ?? []);

        $product->title         = $this->getValueFromCharacteristics($characteristics, WbAttributes::TITLE->value,
            $productData->data['vendorCode']);
        $product->description   = $this->getValueFromCharacteristics($characteristics, WbAttributes::DESCRIPTION->value,
            '');
        $product->meta_keywords = $this->getValueFromCharacteristics($characteristics, WbAttributes::KEYWORDS->value,
            '');
        $product->weight        = $this->getValueFromCharacteristics($characteristics, WbAttributes::WEIGHT->value, 0);
        $product->width         = (int)$this->getValueFromCharacteristics($characteristics, WbAttributes::WIDTH->value,
                0) * 10;
        $product->height        = (int)$this->getValueFromCharacteristics($characteristics, WbAttributes::HEIGHT->value,
                0) * 10;
        $product->length        = (int)$this->getValueFromCharacteristics($characteristics, WbAttributes::LENGTH->value,
                0) * 10;

        $countryCharacteristic = $this->getValueFromCharacteristics($characteristics, WbAttributes::COUNTRY->value, '');
        $country               = ! empty($countryCharacteristic)
            ? $this->getProductCountryByTitle($this->getAttributeValue(is_array($countryCharacteristic) ? $countryCharacteristic : [$countryCharacteristic]))
            : null;
        $product->country_id   = $country?->id ?? null;

        $tnved = $this->getValueFromCharacteristics($characteristics, WbAttributes::TNVED->value);
        if ( ! empty($tnved)) {
            $tnved             = is_array($tnved) ? current($tnved) : $tnved;
            $product->settings = array_merge($product->settings ?? [], ['tnved' => $tnved]);
        }

        $compositions = $this->getValueFromCharacteristics($characteristics, WbAttributes::COMPOSITION->value, []);

        /** Если нет размеров в характеристиках, то добавляем его туда из массива размеров */
        if ( ! $this->getValueFromCharacteristics($characteristics, WbAttributes::SIZE->value)) {
            $characteristics->add([WbAttributes::SIZE->value => [$productData->additionalInfo['techSize']]]);
        }
        if ( ! $this->getValueFromCharacteristics($characteristics, WbAttributes::SIZE_RU->value)) {
            $characteristics->add([WbAttributes::SIZE_RU->value => [$productData->additionalInfo['wbSize']]]);
        }
        if ( ! $this->getValueFromCharacteristics($characteristics, WbAttributes::SIZE_RU_FULL->value)) {
            $characteristics->add([WbAttributes::SIZE_RU_FULL->value => [$productData->additionalInfo['wbSize']]]);
        }

        $size    = $this->getValueFromCharacteristics($characteristics, WbAttributes::SIZE->value);
        $size_ru = $this->getValueFromCharacteristics(
            $characteristics,
            WbAttributes::SIZE_RU->value,
            $this->getValueFromCharacteristics($characteristics, WbAttributes::SIZE_RU_FULL->value)
        );

        $images = [];
        $videos = [];
        foreach ($productData->data['mediaFiles'] as $mediaFile) {
            if (is_video_file($mediaFile)) {
                $videos[] = $mediaFile;
            } else {
                $images[] = $mediaFile;
            }
        }

        /** Если товар еще не был создан - главную картинку */
        if (empty($product->image)) {
            $mediaFiles = $images;
            if ( ! empty($mediaFiles)) {
                $product->image = array_shift($mediaFiles);
            } else {
                $product->image = '';
            }
        }

        $variationAttributeIds = [
            WbAttributes::COLOR->value
        ];

        /** Если у категории указана вариантообразующая хар-ка - используем ее для группировки товаров, как цвет */
        foreach ($category->system_category->settings['variation_attributes'] ?? [] as $variationAttributeId) {
            $variationAttribute = \Illuminate\Support\Arr::first($userAttributesWithSync,
                function ($item) use ($variationAttributeId) {
                    return (int)$item['system_attribute_id'] === (int)$variationAttributeId;
                });

            if ( ! empty($variationAttribute)) {
                $variationAttributeIds[] = $variationAttribute['marketplace_attribute_id'];
            }
        }

        $variationAttributeIds  = array_unique($variationAttributeIds);
        $dictionaryAttributeIds = [];
        $variationAttributes    = [];

        $gruppedBy = null;

        foreach ($wbAttributes as $wbAttribute) {
            $isDictionary = ! empty($wbAttribute['settings']['dictionary_id']);

            if ($isDictionary) {
                $dictionaryAttributeIds[] = $wbAttribute['value'];
            }

            if (in_array($wbAttribute['value'], $variationAttributeIds)) {
                $attr      = $this->getAttributeByTitle($characteristics, $wbAttribute);
                $attrValue = $this->getAttributeValue($attr, $isDictionary);
                if ( ! empty($attrValue)) {
                    $variationAttributes[$wbAttribute['value']] = $attrValue;
                }
            }
        }

        /** Проходим по хар-кам и сохраняем значения, если их нет в базе */
        foreach ($characteristics as $characteristic) {
            $attributeName = array_key_first($characteristic);
            if (empty($attributeName)) {
                continue;
            }

            $isDictionary = in_array($attributeName, $dictionaryAttributeIds);
            $values       = (array)$characteristic[$attributeName];

            if ( ! in_array($attributeName, $this->getExcludedAttributes())) {
                foreach ($values as $value) {
                    if ( ! MarketplaceAttributeValue::where([
                        'marketplace' => $this->marketPlace,
                        'external_id' => $value,
                    ])->exists()) {
                        MarketplaceAttributeValue::create([
                            'marketplace' => $this->marketPlace,
                            'external_id' => $value,
                            'value'       => $value,
                        ]);
                    }
                }
            }

            /** Ищем связь пользовательской характеристики с текущей */
            $userAttributeWithSync = $userAttributesWithSync->filter(function ($item) use ($attributeName) {
                return $item['marketplace_attribute_id'] === $attributeName;
            })->first();

            /** Если еще не существует связи пользовательской хар-ки с системной */
            if ( ! $userAttributeWithSync) {
                /** Получаем external_id характеристики маркетплейса */
                $external_id = $marketplaceAttributesId[$attributeName] ?? 0;

                if (empty($external_id)) {
                    continue;
                }

                /** Получаем связь характеристики маркетплейса с системной характеристикой */
                $systemAttributesMarketplace = SystemAttributesMarketplace::where(
                    ['dictionary_id' => $external_id, 'marketplace' => $this->marketPlace]
                )->first();

                /** Если связи еще нет - нужно ее создать */
                if ( ! $systemAttributesMarketplace) {
                    logger()->info(sprintf(
                        'Для характеристики %s в категории %s нет связи с системной характеристикой (WB)',
                        $attributeName, $category->title
                    ));
                    continue;
                }

                /** Ищем у пользователя характеристику по названию или создаем ее */
                $userAttribute = Attribute::firstOrCreate([
                    'user_id' => $integration->user_id, 'name' => $systemAttributesMarketplace->system_attribute->title
                ]);

                /** Привязываем к системной хар-ке */
                $userAttribute->system_attributes()->attach(
                    $systemAttributesMarketplace->attribute_id, ['user_id' => $integration->user_id]
                );

                /** Обновляем кеш и получаем свежие данные по связям */
                $userAttributesWithSync = $attributeService->getUserAttributesWithSync($this->marketPlace,
                    $integration->user_id, true);

                $userAttributeWithSync = $userAttributesWithSync->filter(function ($item) use ($attributeName) {
                    return $item['marketplace_attribute_id'] === $attributeName;
                })->first();
            }

            /** Если по какой-то причине не удалось создать связь - пропускаем хар-ку */
            if (empty($userAttributeWithSync)) {
                continue;
            }

            $attributes[] = [
                'name'                     => $userAttributeWithSync['system_title'],
                'attribute_id'             => $userAttributeWithSync['attribute_id'],
                'marketplace_attribute_id' => $userAttributeWithSync['marketplace_attribute_id'],
                'system_attribute_id'      => $userAttributeWithSync['system_attribute_id'],
                'is_dictionary'            => $isDictionary,
                'values'                   => $values
            ];
        }

        $product->importData = array_merge($productData->data, [
            'attributes'          => $attributes,
            'gruppedBy'           => $gruppedBy,
            'variationAttributes' => $variationAttributes,
            'compositions'        => $compositions,
            'size'                => $size[0] ?? null,
            'size_ru'             => $size_ru[0] ?? null,
            'price'               => $productData['price'] ?? [],
            'chrtID'              => $productData->additionalInfo['chrtID'] ?? '',
            'skus'                => $productData->additionalInfo['skus'] ?? [],
            'wbSize'              => $productData->additionalInfo['wbSize'] ?? '',
            'techSize'            => $productData->additionalInfo['techSize'] ?? '',
            'images'              => $images,
            'video'               => $videos,
        ]);

        return $product;
    }

    /**
     * @param Integration $integration
     * @param Product $product
     * @param Collection $priceLists
     *
     * @return void
     */
    public function saveProductAdditionalData(Integration $integration, Product $product, Collection $priceLists): void
    {
        $attributes = [];
        foreach ($product->importData['card']['characteristics'] ?? [] as $attribute) {
            $attributeName = array_key_first($attribute);

            if (in_array($attributeName, $this->getExcludedAttributes())) {
                continue;
            }

            foreach ($attribute[$attributeName] ? (array)$attribute[$attributeName] : [] as $value) {
                $attributes[] = [
                    'name'  => $attributeName,
                    'value' => $value,
                ];
            }
        }

        $attributeService = new AttributeService();
        $attributeService->saveProductAttributes($product, $attributes, false);

        $basePrice = $product->importData['price']['price'] ?? null;
        $this->savePriceListsAndPrices(
            $product,
            [Product::PRICE_TYPE => ['base' => $basePrice]],
            $priceLists
        );

        if ($product->isDirty('image')) {
            Storage::delete($product->getOriginal('image'));
        }

        if ( ! empty($product->importData['images'])) {
            $productImages = $product->importData['images'];
            array_shift($productImages);
            $this->saveProductImages($product, $productImages);
        }

        $this->setProductMarketplace(
            $product->id,
            $product->barcode,
            $integration->user_id,
            $this->marketPlace,
            $this->prepareMarketplaceProductData($product->importData)
        );
    }

    /**
     * @param $attributeName
     * @param int $user_id
     * @param array $params
     * @param Collection $attributesMarketplace
     *
     * @return array
     */
    public function getUserAttributeByName(
        $attributeName,
        int $user_id,
        array $params,
        Collection $attributesMarketplace
    ): array {
        $attributes = [];

        $attributeMarketplace = $attributesMarketplace->where('name', $attributeName)->first();

        if ($attributeMarketplace) {
            $attributeId = $attributeMarketplace['attribute_id'];
        } else {
            $attribute   = Attribute::firstOrCreate(['user_id' => $user_id, 'name' => $attributeName]);
            $attributeId = $attribute->id;
        }

        foreach ($params as $param) {
            $attributes[] = [
                'attribute_id' => $attributeId,
                'value'        => $param,
            ];
        }

        return $attributes;
    }

    /**
     * @param Collection $variationAddins
     *
     * @return array
     */
    /**
     * @param $variation
     *
     * @return int[]
     */
    public function getBasePrice($variation): array
    {
        return [
            'base' => (int)$variation['card']['sizes'][0]['price'] ?? 0
        ];
    }

    /**
     * @param Collection $attributes
     * @param array $wbAttribute
     *
     * @return mixed
     */
    public function getAttributeByTitle(
        Collection $attributes,
        array $wbAttribute
    ): mixed {
        return $attributes->filter(function ($item) use ($wbAttribute) {
            return array_key_first($item) == $wbAttribute['value'];
        })->first();
    }

    /**
     * @param array|null $attribute
     * @param bool $isDictionary
     *
     * @return string
     */
    public function getAttributeValue(
        ?array $attribute,
        bool $isDictionary = false
    ): string {
        /** Если в значениях пусто */
        if (empty($attribute) || ! is_array($attribute)) {
            return '';
        }

        $attributeName = array_key_first($attribute);
        $values        = array_filter((array)$attribute[$attributeName]);

        /** Для справочников */
        if ($isDictionary) {
            $attributeValues = [];
            foreach ($values as $value) {
                $attributeValues[] = $this->importAttributeValues($value)?->value;
            }

            return implode(';', $attributeValues);
        }

        return current($values);
    }

    /**
     * @param string $value
     *
     * @return MarketplaceAttributeValue|null
     */
    private function importAttributeValues(
        string $value
    ): ?MarketplaceAttributeValue {
        $attributeValue = MarketplaceAttributeValue::where([
            'marketplace' => $this->marketPlace,
            'external_id' => $value
        ])->first();

        if ( ! $attributeValue) {
            $attributeValue = MarketplaceAttributeValue::create([
                'marketplace' => $this->marketPlace,
                'external_id' => $value,
                'value'       => $value,
            ]);
        }

        return $attributeValue;
    }

    /**
     * Поиск товара по атрибуту imtID
     *
     * @param string $imtID
     * @param int $userId
     *
     * @return Product|null
     */
    public function findProductByImtID(string $imtID, int $userId): ?Product
    {
        $marketPlaceProduct = MarketplaceProduct::whereRaw(
            "marketplace = 'wildberries' AND user_id = ? AND type = ? AND data->>'imtID' = ? AND status = ?", [
                $userId, Product::PRICE_TYPE, $imtID, 'success'
            ]
        )->first();

        if ($marketPlaceProduct) {
            return Product::find($marketPlaceProduct->object_id);
        }

        return null;
    }

    /**
     * @param Collection $characteristics
     * @param string $characteristicName
     * @param mixed|null $defaultValue
     *
     * @return mixed
     */
    public function getValueFromCharacteristics(
        Collection $characteristics,
        string $characteristicName,
        mixed $defaultValue = null
    ): mixed {
        $value = $characteristics->filter(function ($item) use ($characteristicName) {
            return array_key_first($item) === $characteristicName;
        })->map(function ($item) use ($defaultValue) {
            return $item[array_key_first($item)] ?? $defaultValue;
        })->first();

        return $value ?? $defaultValue;
    }

    /**
     * Если есть состав - парсим его и сохраняем в нужном нам формате
     *
     * @param array $compositions
     *
     * @return array
     */
    public function prepareCompositionsToImport(array $compositions): array
    {
        $productCompositions  = [];
        $importedCompositions = [];

        if ($compositions) {
            foreach ($compositions as $composition) {
                $compositionData = explode(' ', $composition);

                if (count($compositionData) === 2) {
                    $name    = $compositionData[0];
                    $percent = preg_replace('/[^0-9]/', '', $compositionData[1]);

                    if ( ! empty($percent) && ! empty($name)) {
                        $importedCompositions[] = [
                            'name'  => $name,
                            'value' => $percent
                        ];
                    }
                }
            }

            if ( ! empty($importedCompositions)) {
                $compositionDictionary = \App\Models\System\Attribute::where([
                    'settings->system_name' => 'composition'
                ])->firstOrFail();

                if ($compositionDictionary) {
                    foreach ($importedCompositions as $importedComposition) {
                        $attributeValue = AttributeValue::where('attribute_id', $compositionDictionary->id)
                            ->whereRaw('LOWER(title) = ?', Str::lower($importedComposition['name']))->first();

                        if (empty($attributeValue)) {
                            $attributeValue = AttributeValue::create([
                                'attribute_id' => $compositionDictionary->id,
                                'title'        => Str::lower($importedComposition['name'])
                            ]);
                        }

                        if ($attributeValue) {
                            $productCompositions[] = [
                                'id'    => $attributeValue->id,
                                'value' => $importedComposition['value']
                            ];
                        }
                    }
                }
            }
        }

        return $productCompositions;
    }

    /**
     * @param Product|ProductVariation|ProductVariationItem $product
     * @param array $prices
     * @param Collection $priceLists
     *
     * @return void
     */
    public function savePriceListsAndPrices(
        Product|ProductVariation|ProductVariationItem $product,
        array $prices,
        Collection $priceLists
    ): void {
        /** @var PriceList $priceList */
        foreach ($priceLists as $priceList) {
            if ($product instanceof Product) {
                $priceList->products()->syncWithoutDetaching($product->id);
            }

            foreach ($prices as $priceType => $priceArr) {
                ProductPrice::updateOrCreate([
                    'object_id'     => $product->id,
                    'type'          => $priceType,
                    'marketplace'   => $this->marketPlace,
                    'price_list_id' => $priceList->id,
                ], ['prices' => $priceArr]);
            }
        }
    }


    /**
     * @param Integration $integration
     * @param array $products
     * @param Collection $priceLists
     *
     * @return void
     */
    public function updateProductStocksFromMarketplace(
        Integration $integration,
        array $products,
        Collection $priceLists
    ): void {
        try {
            $api = new WbClient(getIntegrationSetting($integration, 'api_token'));

            $warehouses = Warehouse::where([
                'user_id'     => $integration->user_id,
                'marketplace' => $this->marketPlace,
            ])->get();

            $productsCollection = collect($products)->keyBy('barcode');
            $barcodes           = $productsCollection->keys()->map(function ($item) {
                return (string)$item;
            })->toArray();

            if ( ! empty($barcodes)) {
                foreach ($warehouses as $warehouse) {
                    $stocks = $api->getStocks($warehouse->warehouse_id, $barcodes);
                    if ( ! empty($stocks)) {
                        foreach ($stocks as $stock) {
                            $product = $productsCollection->get($stock['sku']);

                            /** @var Product|ProductVariation|ProductVariationItem $product */
                            if ( ! empty($product)) {
                                /** @var PriceList $priceList */
                                foreach ($priceLists as $priceList) {
                                    ProductStock::updateOrCreate([
                                        'object_id'     => $product->id,
                                        'type'          => $product::PRICE_TYPE,
                                        'marketplace'   => $this->marketPlace,
                                        'price_list_id' => $priceList->id,
                                        'warehouse_id'  => $warehouse->id,
                                    ], ['fbs_stock' => (int)$stock['amount']]);
                                }
                            }
                        }
                    }
                }
            }
        } catch (BusinessException $e) {
            $this->addIntegrationLog($integration, 'error', ['msg' => $e->getUserMessage()]);
        }
    }

    /**
     * @return array
     */
    public function getExcludedAttributes(): array
    {
        return [
            WbAttributes::TITLE->value,
            WbAttributes::DESCRIPTION->value,
            WbAttributes::KEYWORDS->value,
            WbAttributes::CATEGORY->value,
            WbAttributes::COUNTRY->value,
            WbAttributes::COMPOSITION->value,
            WbAttributes::WEIGHT->value,
            WbAttributes::WIDTH->value,
            WbAttributes::HEIGHT->value,
            WbAttributes::LENGTH->value,
            WbAttributes::TNVED->value,
        ];
    }

    /**
     * @param Product $product
     * @param string $attributeId
     * @param string $marketPlace
     * @param array $attributes
     *
     * @return void
     */
    public function getVariationModificationAttributes(
        Product $product,
        string $attributeId,
        string $marketPlace,
        array &$attributes,
        bool $is_collection = false
    ): void {
        $mpKey = SystemCategory::getMarketPlaceIdKey($marketPlace);

        $attribute = Arr::first($product->importData['attributes'],
            function ($item) use ($attributeId) {
                return (int)$item['system_attribute_id'] === (int)$attributeId;
            });

        if ( ! empty($attribute)) {
            foreach ($attribute['values'] as $value) {
                $systemAttribute = \App\Models\System\Attribute::find($attributeId);

                /** Если и в вб и в мпс характеристика не является справочником - просто запишем значение */
                if ( ! $attribute['is_dictionary'] && $systemAttribute->type !== 'dictionary') {
                    if ($is_collection) {
                        $attributes[$attributeId][] = $value;
                    } else {
                        $attributes[$attributeId] = $value;
                    }
                } /** Если в вб это не справочник, а у нас справочник - ищем системное значение характеристики */
                elseif ( ! $attribute['is_dictionary'] && $systemAttribute->type === 'dictionary') {
                    $attributeValue = AttributeValue::where([
                        'attribute_id' => $attributeId
                    ])->where(function ($query) use ($value) {
                        $attribute_value_ids = Synonym::where([
                            'type' => DictionaryTypes::ATTRIBUTE_VALUE, ['title', 'ilike', $value]
                        ])->get(['object_id'])->toArray();

                        return ! empty($attribute_value_ids) ?
                            $query->where('title', $value)->orWhereIn('id', $attribute_value_ids)
                            : $query->where('title', $value);
                    })->orderByRaw('case when settings->>? = ? then 0 else 1 end asc', ['isMain', 1])->first();

                    if ( ! empty($attributeValue)) {
                        if ($is_collection) {
                            $attributes[$attributeId][] = $attributeValue->id;
                        } else {
                            $attributes[$attributeId] = $attributeValue->id;
                        }
                    }
                } else {
                    /** Вначале ищем существующее значение маркетплейса */
                    $marketplaceAttributeValue = MarketplaceAttributeValue::where(['external_id' => $value])->first();
                    if (empty($marketplaceAttributeValue)) {
                        continue;
                    }

                    /** Потом ищем существующую связь */
                    $attributeValue = AttributeValue::where([
                        'attribute_id'      => $attributeId,
                        'settings->'.$mpKey => $marketplaceAttributeValue->id
                    ])->orderByRaw('case when settings->>? = ? then 0 else 1 end asc', ['isMain', 1])->first();

                    /** Если не нашли - создаем */
                    if (empty($attributeValue)) {
                        logger()->info(
                            sprintf(
                                'Не создано значение справочника %s: %s (Wildberries)',
                                $systemAttribute->title, $marketplaceAttributeValue->value
                            )
                        );
                    }

                    if ($attributeValue) {
                        if ($is_collection) {
                            $attributes[$attributeId][] = $attributeValue->id;
                        } else {
                            $attributes[$attributeId] = $attributeValue->id;
                        }
                    }
                }
            }
        }
    }

    /**
     * @param array $importData
     * @param string $type
     *
     * @return array
     */
    public function prepareMarketplaceProductData(array $importData, string $type = Product::PRICE_TYPE): array
    {
        if (empty($importData)) {
            return [];
        }

        $characteristics = $importData['characteristics'] ?? $importData['card']['characteristics'];

        $imtID  = $importData['imtID'] ?? $importData['card']['imtID'];
        $nmID   = $importData['nmID'] ?? $importData['card']['nmID'];
        $chrtID = $importData['chrtID'] ?? null;

        $id = match ($type) {
            ProductVariation::PRICE_TYPE => $nmID,
            ProductVariationItem::PRICE_TYPE => $chrtID,
            default => $imtID,
        };

        return [
            'id'       => $id,
            'title'    => $this->getValueFromCharacteristics(collect($characteristics), WbAttributes::TITLE->value,
                $importData['vendorCode']),
            'sku'      => $importData['vendorCode'],
            'barcodes' => $importData['skus'] ?? [],
            //'attributes' => $characteristics,
            'imtID'    => $imtID,
            'nmID'     => $nmID,
            'chrtID'   => $chrtID,
            'wbSize'   => $importData['wbSize'] ?? '',
            'techSize' => $importData['techSize'] ?? '',
        ];
    }

    /**
     * @param Order $order
     * @param array $orderData
     *
     * @return array[]
     */
    private function getOrderProducts(Order $order, array $orderData): array
    {
        $productVariation     = null;
        $productVariationItem = null;

        if ( ! empty($orderData['chrtId'])) {
            $marketplaceProduct = MarketplaceProduct::where([
                'data->id'    => $orderData['chrtId'],
                'type'        => ProductVariationItem::PRICE_TYPE,
                'user_id'     => $order->user_id,
                'marketplace' => $this->marketPlace,
            ])->first();

            if ( ! empty($marketplaceProduct)) {
                $productVariationItem = ProductVariationItem::find($marketplaceProduct->object_id);
                if ( ! empty($productVariationItem)) {
                    $productVariation = $productVariationItem->product_variation;
                    $product          = $productVariation->product;
                }
            }
        }

        if (empty($product)) {
            $marketplaceProduct = MarketplaceProduct::where([
                'data->nmID'  => $orderData['nmId'],
                'type'        => ProductVariation::PRICE_TYPE,
                'user_id'     => $order->user_id,
                'marketplace' => $this->marketPlace,
            ])->first();

            if ( ! empty($marketplaceProduct)) {
                $productVariation = ProductVariation::find($marketplaceProduct->object_id);
                if ( ! empty($productVariation)) {
                    $product = $productVariation->product;
                }
            }
        }

        if (empty($product)) {
            /** Ищем по штрихкоду товар */
            $product = Product::where(['user_id' => $order->user_id, 'sku' => $orderData['article']])->first();
        }

        $settings = [
            'sku'     => $orderData['article'],
            'chrtId'  => $orderData['chrtId'],
            'barcode' => current($orderData['skus']),
            'skus'    => $orderData['skus'],
        ];

        if ( ! empty($productVariation)) {
            $settings['product_variation_id'] = $productVariation->id;
        }
        if ( ! empty($productVariationItem)) {
            $settings['product_variation_item_id'] = $productVariationItem->id;
        }

        return [
            [
                'id'       => $product?->id,
                'title'    => $product?->title ?? $orderData['article'],
                'quantity' => 1,
                'price'    => $order->total,
                'settings' => $settings,
                'barcode'  => current($orderData['skus']),
            ]
        ];
    }

    /**
     * @param int $user_id
     * @param array $ordersData
     * @param array $orderStatuses
     *
     * @return void
     */
    private function saveOrders(int $user_id, array $ordersData, array $orderStatuses): void
    {
        $orderService = new OrderService();

        if (empty($ordersData) || empty($orderStatuses)) {
            return;
        }

        foreach ($ordersData as $orderData) {
            if (empty($orderData['id']) || empty($orderStatuses[$orderData['id']])) {
                continue;
            }

            $currency = Currency::where('iso_code', $orderData['currencyCode'])->first();

            $order = Order::where([
                'user_id'     => $user_id,
                'marketplace' => $this->marketPlace,
                'order_uid'   => $orderData['id'],
            ])->first();

            if (empty($order)) {
                $order = new Order(
                    [
                        'user_id'         => $user_id,
                        'marketplace'     => $this->marketPlace,
                        'order_uid'       => $orderData['id'], //Id заказа
                        'delivery'        => [
                            'user'         => $orderData['user'] ?? [], //Информация о клиенте.
                            'deliveryType' => $orderData['deliveryType'] ?? 'fbs',
                            //Тип доставки: fbs - доставка на склад Wildberries, dbs - доставка силами поставщика.
                            'address'      => $orderData['address'] ?? '',
                            //Детализованный адрес клиента для доставки (если применимо)
                            'offices'      => $orderData['offices'] ?? '',
                            //Список офисов, куда следует привезти товар.
                            'prioritySc'   => $orderData['prioritySc'] ?? '',
                            //Массив приоритетных СЦ для доставки сборочного задания. Если поле не заполнено или массив пустой, приоритетного СЦ для данного сборочного задания нет.
                        ],
                        'order_created'   => $orderData['createdAt'] ?? '', //Дата создания заказа
                        'status'          => $orderStatuses[$orderData['id']]['supplierStatus'] ?? 'new',
                        //Статус сборочного задания, триггером изменения которого является сам поставщик.
                        //  Возможны следующие значения данного поля: new - Новое сборочное задание, confirm - 	На сборке, complete	- В доставке, cancel - Отменено поставщиком, deliver - В доставке, receive - Получено клиентом, reject - Отказ при получении
                        'additional_data' => [
                            'wbStatus'              => $orderStatuses[$orderData['id']]['wbStatus'] ?? 'waiting',
                            //wbStatus - статус сборочного задания в системе Wildberries.
                            //Возможны следующие значения данного поля:
                            //
                            //waiting - сборочное задание в работе
                            //sorted - сборочное задание отсортировано
                            //sold - сборочное задание получено клиентом
                            //canceled - отмена сборочного задания
                            //canceled_by_client - отмена сборочного задания клиентом
                            'convertedPrice'        => $orderData['convertedPrice'] ?? '',
                            'convertedCurrencyCode' => $orderData['convertedCurrencyCode'] ?? '',
                            //Цена продажи с учетом скидок в копейках, сконвертированная в валюту страны поставщика по курсу на момент создания сборочного задания. Предоставляется в информационных целях.
                            'warehouseId'           => $orderData['warehouseId'] ?? '',
                            //Идентификатор склада поставщика, на который поступило сборочное задание
                            'supplyId'              => $orderData['supplyId'] ?? '',
                            //Идентификатор поставки. Возвращается, если заказ закреплён за поставкой.
                            'chrtId'                => $orderData['chrtId'] ?? '',
                            //Идентификатор размера товара в системе Wildberries
                            'nmId'                  => $orderData['nmId'] ?? '',
                            //Артикул товара в системе Wildberries
                            'skus'                  => $orderData['skus'] ?? [], //Массив штрихкодов товара.
                            'article'               => $orderData['article'] ?? [], //Артикул поставщика
                            'rid'                   => $orderData['rid'] ?? '',
                            //Идентификатор сборочного задания в системе Wildberries
                            'orderUid'              => $orderData['orderUid'] ?? '',
                            //Идентификатор транзакции (для группировки заказов)
                            'isLargeCargo'          => $orderData['isLargeCargo'] ?? '',
                            //сКГТ-признак товара, на который был сделан заказ
                            'order_type'            => $orderData['deliveryType'] ?? 'fbs',
                        ],
                        'total'           => ($orderData['price'] ?? 0) / 100, // wb отдает цены в копейках
                        'currency_id'     => $currency?->id,
                    ]
                );
                $order->save();

                $orderService->addOrderHistory($order);

                $orderService->addOrderTotal(
                    $order, $order->total, $order->currency_id, 'total', trans('orders.total_title')
                );
            } else {
                /** Если поменялся статус заказа - обновляем */
                if (
                    ($orderStatuses[$orderData['id']]['supplierStatus'] ?? 'new') !== $order->status
                    || ($orderStatuses[$orderData['id']]['wbStatus'] ?? 'waiting') !== $order->additional_data['wbStatus']
                ) {
                    $order->status          = $orderStatuses[$orderData['id']]['supplierStatus'] ?? 'new';
                    $order->additional_data = array_merge($order->additional_data ?? [],
                        ['wbStatus' => $orderStatuses[$orderData['id']]['wbStatus'] ?? 'waiting']);

                    $order->save();

                    $orderService->addOrderHistory($order);
                }

                /** Если изменилась сумма заказа - обновим данные */
                if ($order->total != ($orderData['price'] ?? 0) / 100) {
                    $order->total = ($orderData['price'] ?? 0) / 100;
                    $order->save();

                    $orderTotal = OrderTotal::where(['code' => 'total'])->first();
                    $orderTotal?->update(['price' => $order->total, 'currency_id' => $order->currency_id]);
                }
            }

            $this->saveOrderProducts($order, $orderData);
        }
    }

    /**
     * Привязывает товары к заказу
     *
     * @param Order $order
     * @param array $orderData
     *
     * @return void
     */
    private function saveOrderProducts(Order $order, array $orderData): void
    {
        $orderService  = new OrderService();
        $orderProducts = $this->getOrderProducts($order, $orderData);

        if (empty($order->products->count())) {
            $orderService->addOrderProducts($order, $orderProducts);
        } else {
            foreach ($order->products as $product) {
                $productItem = \Illuminate\Support\Arr::first($orderProducts, function ($item) use ($product) {
                    return $item['settings']['barcode'] === $product->settings['barcode'];
                });

                /** Если у товара не был указан id товара - пробуем его найти */
                if (empty($product->product_id) && ! empty($productItem['id'])) {
                    $product->update([
                        'product_id' => $productItem['id'], 'title' => $productItem['title']
                    ]);
                }
            }
        }
    }
}
