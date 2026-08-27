<?php

namespace App\DataTables;

use App\Models\SupportTicket;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class SupportTicketDataTable extends DataTable
{
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
        ->addIndexColumn()
        ->editColumn('ticket_id', function (SupportTicket $ticket) {
            if (auth()->user() && auth()->user()->isAbleTo('Replay Support Ticket')) {
                $url = route('support_ticket.edit', $ticket->id);
                $ticket_number = '<a class="btn btn-outline-primary" href="'.$url.'">#'.$ticket->ticket_id.'</a>';
            } else {
                $ticket_number = '-';
            }

            return $ticket_number;
        })
         ->editColumn('order_id', function (SupportTicket $ticket) {
                $url = route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($ticket->id));
                $order_number = '<a class="btn btn-outline-primary" href="'.$url.'">#'.($ticket->orderData->product_order_id ?? '-').'</a>';
            
            return $order_number;
        })
        ->editColumn('created_at', function (SupportTicket $ticket) {
            return $ticket->created_at ? $ticket->created_at->format('Y-m-d H:i:s') : '-';
        })
        ->editColumn('customer_id', function (SupportTicket $ticket) {
            return $ticket->UserData ? $ticket->UserData->name : '';
        })
        ->editColumn('status', function (SupportTicket $ticket) {
            return '<span class="badge fix_badges bg-primary p-2 px-3 rounded-1">'.$ticket->status.'</span>';
        })
        ->addColumn('action', function (SupportTicket $ticket) {
            return view('support.action', compact('ticket'));
        })
        ->filterColumn('customer_id', function ($query, $keyword) {
            $query->whereRaw("CONCAT(customers.first_name, ' ', customers.last_name) LIKE ?", ["%{$keyword}%"]);
        })
        ->filterColumn('order_id', function ($query, $keyword) {
            $query->whereHas("orderData", function ($q) use ($keyword) {
                $q->where("product_order_id", "like", "%{$keyword}%");
            });
        })
        ->rawColumns(['ticket_id', 'order_id', 'created_at', 'customer_id', 'status', 'action']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(SupportTicket $model): QueryBuilder
    {
        return $model->newQuery()->leftjoin('customers', 'customers.id', '=', 'support_tickets.customer_id')->where('support_tickets.store_id', getCurrentStore())->select('support_tickets.*', 'customers.first_name', 'customers.last_name'); ;
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('supportticket-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'ticket_id' => ['title' => __('Ticket ID')],
            'order_id' => ['title' => __('Order ID')],
            'title' => ['title' => __('Title')],
            'created_at' => ['title' => __('Date')],
            'customer_id' => ['title' => __('User')],
            'status' => ['title' => __('Status'), 'addClass' => 'text-capitalize']
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'SupportTicket_' . date('YmdHis');
    }
}
