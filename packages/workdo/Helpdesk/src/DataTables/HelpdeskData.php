<?php

namespace Workdo\Helpdesk\DataTables;

use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;
use Workdo\Helpdesk\app\Models\HelpdeskTicket;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;

use Yajra\DataTables\Html\Column;
use Illuminate\Http\Request;
use Yajra\DataTables\Services\DataTable;

class HelpdeskData extends DataTable
{
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        $dataTable = (new EloquentDataTable($query))
            ->addIndexColumn()
            ->addColumn('action', function (HelpdeskTicket $ticket) {
                return view('helpdesk::helpdesk_ticket.action', compact('ticket'));
            })
            ->editColumn('ticket_id', function ($ticket) {
                $ticketIdLink = '<a class="btn btn-badge btn-outline-primary"';
                if (auth()->user()->isAbleTo('Reply Helpdesk Ticket')) {
                    $ticketIdLink .= ' href="' . route('helpdesk.ticket.reply', ['ticketId' => encrypt($ticket->ticket_id)]) . '"';
                } else {
                    $ticketIdLink .= ' href="#"';
                }
                $ticketIdLink .= '>' . '#' . $ticket->ticket_id . '</a>';

                return $ticketIdLink;
            })
            ->editColumn('name', function ($ticket) {
                return '<span class="white-space">' . $ticket->name . '</span>';
            })
            ->editColumn('created_by', function ($ticket) {
                return '<span class="text-primary">' . $ticket->createdBy->name . '</span>';
            })

            ->editColumn('subject', function ($ticket) {
                return '<span class="white-space">' . $ticket->subject . '</span>';
            })

            ->editColumn('category_id', function ($ticket) {
                $category = isset($ticket->getTicketCategory->name) ? $ticket->getTicketCategory->name : '';
                $color = isset($ticket->getTicketCategory->color) ? $ticket->getTicketCategory->color : '';
                return '<span class="badge badge-white p-2 px-3 fix_badge category_badge" style="background: ' . $color . ';">' . $category . '</span>';
            })

            ->editColumn('status_id', function ($ticket) {
                $status = isset($ticket->getTicketStatus->status_name) ? $ticket->getTicketStatus->status_name : '';
                $statusColor = isset($ticket->getTicketStatus->status_color) ? $ticket->getTicketStatus->status_color : '';
                return '<span class="badge badge-white p-2 px-3 fix_badge status_badge" style="background: ' . $statusColor . ';">' . $status . '</span>';
            })

            ->editColumn('created_at', function ($ticket) {
                return $ticket->created_at->diffForHumans();
            })

            ->rawColumns(['ticket_id', 'name', 'created_by', 'subject', 'category_id', 'status_id']);
        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(HelpdeskTicket $model, Request $request): QueryBuilder
    {
        $tickets = $model->with('getTicketCategory', 'getTicketStatus');
        $status = $this->status;
        if (isset($status)) {
            if ($status == 1) {
                $tickets->where('status_id', 1);
            } elseif ($status == 2) {
                $tickets->where('status_id', 2);
            } elseif ($status == 3) {
                $tickets->where('status_id', 3);
            } elseif ($status == 4) {
                $tickets->where('status_id', 4);
            }
        }
        if (Auth::user()->type == 'super admin') {
            $tickets = $tickets->orderBy('id', 'desc');
        } else {
            $tickets = $tickets->where('user_id', Auth::user()->id)->orderBy('id', 'desc');
        }

        return $tickets;
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('helpdeskticket-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'ticket_id' => ['title' => __('Ticket Id')],
            'name' => ['title' => __('Assigned To')],
            'email' => ['title' => __('Email')],
            'created_by' => ['title' => __('Created By')],
            'subject' => ['title' => __('Subject')],
            'category_id' => ['title' => __('Category')],
            'status_id' => ['title' => __('Status'), 'addClass' => 'text-capitalize'],
            'created_at' => ['title' => __('Created')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Helpdeskticket_' . date('YmdHis');
    }
}
