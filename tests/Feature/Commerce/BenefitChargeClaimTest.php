<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Product;
use App\Services\OrderTotalReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-285 — ONE CAPTURED CHARGE ID MUST AUTHORISE ONE ORDER.
 * =================================================================================================
 *
 * WHAT THE ROW CLAIMS. The storefront return checks the Tap charge is `CAPTURED` but binds it to
 * nothing: no order, no amount, no currency, no single-use claim. `claimGatewayReturn()` keys only
 * on the checkout nonce for this rail, and a *new* checkout mints a fresh nonce — so a re-used
 * `tap_id` is not a replay, and one real charge authorises unlimited orders of any size.
 *
 * WHAT WAS NOT TRUE OF THIS REPOSITORY WHEN THIS FILE WAS WRITTEN. The claim half was reported as
 * already shipped. Measured across **every** branch in this repository — `feature/…`, `int/…` and
 * all ten `ws/…` — no `tap_id` claim existed anywhere. So this is the first one.
 *
 * HOW IT IS IMPLEMENTED, AND WHY NOT AS A FRESH `Cache::add`. `gatewayTransactionReference()` is
 * the one place that answers "what is this gateway's own identifier for the payment", and its own
 * docblock records the storefront return as one of four rails that "read no transaction identifier
 * from their return at all". Teaching it to read `tap_id` makes `claimGatewayReturn()` — which this
 * handler ALREADY calls, below the gateway-finality checks — mint a payment key for the charge,
 * with the store scoping, the TTL, the fail-closed partial-claim rule, the refusal text, the log
 * line and the release path that every other rail already gets. A bespoke `Cache::add` beside it
 * would be a second copy of a control this codebase has twice recorded as a drift hazard.
 *
 * A NON-TAP RETURN IS UNAFFECTED: no `tap_id` means no reference, which means no payment key, which
 * is the documented "a missing field never produces a WRONG key" behaviour. Asserted below.
 *
 * WHAT THIS SUITE CANNOT DRIVE, stated so a pass is not over-read. The capture check itself is
 * unreachable here: `getProductStatus` does `new \App\Package\Payment([...])` and calls
 * `getCharge()`, which is a live cURL to api.tap.company — constructed with `new` inside the
 * handler, so it cannot be swapped through the container, and with no egress it throws into the
 * handler's own `catch` and returns the failure redirect. **So an end-to-end "Tap return" test
 * would assert "no order was written" against a handler that writes none for anybody, which is
 * vacuous** — the trap this project calls a test that cannot fail. The claim is therefore driven on
 * the real route through a rail that reaches the claim, with the charge id present, which is
 * exactly the property the row is about: the same `tap_id` must not authorise a second order on a
 * second, genuinely new checkout.
 *
 * THE AMOUNT HALF IS MEASURED HERE AND DELIBERATELY NOT FIXED. See
 * `test_the_amount_half_*` below for the figures and `GAP-285`'s report for why it is an owner
 * question rather than an arithmetic one.
 *
 * @see \App\Http\Controllers\Concerns\GuardsGatewayReturn::gatewayTransactionReference()
 * @see \App\Http\Controllers\PaymentController::getProductStatus()
 */
class BenefitChargeClaimTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The refusal text a replayed return gets, so the assertions name the guard that fired. */
    private const REPLAY_REFUSAL = 'already been recorded against an order';

    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'bc-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        foreach (['stock_management' => 'on', 'out_of_stock_threshold' => '0', 'notification' => '[]'] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('countries')->insertOrIgnore([
            'id' => 101, 'name' => 'Syria', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('states')->insertOrIgnore([
            'id' => 1, 'name' => 'Damascus', 'country_id' => 101,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cities')->insertOrIgnore([
            'id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$merchant, $store];
    }

    private function productIn($store, float $price = 100.00, int $stock = 50): Product
    {
        return Product::forceCreate([
            'name' => 'bc-' . uniqid(), 'slug' => 'bc-' . uniqid(),
            'product_stock' => $stock, 'track_stock' => 1, 'variant_product' => 0,
            'price' => $price, 'sale_price' => $price, 'status' => 1,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    /**
     * A fresh checkout state, with its own nonce — so the nonce claim can never be what refuses.
     *
     * `payment_type` is `bank_transfer` and not `Tap`, for the reason the class docblock gives: the
     * Tap branch makes a live cURL call this suite cannot reach, so driving it would measure a
     * handler that writes nothing for anybody. What is under test is the CHARGE CLAIM, which sits
     * below those branches and applies to the charge id whichever rail carried it.
     */
    private function freshCheckout($store): array
    {
        return [
            'slug' => $store->slug,
            /*
             * `store_id` IS LOAD-BEARING AND ITS ABSENCE WAS A REAL FALSE READING. The payment
             * claim key takes its store term from `$state['store_id']` and falls back to the
             * literal `no-store` when the state has none -- so the first draft of this fixture,
             * which carried only `slug`, made two different tenants share ONE key and the
             * cross-tenant case failed against a correct fix. The existing fixtures in
             * `GatewayReturnTrustBoundaryTest` carry both, and a real initiation does too. The
             * degraded shape is asserted separately below, because the store term only protects a
             * tenant when the state actually names it.
             */
            'store_id' => $store->id,
            'customer_id' => '',
            'payment_type' => 'bank_transfer',
            'shipping_final_price' => 0,
            'tax_price' => 0,
            'cartlist_final_price' => 100.00,
            'checkout_nonce' => (string) Str::uuid(),
            'billing_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
                'billing_user_telephone' => '123', 'billing_address' => 'x',
                'billing_postecode' => '1', 'billing_country' => '101',
                'billing_state' => '1', 'billing_city' => '1', 'billing_company_name' => '',
                'delivery_address' => 'x', 'delivery_postcode' => '1',
                'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
            ],
        ];
    }

    private function basketFor($store): string
    {
        $product = $this->productIn($store, 100.00, 50);
        $sid = 'bc-cookie-' . uniqid();

        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        return $sid;
    }

    private function orderCount(): int
    {
        return (int) DB::table('orders')->count();
    }

    /** One storefront return, on a brand-new checkout, carrying `$tapId`. */
    private function returnWithCharge($store, ?string $tapId)
    {
        $sid = $this->basketFor($store);
        session()->forget('error');

        $body = $tapId === null ? [] : ['tap_id' => $tapId];

        return $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $this->freshCheckout($store)])
            ->post('/' . $store->slug . '/get-payment-status', $body);
    }

    /*
    |--------------------------------------------------------------------------
    | PREMISES
    |--------------------------------------------------------------------------
    */

    /**
     * THE PREMISE THE WHOLE ROW RESTS ON: a second checkout is NOT caught by the nonce.
     *
     * Without this, "the charge claim refused it" would be indistinguishable from "the nonce
     * refused it", and the control under test would be untested.
     */
    public function test_premise_two_fresh_checkouts_both_write_their_order_without_a_charge_id(): void
    {
        [, $store] = $this->tenant();

        $before = $this->orderCount();

        foreach ([0, 1] as $round) {
            $this->returnWithCharge($store, null);

            $this->assertSame($before + $round + 1, $this->orderCount(),
                'a second brand-new checkout with no charge id did not write its order on round '
                . $round . ', so the nonce or something else is refusing and the charge claim '
                . 'below would be measuring that instead. Session error: '
                . json_encode(session('error')));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | THE DEFECT — one charge id, two orders
    |--------------------------------------------------------------------------
    */

    /**
     * THE REPRODUCTION. One charge id, two genuinely separate checkouts.
     *
     * Before the fix the second return wrote a second order, because nothing on this rail read the
     * charge id and the nonce was legitimately new. After it, the second return is refused by name.
     */
    public function test_one_charge_id_cannot_authorise_a_second_order(): void
    {
        [, $store] = $this->tenant();

        $charge = 'chg_' . strtoupper(uniqid('', true));
        $before = $this->orderCount();

        $this->returnWithCharge($store, $charge);

        $this->assertSame($before + 1, $this->orderCount(),
            'the FIRST return did not write its order, so the refusal below would not be a '
            . 'refusal. Session error: ' . json_encode(session('error')));

        $this->returnWithCharge($store, $charge);

        $this->assertSame($before + 1, $this->orderCount(),
            'GAP-285: ONE captured charge id wrote TWO orders. The second checkout is genuinely '
            . 'new, so its nonce cannot catch this — only a claim on the charge can.');
        $this->assertStringContainsString(self::REPLAY_REFUSAL, (string) session('error'),
            'the second order was stopped by something other than the replay claim, so the '
            . 'control under test is not what fired. Session error: '
            . json_encode(session('error')));
    }

    /** AND THE CLAIM IS SCOPED BY STORE, so one tenant's charge cannot deny another's order. */
    public function test_one_stores_charge_cannot_deny_another_stores_order(): void
    {
        [, $storeA] = $this->tenant();
        [, $storeB] = $this->tenant();

        $charge = 'chg_SHARED_' . strtoupper(uniqid('', true));
        $before = $this->orderCount();

        foreach ([$storeA, $storeB] as $index => $store) {
            $this->returnWithCharge($store, $charge);

            $this->assertSame($before + $index + 1, $this->orderCount(),
                'store ' . ($index + 1) . ' of two different tenants did not get its order. The '
                . 'two share only a charge id, so one tenant\'s payment is denying another\'s — '
                . 'the cache namespace is installation-wide, so the store term is what prevents '
                . 'it. Session error: ' . json_encode(session('error')));
        }
    }

    /**
     * A RETURN WITH NO CHARGE ID IS UNCHANGED — the documented fail-open, asserted.
     *
     * `gatewayTransactionReference()` states that a missing field yields NULL and therefore no
     * key, and "never produces a WRONG key". Twenty-odd rails reach this handler without a
     * `tap_id`; if an absent one minted a key they would all share it and the second order on the
     * whole platform would be refused.
     */
    public function test_a_return_with_no_charge_id_mints_no_claim(): void
    {
        [, $store] = $this->tenant();

        $this->returnWithCharge($store, null);

        $this->assertFalse(
            Cache::has('gateway-return-claim:payment:storefront-return:' . $store->id . ':' . sha1('')),
            'an ABSENT charge id minted a claim key. Every rail on this handler that carries no '
            . 'tap_id would then share one key and the platform would refuse its second order.'
        );
    }

    /** A blank or whitespace charge id is treated as absent, not as a key over an empty string. */
    public function test_a_blank_charge_id_is_treated_as_absent(): void
    {
        [, $store] = $this->tenant();

        $before = $this->orderCount();

        foreach (['', '   '] as $round => $blank) {
            $this->returnWithCharge($store, $blank);

            $this->assertSame($before + $round + 1, $this->orderCount(),
                'a blank charge id was keyed rather than ignored, so two unrelated returns that '
                . 'both send an empty tap_id deny each other. Session error: '
                . json_encode(session('error')));
        }
    }

    /**
     * A RESIDUAL, MEASURED RATHER THAN ASSUMED: the store term protects a tenant only when the
     * stored state NAMES the store.
     * =============================================================================================
     *
     * `gatewayReturnClaimKeys()` builds the payment key from `$state['store_id']` and falls back to
     * the literal `no-store` when the state carries none. Its own docblock says the store comes
     * from "the stored state, which the server wrote; it is never the caller's" — true, and it does
     * not say what happens when the server wrote no `store_id`. Measured here: two different
     * tenants presenting the same charge id with a state that omits `store_id` share ONE key, and
     * the second tenant's legitimate order is refused as a replay.
     *
     * This is not introduced by the `tap_id` entry — it is a property of the key for every rail
     * that supplies a reference — but the `tap_id` entry is what makes it reachable on the
     * storefront return, so it is recorded here with its figures rather than left for the next
     * reader. It is a DENIAL of a legitimate order, not an admission of an illegitimate one, which
     * is why it is reported rather than closed on this slice's authority: the available remedies
     * (refuse a state with no `store_id`, or resolve the store from the route slug) each change
     * what happens to a live return that this suite cannot prove is impossible.
     *
     * Asserted in the failing direction deliberately, so the day somebody fixes it this case says
     * so instead of staying silently green.
     */
    public function test_residual_a_state_with_no_store_id_shares_one_claim_across_tenants(): void
    {
        [, $storeA] = $this->tenant();
        [, $storeB] = $this->tenant();

        $charge = 'chg_NOSTORE_' . strtoupper(uniqid('', true));
        $before = $this->orderCount();

        foreach ([$storeA, $storeB] as $store) {
            $state = $this->freshCheckout($store);
            unset($state['store_id']);

            $sid = $this->basketFor($store);
            session()->forget('error');

            $this->withCookie('cart', $sid)
                ->withSession(['request_data' => $state])
                ->post('/' . $store->slug . '/get-payment-status', ['tap_id' => $charge]);
        }

        $this->assertSame($before + 1, $this->orderCount(),
            'the no-store fallback no longer shares one claim across tenants — this residual has '
            . 'been fixed, so INVERT this case to assert both orders are written and say which '
            . 'change did it.');
        $this->assertStringContainsString(self::REPLAY_REFUSAL, (string) session('error'),
            'the second tenant was refused by something other than the replay claim, so this case '
            . 'is not measuring the shared key. Session error: ' . json_encode(session('error')));
    }

    /*
    |--------------------------------------------------------------------------
    | THE AMOUNT HALF — MEASURED, PINNED, AND NOT FIXED
    |--------------------------------------------------------------------------
    */

    /**
     * THE AMOUNT COMPARISON THE ROW ASKS FOR CANNOT BE BUILT FROM THESE TWO PIECES AS THEY STAND.
     * =============================================================================================
     *
     * `GAP-285` also asks that the charge amount be compared against
     * `GuardsGatewayReturn::serverPayableAmount()`. The two halves of that comparison disagree
     * about scale, and the disagreement refuses rather than rounds:
     *
     *   - `serverPayableAmount()` ends in `round((float) $total, 2)` — a **2-decimal** figure.
     *   - `OrderTotalReconciler::amountsMatch()` compares exact integer minor units at
     *     `SCALE = 4`, and returns FALSE when either side is not exactly representable, because
     *     "an unrepresentable amount is not equal to anything".
     *
     * MEASURED, and the figures are asserted below so the escalation cannot go stale:
     *
     *     amountsMatch('10.123', '10.12')   = false   <- a real 3-decimal charge vs the server's
     *                                                    own rounded figure: REFUSED
     *     amountsMatch('10.123', '10.123')  = true    <- so the comparison itself is fine at 3dp
     *     round(10.123, 2)                  = 10.12   <- the loss is in serverPayableAmount()
     *     tryToMinorUnits('10.12345')       = null    <- beyond 4dp equals nothing at all
     *
     * SO ONE CLAUSE OF THE ROW IS REFINED BY MEASUREMENT: it is not `SCALE = 4` that refuses a
     * 3-decimal currency — `amountsMatch` handles 10.123 correctly. It is `serverPayableAmount()`
     * rounding the SERVER's figure to 2 decimals before the comparison ever happens.
     *
     * AND THIS IS A LIVE CURRENCY, NOT A HYPOTHETICAL. `processBenefit()` sends
     * `"currency" => !empty($CURRENCY_NAME) ? $CURRENCY_NAME : 'BHD'`, so BHD — three decimals —
     * is the DEFAULT for any store that has not set `CURRENCY_NAME`, and BHD, KWD, OMR and JOD
     * (all three-decimal) are in this platform's own supported list for the MyFatoorah rail.
     *
     * WHY IT IS NOT FIXED HERE. Any of the three available shapes decides something that is not
     * arithmetic: rounding the charge to 2dp accepts a charge that differs from the payable by up
     * to half a fils; comparing at the currency's own exponent requires a currency→exponent table
     * this repository does not have; and declaring 3-decimal currencies unsupported refuses live
     * checkouts. All three change what a shopper may be charged or which currencies work, which
     * CLAUDE.md §2 reserves to the owner. Measured, recommended, and left.
     */
    public function test_the_amount_half_refuses_a_three_decimal_currency_and_is_therefore_escalated(): void
    {
        $this->assertFalse(OrderTotalReconciler::amountsMatch('10.123', '10.12'),
            'a 3-decimal charge now matches the 2-decimal server figure, so the escalation on '
            . 'GAP-285\'s amount half has been resolved somewhere — re-read it before relying on '
            . 'this assertion.');

        $this->assertTrue(OrderTotalReconciler::amountsMatch('10.123', '10.123'),
            'amountsMatch no longer handles 3 decimals at all, which would make the refusal a '
            . 'property of SCALE rather than of the rounding — a different finding');

        $this->assertSame(10.12, round(10.123, 2),
            'the rounding in serverPayableAmount() is where the 3rd decimal is lost');

        $this->assertNull((new OrderTotalReconciler())->tryToMinorUnits('10.12345'),
            'an amount beyond SCALE is no longer unrepresentable, so "refuses rather than rounds" '
            . 'has changed');

        $this->assertSame(4, OrderTotalReconciler::SCALE,
            'SCALE moved; the figures in this escalation were measured at 4');
    }

    /**
     * AND THE SERVER'S OWN FIGURE REALLY IS ROUNDED TO TWO DECIMALS, through the real method.
     *
     * Asserted against `serverPayableAmount()` itself rather than against `round()`, so the
     * escalation names the code and not an arithmetic identity.
     */
    public function test_the_server_payable_amount_is_two_decimals(): void
    {
        $guard = new class {
            use \App\Http\Controllers\Concerns\GuardsGatewayReturn;

            public function payable(array $state): ?float
            {
                return $this->serverPayableAmount($state);
            }
        };

        $this->assertSame(10.12, $guard->payable(['cartlist_final_price' => '10.123']),
            'serverPayableAmount() no longer rounds to 2 decimals. If it now preserves the third, '
            . 'the amount comparison GAP-285 asks for may be buildable — re-measure the '
            . 'escalation.');

        $this->assertSame(10.13, $guard->payable(['cartlist_final_price' => '10.126']),
            'premise: it rounds rather than truncates, which is the direction its own docblock '
            . 'records as deliberate and in the merchant\'s favour');
    }
}
