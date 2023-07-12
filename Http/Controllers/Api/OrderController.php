<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessException;
use App\Http\Requests\Api\BaseRequest;
use App\Http\Requests\Api\Orders\CancelReasonsRequest;
use App\Http\Requests\Api\Orders\ChangeStatusRequest;
use App\Http\Requests\Api\Orders\DeliveriesRequest;
use App\Http\Requests\Api\StickersRequest;
use App\Models\Orders\Order;
use App\Services\Shop\OrderService;
use Carbon\Carbon;
use DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Throwable;

class OrderController extends BaseApiController
{
    /**
     * Список заказов
     *
     * @param BaseRequest $request
     * @param OrderService $orderService
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function list(BaseRequest $request, OrderService $orderService)
    {
        try {
            $this->checkAuthByToken($request);

            $where    = ['user_id' => auth()->id()];
            $whereRaw = [];
            $whereIn  = [];

            $marketPlaces = \Arr::pluck(getActiveMarketPlaces(), 'name');
            if ( ! empty($request->get('marketplace')) && in_array($request->get('marketplace'), $marketPlaces)) {
                $where['marketplace'] = $request->get('marketplace');
            }

            if ( ! empty($request->get('date_from'))) {
                $dateFrom = Carbon::createFromFormat('d-m-Y', $request->get('date_from'));
                if ( ! empty($dateFrom)) {
                    $whereRaw[] = ' order_created >= '.DB::connection()->getPdo()->quote($dateFrom->toDateString());
                }
            }

            if ( ! empty($request->get('date_to'))) {
                $dateTo = Carbon::createFromFormat('d-m-Y', $request->get('date_to'));
                if ( ! empty($dateTo)) {
                    $whereRaw[] = 'order_created <= '.DB::connection()->getPdo()->quote($dateTo->toDateString());
                }
            }

            if ( ! empty($request->get('status'))) {
                $whereIn['status'] = $request->get('status');
            }

            if ( ! empty($request->get('order_type'))) {
                $where['additional_data->order_type'] = $request->get('order_type');
            }

            if ( ! empty($request->get('shipment_date'))) {
                $shipmentDate = Carbon::createFromFormat('d-m-Y', $request->get('shipment_date'));
                $whereRaw[]   = 'DATE(delivery->>\'shipment_date\') = '.DB::connection()->getPdo()->quote($shipmentDate->toDateString());
            }

            $result = [];
            $query  = Order::where($where);

            foreach ($whereRaw as $item) {
                $query->whereRaw($item);
            }

            foreach ($whereIn as $key => $value) {
                $query->whereIn($key, $value);
            }

            $orders = $query->orderBy('id')->get();

            foreach ($orders ?? [] as $order) {
                $result[] = $orderService->prepareOrderToApi($order);
            }

            return $this->successResponse($result);
        } catch (QueryException $e) {
            logger()->critical($e);

            return $this->errorResponse([['msg' => trans('api_errors.system_error')]]);
        } catch (Throwable $e) {
            return $this->errorResponse([['msg' => $e->getMessage()]]);
        }
    }


    /**
     * @param BaseRequest $request
     * @param OrderService $orderService
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancel(BaseRequest $request, OrderService $orderService)
    {
        try {
            $this->checkAuthByToken($request);

            $order_id = $request->route('order_id');
            $order    = Order::where('user_id', auth()->id())->where(function ($query) use ($order_id) {
                return $query->where('id', (int)$order_id)->orWhere('order_uid', $order_id);
            })->firstOrFail();

            $orderService->cancelOrder($order, $request->get('cancel_reason'));

            return $this->successResponse(['msg' => trans('panel.operation_success')]);
        } catch (ModelNotFoundException $e) {
            logger()->error($e);

            return $this->errorResponse([['msg' => trans('api_errors.item_not_found')]]);
        } catch (QueryException $e) {
            logger()->critical($e);

            return $this->errorResponse([['msg' => trans('api_errors.system_error')]]);
        } catch (Throwable $e) {
            logger()->error($e);

            if ($e instanceof BusinessException) {
                return $this->errorResponse([['msg' => $e->getUserMessage()]]);
            }

            return $this->errorResponse([['msg' => $e->getMessage()]]);
        }
    }

    /**
     * Метод получения этикетки сборочного задания
     *
     * @param StickersRequest $request
     * @param OrderService $orderService
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function sticker(StickersRequest $request, OrderService $orderService)
    {
        try {
            $this->checkAuthByToken($request);

            $order_id = $request->route('order_id');
            $order    = Order::where('user_id', auth()->id())->where(function ($query) use ($order_id) {
                return $query->where('id', (int)$order_id)->orWhere('order_uid', $order_id);
            })->firstOrFail();

            $format = $request->get('format', 'png');

            $stickers = $orderService->getStickers($order->user_id, [$order->order_uid]);
            $sticker  = current($stickers);

            if ($format === 'svg' && ! empty($sticker[$format]) && filter_var($sticker[$format],
                    FILTER_VALIDATE_URL)) {
                $sticker[$format] = base64_encode(file_get_contents($sticker[$format]));
            }

            $fieldName = $format === 'png' ? 'url' : $format;

            $result = [
                'file'    => $sticker[$fieldName] ?? '',
                'partA'   => $sticker['partA'] ?? '',
                'partB'   => $sticker['partB'] ?? '',
                'barcode' => $sticker['barcode'] ?? '',
            ];

            return $this->successResponse($result);
        } catch (QueryException $e) {
            logger()->critical($e);

            return $this->errorResponse([['msg' => trans('api_errors.system_error')]]);
        } catch (ModelNotFoundException $e) {
            logger()->error($e);

            return $this->errorResponse([['msg' => trans('api_errors.item_not_found')]]);
        } catch (Throwable $e) {
            logger()->error($e);

            if ($e instanceof BusinessException) {
                return $this->errorResponse([['msg' => $e->getUserMessage()]]);
            }

            return $this->errorResponse([['msg' => $e->getMessage()]]);
        }
    }

    /**
     * Список причин отмены заказа
     *
     * @param CancelReasonsRequest $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancel_reasons(CancelReasonsRequest $request)
    {
        try {
            $this->checkAuthByToken($request);

            $marketplace = $request->get('marketplace');

            $reasons = [];

            switch ($marketplace) {
                case 'ozon':
                    foreach (config('marketplace.ozon.order_cancel_reasons') ?? [] as $reason_id) {
                        $reasons[] = [
                            'reason_id' => $reason_id,
                            'title'     => trans('orders.order_cancel_reasons.'.$reason_id),
                        ];
                    }
                    break;
                default:
                    break;
            }

            return $this->successResponse($reasons);
        } catch (Throwable $e) {
            logger()->error($e);

            if ($e instanceof BusinessException) {
                return $this->errorResponse([['msg' => $e->getUserMessage()]]);
            }

            return $this->errorResponse([['msg' => $e->getMessage()]]);
        }
    }

    /**
     * Смена статуса заказа
     *
     * @param ChangeStatusRequest $request
     * @param OrderService $orderService
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function changeStatus(ChangeStatusRequest $request, OrderService $orderService)
    {
        try {
            $this->checkAuthByToken($request);

            $validated = $request->validated();

            $order = Order::where('user_id', auth()->id())->whereOrderUid($validated['order_uid'])
                ->whereMarketplace($validated['marketplace'])->where(function (Builder $query) use ($validated) {
                    /** Если передан номер отправления - ищем заказ с этим номером */
                    if ( ! empty($validated['posting_number'])) {
                        return $query->where('additional_data->posting_number', $validated['posting_number']);
                    }

