<?php

namespace Tests\Feature\Security;

use App\Models\OtpIntegration;
use App\Models\Product;
use App\Models\StoreWallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * THE SURFACES GATE F TURNED ON, AND THE FOUR DEFECTS THAT WERE WAITING ON THEM.
 *
 * ================================================================================================
 *  EVERY ONE OF THESE WAS LATENT *IN THE TEST SUITE* BECAUSE A TABLE OR COLUMN DID NOT EXIST
 *  THERE, AND NOTHING IN THE SUITE SAID SO.
 * ================================================================================================
 *
 * [CORRECTED 2026-11-02, owner Decision 4.] This banner read "ALL FOUR WENT LIVE ON 2026-11-02",
 * and that is false of PRODUCTION. `docs/GATE-F-VERIFIED-SCHEMA.md` records, in a table headed
 * "The three claims the dump refuted", that all fifteen tables already existed in production with
 * live data -- 92 `store_order_financials` rows, the service "running, and has been" -- and that
 * `products.cost_price` was populated on all 45 products. The original wording is kept above in
 * corrected form rather than deleted, because the mistake is the lesson: the claim was inferred
 * from the migrations, which CLAUDE.md §1 states are not the schema.
 *
 * What went live ON 2026-11-02 was the suite's ability to SEE these surfaces. The defects
 * themselves were reachable in production before that date, which is what makes the fixes
 * remediations rather than preventions.
 *
 * The four waves before this one were found by running the suite: a skip, an absence assertion, a
 * fixture that did not match the schema, an absence used as a proof. This wave could not be found
 * that way, because the suite was green. It was found by an independent completeness sweep asking
 * the opposite question -- *what did making these objects exist make the application DO?* -- and
 * every finding below was then re-measured through the real route before a line was changed.
 *
 * The shape is the same every time and it is worth naming once: **application code whose
 * behaviour depended on a table being ABSENT.** A write that threw into a `catch`, a column that
 * was always null so `?? 0` hid it, a validator rule that raised a QueryException so the endpoint
 * answered 500 to everyone. Each of those is a control by accident, and GATE F removed all of them
 * in one day.
 *
 * The fifth finding of that sweep is NOT here, because it is not fixable from this side:
 * `P1-SEC-43`, one forged Zid webhook creating a real parcel at Delivery Syria on another
 * merchant's account. It is S1, OPEN, and reserved to the owner in
 * `docs/security/P01-SECURITY-WORK-QUEUE.md`.
 */
class GateFActivatedSurfacesTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * DNS IS A SEAM, so nothing here touches a network and the guard's verdict is deterministic.
     *
     * `SafeWebhook::allows()` judges through `SafeRemoteFetch::check`, which resolves the host and
     * refuses a private, loopback, link-local or metadata address on ANY of its records. A fixture
     * host that does not resolve is refused too -- correctly -- which is why the positive case
     * below needs this seam rather than a made-up hostname: without it, the "still reaches a
     * public address" test would pass for the wrong reason, by being refused for a DNS failure
     * instead of accepted.
     */
    protected function setUp(): void
    {
        parent::setUp();

        \App\Services\SafeRemoteFetch::resolveUsing(fn (string $host) => match ($host) {
            'sms.example.test' => ['93.184.216.34'],
            'other-sms.example.test' => ['93.184.216.35'],
            default => [],
        });
    }

    protected function tearDown(): void
    {
        \App\Services\SafeRemoteFetch::resolveUsing(null);

        parent::tearDown();
    }

    private function walletFor($store): void
    {
        StoreWallet::create([
            'store_id' => $store->id,
            'currency_code' => 'USD',
            'available_balance' => 0,
            'pending_balance' => 0,
            'total_income' => 0,
            'total_withdrawn' => 0,
        ]);
    }

    private function productIn($store, float $costPrice): Product
    {
        return Product::forceCreate([
            'name' => 'cost-' . uniqid(),
            'slug' => 'cost-' . uniqid(),
            'product_stock' => 10,
            'track_stock' => 1,
            'variant_product' => 0,
            'price' => 100,
            'sale_price' => 100,
            'cost_price' => $costPrice,
            'stock_status' => 'in_stock',
            'status' => 1,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);
    }

    private function financialFor($orderId)
    {
        return DB::table('store_order_financials')->where('order_id', $orderId)->first();
    }

    // ============================================================================================
    //  P1-SEC-44 -- one store's confidential cost_price read into another store's P&L
    // ============================================================================================

    /**
     * `OrderFinancialService::calculateProductCost()` resolved each order line with
     * `Product::find($productId)`. `Product` is NOT in `config/tenancy.php`'s `scoped_models`, so
     * `StoreScope::isActiveFor()` short-circuits and that call added no predicate for ANY
     * principal. `$productId` comes out of `orders.product_json`, which is caller-influenced --
     * this repository already records that a cart line naming another merchant's product reaches
     * an order (the S19/S32 cross-tenant stock drain).
     *
     * So a competitor's unit cost was multiplied by the quantity the ordering store chose and
     * written into THEIR `product_cost` and `gross_profit`, which their own finance screen shows.
     *
     * DOUBLY ACTIVATED BY GATE F, which is why no test saw it: until 2026-11-02 the persistence
     * threw (`store_order_financials` was undeclared) into `OrderObserver::created()`'s catch,
     * AND `products.cost_price` was undeclared so `?? 0` made every cost zero.
     *
     * MEASURED before the fix: store B's product at 77.00, a store A order naming it with qty 2
     * -> store A's row carried `product_cost = 154`, `gross_profit = 46`.
     */
    public function test_a_foreign_products_cost_price_does_not_enter_this_stores_profit_and_loss(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->walletFor($storeA);

        $theirProduct = $this->productIn($storeB, 77.00);

        $this->assertSame(
            '77.00',
            (string) number_format((float) $theirProduct->cost_price, 2, '.', ''),
            'premise: the foreign product carries a cost_price, or this test proves nothing'
        );

        // The order belongs to store A and its single line names store B's product.
        $order = $this->makeOrder($storeA, [
            'price' => 200,
            'final_price' => 200,
            'payment_status' => 'succeeded',
            'product_json' => json_encode([
                ['product_id' => $theirProduct->id, 'qty' => 2, 'price' => 100],
            ]),
        ]);

        $financial = $this->financialFor($order->id);

        $this->assertNotNull($financial, 'no financial row was written for the order');

        $this->assertEqualsWithDelta(
            0.0,
            (float) $financial->product_cost,
            0.01,
            'ANOTHER STORE\'S COST PRICE IS IN THIS STORE\'S P&L. calculateProductCost() is '
            . 'resolving order lines through an unscoped Product::find() again, so store B\'s '
            . 'confidential unit cost (77.00 x qty 2 = 154.00) landed in store A\'s product_cost. '
            . 'Divide by the quantity and the ordering merchant has their competitor\'s exact '
            . 'unit cost. This is P1-SEC-44.'
        );
    }

    /**
     * THE POSITIVE CONTROL, without which the test above passes against a method that has simply
     * stopped costing anything at all.
     */
    public function test_the_stores_own_product_is_still_costed(): void
    {
        [, $store] = $this->makeTenant();
        $this->walletFor($store);

        $own = $this->productIn($store, 30.00);

        $order = $this->makeOrder($store, [
            'price' => 200,
            'final_price' => 200,
            'payment_status' => 'succeeded',
            'product_json' => json_encode([
                ['product_id' => $own->id, 'qty' => 3, 'price' => 100],
            ]),
        ]);

        $financial = $this->financialFor($order->id);

        $this->assertNotNull($financial, 'no financial row was written for the order');

        $this->assertEqualsWithDelta(
            90.0,
            (float) $financial->product_cost,
            0.01,
            'The store\'s OWN product was not costed (30.00 x qty 3 = 90.00). The tenancy fix has '
            . 'turned the cost calculation off rather than making it safe.'
        );
    }

    // ============================================================================================
    //  P1-SEC-45 -- the OTP test endpoint shipped the store's secret to a caller-chosen host
    // ============================================================================================

    /**
     * `OtpIntegrationController::test()` validated only `phone`, and then preferred
     * `$request->base_url` over the stored row while sending `secret => $integration->api_secret`
     * in the body. `save()` validates `base_url` as `required|url`; `test()` validated nothing.
     *
     * MEASURED before the fix, as a principal holding no permission: HTTP 200
     * `{"success":true,...}` and an outbound POST to
     * `http://169.254.169.254/latest/meta-data/api/send/sms` carrying the store's secret. The
     * handler also reflects the remote body and the exception message, so it was a semi-blind
     * SSRF with error reflection on top of the credential leak.
     *
     * WAVE 5, not a new hole: `routes/web.php` records that anonymously this route used to answer
     * 500 because `otp_integrations` was one of GATE F's fifteen. The fix taken then was `auth`;
     * the caller-chosen-URL half was never closed, and the table now exists everywhere.
     *
     * [2026-11-02, P1-SEC-55] THIS TEST HAD BECOME VACUOUS AND STILL PASSED, WHICH IS WHY IT IS
     * REWRITTEN RATHER THAN LEFT ALONE.
     *
     * It read `Http::fake()` plus `Http::assertNothingSent()`. The controller now sends through
     * `SafeWebhook::send()`, which is cURL, so the `Http` facade is no longer involved at all and
     * `assertNothingSent()` HOLDS WHATEVER THE HANDLER DOES. Measured: with the fix in place this
     * test passed; with the guard removed it would have passed too. Its sibling
     * (`...still reaches a public address`) failed loudly in the same run, because
     * `Http::assertSent` cannot see a cURL request -- so the pair failed in opposite directions
     * from the same cause, and only one of them told anyone.
     *
     * The seam used now is `SafeWebhook::transportUsing()`, which is the actual transport, so a
     * refusal means nothing was handed to it. The comprehensive matrix -- redirect refusal, DNS
     * pinning, authorisation, the anonymous oracle -- lives in OtpOutboundHardeningTest; this
     * case stays here because it is the GATE F wave-5 record.
     */
    public function test_the_otp_test_endpoint_refuses_an_internal_address_and_sends_nothing(): void
    {
        $sent = [];
        \App\Services\SafeWebhook::transportUsing(function (string $url, array $options) use (&$sent) {
            $sent[] = $url;

            // The metadata seam shape: an HTTP status alongside the body. The success test is
            // `OtpTransport::succeeded()`, which requires HTTP 2xx AND provider status 200, so a
            // seam that reports no status would make this positive case fail -- deliberately.
            return ['body' => json_encode(['status' => 200]), 'status' => 200];
        });

        [$merchant, $store] = $this->makeTenant();

        OtpIntegration::create([
            'store_id' => $store->id,
            'base_url' => 'https://sms.example.test',
            'api_secret' => 'the-stores-own-otp-secret',
            'otp_channel' => 'sms',
            // `sms_mode` IS LOAD-BEARING FOR BOTH CASES, and leaving it out made the NEGATIVE one
            // pass for the wrong reason: the handler returns "Invalid SMS mode." before it ever
            // reaches the HTTP call, so `Http::assertNothingSent()` would have held with or
            // without the SafeWebhook guard. With it set, a request WOULD go out but for the
            // guard, which is what makes the assertion attributable.
            'sms_mode' => 'credits',
            // And `sms_gateway` for the same reason: credits mode returns "Gateway ID is missing."
            // before the HTTP call without it. Every early return in this handler is a way for a
            // `Http::assertNothingSent()` to hold for a reason that is not the guard.
            'sms_gateway' => 'fixture-gateway',
        ]);

        $response = $this->actingAs($merchant->fresh())->post('/otp-integration/test', [
            'phone' => '0100000000',
            'base_url' => 'http://169.254.169.254',
        ]);

        $this->assertNotSame(404, $response->getStatusCode(), 'the route must exist');

        \App\Services\SafeWebhook::transportUsing(null);

        $this->assertSame([], $sent, 'the link-local address was dialled');

        $body = json_decode((string) $response->getContent(), true) ?: [];

        $this->assertFalse(
            (bool) ($body['success'] ?? false),
            'THE OTP TEST ENDPOINT ACCEPTED A LINK-LOCAL ADDRESS. The store\'s api_secret is in '
            . 'the body of that request, and 169.254.169.254 is the cloud instance-metadata '
            . 'endpoint. The SafeWebhook::allows() guard has been removed or bypassed. '
            . 'This is P1-SEC-45. Response: ' . substr((string) $response->getContent(), 0, 200)
        );
    }

    /**
     * AND THE FEATURE STILL WORKS, which is why the override was guarded rather than deleted: a
     * merchant may still test a public address before saving it.
     */
    public function test_the_otp_test_endpoint_still_reaches_a_public_address(): void
    {
        // See the note on the negative case above: `Http::fake()` can no longer see this send,
        // and THIS test is the one that failed loudly when the transport changed. The resolver
        // seam is needed too, because the real one has no record for *.example.test.
        $sent = [];
        \App\Services\SafeRemoteFetch::resolveUsing(fn (string $host) => ['93.184.216.34']);
        \App\Services\SafeWebhook::transportUsing(function (string $url, array $options) use (&$sent) {
            $sent[] = $url;

            return ['body' => json_encode(['status' => 200, 'message' => 'queued']), 'status' => 200];
        });

        [$merchant, $store] = $this->makeTenant();

        OtpIntegration::create([
            'store_id' => $store->id,
            'base_url' => 'https://sms.example.test',
            'api_secret' => 'the-stores-own-otp-secret',
            'otp_channel' => 'sms',
            // `sms_mode` IS LOAD-BEARING FOR BOTH CASES, and leaving it out made the NEGATIVE one
            // pass for the wrong reason: the handler returns "Invalid SMS mode." before it ever
            // reaches the HTTP call, so `Http::assertNothingSent()` would have held with or
            // without the SafeWebhook guard. With it set, a request WOULD go out but for the
            // guard, which is what makes the assertion attributable.
            'sms_mode' => 'credits',
            // And `sms_gateway` for the same reason: credits mode returns "Gateway ID is missing."
            // before the HTTP call without it. Every early return in this handler is a way for a
            // `Http::assertNothingSent()` to hold for a reason that is not the guard.
            'sms_gateway' => 'fixture-gateway',
        ]);

        $this->actingAs($merchant->fresh())->post('/otp-integration/test', [
            'phone' => '0100000000',
            'base_url' => 'https://other-sms.example.test',
        ]);

        \App\Services\SafeWebhook::transportUsing(null);
        \App\Services\SafeRemoteFetch::resolveUsing(null);

        $this->assertCount(1, $sent, 'the public address was not reached, so the guard over-refuses');
        $this->assertSame(
            'https://other-sms.example.test/api/send/sms',
            $sent[0],
            'the request must go to the full provider endpoint, and that full URL is what was '
            . 'judged -- the previous code judged the base and requested the path'
        );
    }

    // ============================================================================================
    //  P1-SEC-47 -- an existence oracle over delivery_boy_notifications.id
    // ============================================================================================

    /**
     * `markAsRead` validated `notification_id` with `exists:delivery_boy_notifications,id` -- the
     * WHOLE table -- before the correctly-scoped `->where('delivery_boy_id', $user->id)` lookup.
     * The table has no `store_id` and is not in `scoped_models`, so the validator saw every
     * tenant's rows and the two refusals were distinguishable:
     *
     *     a foreign but EXISTING id -> "Notification not found."
     *     a NONEXISTENT id          -> "The selected notification id is invalid."
     *
     * Unreachable before GATE F: the rule raised a QueryException and the endpoint answered 500 to
     * everybody. The assertion is that the two bodies are now IDENTICAL -- not that either has a
     * particular wording, which would pin the message rather than the property.
     */
    public function test_a_driver_cannot_tell_another_stores_notification_from_a_nonexistent_one(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $mine = $this->makeDriverFor($storeA, 'oracle-mine@example.test');
        $theirs = $this->makeDriverFor($storeB, 'oracle-theirs@example.test');

        // THE MEASURED SCHEMA, not a guess: `order_id`, `type`, `title` and `message` are all NOT
        // NULL, `order_id` carries a FOREIGN KEY to `orders`, and there is no `body` column -- the
        // first version of this fixture invented one. Fourth time GATE F has falsified a column
        // guess in this suite, which is why the shape is read from
        // App\Support\Database\GateFProductionSchema rather than from the model's $fillable.
        $theirOrder = $this->makeOrder($storeB);

        $foreignId = DB::table('delivery_boy_notifications')->insertGetId([
            'delivery_boy_id' => $theirs->id,
            'order_id' => $theirOrder->id,
            'type' => 'order_assigned',
            'title' => 'theirs',
            'message' => 'theirs',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $mine->createToken('auth_token')->plainTextToken;

        $existing = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/notifications/mark-as-read', ['notification_id' => $foreignId]);

        $absent = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/notifications/mark-as-read', ['notification_id' => 99999999]);

        $this->assertNotSame(404, $existing->getStatusCode(), 'the route must exist');

        $this->assertSame(
            $absent->json('data.message'),
            $existing->json('data.message'),
            'THE REFUSALS DIFFER, so a driver can enumerate which notification ids exist across '
            . 'every tenant. The `exists:` rule is unscoped again: it runs before the '
            . 'delivery_boy_id predicate and sees the whole table. This is P1-SEC-47. Foreign: '
            . json_encode($existing->json()) . ' Absent: ' . json_encode($absent->json())
        );

        $this->assertSame(
            0,
            (int) DB::table('delivery_boy_notifications')->where('id', $foreignId)->value('is_read'),
            'the foreign notification was marked read'
        );
    }

    /**
     * POSITIVE CONTROL: the driver's OWN notification is still readable, so the scoped rule did
     * not simply refuse everything.
     */
    public function test_a_driver_can_still_mark_their_own_notification_read(): void
    {
        [, $store] = $this->makeTenant();
        $driver = $this->makeDriverFor($store, 'oracle-own@example.test');

        $order = $this->makeOrder($store);

        $id = DB::table('delivery_boy_notifications')->insertGetId([
            'delivery_boy_id' => $driver->id,
            'order_id' => $order->id,
            'type' => 'order_assigned',
            'title' => 'mine',
            'message' => 'mine',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $driver->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/notifications/mark-as-read', ['notification_id' => $id]);

        $this->assertSame(
            1,
            (int) ($response->json('status') ?? 0),
            'the driver could not mark their OWN notification read: ' . $response->getContent()
        );

        $this->assertSame(
            1,
            (int) DB::table('delivery_boy_notifications')->where('id', $id)->value('is_read'),
            'the notification was not marked read'
        );
    }

    // ============================================================================================
    //  P1-SEC-46 -- ViewProduct served the store's margin with no permission check
    // ============================================================================================

    /**
     * `ProductList` serves `cost_price` behind `refuseWithoutPermission(['Manage Products'])`, and
     * its own comment says why: *"the store's margin, not just its catalogue"*. `ViewProduct`
     * served the same field with NO permission check anywhere in the method. Tenant isolation held
     * -- the query carries `->where('store_id', $store->id)` -- but the intra-tenant gate did not.
     *
     * It cost nothing until GATE F: `products.cost_price` was declared by no migration, so `?? 0`
     * served `0.00` to everybody. The column arriving is what made the asymmetry matter.
     *
     * THE FIELD IS OMITTED RATHER THAN THE REQUEST REFUSED. A 403 would change who may call
     * `ViewProduct` at all, and the shipped Flutter app calls it -- CLAUDE.md requires that
     * contract preserved. So the assertion is on the KEY's absence, not on a status code.
     */
    public function test_view_product_does_not_serve_cost_price_without_the_products_permission(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $product = $this->productIn($store, 42.00);

        $staff = \App\Models\User::factory()->create([
            'type' => 'staff',
            'created_by' => $merchant->id,
            'is_active' => 1,
            'email_verified_at' => now(),
            'current_store' => $store->id,
        ]);

        $this->assertFalse(
            $staff->fresh()->isAbleTo('Manage Products'),
            'premise: the staff member must NOT hold Manage Products, or this proves nothing'
        );

        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . $staff->createToken('auth_token')->plainTextToken
        )->getJson('/api/admin/viewproduct?store_id=' . $store->id . '&product_id=' . $product->id);

        $this->assertNotSame(404, $response->getStatusCode(), 'the route must exist');

        // `data.Product`, NOT `data`. The first version of this test read `data`, which never
        // carries `cost_price` at any nesting -- so `assertArrayNotHasKey` was vacuous and the
        // test passed against the unfixed handler. Its own POSITIVE CONTROL is what caught that,
        // which is the entire reason this file pairs every refusal with one.
        $payload = $response->json('data.Product') ?? [];

        $this->assertNotEmpty(
            $payload,
            'the handler returned no product at all, so the assertion below would be vacuous: '
            . substr((string) $response->getContent(), 0, 300)
        );

        $this->assertArrayNotHasKey(
            'cost_price',
            is_array($payload) ? $payload : [],
            'ViewProduct SERVED cost_price TO A PRINCIPAL THAT ProductList REFUSES. That field is '
            . 'the store\'s margin; its sibling endpoint gates it on Manage Products and this one '
            . 'did not. This is P1-SEC-46. Payload: ' . json_encode($payload)
        );
    }

    /**
     * POSITIVE CONTROL: the merchant who DOES hold the permission still gets the figure, so the
     * fix did not simply delete the field for everyone.
     */
    public function test_view_product_still_serves_cost_price_to_a_permitted_principal(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');
        $product = $this->productIn($store, 42.00);

        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . \App\Models\User::find($merchant->id)->createToken('auth_token')->plainTextToken
        )->getJson('/api/admin/viewproduct?store_id=' . $store->id . '&product_id=' . $product->id);

        $payload = $response->json('data.Product') ?? [];

        $this->assertArrayHasKey(
            'cost_price',
            is_array($payload) ? $payload : [],
            'the permitted merchant no longer receives cost_price at all, so the gate has turned '
            . 'the field off rather than scoping it. Payload: ' . json_encode($payload)
        );

        $this->assertEqualsWithDelta(
            42.0,
            (float) $payload['cost_price'],
            0.01,
            'the served cost_price is not the stored one'
        );
    }

    private function makeDriverFor($store, string $email): \App\Models\DeliveryBoy
    {
        $driver = new \App\Models\DeliveryBoy();
        $driver->forceFill([
            'name' => 'ORACLE-FIXTURE',
            'email' => $email,
            'password' => \Illuminate\Support\Facades\Hash::make('driver-password-fixture'),
            'contact' => '0100000000',
            'profile_image' => 'uploads/profile/avatar.png',
            'type' => 'deliveryboy',
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ])->save();

        return $driver;
    }
}
