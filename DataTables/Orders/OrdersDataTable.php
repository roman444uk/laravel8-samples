<?php

namespace App\DataTables\Orders;

use App\Models\Orders\Order;
use App\Services\Shop\OrderService;
use App\Services\Shop\SupplyService;
use App\Traits\MarketplaceProductHelper;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class OrdersDataTable extends DataTable
{
    use MarketplaceProductHelper;

    /**
     * Build DataTable class.
     *
     * @param mixed $query Results from query() method.
     *
     * @return \Yajra\DataTables\DataTableAbstract
     */
    public function dataTable($query)
    {
        $orderService = new OrderService();

        return datatables()
            ->eloquent($query)
            ->editColumn('ckeckbox', function (Order $model) {
                return '<div class="d-flex align-items-center">
                    <input type="checkbox" class="form-check-input checkboxItem me-5" name="products[]" value="'.$model->id.'">
                    </div>';
            })
            ->editColumn('order_uid', function (Order $model) {
                switch ($model->marketplace) {
                    case 'ozon':
                        $html = $model->order_uid;
                        $html .= sprintf('<div class="text-gray-500 fs-8">(%s)</div>', $model->posting_number);
                        break;
                    default:
                        $html = $model->order_uid;
                        break;
                }

                return $html;
            })
            ->editColumn('warehouse', function (Order $model) use ($orderService) {
                return $orderService->getWarehouseNameByOrder($model);
            })
            ->editColumn('status', function (Order $model) use ($orderService) {
                return '<span class="badge badge-light-dark">
                    '.$orderService->getOrderStatusName($model).'
                </span>';
            })
            ->editColumn('total', function (Order $model) use ($orderService) {
                return $model->total.' '.$model->currency;
            })
            ->editColumn('marketplace', function (Order $model) {
                return $model->marketplace === 'ozon' ? $model->marketplace.' '.$model->additional_data['order_type'] : $model->marketplace;
            })
            ->editColumn('order_created', function (Order $model) {
                return $model->order_created?->timezone('Europe/Moscow')->toDateTimeString();
            })
            ->addColumn('action', function (Order $model) {
                return view('pages.orders._action-menu', compact('model'));
            })
            ->escapeColumns('status')
            ->filter(fn($query) => $this->filter($query), true);
    }

    /**
     * Get query source of dataTable.
     *
     * @param Order $model
     *
     * @return Builder
     */
    public function query(Order $model)
    {
        return $model->newQuery()
            ->leftJoin('currencies', 'currencies.id', '=', 'orders.currency_id')
            ->where('orders.user_id', auth()->id())
            ->select(['orders.*', 'currencies.designation as currency'])->groupBy(['orders.id', 'currency']);
    }

    /**
     * Optional method if you want to use html builder.
     *
     * @return \Yajra\DataTables\Html\Builder
     */
    public function html()
    {
        return $this->builder()
            ->setTableId('orders_table')
            ->columns($this->getColumns())
            ->minifiedAjax()
            ->ajax([
                'data' => "
                    function(data){
                      data.orderType = $('#orderTypeSelect').val();
                      data.orderStatus = data.orderType.indexOf('OZON') > -1
                        ? $('#orderStatusSelectOzon').val() : $('#orderStatusSelectWb').val();
                      data.orderDate = $('#orderDateInput').val()
                   }
               "
            ])
            ->language([
                "info"           => trans('data_tables.showing')." _START_ ".trans('data_tables.to')." _END_ "
                    .trans('data_tables.from')." _TOTAL_ ".trans('data_tables.records'),
                "infoEmpty"      => '',
                "lengthMenu"     => "_MENU_",
                "processing"     => trans('data_tables.processing'),
                "search"         => trans('data_tables.search'),
                "loadingRecords" => trans('data_tables.loading'),
                "emptyTable"     => trans('data_tables.empty'),
                "zeroRecords"    => trans('data_tables.empty'),
            ])
            ->stateSave()
            ->responsive(false)
            ->autoWidth(false)
            ->parameters([
                'scrollX' => true,
                'order'   => [
                    5, 'desc'
                ]
            ])
            ->addTableClass('align-middle table-row-dashed fs-6 gy-5');
    }

    /**
     * Get columns.
     *
     * @return array
     */
    protected function getColumns()
    {
        return [
            Column::make('ckeckbox')
                ->title('<input type="checkbox" class="form-check-input" id="checkAll">')
                ->orderable(false)->searchable(false),
            Column::make('order_uid')->title(trans('orders.order_uid')),
            Column::make('marketplace')->title(trans('products.marketplace')),
            Column::make('warehouse')->title(trans('products.warehouse'))->orderable(false)->searchable(false),
            Column::make('status')->title(trans('products.status'))->orderable(false)->searchable(false),
            Column::make('total')->title(trans('orders.total_title'))->orderable(false)->searchable(false),
            Column::make('order_created')->title(trans('orders.date_order')),
            Column::computed('action')
                ->exportable(false)
                ->printable(false)
                ->searchable(false)
                ->title(''),
        ];
    }

    /**
     * Get filename for export.
     *
     * @return string
     */
    protected function filename()
    {
        return 'orders-'.date('YmdHis');
    }

    private function filter(Builder $query)
    {
        $searchArr   = request()->get('search');
        $searchValue = $searchArr['value'] ?? null;

        if ( ! empty($searchValue)) {
            $query->orWhere('additional_data->posting_number', $searchValue);
        }

        /** Фильтруем по типу заказа и маркетплейсу */
        if ( ! empty(request()->get('orderType'))) {
            $supplyService = new SupplyService();
            $marketPlace   = $supplyService->getMarketplaceByOrderTypeName(request()->get('orderType'));
            $orderType     = $supplyService->getOrderTypeByOrderTypeName(request()->get('orderType'));
            $orderStatus   = request()->get('orderStatus');

            switch ($marketPlace) {
                case 'ozon':
                    $query->where([
                        'orders.marketplace'                 => $marketPlace,
                        'orders.additional_data->order_type' => $orderType,
                    ]);

                    /** Если передан статус */
                    if ($orderStatus) {
                        $query->where(['orders.status' => $orderStatus]);
                    }

                    /** Если передана Дата отгрузки - фильтруем по ней */
                    if ( ! empty(request()->get('ozonShipmentDate'))) {
                        $shipmentDate = Carbon::createFromDate(request()->get('ozonShipmentDate'));
                        $query->whereRaw('delivery->>? != ? and DATE(delivery->>?) = DATE(?)',
                            ['shipment_date', '', 'shipment_date', $shipmentDate]);
                    }
                    break;
                default:
                    $query->where([
                        'orders.marketplace' => $marketPlace,
                    ]);

                    /** Если передан статус */
                    if ($orderStatus) {
                        if (in_array($orderStatus, ['sold', 'canceled_by_client'])) {
                            $query->whereRaw('additional_data->>? = ?', [
                                'wbStatus', $orderStatus
                            ]);
                        } else {
                            $query->where(['orders.status' => $orderStatus]);
                            $query->whereNotIn('additional_data->wbStatus', ['sold', 'canceled_by_client']);
                        }
                    }

                    break;
            }
        }

        /** Фильтруем по дате создания */
        if ( ! empty(request()->get('orderDate'))) {
            $dateParts = explode('-', request()->get('orderDate'));

            $dateStart = \DateTime::createFromFormat('d.m.Y', trim($dateParts[0]));
            $dateTo    = \DateTime::createFromFormat('d.m.Y', trim($dateParts[1]));

            $query->whereRaw('DATE(orders.created_at) >= ? AND DATE(orders.created_at) <= ?', [
                $dateStart->format('Y-m-d'), $dateTo->format('Y-m-d')
            ]);
        }
    }
}
