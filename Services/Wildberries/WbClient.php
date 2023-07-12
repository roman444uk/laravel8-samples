<?php

namespace App\Services\Wildberries;

use App\DTO\WarehouseDTO;
use App\Services\Wildberries\Exceptions\ResponseException;
use App\Services\Wildberries\Exceptions\TokenRequiredException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\DataTransferObject\Exceptions\UnknownProperties;

final class WbClient
{
    use ExportHelper;

    private string $apiUrl = 'https://suppliers-api.wildberries.ru/';

    private PendingRequest $client;

    /**
     * @throws TokenRequiredException
     */
    public function __construct(?string $token)
    {
        if (empty($token)) {
            throw new TokenRequiredException();
        }

        $this->client = Http::withHeaders(['Authorization' => $token])->timeout(10);
    }

    /**
     * Метод получает список категорий wb
     *
     * @param int $limit
     *
     * @return Collection
     */
    public function getAllCategories(int $limit = 10): Collection
    {
        $categories = collect();
        $cacheKey   = sprintf('wildberries_categories_all_%s', md5($limit));

        try {
            $categories = Cache::remember($cacheKey, 43200, function () use ($limit) {
                return $this->sendData('content/v1/object/all', ['top' => $limit]);
            });
        } catch (ResponseException $e) {
            logger()->critical($e);
        } catch (HttpClientException $e) {
            logger()->critical($e);
        }

        return $categories;
    }

    /**
     * Метод получает всю информацию по категории wb с требованиями к атрибутам
     *
     * @param string $category
     *
     * @return Collection
     */
    public function getCategoryCharacteristics(string $category): Collection
    {
        $config   = collect();
        $cacheKey = sprintf('wildberries_category_characteristics_%s', md5($category));

        try {
            $config = Cache::remember($cacheKey, 86400, function () use ($category) {
                activity()->log('get wildberries characteristics from api: '.$category);

                return $this->sendData(sprintf('content/v1/object/characteristics/%s', $category), []);
            });
        } catch (ResponseException|HttpClientException $e) {
            if (substr_count($e->getMessage(),
                    'Категория из выбранной карточки товара запрещена к реализации') && ! empty($category)) {
                logger()->critical(sprintf('wildberries category disallow %s', $category));
            } else {
                logger()->critical($e);
            }
        }

        return $config;
    }

    /**
     * Метод получает указанный справочник wb
     *
     * @param string $dictionary
     * @param int|null $id
     * @param int $limit
     * @param string $pattern
     *
     * @return Collection
     */
    public function getDictionary(
        string $dictionary,
        string $pattern = '',
        int $limit = 10
    ): Collection {
        $values = collect();

        try {
            $data = $this->sendData('content/v1/directory/'.$dictionary,
                ['top' => $limit, 'pattern' => $pattern]);

            foreach ($data as $value) {
                if (is_array($value)) {
                    $values[] = [
                        'id'   => $value['id'] ?? null,
                        'name' => $value['name'] ?? null,
                    ];
                } else {
                    $values[] = [
                        'id'   => null,
                        'name' => $value,
                    ];
                }
            }
        } catch (ResponseException $e) {
            logger()->critical($e);
        } catch (HttpClientException $e) {
            logger()->critical($e);
        }

        return $values;
    }

    /**
     * Создание карточки товара
     *
     * @param array $products
     *
     * @return Collection
     */
    public function createCard(array $products): Collection
    {
        $values = collect();

        try {
            $values = $this->sendData(
                'content/v1/cards/upload',
                $products,
                'post',
                true
            );
        } catch (ResponseException $e) {
            logger()->critical($e);
        } catch (HttpClientException $e) {
            logger()->critical($e);
        }

        return $values;
    }

