<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Product;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * GAP-284 — ONE RAIL'S CHECKOUT MUST NOT BE CASHED IN BY ANOTHER RAIL'S RETURN HANDLER.
 * =================================================================================================
 *
 * WHAT THE ROW CLAIMS. The `GAP-138` Benefit capture check guards ONE route
 * (`{slug}/get-payment-status`). The session state it guards — `request_data` — is read by two
 * other anonymous `Route::any` siblings that write the order with no `payment_type` gate:
 * `PaiementProPaymentController::getProductStatus` (no gate at all; its own docblock says "The
 * order is still always written") and `CinetPayController::getProductStatus` (gated only on
 * `$request->responsecode == 0`). So a `benefit` checkout can be taken to a sibling and written
 * without the capture check ever running. That is what "falsifies unbypassable for the whole
 * gateway-return family" means.
 *
 * THE CONTROL THIS FILE PINS, and why it is the gateway and not the gate. `PaymentService::process()`
 * now stamps the rail the checkout was initiated for into the stored state, beside the one-time
 * nonce and by the same mechanism (a `merge()` before the switch, so all 46
 * `Session::put('request_data', …)` sites carry it for free and there is no census to keep in
 * step). `GuardsGatewayReturn::storedCheckoutState()` — which already took a `$gateway` argument
 * FOR THE LOG LINE ONLY — now refuses when the stamp names a different rail.
 *
 * THE COMPARISON IS CASE-INSENSITIVE, AND THAT IS MEASURED RATHER THAN DEFENSIVE. The switch in
 * `process()` keys on `$request->payment_type` with cases spelled `Paiementpro`, `CinetPay`,
 * `AuthorizeNet`, `NMI`, `khalti`, `easebuzz`; the six per-rail handlers pass `paiementpro`,
 * `cinetpay`, `authorizenet`, `nmi`, `khalti`, `easebuzz`. A case-SENSITIVE comparison would
 * therefore refuse every legitimate return on four of the six rails — a far worse defect than the
 * one being closed — so both spellings are asserted here, per rail, in both directions.
 *
 * AND AN ABSENT STAMP IS NOT A REFUSAL. A session minted before this change carries no stamp, and
 * refusing those would strand in-flight checkouts at deploy — a shopper already charged, with no
 * order. The gate fires only when the stamp is PRESENT and DISAGREES. Asserted, so the fail-open
 * direction cannot be tightened by accident.
 *
 * WHAT THIS FILE DELIBERATELY DOES NOT TOUCH, because an owner decision already settled it.
 * `CinetPayController:425`'s `$request->responsecode == 0` admits `null` (`null == 0` is TRUE in
 * PHP 8 — measured in `test_premise_*` below). The register prescribes changing it to `===`.
 * **That is the change this repository already tried on the sibling rail and reversed**, and
 * `PaiementProPaymentController:275-340` records why in full: `responsecode` is the caller's own
 * field so a strict test stops nothing a loose one admits (any caller reaching the URL can send
 * it), an independent pass found two ways to get a Paid order THROUGH a `=== '0'` gate, and owner
 * decision D2 plus the Phase 01 directive require that an unverifiable gateway write its order and
 * stay Pending rather than refuse a shopper who has already been charged. A literal `=== 0` would
 * also refuse the live return outright, because a query parameter arrives as the STRING `'0'` and
 * `'0' === 0` is FALSE — also measured below. So the gateway stamp closes the cross-rail path
 * without touching that gate, and the gate's own disposition stays where the owner put it.
 *
 * EVERY ASSERTION READS THE RAW ROW. `Product`, `Order` and `Cart` are `Cachable` and the suite
 * runs under `CACHE_STORE=array`.
 *
 * @see \App\Services\PaymentService::process()
 * @see \App\Http\Controllers\Concerns\GuardsGatewayReturn::storedCheckoutState()
 */
class CrossRailCheckoutAdoptionTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    /** The rail a handler names, and the `payment_type` spelling that reaches the same rail. */
    private const RAILS = [
        'paiementpro' => 'Paiementpro',
        'cinetpay' => 'CinetPay',
        'authorizenet' => 'AuthorizeNet',
        'nmi' => 'NMI',
        'khalti' => 'khalti',
        'easebuzz' => 'easebuzz',
    ];

    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'xr-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
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

        /* getProductStatus INSERTs a cities row from billing_info; without the FK parents it 500s
           before the order write, which would make every assertion below vacuous. */
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
            'name' => 'xr-' . uniqid(), 'slug' => 'xr-' . uniqid(),
            'product_stock' => $stock, 'track_stock' => 1, 'variant_product' => 0,
            'price' => $price, 'sale_price' => $price, 'status' => 1,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function guestBilling(): array
    {
        return [
            'firstname' => 'A', 'lastname' => 'B',
            'email' => 'guest-' . uniqid() . '@example.test',
            'billing_user_telephone' => '123', 'billing_address' => 'x',
            'billing_postecode' => '1', 'billing_country' => '101',
            'billing_state' => '1', 'billing_city' => '1', 'billing_company_name' => '',
            'delivery_address' => 'x', 'delivery_postcode' => '1',
            'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
        ];
    }

    /**
     * Drive the REAL initiation for `$paymentType` and return the state the server stored.
     *
     * Through `/process-order` rather than by hand-building an array: the whole control is that the
     * SERVER stamps the rail, and a hand-built state would assert the test's own fixture.
     *
     * @return array{0: string, 1: array}  the cart cookie, and the stored state
     */
    private function initiate($store, Product $product, string $paymentType): array
    {
        $sid = 'xr-cookie-' . uniqid();

        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        $this->withCookie('cart', $sid)->post('/' . $store->slug . '/process-order', [
            'payment_type' => $paymentType,
            'billing_info' => $this->guestBilling(),
        ]);

        /*
         * EASEBUZZ STORES UNDER ITS OWN KEY, and reading `request_data` for it was a real false
         * reading in the first draft of this file: the easebuzz round read the PREVIOUS round's
         * khalti state and the premise failed with 'easebuzz' vs 'khalti'. That isolation is also
         * a finding -- a `request_data` state cannot be adopted by the easebuzz handler at all,
         * because it does not look there.
         */
        $state = session($paymentType === 'easebuzz' ? 'easebuzz_request_data' : 'request_data');

        $this->assertIsArray($state,
            'initiation stored no checkout state for ' . $paymentType . ', so nothing below is '
            . 'measuring the stamp. Session error: ' . json_encode(session('error')));

        return [$sid, $state];
    }

    private function stockOf(Product $product): int
    {
        return (int) DB::table('products')->where('id', $product->id)->value('product_stock');
    }

    private function orderCount(): int
    {
        return (int) DB::table('orders')->count();
    }

    private function cartRows(string $sid): int
    {
        return (int) DB::table('carts')->where('cookie_session_id', $sid)->count();
    }

    /*
    |--------------------------------------------------------------------------
    | PREMISES — the two PHP facts the row rests on, and the stamp itself
    |--------------------------------------------------------------------------
    */

    /**
     * The loose-comparison claim, and the reason the prescribed `===` is NOT applied.
     *
     * Both halves matter. `null == 0` is why the CinetPay gate admits a parameterless request;
     * `'0' === 0` being FALSE is why replacing it with a strict test against integer 0 would refuse
     * the live return, since a query parameter arrives as a string.
     */
    public function test_premise_the_php_comparisons_this_row_rests_on(): void
    {
        $this->assertTrue(null == 0,
            'null == 0 is no longer true, so the CinetPay gate no longer admits a parameterless '
            . 'request and GAP-284 must be re-measured');

        $this->assertFalse('0' === 0,
            'a strict comparison against INTEGER 0 would now accept the string a query parameter '
            . 'actually arrives as — which is the whole reason the prescribed === is not applied '
            . 'here. Re-read PaiementProPaymentController:275-340 before changing this.');
    }

    /** PREMISE: the server really stamps the rail, for every rail a handler can name. */
    public function test_premise_initiation_stamps_the_rail_into_the_stored_state(): void
    {
        [, $store] = $this->tenant();

        foreach (self::RAILS as $handlerName => $paymentType) {
            $product = $this->productIn($store);
            [, $state] = $this->initiate($store, $product, $paymentType);

            $this->assertArrayHasKey(PaymentService::CHECKOUT_GATEWAY_KEY, $state,
                'PaymentService::process() stored a checkout state with NO rail stamp for '
                . $paymentType . '. The gate then falls open for every rail. Keys: '
                . implode(',', array_keys($state)));

            $this->assertSame($paymentType, $state[PaymentService::CHECKOUT_GATEWAY_KEY],
                'the stamp is not the rail the checkout was initiated for');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | THE DEFECT — a benefit checkout cashed in by the ungated paiementpro sibling
    |--------------------------------------------------------------------------
    */

    /**
     * THE ROW'S OWN EXAMPLE PATH DOES NOT EXIST, AND THIS IS THE CORRECTION.
     * =============================================================================================
     *
     * `GAP-284` says the session state is "written by the Benefit arm at `PaymentService.php:1851`"
     * and that the attack is: initiate with `payment_type=benefit`, then request
     * `{slug}/get-payment-paiementpro`, where "the Benefit state is read".
     *
     * MEASURED, TWO WAYS: `processBenefit()` contains **no `Session::` call of any kind** — so
     * there is no Benefit state to adopt, and the cited line is inside a method that stores
     * nothing. Benefit falls through `processPayment()`'s `else` branch, which only returns a
     * redirect to the Tap page (`PaymentController:3239-3243`), and nothing in `processOrder()` or
     * `processPayment()` stores `request_data` either. Driving a real `benefit` initiation in this
     * suite leaves `session('request_data')` NULL.
     *
     * SO THE MECHANISM IS RIGHT AND THE EXAMPLE IS WRONG — the same shape as `GAP-260`, where a
     * row's reachability was refuted while its mechanism held. The cross-rail property is real and
     * is reproduced below between two rails whose arms DO store state. What is NOT reproducible is
     * the specific claim that the `GAP-138` capture check is bypassable this way, because the rail
     * that check guards leaves nothing behind for a sibling to adopt.
     *
     * The source scan strips comments first: this file's own prose names
     * `Session::put('request_data')` repeatedly, and a census that reads comments can be satisfied
     * — or broken — by prose about the thing it measures.
     */
    public function test_the_benefit_arm_stores_no_checkout_state_so_the_rows_example_cannot_exist(): void
    {
        $source = $this->sourceWithoutComments('app/Services/PaymentService.php');

        $start = strpos($source, 'private function processBenefit(');
        $this->assertNotFalse($start, 'processBenefit() is gone; GAP-284 must be re-measured');

        $end = strpos($source, 'private function processCashFree(', $start);
        $this->assertNotFalse($end, 'the arm after processBenefit() is gone');

        $arm = substr($source, $start, $end - $start);

        $this->assertStringNotContainsString('Session::', $arm,
            'processBenefit() now touches the session. GAP-284 claimed it writes request_data at '
            . ':1851 and it did not; if that has changed, the row\'s example path may now be real '
            . 'and must be re-measured rather than left as a withdrawn clause.');

        /* And behaviourally: a real benefit initiation leaves no state for any sibling to adopt. */
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $sid = 'xr-cookie-' . uniqid();
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        $this->withCookie('cart', $sid)->post('/' . $store->slug . '/process-order', [
            'payment_type' => 'benefit',
            'billing_info' => $this->guestBilling(),
        ]);

        $this->assertNull(session('request_data'),
            'a benefit initiation now stores checkout state, so the GAP-284 example path may be '
            . 'reachable after all');
    }

    /**
     * THE REPRODUCTION, between two rails that really do store state, on the figures.
     *
     * Both directions, because "paiementpro cannot read cinetpay's" and "cinetpay cannot read
     * paiementpro's" are different code paths: the paiementpro handler has no gateway gate at all,
     * and the cinetpay handler is reached here by a PARAMETERLESS request, which its only gate
     * (`$request->responsecode == 0`) admits because `null == 0`.
     *
     * Before the stamp: the foreign state is adopted, the order is written, stock is decremented
     * and the basket emptied. After: refused with none of those moved.
     */
    public function test_a_return_handler_cannot_adopt_another_rails_checkout(): void
    {
        foreach ([
            ['Paiementpro', 'get-payment-cinetPay'],
            ['CinetPay', 'get-payment-paiementpro'],
        ] as [$initiatedAs, $foreignUri]) {
            [, $store] = $this->tenant();
            $product = $this->productIn($store, 100.00, 50);

            [$sid, $state] = $this->initiate($store, $product, $initiatedAs);

            $this->assertSame(50, $this->stockOf($product),
                'premise: initiation itself must not move stock, or the assertion below is not '
                . 'about the return');
            $this->assertSame(1, $this->cartRows($sid), 'premise: the basket survives initiation');

            $before = $this->orderCount();

            $this->withCookie('cart', $sid)
                ->withSession(['request_data' => $state])
                ->get('/' . $store->slug . '/' . $foreignUri);

            $this->assertSame($before, $this->orderCount(),
                'GAP-284: a ' . $initiatedAs . ' checkout was written by /' . $foreignUri
                . ' — one rail\'s return handler cashed in another rail\'s checkout.');
            $this->assertSame(50, $this->stockOf($product),
                'GAP-284: stock was decremented by a foreign rail\'s return handler.');
            $this->assertSame(1, $this->cartRows($sid),
                'GAP-284: the shopper\'s basket was emptied by a foreign rail\'s return handler.');
        }
    }

    /**
     * THE HEADLINE PROPERTY, ON THE RAIL THAT ACTUALLY CARRIES THE CAPTURE CHECK.
     * =============================================================================================
     *
     * `GAP-284` is about the `GAP-138` capture check being routed around. That check lives in
     * `PaymentController::getProductStatus` and fires on `$requests_data['payment_type'] == 'Tap'`
     * — the Tap/Benefit rail. `processBenefit()` stores no state (see the case above), but
     * `processTap()` DOES, at `PaymentService:4237`, before any outbound call — so the rail the
     * check guards can be driven here for real, and this is the case that shows the check can no
     * longer be sidestepped.
     *
     * Both siblings, because they fail differently: paiementpro has no gateway gate at all, and
     * cinetpay is reached by a parameterless request its own `== 0` gate admits.
     */
    public function test_a_tap_checkout_cannot_be_cashed_in_by_a_sibling_that_has_no_capture_check(): void
    {
        foreach (['get-payment-paiementpro', 'get-payment-cinetPay'] as $foreignUri) {
            [, $store] = $this->tenant();
            $product = $this->productIn($store, 100.00, 50);

            [$sid, $state] = $this->initiate($store, $product, 'Tap');

            $this->assertSame('Tap', $state[PaymentService::CHECKOUT_GATEWAY_KEY] ?? null,
                'premise: the Tap initiation must be stamped, or this case is not about the rail '
                . 'the capture check guards');

            $before = $this->orderCount();

            $this->withCookie('cart', $sid)
                ->withSession(['request_data' => $state])
                ->get('/' . $store->slug . '/' . $foreignUri);

            $this->assertSame($before, $this->orderCount(),
                'GAP-284: a Tap checkout was written by /' . $foreignUri . ', so the GAP-138 '
                . 'capture check was bypassed by changing the URL — which is what the row means by '
                . '"falsifies unbypassable for the whole gateway-return family".');
            $this->assertSame(50, $this->stockOf($product),
                'GAP-284: stock was decremented for a Tap checkout nobody captured.');
            $this->assertSame(1, $this->cartRows($sid),
                'GAP-284: the shopper\'s basket was emptied by a rail with no capture check.');
        }
    }

    /**
     * AND THE CLAIM IS NOT BURNT BY THE REFUSAL, which is the half that protects the shopper.
     *
     * `gatewayReturnClaimKeys()` keys the checkout claim on the nonce ALONE — no rail in the key.
     * So a sibling that claimed before refusing would deny the legitimate return of the very
     * checkout it could not write: the shopper pays, comes back, and is told it is a replay. The
     * gate is inside `storedCheckoutState()`, which every handler calls BEFORE
     * `claimGatewayReturn()`, so a cross-rail refusal costs no claim.
     */
    public function test_the_refusal_does_not_burn_the_checkouts_own_claim(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);

        [$sid, $state] = $this->initiate($store, $product, 'Paiementpro');

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state])
            ->get('/' . $store->slug . '/get-payment-cinetPay');

        $nonce = $state['checkout_nonce'] ?? null;

        $this->assertIsString($nonce, 'premise: the state carries a nonce to claim');
        $this->assertFalse(
            Cache::has('gateway-return-claim:checkout:' . sha1(trim($nonce))),
            'the cross-rail refusal BURNT the checkout\'s own claim, so the shopper\'s legitimate '
            . 'return would now be refused as a replay — a worse outcome than the defect.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | THE CONTROLS — without these, "nothing was written" is also what a broken route produces
    |--------------------------------------------------------------------------
    */

    /**
     * EVERY RAIL STILL WRITES ITS OWN RETURN, in BOTH spellings.
     *
     * This is the case that makes the gate safe to ship. The switch spells four of the six rails
     * differently from the name their handler passes (`Paiementpro`/`paiementpro`,
     * `CinetPay`/`cinetpay`, `AuthorizeNet`/`authorizenet`, `NMI`/`nmi`), so a case-sensitive
     * comparison would refuse every legitimate return on those four. Driven on the two rails whose
     * returns this suite can reach without a gateway credential; the stamp itself is asserted for
     * all six by the premise above.
     */
    public function test_a_rails_own_return_is_still_written_whatever_the_case_of_the_stamp(): void
    {
        [, $store] = $this->tenant();

        foreach (['Paiementpro' => 'get-payment-paiementpro', 'CinetPay' => 'get-payment-cinetPay'] as $paymentType => $uri) {
            $product = $this->productIn($store, 100.00, 50);
            [$sid, $state] = $this->initiate($store, $product, $paymentType);

            $before = $this->orderCount();

            $this->withCookie('cart', $sid)
                ->withSession(['request_data' => $state])
                ->get('/' . $store->slug . '/' . $uri);

            $this->assertSame($before + 1, $this->orderCount(),
                'THE CONTROL BROKE: a ' . $paymentType . ' checkout returning to its OWN handler '
                . 'no longer writes an order. The rail stamp is spelled "' . $paymentType . '" by '
                . 'the switch and "' . strtolower($paymentType) . '" by the handler, so a '
                . 'case-sensitive comparison refuses a live payment. This is the worse defect.');
        }
    }

    /**
     * AND A STATE WITH NO STAMP IS STILL ACCEPTED — the fail-open direction, asserted.
     *
     * A session minted before this change carries no stamp. Refusing those would strand every
     * in-flight checkout at deploy: the shopper has paid and there is no order. Pinned so the gate
     * cannot be tightened into that without a red test.
     */
    public function test_a_state_with_no_rail_stamp_is_still_accepted(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);

        [$sid, $state] = $this->initiate($store, $product, 'Paiementpro');

        unset($state[PaymentService::CHECKOUT_GATEWAY_KEY]);

        $before = $this->orderCount();

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state])
            ->get('/' . $store->slug . '/get-payment-paiementpro');

        $this->assertSame($before + 1, $this->orderCount(),
            'a checkout stored BEFORE this change is now refused. That strands every in-flight '
            . 'payment at deploy — the shopper is charged and no order exists. The gate must fire '
            . 'only when the stamp is PRESENT and disagrees.');
    }
}
