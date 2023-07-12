<?php

namespace App\Observers;

use App\Jobs\ProductChangeStatusInMarketplaces;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceProductCategory;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;

class ProductObserver
{
    /**
     * Handle the Product "created" event.
     *
     * @param \App\Models\Product $product
     *
     * @return void
     */
    public function created(Product $product)
    {
        //
    }

    /**
     * Handle the Product "updated" event.
     *
     * @param \App\Models\Product $product
     *
     * @return void
     */
    public function updated(Product $product)
    {
        $oldStatus = $product->getOriginal('status');
        if ($oldStatus !== $product->status) {
            ProductChangeStatusInMarketplaces::dispatch($product);
        }

        /** Если у товара сменилась категория - нужно проверить и обновить связи категорий */
        if ($product->isDirty('category_id')) {
            $marketPlaceProductCategory = MarketplaceProductCategory::where([
                'user_id'     => $product->user_id,
                'sku'         => $product->sku,
                'category_id' => $product->getOriginal('category_id')
            ])->first();

            if ( ! empty($marketPlaceProductCategory)) {
                $marketPlaceProductCategory->update(['category_id' => $product->category_id]);
            }

            /** И для вариаций тоже */
            foreach ($product->variations as $variation) {
                $marketPlaceProductCategory = MarketplaceProductCategory::where([
                    'user_id'     => $product->user_id,
                    'sku'         => $variation->vendor_code,
                    'category_id' => $product->getOriginal('category_id')
                ])->first();

                if ( ! empty($marketPlaceProductCategory)) {
                    $marketPlaceProductCategory->update(['category_id' => $product->category_id]);
                }
            }
        }
    }

    /**
     * Handle the Product "deleted" event.
     *
     * @param \App\Models\Product $product
     *
     * @return void
     */
    public function deleted(Product $product)
    {
        //
    }

    public function deleting(Product $product)
    {
        /** Удаляем информацию о товаре в мп */
        MarketplaceProduct::where([
            'type'      => Product::PRICE_TYPE,
            'object_id' => $product->id,
            'user_id'   => $product->user_id
        ])->delete();

        /** Удаляем связи категорий товара */
        MarketplaceProductCategory::where([
            'user_id' => $product->user_id,
            'sku'     => $product->sku,
        ])->delete();

        /** Если фото залито к нам - удаляем */
        foreach ($product->images as $image) {
            if (filter_var($image, FILTER_VALIDATE_URL)) {
                continue;
            }

            try {
                Storage::delete($image->image);
            } catch (\Exception $e) {
                logger()->info($e->getMessage());
            }
        }

        $product->images()->delete();

        /** Если фото залито к нам - удаляем */
        if ( ! empty($product->image) && ! filter_var($product->image, FILTER_VALIDATE_URL)) {
            try {
                Storage::delete($product->image);
            } catch (\Exception $e) {
                logger()->info($e->getMessage());
            } finally {
                $product->image = '';
                $product->save();
            }
        }
    }

    /**
     * Handle the Product "restored" event.
     *
     * @param \App\Models\Product $product
     *
     * @return void
     */
    public function restored(Product $product)
    {
        //
    }

    /**
     * Handle the Product "force deleted" event.
     *
     * @param \App\Models\Product $product
     *
     * @return void
     */
    public function forceDeleted(Product $product)
    {
        //
    }
}