    /**
     * Обновление карточки товара
     *
     * @param array $product
     *
     * @return array
     */
    public function updateCard(array $products): array
    {
        $result = ['success' => false, 'error' => ''];

        try {
            $data = $this->sendData(
                'content/v1/cards/update',
                $products,
                'post',
            );

            $response = $data->get('response');
            if ( ! empty($response['error'])) {
                throw new ResponseException($response['errorText'] ?? $response['error']['data']['cause']['err'] ?? '');
            }

            $result['success'] = true;
        } catch (ResponseException $e) {
            $result['error'] = $e->getUserMessage();
        } catch (HttpClientException $e) {
            logger()->critical($e);
        }

        return $result;
    }

    /**
     * Добавление номенклатур к карточке товара
     *
     * @param string $vendorCode
     * @param array $products
     *
     * @return Collection
     */
    public function addNomenclaturesToCard(string $vendorCode, array $products): Collection
    {
        $values = collect();

        try {
            $values = $this->sendData(
                'content/v1/cards/upload/add',
                [
                    'vendorCode' => $vendorCode,
                    'cards'      => $products
                ],
                'post',
                true
            );
        } catch (ResponseException $e) {
            logger()->critical($e);
        } catch (HttpClientException $e) {
            logger()->critical($e);
        }

        return $values;
    }

    /**
     * @param array $vendorCodes
     *
     * @return array
     */
    public function getCardBySupplierVendorCode(array $vendorCodes): array
    {
        $cards = [];

        try {
            $data = $this->sendData(
                'content/v1/cards/filter',
                [
                    'vendorCodes' => $vendorCodes
                ],
                'post',
                true
            );

            $cards = $data->get('response')['data'] ?? [];
        } catch (ResponseException $e) {
            logger()->critical($e);
        } catch (HttpClientException $e) {
            logger()->critical($e);
        }

        return $cards;
    }

    /**
     * @param array|null $supplierVendorCodes
     *
     * @return array|null
     */
    public function getErrorsBySupplierVendorCode(array $supplierVendorCodes = null): ?array
    {
        $errorCards = null;

        try {
            $data = $this->sendData(
                'content/v1/cards/error/list',
                [],
                'get',
                true
            );

            $cards = $data->get('response')['data'] ?? [];
            foreach ($cards as $card) {
                if (in_array($card['vendorCode'], $supplierVendorCodes)) {
                    $errorCards[$card['vendorCode']] = $card;
                }
            }
        } catch (ResponseException $e) {
            logger()->critical($e);
        } catch (HttpClientException $e) {
            logger()->critical($e);
        }

        return $errorCards;
    }

    /**
     * Получение информации по номенклатурам, их ценам, скидкам и промокодам
     *
     * @return Collection
     */
    public function getPrices(): Collection
    {
        $items = collect();

        try {
            $data = $this->sendData(
                'public/api/v1/info',
                [],
                'get'
            );

            $items = collect($data->get('response') ?? []);
        } catch (ResponseException $e) {
            logger()->critical($e);
        } catch (HttpClientException $e) {
            logger()->critical($e);
        }

        return $items;
    }

    /**
     * @param array $prices
     *
     * @return bool
     * @throws RequestException
     * @throws ResponseException
     */
    public function updatePrices(array $prices): bool
    {
        try {
            $this->sendData(
                'public/api/v1/prices',
                $prices,
                'post'
            );
        } catch (ResponseException|HttpClientException $e) {
            throw $e;
        }

        return true;
    }

    /**
     * @param int $warehouse_id
     * @param array $stocks
     *
     * @return bool
     * @throws RequestException
     * @throws ResponseException
     */
    public function updateStocks(int $warehouse_id, array $stocks): bool
    {
        try {
            $this->sendData(
                sprintf('api/v3/stocks/%s', $warehouse_id),
                ['stocks' => $stocks],
                'put'
            );
        } catch (ResponseException|HttpClientException $e) {
            throw $e;
        }

        return true;
    }

