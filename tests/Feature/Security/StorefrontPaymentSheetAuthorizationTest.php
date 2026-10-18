<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P01-SEC-11, AUTHZ-22 — POST api/{slug}/payment-sheet.
 *
 * THE DEFECT. No principal check and no store guard, so any token holder -- a merchant, a driver,
 * or a customer of a DIFFERENT store -- created a Stripe PaymentIntent against THIS store's
 * Stripe account with an amount and currency of their choosing. An unauthorized call on someone
 * else's payment processor: it consumes the merchant's Stripe account and appears in their
 * dashboard. A bogus slug also reached `$store->id` on null and returned 500.
 *
 * HOW THESE TESTS AVOID TALKING TO STRIPE, AND WHY THEY ASSERT THE MESSAGE. Every assertion is
 * about a refusal that happens BEFORE `\Stripe\Stripe::setApiKey()`, so no outbound call is made
 * and none is mocked. But "status 0" is not enough to distinguish them: with no Stripe secret
 * configured the handler answers status 0 for EVERY caller, so a bare status assertion passes
 * with the gate removed. The first run of this file did exactly that -- reverting the principal
 * check failed nothing.
 *
 * So each denial asserts the gate's OWN message. With the fix a wrong principal sees
 * 'Unauthenticated.'; without it they reach 'publishable key or stripe secret not found.', which
 * is positive evidence they got past the gate. The authorized caller's test is the mirror image:
 * seeing the Stripe-key message proves the gate accepted them. No Stripe secret is needed for
 * either direction.
 *
 * NOT CLOSED, and not claimed: `amount` still comes from the request. See the handler comment and
 * docs/security/P01-SECURITY-WORK-QUEUE.md.
 */
class StorefrontPaymentSheetAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function customerIn($store, string $label): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => $label,
            'email' => 'pay-' . $label . '-' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function callApi($store, array $payload, ?string $bearer)
    {
        $headers = $bearer === null ? [] : ['Authorization' => 'Bearer ' . $bearer];

        return $this->withHeaders($headers)
            ->postJson('/api/' . $store->slug . '/payment-sheet', $payload);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['price' => 1000, 'currency' => 'usd'], $overrides);
    }

    public function test_a_merchant_token_is_refused(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $response = $this->callApi($store, $this->payload(),
            $merchant->createToken('t')->plainTextToken);

        $this->assertSame(0, $response->json('status'));
        $this->assertSame('Unauthenticated.', $response->json('data.message'),
            "A merchant's token got past the gate -- it reached the Stripe-key check, which only "
            . 'an accepted caller sees.');
        $this->assertStringNotContainsString('clientSecret', $response->getContent());
    }

    public function test_a_driver_token_is_refused(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $driver = DeliveryBoy::forceCreate([
            'name' => 'Driver', 'email' => 'drv-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'created_by' => $merchant->id, 'store_id' => $store->id,
        ]);

        $response = $this->callApi($store, $this->payload(),
            $driver->createToken('t')->plainTextToken);

        $this->assertSame('Unauthenticated.', $response->json('data.message'),
            "A driver's token got past the gate.");
    }

    public function test_a_customer_of_another_store_is_refused(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $outsider = $this->customerIn($storeB, 'x1');

        $response = $this->callApi($storeA, $this->payload(),
            $outsider->createToken('t')->plainTextToken);

        $this->assertSame('Unauthenticated.', $response->json('data.message'),
            "A shopper of store B got past store A's gate.");
    }

    public function test_an_anonymous_caller_is_refused(): void
    {
        [, $store] = $this->makeTenant();

        $response = $this->callApi($store, $this->payload(), null);

        $this->assertNotSame(1, $response->json('status'),
            'An anonymous caller created a payment intent.');
        $this->assertNotSame('publishable key or stripe secret not found.',
            $response->json('data.message'),
            'An anonymous caller got past the gate.');
    }

    /**
     * SUPERSEDED BY P01-SEC-14 WORK PACKAGE B, and rewritten rather than deleted.
     *
     * When this was written the handler took `price` from the request, so the defence against a
     * negative amount was a validation rule and this test asserted that rule's message. Work
     * Package B made the amount server-derived, so a posted price -- negative, enormous or absurd
     * -- is not validated because it is not read. That is strictly stronger than rejecting it, and
     * the assertion now says so: the refusal still happens (this shopper has no cart), and it is
     * NOT a complaint about the posted value.
     *
     * PaymentSheetAmountAuthorityTest is where the amount's provenance is proved against the
     * Stripe transport.
     */
    public function test_a_posted_amount_is_not_validated_because_it_is_not_read(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'n1');

        $response = $this->callApi($store, $this->payload(['price' => -5000]),
            $customer->createToken('t')->plainTextToken);

        $this->assertSame(0, $response->json('status'),
            'A shopper with no cart was served.');
        $this->assertStringNotContainsString('price', strtolower(
            (string) $response->json('message') . ' ' . (string) $response->json('data.message')),
            'The handler is still validating the posted price, which means it is still reading it.');
        $this->assertStringNotContainsString('clientSecret', $response->getContent());
    }

    public function test_a_nonnumeric_amount_is_refused(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'n2');

        $response = $this->callApi($store, $this->payload(['price' => 'free']),
            $customer->createToken('t')->plainTextToken);

        $this->assertSame(0, $response->json('status'));
        $this->assertNotSame('publishable key or stripe secret not found.',
            $response->json('data.message'),
            'A non-numeric amount got past validation to the Stripe call.');
    }

    /**
     * A bogus slug reached `Utility::GetValueByName('publishable_key', $store->id)` with $store
     * null, which is a 500. It must be the handler's own store-not-found envelope now.
     */
    public function test_an_unknown_slug_does_not_crash(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 's1');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $customer->createToken('t')->plainTextToken,
        ])->postJson('/api/no-such-store-' . uniqid() . '/payment-sheet', $this->payload());

        $this->assertSame(200, $response->getStatusCode(),
            'An unknown slug still produces a 500.');
        $this->assertSame(0, $response->json('status'));
    }

    /**
     * The shopper of this store gets PAST the gate. Proven by the error they receive: only a
     * caller the gate accepted reaches the Stripe-key check, so seeing that message is positive
     * evidence the authorized path is not being refused -- without needing a Stripe secret or an
     * outbound call.
     */
    /**
     * The store's own shopper gets PAST the authorization gate, proven by the error they receive.
     *
     * UPDATED FOR P01-SEC-14 WORK PACKAGE B: the handler now resolves the payable amount from the
     * caller's cart BEFORE it looks for a Stripe key, so the first thing an authorized caller with
     * an empty basket meets is the cart check rather than the key check. Reaching that message is
     * still positive evidence of having passed the gate -- an unauthorized caller never sees it --
     * and it needs no Stripe secret and no outbound call.
     */
    public function test_a_shopper_of_this_store_passes_the_gate(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'ok1');

        $this->assertNull(DB::table('settings')->where('name', 'stripe_secret')
            ->where('store_id', $store->id)->value('value'),
            'A stripe secret exists in this fixture, so this test could call Stripe.');

        $response = $this->callApi($store, $this->payload(),
            $customer->createToken('t')->plainTextToken);

        // ApiResponser::error() puts the handler's own text in data.message and leaves the
        // top-level `message` as its 'fail' default when no second argument is passed.
        $this->assertStringContainsString('cart is empty',
            strtolower((string) $response->json('data.message')),
            "The store's own shopper was refused before reaching the cart check, so the gate is "
            . 'rejecting the authorized caller.');
    }
}
