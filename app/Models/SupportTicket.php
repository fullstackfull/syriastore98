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
        // `answer` WAS DECLARED HERE AND `support_tickets` HAS NO SUCH COLUMN -- no migration
        // declares one and no handler reads or writes it. It is the obvious name for "the merchant's
        // reply", which is what makes a lie here expensive: a writer that trusted $fillable would
        // either write nothing or error on a column the model said was mass-assignable. The reply
        // lives in `support_conversions`. Pinned both ways by
        // SupportModelFillableMatchesSchemaTest.
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
     * A TICKET NUMBER TWO SHOPPERS CANNOT SHARE.
     *
     * `AccountProfileController::support_ticket_store()` wrote `time()`, and
     * `support_tickets.ticket_id` is a nullable string with NO unique index, so two shoppers who
     * submitted in the same second were silently given the same number -- the one handle either
     * party has for a ticket in an e-mail or a phone call, naming two conversations. Reproduced by
     * driving the real door twice (`ShopperTicketReferenceTest`).
     *
     * STILL CLOCK-LED, and that is the only part of the old format that was doing anything: every
     * reference already in the table is a bare Unix second, so a number that stopped beginning with
     * one would sort a six-month-old ticket after today's wherever a list orders by it. The entropy
     * is appended, not substituted.
     *
     * `random_bytes()`, not `uniqid()` or `rand()`: `uniqid()` IS the clock (its own documentation
     * says so) and would reintroduce the defect under a different spelling, and `rand()` is seeded
     * per process, so two workers that start in the same second can draw the same value.
     *
     * NO UNIQUE INDEX IS ADDED. That is the structural fix, it is a persistent migration over a
     * column that already holds colliding values, and CLAUDE.md section 2 makes such a migration an
     * owner decision. 24 bits of entropy per second makes a collision improbable rather than
     * impossible; the index is reported with GAP-176, not added here.
     */
    public static function newReference(): string
    {
        return time() . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

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
