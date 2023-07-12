<?php

namespace App\DataTables\System;

use App\Models\System\Attribute;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class DictionaryDataTable extends DataTable
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
            ->editColumn('type', function (Attribute $dictionary) {
                return trans('system.dictionaries.dictionary.types.'.$dictionary->type);
            })
            ->editColumn('status', function (Attribute $model) {
                $class  = $model->status === 'published' ? 'badge badge-light-success' : 'badge badge-light-dark';
                $status = $model->status === 'published' ? trans('panel.published') : trans('panel.unpublished');

                return '<span class="'.$class.'">'.$status.'</span>';
            })
            ->addColumn('action', function (Attribute $model) {
                return view('pages.system.dictionaries.dictionary._action-menu', compact('model'));
            })->escapeColumns('avatar');
    }

    /**
     * Get query source of dataTable.
     *
     * @param Attribute $model
     *
     * @return Builder
     */
    public function query(Attribute $model)
    {
        return $model->newQuery();
    }

    /**
     * Optional method if you want to use html builder.
     *
     * @return \Yajra\DataTables\Html\Builder
     */
    public function html()
    {
        return $this->builder()
            ->setTableId('dictionaries-table')
            ->columns($this->getColumns())
            ->minifiedAjax()
            ->stateSave()
            ->language([
                "info"           => trans('data_tables.showing')." _START_ ".trans('data_tables.to')." _END_ "
                    .trans('data_tables.from')." _TOTAL_ ".trans('data_tables.records'),
                "infoEmpty"      => '',
                "lengthMenu"     => "_MENU_",
                "processing"     => trans('data_tables.processing'),
                "search"         => trans('data_tables.search'),
                "loadingRecords" => trans('data_tables.loading'),
                "emptyTable"     => trans('data_tables.empty'),
            ])
            ->responsive()
            ->autoWidth(false)
            ->parameters([
                'scrollX' => true,
                'order'   => [
                    [3, 'asc']
                ],
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
            Column::make('title')->title(trans('system.dictionaries.dictionary.title')),
            Column::make('type')->title(trans('system.dictionaries.dictionary.type')),
            Column::make('status')->title(trans('categories.status')),
            Column::make('sort')->title(trans('system.dictionaries.dictionary.sort')),
            Column::computed('action')
                ->exportable(false)
                ->printable(false)
                ->searchable(false)
                ->title('')
                ->addClass('text-center'),
        ];
    }

    /**
     * Get filename for export.
     *
     * @return string
     */
    protected function filename()
    {
        return 'Dictionaries_'.date('YmdHis');
    }
}
