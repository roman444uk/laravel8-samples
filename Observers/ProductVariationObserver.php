<?php

namespace App\Observers;

use App\Jobs\ProductVariationChangeStatusInMarketplaces;
use App\Models\MarketplaceProduct;
use App\Models\ProductVariation;
use App\Models\ProductVariationItem;
use Exception;
use Illuminate\Support\Facades\Storage;

class ProductVariationObserver
{
    /**
     * Handle the ProductVariation "created" event.
     *
     * @param \App\Models\ProductVariation $productVariation
     *
     * @return void
     */
    public function created(ProductVariation $productVariation)
    {
        //
    }

    /**
     * Handle the ProductVariation "updated" event.
     *
     * @param \App\Models\ProductVariation $productVariation
     *
     * @return void
     */
    public function updated(ProductVariation $productVariation)
    {
        $oldStatus = $productVariation->getOriginal('status');
        if ($oldStatus !== $productVariation->status) {
            ProductVariationChangeStatusInMarketplaces::dispatch($productVariation);
        }
    }

    /**
     * Handle the ProductVariation "deleted" event.
     *
     * @param \App\Models\ProductVariation $productVariation
     *
     * @return void
     */
    public function deleted(ProductVariation $productVariation)
    {
    }

    public function deleting(ProductVariation $productVariation)
    {
        /** Удаляем размеры вариации */
        foreach ($productVariation->items as $item) {
            /** Удаляем информацию о товаре в мп */
            MarketplaceProduct::where([
                'type'      => ProductVariationItem::PRICE_TYPE,
                'object_id' => $item->id,
                'user_id'   => $productVariation->product?->user_id ?? auth()->id()
            ])->delete();
        }

        /** Удаляем информацию о товаре в мп */
        MarketplaceProduct::where([
            'type'      => ProductVariation::PRICE_TYPE,
            'object_id' => $productVariation->id,
            'user_id'   => $productVariation->product?->user_id ?? auth()->id()
        ])->delete();

        /** Если фото залито к нам - удаляем */
        foreach ($productVariation->images as $image) {
            if (filter_var($image, FILTER_VALIDATE_URL)) {
                continue;
            }

            try {
                Storage::delete($image);
            } catch (Exception $e) {
                logger()->info($e->getMessage());
            }
        }
    }

    /**
     * Handle the ProductVariation "restored" event.
     *
     * @param \App\Models\ProductVariation $productVariation
     *
     * @return void
     */
    public function restored(ProductVariation $productVariation)
    {
        //
    }

    /**
     * Handle the ProductVariation "force deleted" event.
     *
     * @param \App\Models\ProductVariation $productVariation
     *
     * @return void
     */
    public function forceDeleted(ProductVariation $productVariation)
    {
        //
    }
}
