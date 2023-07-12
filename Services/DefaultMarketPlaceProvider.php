<?php

namespace App\Services;

use App\Contracts\MarketPlace;
use App\DTO\ExportInfoDTO;
use App\Models\Dictionary;
use App\Models\ExportInfo;
use App\Models\Integration;
use App\Models\Supplies\Supply;
use App\Traits\ApiResponser;
use App\Traits\CategoryHelper;
use App\Traits\DictionaryHelper;
use App\Traits\ImportFromUrlTrait;
use App\Traits\ProductHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class DefaultMarketPlaceProvider implements MarketPlace
{
    use ApiResponser;
    use CategoryHelper;
    use ProductHelper;
    use DictionaryHelper;
    use ImportFromUrlTrait;

    public function getCategoryAttributes(Dictionary $category): Collection
    {
        return collect();
    }

    public function getDictionaryValues(array $args): Collection
    {
        return collect();
    }

    public function exportProducts(array $products, Integration $integration)
    {
        return collect();
    }

    public function exportStat(ExportInfo $exportInfo): ExportInfoDTO
    {
        return new ExportInfoDTO([]);
    }

    public function productsStatus(array $products, Integration $integration)
    {
        // TODO: Implement productsStatus() method.
    }

    public function productsUpdatePricesAndStocks(array $productIds, Integration $integration)
    {
        // TODO: Implement productsUpdatePricesAndStocks() method.
    }

    public function getWarehouses(Integration $integration): JsonResponse
    {
        return $this->successResponse([]);
    }

    public function productsUnpublished(array $productIds, Integration $integration)
    {
        // TODO: Implement productsUnpublished() method.
    }

    public function productVariationsUnpublished(array $productVariationIds, Integration $integration)
    {
        // TODO: Implement productVariationsUnpublished() method.
    }

    public function productVariationsUpdatePricesAndStocks(array $productVariationIds, Integration $integration)
    {
        // TODO: Implement productVariationsUpdatePricesAndStocks() method.
    }

    public function checkConnection(Integration $integration)
    {
        // TODO: Implement checkConnection() method.
    }

    public function importProducts(Integration $integration)
    {
        // TODO: Implement importProducts() method.
    }

    public function importMarketplaceAttributes()
    {
        // TODO: Implement importMarketplaceAttributes() method.
    }

    public function getLastOrders(int $user_id, array $keyData)
    {
        // TODO: Implement getOrders() method.
    }

    public function openSupply(int $user_id)
    {
        // TODO: Implement openSupply() method.
    }

    public function closeSupply(Supply $supply)
    {
        // TODO: Implement closeSupply() method.
    }

    public function saveImportedProducts(array $importedData)
    {
        // TODO: Implement saveImportedProducts() method.
    }

    public function getSupplies(int $user_id, array $keyData)
    {
        // TODO: Implement getSupplies() method.
    }

    public function updateOrderStatuses(int $user_id, array $keyData)
    {
        // TODO: Implement updateOrderStatuses() method.
    }

    public function productsUpdatePrices(array $productIds, string $priceType, Integration $integration)
    {
        // TODO: Implement productsUpdatePrices() method.
    }

    public function productsUpdateStocks(array $productIds, string $priceType, Integration $integration)
    {
        // TODO: Implement productsUpdateStocks() method.
    }

    public function exportProductImages(array $imagesData, Integration $integration)
    {
        // TODO: Implement exportProductImages() method.
    }
}