                    return $query;
                })->firstOrFail();


            /** Для озона требуем posting_number */
            if (empty($validated['posting_number']) && $order->marketplace === 'ozon') {
                throw new BusinessException(trans('api_errors.posting_number_required'));
            }

            $orderService->changeOrderStatus($order, $validated['status']);

            return $this->successResponse(['msg' => trans('panel.operation_success')]);
        } catch (ModelNotFoundException $e) {
            logger()->error($e);

            return $this->errorResponse([['msg' => trans('api_errors.item_not_found')]]);
        } catch (QueryException $e) {
            logger()->critical($e);

            return $this->errorResponse([['msg' => trans('api_errors.system_error')]]);
        } catch (Throwable $e) {
            logger()->error($e);

            if ($e instanceof BusinessException) {
                return $this->errorResponse([['msg' => $e->getUserMessage()]]);
            }

            return $this->errorResponse([['msg' => $e->getMessage()]]);
        }
    }

    /**
     * @param DeliveriesRequest $request
     * @param OrderService $orderService
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function deliveries(DeliveriesRequest $request, OrderService $orderService)
    {
        try {
            $this->checkAuthByToken($request);

            $deliveries = $orderService->deliveryList($request->get('marketplace'), auth()->id());

            return $this->successResponse($deliveries);
        } catch (QueryException $e) {
            logger()->critical($e);

            return $this->errorResponse([['msg' => trans('api_errors.system_error')]]);
        } catch (Throwable $e) {
            logger()->error($e);

            if ($e instanceof BusinessException) {
                return $this->errorResponse([['msg' => $e->getUserMessage()]]);
            }

            return $this->errorResponse([['msg' => $e->getMessage()]]);
        }
    }
}
