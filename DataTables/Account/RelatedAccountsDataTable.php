<?php

namespace App\DataTables\Account;

use App\Models\RelatedAccount;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class RelatedAccountsDataTable extends DataTable
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
            ->editColumn('email', function (RelatedAccount $model) {
                return $model->related_user->email;
            })
            ->editColumn('created_at', function (RelatedAccount $model) {
                return $model->created_at->format('d M, Y H:i:s');
            })
            ->addColumn('action', function (RelatedAccount $model) {
                return view('pages.account.related-accounts._action-menu', compact('model'));
            });
    }

    /**
     * Get query source of dataTable.
     *
     * @param \App\Models\RelatedAccount $model
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function query(RelatedAccount $model)
    {
        return $model->newQuery()->whereUserId(auth()->id());
    }

    /**
     * Optional method if you want to use html builder.
     *
     * @return \Yajra\DataTables\Html\Builder
     */
    public function html()
    {
        return $this->builder()
            ->setTableId('users-table')
            ->columns($this->getColumns())
            ->minifiedAjax()
            ->stateSave(true)
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
            ->searching(false)
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
            Column::make('email')->title(trans('registration.email'))->searchable(false)->orderable(false),
            Column::make('created_at')->title(trans('panel.registration_date')),
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
        return 'RelatedAccounts_'.date('YmdHis');
    }
}
