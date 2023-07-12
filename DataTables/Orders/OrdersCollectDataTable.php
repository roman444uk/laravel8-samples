<?php

namespace App\DataTables\Orders;

use App\Models\Orders\Order;
use App\Models\Supplies\SupplyProduct;
use App\Services\Shop\OrderService;
use App\Services\Shop\SupplyService;
use App\Traits\MarketplaceProductHelper;
use Carbon\Carbon;
use DateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class OrdersCollectDataTable extends DataTable
{
    use MarketplaceProductHelper;

    private string $tableId = 'orders_collect';

    /**
     * Build DataTable class.
     *
     * @param mixed $query Results from query() method.
     *
     * @return \Yajra\DataTables\DataTableAbstract
     */
    public function dataTable($query)
    {
        $orderService  = new OrderService();
        $supplyService = new SupplyService();

        $openedSupply = null;
        if ( ! empty(request()->get('orderType'))) {
            $marketPlace = $supplyService->getMarketplaceByOrderTypeName(request()->get('orderType'));
            $orderType   = $supplyService->getOrderTypeByOrderTypeName(request()->get('orderType'));

            $openedSupply = $supplyService->getOpenedSupply(auth()->id(), $marketPlace, $orderType);
        }

        return datatables()
            ->eloquent($query)
            ->editColumn('ckeckbox', function (Order $model) {
                return '<div class="d-flex align-items-center">
                    <input type="checkbox" class="form-check-input checkboxItem me-5" name="orders[]" value="'.$model->order_uid.'">
                    </div>';
            })
            ->editColumn('order_uid', function (Order $model) {
                return $model->marketplace === 'ozon' ? $model->posting_number ?? $model->order_uid : $model->order_uid;
            })
            ->editColumn('marketplace', function (Order $model) {
                return $model->marketplace === 'ozon' ? $model->marketplace.' '.$model->additional_data['order_type'] : $model->marketplace;
            })
            ->editColumn('products', function (Order $model) use ($orderService, $openedSupply) {
                $html = '';
                foreach ($model->products as $product) {
                    $productInfo     = $orderService->getOrderProductInfo($product, $model->marketplace);
                    $inCurrentSupply = $openedSupply ?
                        SupplyProduct::where([
                            'supply_id'        => $openedSupply->id,
                            'order_product_id' => $product->id
                        ])->first() : null;
                    $inOtherSupplies = $openedSupply ?
                        SupplyProduct::whereNotIn('supply_id', [$openedSupply->id])->where('order_product_id',
                            $product->id)->first()
                        : SupplyProduct::where('order_product_id', $product->id)->first();

                    $html .= view('pages.orders.collect.product',
                        compact('productInfo', 'product', 'openedSupply', 'inCurrentSupply', 'inOtherSupplies'));
                }

                return $html;
            })
            ->editColumn('order_created', function (Order $model) {
                return $model->order_created?->timezone('Europe/Moscow')->toDateTimeString();
            })
            ->editColumn('date_shipping', function (Order $model) {
                return ! empty($model->delivery['shipment_date']) ? Carbon::createFromTimeString($model->delivery['shipment_date']) : '';
            })
            ->editColumn('status', function (Order $model) use ($orderService) {
                return '<span class="badge badge-light-dark">
                    '.$orderService->getOrderStatusName($model).'
                </span>';
            })
            ->editColumn('total', function (Order $model) use ($orderService) {
                return $model->total.' '.$model->currency;
            })
            ->addColumn('action', function (Order $order) {
                return view()->first(
                    [
                        'pages.orders.collect.action-menu.'.$order->marketplace,
                        'pages.orders.collect.action-menu.default'
                    ],
                    compact('order')
                );
            })
            ->escapeColumns('status')
            ->filterColumn('products', function ($query, $keyword) {
                $query->whereRaw('orders.id IN (SELECT order_id FROM order_products WHERE title ilike ?)',
                    ["%{$keyword}%"]);
            })
            ->orderColumn('date_shipping', function ($query, $order) {
                $query->orderBy('delivery->shipment_date', $order);
            })
            ->filterColumn('date_shipping', function ($query, $keyword) {
                $date = DateTime::createFromFormat('Y-m-d', $keyword);
                /** Только если в фильтр вбили дату */
                if ( ! empty($date)) {
                    $query->whereRaw('delivery->>? != ? and DATE(delivery->>?) = DATE(?)',
                        ['shipment_date', '', 'shipment_date', $date->format('Y-m-d')]);
                }
            })
            ->filterColumn('order_uid', function ($query, $keyword) {
                $query->where('order_uid', $keyword)
                    ->orWhere('additional_data->posting_number', $keyword);
            })
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
            ->whereIn('orders.status', ['new', 'confirm', 'awaiting_packaging', 'awaiting_deliver'])
            ->with('products')
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
            ->setTableId($this->tableId)
            ->columns($this->getColumns())
            ->minifiedAjax()
            ->ajax([
                'data' => "
                    function(data){
                      data.orderType = $('#orderTypeSelect').val();
                      data.ozonShipmentDate = $('#ozonShipmentDate').val();
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
                    1, 'asc'
                ]
            ])
            ->drawCallback('function(){KTMenu.createInstances();}')
            ->addTableClass('align-middle table-row-dashed fs-6 gy-5');
    }

    /**
     * Get columns.
     *
     * @return array
     */
    protected function getColumns()
    {
        $disableableColumns = $this->disableableColumns();
        $enabledColumns     = $this->getEnabledColumns();

        $allColumns = [
            'ckeckbox'      => Column::make('ckeckbox')
                ->title('<input type="checkbox" class="form-check-input" id="checkAll">')
                ->orderable(false)
                ->searchable(false),
            'order_uid'     => Column::make('order_uid')
                ->title(trans('orders.order_id')),
            'marketplace'   => Column::make('marketplace')
                ->title(trans('products.marketplace')),
            'products'      => Column::make('products')
                ->title(trans('orders.product')),
            'order_created' => Column::make('order_created')
                ->title(trans('orders.date_order')),
            'date_shipping' => Column::make('date_shipping')
                ->title(trans('orders.date_shipping')),
            'status'        => Column::make('status')
                ->title(trans('products.status'))
                ->orderable(false)
                ->searchable(false),
            'total'         => Column::make('total')
                ->title(trans('orders.total_title'))
                ->orderable(false)
                ->searchable(false),
            'action'        => Column::computed('action')
                ->titleAttr(trans('data_tables.columns_display_setup'))
                ->title((string)view('pages.orders.collect._columns-setup',
                    compact('disableableColumns', 'enabledColumns')))
                ->exportable(false)
                ->printable(false)
                ->searchable(false),
        ];

        $columns = [];
        foreach ($allColumns as $name => $column) {
            if (array_key_exists($name, $this->disableableColumns()) && ! in_array($name, $enabledColumns)) {
                continue;
            }

            $columns[] = $column;
        }

        return $columns;
    }

    public function disableableColumns()
    {
        return [
            'order_uid'   => trans('orders.order_uid'),
            'marketplace' => trans('products.marketplace'),
            'products'    => trans('orders.product'),
            'status'      => trans('products.status'),
            'total'       => trans('orders.total_title'),
        ];
    }

    /**
     * @return array
     */
    public function getEnabledColumns()
    {
        return auth()->user()->info->settings['dataTableColumns'][$this->tableId] ?? array_keys($this->disableableColumns());
    }

    /**
     * Get filename for export.
     *
     * @return string
     */
    protected function filename()
    {
        return 'orders-collect-'.date('YmdHis');
    }

    private function filter($query)
    {
        /** Фильтруем по типу заказа и маркетплейсу */
        if ( ! empty(request()->get('orderType'))) {
            $supplyService = new SupplyService();
            $marketPlace   = $supplyService->getMarketplaceByOrderTypeName(request()->get('orderType'));
            $orderType     = $supplyService->getOrderTypeByOrderTypeName(request()->get('orderType'));

            switch ($marketPlace) {
                case 'ozon':
                    $query->where([
                        'orders.marketplace'                 => $marketPlace,
                        'orders.additional_data->order_type' => $orderType,
                    ]);

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
                    ])->whereIn('additional_data->wbStatus', ['waiting']);
                    break;
            }
        }
    }

    public function ajax()
    {
        $query = null;
        if (method_exists($this, 'query')) {
            $query = app()->call([$this, 'query']);
            $query = $this->applyScopes($query);
        }

        /** @var \Yajra\DataTables\DataTableAbstract $dataTable */
        $dataTable = app()->call([$this, 'dataTable'], compact('query'));

        if ($callback = $this->beforeCallback) {
            $callback($dataTable);
        }

        if ($callback = $this->responseCallback) {
            $data = new Collection($dataTable->toArray());

            return new JsonResponse($callback($data));
        }

        return $dataTable->toJson();
    }
}
