<?php

namespace App\Contracts;

use App\DTO\ExportInfoDTO;
use App\Models\Dictionary;
use App\Models\ExportInfo;
use App\Models\Integration;
use App\Models\Supplies\Supply;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

interface MarketPlace
{
    /**
     * @param Dictionary $category
     *
     * @return Collection
     */
    public function getCategoryAttributes(Dictionary $category): Collection;

    /**
     * @param array $args
     *
     * @return Collection
     */
    public function getDictionaryValues(array $args): Collection;

    /**
     * @param array $products
     * @param Integration $integration
     *
     * @return mixed
     */
    public function exportProducts(array $products, Integration $integration);

    /**
     * @param ExportInfo $exportInfo
     *
     * @return ExportInfoDTO
     * @throws \Spatie\DataTransferObject\Exceptions\UnknownProperties
     */
    public function exportStat(ExportInfo $exportInfo): ExportInfoDTO;

    /**
     * @param array $products
     * @param Integration $integration
     *
     * @return mixed
     */
    public function productsStatus(array $products, Integration $integration);

    /**
     * @param array $productIds
     * @param Integration $integration
     *
     * @return mixed
     */
    public function productsUpdatePricesAndStocks(array $productIds, Integration $integration);

    /**
     * @param Integration $integration
     *
     * @return JsonResponse
     */
    public function getWarehouses(Integration $integration): JsonResponse;

    /**
     * @param array $productIds
     * @param Integration $integration
     *
     * @return mixed
     */
    public function productsUnpublished(array $productIds, Integration $integration);

    /**
     * @param array $productVariationIds
     * @param Integration $integration
     *
     * @return mixed
     */
    public function productVariationsUnpublished(array $productVariationIds, Integration $integration);

    /**
     * @param array $productVariationIds
     * @param Integration $integration
     *
     * @return mixed
     */
    public function productVariationsUpdatePricesAndStocks(array $productVariationIds, Integration $integration);

    /**
     * @param Integration $integration
     *
     * @return int
     */
    public function checkConnection(Integration $integration);

    /**
     * @param Integration $integration
     *
     * @return mixed
     */
    public function importProducts(Integration $integration);

    /**
     * @param array $importedData
     *
     * @return void
     */
    public function saveImportedProducts(array $importedData);

    /**
     * @param string $url
     * @param string $marketPlace
     *
     * @return mixed
     */
    public function importDataFromUrl(string $url, string $marketPlace);

    /**
     * @return mixed
     */
    public function importMarketplaceAttributes();

    /**
     * @param int $user_id
     * @param array $keyData
     *
     * @return mixed
     */
    public function getLastOrders(int $user_id, array $keyData);

    /**
     * @param int $user_id
     *
     * @return mixed
     */
    public function openSupply(int $user_id);

    /**
     * @param Supply $supply
     *
     * @return mixed
     */
    public function closeSupply(Supply $supply);

    /**
     * @param int $user_id
     * @param array $keyData
     *
     * @return mixed
     */
    public function getSupplies(int $user_id, array $keyData);


    /**
     * @param int $user_id
     * @param array $keyData
     *
     * @return mixed
     */
    public function updateOrderStatuses(int $user_id, array $keyData);

    /**
     * @param array $productIds
     * @param string $priceType
     * @param Integration $integration
     *
     * @return mixed
     */
    public function productsUpdatePrices(array $productIds, string $priceType, Integration $integration);

    /**
     * @param array $productIds
     * @param string $priceType
     * @param Integration $integration
     *
     * @return mixed
     */
    public function productsUpdateStocks(array $productIds, string $priceType, Integration $integration);
    
    
    public function exportProductImages(array $imagesData, Integration $integration);
}
