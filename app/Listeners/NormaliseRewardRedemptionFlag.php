<?php

namespace App\Listeners;

use App\Events\AddRewardClubPoint;

/**
 * D7 — reduce the posted reward-redemption flag to `'on'`-or-absent, before the vendored
 * `RewardClubPoint` listener reads it.
 *
 * OWNER-APPROVED 2026-11-17, D7: *"Use safe first-party protections for the identified vendor
 * defects and report vendor issues upstream. Do not directly modify protected WorkDo vendor
 * packages."*
 *
 * WHAT IT PROTECTS AGAINST. `AddRewardClubPointLis.php:38` is
 *
 *     if ($requests_data['club_point_is_active'] ?? '' == 'on' && $requests_data['final_amount_point'] == $order['final_price'])
 *
 * `==` and `&&` bind tighter than `??`, so that parses as
 * `$flag ?? (('' == 'on') && (…))` — i.e. `$flag ?? false`, the TRUTHINESS of the posted flag,
 * with the anti-tamper comparison stranded in a dead operand that has never executed.
 *
 * `'off'` is a non-empty string, so it is truthy, so the redemption branch runs. That branch does
 * `$totalClubPoint = $orderClubPoint;` (:39) — an assignment, not an addition — and then saves it
 * to `customers.total_club_point` (:46-47). **So an order that redeemed nothing REPLACES the
 * customer's accumulated balance with just that order's earned points, and writes a
 * `RewardClubPoint` row claiming the whole prior balance was spent (:55).**
 *
 * And `'off'` is not a hypothetical value. It is what the shipped checkout posts, in
 * `clubPointApply.blade.php:25`:
 *
 *     <input type="hidden" class="club_point_is_active" name="club_point_is_active" value="off">
 *
 * reaching the browser via `ViewComposer.php:27-32` -> `CartController.php:114` ->
 * `checkout-cart-list.blade.php:97` -> `checkout_list.blade.php:2` -> `front-theme.js:224`,
 * injected into `.checkout_page_cart`, which sits between `Form::open` and `Form::close` at
 * `checkout.blade.php:25/256/263` and is therefore submitted. The module's JavaScript flips it to
 * `'on'` only when the shopper applies points (`clubPointApply.blade.php:80`); the shared `class`
 * is the handle it uses.
 *
 * IT IS GATED, AND AN EARLIER VERSION OF THIS PARAGRAPH SAID OTHERWISE. That input sits inside
 * `clubPointApply.blade.php:1-9` -- six reward settings present, AND
 * `total_club_point >= checkout_points`, AND `final_price >= checkout_price`. So the destructive
 * path fires for a logged-in customer WHO ALREADY QUALIFIES TO REDEEM and did not press Apply,
 * not on every checkout. It degenerates to every logged-in checkout when both thresholds are
 * configured at 0, which is savable because `RewardClubPointController:26-33` validates them with
 * a bare `required`. Either way it is the population whose balance is worth destroying, and a
 * hand-crafted POST -- or any storefront-API caller reaching `PaymentService.php:3435` -- sets the
 * flag whatever a view renders.
 *
 * (The first version of this traced `clubPointFormField.blade.php` ->
 * `checkout_hide_field.blade.php` -> `common/checkout/checkout_form.blade.php:125` and claimed
 * "every order". NOTHING RENDERS THAT LAST FILE: its only reference anywhere is LynomiaChat's
 * ViewComposer naming it as a composer target, and the live checkout includes a different file of
 * nearly the same name. The test that "pinned" the claim asserted four strings existed in four
 * files and stayed green through a full revert. Both are replaced; see
 * `test_premise_the_flag_is_posted_only_when_the_customer_already_qualifies` and
 * `test_premise_the_other_hidden_field_is_on_a_path_nothing_renders`, which is a ratchet on that
 * dead path: if it is ever revived the flag starts posting UNGATED and the severity goes back up.)
 *
 * WHY A LISTENER AND NOT NINE EDITS. The event is dispatched from nine first-party sites. The
 * event object is passed by handle, so a mutation here is visible to every later listener. One
 * place to read, one place to test, and no vendored file touched.
 *
 * THE ORDERING IS THE LOAD-BEARING CLAIM, AND IT IS MEASURED, NOT REASONED. The vendored listener
 * must run AFTER this one or the normalisation is pointless. Module providers are brought in from
 * `app->booted()`, while every registration of this listener happens during `booting` or in a host
 * provider's `boot()` — both strictly earlier. Verified by booting this repository's real
 * `bootstrap/app.php`, registering a probe listener from `app->booted()` exactly as a module
 * provider arrives, dispatching the event with `club_point_is_active => 'off'`, and observing what
 * the probe saw:
 *
 *     [0] App\Listeners\NormaliseRewardRedemptionFlag          <- explicit, AppServiceProvider::boot()
 *     [1] App\Listeners\NormaliseRewardRedemptionFlag@handle   <- Laravel 11 event DISCOVERY
 *     [2] Closure                                              <- registered from app->booted()
 *
 *     the probe observed: 'ABSENT'
 *
 * IT IS REGISTERED TWICE, AND THAT IS DISCLOSED RATHER THAN TIDIED AWAY. `Application::configure()`
 * calls `->withEvents()` unconditionally (`Illuminate/Foundation/Application.php:244`), which
 * registers the FRAMEWORK's `EventServiceProvider`, whose `protected static $shouldDiscoverEvents`
 * is `true` and whose discovery path defaults to `app/Listeners`. So every class in that directory
 * with a typed `handle()` is auto-registered — measured: all five, this one included, and
 * `VerifyReCaptchaTokenLis` is likewise registered both explicitly and by discovery today.
 *
 * NOTE THAT `App\Providers\EventServiceProvider::shouldDiscoverEvents()` RETURNS FALSE AND DOES
 * NOT TURN THIS OFF. That override applies only to the app's own provider instance; the framework
 * registers a second one. A reader who trusts that method would conclude discovery is disabled,
 * which is why the explicit `Event::listen` is KEPT: it is the registration a reader can see, and
 * it survives discovery being switched off. The cost is that the handler runs twice per dispatch.
 * It is idempotent by construction — a second pass sees either a canonical `'on'` or an already
 * absent key — and `test_the_handler_is_idempotent` pins that, so the duplicate can never become a
 * defect.
 *
 * THIS IS A SHAPE NORMALISATION, NOT A SECOND DECISION, and that is the entire design. It does
 * not read a balance, consult a threshold, compute money, or decide whether redemption was
 * allowed — so it cannot drift from the ELEVEN sites that apply the discount. It only stops a
 * value none of those sites treats as "yes" from being read as "yes" here. No input moves from the
 * accrual branch to the redemption branch; only `'off'` moves, and it moves to the branch the
 * vendor's own `else` was written for.
 *
 * ELEVEN APPLY THE DISCOUNT, NINE DISPATCH THE EVENT, and an earlier version of this docblock
 * conflated the two. Measured: eleven sites in `app/` read `club_point_is_active` behind
 * `module_is_active('RewardClubPoint')` and apply a discount; nine `event(new AddRewardClubPoint(`
 * sites exist across `app/` and `packages/`. The odd one out is `KhaltiPaymnetController:972`,
 * which applies the discount and dispatches NOTHING — so a Khalti shopper takes the discount and
 * keeps their points. That is a pre-existing leak, already recorded as an owner item in
 * `PHASE-05-OWNER-DECISION-PACKAGE.md:145` and assigned to D4; this listener neither causes nor
 * fixes it, and cannot, because there is no event on that path to intercept.
 *
 * `==` AND NOT `===`. Every first-party site compares `… == 'on'` loosely, and an API client can
 * post JSON `true`, for which `true == 'on'` is TRUE in PHP 8. A strict comparison here would
 * strip a flag those sites honour — a discount applied with no points debited, i.e. free money
 * introduced by the guard meant to prevent a loss. The kept value is then canonicalised to the
 * literal `'on'` so nothing downstream has to repeat the loose comparison.
 *
 * WHAT IT DELIBERATELY DOES NOT DO:
 *
 *   - IT DOES NOT ENFORCE ELIGIBILITY. `checkout_points` and `checkout_price` are still checked in
 *     Blade only, so a hand-crafted `club_point_is_active=on` still redeems below both thresholds.
 *     That is D6, and it is a separate slice with its own owner-approved policy content. Putting
 *     it here would make this class a decision-maker and reintroduce the drift risk above.
 *   - IT DOES NOT REPAIR AN EXISTING BALANCE. D4 forbids retrospectively altering balances
 *     without separate approval, and the history needed to reconstruct them may not exist.
 *   - IT DOES NOT ABORT ANYTHING. There is no refusal path: the event is dispatched after the
 *     order is written, so refusing here could only produce an order with no reward accounting.
 *   - IT DOES NOT TOUCH THE OTHER TWO DEFECTS IN THE SAME VENDORED FILE. `:37` and `:41` both
 *     carry the `($a * $b ?? 0) / ($c ?? 0)` shape — a mis-bound multiplication and a divisor
 *     defaulted to zero, the pair `App\Support\Money\RewardPointDiscount` exists to fix on the
 *     first-party side. They are recorded in
 *     `docs/security/P05-VENDOR-DEFECTS-REWARDCLUBPOINT.md`, not intercepted: `:37` runs before
 *     this listener's effect can matter and `:41` only feeds a ledger column, and neither can be
 *     reached without editing the vendored file.
 *
 * Pinned by tests/Unit/Loyalty/RewardRedemptionFlagTest, whose premise tests assert the vendored
 * expression is still the mis-bound one — so if WorkDo fixes it upstream, CI says so instead of
 * this class silently becoming redundant.
 */
