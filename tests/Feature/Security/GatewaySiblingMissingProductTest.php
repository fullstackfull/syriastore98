<?php

namespace Tests\Feature\Security;

use App\Khalti\Khalti;
use App\Models\Cart;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * D-22 / F121 ON THE THREE SIBLING GATEWAYS, AND ON THE GUEST ARM.
 *
 * `GatewayReturnMissingProductTest` drives Khalti's AUTHENTICATED arm, which is the arm that can be
 * paired with a real verification outcome. This file covers what that one cannot:
 *
 *   - the GUEST arm, which reads `Cart::cart_list_cookie()` rather than `ApiController::cart_list()`
 *     -- a different function with its own copy of the unguarded dereference;
 *   - PaiementPro, CinetPay and Easebuzz, the other three handlers D-18 names.
 *
 * THESE THREE DIFFER FROM KHALTI IN A WAY THAT MATTERS TO THIS FIX. None of them verifies anything:
 * `PaymentVerificationService` holds three provider verifiers and none of them is theirs, so every
 * return they receive is "before proof of collection" by construction. The guard therefore applies
 * to the whole path rather than to one branch of it, and the refusal carries no verification
 * outcome because there is none to carry.
 *
 * WHAT IS BEING FIXED IS THE 500, NOT THE LOST ORDER. Before: an unhandled `ErrorException` from the
 * cart read, no order, no decrement. After: a structured refusal, no order, no decrement. The
 * customer of a gateway that has already collected still has no order -- that half is the owner's
 * decision, recorded in the register -- but the failure is now a message and a `critical` log rather
 * than a stack trace.
 *
 * KHALTI IS ANSWERED BY A DOUBLE IN THIS FILE, AND WHICH ANSWER IT GIVES IS THE POINT.  (2026-11-18)
 *
 * Until then nothing here faked Khalti, so `App\Khalti\Khalti::verifyPayment()` opened a real curl
 * handle to khalti.com -- five times per run of this class -- and what the Khalti rows proved depended
 * on what came back:
 *
 *   - THE HOST ANSWERS (CI, and most days here): a made-up token is answered HTTP 400, the capture is
 *     REJECTED, and the handler refuses at verification -- ABOVE the cart read. The four
 *     `gatewayReturnRoutes` cases then passed for Khalti without reaching the basket guard they are
 *     about. Green, and about nothing.
 *   - THE HOST CANNOT BE REACHED: no HTTP status, the capture is UNVERIFIABLE (not rejected), the
 *     handler goes on to the basket guard -- and those four cases did test it, while
 *     `test_khalti_refuses_at_verification_...` FAILED, because it asserts the rejected branch and
 *     fakes nothing. MEASURED with every outbound connection refused: 1 failed, 26 passed, and five
 *     `CONNECT khalti.com:443` attempts. A required CI job that goes red when a third party is down.
 *
 * So `setUp()` binds a double that answers like an unreachable gateway -- the answer that takes the
 * guest arm to the basket guard -- and `assertReachedTheHandler()` makes "Khalti was asked, once" a
 * premise of every Khalti row. The one case about the REJECTED branch binds a rejection instead, and a
 * new case pins what the unreachable branch does on this arm. No test in this file opens a connection
 * to khalti.com any more.
 *
 * @see GatewayReturnMissingProductTest for the authenticated arm, the verified-capture half and the
 *      bite matrix.
 */
class GatewaySiblingMissingProductTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The Khalti double every case runs against; it counts the times it was asked. */
    private Khalti $khalti;

    protected function setUp(): void
    {
        parent::setUp();

        // No `status_code` at all is what `Khalti::verifyPayment()` returns when the request never completed.
        $this->khalti = $this->fakeKhalti(['data' => null]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function gatewayReturnRoutes(): array
    {
        return [
            'paiementpro' => ['get-payment-paiementpro'],
            'cinetpay' => ['get-payment-cinetPay'],
            'easebuzz' => ['get-payment-easebuzz'],
            'khalti (guest arm)' => ['order-khalti'],
        ];
    }

    /**
     * @dataProvider gatewayReturnRoutes
     */
    public function test_a_dangling_guest_basket_refuses_without_an_unhandled_exception(string $uri): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product);

        DB::table('products')->where('id', $product->id)->delete();

        $outcome = $this->drive($store, $uri, $sid);

        $this->assertReachedTheHandler($uri, $outcome);
        $this->assertNull(
            $outcome['exception'],
            $uri . ': a basket naming a deleted product must not produce an unhandled exception'
        );
        $this->assertSame(0, $outcome['orders'], $uri . ': and it must write no order');
        $this->assertRefusedByTheBasketGuard($uri, $outcome);
    }

    /**
     * @dataProvider gatewayReturnRoutes
     */
    public function test_a_dangling_variant_reference_refuses_without_an_unhandled_exception(string $uri): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product, 987654);

        $outcome = $this->drive($store, $uri, $sid);

        $this->assertReachedTheHandler($uri, $outcome);
        $this->assertNull(
            $outcome['exception'],
            $uri . ': a basket naming a variant row that does not exist must not throw'
        );
        $this->assertSame(
            50,
            (int) DB::table('products')->where('id', $product->id)->value('product_stock'),
            $uri . ': and it must take no stock'
        );
        $this->assertRefusedByTheBasketGuard($uri, $outcome);
    }

    /**
     * THE CONTROL, per route. A guard that refused every basket would satisfy both cases above on
     * every row. This asserts the ordinary guest basket still reaches the handler's own logic --
     * which for these gateways means a decrement, since `StockManagementSettingTest` already
     * establishes that cinetpay, easebuzz and khalti reach their stock loops from here.
     *
     * PaiementPro is asserted on the weaker property (no refusal of this kind) rather than on the
     * decrement: its own stock behaviour is pinned by `StockManagementSettingTest`, and duplicating
     * that here would couple this file to a second gateway's pricing path for nothing.
     *
     * @dataProvider gatewayReturnRoutes
     */
    public function test_an_intact_guest_basket_is_not_refused_by_the_new_guard(string $uri): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product);

        $outcome = $this->drive($store, $uri, $sid);

        $this->assertReachedTheHandler($uri, $outcome);
        $this->assertNull($outcome['exception'], $uri . ': an ordinary basket must not throw');
        $this->assertStringNotContainsString(
            'no longer available',
            (string) session('error'),
            $uri . ': the dangling-row guard must not fire for a basket with nothing missing'
        );
        // AND THE CONTROL MUST END IN AN ORDER. "The guard did not fire" is just as true of a return that was stopped ABOVE the guard,
        // which is how the Khalti row passed for as long as the live gateway rejected it (measured then: no order, stock untouched).
        // What an ordinary basket produces on all four routes is one order row -- Pending, since none of these returns is proof of
        // collection; on Khalti that is the reconciliation order of an unverifiable capture, and the shopper is still told it failed.
        $this->assertSame(1, $outcome['orders'], $uri . ': an ordinary guest basket did not become an order, so this return never got past the guard it is the control for.');
    }

    /**
     * THE GUARD MUST NOT SWEEP THE STORE WHEN THE GUEST HAS NO COOKIE. Found by the project's
     * reviewer, in the guard's own first cut: a null cookie passed to
     * `where('cookie_session_id', $guestCookie)` becomes `whereNull()`, and `cookie_session_id` is
     * nullable with no default -- so every REGISTERED customer's row in the store carries NULL
     * there. The predicate silently became "this store's customer baskets", and one deleted
     * catalogue product would have refused every cookie-less guest, after their capture had been
     * classified.
     *
     * Driven here with the trigger in place: another customer in the same store holds a basket
     * naming a deleted product, and the cookie-less guest must NOT be refused for it.
     *
     * @dataProvider gatewayReturnRoutes
     */
    public function test_a_cookieless_guest_is_not_refused_for_another_customers_dangling_basket(string $uri): void
    {
        [, $store] = $this->tenant();

        // The trigger: a different principal's basket in the same store, naming a deleted product.
        $doomed = $this->productIn($store);
        $other = \App\Models\Customer::forceCreate([
            'first_name' => 'Other', 'last_name' => 'Shopper',
            'email' => 'd22s-other-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
        Cart::forceCreate([
            'customer_id' => $other->id, 'product_id' => $doomed->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);
        DB::table('products')->where('id', $doomed->id)->delete();

        $this->assertNull(
            DB::table('carts')->where('customer_id', $other->id)->value('cookie_session_id'),
            'premise: a customer basket row really does carry NULL there, which is what made the '
            . 'null cookie match it'
        );

        $exception = null;
        $this->withoutExceptionHandling();

        try {
            // No cart cookie at all.
            $state = $this->checkoutState($store);

            $this->withSession([
                    'request_data' => $state,
                    'easebuzz_request_data' => $state,
                ])
                ->post('/' . $store->slug . '/' . $uri, []);
        } catch (\Throwable $e) {
            $exception = get_class($e) . ': ' . $e->getMessage();
        }

        $this->assertNull($exception, $uri . ': a cookie-less guest must not throw');
        $this->assertKhaltiWasAsked($uri);
        $this->assertStringNotContainsString(
            'no longer available',
            (string) session('error'),
            $uri . ': the guard matched another principal\'s basket through a null cookie'
        );
        $this->assertNotStoppedAtVerification($uri, (string) session('error'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unverifiedGatewayRoutes(): array
    {
        /*
         * KHALTI IS DELIBERATELY ABSENT, and its absence is asserted by its own case below rather
         * than left as a gap. `PaymentVerificationService` holds three provider verifiers;
         * PaiementPro, CinetPay and Easebuzz are none of them, so a return on those three is
         * unproven BY CONSTRUCTION and reaches the basket guard. Khalti's guest arm verifies
         * FIRST -- D-22 moved the verification above the cart read -- so with no gateway response
         * faked it is refused at verification and the basket guard is never reached.
         */
        return [
            'paiementpro' => ['get-payment-paiementpro'],
            'cinetpay' => ['get-payment-cinetPay'],
            'easebuzz' => ['get-payment-easebuzz'],
        ];
    }

    /**
     * D-22's SECOND HALF, ON THE GATEWAYS THAT VERIFY NOTHING.
     *
     * Those three gateways have no verifier at all, so the money may well have been taken before
     * the redirect. The honest capture state is `unknown` -- neither `confirmed` nor `rejected` --
     * and the merchant still needs to be told, because a capture nobody checks is worse than one
     * that is merely uncertain.
     *
     * @dataProvider unverifiedGatewayRoutes
     */
    public function test_a_capture_that_produced_no_order_reaches_the_merchant(string $uri): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product);

        DB::table('products')->where('id', $product->id)->delete();

        $outcome = $this->drive($store, $uri, $sid);
        $this->assertReachedTheHandler($uri, $outcome);

        $row = DB::table('store_notifications')
            ->where('store_id', $store->id)
            ->where('type', 'payment_needs_attention')
            ->first();

        $this->assertNotNull(
            $row,
            $uri . ': the return refused and left the merchant nothing to act on. On a gateway'
            . ' with no verifier the money may already be taken, which is exactly when a merchant'
            . ' needs to know.'
        );

        $data = json_decode((string) $row->data, true);

        $this->assertSame(
            'unknown',
            $data['capture_state'] ?? null,
            $uri . ': a gateway with no verifier must report its capture state as unknown, not'
            . ' claim one. (The first version of the mapping collapsed `unverifiable` into'
            . ' `rejected`, so an unreachable gateway would have told the merchant nothing while'
            . ' the card may well have been charged.)'
        );
        $this->assertSame($uri === 'get-payment-cinetPay' ? 'cinetpay' : ($uri === 'get-payment-easebuzz' ? 'easebuzz' : 'paiementpro'), $data['gateway'] ?? null);

        $this->assertSame('payment', $row->reference_type, $uri . ': this is not an order notification');
        $this->assertNull($row->reference_id, $uri . ': there is no order to reference');
        $this->assertSame(0, $outcome['orders'], $uri . ': and recording it is not writing the order');
    }

    /**
     * A VARYING TOTAL MUST NOT MULTIPLY THE BELL — the hardening the independent review asked
     * for, driven rather than reasoned.
     *
     * The review traced a reachable path to the `unknown` state: `getValidPaymentTypes()` is a
     * static list of ~50 gateway names rather than the store's enabled set, so any gateway name
     * passes on any store; `PaymentService::processCinetPay` puts the checkout state in the
     * session BEFORE its cURL call and whether or not the store has credentials; the return route
     * is `Route::any`, so it is reachable by GET with no gateway round trip. An amount-keyed
     * dedupe would therefore let a shopper vary the basket total and multiply both rows and FCM
     * pushes — a nuisance primitive, not a cross-tenant one, but one worth closing.
     *
     * So for `unknown` the key is the store and the gateway, and the amount is part of it only
     * for `confirmed`, which cannot be reached without a verifier positively answering VERIFIED.
     */
    public function test_a_varying_total_does_not_multiply_the_bell_on_an_unverified_gateway(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product);

        DB::table('products')->where('id', $product->id)->delete();

        /*
         * Three returns whose STORED TOTAL differs, which is the shape the dedupe has to survive.
         * The amount the refusal records is `serverPayableAmount()`, read from the session state's
         * `cartlist_final_price` -- so the state is seeded directly here rather than through
         * `drive()`, which hardcodes one total. (The first version of this case tried to vary it
         * through `carts.quantity`; that column is `qty`, and varying it would not have moved the
         * recorded amount anyway, since the amount comes from the stored state and not from the
         * basket.)
         */
        $this->withoutExceptionHandling();

        foreach ([100.00, 250.00, 999.00] as $total) {
            $state = $this->checkoutState($store);
            $state['cartlist_final_price'] = $total;

            try {
                $this->withCookie('cart', $sid)
                    ->withSession(['request_data' => $state, 'easebuzz_request_data' => $state])
                    ->post('/' . $store->slug . '/get-payment-cinetPay', []);
            } catch (\Throwable $e) {
                $this->fail('a varied-total return must not throw: ' . get_class($e) . ': ' . $e->getMessage());
            }
        }

        $amounts = DB::table('store_notifications')
            ->where('store_id', $store->id)
            ->where('type', 'payment_needs_attention')
            ->pluck('data')
            ->map(fn ($d) => json_decode((string) $d, true)['amount'] ?? null)
            ->all();

        $this->assertNotSame([], $amounts, 'the premise: at least one return reached the refusal');

        $this->assertSame(
            1,
            (int) DB::table('store_notifications')
                ->where('store_id', $store->id)
                ->where('type', 'payment_needs_attention')
                ->count(),
            'three unverified returns with three different totals must leave ONE row (recorded'
            . ' amounts: ' . implode(', ', array_map(fn ($a) => var_export($a, true), $amounts)) . ').'
            . " The merchant action for an unknown capture is \"check the gateway\", which is one"
            . ' action however many totals were tried, and every occurrence is still in the'
            . ' critical log.'
        );
    }

    /**
     * WHY KHALTI'S GUEST ARM IS NOT IN THE PROVIDER ABOVE — asserted, not assumed.
     *
     * D-22 moved the verification ABOVE the cart read, so on Khalti a REJECTED return is refused
     * before the basket guard is ever asked: the handler refuses at verification, writes no order,
     * and writes no `payment_needs_attention` row because the guard that writes it was never
     * reached. The rejection is bound here -- HTTP 400, which is what khalti.com answered to a
     * made-up token when it was asked by hand. This case used to fake nothing and take whatever the
     * live host said; with the host unreachable that is "unverifiable", the next case, and it failed.
     *
     * That is the hoist working, not a gap in this half — and it is pinned so that a later change
     * which moves the verification back below the cart read fails here rather than silently
     * restoring the crash D-22 removed. (`GatewayReturnMissingProductTest` drives the Khalti arm
     * WITH a faked response and asserts the notification is written there.)
     */
    public function test_khalti_refuses_at_verification_before_the_basket_guard_is_reached(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product);

        DB::table('products')->where('id', $product->id)->delete();

        $this->khalti = $this->fakeKhalti(['status_code' => 400, 'data' => ['detail' => 'Invalid token.']]);

        $outcome = $this->drive($store, 'order-khalti', $sid);

        $this->assertReachedTheHandler('order-khalti', $outcome);
        $this->assertNull($outcome['exception'], 'the refusal must still not be an exception');
        $this->assertSame(0, $outcome['orders'], 'and it must write no order');
        $this->assertStringContainsString(
            'Transaction has been failed',
            $outcome['error'],
            'the shopper must be told by the REJECTION branch. Any other message means the return was stopped somewhere else and this'
            . ' case is no longer about the rejected capture.'
        );
        $this->assertSame(
            0,
            (int) DB::table('store_notifications')
                ->where('store_id', $store->id)
                ->where('type', 'payment_needs_attention')
                ->count(),
            'khalti verifies BEFORE the cart read, so a rejected return never reaches the'
            . ' basket guard and there is no capture to record. If this starts writing a row, the'
            . ' verification has moved back below the cart read and the 500 D-22 removed is'
            . ' reachable again.'
        );
    }

    /**
     * AN UNREACHABLE GATEWAY IS NOT A REJECTION, ON THE GUEST ARM EITHER. `verifyKhaltiCapture()` says
     * so in capitals: no HTTP status says nothing about whether money moved, so the capture is
     * UNVERIFIABLE and the handler goes on. With the basket naming a deleted product it then meets the
     * basket guard: no exception, no order, and ONE `payment_needs_attention` row, because a card may
     * have been charged and the merchant has to be told.
     *
     * This is what the case above did by accident whenever khalti.com could not be reached -- and why
     * it failed then. `GatewayReturnMissingProductTest` pins the same branch on the authenticated arm.
     */
    public function test_khalti_unreachable_is_not_a_refusal_and_the_merchant_is_told(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product);

        DB::table('products')->where('id', $product->id)->delete();

        $outcome = $this->drive($store, 'order-khalti', $sid);      // the class default: the gateway could not be reached

        $this->assertReachedTheHandler('order-khalti', $outcome);
        $this->assertNull($outcome['exception'], 'an unreachable gateway and a dangling basket must not be an exception');
        $this->assertSame(0, $outcome['orders'], 'and no order is written for a basket that cannot be read');
        $this->assertStringNotContainsString(
            'Transaction has been failed',
            $outcome['error'],
            'an UNVERIFIABLE capture was answered with the rejection message: unreachable has been collapsed into rejected.'
        );

        $rows = DB::table('store_notifications')
            ->where('store_id', $store->id)
            ->where('type', 'payment_needs_attention')
            ->get();

        $this->assertCount(1, $rows, 'an unverifiable capture that produced no order must reach the merchant, once.');
        $this->assertSame(
            'unknown',
            json_decode((string) $rows[0]->data, true)['capture_state'] ?? null,
            'the gateway could not be reached, so the state of the money is unknown and the notice must say so.'
        );
    }

    /**
     * D-13 — **A FAILURE DURING THE ORDER WRITE GIVES THE STOCK BACK**, on all four handlers.
     *
     * `GatewayStockBeforeVerificationTest` drives this on Khalti, which is the only one with a
     * verifier. The other three have none, so they are the ones where the loop runs on no payment
     * evidence at all — which makes compensating a failed write matter more there, not less.
     *
     * The failure is injected at `Order::creating` rather than by editing a handler, so the
     * subject is the PROPERTY ("any failure during the order write is compensated") and not one
     * trigger. F89 is why that is the right subject: a charged card ended in a 500 on
     * `NMIPayController:1328`, and the next one will not be the same line.
     *
     * @dataProvider unverifiedGatewayRoutes
     */
    public function test_a_failed_order_write_gives_the_stock_back(string $uri): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product);

        $opening = (int) DB::table('products')->where('id', $product->id)->value('product_stock');
        $this->assertGreaterThan(0, $opening, 'the premise: the fixture product has stock to lose');

        \App\Models\Order::creating(static function (): void {
            throw new \RuntimeException('simulated failure during the order write');
        });

        $this->drive($store, $uri, $sid);

        $this->assertSame(
            0,
            (int) DB::table('orders')->where('store_id', $store->id)->count(),
            $uri . ': the injected failure must have stopped the order write'
        );
        $this->assertSame(
            $opening,
            (int) DB::table('products')->where('id', $product->id)->value('product_stock'),
            $uri . ': a failure during the order write must give back the stock the loop consumed.'
            . ' Without it the shopper has no order, the merchant has no sale, and the inventory is'
            . ' gone — a drain reachable with no forged payment at all.'
        );
    }

    /**
     * D-13 — **AND A FAILURE AFTER THE ORDER IS WRITTEN KEEPS THE STOCK CONSUMED**, on all three
     * gateways with no verifier.
     *
     * The companion case above proves the compensation fires. This proves it is GATED, which is
     * the defect the independent review found in the first version of the fix: with no transaction
     * and a try around the whole body, a throw after `$order->save()` returned the basket's stock
     * while the order row and its children stayed written — the shopper's goods back on the shelf
     * while their order stands.
     *
     * @dataProvider unverifiedGatewayRoutes
     */
    public function test_a_failure_after_the_order_is_written_keeps_the_stock_consumed(string $uri): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product);

        $opening = (int) DB::table('products')->where('id', $product->id)->value('product_stock');

        \App\Models\OrderBillingDetail::creating(static function (): void {
            throw new \RuntimeException('simulated failure AFTER the order row was written');
        });

        $this->drive($store, $uri, $sid);

        $this->assertSame(
            1,
            (int) DB::table('orders')->where('store_id', $store->id)->count(),
            $uri . ': the premise — the order row committed before the injected failure'
        );
        $this->assertLessThan(
            $opening,
            (int) DB::table('products')->where('id', $product->id)->value('product_stock'),
            $uri . ': an order that EXISTS claims its goods. Giving the stock back here is the'
            . ' inverse of the defect this slice closes.'
        );
    }

    // ---------------------------------------------------------------- fixture

    private function drive($store, string $uri, string $sid): array
    {
        $exception = null;
        $this->withoutExceptionHandling();

        try {
            $state = $this->checkoutState($store);

            $this->withCookie('cart', $sid)
                ->withSession([
                    'request_data' => $state,
                    // Easebuzz reads its OWN session key and does NOT fall back to `request_data`
                    // (storedCheckoutState's third argument), so seeding only the shared one made
                    // its rows refuse at the entry guard and pass for the wrong reason. Both keys
                    // are seeded and the premise below makes a repeat of that impossible.
                    'easebuzz_request_data' => $state,
                ])
                ->post('/' . $store->slug . '/' . $uri, []);
        } catch (\Throwable $e) {
            $exception = get_class($e) . ': ' . $e->getMessage();
        }

        return [
            'exception' => $exception,
            'orders' => (int) DB::table('orders')->where('store_id', $store->id)->count(),
            'error' => (string) session('error'),
        ];
    }

    /**
     * THE PREMISE EVERY ROW IN THIS FILE DEPENDS ON, asserted rather than assumed.
     *
     * The first version of this file seeded only `request_data`. Easebuzz reads
     * `easebuzz_request_data` with no fallback, so its rows never got past the entry guard and
     * passed because the handler refused for an unrelated reason -- a vacuous green, which is the
     * failure mode CLAUDE.md names first. This asserts the handler did NOT stop at that guard.
     */
    private function assertReachedTheHandler(string $uri, array $outcome): void
    {
        $this->assertStringNotContainsString(
            'could not confirm your checkout details',
            $outcome['error'],
            $uri . ': the handler refused at the stored-state entry guard, so this row proves '
            . 'nothing about the cart read'
        );

        $this->assertKhaltiWasAsked($uri);
    }

    /**
     * THE REFUSAL IS THE BASKET GUARD'S, on every route. "No exception and no order" is also what a
     * return looks like when it was stopped anywhere above the cart read -- which is how the Khalti
     * rows passed for as long as the live gateway rejected them. The guard has one message of its
     * own, so the two dangling-basket cases now ask for it.
     */
    private function assertRefusedByTheBasketGuard(string $uri, array $outcome): void
    {
        $this->assertStringContainsString(
            'no longer available',
            $outcome['error'],
            $uri . ': the return was refused, but not by the dangling-row guard -- so the cart read this case is about was never reached.'
        );
    }

    /**
     * AND THE COOKIE-LESS CASE MUST HAVE REACHED THE GUARD TOO. The guard's message is absent from
     * every return that was stopped above it, so its absence proves nothing by itself: with the
     * live gateway rejecting, the Khalti row of that case was refused at verification and passed.
     * A guest with no basket ends with no message at all on every route (measured), and a rejected
     * capture ends with the rejection's -- so that message must be absent as well.
     *
     * NOT USED BY THE INTACT-BASKET CONTROL, and the reason is a fact about the product: an
     * UNVERIFIABLE Khalti capture writes its Pending order and then tells the shopper the same
     * "Transaction has been failed" a rejection does (P1-PAY-03). The message cannot tell the two
     * apart there; the order row can, and that case asks for it.
     */
    private function assertNotStoppedAtVerification(string $uri, string $error): void
    {
        $this->assertStringNotContainsString(
            'Transaction has been failed',
            $error,
            $uri . ': the return was refused as a REJECTED capture, above the cart read -- so "the guard did not fire" was never put to the test.'
        );
    }

    /**
     * THE SECOND PREMISE, for the Khalti rows only: the handler got as far as asking the gateway, and
     * it asked THIS double. Zero calls means the return was stopped above the verification, or that
     * the double is no longer what the container hands the controller -- in which case the request
     * went to khalti.com again and the row means whatever the network made it mean.
     */
    private function assertKhaltiWasAsked(string $uri): void
    {
        if ($uri === 'order-khalti') {
            $this->assertSame(1, $this->khalti->calls, $uri . ': premise -- the Khalti double must have been asked exactly once.');
        }
    }

    private function fakeKhalti(array $response): Khalti
    {
        $double = new class($response) extends Khalti {
            public int $calls = 0;

            public function __construct(private array $canned)
            {
            }

            public function verifyPayment($secret, $token, $amount)
            {
                $this->calls++;

                return $this->canned;
            }
        };

        $this->app->instance(Khalti::class, $double);

        return $double;
    }

    private function guestCart($store, Product $product, int $variantId = 0): string
    {
        $sid = 'd22-cookie-' . uniqid();

        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => $variantId,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        return $sid;
    }

    private function productIn($store): Product
    {
        return Product::forceCreate([
            'name' => 'd22s-' . uniqid(), 'slug' => 'd22s-' . uniqid(),
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 100.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function checkoutState($store): array
    {
        return [
            'slug' => $store->slug,
            'store_id' => $store->id,
            'customer_id' => '',
            'payment_type' => 'paiementpro',
            'shipping_final_price' => 0,
            'tax_price' => 0,
            'cartlist_final_price' => 100.00,
            'coupon_info' => null,
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

    /** @return array{0: \App\Models\User, 1: \App\Models\Store} */
    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'd22s-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        foreach ([
            'stock_management' => 'on',
            'out_of_stock_threshold' => '0',
            'notification' => '[]',
        ] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach ([
            ['countries', ['id' => 101, 'name' => 'Syria']],
            ['states', ['id' => 1, 'name' => 'Damascus', 'country_id' => 101]],
            ['cities', ['id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 1]],
        ] as [$table, $row]) {
            DB::table($table)->insertOrIgnore($row + ['created_at' => now(), 'updated_at' => now()]);
        }

        return [$merchant, $store];
    }
}
