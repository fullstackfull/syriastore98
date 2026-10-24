<?php

namespace App\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * P1-INV-21 / #68 item 1 -- the recorded lottery ticket price is whatever the client posted.
 *
 * THE DEFECT. Two different values, and only one is trustworthy.
 *
 *   THE CHARGE is server-side. `LotteryPrizesController::lottery_form_data:540` passes
 *   `$lottery_setting->ticket_price` into the payment service, which hands it to the gateway views
 *   (`payment/paystack.blade.php:20`, `payment/razorpay.blade.php:20`).
 *
 *   THE RECORD is not. `resources/views/theme/lottery-form.blade.php:277` posts `ticket_price` as a
 *   HIDDEN INPUT; it rides into the session with the rest of the request; and
 *   `StoreLotteryPayment:571` reads it back into `$ticket_amount`, which `:614` assigns to
 *   `$lottery_data->amount` before `:617` saves. The validator at `:513-527` covers the nine
 *   `lottery_info.*` fields and `payment_methods` -- not `ticket_price`.
 *
 * And `purchase_tickets.amount` is a **string** column
 * (`2024_10_24_063457_create_purchase_tickets_table.php:30`), so it is not even type-constrained:
 * any value at all is storable.
 *
 * WHY A MODEL EVENT AND NOT MIDDLEWARE. The handler reads the amount from the SESSION, not the
 * request, so there is nothing for request middleware to rewrite -- `LotteryTicketWriteGuard`
 * already refuses the session-less path and cannot touch this one. `packages/workdo/` is vendored
 * and must not be edited. What is left is the model's own `creating` event, which fires between the
 * controller's assignments and the INSERT.
 *
 * THAT THE EVENT FIRES AT ALL WAS VERIFIED, NOT ASSUMED, because `PurchaseTicket` uses `Cachable`
 * and a trait that intercepted persistence would make this inert:
 *
 *   - `vendor/genealabs/laravel-model-caching/src/Traits/Cachable.php` is six lines: it composes
 *     `Caching`, `ModelCaching` and `PivotEventTrait` and defines nothing itself.
 *   - `ModelCaching` overrides `__get`, `__set`, `all()`, `bootCachable()`, `destroy()`,
 *     `newEloquentBuilder()` and `newBelongsToMany()` -- and NOT `save`, `performInsert` or
 *     `fireModelEvent`.
 *   - `bootCachable()` adds listeners on `created`, `deleted` and `saved` only. Nothing on
 *     `creating`.
 *
 * So dispatch is stock: `Model::save()` -> `performInsert()` -> `fireModelEvent('creating')`, and
 * the `creating` gate runs BEFORE `getAttributesForInsert()`, which is what makes an attribute
 * written here reach the INSERT.
 *
 * REGISTERED BY EVENT-NAME STRING, not `PurchaseTicket::observe()`. The class only exists when the
 * module is loaded, and module providers boot from `app->booted()` -- after the host's own
 * providers. A string key needs no class, so registration cannot fatal when the module is absent
 * and cannot race the loader when it is present. The cost is that the fully-qualified name becomes
 * load-bearing, which is why a test asserts the listener is registered under exactly the name the
 * model reports.
 *
 * IT REWRITES THE AMOUNT AND NOTHING ELSE. Two things it deliberately does NOT do:
 *
 *   - IT DOES NOT RE-DERIVE `store_id`. That looked like a second win and is a TAUTOLOGY, which an
 *     adversarial pass caught before it shipped: `:595-597` selects the lottery
 *     `where('store_id', $store->id)`, so `$lottery_setting->store_id` is identically `$store->id`
 *     -- which is exactly what `:616` already assigns. Re-deriving it could never change a value.
 *     Writing it anyway would be a control that cannot fail, which is worse than no control
 *     because it reads as protection.
 *   - IT DOES NOT ABORT. A `creating` listener returning `false` makes `save()` return `false`, and
 *     `:617` is a bare `$lottery_data->save();` whose return value is discarded -- so execution
 *     continues to the success response, and `theme.payment_success` renders the in-memory model
 *     with a BLANK date (`updateTimestamps()` runs after the gate). A silent false success is worse
 *     than the defect. Refusal, where it is needed, belongs in request middleware that can return a
 *     response.
 *
 * WHAT THIS IS AND IS NOT. It is LEDGER INTEGRITY, not revenue -- stated plainly because the
 * opposite framing is tempting. The only read of the stored `purchase_tickets.amount` anywhere in
 * the repository is `theme/payment_success.blade.php:122`, the buyer's own receipt; the module's
 * admin views do not display it; and the draw re-derives from `ticket_price` rather than reading
 * the ticket. So a forged amount misstates the buyer's receipt and the stored record, and does not
 * move money. It is still worth closing: the owner's standing rule is that client-submitted price
 * is never financial authority, and a money-bearing column that disagrees with the charge is a
 * reconciliation problem whether or not anything reads it today.
 *
 * STILL OPEN, and not closable here: payment verification (#68 item 2). `purchase_tickets` has NO
 * payment-status, transaction-id or gateway-reference column at all -- the full column list is
 * `lottery_id, lottery_code, customer_id, firstname, lastname, email, number, address, country_id,
 * state_id, city_id, postcode, product_id, amount, payment_type, store_id, timestamps` -- so there
 * is nowhere to record settlement and nothing for any listener to check.
 */
class EnforceLotteryTicketPrice
{
    /**
     * The event name this listener is registered under. The FQCN is the vendored model's, and
     * `static::class` never carries a leading backslash, so neither does this.
     */
    public const EVENT = 'eloquent.creating: Workdo\LotteryPrizes\app\Models\PurchaseTicket';

    /**
     * RETURNS void, NEVER false, and the signature takes the MODEL.
     *
     * `fireModelEvent` dispatches with `until($event, $this)`, so the payload is the model itself
     * and the dispatcher spreads it as the first argument -- not the `($event, $payload)` pair a
     * WILDCARD listener receives. The first version of this listener used the wildcard signature
     * and died with a TypeError on the first save, which the premise test caught immediately.
     *
     * `void` matters as much as the parameter: `until()` HALTS on any non-null return, and a
     * `false` here would make `save()` return false while the controller discards it -- a silent
     * false success. See the class docblock.
     */
    public function handle(Model $model): void
    {
        $this->apply($model);
    }

    /**
     * Overwrite `amount` with the authoritative price for the ticket's own lottery.
     *
     * RAW QUERY, DELIBERATELY. `LotterySetting` is a module model and this runs inside another
     * model's `creating` event; reading through Eloquent here would both require the module class
     * and risk the model-cache serving a stale row mid-write. `DB::table()` asks the database.
     *
     * NO PREDICATE BEYOND THE PRIMARY KEY, and that is correct rather than an omission: the ticket
     * is being written for a specific `lottery_id`, and the question is "what does THAT lottery
     * charge". A `store_id` predicate would have to come from the model's own `store_id` -- the
     * value this event cannot trust -- and would narrow a lookup that the primary key already makes
     * unique.
     */
    private function apply(Model $model): void
    {
        $lotteryId = $this->usableId($model->getAttribute('lottery_id'));

        if ($lotteryId === null) {
            // LEAVE IT ALONE rather than refuse or zero it. Through the HTTP handler this is
            // unreachable: `:612` assigns `$lottery_setting->id` and throws on a null lottery five
            // lines before the save. So the only callers that can arrive here without a usable
            // lottery id are seeders or future code, and for those the status quo is the
            // conservative answer -- silently writing 0 would corrupt a row this listener does not
            // understand.
            return;
        }

        try {
            $price = DB::table('lottery_settings')->where('id', $lotteryId)->value('ticket_price');
        } catch (\Throwable $e) {
            // The module's tables exist when its routes do, but "in practice" is not a control.
            // Failing open leaves the row exactly as the handler built it -- no worse than today.
            return;
        }

        if ($price === null) {
            return;
        }

        $model->setAttribute('amount', $price);
    }

    /**
     * STRICT, and `is_numeric` is not good enough -- the lesson `ComparesRowOwnership` records at
     * its own expense. Note `purchase_tickets.lottery_id` is a STRING column
     * (migration `:17`), so the value arriving here genuinely can be anything.
     */
    private function usableId(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_scalar($value)) {
            return null;
        }

        $id = filter_var((string) $value, FILTER_VALIDATE_INT);

        return ($id === false || $id <= 0) ? null : $id;
    }
}
