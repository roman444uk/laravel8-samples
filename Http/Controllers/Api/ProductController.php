<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Facades\SyncHelper;
use App\Http\Requests\Api\BaseRequest;
use App\Http\Requests\Api\ProductDeleteRequest;
use App\Http\Requests\Api\ProductShowRequest;
use App\Http\Requests\Api\ProductStoreRequest;
use App\Models\Category;
use App\Models\Integration;
use App\Models\PriceList;
use App\Models\Product;
use App\Rules\ExistsCountry;
use App\Rules\UserCategories;
use App\Rules\UserUuid;
use App\Services\Shop\AttributeService;
use App\Services\Shop\ProductService;
use App\Services\Shop\VariationService;
use App\Traits\ImageHelper;
use App\Traits\ProductHelper;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends BaseApiController
{
    use ImageHelper;
    use ProductHelper;

    public function store(
        ProductStoreRequest $request,
        VariationService $variationService,
        AttributeService $attributeService
    ) {
        try {
            $this->checkAuthByToken($request);

            $createdCount = 0;
            $updatedCount = 0;

            $integration = $request->integration ?? new Integration();

            $products = $request->json('products');
            if ( ! $products) {
                throw new ApiException(trans('api_errors.need_products'));
            }

            if (count($products) > config('imports.max_products')) {
                throw new ApiException(trans('api_errors.to_many_products',
                    ['max_products' => config('imports.max_products')]));
            }

            $productsToPrice = [];
            $additionalInfo  = [];
            $imagesToMove    = [];

            /** @var  $gruppedProducts */
            $gruppedProducts = [];
            foreach ($products as $product) {
                $sku = $product['sku'];
                if (empty($gruppedProducts[$sku])) {
                    $gruppedProducts[$sku] = $product;
                }

                if ( ! empty($product['variations'])) {
                    $gruppedProducts[$sku]['variations'] = array_unique(
                        array_merge($gruppedProducts[$sku]['variations'], $product['variations']), SORT_REGULAR
                    );
                }
            }

            sort($gruppedProducts);

            foreach ($gruppedProducts as $key => $product) {
                $product['primary_image'] = $product['primary_image'] ?? '';
                $imageIsUuid              = Str::isUuid($product['primary_image']);
                $product['country']       = $this->prepareProductCountry($product['country']);

                $rules = [
                    'product_id'                 => 'required_without:external_id|int|exists:products,id',
                    'external_id'                => 'required_without:product_id|string',
                    'sku'                        => 'required|max:255',
                    'barcode'                    => 'string|max:255|nullable',
                    'title'                      => 'required|string',
                    'description'                => 'required|string',
                    'category_id'                => ['required', new UserCategories],
                    'attributes'                 => 'nullable|array',
                    'attributes.*.name'          => 'required|string|max:255',
                    'attributes.*.value'         => 'required_with:attributes.*.name|string|max:255',
                    'primary_image'              => $imageIsUuid ? [
                        'required', 'uuid', new UserUuid
                    ] : 'required|active_url',
                    'images'                     => 'nullable|array',
                    'status'                     => ['required', Rule::in(['published', 'unpublished'])],
                    'weight'                     => 'required|numeric',
                    'length'                     => 'required|numeric',
                    'width'                      => 'required|numeric',
                    'height'                     => 'required|numeric',
                    'variations'                 => 'required|array',
                    'variations.*.id'            => 'required|uuid',
                    'variations.*.vendor_code'   => 'nullable|string|max:255',
                    'variations.*.barcode'       => 'string|nullable',
                    'variations.*.status'        => 'required|in:published,unpublished',
                    'variations.*.images'        => 'nullable|array',
                    'variations.*.items'         => 'required_with:variations|array',
                    'variations.*.items.*.value' => 'nullable|string',
                    'country'                    => ['required', 'string', new ExistsCountry()],
                ];

                if (isset($product['images'])) {
                    foreach ($product['images'] as $i => $image) {
                        $rules['images.'.$i] = Str::isUuid($image) ? [
                            'required', 'uuid', new UserUuid
                        ] : 'required|active_url';
                    }
                }

                if (isset($product['variations']['images'])) {
                    foreach ($product['variations']['images'] as $i => $image) {
                        $rules['variations.*.images.'.$i] = Str::isUuid($image) ? [
                            'required', 'uuid', new UserUuid
                        ] : 'required|active_url';
                    }
                }


                $validator = Validator::make($product, $rules);
                if ($validator->fails()) {
                    $additionalInfo[] = getAdditionalInfo($key, $validator, $product);
                    continue;
                }

                /** если фото было загружено по api - переносим его с временной папки в основную и удаляем запись */
                if ($imageIsUuid) {
                    $uuid                     = $product['primary_image'];
                    $product['primary_image'] = $this->getImageNewPath($uuid);

                    if ( ! empty($product['primary_image'])) {
                        $imagesToMove[] = $uuid;
                    }
                }

                if ( ! empty($product['product_id'])) {
                    $productInDb = Product::where('user_id', auth()->id())->find($product['product_id']);
                    if ( ! $productInDb) {
                        continue;
                    }
                } else {
                    $productInDb = Product::getUserProductByExternalId(auth()->user()->id, $product['external_id']);
                }


                $country = $this->getProductCountryByTitle($product['country']);

                $settings = ['originalSku' => $product['sku']];

                if ($productInDb) {
                    /** Если не включена настройка Обновлять существующие товары - пропускаем товар */
                    if (empty(getIntegrationImportSetting($integration, 'update_exists_products'))) {
                        continue;
                    }

                    /** удаляем старую фотографию из хранилища, если она там есть */
                    if ($productInDb->image) {
                        Storage::delete($productInDb->image);
                    }

                    $updated = $productInDb->update([
                        'external_id' => $product['external_id'] ?? $productInDb->external_id ?? '',
                        'title'       => $product['title'],
                        'sku'         => $this->prepareSku($product['sku'], $productInDb->user_id),
                        'description' => $product['description'],
                        'barcode'     => $product['barcode'] ?? $productInDb->barcode ?? generateBarcode(),
                        'status'      => $product['status'],
                        'image'       => $product['primary_image'],
                        'weight'      => $product['weight'],
                        'width'       => $product['width'],
                        'height'      => $product['height'],
                        'length'      => $product['length'],
                        'settings'    => array_merge($productInDb->settings ?? [], $settings),
                        'country_id'  => $country?->id,
                    ]);

                    if ($updated) {
                        $updatedCount++;
                    }
                } else {
                    $productInDb = Product::create(
                        [
                            'title'       => $product['title'],
                            'sku'         => $this->prepareSku($product['sku'], auth()->id()),
                            'user_id'     => auth()->user()->id,
                            'description' => $product['description'],
                            'barcode'     => $product['barcode'] ?? generateBarcode(),
                            'status'      => $product['status'],
                            'image'       => $product['primary_image'],
                            'weight'      => $product['weight'],
                            'width'       => $product['width'],
                            'height'      => $product['height'],
                            'length'      => $product['length'],
                            'brand_id'    => null,
                            'created_at'  => Carbon::now(),
                            'settings'    => $settings,
                            'external_id' => $product['external_id'],
                            'country_id'  => $country?->id,
                        ]
                    );

                    if ($productInDb) {
                        $createdCount++;
                    }
                }

                if ($productInDb) {
                    $productsToPrice[] = $productInDb->id;

                    /** Привязываем категорию */
                    $category = Category::getUserCategoryByExternalId(auth()->user()->id, $product['category_id']);
                    if ($category) {
                        $productInDb->category_id = $category->id;
                        $productInDb->save();

                        /** Если не задана системная категория - пробуем ее автоматически привязать */
                        if (empty($category->system_category_id)) {
                            SyncHelper::autoSyncCategory($category);
                        }
                    }

                    /** Привязываем атрибуты */
                    $attributeService->saveProductAttributes($productInDb, $product['attributes'] ?? []);

                    /** Привязываем дополнительные изображения */
                    $productInDb->images()->delete();
                    foreach ($product['images'] as $image) {
                        if (Str::isUuid($image)) {
                            $uuid  = $image;
                            $image = $this->getImageNewPath($uuid);

                            if ( ! empty($image)) {
                                $imagesToMove[] = $uuid;
                            }
                        }

                        if ( ! empty($image)) {
                            $productInDb->images()->create(['image' => $image]);
                        }
                    }

                    if (empty($product['variations'])) {
                        $productVariation = [
                            'vendor_code' => $productInDb->sku,
                            'product_id'  => $productInDb->id,
                            'id'          => \Str::uuid(),
                            'status'      => 'published',
                            'images'      => array_merge([$productInDb->image],
                                $productInDb->images()->pluck('image')->toArray()),
                            'data'        => ['isMain' => true],
                            'barcode'     => $productInDb->barcode
                        ];

                        $product['variations'][] = $productVariation;
                    }

                    /** Привязываем вариации */
                    $variationService->saveProductVariations($productInDb, $product['variations'], true);
                }
            }

            /** переносим временные фото в основную папку */
            if ($imagesToMove) {
                foreach ($imagesToMove as $item) {
                    $this->moveImageFromTmp($item);
                }
            }

            /** Если в импорте указаны прайс-листы - привязываем товары */
            $price_list_ids = PriceList::getPriceListsByIntegration($integration);
            if ($price_list_ids) {
                $priceLists = PriceList::whereIn('id', $price_list_ids)->where('user_id', auth()->user()->id)
                    ->get();

                foreach ($priceLists as $priceList) {
                    $priceList->products()->syncWithoutDetaching($productsToPrice);
                }
            }

            return $this->successResponse([
                [
                    'msg'            => trans('imports.created_updated_count',
                        [
                            'all'     => count($products),
                            'created' => $createdCount,
                            'updated' => $updatedCount,
                        ]),
                    'additionalInfo' => $additionalInfo,
                ],
            ]);
        } catch (QueryException $e) {
            $bindings = $e->getBindings();
            /** Если получили ошибку дублей */
            if ($e->getCode() == 23505 && stristr($e->getMessage(), 'products_unique_not_deleted')) {
                return $this->errorResponse([
                    [
                        'msg' => trans(
                            'api_errors.product_not_unique',
                            ['title' => $bindings[0], 'sku' => $bindings[1]]
                        )
                    ]
                ]);
            }

            logger()->critical($e);

            return $this->errorResponse([['msg' => trans('api_errors.system_error')]]);
        } catch (Exception $e) {
            return $this->errorResponse([['msg' => $e->getMessage()]]);
        }
    }

    public function destroy(ProductDeleteRequest $request)
    {
        try {
            $this->checkAuthByToken($request);

            $integration = $request->integration ?? new Integration();

            $products = $request->json('products');
            if ( ! $products) {
                throw new ApiException(trans('api_errors.need_products'));
            }
            $additionalInfo = [];

            $productIds = collect($products)->map(function ($item, $key) use (&$additionalInfo) {
                $validator = Validator::make($item, ['id' => 'required|string',]);

                if ($validator->fails()) {
                    $additionalInfo[] = getAdditionalInfo($key, $validator, $item);

                    return false;
                }

                $productInDb = Product::getUserProductById(auth()->user()->id, $item['id']);
                if ($productInDb) {
                    return $productInDb->id;
                }

                return false;
            })->reject(function ($value) {
                return $value === false;
            })->all();

            /** Если в импорте указаны прайс-листы - отвязываем товары */
            $price_list_ids = PriceList::getPriceListsByIntegration($integration);
            if ($price_list_ids) {
                $priceLists = PriceList::whereIn('id', $price_list_ids)->where('user_id', auth()->user()->id)
                    ->get();

                foreach ($priceLists as $priceList) {
                    $priceList->products()->detach($productIds);
                }
            }

            $deletedCount = Product::destroy($productIds);

            return $this->successResponse([
                [
                    'msg'            => trans('imports.deleted_count',
                        [
                            'all'     => count($products),
                            'deleted' => $deletedCount,
                        ]),
                    'additionalInfo' => $additionalInfo,
                ],
            ]);
        } catch (QueryException $e) {
            logger()->critical($e);

            return $this->errorResponse([['msg' => trans('api_errors.system_error')]]);
        } catch (Exception $e) {
            return $this->errorResponse([['msg' => $e->getMessage()]]);
        }
    }

    /**
     * @param ProductStoreRequest $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function sync(
        ProductStoreRequest $request
    ) {
        try {
            $this->checkAuthByToken($request);

            $updatedCount   = 0;
            $additionalInfo = [];

            $products       = $request->json('products');
            $userProductIds = Product::where('user_id', auth()->id())->pluck('id')->toArray();

            foreach ($products as $key => $product) {
                $rules = [
                    'product_id'  => ['required', 'numeric', Rule::in($userProductIds)],
                    'external_id' => 'required|string',
                ];

                $validator = Validator::make($product, $rules);
                if ($validator->fails()) {
                    $additionalInfo[] = getAdditionalInfo($key, $validator, $product);
                    continue;
                }

                $productInDb = Product::where(['user_id' => auth()->id(), 'id' => $product['product_id']]);

                if ($productInDb) {
                    $updated = $productInDb->update([
                        'external_id' => $product['external_id'],
                    ]);

                    if ($updated) {
                        $updatedCount++;
                    }
                }
            }

            return $this->successResponse([
                [
                    'msg'            => trans('imports.updated_count',
                        [
                            'all'     => count($products),
                            'updated' => $updatedCount,
                        ]),
                    'additionalInfo' => $additionalInfo,
                ],
            ]);
        } catch (QueryException $e) {
            logger()->critical($e);

            return $this->errorResponse([['msg' => trans('api_errors.system_error')]]);
        } catch (Exception $e) {
            return $this->errorResponse([['msg' => $e->getMessage()]]);
        }
    }

    /**
     * @param BaseRequest $request
     * @param ProductService $productService
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function list(BaseRequest $request, ProductService $productService)
    {
        try {
            $this->checkAuthByToken($request);

            $result = [
                'products' => []
            ];

            $priceList = getDefaultPriceList(auth()->user());

            $limit = $request->get('limit', 100);
            if ($limit > 1000) {
                $limit = 1000;
            }

            $offset = $request->get('offset', 0);

            $products      = $priceList->products()->offset($offset)->limit($limit)->get();
            $productsCount = $priceList->products()->count();

            foreach ($products as $product) {
                $result['products'][] = $productService->prepareProductToShow($product);
            }

            $result['has_next'] = $productsCount > ($limit + $offset);

            return $this->successResponse($result);
        } catch (QueryException $e) {
            logger()->critical($e);

            return $this->errorResponse([['msg' => trans('api_errors.system_error')]]);
        } catch (Exception $e) {
            return $this->errorResponse([['msg' => $e->getMessage()]]);
        }
    }


    /**
     * @param ProductShowRequest $request
     * @param ProductService $productService
     *
     * @return JsonResponse
     */
    public function show(ProductShowRequest $request, ProductService $productService)
    {
        $product_id  = $request->get('product_id');
        $external_id = $request->get('external_id');
        $barcode     = $request->get('barcode');

        try {
            $this->checkAuthByToken($request);

            $product = new Product();

            if ( ! empty($product_id)) {
                $product = Product::my()->findOrFail($product_id);
            } elseif ( ! empty($external_id)) {
                $product = Product::my()->where('external_id', $external_id)->firstOrFail();
            } elseif ( ! empty($barcode)) {
                $product = Product::my()->where('barcode', $barcode)->firstOrFail();
            }

            $result = $productService->prepareProductToShow($product);

            return $this->successResponse($result);
        } catch (QueryException $e) {
            logger()->critical($e);

            return $this->errorResponse([['msg' => trans('api_errors.system_error')]]);
        } catch (ModelNotFoundException $e) {
            logger()->critical($e);

            return $this->errorResponse([
                [
                    'msg' => trans('exports.errors.product_not_found',
                        ['product' => $product_id ?? $external_id ?? $barcode])
                ]
            ], 404);
        } catch (Exception $e) {
            return $this->errorResponse([['msg' => $e->getMessage()]]);
        }
    }
}