    /**
     * Получение остатков товара
     *
     * @param string $warehouse
     * @param array $skus
     *
     * @return array
     */
    public function getStocks(string $warehouse, array $skus): array
    {
        $stocks = [];

        try {
            $apiResponse = $this->sendData(
                sprintf('api/v3/stocks/%s', $warehouse),
                ['skus' => $skus],
                'post',
                true
            );

            $stocks = $apiResponse['response']['stocks'] ?? [];
        } catch (ResponseException|HttpClientException $e) {
            logger()->critical($e);
        }

        return $stocks;
    }

    /**
     * Получение складов поставщика
     * @return array|WarehouseDTO[]
     */
    public function getWarehouses(): array
    {
        $warehouses = [];

        try {
            $apiResponse = $this->sendData(
                'api/v3/warehouses',
                [],
                'get',
                true
            );
            if ($apiResponse->has('response')) {
                foreach ($apiResponse['response'] as $warehouse) {
                    if (empty($warehouse['id']) || empty($warehouse['name'])) {
                        continue;
                    }

                    try {
                        $warehouses[] = new WarehouseDTO(['id' => (int)$warehouse['id'], 'name' => $warehouse['name']]);
                    } catch (UnknownProperties $e) {
                        logger()->critical($e);
                    }
                }
            }
        } catch (ResponseException|HttpClientException $e) {
            logger()->critical($e);
        }

        return $warehouses;
    }

    /**
     * Получени списка всех номенклатур
     *
     * @param int $offset
     * @param int $limit
     *
     * @return array|mixed
     * @throws HttpClientException
     * @throws RequestException
     * @throws ResponseException
     */
    public function getAllNomenclatures(int $limit = 1000, $requestCursor = [])
    {
        try {
            $data = $this->sendData(
                'content/v1/cards/cursor/list',
                [
                    'sort' => [
                        'cursor' => [
                            'updatedAt' => $requestCursor['updatedAt'] ?? null,
                            'nmID'      => $requestCursor['nmID'] ?? null,
                            'limit'     => $limit
                        ],
                        'filter' => [
                            'withPhoto' => -1
                        ]
                    ],
                ],
                'post',
                true
            );

            $response = $data->get('response');
            if ( ! empty($response['error'])) {
                throw new ResponseException($response['errorText'] ?? $response['error']['data']['cause']['err'] ?? '');
            }

            $variations     = $response['data']['cards'] ?? [];
            $responseCursor = $response['data']['cursor'] ?? [];

            if ($responseCursor['total'] >= $limit) {
                $variations = array_merge($variations, $this->getAllNomenclatures($limit, $responseCursor));
            }
        } catch (ResponseException $e) {
            logger()->critical($e);
            throw $e;
        } catch (HttpClientException $e) {
            logger()->critical($e);
            throw $e;
        }

        return $variations;
    }

    /**
     * @param int $offset
     * @param int $limit
     *
     * @return array
     */
    public function getAllProducts(int $offset = 0, int $limit = 1000): array
    {
        $products = [];

        $marketPlaceNomenclatures = collect($this->getAllNomenclatures($limit))->keyBy('vendorCode');
        $vendorCodes              = $marketPlaceNomenclatures->keys();

        do {
            $vendorCodesScope = $vendorCodes->splice(0, 100);

            if ($vendorCodesScope->count() > 0) {
                $newProducts = $this->getAllProductsByVendorCode($vendorCodesScope->all());

                foreach ($newProducts as $product) {
                    $item = $marketPlaceNomenclatures->get($product['vendorCode']);
                    if ( ! $item) {
                        continue;
                    }

                    $item['card'] = $product;
                    $products[]   = $item;
                }
            }
        } while ($vendorCodes->count() > 0);

        return $products;
    }

    /**
     * @param array $vendorCodes
     *
     * @return array
     */
    public function getAllProductsByVendorCode(array $vendorCodes): array
    {
        $products = [];

        try {
            $data = $this->sendData(
                'content/v1/cards/filter',
                [
                    'vendorCodes' => $vendorCodes
                ],
                'post',
                true
            );

            $response = $data->get('response');
            if ( ! empty($response['error'])) {
                throw new ResponseException($response['errorText'] ?? $response['error']['data']['cause']['err'] ?? '');
            }

            $products = $response['data'] ?? [];
        } catch (ResponseException $e) {
            logger()->critical($e);
        } catch (HttpClientException $e) {
            logger()->critical($e);
        }

        return $products;
    }

