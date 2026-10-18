<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\Payments\RecordingStripeHttpClient;

/**
 * P01-SEC-14 Work Package B — who decides what a customer pays.
 *
 * THE DEFECT. api/{slug}/payment-sheet took BOTH the amount and the currency from the request:
 *
 *     $paymentIntent = \Stripe\PaymentIntent::create([
 *         'amount'   => $request['price'],
 *         'currency' => $request['currency'],
 *         ...
 *
 * The previous package (P01-SEC-11, AUTHZ-22) closed who may call this endpoint -- it now requires
 * a Customer of the store in the path -- and recorded the amount as explicitly NOT fixed. This file
 * closes the amount.
 *
 * WHY A REFUSAL TEST CANNOT ANSWER THIS. "An unauthorized caller was stopped" says nothing about
 * what number an AUTHORIZED call puts on the merchant's Stripe account. So these tests install
 * Tests\Support\Payments\RecordingStripeHttpClient into \Stripe\ApiRequestor and assert the
 * parameters the SDK actually sent. Nothing leaves the process: there is no socket and no API key
 * use, so "do not make live charges or contact production payment services" holds by construction.
 *
 * THE DOCUMENTED CLIENT CONTRACT, which settles the unit question:
 * packages/workdo/Api/src/documentation/theme.json, general-detail/payment-sheet, documents
 *   request: {"theme_id": "stylique", "currency": "USD", "price": "10770"}
 *   success: {"clientSecret": "************"}
 * `10770` is MINOR UNITS -- $107.70. So the client was already sending cents, Stripe is given
 * cents, and a server-side amount must convert the cart's major-unit total the same way. Getting
 * that backwards is a 100x error in either direction, which is why it is asserted explicitly.
 */
class PaymentSheetAmountAuthorityTest extends SecurityTestCase
{
    use RefreshDatabase;

    private ?RecordingStripeHttpClient $stripe = null;

    protected function tearDown(): void
    {
        RecordingStripeHttpClient::restore();
        $this->stripe = null;
        parent::tearDown();
    }

