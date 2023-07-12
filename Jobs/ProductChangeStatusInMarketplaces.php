<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Models\MarketplaceProduct;
use App\Models\Product;
use App\Services\MarketPlaceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProductChangeStatusInMarketplaces implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;


    protected Product $product;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Product $product)
    {
        $this->product = $product;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        MarketplaceProduct::where(
            ['type' => Product::PRICE_TYPE, 'object_id' => $this->product->id, 'status' => 'success']
        )->each(function (MarketplaceProduct $marketPlaceProduct) {
            $integrations = Integration::where([
                'user_id' => auth()->id(),
                'type'    => $marketPlaceProduct->marketplace
            ])->active()->get();

            /** Если выгрузка отключена */
            if (empty($integrations->count())) {
                return;
            }

            foreach ($integrations as $integration) {
                if ( ! empty(getIntegrationExportSetting($integration, 'update_stocks'))) {
                    $marketPlaceService = new MarketPlaceService($integration->type);

                    switch ($this->product->status) {
                        case 'unpublished':
                            $marketPlaceService->getProvider()->productsUnpublished([$this->product->id],
                                $integration);
                            break;
                        case 'published':
                            $marketPlaceService->getProvider()->productsUpdatePricesAndStocks([$this->product->id],
                                $integration);
                            break;
                    }
                }
            }
        });
    }
}
