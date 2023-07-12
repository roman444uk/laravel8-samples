<?php

namespace App\DataTables\System;

use App\Facades\SyncHelper;
use App\Models\System\Category;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class CategoriesDataTable extends DataTable
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
        return datatables()
            ->eloquent($query)
            ->editColumn('title', function (Category $model) {
                return '
                <div class="d-flex align-items-center">
                    <div class="ms-5">
                        <a href="'.route('system.categories.edit',
                        $model->id).'" class="text-gray-800 text-hover-primary fs-5 mb-1">'.$model->getParentNames(true).'</a>
                    </div>
                </div>';
            })
            ->editColumn('status', function (Category $model) {
                $class  = $model->status === 'published' ? 'badge badge-light-success' : 'badge badge-light-dark';
                $status = $model->status === 'published' ? trans('panel.published') : trans('panel.unpublished');

                return '<span class="'.$class.'">'.$status.'</span>';
            })
            ->editColumn('status_sync', function (Category $model) {
                return SyncHelper::getCategorySyncStatus($model);
            })
            ->editColumn('status_sync_attributes', function (Category $model) {
                return SyncHelper::getCategoryAttributesSyncStatus($model);
            })
            ->addColumn('action', function (Category $model) {
                return view('pages.system.categories._action-menu', compact('model'));
            })
            ->escapeColumns('status');
    }

    /**
     * Get query source of dataTable.
     *
     * @param \App\Models\Category $model
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function query(Category $model)
    {
        return $model->newQuery()->tree()->depthFirst();
    }

    /**
     * Optional method if you want to use html builder.
     *
     * @return \Yajra\DataTables\Html\Builder
     */
    public function html()
    {
        return $this->builder()
            ->setTableId('categories_table')
            ->columns($this->getColumns())
            ->minifiedAjax()
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
            ->stateSave(true)
            ->responsive()
            ->autoWidth(false)
            ->parameters(['scrollX' => true])
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
            Column::make('title')->title(trans('categories.title')),
            Column::make('status')->title(trans('categories.status')),
            Column::make('status_sync')->searchable(false)->orderable(false)->title(trans('syncs.columns.categories')),
            Column::make('status_sync_attributes')->searchable(false)->orderable(false)->title(trans('syncs.columns.attributes')),
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
        return 'categories_'.date('YmdHis');
    }
}
