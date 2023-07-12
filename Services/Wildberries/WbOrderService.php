<?php

namespace App\Services\Wildberries;

use App\Exceptions\BusinessException;
use App\Models\Integration;
use App\Models\Orders\Order;
use App\Services\Shop\OrderService;
use App\Services\Wildberries\Exceptions\ResponseException;
use App\Services\Wildberries\Exceptions\TokenRequiredException;
use App\Traits\ImageHelper;
use App\Traits\ProductHelper;
use Exception;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class WbOrderService
{
    use ImageHelper;
    use ProductHelper;

    private const MARKETPLACE = 'wildberries';

    /**
     * @param array $orders
     * @param Integration $integration
     *
     * @return array
     * @throws BusinessException
     * @throws HttpClientException
     * @throws RequestException
     * @throws ResponseException
     * @throws TokenRequiredException
     */
    public function getStickers(array $orders, Integration $integration): array
    {
        $formats  = ['png', 'svg'];
        $user_id  = $integration->user_id;
        $stickers = [];

        foreach ($formats as $format) {
            $fieldName          = $format === 'png' ? 'url' : $format;
            $ordersNeedStickers = [];

            foreach ($orders as $order) {
                if ( ! empty($order->settings['sticker'][$fieldName])) {
                    $stickers[$order->id] = $order->settings['sticker'];
                } else {
                    $ordersNeedStickers[] = (int)$order->order_uid;
                }
            }

            if ( ! empty($ordersNeedStickers)) {
                try {
                    $api         = new WbClient(getIntegrationSetting($integration, 'api_token'));
                    $newStickers = $api->getOrderStickers($ordersNeedStickers, $format);

                    foreach ($newStickers as &$sticker) {
                        if (empty($sticker['file'])) {
                            continue;
                        }

                        $orderId = (int)$sticker['orderId'];
                        $order   = Order::where(['user_id' => $user_id, 'order_uid' => $orderId])->first();

                        $sticker['order_id'] = $order->id;

                        switch ($format) {
                            case 'svg':
                                $fileName = sprintf('%s_%s.svg', hashids_encode($user_id), $sticker['orderId']);

                                if ($path = $this->uploadSvg(base64_decode($sticker['file']), $fileName, 'stickers')) {
                                    $sticker[$fieldName] = Storage::url($path.'/'.$fileName);
                                }
                                break;
                            case 'png':
                                $fileName = sprintf('%s_%s.png', hashids_encode($user_id), $sticker['orderId']);

                                if ($path = $this->uploadSticker($sticker['file'], $fileName, -90)) {
                                    $sticker[$fieldName] = Storage::url($path.'/'.$fileName);
                                }
                                break;
                        }

                        unset($sticker['file']);
                        $sticker = array_merge($stickers[$sticker['order_id']] ?? [], $sticker);

                        $order->update([
                            'settings' => array_merge($order->settings ?? [], ['sticker' => $sticker])
                        ]);
                    }

                    foreach ($newStickers as $newSticker) {
                        $stickers[$newSticker['order_id']] = $newSticker;
                    }
                } catch (TokenRequiredException|BusinessException|HttpClientException $e) {
                    throw $e;
                }
            }
        }

        return $stickers;
    }

    /**
     * @param Integration $integration
     * @param Order $order
     *
     * @return void
     * @throws ResponseException
     * @throws TokenRequiredException|BusinessException
     */
    public function cancel(Integration $integration, Order $order): void
    {
        /** Отменяем сборочное задание */
        try {
            $orderService = new OrderService();

            $api = new WbClient(getIntegrationSetting($integration, 'api_token'));
            $api->cancelOrder((int)$order->order_uid);

            /** Получаем текущий статус сборочного задания */
            $statuses = $api->getOrderStatuses([(int)$order->order_uid]);
            $statuses = collect($statuses)->keyBy('id');
            if ($statuses->has($order->order_uid)) {
                $currentStatus = $statuses->get($order->order_uid);

                if ($currentStatus['supplierStatus'] !== $order->status) {
                    $orderService->changeStatus($order, $currentStatus['supplierStatus']);
                }
            }
        } catch (Exception|BusinessException $e) {
            throw $e;
        }
    }

    /**
     * Метод для сопоставления товаров заказа с товарами в мпс
     * @return void
     */
    public function syncOrderProducts(): void
    {
        /** Получаем заказы, у которых не были привязаны товары и еще раз пробуем их привязать */
        $orders = Order::leftJoin('order_products', 'orders.id', '=', 'order_products.order_id')
            ->where('marketplace', self::MARKETPLACE)
            ->where(function ($query) {
                return $query->whereNull('order_products.product_id');
            })->get('orders.*');

        foreach ($orders as $order) {
            foreach ($order->products as $orderProduct) {
                if (empty($orderProduct->settings['barcode'])) {
                    continue;
                }

                /**
                 * @var Collection $existProducts
                 * @var Collection $existVariations
                 * @var Collection $existVariationItems
                 */
                [
                    'existProducts'       => $existProducts,
                    'existVariations'     => $existVariations,
                    'existVariationItems' => $existVariationItems,
                ] = $this->getUserProductsByBarcodes($order->user_id, [$orderProduct->settings['barcode']]);

                if (empty($existProducts->count())) {
                    continue;
                }

                $product              = $existProducts->first();
                $productVariation     = $existVariations->first();
                $productVariationItem = $existVariationItems->first();

                $settings = [];

                if ( ! empty($productVariation)) {
                    $settings['product_variation_id'] = $productVariation->id;
                }
                if ( ! empty($productVariationItem)) {
                    $settings['product_variation_item_id'] = $productVariationItem->id;
                }

                $orderProduct->product_id = $product->id;

                if ( ! empty($settings)) {
                    $orderProduct->settings = array_merge($orderProduct->settings ?? [], $settings);
                }

                if (empty($orderProduct->barcode)) {
                    $orderProduct->barcode = $orderProduct->settings['barcode'];
                }

                $orderProduct->save();
            }
        }

        /** Получаем товары без штрихкода и пробуем его найти и записать */
        $orders = Order::leftJoin('order_products', 'orders.id', '=', 'order_products.order_id')
            ->where('marketplace', self::MARKETPLACE)
            ->where(function ($query) {
                return $query->whereNull('order_products.barcode');
            })->get('orders.*');
        foreach ($orders as $order) {
            foreach ($order->products as $orderProduct) {
                if (empty($orderProduct->settings['barcode'])) {
                    continue;
                }

                $orderProduct->update(['barcode' => $orderProduct->settings['barcode']]);
            }
        }
    }
}