    /**
     * @return int
     *
     * @throws HttpClientException
     * @throws RequestException
     * @throws ResponseException
     */
    public function getProductsTotalCount(): int
    {
        try {
            $marketPlaceProducts = $this->getAllProducts();

            return count($marketPlaceProducts);
        } catch (ResponseException $e) {
            logger()->critical($e);
            throw $e;
        } catch (HttpClientException $e) {
            logger()->critical($e);
            throw $e;
        }
    }

    /**
     * @param int $count
     *
     * @return array
     */
    public function generateBarcodes(int $count = 1): array
    {
        $barcodes = [];
        try {
            $data = $this->sendData(
                '/content/v1/barcodes',
                [
                    'count' => $count
                ],
                'post',
                true
            );

            $response = $data->get('response');
            if ( ! empty($response['error'])) {
                throw new ResponseException($response['errorText'] ?? $response['error']['data']['cause']['err'] ?? '');
            }

            $barcodes = $response['data'] ?? [];
        } catch (ResponseException $e) {
            logger()->critical($e);
        } catch (HttpClientException $e) {
            logger()->critical($e);
        } finally {
            return $barcodes;
        }
    }

    /**
     * @param string $date_start
     * @param int $limit
     * @param int $offset
     * @param string|null $date_end
     * @param int|null $status
     *
     * @return array
     */
    public function getOrders(
        string $date_start,
        int $limit = 10000,
        int $offset = 0,
        ?string $date_end = null,
        ?int $status = null
    ): array {
        $orders = [];

        try {
            /** За один запрос можно забрать только 1000 заказов */
            $take = ($limit > 1000) ? 1000 : $limit;

            $query = [
                'next'     => $offset,
                'limit'    => $take,
                'dateFrom' => $date_start
            ];

            if ( ! empty($date_end)) {
                $query['dateTo'] = $date_end;
            }

            if ( ! empty($status)) {
                $query['status'] = $status;
            }

            $data = $this->sendData(
                'api/v3/orders',
                $query,
                'get',
                true
            );

            $response = $data->get('response');
            if ( ! empty($response['error'])) {
                throw new ResponseException($response['errorText'] ?? '');
            }

            $orders = array_merge($orders, $response['orders'] ?? []);
            $next   = $response['next'] ?? 0;

            /** Если заказов больше, чем было получено и лимит еще не выполнен - выбираем еще */
            if ( ! empty($next) && $limit > count($orders)) {
                $orders = array_merge($orders, $this->getOrders($date_start, $limit - count($orders), $next));
            }
        } catch (ResponseException $e) {
            logger()->critical($e);
        } catch (HttpClientException $e) {
            logger()->critical($e);
        } finally {
            return $orders;
        }
    }

    /**
     * @param int $limit
     * @param int $offset
     *
     * @return array
     * @throws HttpClientException
     * @throws RequestException
     * @throws ResponseException
     */
    public function getSupplies(
        int $limit = 10000,
        int $offset = 0,
    ): array {
        $supplies = [];

        try {
            /** За один запрос можно забрать только 1000 поставок */
            $take = ($limit > 1000) ? 1000 : $limit;

            $query = [
                'next'  => $offset,
                'limit' => $take,
            ];

            $data = $this->sendData(
                'api/v3/supplies',
                $query,
            );

            $response = $data->get('response');

            $supplies = array_merge($supplies, $response['supplies'] ?? []);
            $next     = $response['next'] ?? 0;

            /** Если заказов больше, чем было получено и лимит еще не выполнен - выбираем еще */
            if ( ! empty($next) && $limit > count($supplies)) {
                $supplies = array_merge($supplies, $this->getSupplies($limit - count($supplies), $next));
            }
        } catch (ResponseException|HttpClientException $e) {
            logger()->critical($e);
            throw $e;
        }

        return $supplies;
    }

