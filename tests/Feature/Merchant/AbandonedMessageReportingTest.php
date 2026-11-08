<?php

namespace Tests\Feature\Merchant;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\WhatsappMessage;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2.5-PKG-K — "Invalid Auth access token" is what the merchant is told, whatever happened.
 * ================================================================================================
 *
 * THE OWNER ASKED WHICH IT IS: are the messages truly sent while the UI reports failure, or does
 * delivery fail? REPRODUCED through both real workflows, with no network and no real message --
 * and that claim is now true of EVERY case here, which it was not in the first version. An
 * independent review found that the two cases which configure WhatsApp drove the real
 * `Utility::SendMsgs()` into `curl_exec` against graph.facebook.com, four times per Feature
 * run. Their assertions passed either way, because `SendMsgs()` discards the response, so the
 * RESULT looked identical and only reading the path showed it. Every request that can reach
 * the send leg now runs behind `withoutSending()`, and the configured case asserts that the
 * double -- not the gateway -- is what received the message.
 *
 *   DELIVERY FAILS, ALWAYS — and the reporting would be wrong even if it did not.
 *
 * Three independent faults, each sufficient on its own:
 *
 *   1. THE RECIPIENT IS NOT A PHONE NUMBER. Both handlers pass `$cart->UserData` — the `hasOne`
 *      RELATION, an Eloquent model — where `Utility::SendMsgs()` expects `$mobile_no`. It does
 *      not throw: `Illuminate\Database\Eloquent\Model::__toString()` is `toJson()`, so the
 *      WhatsApp `to` field becomes the customer's whole record serialised as JSON:
 *
 *        +{"first_name":"Nour","last_name":"Saleh","email":"...","mobile":"963900000001", ...}
 *
 *      WhatsApp cannot deliver that, and on a store with real credentials it POSTs the shopper's
 *      name, e-mail, mobile, address and postcode to graph.facebook.com in the recipient field.
 *      `password`, `remember_token` and `password_otp` are NOT in it — P1-SEC-16 put them in
 *      `$hidden` — so this is personal data, not credentials. Stated precisely because the
 *      difference decides how it is handled.
 *
 *   2. `Utility::SendMsgs()` HAS NO RETURN STATEMENT. Measured: it returns `null` on every path
 *      that does not throw, whether the curl ran or the whole block was skipped. Both handlers
 *      then ask `if ($resp == false)`, and `null == false` is true in PHP, so the failure branch
 *      is taken 100% of the time. A genuinely delivered message would be reported as a failure.
 *
 *   3. THE REASON IS A HARDCODED STRING. Both handlers answer
 *      "Invalid Auth access token - Cannot parse access token" for every outcome. Measured: the
 *      response is BYTE-IDENTICAL whether WhatsApp is entirely unconfigured or fully configured
 *      with an active template. The merchant is told their token is broken when it may be fine,
 *      and is given nothing that would let them fix the real cause.
 *
 * WHAT THIS SLICE DOES NOT DO. `SendMsgs()` still discards the gateway's response, so after the
 * repair the handlers can honestly say the message was HANDED TO WhatsApp and cannot say it was
 * delivered. Changing that is a change to the shared contract — it is called from the payment
 * flows in PaymentService, PaymentController, ApiController, PayU and AuthorizeNet — and §3 of the
 * owner's direction reserves it. The wording the repair uses is therefore "queued", not "sent",
 * and this file asserts that distinction rather than letting a cheerful lie replace a gloomy one.
 */
class AbandonedMessageReportingTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const CART_ENDPOINT = '/abandon-carts-messagesend';
    private const WISH_ENDPOINT = '/abandon-wishlist-message-send';

    /** The hardcoded diagnosis both handlers gave for every outcome. */
    private const FALSE_DIAGNOSIS = 'Invalid Auth access token - Cannot parse access token';

    /** @return array{0: \App\Models\User, 1: \App\Models\Store, 2: Customer} */
    private function tenantWithShopper(string $mobile = '963900000001'): array
    {
        [$owner, $store] = $this->makeTenant();

        $customer = Customer::forceCreate([
            'first_name' => 'Nour', 'last_name' => 'Saleh',
            'email' => 'shopper' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'store_id' => $store->id,
            'mobile' => $mobile,
        ]);

        $this->grantPermission($owner, 'Abandon Cart');

        return [$owner, $store, $customer];
    }

    /** `wishlists.product_id` carries a FOREIGN KEY, so an invented id is refused by the engine. */
    private function makeProductIn(int $storeId, int $createdBy): Product
    {
        $name = 'Nudge Item ' . strtoupper(bin2hex(random_bytes(4)));
        $catName = 'Nudge Cat ' . uniqid();

        $category = Category::forceCreate([
            'name' => $catName,
            'slug' => \Illuminate\Support\Str::slug($catName) . '-' . uniqid(),
            'parent_id' => 0, 'trending' => 0, 'status' => 1, 'store_id' => $storeId,
        ]);

        return Product::forceCreate([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'description' => 'x', 'price' => 10, 'track_stock' => 0, 'trending' => 0,
            'stock_status' => 'in_stock', 'product_stock' => 5, 'product_type' => null,
            'status' => 1, 'variant_product' => 0,
            'category_id' => $category->id,
            'store_id' => $storeId, 'created_by' => $createdBy,
        ]);
    }

    private function cartFor(Customer $customer): Cart
    {
        $product = $this->makeProductIn((int) $customer->store_id, 1);

        return Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 5, 'store_id' => $customer->store_id,
        ]);
    }

    private function wishlistFor(Customer $customer): Wishlist
    {
        $product = $this->makeProductIn((int) $customer->store_id, 1);

        return Wishlist::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id,
            'store_id' => $customer->store_id,
        ]);
    }

    /**
     * Run a request with the dispatcher's SEND leg replaced, so nothing leaves this machine.
     *
     * F1 FROM THE INDEPENDENT REVIEW, AND IT WAS RIGHT. The first version of this file installed
     * the double only in `captureRecipients()`, so the two cases that configure WhatsApp and then
     * post drove the REAL `Utility::SendMsgs()` -- which reached `curl_exec` against
     * graph.facebook.com with `Authorization: Bearer FAKE-TOKEN-NEVER-REAL`, twice each across the
     * two-row provider: four outbound requests per Feature run, with no `CURLOPT_TIMEOUT` and no
     * `CURLOPT_CONNECTTIMEOUT` set, so a runner that blackholes 443 could block for cURL's default
     * 300s each time. The assertions passed either way, because `SendMsgs()` discards the response
     * -- so this was invisible in the result and visible only by reading the path.
     *
     * The owner's instruction was "use mocks/fakes to avoid sending real messages". This is that,
     * applied to EVERY case that can reach the send leg rather than only the one that inspects it.
     */
    /** The double installed by the most recent `withoutSending()`, so a case can inspect it. */
    private ?\App\Services\AbandonedMessageDispatch $lastRecorder = null;

    private function withoutSending(callable $run): object
    {
        $recorder = $this->recorder();
        $this->lastRecorder = $recorder;
        $this->app->instance(\App\Services\AbandonedMessageDispatch::class, $recorder);

        try {
            return $run();
        } finally {
            $this->app->forgetInstance(\App\Services\AbandonedMessageDispatch::class);
        }
    }

    /** `handOver` records instead of sending; `blockedReason` stays the real one under test. */
    private function recorder(): \App\Services\AbandonedMessageDispatch
    {
        return new class extends \App\Services\AbandonedMessageDispatch {
            /** @var array<int, array{template: string, to: string, message: string}> */
            public array $recorded = [];

            public function handOver(string $template, string $mobile, string $message): void
            {
                $this->recorded[] = ['template' => $template, 'to' => $mobile, 'message' => $message];
            }
        };
    }

    /** Give the store a working WhatsApp configuration, with the template switched on. */
    private function configureWhatsapp(\App\Models\User $owner, \App\Models\Store $store, string $template): void
    {
        WhatsappMessage::forceCreate([
            'name' => $template, 'from' => 'StoreSyria', 'user_id' => $owner->id,
            'is_active' => 1, 'store_id' => $store->id, 'created_by' => $owner->id,
        ]);

        DB::table('settings')->insert([
            ['name' => 'whatsapp_phone_number_id', 'value' => '1234567890',
             'store_id' => $store->id, 'created_by' => $owner->id],
            ['name' => 'whatsapp_access_token', 'value' => 'FAKE-TOKEN-NEVER-REAL',
             'store_id' => $store->id, 'created_by' => $owner->id],
        ]);

        \Cache::flush();
    }

    // =============================================================================================
    //  1 — the recipient field
    // =============================================================================================

    /**
     * The premise the whole slice rests on: an Eloquent model passed where a string is wanted does
     * NOT throw, it stringifies to JSON. If Laravel ever drops `Model::__toString()` this becomes a
     * TypeError instead and the handlers fail loudly rather than quietly, which is a different bug.
     */
    public function test_premise_an_eloquent_model_stringifies_to_its_json(): void
    {
        $customer = new Customer(['first_name' => 'Nour', 'mobile' => '963900000001']);

        $rendered = (string) $customer;

        $this->assertJson($rendered);
        $this->assertStringContainsString('963900000001', $rendered);
        $this->assertStringContainsString('Nour', $rendered);
    }

    /** And the shopper's record is what would have been POSTed as the recipient. */
    public function test_premise_the_customer_json_carries_personal_data_but_not_credentials(): void
    {
        [, , $customer] = $this->tenantWithShopper();

        $rendered = (string) $customer->fresh();

        $this->assertStringContainsString($customer->email, $rendered);
        $this->assertStringContainsString('963900000001', $rendered);

        // P1-SEC-16 put these in $hidden; the finding is personal data, not credentials
        foreach (['password', 'remember_token', 'password_otp'] as $secret) {
            $this->assertStringNotContainsString('"' . $secret . '"', $rendered);
        }
    }

    /**
     * @dataProvider flows
     */
    public function test_the_recipient_is_the_shoppers_phone_number_not_their_record(string $endpoint, string $key, string $template): void
    {
        [$owner, $store, $customer] = $this->tenantWithShopper();
        $this->configureWhatsapp($owner, $store, $template);
        $row = $endpoint === self::CART_ENDPOINT ? $this->cartFor($customer) : $this->wishlistFor($customer);

        $recipients = $this->captureRecipients(function () use ($owner, $endpoint, $key, $row) {
            $this->actingAs($owner)->postJson($endpoint, [$key => $row->id]);
        });

        $this->assertNotEmpty($recipients,
            'the handler did not reach the dispatcher at all, so this case proves nothing');

        foreach ($recipients as $recipient) {
            $this->assertSame($customer->mobile, $recipient,
                'the dispatcher was handed ' . substr((string) $recipient, 0, 120)
                . ' instead of the shopper\'s mobile number. An Eloquent model stringifies to its '
                . 'JSON, so this is the shopper\'s record in the WhatsApp recipient field.');
        }
    }

    // =============================================================================================
    //  2 and 3 — what the merchant is told
    // =============================================================================================

    /**
     * THE DEFECT IN ONE CASE. Two stores, opposite configurations, byte-identical answers.
     *
     * @dataProvider flows
     */
    public function test_the_merchant_is_told_something_different_when_the_cause_is_different(string $endpoint, string $key, string $template): void
    {
        [$offOwner, , $offCustomer] = $this->tenantWithShopper();
        $offRow = $endpoint === self::CART_ENDPOINT ? $this->cartFor($offCustomer) : $this->wishlistFor($offCustomer);
        $unconfigured = $this->actingAs($offOwner)->postJson($endpoint, [$key => $offRow->id]);

        [$onOwner, $onStore, $onCustomer] = $this->tenantWithShopper();
        $this->configureWhatsapp($onOwner, $onStore, $template);
        $onRow = $endpoint === self::CART_ENDPOINT ? $this->cartFor($onCustomer) : $this->wishlistFor($onCustomer);
        $configured = $this->withoutSending(
            fn () => $this->actingAs($onOwner)->postJson($endpoint, [$key => $onRow->id]));

        $this->assertNotSame(
            $unconfigured->json('message'),
            $configured->json('message'),
            'the handler gives the same answer for an unconfigured store and a fully configured '
            . 'one, so the merchant cannot tell which of the two they are looking at'
        );
    }

    /**
     * @dataProvider flows
     */
    public function test_an_unconfigured_store_is_told_that_whatsapp_is_not_configured(string $endpoint, string $key): void
    {
        [$owner, , $customer] = $this->tenantWithShopper();
        $row = $endpoint === self::CART_ENDPOINT ? $this->cartFor($customer) : $this->wishlistFor($customer);

        $response = $this->actingAs($owner)->postJson($endpoint, [$key => $row->id]);

        $response->assertJson(['is_success' => false]);
        $this->assertNotSame(self::FALSE_DIAGNOSIS, $response->json('message'),
            'an unconfigured store is still told its access token cannot be parsed');
        $this->assertStringContainsStringIgnoringCase('whatsapp', (string) $response->json('message'),
            'the message does not name WhatsApp, so the merchant is not told where to go');
    }

    /**
     * @dataProvider flows
     */
    public function test_a_shopper_with_no_mobile_number_is_named_as_the_reason(string $endpoint, string $key, string $template): void
    {
        [$owner, $store, $customer] = $this->tenantWithShopper('');
        $this->configureWhatsapp($owner, $store, $template);
        $row = $endpoint === self::CART_ENDPOINT ? $this->cartFor($customer) : $this->wishlistFor($customer);

        $response = $this->withoutSending(
            fn () => $this->actingAs($owner)->postJson($endpoint, [$key => $row->id]));

        $response->assertJson(['is_success' => false]);
        $this->assertNotSame(self::FALSE_DIAGNOSIS, $response->json('message'));
        $this->assertStringContainsStringIgnoringCase('mobile', (string) $response->json('message'),
            'a shopper with no mobile number is reported as a token problem');
    }

    /**
     * @dataProvider flows
     */
    public function test_a_switched_off_template_is_named_as_the_reason(string $endpoint, string $key, string $template): void
    {
        [$owner, $store, $customer] = $this->tenantWithShopper();
        $this->configureWhatsapp($owner, $store, $template);
        WhatsappMessage::where('store_id', $store->id)->where('name', $template)
            ->update(['is_active' => 0]);
        \Cache::flush();

        $row = $endpoint === self::CART_ENDPOINT ? $this->cartFor($customer) : $this->wishlistFor($customer);
        $response = $this->withoutSending(
            fn () => $this->actingAs($owner)->postJson($endpoint, [$key => $row->id]));

        $response->assertJson(['is_success' => false]);
        $this->assertNotSame(self::FALSE_DIAGNOSIS, $response->json('message'));
        $this->assertStringContainsStringIgnoringCase('template', (string) $response->json('message'),
            'a switched-off template is reported as a token problem');
    }

    /**
     * THE REPAIR MUST NOT TRADE ONE LIE FOR ANOTHER. `SendMsgs()` discards the gateway's response,
     * so a configured store can only be told the message was HANDED OVER. The wording is asserted
     * because "sent successfully" would be exactly as untrue as the old failure message.
     *
     * @dataProvider flows
     */
    public function test_a_configured_store_is_told_the_message_was_queued_not_delivered(string $endpoint, string $key, string $template): void
    {
        [$owner, $store, $customer] = $this->tenantWithShopper();
        $this->configureWhatsapp($owner, $store, $template);
        $row = $endpoint === self::CART_ENDPOINT ? $this->cartFor($customer) : $this->wishlistFor($customer);

        $response = $this->withoutSending(
            fn () => $this->actingAs($owner)->postJson($endpoint, [$key => $row->id]));
        $message = strtolower((string) $response->json('message'));

        $response->assertJson(['is_success' => true]);

        /*
         * BOTH HALVES, because the absence half is not enough on its own -- the review pointed out
         * that "Message sent successfully" would pass a test that only forbids the word
         * "delivered", and that is exactly the cheerful lie this case exists to prevent.
         */
        $this->assertStringNotContainsString('delivered', $message,
            'the handler claims delivery, which it cannot know: SendMsgs discards the response');
        $this->assertStringContainsString('queued', $message,
            'the handler no longer says the message was QUEUED, so it is asserting more than it knows');

        /*
         * AND THE DOUBLE IS WHAT RECEIVED IT, not the gateway. Without this the case would pass
         * identically whether `handOver()` recorded or whether `Utility::SendMsgs()` POSTed to
         * graph.facebook.com -- which is exactly how the first version of this file sent four real
         * requests per run without anything going red.
         */
        $this->assertCount(1, $this->lastRecorder->recorded,
            'the configured case did not reach the recording double exactly once, so either the '
            . 'handler stopped handing the message over or the real sender ran');
    }

    /**
     * Nothing here may relax the tenant predicate the PKG-C slice put on these two handlers.
     *
     * THE WISHLIST LEG IS THE ONE WITH BITE, and the review is why that is stated rather than
     * implied. `Wishlist` registers no `StoreScope` and is not in `config/tenancy.php`'s
     * `scoped_models`, so `WishlistController`'s own `where('store_id', ...)` is the sole control:
     * delete it and this goes red. `Cart` IS allowlisted, and the attacker here resolves a tenant,
     * so the scope hides the victim's row whether or not the handler's predicate exists -- the
     * cart leg would stay green through a revert. The case that really pins the CART predicate
     * already exists and does it properly, with a STORELESS caller for whom the scope is inert:
     * Tests\Feature\Security\AbandonedCartSendStoreScopeTest. This is a guard against a
     * regression in the message changes, not a replacement for that file.
     */
    public function test_a_foreign_row_is_still_refused(): void
    {
        [, , $victimCustomer] = $this->tenantWithShopper();
        $victimCart = $this->cartFor($victimCustomer);
        $victimWish = $this->wishlistFor($victimCustomer);

        [$attacker] = $this->tenantWithShopper();

        $this->actingAs($attacker)->postJson(self::CART_ENDPOINT, ['cart_id' => $victimCart->id])
            ->assertStatus(404);
        $this->actingAs($attacker)->postJson(self::WISH_ENDPOINT, ['wish_id' => $victimWish->id])
            ->assertStatus(404);
    }

    public static function flows(): array
    {
        return [
            'abandoned cart' => [self::CART_ENDPOINT, 'cart_id', 'Abandon Cart'],
            'abandoned wishlist' => [self::WISH_ENDPOINT, 'wish_id', 'Abandon Cart'],
        ];
    }

    /**
     * Record every recipient handed to the dispatcher, WITHOUT SENDING ANYTHING.
     *
     * There is no seam inside `Utility::SendMsgs()` -- it calls `curl_*` directly, so `Http::fake()`
     * cannot see it, and adding one would change the shared contract this slice is not authorised
     * to touch. `AbandonedMessageDispatch` is resolved from the container precisely so a double can
     * stand in front of it: `handOver()` is overridden to record and NOT call `SendMsgs()`, while
     * `blockedReason()` is left exactly as the real class computes it, so the preconditions under
     * test are the real ones.
     */
    private function captureRecipients(callable $run): array
    {
        $recorder = $this->recorder();
        $this->app->instance(\App\Services\AbandonedMessageDispatch::class, $recorder);
        $run();
        $this->app->forgetInstance(\App\Services\AbandonedMessageDispatch::class);

        return array_column($recorder->recorded, 'to');
    }
}
