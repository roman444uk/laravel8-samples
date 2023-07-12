<?php

namespace App\DataTables\Tickets;

use App\Models\Ticket\TicketCategory;
use App\Services\Ticket\TicketCategoryService;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class TicketCategoriesDataTable extends DataTable
{
    private TicketCategoryService $ticketService;

    public function __construct(TicketCategoryService $ticketService)
    {
        $this->ticketService = $ticketService;
    }
    public function dataTable($query): \Yajra\DataTables\DataTableAbstract
    {
        return datatables()
            ->eloquent($query)
            ->editColumn('id', function (TicketCategory $model) {
                return $model->id;
            })
            ->editColumn('name', function (TicketCategory $model) {
                return $model->name;
            })
            ->editColumn('created_at', function (TicketCategory $model) {
                return $model->created_at->format('d M, Y H:i:s');
            })
            ->editColumn('updated_at', function (TicketCategory $model) {
                return $model->updated_at->format('d M, Y H:i:s');
            })
            ->addColumn('action', function (TicketCategory $model) {
                $roles = $this->ticketService->getRoles($model);
                return view('pages.tickets.categories._action-menu', compact('model', 'roles'));
            });
    }

    public function query(TicketCategory $model): \Illuminate\Database\Eloquent\Builder
    {
        return $model->newQuery();
    }

    public function html(): \Yajra\DataTables\Html\Builder
    {
        return $this->builder()
            ->setTableId('tickets_categories_table')
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

    protected function getColumns(): array
    {
        return [
            Column::make('id')->title(trans('tickets.categories.number')),
            Column::make('name')->title(trans('tickets.categories.name')),
            Column::make('created_at')->title(trans('tickets.categories.date.created')),
            Column::make('updated_at')->title(trans('tickets.categories.date.updated')),
            Column::computed('action')
                ->exportable(false)
                ->printable(false)
                ->searchable(false)
                ->title('')
        ];
    }

    protected function filename(): string
    {
        return 'TicketCategories_'.date('YmdHis');
    }
}
