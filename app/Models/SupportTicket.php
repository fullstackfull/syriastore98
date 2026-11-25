<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    use HasFactory, Cachable, BelongsToStore;

    protected $fillable = [
        'order_id',
        'ticket_id',
        'customer_id',
        'title',
        'description',
        'attachment',
        'answer',
        'status',
        
        'store_id',
        'created_by'
    ];

    public function UserData()
    {
        return $this->hasOne(Customer::class, 'id',
        'customer_id');
    }

    public function conversions()
    {
        return $this->hasMany(SupportConversion::class, 'ticket_id', 'id')->orderBy('id');
    }

    public function orderData()
    {
        return $this->hasOne(Order::class, 'id',
        'order_id');
    }

    /**
     * THE STATUSES THIS WRITER ACCEPTS -- the three the shipped dropdown offers, unchanged.
     *
     * Previously spelled out as three IDENTICAL `if` blocks comparing against three literals, which
     * is how the method came to have a path that returns nothing (see below). Declared once so the
     * controller's validation and this writer cannot disagree about what a valid status is.
     *
     * NOT A NEW WORKFLOW. Which transitions the UI offers from which state is `support/edit.blade.php`'s
     * business and is not changed here; this is only the set a write may name.
     */
    public const STATUSES = ['open', 'In Progress', 'Solved'];

    /**
     * Change a ticket's status, answering `['status' => 'success'|'error', 'message' => ...]`.
     *
     * AN UNKNOWN STATUS USED TO BE AN HTTP 500, and the cause was the shape rather than the check.
     * The three `if`s each returned on a match and there was no `else`, so for any other value
     * control fell off the end of the `if (! empty($ticket))` block and the method returned PHP's
     * IMPLICIT NULL. `SupportTicketController::ticket_status_change()`'s next line is
     * `$responce['status']`, and reading an offset on null raises a warning Laravel promotes to
     * `ErrorException` -- so the merchant's status dropdown answered 500. Measured by driving the
     * real route with a status of "Escalated to legal".
     *
     * Every path now returns an array. The controller validates first as well, so this is defence
     * in depth rather than the only guard -- but a method whose contract is "an array with a status
     * key" must not have a path that silently returns nothing, and the next caller will not know to
     * check.
     */
    public static function ticket_status_change($date = [])
    {
        $ticket_id = $date['ticket_id'] ?? null;
        $ticket_status = $date['ticket_status'] ?? null;

        if (! in_array($ticket_status, self::STATUSES, true)) {
            return ['status' => 'error', 'message' => 'Unrecognised ticket status.'];
        }

        $ticket = SupportTicket::find($ticket_id);

        if (empty($ticket)) {
            return ['status' => 'error', 'message' => 'Ticket not found.'];
        }

        $ticket->status = $ticket_status;
        $ticket->save();

        return ['status' => 'success', 'message' => 'Ticket status changed.'];
    }
}
