<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Requests\Api\BaseRequest;
use App\Http\Requests\Api\PriceStoreRequest;
use App\Http\Requests\Api\UpdatePricesStocksRequest;
use App\Models\Integration;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\ProductVariationItem;
use App\Traits\ProductHelper;
use App\Traits\WarehouseHelper;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PriceController extends BaseApiController
{
    use ProductHelper;
    use WarehouseHelper;

    public function pricesStocksUpdate(PriceStoreRequest $request)
    {
        try {
            $this->checkAuthByToken($request);

            $updatedCount = 0;

            $integration = $request->integration ?? new Integration();

            /** Если не включена настройка Загружать остатки и цены */
            if (empty(getIntegrationImportSetting($integration, 'update_prices'))
                || empty(getIntegrationImportSetting($integration, 'update_stocks'))) {
                return $this->errorResponse([['msg' => trans('api_errors.prices_stocks_off')]]);
            }

            $products        = $request->json('products', []);
            $variations      = $request->json('variations', []);
            $variation_items = $request->json('variation_items', []);
            if ( ! $products && ! $variations && ! $variation_items) {
                throw new ApiException(trans('api_errors.need_products'));
            }

            $additionalInfo = [];
            $productIds     = [];

            $price_list_ids = PriceList::getPriceListsByIntegration($integration);
            if ( ! $price_list_ids) {
                throw new ApiException(trans('api_errors.need_price_list'));
            }

            $priceLists = PriceList::whereIn('id', $price_list_ids)->where('user_id', auth()->user()->id)
                ->get();

            $marketplaces = getActiveMarketPlaces();
            $priceTypes   = collect(array_merge($marketplaces, [['name' => 'default']]))->pluck('name');
            $priceKeys    = collect(['base', 'purchase', 'presale']);

            $rules = [
                'id'                             => 'required|string',
                'values'                         => 'present|array',
                'values.*.key'                   => 'required|string|max:255',
                'values.*.prices'                => 'required|array',
                'values.*.prices.*.key'          => 'required|string|max:255',
                'values.*.prices.*.value'        => 'required|numeric',
                'values.*.stocks'                => 'required|array',
                'values.*.stocks.*.warehouse_id' => [
                    'nullable', Rule::in($this->getUserWarehouseIds(auth()->id()))
                ],
                'values.*.stocks.*.stock'        => 'required|numeric',
            ];

            $key = 0;
            foreach ($products as $product) {
                $validator = Validator::make($product, $rules);
                if ($validator->fails()) {
                    $additionalInfo[] = getAdditionalInfo($key, $validator, $product);
                    continue;
                }

                $productInDb = Product::getUserProductById(auth()->user()->id, $product['id']);

                if ($productInDb) {
                    $productIds[] = $productInDb->id;

                    $prices = [];

                    foreach ($product['values'] as $value) {
                        if ( ! $priceTypes->contains($value['key'])) {
                            continue;
                        }

                        foreach ($value['prices'] as $price) {
                            if ( ! $priceKeys->contains($price['key'])) {
                                continue;
                            }

                            $prices[$productInDb->id][$value['key']][$price['key']] = (float)$price['value'];
                        }

                        foreach ($value['stocks'] as $stock) {
                            $prices[$productInDb->id][$value['key']]['stock'][(int)$stock['warehouse_id']] = (int)$stock['stock'];
                        }
                    }

                    if ($prices) {
                        foreach ($priceLists as $priceList) {
                            $this->savePricesAndStocks(Product::PRICE_TYPE, $prices, $priceList->id);
                        }
                    } else {
                        foreach ($priceLists as $priceList) {
                            $this->deletePriceListPrices(Product::PRICE_TYPE, $productInDb->id, $priceList->id);
                            $this->deletePriceListStocks(Product::PRICE_TYPE, $productInDb->id, $priceList->id);
                        }
                    }

                    $updatedCount++;
                }
            }

            $rules['id']         = 'required|uuid';
            $rules['product_id'] = 'required|string';
            foreach ($variations as $variation) {
                $key++;
                $validator = Validator::make($variation, $rules);
                if ($validator->fails()) {
                    $additionalInfo[] = getAdditionalInfo($key, $validator, $variation);
                    continue;
                }

                $productInDb = Product::getUserProductById(auth()->id(), $variation['product_id']);

                if ($productInDb) {
                    $productIds[] = $productInDb->id;

                    $productVariation = ProductVariation::where('uuid', $variation['id'])
                        ->where('product_id', $productInDb->id)->first();
                    if ($productVariation) {
                        $prices = [];

                        foreach ($variation['values'] as $value) {
                            if ( ! $priceTypes->contains($value['key'])) {
                                continue;
                            }

                            foreach ($value['prices'] as $price) {
                                if ( ! $priceKeys->contains($price['key'])) {
                                    continue;
                                }

                                $prices[$productVariation->id][$value['key']][$price['key']] = (float)$price['value'];
                            }

                            foreach ($value['stocks'] as $stock) {
                                $prices[$productVariation->id][$value['key']]['stock'][(int)$stock['warehouse_id']] = (int)$stock['stock'];
                            }
                        }

                        if ($prices) {
                            foreach ($priceLists as $priceList) {
                                $this->savePricesAndStocks(ProductVariation::PRICE_TYPE, $prices, $priceList->id);
                            }
                        } else {
                            foreach ($priceLists as $priceList) {
                                $this->deletePriceListPrices(
                                    ProductVariation::PRICE_TYPE,
                                    $productVariation->id,
                                    $priceList->id
                                );
                                $this->deletePriceListStocks(
                                    ProductVariation::PRICE_TYPE,
                                    $productVariation->id,
                                    $priceList->id
                                );
                            }
                        }

                        $updatedCount++;
                    } else {
                        $additionalInfo[] = customAdditionalInfo(
                            trans('validation.api_custom_additional_info', ['key' => $key, 'id' => $variation['id']]),
                            [trans('validation.variation_not_exist')]
                        );
                    }
                } else {
                    $additionalInfo[] = customAdditionalInfo(
                        trans('validation.variation_product_not_exist'),
                        [trans('exports.errors.product_not_found', ['product' => $variation['product_id']])]
                    );
                }
            }

            $rules['id']           = 'required|uuid';
            $rules['product_id']   = 'required|string';
            $rules['variation_id'] = 'required|string';
            foreach ($variation_items as $variation_item) {
                $key++;
                $validator = Validator::make($variation_item, $rules);
                if ($validator->fails()) {
                    $additionalInfo[] = getAdditionalInfo($key, $validator, $variation_item);
                    continue;
                }

                $productInDb = Product::getUserProductById(auth()->id(), $variation_item['product_id']);

                if ( ! $productInDb) {
                    $additionalInfo[] = customAdditionalInfo(
                        trans('validation.variation_item_product_not_exist'),
                        [trans('exports.errors.product_not_found', ['product' => $variation_item['product_id']])]
                    );

                    continue;
                }

                $productIds[] = $productInDb->id;

                $productVariation = ProductVariation::where('uuid', $variation_item['variation_id'])
                    ->where('product_id', $productInDb->id)->first();
                if ( ! $productVariation) {
                    $additionalInfo[] = customAdditionalInfo(
                        trans('validation.api_custom_additional_info',
                            ['key' => $key, 'id' => $variation_item['variation_id']]),
                        [trans('validation.variation_not_exist')]
                    );

                    continue;
                }

                $productVariationItem = ProductVariationItem::where('uuid', $variation_item['id'])
                    ->where('product_variation_id', $productVariation->id)->first();

                if ($productVariationItem) {
                    $prices = [];

                    foreach ($variation_item['values'] as $value) {
                        if ( ! $priceTypes->contains($value['key'])) {
                            continue;
                        }

                        foreach ($value['prices'] as $price) {
                            if ( ! $priceKeys->contains($price['key'])) {
                                continue;
                            }

                            $prices[$productVariationItem->id][$value['key']][$price['key']] = (float)$price['value'];
                        }

                        foreach ($value['stocks'] as $stock) {
                            $prices[$productVariationItem->id][$value['key']]['stock'][(int)$stock['warehouse_id']] = (int)$stock['stock'];
                        }
                    }

                    if ($prices) {
                        foreach ($priceLists as $priceList) {
                            $this->savePricesAndStocks(ProductVariationItem::PRICE_TYPE, $prices, $priceList->id);
                        }
                    } else {
                        foreach ($priceLists as $priceList) {
                            $this->deletePriceListPrices(ProductVariationItem::PRICE_TYPE, $variation_item['id'],
                                $priceList->id);
                            $this->deletePriceListStocks(ProductVariationItem::PRICE_TYPE, $variation_item['id'],
                                $priceList->id);
                        }
                    }

                    $updatedCount++;
                } else {
                    $additionalInfo[] = customAdditionalInfo(
                        trans('validation.api_custom_additional_info',
                            ['key' => $key, 'id' => $variation_item['id']]),
                        [trans('validation.variation_item_not_exist')]
                    );
                }
            }

            /** Добавляем товары в прайслисты */
            foreach ($priceLists ?? [] as $priceList) {
                $priceList->products()->syncWithoutDetaching(array_unique($productIds));
            }

            return $this->successResponse([
                [
                    'msg'            => trans('imports.prices_updated_count',
                        [
                            'all'     => count($products) + count($variations) + count($variation_items),
                            'updated' => $updatedCount,
                        ]),
                    'additionalInfo' => $additionalInfo
                ],
            ]);
        } catch (QueryException $e) {
            logger()->critical($e);

            return $this->errorResponse([['msg' => trans('api_errors.system_error')]]);
        } catch (\Exception $e) {
            return $this->errorResponse([['msg' => $e->getMessage()]]);
        }
    }

    public function types(BaseRequest $request)
    {
        try {
            $this->checkAuthByToken($request);

            $result = [
                [
                    'key'    => 'default',
                    'name'   => trans('price_lists.default_prices'),
                    'prices' => [
                        [
                            'key'  => 'base',
                            'name' => trans('price_lists.base'),
                        ],
                        [
                            'key'  => 'purchase',
                            'name' => trans('price_lists.purchase'),
                        ],
                        [
                            'key'  => 'presale',
                            'name' => trans('price_lists.presale'),
                        ],
                    ],
                ],
            ];

            $marketplaces = collect(config('marketplaces.modules'))->filter(function ($item) {
                return $item['status'] === 1;
            })->pluck('title', 'name')->all();

            foreach ($marketplaces as $key => $name) {
                $result[] = [
                    'key'    => $key,
                    'name'   => sprintf('%s %s', trans('price_lists.prices'), $name),
                    'prices' => [
                        [
                            'key'  => 'base',
                            'name' => trans('price_lists.base'),
                        ],
                        [
                            'key'  => 'presale',
                            'name' => trans('price_lists.presale'),
                        ],
                    ],
                ];
            }

            return $this->successResponse($result);
        } catch (QueryException $e) {
            logger()->critical($e);

            return $this->errorResponse([['msg' => trans('api_errors.system_error')]]);
        } catch (\Exception $e) {
            return $this->errorResponse([['msg' => $e->getMessage()]]);
        }
    }

    /**
     * @param PriceStoreRequest $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function stocksUpdate(PriceStoreRequest $request)
    {
        try {
            $this->checkAuthByToken($request);

            $updatedCount = 0;

            $integration = $request->integration ?? new Integration();

            /** Если не включена настройка Загружать остатки */
            if (empty(getIntegrationImportSetting($integration, 'update_stocks'))) {
                return $this->errorResponse([['msg' => trans('api_errors.update_stocks_off')]]);
            }

            $products        = $request->json('products', []);
            $variations      = $request->json('variations', []);
            $variation_items = $request->json('variation_items', []);
            if ( ! $products && ! $variations && ! $variation_items) {
                throw new ApiException(trans('api_errors.need_products'));
            }

            $additionalInfo = [];
            $productIds     = [];

            $price_list_ids = PriceList::getPriceListsByIntegration($integration);
            if ( ! $price_list_ids) {
                throw new ApiException(trans('api_errors.need_price_list'));
            }

            $priceLists = PriceList::whereIn('id', $price_list_ids)->where('user_id', auth()->user()->id)
                ->get();

            $marketplaces = getActiveMarketPlaces();
            $stockTypes   = collect(array_merge($marketplaces, [['name' => 'default']]))->pluck('name');

            $rules = [
                'id'                             => 'required|string',
                'values'                         => 'present|array',
                'values.*.key'                   => 'required|string|max:255',
                'values.*.stocks'                => 'required|array',
                'values.*.stocks.*.warehouse_id' => [
                    'nullable', Rule::in($this->getUserWarehouseIds(auth()->id()))
                ],
                'values.*.stocks.*.stock'        => 'required|numeric',
            ];

            $key = 0;
            foreach ($products as $product) {
                $validator = Validator::make($product, $rules);
                if ($validator->fails()) {
                    $additionalInfo[] = getAdditionalInfo($key, $validator, $product);
                    continue;
                }

                $productInDb = Product::getUserProductById(auth()->user()->id, $product['id']);

                if ($productInDb) {
                    $productIds[] = $productInDb->id;

                    $stocks = [];

                    foreach ($product['values'] as $value) {
                        if ( ! $stockTypes->contains($value['key'])) {
                            continue;
                        }

                        foreach ($value['stocks'] as $stock) {
                            $stocks[$productInDb->id][$value['key']]['stock'][(int)$stock['warehouse_id']] = (int)$stock['stock'];
                        }
                    }

                    if ($stocks) {
                        foreach ($priceLists as $priceList) {
                            $this->saveStocks(Product::PRICE_TYPE, $stocks, $priceList->id);
                        }
                    } else {
                        foreach ($priceLists as $priceList) {
                            $this->deletePriceListStocks(Product::PRICE_TYPE, $productInDb->id, $priceList->id);
                        }
                    }

                    $updatedCount++;
                }
            }

            $rules['id']         = 'required|uuid';
            $rules['product_id'] = 'required|string';
            foreach ($variations as $variation) {
                $key++;
                $validator = Validator::make($variation, $rules);
                if ($validator->fails()) {
                    $additionalInfo[] = getAdditionalInfo($key, $validator, $variation);
                    continue;
                }

                $productInDb = Product::getUserProductById(auth()->id(), $variation['product_id']);

                if ($productInDb) {
                    $productIds[] = $productInDb->id;

                    $productVariation = ProductVariation::where('uuid', $variation['id'])
                        ->where('product_id', $productInDb->id)->first();
                    if ($productVariation) {
                        $stocks = [];

                        foreach ($variation['values'] as $value) {
                            if ( ! $stockTypes->contains($value['key'])) {
                                continue;
                            }

                            foreach ($value['stocks'] as $stock) {
                                $stocks[$productVariation->id][$value['key']]['stock'][(int)$stock['warehouse_id']] = (int)$stock['stock'];
                            }
                        }

                        if ($stocks) {
                            foreach ($priceLists as $priceList) {
                                $this->saveStocks(ProductVariation::PRICE_TYPE, $stocks, $priceList->id);
                            }
                        } else {
                            foreach ($priceLists as $priceList) {
                                $this->deletePriceListStocks(
                                    ProductVariation::PRICE_TYPE,
                                    $productVariation->id,
                                    $priceList->id
                                );
                            }
                        }

                        $updatedCount++;
                    } else {
                        $additionalInfo[] = customAdditionalInfo(
                            trans('validation.api_custom_additional_info', ['key' => $key, 'id' => $variation['id']]),
                            [trans('validation.variation_not_exist')]
                        );
                    }
                } else {
                    $additionalInfo[] = customAdditionalInfo(
                        trans('validation.variation_product_not_exist'),
                        [trans('exports.errors.product_not_found', ['product' => $variation['product_id']])]
                    );
                }
            }

            $rules['id']           = 'required|uuid';
            $rules['product_id']   = 'required|string';
            $rules['variation_id'] = 'required|string';
            foreach ($variation_items as $variation_item) {
                $key++;
                $validator = Validator::make($variation_item, $rules);
                if ($validator->fails()) {
                    $additionalInfo[] = getAdditionalInfo($key, $validator, $variation_item);
                    continue;
                }

                $productInDb = Product::getUserProductById(auth()->id(), $variation_item['product_id']);

                if ( ! $productInDb) {
                    $additionalInfo[] = customAdditionalInfo(
                        trans('validation.variation_item_product_not_exist'),
                        [trans('exports.errors.product_not_found', ['product' => $variation_item['product_id']])]
                    );

                    continue;
                }

                $productIds[] = $productInDb->id;

                $productVariation = ProductVariation::where('uuid', $variation_item['variation_id'])
                    ->where('product_id', $productInDb->id)->first();
                if ( ! $productVariation) {
                    $additionalInfo[] = customAdditionalInfo(
                        trans('validation.api_custom_additional_info',
                            ['key' => $key, 'id' => $variation_item['variation_id']]),
                        [trans('validation.variation_not_exist')]
                    );

                    continue;
                }

                $productVariationItem = ProductVariationItem::where('uuid', $variation_item['id'])
                    ->where('product_variation_id', $productVariation->id)->first();

                if ($productVariationItem) {
                    $stocks = [];

                    foreach ($variation_item['values'] as $value) {
                        if ( ! $stockTypes->contains($value['key'])) {
                            continue;
                        }

                        foreach ($value['stocks'] as $stock) {
                            $stocks[$productVariationItem->id][$value['key']]['stock'][(int)$stock['warehouse_id']] = (int)$stock['stock'];
                        }
                    }

                    if ($stocks) {
                        foreach ($priceLists as $priceList) {
                            $this->saveStocks(ProductVariationItem::PRICE_TYPE, $stocks, $priceList->id);
                        }
                    } else {
                        foreach ($priceLists as $priceList) {
                            $this->deletePriceListStocks(ProductVariationItem::PRICE_TYPE, $variation_item['id'],
                                $priceList->id);
                        }
                    }

                    $updatedCount++;
                } else {
                    $additionalInfo[] = customAdditionalInfo(
                        trans('validation.api_custom_additional_info',
                            ['key' => $key, 'id' => $variation_item['id']]),
                        [trans('validation.variation_item_not_exist')]
                    );
                }
            }

            /** Добавляем товары в прайслисты */
            foreach ($priceLists ?? [] as $priceList) {
                $priceList->products()->syncWithoutDetaching(array_unique($productIds));
            }

            return $this->successResponse([
                [
                    'msg'            => trans('imports.stocks_updated_count',
                        [
                            'all'     => count($products) + count($variations) + count($variation_items),
                            'updated' => $updatedCount,
                        ]),
                    'additionalInfo' => $additionalInfo
                ],
            ]);
        } catch (QueryException $e) {
            logger()->critical($e);

            return $this->errorResponse([['msg' => trans('api_errors.system_error')]]);
        } catch (\Exception $e) {
            return $this->errorResponse([['msg' => $e->getMessage()]]);
        }
    }

    /**
     * @param PriceStoreRequest $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function pricesUpdate(PriceStoreRequest $request)
    {
        try {
            $this->checkAuthByToken($request);

            $updatedCount = 0;

            $integration = $request->integration ?? new Integration();

            /** Если не включена настройка Загружать остатки и цены */
            if (empty(getIntegrationImportSetting($integration, 'update_prices'))) {
                return $this->errorResponse([['msg' => trans('api_errors.update_prices_off')]]);
            }

            $products        = $request->json('products', []);
            $variations      = $request->json('variations', []);
            $variation_items = $request->json('variation_items', []);
            if ( ! $products && ! $variations && ! $variation_items) {
                throw new ApiException(trans('api_errors.need_products'));
            }

            $additionalInfo = [];
            $productIds     = [];

            $price_list_ids = PriceList::getPriceListsByIntegration($integration);
            if ( ! $price_list_ids) {
                throw new ApiException(trans('api_errors.need_price_list'));
            }

            $priceLists = PriceList::whereIn('id', $price_list_ids)->where('user_id', auth()->user()->id)
                ->get();

            $marketplaces = getActiveMarketPlaces();
            $priceTypes   = collect(array_merge($marketplaces, [['name' => 'default']]))->pluck('name');
            $priceKeys    = collect(['base', 'purchase', 'presale']);

            $rules = [
                'id'                      => 'required|string',
                'values'                  => 'present|array',
                'values.*.key'            => 'required|string|max:255',
                'values.*.prices'         => 'required|array',
                'values.*.prices.*.key'   => 'required|string|max:255',
                'values.*.prices.*.value' => 'required|numeric',
            ];

            $key = 0;
            foreach ($products as $product) {
                $validator = Validator::make($product, $rules);
                if ($validator->fails()) {
                    $additionalInfo[] = getAdditionalInfo($key, $validator, $product);
                    continue;
                }

                $productInDb = Product::getUserProductById(auth()->user()->id, $product['id']);

                if ($productInDb) {
                    $productIds[] = $productInDb->id;

                    $prices = [];

                    foreach ($product['values'] as $value) {
                        if ( ! $priceTypes->contains($value['key'])) {
                            continue;
                        }

                        foreach ($value['prices'] as $price) {
                            if ( ! $priceKeys->contains($price['key'])) {
                                continue;
                            }

                            $prices[$productInDb->id][$value['key']][$price['key']] = (float)$price['value'];
                        }
                    }

                    if ($prices) {
                        foreach ($priceLists as $priceList) {
                            $this->savePrices(Product::PRICE_TYPE, $prices, $priceList->id);
                        }
                    } else {
                        foreach ($priceLists as $priceList) {
                            $this->deletePriceListPrices(Product::PRICE_TYPE, $productInDb->id, $priceList->id);
                        }
                    }

                    $updatedCount++;
                }
            }

            $rules['id']         = 'required|uuid';
            $rules['product_id'] = 'required|string';
            foreach ($variations as $variation) {
                $key++;
                $validator = Validator::make($variation, $rules);
                if ($validator->fails()) {
                    $additionalInfo[] = getAdditionalInfo($key, $validator, $variation);
                    continue;
                }

                $productInDb = Product::getUserProductById(auth()->id(), $variation['product_id']);

                if ($productInDb) {
                    $productIds[] = $productInDb->id;

                    $productVariation = ProductVariation::where('uuid', $variation['id'])
                        ->where('product_id', $productInDb->id)->first();
                    if ($productVariation) {
                        $prices = [];

                        foreach ($variation['values'] as $value) {
                            if ( ! $priceTypes->contains($value['key'])) {
                                continue;
                            }

                            foreach ($value['prices'] as $price) {
                                if ( ! $priceKeys->contains($price['key'])) {
                                    continue;
                                }

                                $prices[$productVariation->id][$value['key']][$price['key']] = (float)$price['value'];
                            }
                        }

                        if ($prices) {
                            foreach ($priceLists as $priceList) {
                                $this->savePrices(ProductVariation::PRICE_TYPE, $prices, $priceList->id);
                            }
                        } else {
                            foreach ($priceLists as $priceList) {
                                $this->deletePriceListPrices(
                                    ProductVariation::PRICE_TYPE,
                                    $productVariation->id,
                                    $priceList->id
                                );
                            }
                        }

                        $updatedCount++;
                    } else {
                        $additionalInfo[] = customAdditionalInfo(
                            trans('validation.api_custom_additional_info', ['key' => $key, 'id' => $variation['id']]),
                            [trans('validation.variation_not_exist')]
                        );
                    }
                } else {
                    $additionalInfo[] = customAdditionalInfo(
                        trans('validation.variation_product_not_exist'),
                        [trans('exports.errors.product_not_found', ['product' => $variation['product_id']])]
                    );
                }
            }

            $rules['id']           = 'required|uuid';
            $rules['product_id']   = 'required|string';
            $rules['variation_id'] = 'required|string';
            foreach ($variation_items as $variation_item) {
                $key++;
                $validator = Validator::make($variation_item, $rules);
                if ($validator->fails()) {
                    $additionalInfo[] = getAdditionalInfo($key, $validator, $variation_item);
                    continue;
                }

                $productInDb = Product::getUserProductById(auth()->id(), $variation_item['product_id']);

                if ( ! $productInDb) {
                    $additionalInfo[] = customAdditionalInfo(
                        trans('validation.variation_item_product_not_exist'),
                        [trans('exports.errors.product_not_found', ['product' => $variation_item['product_id']])]
                    );

                    continue;
                }

                $productIds[] = $productInDb->id;

                $productVariation = ProductVariation::where('uuid', $variation_item['variation_id'])
                    ->where('product_id', $productInDb->id)->first();
                if ( ! $productVariation) {
                    $additionalInfo[] = customAdditionalInfo(
                        trans('validation.api_custom_additional_info',
                            ['key' => $key, 'id' => $variation_item['variation_id']]),
                        [trans('validation.variation_not_exist')]
                    );

                    continue;
                }

                $productVariationItem = ProductVariationItem::where('uuid', $variation_item['id'])
                    ->where('product_variation_id', $productVariation->id)->first();

                if ($productVariationItem) {
                    $prices = [];

                    foreach ($variation_item['values'] as $value) {
                        if ( ! $priceTypes->contains($value['key'])) {
                            continue;
                        }

                        foreach ($value['prices'] as $price) {
                            if ( ! $priceKeys->contains($price['key'])) {
                                continue;
                            }

                            $prices[$productVariationItem->id][$value['key']][$price['key']] = (float)$price['value'];
                        }
                    }

                    if ($prices) {
                        foreach ($priceLists as $priceList) {
                            $this->savePrices(ProductVariationItem::PRICE_TYPE, $prices, $priceList->id);
                        }
                    } else {
                        foreach ($priceLists as $priceList) {
                            $this->deletePriceListPrices(ProductVariationItem::PRICE_TYPE, $variation_item['id'],
                                $priceList->id);
                        }
                    }

                    $updatedCount++;
                } else {
                    $additionalInfo[] = customAdditionalInfo(
                        trans('validation.api_custom_additional_info',
                            ['key' => $key, 'id' => $variation_item['id']]),
                        [trans('validation.variation_item_not_exist')]
                    );
                }
            }

            /** Добавляем товары в прайслисты */
            foreach ($priceLists ?? [] as $priceList) {
                $priceList->products()->syncWithoutDetaching(array_unique($productIds));
            }

            return $this->successResponse([
                [
                    'msg'            => trans('imports.prices_updated_count',
                        [
                            'all'     => count($products) + count($variations) + count($variation_items),
                            'updated' => $updatedCount,
                        ]),
                    'additionalInfo' => $additionalInfo
                ],
            ]);
        } catch (QueryException $e) {
            logger()->critical($e);

            return $this->errorResponse([['msg' => trans('api_errors.system_error')]]);
        } catch (\Exception $e) {
            return $this->errorResponse([['msg' => $e->getMessage()]]);
        }
    }


    /**
     * @param UpdatePricesStocksRequest $request
     *
     * @return JsonResponse
     */
    public function stocksUpdateV2(UpdatePricesStocksRequest $request)
    {
        try {
            $this->checkAuthByToken($request);

            $updatedCount = 0;

            $integration = $request->integration ?? new Integration();

            /** Если не включена настройка Загружать остатки */
            if (empty(getIntegrationImportSetting($integration, 'update_stocks'))) {
                return $this->errorResponse([['msg' => trans('api_errors.update_stocks_off')]]);
            }

            $products       = $request->json('products', []);
            $additionalInfo = [];
            $stocks         = [];

            $price_list_ids = PriceList::getPriceListsByIntegration($integration);
            if ( ! $price_list_ids) {
                throw new ApiException(trans('api_errors.need_price_list'));
            }

            $priceLists = PriceList::whereIn('id', $price_list_ids)->where('user_id', auth()->id())->get();

            $marketplaces = getActiveMarketPlaces();
            $stockTypes   = collect(array_merge($marketplaces, [['name' => 'default']]))->pluck('name');

            $rules = [
                'barcode'                        => 'required|string',
                'values'                         => 'present|array',
                'values.*.key'                   => 'required|string|max:255',
                'values.*.stocks'                => 'required|array',
                'values.*.stocks.*.warehouse_id' => [
                    'nullable', Rule::in($this->getUserWarehouseIds(auth()->id()))
                ],
                'values.*.stocks.*.stock'        => 'required|numeric',
            ];

            $key = 0;
            foreach ($products as $product) {
                $validator = Validator::make($product, $rules);
                if ($validator->fails()) {
                    $additionalInfo[] = getAdditionalInfo($key, $validator, $product);
                    continue;
                }

                $productInDb = ProductVariationItem::whereBarcode($product['barcode'])->byUserId(auth()->id())->first();

                if (empty($productInDb)) {
                    $productInDb = ProductVariation::whereBarcode($product['barcode'])->byUserId(auth()->id())->first();
                }

                if (empty($productInDb)) {
                    $productInDb = Product::whereBarcode($product['barcode'])->whereUserId(auth()->id())->first();
                }

                if ($productInDb) {
                    foreach ($product['values'] as $value) {
                        if ( ! $stockTypes->contains($value['key'])) {
                            continue;
                        }

                        foreach ($value['stocks'] as $stock) {
                            $stocks[$productInDb::PRICE_TYPE][$productInDb->id][$value['key']]['stock'][(int)$stock['warehouse_id']] = (int)$stock['stock'];
                        }
                    }
                } else {
                    $additionalInfo[] = customAdditionalInfo(trans('exports.errors.product_not_found',
                        ['product' => $product['barcode']]), null);
                }
            }

            if ( ! empty($stocks)) {
                foreach ($stocks as $stockType => $stockData) {
                    foreach ($priceLists as $priceList) {
                        $this->saveStocks($stockType, $stockData, $priceList->id);
                    }

                    $updatedCount += count($stockData);
                }
            }

            return $this->successResponse([
                [
                    'msg'            => trans('imports.stocks_updated_count',
                        [
                            'all'     => count($products),
                            'updated' => $updatedCount,
                        ]),
                    'additionalInfo' => $additionalInfo
                ],
            ]);
        } catch (QueryException $e) {
            logger()->critical($e);

            return $this->errorResponse([['msg' => trans('api_errors.system_error')]]);
        } catch (\Exception $e) {
            return $this->errorResponse([['msg' => $e->getMessage()]]);
        }
    }

    /**
     * @param UpdatePricesStocksRequest $request
     *
     * @return JsonResponse
     */
    public function pricesUpdateV2(UpdatePricesStocksRequest $request)
    {
        try {
            $this->checkAuthByToken($request);

            $updatedCount = 0;

            $integration = $request->integration ?? new Integration();

            /** Если не включена настройка Загружать цены */
            if (empty(getIntegrationImportSetting($integration, 'update_prices'))) {
                return $this->errorResponse([['msg' => trans('api_errors.update_prices_off')]]);
            }

            $products       = $request->json('products', []);
            $additionalInfo = [];
            $prices         = [];

            $price_list_ids = PriceList::getPriceListsByIntegration($integration);
            if ( ! $price_list_ids) {
                throw new ApiException(trans('api_errors.need_price_list'));
            }

            $priceLists = PriceList::whereIn('id', $price_list_ids)->where('user_id', auth()->user()->id)
                ->get();

            $marketplaces = getActiveMarketPlaces();
            $priceTypes   = collect(array_merge($marketplaces, [['name' => 'default']]))->pluck('name');
            $priceKeys    = collect(['base', 'purchase', 'presale']);

            $rules = [
                'barcode'                 => 'required|string',
                'values'                  => 'present|array',
                'values.*.key'            => 'required|string|max:255',
                'values.*.prices'         => 'required|array',
                'values.*.prices.*.key'   => 'required|string|max:255',
                'values.*.prices.*.value' => 'required|numeric',
            ];

            $key = 0;
            foreach ($products as $product) {
                $validator = Validator::make($product, $rules);
                if ($validator->fails()) {
                    $additionalInfo[] = getAdditionalInfo($key, $validator, $product);
                    continue;
                }

                $productInDb = ProductVariationItem::whereBarcode($product['barcode'])->byUserId(auth()->id())->first();

                if (empty($productInDb)) {
                    $productInDb = ProductVariation::whereBarcode($product['barcode'])->byUserId(auth()->id())->first();
                }

                if (empty($productInDb)) {
                    $productInDb = Product::whereBarcode($product['barcode'])->whereUserId(auth()->id())->first();
                }

                if ($productInDb) {
                    foreach ($product['values'] as $value) {
                        if ( ! $priceTypes->contains($value['key'])) {
                            continue;
                        }

                        foreach ($value['prices'] as $price) {
                            if ( ! $priceKeys->contains($price['key'])) {
                                continue;
                            }

                            $prices[$productInDb::PRICE_TYPE][$productInDb->id][$value['key']][$price['key']] = (float)$price['value'];
                        }
                    }
                } else {
                    $additionalInfo[] = customAdditionalInfo(trans('exports.errors.product_not_found',
                        ['product' => $product['barcode']]), null);
                }
            }

            if ( ! empty($prices)) {
                foreach ($prices as $priceType => $priceData) {
                    foreach ($priceLists as $priceList) {
                        $this->savePrices($priceType, $priceData, $priceList->id);
                    }

                    $updatedCount += count($priceData);
                }
            }

            return $this->successResponse([
                [
                    'msg'            => trans('imports.prices_updated_count',
                        [
                            'all'     => count($products),
                            'updated' => $updatedCount,
                        ]),
                    'additionalInfo' => $additionalInfo
                ],
            ]);
        } catch (QueryException $e) {
            logger()->critical($e);

            return $this->errorResponse([['msg' => trans('api_errors.system_error')]]);
        } catch (\Exception $e) {
            return $this->errorResponse([['msg' => $e->getMessage()]]);
        }
    }
}