    /** A store whose Stripe secret is configured, so the handler reaches the SDK. */
    private function stripeStore(string $currency = 'USD'): array
    {
        [$merchant, $store] = $this->makeTenant();

        foreach ([
            'stripe_secret' => 'sk_test_fake_not_a_real_key',
            'publishable_key' => 'pk_test_fake_not_a_real_key',
            'CURRENCY_NAME' => $currency,
            'CURRENCY' => '$',
        ] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value, 'created_by' => $merchant->id,
                'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$merchant, $store];
    }

    private function customerIn($store, string $label = 'pay'): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => $label,
            'email' => 'pay-' . $label . '-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    /**
     * A cart whose grand total is deterministic. Returns the major-unit total.
     *
     * Priced through the same path cart_list reads, so the total the handler computes is the total
     * this fixture intends rather than one this test asserts into existence.
     */
    private function cartWorth($store, Customer $customer, float $unitPrice, int $qty): float
    {
        $product = Product::forceCreate([
            'name' => 'pay-product-' . uniqid(),
            'slug' => 'pay-product-' . uniqid(),
            'product_stock' => 100, 'track_stock' => 0, 'variant_product' => 0,
            'price' => $unitPrice, 'sale_price' => $unitPrice,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => $qty, 'price' => $unitPrice * $qty, 'store_id' => $store->id,
        ]);

        return round($unitPrice * $qty, 2);
    }

    private function callSheet($store, array $payload, ?string $bearer)
    {
        $headers = $bearer === null ? [] : ['Authorization' => 'Bearer ' . $bearer];

        return $this->withHeaders($headers)
            ->postJson('/api/' . $store->slug . '/payment-sheet', $payload);
    }

    /** payment_sheet returns a raw json_encode string, not the {status,message,data} envelope. */
    private function decode($response): array
    {
        $decoded = json_decode($response->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** A tax the whole store pays, so the grand total differs from the products subtotal. */
    private function flatTax($store, float $ratePercent): int
    {
        $taxId = DB::table('taxes')->insertGetId([
            'name' => 'VAT', 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // No country/state/city, which is getProductTaxAmount's catch-all branch
        // (app/Models/Cart.php:901), so it applies regardless of the shopper's address.
        DB::table('tax_methods')->insert([
            'name' => 'VAT', 'tax_rate' => (string) $ratePercent, 'tax_id' => $taxId,
            'priority' => '0', 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) $taxId;
    }

    // ===================================================================================
    // THE AMOUNT IS THE SERVER'S
    // ===================================================================================

    /**
     * THE DEFECT, measured in both directions. Before Work Package B this call put `1` on the
     * merchant's Stripe account; it must now put the basket's own total.
     *
     * The assertion is on the parameters the Stripe SDK actually sent, not on the response, because
     * "the handler returned a clientSecret" says nothing about the number behind it.
     */
    public function test_the_amount_comes_from_the_cart_not_the_request(): void
    {
        [, $store] = $this->stripeStore('USD');
        $customer = $this->customerIn($store, 'authority');
        $total = $this->cartWorth($store, $customer, 107.70, 1);
        $this->assertSame(107.70, $total, 'fixture total drifted');

        $this->stripe = RecordingStripeHttpClient::install();

        $response = $this->callSheet($store, ['price' => 1, 'currency' => 'usd'],
            $customer->createToken('t')->plainTextToken);

        $create = $this->stripe->lastPaymentIntentCreate();
        $this->assertNotNull($create,
            'No PaymentIntent was created, so this test is measuring a refusal rather than the '
            . 'amount. Check the Stripe secret fixture and the authorization gate.');

        $this->assertSame(10770, (int) $create['params']['amount'],
            'The caller still decides what they pay. 10770 is 107.70 in minor units, which is what '
            . 'the documented contract shows the client sending for that basket.');

        // And the documented success shape survives: a bare {"clientSecret": ...}.
        $this->assertArrayHasKey('clientSecret', $this->decode($response),
            'The success response shape changed, which the gateway contract depends on.');
    }

    /**
     * THE KEY THAT MATTERS, and which one is right was settled by MEASURING both candidates
     * against the order the checkout actually writes rather than by reading their names.
     *
     * For this fixture -- one 100.00 product, 10% store tax -- the platform produces:
     *
     *     cart_list total_final_price   110.00     the line prices, which already include tax
     *     cart_list total_sub_price     121.00     the above plus tax computed AGAIN, on 110
     *     orders.final_price            110.00     what the order records as owed
     *
     * So the amount must be 11000 minor units. 12100 would mean `total_sub_price` was used, which
     * would take 121.00 for an order recording 110.00 -- worse than undercharging, because it is
     * money the order has no record of. 10000 would mean the tax was dropped entirely.
     *
     * The assertion is pinned to the ORDER's figure, not to a number typed here, which is what
     * makes it a consistency test rather than a restatement of the implementation.
     */
    public function test_the_amount_matches_what_the_order_records_as_owed(): void
    {
        [, $store] = $this->stripeStore('USD');
        $customer = $this->customerIn($store, 'withtax');
        $taxId = $this->flatTax($store, 10.0);

        $product = Product::forceCreate([
            'name' => 'taxed-' . uniqid(), 'slug' => 'taxed-' . uniqid(),
            'product_stock' => 100, 'track_stock' => 0, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 100.00, 'tax_id' => $taxId,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $token = $customer->createToken('t')->plainTextToken;

        // Read the cart the way the handler does, so the premise is the platform's own arithmetic
        // and not an assumption about it.
        $cart = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/' . $store->slug . '/cart-list', ['customer_id' => $customer->id]);

        $this->assertSame('110.00', (string) $cart->json('data.total_final_price'),
            'The tax fixture did not take effect, so this test cannot distinguish the two keys.');
        $this->assertSame('121.00', (string) $cart->json('data.total_sub_price'),
            'total_sub_price no longer double-counts the tax. If the platform has been fixed, this '
            . 'test should be re-derived rather than adjusted.');

        $this->stripe = RecordingStripeHttpClient::install();
        $this->callSheet($store, ['price' => 1, 'currency' => 'usd'], $token);

        $create = $this->stripe->lastPaymentIntentCreate();
        $this->assertNotNull($create, 'no intent was created, so nothing is being measured');

        $amount = (int) $create['params']['amount'];
        $this->assertSame(11000, $amount,
            'The amount was ' . $amount . '. 12100 means total_sub_price was used, which overcharges '
            . 'by a double-counted tax; 10000 means the tax was dropped.');
    }

    public function test_an_empty_cart_cannot_start_a_payment(): void
    {
        [, $store] = $this->stripeStore('USD');
        $customer = $this->customerIn($store, 'emptycart');

        $this->stripe = RecordingStripeHttpClient::install();

        $this->callSheet($store, ['price' => 50000, 'currency' => 'usd'],
            $customer->createToken('t')->plainTextToken);

        $this->assertSame(0, $this->stripe->createCount(),
            'A shopper with no cart started a payment for an amount they chose, which is the '
            . 'original defect in a different shape.');
    }

    /**
     * A basket that prices to zero must not start a payment either, and this is the case that
     * isolates the amount guard from the empty-cart guard: `product_list` is NOT empty here, so
     * only the `$payable <= 0` check can refuse.
     *
     * Those two guards are redundant for an EMPTY cart -- cart_list's refusal envelope carries no
     * total, so `?? 0` sends it to the amount guard anyway -- which is why removing either one
     * alone fails nothing. Removing both does; see the combined revert recorded in the commit.
     */
    public function test_a_basket_that_prices_to_zero_cannot_start_a_payment(): void
    {
        [, $store] = $this->stripeStore('USD');
        $customer = $this->customerIn($store, 'zeroprice');
        $this->cartWorth($store, $customer, 0.00, 3);

        $this->stripe = RecordingStripeHttpClient::install();

        $this->callSheet($store, ['price' => 25000, 'currency' => 'usd'],
            $customer->createToken('t')->plainTextToken);

        $this->assertSame(0, $this->stripe->createCount(),
            'A basket priced at zero started a payment. With a non-empty product_list the empty-'
            . 'cart guard does not apply, so only the amount guard can refuse here.');
    }

    // ===================================================================================
    // THE CURRENCY IS THE STORE'S
    // ===================================================================================

    public function test_the_currency_comes_from_the_store_not_the_request(): void
    {
        [, $store] = $this->stripeStore('EUR');
        $customer = $this->customerIn($store, 'currency');
        $this->cartWorth($store, $customer, 50.00, 1);

        $this->stripe = RecordingStripeHttpClient::install();

        $this->callSheet($store, ['price' => 1, 'currency' => 'JPY'],
            $customer->createToken('t')->plainTextToken);

        $create = $this->stripe->lastPaymentIntentCreate();
        $this->assertNotNull($create, 'no intent was created');
        $this->assertSame('eur', strtolower((string) $create['params']['currency']),
            "The caller chose the currency. That matters beyond labelling: 5000 is EUR 50.00 but "
            . 'also JPY 5,000, so pairing a store amount with another currency changes what is '
            . 'actually taken.');
        $this->assertSame(5000, (int) $create['params']['amount']);
    }

    /**
     * A zero-decimal currency has no minor unit, so the amount is passed whole. Charging 5000 for a
     * JPY 50 basket -- the non-zero-decimal conversion -- would be a 100x overcharge.
     */
    public function test_a_zero_decimal_currency_is_not_multiplied(): void
    {
        [, $store] = $this->stripeStore('JPY');
        $customer = $this->customerIn($store, 'jpy');
        $this->cartWorth($store, $customer, 50.00, 1);

        $this->stripe = RecordingStripeHttpClient::install();

        $this->callSheet($store, [], $customer->createToken('t')->plainTextToken);

        $create = $this->stripe->lastPaymentIntentCreate();
        $this->assertNotNull($create, 'no intent was created');
        $this->assertSame(50, (int) $create['params']['amount'],
            'A JPY amount was multiplied by 100, which is a 100x overcharge.');
    }

    /**
     * A store that has set no CURRENCY_NAME still gets a currency -- `Utility::Setting()` carries
     * `'CURRENCY_NAME' => 'USD'` as a platform default (app/Models/Utility.php:39), and
     * GetValueByName falls back to that map. So the handler's "no currency configured" refusal is
     * unreachable in practice; it is kept as a guard in case those defaults change, and this test
     * asserts the behaviour that IS reachable: the platform default is used, and the caller's
     * choice still is not.
     *
     * Recorded rather than asserted as a refusal, because a test claiming to prove a refusal that
     * cannot happen proves nothing.
     */
    public function test_a_store_without_its_own_currency_falls_back_to_the_platform_default(): void
    {
        [$merchant, $store] = $this->makeTenant();
        DB::table('settings')->insert([
            'name' => 'stripe_secret', 'value' => 'sk_test_fake', 'created_by' => $merchant->id,
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $customer = $this->customerIn($store, 'nocurrency');
        $this->cartWorth($store, $customer, 10.00, 1);

        $this->stripe = RecordingStripeHttpClient::install();

        $this->callSheet($store, ['price' => 999999, 'currency' => 'JPY'],
            $customer->createToken('t')->plainTextToken);

        $create = $this->stripe->lastPaymentIntentCreate();
        $this->assertNotNull($create, 'no intent was created');
        $this->assertSame('usd', strtolower((string) $create['params']['currency']),
            "The caller's currency was used for a store that had configured none.");
        $this->assertSame(1000, (int) $create['params']['amount'],
            "The caller's amount was used for a store that had configured no currency.");
    }

    // ===================================================================================
    // BINDING AND DUPLICATE SUPPRESSION
    // ===================================================================================

    public function test_the_intent_is_bound_to_the_store_and_the_customer(): void
    {
        [, $store] = $this->stripeStore('USD');
        $customer = $this->customerIn($store, 'metadata');
        $this->cartWorth($store, $customer, 25.00, 2);

        $this->stripe = RecordingStripeHttpClient::install();

        $this->callSheet($store, [], $customer->createToken('t')->plainTextToken);

        $create = $this->stripe->lastPaymentIntentCreate();
        $this->assertNotNull($create, 'no intent was created');
        $this->assertSame((string) $store->id, (string) ($create['params']['metadata']['store_id'] ?? ''),
            'The intent does not say which store the money belongs to.');
        $this->assertSame((string) $customer->id, (string) ($create['params']['metadata']['customer_id'] ?? ''),
            'The intent does not say which shopper the money belongs to.');
    }

    /**
     * Without an idempotency key a caller can sit in a loop and fill a merchant's Stripe dashboard
     * with intents. Stripe returns the same intent for a repeated key, so the same basket must
     * produce the same key -- and a changed basket must not.
     */
    public function test_repeating_the_same_request_reuses_one_idempotency_key(): void
    {
        [, $store] = $this->stripeStore('USD');
        $customer = $this->customerIn($store, 'idem');
        $this->cartWorth($store, $customer, 30.00, 1);
        $token = $customer->createToken('t')->plainTextToken;

        $this->stripe = RecordingStripeHttpClient::install();

        $this->callSheet($store, [], $token);
        $first = $this->stripe->lastIdempotencyKey();

        $this->callSheet($store, [], $token);
        $second = $this->stripe->lastIdempotencyKey();

        $this->assertNotNull($first, 'No Idempotency-Key was sent, so a caller can mint unlimited '
            . "intents on the merchant's account.");
        $this->assertSame($first, $second,
            'Two identical requests produced different idempotency keys, so Stripe will create two '
            . 'intents.');
    }

    public function test_a_changed_basket_produces_a_different_idempotency_key(): void
    {
        [, $store] = $this->stripeStore('USD');
        $customer = $this->customerIn($store, 'idem2');
        $this->cartWorth($store, $customer, 30.00, 1);
        $token = $customer->createToken('t')->plainTextToken;

        $this->stripe = RecordingStripeHttpClient::install();

        $this->callSheet($store, [], $token);
        $first = $this->stripe->lastIdempotencyKey();

        // The shopper adds more to the basket, so this is a genuinely different payment.
        $this->cartWorth($store, $customer, 20.00, 1);

        $this->callSheet($store, [], $token);
        $second = $this->stripe->lastIdempotencyKey();

        $this->assertNotSame($first, $second,
            'A larger basket reused the previous idempotency key, so Stripe would return the OLD '
            . 'intent and the shopper would be charged the old, smaller amount.');
    }

    /** Two shoppers with identical baskets must not collide on one intent. */
    public function test_two_customers_with_identical_baskets_get_different_keys(): void
    {
        [, $store] = $this->stripeStore('USD');
        $a = $this->customerIn($store, 'idem-a');
        $b = $this->customerIn($store, 'idem-b');
        $this->cartWorth($store, $a, 40.00, 1);
        $this->cartWorth($store, $b, 40.00, 1);

        $this->stripe = RecordingStripeHttpClient::install();

        $this->callSheet($store, [], $a->createToken('t')->plainTextToken);
        $keyA = $this->stripe->lastIdempotencyKey();
        $this->callSheet($store, [], $b->createToken('t')->plainTextToken);
        $keyB = $this->stripe->lastIdempotencyKey();

        $this->assertNotSame($keyA, $keyB,
            "Two shoppers with equal baskets shared an idempotency key, so the second would be "
            . "handed the first shopper's payment intent.");
    }

    // ===================================================================================
    // CROSS-STORE PAYMENT CONFIGURATION
    // ===================================================================================

    /**
     * The authorization gate from P01-SEC-11 is what stops this, and it is asserted here against
     * the Stripe transport rather than against a status code: the question is whether store A's
     * SECRET can be made to sign a stranger's intent.
     */
    public function test_a_customer_of_another_store_cannot_use_this_stores_stripe_account(): void
    {
        [, $storeA] = $this->stripeStore('USD');
        [, $storeB] = $this->stripeStore('USD');

        $outsider = $this->customerIn($storeB, 'outsider');
        $this->cartWorth($storeB, $outsider, 99.00, 1);

        $this->stripe = RecordingStripeHttpClient::install();

        $this->callSheet($storeA, ['price' => 100, 'currency' => 'usd'],
            $outsider->createToken('t')->plainTextToken);

        $this->assertSame(0, $this->stripe->createCount(),
            "A shopper of store B created a payment intent on store A's Stripe account.");
    }

    public function test_a_merchant_token_cannot_start_a_payment(): void
    {
        [$merchant, $store] = $this->stripeStore('USD');

        $this->stripe = RecordingStripeHttpClient::install();

        $this->callSheet($store, ['price' => 100, 'currency' => 'usd'],
            $merchant->createToken('t')->plainTextToken);

        $this->assertSame(0, $this->stripe->createCount(),
            "A merchant's token created a payment intent.");
    }

    public function test_an_anonymous_caller_cannot_start_a_payment(): void
    {
        [, $store] = $this->stripeStore('USD');

        $this->stripe = RecordingStripeHttpClient::install();

        $this->callSheet($store, ['price' => 100, 'currency' => 'usd'], null);

        $this->assertSame(0, $this->stripe->createCount(),
            'An anonymous caller created a payment intent.');
    }

    // ===================================================================================
    // FAILURE HANDLING
    // ===================================================================================

    /**
     * The handler's `catch (Error $e)` was unqualified inside namespace App\Http\Controllers\Api,
     * so it resolved to a non-existent App\Http\Controllers\Api\Error and could never match: a
     * Stripe failure became an unhandled 500 with a stack trace. It must now be this endpoint's own
     * error envelope.
     */
    public function test_a_stripe_failure_returns_an_error_envelope_not_a_500(): void
    {
        [, $store] = $this->stripeStore('USD');
        $customer = $this->customerIn($store, 'failure');
        $this->cartWorth($store, $customer, 15.00, 1);

        $this->stripe = RecordingStripeHttpClient::install(
            static fn () => [json_encode([
                'error' => ['type' => 'card_error', 'message' => 'Your card was declined.'],
            ]), 402, []]
        );

        $response = $this->callSheet($store, [], $customer->createToken('t')->plainTextToken);

        $this->assertSame(200, $response->getStatusCode(),
            'A Stripe failure produced HTTP ' . $response->getStatusCode() . ' rather than this '
            . "endpoint's error envelope.");
        $this->assertStringNotContainsString('clientSecret', $response->getContent());
        $this->assertStringNotContainsString('card was declined', $response->getContent(),
            "The gateway's message was relayed to the caller.");
    }
}
