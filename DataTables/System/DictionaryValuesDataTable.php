<?php

namespace App\DataTables\System;

use App\Models\System\AttributeValue;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class DictionaryValuesDataTable extends DataTable
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
            ->editColumn('ckeckbox', function (AttributeValue $model) {
                return '<div class="d-flex align-items-center">
                    <input type="checkbox" class="form-check-input checkboxItem me-5" name="massDelete[]" value="'.$model->id.'">
                    </div>';
            })
            ->addColumn('action', function (AttributeValue $model) {
                return view('pages.system.dictionaries.dictionary_values._action-menu', compact('model'));
            })->escapeColumns('avatar');
    }

    /**
     * Get query source of dataTable.
     *
     * @param AttributeValue $model
     *
     * @return Builder
     */
    public function query(AttributeValue $model)
    {
        $dictionary_id = request()->dictionary_id;

        return $model->where('attribute_id', $dictionary_id)->newQuery();
    }

    /**
     * Optional method if you want to use html builder.
     *
     * @return \Yajra\DataTables\Html\Builder
     */
    public function html()
    {
        return $this->builder()
            ->setTableId('dictionary_values-table')
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
            Column::make('title')->title(trans('imports.title')),
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
        return 'DictionaryValues_'.date('YmdHis');
    }
}
