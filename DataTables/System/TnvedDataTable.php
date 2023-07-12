<?php

namespace App\DataTables\System;

use App\Facades\SyncHelper;
use App\Models\Tnved\TnvedGroup;
use App\Models\Tnved\TnvedItem;
use App\Models\Tnved\TnvedSection;
use Illuminate\Database\Eloquent\Builder;
use Yajra\DataTables\DataTableAbstract;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class TnvedDataTable extends DataTable
{
    /**
     * Build DataTable class.
     *
     * @param mixed $query Results from query() method.
     *
     * @return DataTableAbstract
     */
    public function dataTable(mixed $query): DataTableAbstract
    {
        return datatables()
            ->eloquent($query)
            ->editColumn('title', function (TnvedItem $model) {
                return '
                <div class="d-flex align-items-center">
                    <div class="ms-5">
                        <a href="'.route('system.tnved.edit',
                        $model->id).'" class="text-gray-800 text-hover-primary fs-5 mb-1">'.$model->getParentNamesLight().'</a>
                    </div>
                </div>';
            })
            ->editColumn('group', function (TnvedItem $model) {
                if(!empty($model->parent_id)){
                    return '';
                }
                
                $group = TnvedGroup::whereGroupId($model->group_id)->first();
                
                return $group->title ?? '';
            })
            ->editColumn('section', function (TnvedItem $model) {
                if(!empty($model->parent_id)){
                    return '';
                }
                
                $section = TnvedSection::whereRaw('section_id IN (SELECT DISTINCT section_id FROM tnved_groups WHERE group_id = ?)', [$model->group_id])->first();
                
                return $section->title ?? '';
            })
            ->editColumn('title', function (TnvedItem $model) {
                return '
                <div class="d-flex align-items-center">
                    <div class="ms-5">
                        <a href="'.route('system.tnved.edit',
                        $model->id).'" class="text-gray-800 text-hover-primary fs-5 mb-1">'.$model->getParentNamesLight().'</a>
                    </div>
                </div>';
            })
            ->editColumn('status', function (TnvedItem $model) {
                $class  = $model->status === 1 ? 'badge badge-light-success' : 'badge badge-light-dark';
                $status = $model->status === 1 ? trans('panel.published') : trans('panel.unpublished');

                return '<span class="'.$class.'">'.$status.'</span>';
            })
            ->addColumn('action', function (TnvedItem $model) {
                return view('pages.system.tnved._action-menu', compact('model'));
            })
            ->escapeColumns('status');
    }

    /**
     * Get query source of dataTable.
     *
     * @param TnvedItem $model
     *
     * @return Builder
     */
    public function query(TnvedItem $model): Builder
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
            ->setTableId('tnved_table')
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
            ->stateSave()
            ->responsive()
            ->autoWidth(false)
            ->parameters(['scrollX' => true])
            ->pageLength(50)
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
            Column::make('title')->title(trans('tnved.title')),
            Column::make('full_code')->title(trans('tnved.full_code')),
            Column::make('section')->title(trans('tnved.section'))->searchable(false)->orderable(false),
            Column::make('group')->title(trans('tnved.group'))->searchable(false)->orderable(false),
            Column::make('status')->title(trans('categories.status'))->searchable(false),
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
        return 'tnved_'.date('YmdHis');
    }
}
