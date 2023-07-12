<?php

namespace App\DataTables\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket\Ticket;
use App\Services\Ticket\TicketCategoryService;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class TicketArchiveDataTable extends DataTable
{
    private TicketCategoryService $categoryService;

    public function __construct(TicketCategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    public function dataTable($query): \Yajra\DataTables\DataTableAbstract
    {
        return datatables()
            ->eloquent($query)
            ->editColumn('id', function (Ticket $model) {
                return $model->id;
            })
            ->editColumn('topic', function (Ticket $model) {
                return '<a href="'.route('tickets.show', $model->id).'">'.$model->topic.'</a>';
            })
            ->editColumn('status', function (Ticket $model) {
                return trans('tickets.statuses.'.$model->status);
            })->orderColumn('status', false)
            ->editColumn('created_at', function (Ticket $model) {
                return $model->created_at->format('d M, Y H:i:s');
            })
            ->editColumn('category', function (Ticket $model) {
                return $model->category->name;
            })->orderColumn('category', false)
            ->rawColumns(['topic'])
            ->order(function ($query) {
                $query->orderBy('updated_at', 'desc');
            })
            ->editColumn('manager', function (Ticket $model) {
                $manager = $model->manager;

                return $manager ? $manager->first_name.' '.$manager->last_name : '-';
            })
            ->addColumn('action', function (Ticket $model) {
                $user = auth()->user();

                return $user->hasPermissionTo('tickets.tickets-delete') ? view('pages.tickets._action-menu',
                    compact('model')) : '';
            });
    }

    public function query(Ticket $model): \Illuminate\Database\Eloquent\Builder
    {
        $close       = TicketStatus::SUCCESS_CLOSED->value;
        $closeClient = TicketStatus::CLOSED_CLIENT->value;
        $user        = auth()->user();
        $categories  = $this->categoryService->userCategories($user);

        $query = $model->newQuery()
            ->where(function ($query) use ($close, $closeClient) {
                $query->where('status', '=', $close)->orWhere('status', '=', $closeClient);
            });

        if ( ! $user->hasPermissionTo('tickets.tickets-update')) {
            $query->where(function ($q) use ($categories) {
                foreach ($categories as $category) {
                    $q->orWhere('category_id', '=', $category->id);
                }
            });
        }

        return $query;
    }

    public function html(): \Yajra\DataTables\Html\Builder
    {
        return $this->builder()
            ->setTableId('tickets_table_archive')
            ->columns($this->getColumns())
            ->minifiedAjax(route('tickets.archive'))
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
            Column::make('id')->title(trans('tickets.number')),
            Column::make('topic')->title(trans('tickets.topic')),
            Column::make('status')->title(trans('tickets.status'))->searchable(false),
            Column::make('created_at')->title(trans('tickets.created_data'))->searchable(false),
            Column::make('category')->title(trans('tickets.category'))->searchable(false),
            Column::make('manager')->title(__('tickets.manager'))->searchable(false),
            Column::computed('action')
                ->exportable(false)
                ->printable(false)
                ->searchable(false)
                ->title('')
        ];
    }

    protected function filename(): string
    {
        return 'ticket_archive_'.date('YmdHis');
    }
}
