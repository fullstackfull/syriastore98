<?php

namespace Tests\Feature\Commerce;

use App\Khalti\Khalti;
use App\Models\Customer;
use App\Models\Product;
use App\Services\PaymentVerificationResult;
use App\Services\PaymentVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P1-PAY-03 -- Khalti verified the SHOPPER'S number, and recorded nothing.
 *
 * TWO DEFECTS IN ONE CALL, and they pull in opposite directions, which is why one commit closes
 * both.
 *
 * THE FIRST IS A PAYMENT-INTEGRITY DEFECT. `KhaltiPaymnetController` read
 *
 *     $amount = $payload['amount'];
 *     $response = $khalti->verifyPayment($secret, $token, $amount);
 *
 * and `$payload` is `$request->payload` -- the caller's own body. Khalti's `payment/verify/`
 * endpoint confirms the token AND the amount as a PAIR, so handing it the caller's figure asks
 * the gateway the caller's question. A buyer who paid one unit presents that token with a
 * matching one-unit amount, Khalti answers 200 truthfully, and this handler then writes the order
 * at the SERVER's full basket price. The verification was real the whole time; it was a
 * verification of the wrong number. The same line, with the same shape, sat on the platform's own
 * plan-purchase path -- and there it also ran ABOVE the coupon block, so it asked about the list
 * price rather than the price actually being charged.
 *
 * THE SECOND IS A COMMERCIAL REGRESSION THIS PROJECT INTRODUCED. Khalti called a real gateway and
 * then recorded no verification, so once `resolveOrderPaymentStatus()` stopped defaulting absent
 * records to Paid, every Khalti order -- including genuinely captured ones -- was written Pending.
 * Nobody chose that; it fell out of the default inversion.
 *
 * WHAT MAKES THESE DRIVABLE AT ALL. `App\Khalti\Khalti::verifyPayment()` opens a curl handle to
 * khalti.com, and the controller used to construct it with `new`. GatewayReturnTrustBoundaryTest
 * records the consequence in its own docblock: "khalti stops at verifyPayment()". The controller
 * now resolves it from the container, so the double below drives the handler past that line
 * WITHOUT any outbound request -- and records the arguments, which is how the amount binding
 * itself is asserted rather than read.
 *
 * WHAT THESE TESTS DO NOT CLAIM. They do not establish that Khalti's live verify response carries
 * an `amount` field. This repository holds no contract, fixture or recorded response for that
 * endpoint -- the same position verifyPaymeraCapture() is in with its field name -- so the
 * returned-amount comparison is the SECONDARY control and is opportunistic by construction. The
 * primary control is that the request itself carries the server's amount, which these tests do
 * assert directly.
 */
class KhaltiAmountBindingTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The amount, in whole currency units, that the server prices this basket at. */
    private const SERVER_TOTAL = 100.00;

    /** The same figure in paisa -- what the Khalti widget was initiated with. */
    private const SERVER_PAISA = 10000;

    // ------------------------------------------------------------------ the seam, unit level

    public function test_a_matching_amount_is_verified(): void
    {
        $result = (new PaymentVerificationService())->verifyKhaltiCapture(
            ['status_code' => 200, 'data' => ['amount' => self::SERVER_PAISA]],
            self::SERVER_PAISA
        );

        $this->assertTrue($result->isVerified(), $result->reason);
        $this->assertSame('100.00', $result->capturedAmount);
        $this->assertSame('100.00', $result->expectedAmount);
    }

    /**
     * THE FORGERY, at the seam. Khalti answers 200 but for a smaller payment than this order is
     * for. Rejected, not merely "not verified": the gateway was reached and the answer does not
     * cover this basket.
     */
    public function test_a_smaller_reported_amount_is_rejected(): void
    {
        $result = (new PaymentVerificationService())->verifyKhaltiCapture(
            ['status_code' => 200, 'data' => ['amount' => 100]],
            self::SERVER_PAISA
        );

        $this->assertFalse($result->isVerified());
        $this->assertSame(PaymentVerificationResult::REJECTED, $result->outcome);
        $this->assertSame('1.00', $result->capturedAmount);
    }

    /**
     * THE OPPORTUNISTIC HALF, asserted so that it is a recorded decision rather than an
     * oversight. With no `amount` in the response the 200 is accepted, because the REQUEST
     * carried the server's amount and Khalti verifies the pair. Failing closed here would make
     * every Khalti order Pending the day the response shape differed from a guess -- a commercial
     * regression justified by nothing measured.
     */
    public function test_a_response_with_no_amount_field_is_still_verified(): void
    {
        $result = (new PaymentVerificationService())->verifyKhaltiCapture(
            ['status_code' => 200, 'data' => ['idx' => 'abc']],
            self::SERVER_PAISA
        );

        $this->assertTrue($result->isVerified(), $result->reason);
        $this->assertNull($result->capturedAmount);
    }

    public function test_an_unreachable_gateway_is_unverifiable_not_rejected(): void
    {
        $result = (new PaymentVerificationService())->verifyKhaltiCapture(
            ['status_code' => 0, 'data' => null],
            self::SERVER_PAISA
        );

        $this->assertSame(PaymentVerificationResult::UNVERIFIABLE, $result->outcome);
        $this->assertFalse($result->isVerified());
    }

    public function test_a_gateway_refusal_is_rejected(): void
    {
        $result = (new PaymentVerificationService())->verifyKhaltiCapture(
            ['status_code' => 400, 'data' => ['detail' => 'no']],
            self::SERVER_PAISA
        );

        $this->assertSame(PaymentVerificationResult::REJECTED, $result->outcome);
    }

    public function test_an_unparseable_amount_is_unverifiable(): void
    {
        $result = (new PaymentVerificationService())->verifyKhaltiCapture(
            ['status_code' => 200, 'data' => ['amount' => 'lots']],
            self::SERVER_PAISA
        );

        $this->assertSame(PaymentVerificationResult::UNVERIFIABLE, $result->outcome);
    }

    public function test_no_server_price_is_unverifiable(): void
    {
        $result = (new PaymentVerificationService())->verifyKhaltiCapture(
            ['status_code' => 200, 'data' => ['amount' => 0]],
            0
        );

        $this->assertSame(PaymentVerificationResult::UNVERIFIABLE, $result->outcome);
    }

    // ------------------------------------------------------------- the storefront, driven

    /**
     * THE DEFECT ITSELF, on the real route.
     *
     * The caller posts `payload[amount] = 100` -- one unit -- for a basket this server prices at
     * 100 units. The double records what the handler actually asked Khalti about. Before the fix
     * that was 100 (the caller's); it is now 10000 (the server's, in paisa), which is the figure
     * resources/views/payment/khalti.blade.php:16 initiates the widget with.
     */
    public function test_the_amount_sent_to_khalti_is_the_servers_not_the_shoppers(): void
    {
        [$merchant, $store] = $this->tenant();
        $customer = $this->customerIn($store);
        $spy = $this->fakeKhalti(['status_code' => 200, 'data' => ['amount' => self::SERVER_PAISA]]);

        $response = $this->actingAs($customer, 'customers')
            ->withSession(['request_data' => $this->checkoutState($store, $customer->id)])
            ->post('/' . $store->slug . '/order-khalti', [
                'payload' => ['token' => 'tok-' . uniqid(), 'amount' => 100],
            ]);

        $this->assertNotSame(404, $response->getStatusCode(), 'the route must exist for this to mean anything');
        $this->assertSame(1, $spy->calls, 'the handler never reached Khalti: ' . $response->getContent());
        $this->assertNotSame(
            100,
            $spy->amount,
            'the handler asked Khalti about the shopper\'s own figure'
        );
        $this->assertSame(self::SERVER_PAISA, $spy->amount);
    }

    /**
     * THE COMMERCIAL HALF. A confirmed capture is Paid again.
     *
     * This is also the LIVENESS CONTROL for every negative below: without it, "no Paid order" is
     * satisfied by a handler that refuses everything, which is exactly how a vacuous suite looks.
     */
    public function test_a_confirmed_capture_writes_a_paid_order(): void
    {
        [$merchant, $store] = $this->tenant();
        $customer = $this->customerIn($store);
        $this->fakeKhalti(['status_code' => 200, 'data' => ['amount' => self::SERVER_PAISA]]);

        $this->actingAs($customer, 'customers')
            ->withSession(['request_data' => $this->checkoutState($store, $customer->id)])
            ->post('/' . $store->slug . '/order-khalti', [
                'payload' => ['token' => 'tok-' . uniqid(), 'amount' => self::SERVER_PAISA],
            ]);

        $order = DB::table('orders')->where('store_id', $store->id)->first();

        $this->assertNotNull($order, 'a confirmed Khalti capture must still write an order');
        $this->assertSame('Paid', $order->payment_status);
    }

    /**
     * A gateway that answers "not for this amount" writes nothing. Khalti was reached and said
     * no, so there is nothing to reconcile and no order to write.
     */
    public function test_a_rejected_capture_writes_no_order(): void
    {
        [$merchant, $store] = $this->tenant();
        $customer = $this->customerIn($store);
        $this->fakeKhalti(['status_code' => 200, 'data' => ['amount' => 100]]);

        $this->actingAs($customer, 'customers')
            ->withSession(['request_data' => $this->checkoutState($store, $customer->id)])
            ->post('/' . $store->slug . '/order-khalti', [
                'payload' => ['token' => 'tok-' . uniqid(), 'amount' => 100],
            ]);

        $this->assertSame(0, DB::table('orders')->where('store_id', $store->id)->count());
    }

    /**
     * THE RECONCILIATION BRANCH. The verify call never completed, so this server cannot say
     * whether money moved. The old code returned "Transaction has been failed" and wrote nothing
     * -- leaving a possibly-charged customer with no record at all. The order is now written,
     * Pending, and the shopper is still told it failed.
     */
    public function test_an_unreachable_gateway_writes_a_pending_order_for_reconciliation(): void
    {
        [$merchant, $store] = $this->tenant();
        $customer = $this->customerIn($store);
        $this->fakeKhalti(['status_code' => 0, 'data' => null]);

        $response = $this->actingAs($customer, 'customers')
            ->withSession(['request_data' => $this->checkoutState($store, $customer->id)])
            ->post('/' . $store->slug . '/order-khalti', [
                'payload' => ['token' => 'tok-' . uniqid(), 'amount' => self::SERVER_PAISA],
            ]);

        $order = DB::table('orders')->where('store_id', $store->id)->first();

        $this->assertNotNull($order, 'an unreachable gateway must still leave a reconcilable row');
        $this->assertSame('Pending', $order->payment_status);
        $this->assertSame(
            __('Transaction has been failed'),
            session('error'),
            'the shopper must not be told a payment succeeded that this server could not confirm'
        );
    }

    /**
     * F2 — A VERIFICATION OF ONE BASKET MUST NOT PAY FOR ANOTHER.
     *
     * The basket is mutable across the round trip: the Khalti token lives in the browser, so a
     * shopper can pay for a small basket, withhold the automatic return, add an expensive line,
     * and then post the return themselves. The verification is GENUINE — Khalti really did
     * confirm that token for that amount — and it covers an order that no longer exists.
     *
     * Enrolling khalti in ENFORCED_PAYMENT_TYPES is what closes it: the service compares the
     * recorded `expected_amount` against the total the order is actually written for. Measured
     * first, because enrolling a gateway whose two figures legitimately differ would make every
     * Khalti order Pending — on the plain path they agree, which the positive control above pins.
     */
    public function test_a_capture_verified_for_a_smaller_basket_does_not_pay_a_larger_order(): void
    {
        [$merchant, $store] = $this->tenant();
        $customer = $this->customerIn($store);
        $state = $this->checkoutState($store, $customer->id);

        // The shopper pays for what the server priced at initiation...
        $this->fakeKhalti(['status_code' => 200, 'data' => ['amount' => self::SERVER_PAISA]]);

        // ...and then adds a second line before posting the return by hand.
        $extra = $this->productIn($store);
        DB::table('carts')->insert([
            'product_id' => $extra->id, 'variant_id' => 0, 'qty' => 1,
            'customer_id' => $customer->id, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($customer, 'customers')
            ->withSession(['request_data' => $state])
            ->post('/' . $store->slug . '/order-khalti', [
                'payload' => ['token' => 'tok-' . uniqid(), 'amount' => self::SERVER_PAISA],
            ]);

        $order = DB::table('orders')->where('store_id', $store->id)->first();

        $this->assertNotNull($order, 'the order is still written — this is a status question, not a refusal');
        $this->assertGreaterThan(
            self::SERVER_TOTAL,
            (float) $order->final_price,
            'premise: the handler must have priced the LARGER basket, or this test proves nothing'
        );
        $this->assertSame(
            'Pending',
            $order->payment_status,
            'a capture verified for the smaller basket paid for the larger order'
        );
    }

    /**
     * F1 — A VERIFICATION RECORD LEFT BEHIND BY ONE REQUEST MUST NOT PAY FOR ANOTHER ORDER.
     *
     * The AuthorizeNet record used to be written ~600 lines above its consumer, with six
     * reachable refusals in between — 'Cart is empty.', 'User not found.', the quantity refusal
     * and others — each returning a plain 200 that saves the session. The record is bound to
     * nothing but a lowercase type string, and `PaymentController::getProductStatus` on the
     * SHARED `{slug}/get-payment-status` route consumes whatever matches. So a real capture of a
     * small basket, aborted at one of those refusals, could pay for an expensive order placed
     * afterwards.
     *
     * This seeds the session directly rather than faking the Authorize.Net SDK: the plant is the
     * subject, and how it got there does not change what the consumer does with it. Against the
     * first cut of this change the assertion below read 'Paid'.
     */
    public function test_a_planted_authorizenet_record_cannot_pay_for_a_later_order(): void
    {
        [$merchant, $store] = $this->tenant();
        $customer = $this->customerIn($store);
        $state = $this->checkoutState($store, $customer->id);
        $state['payment_type'] = 'AuthorizeNet';

        // The plant: what an aborted capture of a ONE-unit basket leaves behind. It names the
        // checkout it came from, which is the whole point -- that checkout is not this one.
        $planted = [
            'payment_type' => 'authorizenet',
            'reason' => 'planted by an aborted capture',
            'captured_amount' => '1.00',
            'expected_amount' => '1.00',
            'verified_at' => now()->toIso8601String(),
            'checkout_nonce' => 'nonce-of-the-aborted-checkout',
        ];

        $response = $this->actingAs($customer, 'customers')
            ->withSession([
                'request_data' => $state,
                PaymentVerificationService::SESSION_KEY => $planted,
            ])
            ->post('/' . $store->slug . '/get-payment-status', []);

        $this->assertNotSame(404, $response->getStatusCode(), 'the shared return route must exist');

        $order = DB::table('orders')->where('store_id', $store->id)->first();

        $this->assertNotNull(
            $order,
            'premise: the shared writer must have reached an order write, or this proves nothing '
                . 'about redemption'
        );
        $this->assertNotSame(
            'Paid',
            $order->payment_status,
            'a verification planted by an aborted capture paid for a later, unrelated order'
        );
    }

    /**
     * THE LIVENESS CONTROL FOR THE TEST ABOVE.
     *
     * Without it, "not Paid" is satisfied by a binding that refuses everything, which would be a
     * silent outage dressed as a security fix. A record naming THIS checkout still pays.
     */
    public function test_a_record_naming_this_checkout_still_pays(): void
    {
        [$merchant, $store] = $this->tenant();
        $customer = $this->customerIn($store);
        $state = $this->checkoutState($store, $customer->id);
        $state['payment_type'] = 'AuthorizeNet';
        // The LITERAL, not the constant: PHP refuses `Trait::CONSTANT` directly ("Cannot access
        // trait constant ... directly"), and reaching it through a using class would tie this
        // fixture to whichever controller happened to be named. test_the_checkout_nonce_key_is_still_spelled_this_way
        // keeps the literal and the constant together.
        $state['checkout_nonce'] = 'nonce-of-this-checkout';

        $record = [
            'payment_type' => 'authorizenet',
            'reason' => 'this checkout',
            'captured_amount' => '100.00',
            'expected_amount' => '100.00',
            'verified_at' => now()->toIso8601String(),
            'checkout_nonce' => 'nonce-of-this-checkout',
        ];

        $this->actingAs($customer, 'customers')
            ->withSession([
                'request_data' => $state,
                PaymentVerificationService::SESSION_KEY => $record,
            ])
            ->post('/' . $store->slug . '/get-payment-status', []);

        $order = DB::table('orders')->where('store_id', $store->id)->first();

        if ($order === null) {
            $this->markTestIncomplete('the shared writer did not reach an order write with this fixture');
        }

        $this->assertSame(
            'Paid',
            $order->payment_status,
            'the checkout binding refuses a record that names THIS checkout -- that is an outage, '
                . 'not a control'
        );
    }

    /**
     * The position itself, as a ratchet.
     *
     * The behavioural test above can be defeated by a fixture that never reaches the writer, and
     * a source grep for `recordVerification('authorizenet'` passes wherever the call sits. This
     * asserts the one thing that actually closes F1: the record is taken AFTER the handler's last
     * refusal, which means after `basketHasForeignProduct` — the latest of them — and immediately
     * before the status derivation that consumes it.
     */
    public function test_the_authorizenet_record_sits_next_to_its_consumer(): void
    {
        $code = $this->codeOf(app_path('Http/Controllers/AuthorizeNetPaymnetController.php'));

        $record = strpos($code, "recordVerification(\n                        'authorizenet'");

        if ($record === false) {
            $record = strpos($code, "recordVerification(");
        }

        $consume = strpos($code, 'gatewayReturnPaymentStatus(');
        $lastRefusal = strrpos($code, 'gatewayReturnForeignProductRefusal(');

        $this->assertNotFalse($record, 'the AuthorizeNet capture is no longer recorded at all');
        $this->assertNotFalse($consume, 'premise: the status derivation is gone');
        $this->assertNotFalse($lastRefusal, 'premise: the foreign-product refusal is gone');

        $this->assertGreaterThan(
            $lastRefusal,
            $record,
            'the verification is recorded BEFORE a refusal that returns without clearing it — '
                . 'that window is the plant'
        );
        $this->assertLessThan(
            $consume,
            $record,
            'premise: the record must still be taken before the status derivation that reads it'
        );
    }

    // -------------------------------------------------------------------------- the ratchet

    /**
     * NO CALLER-SUPPLIED AMOUNT REACHES verifyPayment() AGAIN.
     *
     * A behaviour test cannot see a THIRD Khalti call site added later with the original line, so
     * this is a source scan. Comments are stripped first with token_get_all(), because the
     * docblocks above both call sites QUOTE the removed line -- CLAUDE.md's prose trap, which has
     * already made one ratchet in this repository fail on its own explanation.
     */
    public function test_no_khalti_call_site_passes_a_caller_supplied_amount(): void
    {
        $code = $this->codeOf(app_path('Http/Controllers/KhaltiPaymnetController.php'));

        $this->assertStringNotContainsString(
            "\$payload['amount']",
            $code,
            'the caller-supplied amount is back in KhaltiPaymnetController'
        );

        preg_match_all('/verifyPayment\s*\(([^;]*?)\)\s*;/s', $code, $calls);

        $this->assertNotEmpty($calls[1], 'the scan found no verifyPayment() call -- the premise is gone');

        foreach ($calls[1] as $args) {
            $this->assertStringContainsString(
                '$expectedPaisa',
                $args,
                'a verifyPayment() call does not pass the server-computed amount: ' . trim($args)
            );
        }
    }

    /** The ratchet above can actually detect the defect it is written against. */
    public function test_the_caller_supplied_amount_detector_can_detect_it(): void
    {
        $injected = "<?php \$amount = \$payload['amount']; \$khalti->verifyPayment(\$secret, \$token, \$amount);";
        $code = $this->stripComments($injected);

        $this->assertStringContainsString("\$payload['amount']", $code);

        preg_match_all('/verifyPayment\s*\(([^;]*?)\)\s*;/s', $code, $calls);
        $this->assertNotEmpty($calls[1]);
        $this->assertStringNotContainsString('$expectedPaisa', $calls[1][0]);
    }

    /**
     * AUTHORIZE.NET RECORDS ITS OWN CAPTURE. It is the one handler that performs the charge
     * itself, so its approval is the strongest evidence any gateway on this platform produces --
     * and it was recording nothing, which is what made its orders Pending.
     */
    public function test_authorizenet_records_the_capture_it_performed(): void
    {
        $code = $this->codeOf(app_path('Http/Controllers/AuthorizeNetPaymnetController.php'));

        $this->assertMatchesRegularExpression(
            "/recordVerification\(\s*'authorizenet'/",
            $code,
            'the AuthorizeNet capture is no longer recorded, so every AuthorizeNet order is Pending'
        );
    }

    /**
     * The literal above is the constant. Read through a class that USES the trait, because PHP
     * will not resolve a trait constant directly -- which is exactly why the fixture hard-codes
     * the string and why this test has to exist.
     */
    public function test_the_checkout_nonce_key_is_still_spelled_this_way(): void
    {
        $this->assertSame(
            'checkout_nonce',
            \App\Http\Controllers\PaymentController::CHECKOUT_NONCE_KEY,
            'the stored-state key moved; the fixtures in this file still use the old spelling'
        );
    }

    // ------------------------------------------------------------------------------ fixture

    /**
     * A Khalti double that records its arguments and never opens a socket.
     *
     * Bound into the container, which is what the controller resolves. The object is a real
     * Khalti subclass rather than a mock so that a signature change on verifyPayment() breaks
     * this test rather than silently passing it.
     */
    private function fakeKhalti(array $response): Khalti
    {
        $spy = new class($response) extends Khalti {
            public $secret = null;
            public $token = null;
            public $amount = null;
            public int $calls = 0;

            public function __construct(private array $canned)
            {
            }

            public function verifyPayment($secret, $token, $amount)
            {
                $this->calls++;
                $this->secret = $secret;
                $this->token = $token;
                $this->amount = $amount;

                return $this->canned;
            }
        };

        $this->app->instance(Khalti::class, $spy);

        return $spy;
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Store} */
    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'kh-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
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

        // The billing writer inserts a cities row, so without these FK parents the handler 500s
        // before the order write and every assertion here would be vacuous.
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

    private function customerIn($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Kh', 'last_name' => 'Buyer',
            'email' => 'kh-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function productIn($store): Product
    {
        return Product::forceCreate([
            'name' => 'kh-' . uniqid(), 'slug' => 'kh-' . uniqid(),
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'price' => self::SERVER_TOTAL, 'sale_price' => self::SERVER_TOTAL,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    /** The shape PaymentService stores -- the handler's only source of money fields. */
    private function checkoutState($store, $customerId): array
    {
        $product = $this->productIn($store);

        DB::table('carts')->insert([
            'product_id' => $product->id, 'variant_id' => 0, 'qty' => 1,
            'customer_id' => $customerId, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'slug' => $store->slug,
            'store_id' => $store->id,
            'customer_id' => $customerId,
            'payment_type' => 'khalti',
            'shipping_final_price' => 0,
            'tax_price' => 0,
            'cartlist_final_price' => self::SERVER_TOTAL,
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

    private function codeOf(string $path): string
    {
        $this->assertFileExists($path);

        return $this->stripComments(file_get_contents($path));
    }

    /** CLAUDE.md's prose trap: a docblock quoting the thing a ratchet greps for defeats it. */
    private function stripComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
