<?php

namespace App\DataTables;

use App\Support\Html\RichText;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Workdo\Helpdesk\DataTables\HelpdeskData;
use Yajra\DataTables\EloquentDataTable;

/**
 * The Helpdesk module's ticket list, with the cells it builds as markup ESCAPED.  P1-SEC-75.
 *
 * `Workdo\Helpdesk\DataTables\HelpdeskData` (vendored, not edited) builds the `name`, `subject`, `created_by`, `category_id`, `status_id` and `ticket_id` cells by concatenating
 * stored text into a string of markup and declares all six `rawColumns`. `name` and `subject` are what a merchant typed into a ticket; `created_by` is the USER'S profile name,
 * which the merchant sets for themselves; the category and status colours go into a `style` attribute. The list is the first page the platform operator opens, so each was a way
 * for a registered merchant to run script in the operator's session.
 *
 * This subclass is bound over the module's class in AppServiceProvider, which is the class the module's own controller injects (`HelpdeskController::index(Request, HelpdeskData,
 * $status)`). The query, the columns, the filename and the HTML builder are the vendored ones; `dataTable()` is REBUILT, not extended, and that is the point of the rebuild:
 * Yajra keeps every `editColumn()` and runs them all in order, so a subclass that called `parent::dataTable()` and added its own cells would still run the vendored closure for each
 * of them first -- and the vendored `created_by` closure reads `$ticket->createdBy->name`, which throws when the creating user's row is gone (a merchant deleting a staff member is
 * enough), taking the operator's whole list down with the exception text (executed by two reviewers of P1-SEC-75). Nothing of the vendored `dataTable()` runs here.
 *
 * The markup around the text is exactly what the vendored code writes; the text goes through e(); a colour goes through RichText::cssColor() and is dropped, with its attribute, when it
 * is not a colour. THE VENDORED FILE IS PINNED (tests/Unit/Packages/HelpdeskRawSinksTest): when the module is updated that test fails, and the prompt is to re-diff this method against
 * the vendored one -- a column added upstream would otherwise come out of this list without its formatting, and one that stops being raw would be raw here.
 */
final class SafeHelpdeskData extends HelpdeskData
{
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
            ->addIndexColumn()
            ->addColumn('action', fn ($ticket) => view('helpdesk::helpdesk_ticket.action', compact('ticket')))
            ->editColumn('ticket_id', function ($ticket) {
                $link = '<a class="btn btn-badge btn-outline-primary"';

                if (auth()->user()->isAbleTo('Reply Helpdesk Ticket')) {
                    $link .= ' href="' . e(route('helpdesk.ticket.reply', ['ticketId' => encrypt($ticket->ticket_id)])) . '"';
                } else {
                    $link .= ' href="#"';
                }

                return $link . '>#' . e($ticket->ticket_id) . '</a>';
            })
            ->editColumn('name', fn ($ticket) => '<span class="white-space">' . e($ticket->name) . '</span>')
            ->editColumn('created_by', fn ($ticket) => '<span class="text-primary">' . e($ticket->createdBy?->name) . '</span>')
            ->editColumn('subject', fn ($ticket) => '<span class="white-space">' . e($ticket->subject) . '</span>')
            ->editColumn('category_id', fn ($ticket) => '<span class="badge badge-white p-2 px-3 fix_badge category_badge"' . self::background($ticket->getTicketCategory->color ?? null) . '>' . e($ticket->getTicketCategory->name ?? '') . '</span>')
            ->editColumn('status_id', fn ($ticket) => '<span class="badge badge-white p-2 px-3 fix_badge status_badge"' . self::background($ticket->getTicketStatus->status_color ?? null) . '>' . e($ticket->getTicketStatus->status_name ?? '') . '</span>')
            ->editColumn('created_at', fn ($ticket) => $ticket->created_at?->diffForHumans())
            ->rawColumns(['ticket_id', 'name', 'created_by', 'subject', 'category_id', 'status_id']);
    }

    /** the badge's background, from a stored colour that really is one; nothing otherwise */
    private static function background(?string $color): string
    {
        $color = RichText::cssColor($color);

        return $color === null ? '' : ' style="background: ' . e($color) . ';"';
    }
}