    /**
     * Метод для получения статусов сборочных заданий
     *
     * @param array $orderIds
     *
     * @return array
     */
    public function getOrderStatuses(array $orderIds): array
    {
        try {
            $data = $this->sendData(
                'api/v3/orders/status',
                [
                    'orders' => $orderIds
                ],
                'post',
                true
            );

            $response = $data->get('response');
            $orders   = $response['orders'];
        } catch (ResponseException|HttpClientException $e) {
            logger()->critical($e);

            throw $e;
        }

        return $orders;
    }

    /**
     * @param string $name
     *
     * @return string|null
     */
    public function openSupply(string $name = ''): ?string
    {
        if ($this->isTesMode()) {
            return 'WB-GI-TEST'.rand(1000, 9999);
        }

        try {
            $data = $this->sendData(
                'api/v3/supplies',
                ['name' => $name ?: Str::uuid()],
                'post'
            );

            $response = $data->get('response');
            if ( ! empty($response['error'])) {
                throw new ResponseException($response['errorText'] ?? $response['error']['data']['cause']['err'] ?? '');
            }

            $supplyId = $response['id'] ?? null;
        } catch (ResponseException|HttpClientException $e) {
            logger()->critical($e);

            throw $e;
        }

        return $supplyId;
    }

    /**
     * Удаление поставки - в случае успеха ничего не возвращает
     *
     * @param string $supply
     *
     * @return bool
     */
    public function deleteSupply(string $supply): bool
    {
        if ($this->isTesMode()) {
            return true;
        }

        try {
            $this->sendData(
                sprintf('api/v3/supplies/%s', $supply),
                [],
                'delete'
            );
        } catch (ResponseException|HttpClientException $e) {
            logger()->critical($e);

            throw $e;
        }

        return true;
    }

    /**
     * Получение сборочных заданий в поставке
     *
     * @param string $supply
     *
     * @return array
     */
    public function getSupplyOrders(string $supply): array
    {
        $orders = [];

        try {
            $data = $this->sendData(
                sprintf('api/v3/supplies/%s/orders', $supply),
                [],
                'get',
                true
            );

            $response = $data->get('response');
            if ( ! empty($response['error'])) {
                throw new ResponseException($response['errorText'] ?? '');
            }

            $orders = $response['orders'] ?? [];
        } catch (ResponseException|HttpClientException $e) {
            logger()->critical($e);
        } finally {
            return $orders;
        }
    }

    /**
     * Добавление к поставке сборочного задания
     *
     * @param string $supply
     * @param int $order
     *
     * @return bool
     */
    public function addOrderToSupply(string $supply, int $order): bool
    {
        if ($this->isTesMode()) {
            return true;
        }

        try {
            $this->sendData(
                sprintf('api/v3/supplies/%s/orders/%s', $supply, $order),
                [],
                'patch'
            );
        } catch (ResponseException|HttpClientException $e) {
            logger()->critical($e);

            throw $e;
        }

        return true;
    }

    /**
     * Передача поставки в доставку
     *
     * @param string $supply
     *
     * @return bool
     */
    public function supplyDeliver(string $supply): bool
    {
        if ($this->isTesMode()) {
            return true;
        }

        try {
            $this->sendData(
                sprintf('api/v3/supplies/%s/deliver', $supply),
                [],
                'patch'
            );
        } catch (ResponseException|HttpClientException $e) {
            logger()->critical($e);

            throw $e;
        }

        return true;
    }

