<?php

namespace App\Listeners;

use App\Events\AddRewardClubPoint;
use App\Support\Loyalty\LoyaltyAttribution;

/**
 * `MKT-005` — NOTE THE ORDER THAT IS ABOUT TO MOVE A CUSTOMER'S LOYALTY BALANCE.
 * =================================================================================================
 *
 * `AddRewardClubPoint` is dispatched from nine first-party sites immediately before the vendored
 * `AddRewardClubPointLis` moves `customers.total_club_point`. This listener leaves a note naming the
 * order, which {@see \App\Observers\CustomerLoyaltyPointObserver} claims when it sees the movement
 * land. Without it an audit row can say what happened and not why.
 *
 * -------------------------------------------------------------------------------------------------
 * IT READS THE ORDER AND DELIBERATELY NOT THE FLAG'S TRUTHINESS
 * -------------------------------------------------------------------------------------------------
 *
 * `club_point_is_active` is compared with `=== 'on'`, exactly, and the result is recorded as a CLAIM
 * — never as the movement's kind. Two measured reasons:
 *
 * 1. **IT IS CALLER-SUPPLIED.** The host overwrites it server-side at the quote
 *    (`PaymentController::processRewardPoints`), but `GAP-214` records the gateway-return handlers
 *    as not covered. So `'on'` means *the request said a redemption was being applied*, which is
 *    worth auditing and is not the same statement as *points were spent*. The kind comes from the
 *    observed delta instead.
 * 2. **`'off'` IS TRUTHY**, and `'off'` is exactly what the shipped checkout posts on a qualifying
 *    order — the whole substance of D7 and of `NormaliseRewardRedemptionFlag`. A `!empty()` or a
 *    `??`-bound comparison here would record a redemption claim on every such order. `=== 'on'` is
 *    correct whether or not the normaliser has already run, which is why this listener carries **no
 *    ordering dependency on it**. Both are discovered out of `app/Listeners` and their relative
 *    order is a property of directory iteration — not a thing to build on.
 *
 * -------------------------------------------------------------------------------------------------
 * IT CHANGES NOTHING AND CAN FAIL NOTHING
 * -------------------------------------------------------------------------------------------------
 *
 * It does not touch `$event`, so unlike `NormaliseRewardRedemptionFlag` it has no effect on what any
 * later listener reads. It writes only to a request-scoped note. It is dispatched inside payment
 * confirmation, so it must not throw: every field is read defensively, because `$event->order` is an
 * `Order` model at some dispatch sites and a plain array at others.
 *
 * REGISTERED TWICE, HARMLESSLY — explicitly in `AppServiceProvider::boot()` and again by Laravel's
 * event discovery over `app/Listeners`, which is ON (CLAUDE.md: `Application::configure()` calls
 * `->withEvents()` unconditionally, whatever `App\Providers\EventServiceProvider::shouldDiscoverEvents()`
 * returns). The explicit line is kept for the same reason the normaliser keeps its own: a reader who
 * finds `shouldDiscoverEvents() === false` would otherwise conclude this listener is dead.
 * {@see \App\Support\Loyalty\LoyaltyAttribution::attribute()} is idempotent so that running twice is
 * indistinguishable from running once.
 */
class RecordLoyaltyPointAttribution
{
    public const EVENT = AddRewardClubPoint::class;

    public const SOURCE = 'reward-club-point-event';

    public function handle(AddRewardClubPoint $event): void
    {
        $order = $event->order ?? null;

        /*
         * `$event->order` is an `Order` MODEL at some dispatch sites and a plain ARRAY at others —
         * the vendored listener itself reads it with `$order['customer_id']`, which works for both
         * because Eloquent models implement ArrayAccess. Reading it as an array here matches that
         * and avoids a `property on array` fatal inside payment confirmation.
         */
        if (! is_array($order) && ! $order instanceof \ArrayAccess) {
            return;
        }

        $customerId = (int) ($order['customer_id'] ?? 0);

        if ($customerId <= 0) {
            return;
        }

        $requests = $event->requests_data ?? null;

        $flag = (is_array($requests) || $requests instanceof \ArrayAccess)
            ? ($requests['club_point_is_active'] ?? null)
            : null;

        app(LoyaltyAttribution::class)->attribute(
            customerId: $customerId,
            orderId: (int) ($order['id'] ?? 0),
            /* See the class docblock: `=== 'on'`, exactly, and recorded as a claim. */
            redemptionClaimed: $flag === 'on',
            source: self::SOURCE,
        );
    }
}
