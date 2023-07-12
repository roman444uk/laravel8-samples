<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Jobs\ProductsDelete;
use App\Models\Integration;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Services\MarketPlaceService;
use App\Services\Shop\ProductService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class ProductVariationController extends Controller
{
    /**
     * @param int $id
     * @param Request $request
     * @param ProductService $productService
     *
     * @return JsonResponse
     * @throws AuthorizationException
     */
    public function duplicate(int $id, Request $request, ProductService $productService)
    {
        try {
            $productVariation = $this->getModel($id);

            $product = Product::where(['user_id' => auth()->user()->id])->findOrFail($productVariation->product_id);

            $productService->duplicateByVariation($product, $request->get('full') ? null : $productVariation);

            return response()->json(['success' => true, 'redirect' => route('products.index')]);
        } catch (\Exception $e) {
            throw new AuthorizationException(trans('errors.forbidden'));
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param $id
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     * @throws AuthorizationException
     */
    public function destroy($id)
    {
        try {
            $productVariation = $this->getModel($id);

            $productVariation->delete();

            /** Если у товара не осталось вариаций - удаляем товар */
            if ($productVariation->product->variations()->count() === 0) {
                ProductsDelete::dispatch([$productVariation->product_id], $productVariation->product->user_id);
            }

            if (request()->wantsJson()) {
                return response()->json(['success' => true, 'redirect' => route('products.index')]);
            }

            return redirect(route('products.index'));
        } catch (\Exception $e) {
            throw new AuthorizationException(trans('errors.forbidden'));
        }
    }

    /**
     * @param int $id
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     * @throws AuthorizationException
     */
    public function status($id)
    {
        try {
            $productVariation = $this->getModel($id);

            $productVariation->status = match ($productVariation->status) {
                'published' => 'unpublished',
                'unpublished' => 'published',
            };

            $productVariation->save();

            if (request()->wantsJson()) {
                return response()->json(['success' => true, 'redirect' => route('products.index')]);
            }

            return redirect(route('products.index'));
        } catch (\Exception $e) {
            throw new AuthorizationException(trans('errors.forbidden'));
        }
    }

    /**
     * @param int $id
     *
     * @return \Illuminate\Contracts\Foundation\Application|JsonResponse|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     * @throws AuthorizationException
     */
    public function marketPlaceStatus(Request $request)
    {
        $validated = $request->validate([
            'products'    => 'array|required',
            'full'        => 'int|required',
            'marketplace' => 'string|nullable',
            'status'      => 'string|required'
        ], ['products.required' => trans('products.products_required')]);

        $productsIds = $validated['products'];
        $full        = intval($validated['full']);
        $marketPlace = $validated['marketplace'] ?? null;
        $status      = $validated['status'];

        $marketPlaces = $marketPlace ? [$marketPlace] : collect(getActiveMarketPlaces())->pluck('name')->all();

        $integrations = Integration::where(['user_id' => auth()->id()])->whereIn('type',
            $marketPlaces)->active()->get();

        /** Если выгрузка отключена */
        if (empty($integrations->count())) {
            throw new ApiException(trans('exports.errors.export_unpublished'));
        }

        $errors = [];

        foreach ($integrations as $integration) {
            if ( ! empty(getIntegrationExportSetting($integration, 'update_stocks'))) {
                $marketPlaceService = new MarketPlaceService($integration->type);

                switch ($status) {
                    case 'unpublished':
                        if ($full) {
                            $marketPlaceService->getProvider()->productsUnpublished($productsIds, $integration);
                        } else {
                            $marketPlaceService->getProvider()->productVariationsUnpublished($productsIds,
                                $integration);
                        }
                        break;
                    case 'published':
                        if ($full) {
                            $marketPlaceService->getProvider()->productsUpdatePricesAndStocks($productsIds,
                                $integration);
                        } else {
                            $marketPlaceService->getProvider()->productVariationsUpdatePricesAndStocks($productsIds,
                                $integration);
                        }
                        break;
                }
            } else {
                $errors[] = sprintf('Для отключения товара в %s нужно включить в настройках Интеграции "Выгружать остатки"',
                    $integration->type);
            }
        }

        if (request()->wantsJson()) {
            if ( ! empty($errors)) {
                return response()->json([
                    'success' => false, 'redirect' => route('products.index'), 'errors' => $errors
                ]);
            }

            return response()->json(['success' => true, 'redirect' => route('products.index')]);
        }

        return response()->redirectTo(route('products.index'));
    }

    /**
     * @param Request $request
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function massDelete(Request $request)
    {
        $user_id = auth()->id();

        $validated = $request->validate([
            'products'   => 'array|required',
            'products.*' => 'required|int'
        ], ['products.required' => trans('products.products_required')]);

        $productVariations = $validated['products'];
        $productIds        = [];

        if ( ! empty($productVariations)) {
            /** Удаляем по одному, чтобы можно было повесить observer при необходимости */
            foreach ($productVariations as $productVariationId) {
                $productVariation = $this->getModel($productVariationId);

                $productVariation->delete();

                $productIds[] = $productVariation->product_id;
            }
        }

        if ( ! empty($productIds)) {
            $productsToDelete = [];
            $products         = Product::whereIn('id', $productIds)->get();
            foreach ($products as $product) {
                /** Если у товара не осталось вариаций - удаляем товар */
                if ($product->variations->count() === 0) {
                    $productsToDelete[] = $product->id;
                }
            }

            ProductsDelete::dispatch($productsToDelete, $user_id);
        }

        if (request()->wantsJson()) {
            return response()->json(['success' => true, 'redirect' => route('products.index')]);
        }

        return response()->redirectTo(route('products.index'));
    }

    /**
     * @param int $id
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function changeImagesOrder(int $id, Request $request)
    {
        $productVariation = $this->getModel($id);

        $validated = $request->validate([
            'images'   => 'array|required',
            'images.*' => 'string'
        ]);

        $productVariation->images = $validated['images'];
        $productVariation->save();

        return response()->json(['success' => true]);
    }

    /**
     * @param int $id
     *
     * @return ProductVariation|null
     */
    private function getModel(int $id): ?ProductVariation
    {
        return ProductVariation::leftJoin('products', 'products.id', '=',
            'product_variations.product_id')
            ->where('products.user_id', auth()->id())->select('product_variations.*')->findOrFail($id);
    }
}