    /**
     * Получает этикетки сборочных заданий
     *
     * @param array $order_ids
     * @param string $type
     * @param int $width
     * @param int $height
     *
     * @return array
     * @throws HttpClientException
     * @throws RequestException
     * @throws ResponseException
     */
    public function getOrderStickers(array $order_ids, string $type = 'png', int $width = 58, int $height = 40): array
    {
        try {
            $data = $this->sendData(
                sprintf('api/v3/orders/stickers?type=%s&width=%s&height=%s', $type, $width, $height),
                ['orders' => $order_ids],
                'post'
            );

            $response = $data->get('response');
            $stickers = $response['stickers'] ?? [];
        } catch (ResponseException|HttpClientException $e) {
            logger()->critical($e);
            throw $e;
        }

        return $stickers;
    }

    /**
     * @param string $supply
     * @param string $type
     * @param int $width
     * @param int $height
     *
     * @return array
     * @throws HttpClientException
     * @throws RequestException
     * @throws ResponseException
     */
    public function getSupplyBarcode(string $supply, string $type = 'png', int $width = 58, int $height = 40): array
    {
        if ($this->isTesMode()) {
            return [];
        }

        try {
            $data = $this->sendData(
                sprintf('api/v3/supplies/%s/barcode?type=%s&width=%s&height=%s', $supply, $type, $width, $height),
                compact('type', 'width', 'height')
            );

            $response = $data->get('response');
        } catch (ResponseException|HttpClientException $e) {
            logger()->critical($e);
            throw $e;
        }

        return $response;
    }


    /**
     * Отмена сборочного задания
     *
     * @param int $order
     *
     * @return bool
     * @throws HttpClientException
     * @throws RequestException
     * @throws ResponseException
     */
    public function cancelOrder(int $order): bool
    {
        if ($this->isTesMode()) {
            return true;
        }

        try {
            $this->sendData(
                sprintf('api/v3/orders/%s/cancel', $order),
                [],
                'patch'
            );
        } catch (ResponseException|HttpClientException $e) {
            logger()->critical($e);

            throw $e;
        }

        return true;
    }

    /** Загрузка фоток для товара
     *
     * @param array $data
     *
     * @return bool
     * @throws HttpClientException
     * @throws RequestException
     * @throws ResponseException
     */
    public function mediaSave(array $data): bool
    {
        try {
            $this->sendData(
                'content/v1/media/save',
                $data,
                'post'
            );
        } catch (ResponseException|HttpClientException $e) {
            logger()->critical($e);

            throw $e;
        }

        return true;
    }

    /**
     * @throws RequestException
     * @throws ResponseException
     */
    private function sendData(string $url, ?array $data, string $method = 'get', bool $returnFull = false): Collection
    {
        $result = collect();

        $response = match ($method) {
            'post' => $this->client->post($this->apiUrl.$url, $data),
            'delete' => $this->client->delete($this->apiUrl.$url, $data),
            'patch' => $this->client->patch($this->apiUrl.$url, $data),
            'put' => $this->client->put($this->apiUrl.$url, $data),
            default => $this->client->get($this->apiUrl.$url, $data),
        };

        if ($response->successful()) {
            $json = $response->json();

            if (config('exports.wb_debug')) {
                logger()->info($json);
            }

            if ($returnFull) {
                return collect(['response' => $json]);
            }

            if (empty($json['error'])) {
                $result = collect($json['data'] ?? ['response' => $json]);
            } else {
                throw new ResponseException($json['errorText'] ?? $json['error']['data']['cause']['err'] ?? '');
            }
        } else {
            if (config('exports.wb_debug')) {
                logger()->info($response);
            }

            if ($response->clientError()) {
                $json    = $response->json();
                $code    = $json['code'] ?? '';
                $message = $json['message'] ?? $json['errorText'] ?? $response->body();

                if ( ! empty($code)) {
                    $message .= ' Код ошибки wildberries: '.$code;
                }

                throw new ResponseException($message);
            }

            $response->throw();
        }

        return $result;
    }

    /**
     * Проверяем включен ли тестовый режим
     * @return bool
     */
    private function isTesMode(): bool
    {
        $testMode = config('app.wb_test_mode', true);

        if ( ! empty($testMode)) {
            logger()->info('включен тестовый режим wildberries');
        }

        return $testMode;
    }

}