final class NormaliseRewardRedemptionFlag
{
    /**
     * Registered by class in AppServiceProvider::boot(). The event is first-party
     * (app/Events/AddRewardClubPoint.php), so unlike EnforceLotteryTicketPrice there is no need to
     * key the registration on a string to survive the module being absent.
     */
    public const EVENT = AddRewardClubPoint::class;

    public function handle(AddRewardClubPoint $event): void
    {
        $data = $event->requests_data;

        // PaymentController reads `Session::get('request_data')`, which can be null, and the
        // event's constructor is untyped. A listener that assumed an array would fatal there.
        if (! is_array($data)) {
            return;
        }

        $flag = $data['club_point_is_active'] ?? null;

        // LOOSE, to match every first-party site that applies the discount -- see the class
        // docblock. `null == 'on'`, `'' == 'on'` and `0 == 'on'` are all false in PHP 8, so an
        // absent, empty or zero flag falls through to the strip below, which is a no-op for it.
        //
        // AND NO `!== null` GUARD. There was one, and mutation proved it DEAD: measured across
        // null/'off'/'on'/''/'0'/0/true/'yes', `$flag !== null && $flag == 'on'` and `$flag == 'on'`
        // agree on every input, because `null == 'on'` is already false. This repository's own rule
        // -- written into RewardPointDiscount after three mutually-covering guards there each
        // passed the suite when deleted -- is that a check which cannot fail reads as protection
        // and is a trap, not depth. One comparison, pinned on its own.
        if ($flag == 'on') {
            $data['club_point_is_active'] = 'on';
            $event->requests_data = $data;

            return;
        }

        // `final_amount_point` goes with it so that a FUTURE upstream precedence fix cannot read a
        // stale posted total. Removing it cannot introduce an undefined-key error: with the flag
        // absent, `'' == 'on'` is false and `&&` short-circuits before the key is read.
        unset($data['club_point_is_active'], $data['final_amount_point']);

        $event->requests_data = $data;
    }
}
