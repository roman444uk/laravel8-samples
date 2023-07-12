<?php

namespace App\DataTables\Orders;

use App\Models\Supplies\Supply;
use App\Services\Shop\SupplyService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class SuppliesDataTable extends DataTable
{

    /**
     * Build DataTable class.
     *
     * @param mixed $query Results from query() method.
     *
     * @return \Yajra\DataTables\DataTableAbstract
     */
    public function dataTable($query)
    {
        $supplyService = new SupplyService();

        return datatables()
            ->eloquent($query)
            ->editColumn('ckeckbox', function (Supply $model) {
                return '<div class="d-flex align-items-center">
                    <input type="checkbox" class="form-check-input checkboxItem me-5" name="supplies[]" value="'.$model->id.'">
                    </div>';
            })
            ->editColumn('barcode', function (Supply $model) {
                $html = '';
                if ( ! empty($model->data['barcode']['url']) && $model->marketplace === 'wildberries') {
                    $html = sprintf('<a href="%s" target="_blank">%s</a>', $model->data['barcode']['url'],
                        trans('supply.barcode'));
                }

                if ( ! empty($model->data['documents']) && $model->marketplace === 'ozon') {
                    foreach ($model->data['documents'] as $document) {
                        switch ($document['document_type']) {
                            default:
                            case 'act_of_acceptance':
                                $title = trans('orders.act_types.act_of_acceptance');
                                break;
                            case 'act_of_mismatch':
                                $title = trans('orders.act_types.act_of_mismatch');
                                break;
                            case 'act_od_excess':
                                $title = trans('orders.act_types.act_od_excess');
                                break;
                        }

                        $html .= sprintf('<a href="%s" target="_blank">%s</a><br>', $document['url'], $title);
                    }
                }

                return $html;
            })
            ->editColumn('products', function (Supply $model) {
                return $model->products->count();
            })
            ->editColumn('status', function (Supply $model) use ($supplyService) {
                return '<span class="badge badge-light-dark">
                    '.$supplyService->getStatusName($model->status).'
                </span>';
            })
            ->editColumn('created_at', function (Supply $model) {
                return $model->created_at->timezone('Europe/Moscow')->toDateTimeString();
            })
            ->editColumn('closed_at', function (Supply $model) {
                return ! empty($model->data['closed_at']) ?
                    Carbon::createFromTimeString($model->data['closed_at'])->toDateTimeString() : '';
            })
            ->addColumn('action', function (Supply $model) {
                return view('pages.orders.supplies._action-menu', compact('model'));
            })
            ->escapeColumns('status')
            ->filterColumn('closed_at', function ($query, $keyword) {
                $sql = "data->>'closed_at'  ilike ?";
                $query->whereRaw($sql, ["%{$keyword}%"]);
            })
            ->filterColumn('marketplace_uid', function ($query, $keyword) {
                $sql = "marketplace_uid  ilike ?";
                $query->whereRaw($sql, ["%{$keyword}%"]);
            })
            ->orderColumn('closed_at', function ($query, $order) {
                $query->orderBy('data->closed_at', $order);
            })
            ->filter(fn($query) => $this->filter($query), true);
    }

    /**
     * Get query source of dataTable.
     *
     * @param Supply $model
     *
     * @return Builder
     */
    public function query(Supply $model)
    {
        return $model->newQuery()
            ->where('user_id', auth()->id());
    }

    /**
     * Optional method if you want to use html builder.
     *
     * @return \Yajra\DataTables\Html\Builder
     */
    public function html()
    {
        return $this->builder()
            ->setTableId('supplies_table')
            ->columns($this->getColumns())
            ->minifiedAjax()
            ->ajax([
                'data' => "
                    function(data){
                      data.marketplace = $('#marketPlaceSelect').val();
                      data.status = $('#supplyStatusSelect').val();
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
            Column::make('marketplace_uid')->title(trans('supply.marketplace_id')),
            Column::make('marketplace')->title(trans('products.marketplace')),
            Column::make('barcode')->title(trans('supply.barcode'))->orderable(false)->searchable(false),
            Column::make('products')->title(trans('supply.products_count'))->orderable(false)->searchable(false),
            Column::make('status')->title(trans('products.status'))->orderable(false)->searchable(false),
            Column::make('created_at')->title(trans('supply.created_at')),
            Column::make('closed_at')->title(trans('supply.closed_at')),
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
        return 'supplies-'.date('YmdHis');
    }

    private function filter($query)
    {
        if ( ! empty(request()->get('marketplace'))) {
            $query->whereRaw('marketplace = ?', [request()->get('marketplace')]);
        }

        if ( ! empty(request()->get('status'))) {
            $query->whereRaw('status = ?', [request()->get('status')]);
        }
    }
}
