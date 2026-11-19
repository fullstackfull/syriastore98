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
 * $status)`), so the rest of the module -- the query, the columns, the action cell, the export -- is the vendored code and only these six cells are redefined. The markup around the
 * text is exactly what the vendored code writes; the text goes through e(); a colour goes through RichText::cssColor() and is dropped, with its attribute, when it is not a colour.
 * Re-diff this file against the vendored one whenever the Helpdesk module is updated: tests/Feature/Security/HelpdeskStoredXssTest pins the output.
 */
final class SafeHelpdeskData extends HelpdeskData
{
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        return parent::dataTable($query)
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
            ->editColumn('status_id', fn ($ticket) => '<span class="badge badge-white p-2 px-3 fix_badge status_badge"' . self::background($ticket->getTicketStatus->status_color ?? null) . '>' . e($ticket->getTicketStatus->status_name ?? '') . '</span>');
    }

    /** the badge's background, from a stored colour that really is one; nothing otherwise */
    private static function background(?string $color): string
    {
        $color = RichText::cssColor($color);

        return $color === null ? '' : ' style="background: ' . e($color) . ';"';
    }
}
