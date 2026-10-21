<?php

namespace Tests\Feature\Security;

use App\Mail\ProdcutMail;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * P1-ORD-10 -- `downloadable_prodcut` LET ANY SIGNED-IN SHOPPER MAKE ANY STORE E-MAIL ITS OWN CUSTOMER AN ATTACKER-CHOSEN LINK.
 * ===============================================================================================================================
 *
 * `POST {storeSlug}/downloadable_prodcut` read the order from a raw request integer (no customer, no store predicate -- Order is in no
 * scoped_models list), loaded the SMTP credentials of the ORDER'S store, mailed the order's billing address and put `$request['download_product']`
 * in the message as a link. Measured by P1-SEC-03 round 1 with Mail::fake: a store-A shopper naming store B's order produced one ProdcutMail
 * to the victim's customer, over the victim merchant's own relay, carrying the attacker's URL and the victim's real order number. Nothing is
 * exfiltrated (the digital file is never derived from the order); it is a phishing primitive from a genuine merchant.
 *
 * ORACLE: Mail::fake() -- what was queued/sent and to whom, with the link rendered in the message. The MAIL_* rows are seeded and the Setting
 * model cache flushed (Setting is Cachable: a raw-inserted row is invisible until flushed, and the first run answered "SMTP configuration"
 * for attacker AND owner alike, which reads as a route broken for everyone).
 */
class DownloadableProductMailTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const ATTACKER_LINK = 'https://attacker.example.test/phish';

    private function shopper(Store $store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shop', 'last_name' => 'Per', 'email' => 'shopper' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'), 'store_id' => $store->id, 'status' => 1,
            'billable_id' => $store->created_by, 'billable_type' => \App\Models\User::class,
        ]);
    }

    private function mailSettings(Store $store): void
    {
        foreach (['MAIL_DRIVER' => 'smtp', 'MAIL_HOST' => 'smtp.example.test', 'MAIL_PORT' => '587', 'MAIL_ENCRYPTION' => 'tls', 'MAIL_USERNAME' => 'u',
            'MAIL_PASSWORD' => 'p', 'MAIL_FROM_ADDRESS' => 'shop@example.test', 'MAIL_FROM_NAME' => 'Shop'] as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value, 'store_id' => $store->id, 'created_by' => $store->created_by, 'created_at' => now(), 'updated_at' => now()]);
        }

        (new \App\Models\Setting())->flushCache();
    }

    /** A delivered order whose single product carries a downloadable file. */
    private function deliveredOrder(Store $store, Customer $owner, string $billingEmail): array
    {
        $product = DB::table('products')->insertGetId([
            'name' => 'Ebook', 'slug' => 'ebook-' . uniqid(), 'store_id' => $store->id, 'track_stock' => 0, 'created_by' => $store->created_by,
            'downloadable_product' => 'uploads/' . $store->id . '/files/ebook.pdf', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = $this->makeOrder($store, [
            'customer_id' => $owner->id, 'is_guest' => 0, 'delivered_status' => 1,
            'product_json' => json_encode([['product_id' => $product, 'name' => 'Ebook', 'variant_id' => 0, 'qty' => 1, 'final_price' => 10]]),
        ]);

        DB::table('order_billing_details')->insert(['order_id' => $order->id, 'product_order_id' => $order->product_order_id, 'first_name' => 'Buyer', 'last_name' => 'X',
            'email' => $billingEmail, 'telephone' => '5550001', 'address' => 'Street', 'created_at' => now(), 'updated_at' => now()]);

        return [$order, $product];
    }

    private function ask(Customer $as, Store $slug, $orderId, array $extra = [])
    {
        return $this->actingAs($as, 'customers')->postJson('/' . $slug->slug . '/downloadable_prodcut', array_merge(['order_id' => $orderId, 'download_product' => self::ATTACKER_LINK], $extra));
    }

    public function test_a_shopper_of_another_store_cannot_make_a_store_mail_its_customer(): void
    {
        Mail::fake();
        [, $storeB] = $this->makeTenant();
        [, $storeA] = $this->makeTenant();
        $this->mailSettings($storeB);
        $victimCustomer = $this->shopper($storeB);
        [$victimOrder] = $this->deliveredOrder($storeB, $victimCustomer, 'victim-buyer@example.test');
        $attacker = $this->shopper($storeA);

        $response = $this->ask($attacker, $storeA, $victimOrder->id);

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->assertSame('error', $response->json('status'));
    }

    public function test_the_attackers_own_store_slug_does_not_help(): void
    {
        Mail::fake();
        [, $storeB] = $this->makeTenant();
        $this->mailSettings($storeB);
        $victimCustomer = $this->shopper($storeB);
        [$victimOrder] = $this->deliveredOrder($storeB, $victimCustomer, 'victim-buyer@example.test');
        $sibling = $this->shopper($storeB);

        $this->ask($sibling, $storeB, $victimOrder->id);

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_an_unknown_order_is_an_error_not_a_crash(): void
    {
        Mail::fake();
        [, $store] = $this->makeTenant();
        $this->mailSettings($store);
        $shopper = $this->shopper($store);

        $response = $this->ask($shopper, $store, 9999999);

        Mail::assertNothingSent();
        $this->assertNotSame(500, $response->status());
        $this->assertSame('error', $response->json('status'));
    }

    public function test_the_owner_gets_their_own_mail_with_a_link_the_server_derived(): void
    {
        Mail::fake();
        [, $store] = $this->makeTenant();
        $this->mailSettings($store);
        $owner = $this->shopper($store);
        [$order, $product] = $this->deliveredOrder($store, $owner, 'owner-buyer@example.test');

        $response = $this->ask($owner, $store, $order->id);

        $this->assertSame('success', $response->json('status'), 'Premise: the legitimate journey works: ' . $response->getContent());
        Mail::assertSent(ProdcutMail::class, function (ProdcutMail $mail) use ($store) {
            return $mail->hasTo('owner-buyer@example.test')
                && $mail->product_link !== self::ATTACKER_LINK
                && str_contains((string) $mail->product_link, 'uploads/' . $store->id . '/files/ebook.pdf');
        });
        Mail::assertSent(ProdcutMail::class, 1);
    }

    public function test_a_request_supplied_link_never_reaches_a_sent_mail(): void
    {
        Mail::fake();
        [, $store] = $this->makeTenant();
        $this->mailSettings($store);
        $owner = $this->shopper($store);
        [$order] = $this->deliveredOrder($store, $owner, 'owner-buyer@example.test');

        $this->ask($owner, $store, $order->id, ['download_product' => 'javascript:alert(1)']);

        Mail::assertNotSent(ProdcutMail::class, fn (ProdcutMail $mail) => str_contains((string) $mail->product_link, 'javascript:') || str_contains((string) $mail->product_link, 'attacker'));
    }

    public function test_an_order_with_nothing_downloadable_sends_nothing(): void
    {
        Mail::fake();
        [, $store] = $this->makeTenant();
        $this->mailSettings($store);
        $owner = $this->shopper($store);
        $order = $this->makeOrder($store, ['customer_id' => $owner->id, 'is_guest' => 0, 'delivered_status' => 1,
            'product_json' => json_encode([['product_id' => 777, 'name' => 'Plain', 'variant_id' => 0, 'qty' => 1, 'final_price' => 10]])]);
        DB::table('order_billing_details')->insert(['order_id' => $order->id, 'product_order_id' => $order->product_order_id, 'first_name' => 'Buyer', 'last_name' => 'X',
            'email' => 'owner-buyer@example.test', 'telephone' => '5550001', 'address' => 'Street', 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->ask($owner, $store, $order->id);

        Mail::assertNothingSent();
        $this->assertSame('error', $response->json('status'));
    }

    public function test_a_variants_own_file_is_the_one_mailed_and_a_foreign_variant_is_not_used(): void
    {
        Mail::fake();
        [, $store] = $this->makeTenant();
        [, $other] = $this->makeTenant();
        $this->mailSettings($store);
        $owner = $this->shopper($store);

        $product = DB::table('products')->insertGetId(['name' => 'Shirt', 'slug' => 'shirt-' . uniqid(), 'store_id' => $store->id, 'track_stock' => 0,
            'created_by' => $store->created_by, 'created_at' => now(), 'updated_at' => now()]);
        $variant = DB::table('product_variants')->insertGetId(['product_id' => $product, 'variant' => 'pdf', 'sku' => 'v-' . uniqid(), 'price' => 1, 'stock' => 1,
            'store_id' => $store->id, 'downloadable_product' => 'uploads/' . $store->id . '/files/variant.pdf', 'variation_option' => 'x', 'stock_status' => 'in_stock',
            'created_at' => now(), 'updated_at' => now()]);
        // another store's variant id on the same order line must not be followed
        $foreign = DB::table('product_variants')->insertGetId(['product_id' => $product, 'variant' => 'evil', 'sku' => 'v-' . uniqid(), 'price' => 1, 'stock' => 1,
            'store_id' => $other->id, 'downloadable_product' => 'uploads/' . $other->id . '/files/secret.pdf', 'variation_option' => 'x', 'stock_status' => 'in_stock',
            'created_at' => now(), 'updated_at' => now()]);

        $order = $this->makeOrder($store, ['customer_id' => $owner->id, 'is_guest' => 0, 'delivered_status' => 1,
            'product_json' => json_encode([['product_id' => $product, 'name' => 'Shirt', 'variant_id' => $foreign, 'qty' => 1, 'final_price' => 10]])]);
        DB::table('order_billing_details')->insert(['order_id' => $order->id, 'product_order_id' => $order->product_order_id, 'first_name' => 'Buyer', 'last_name' => 'X',
            'email' => 'owner-buyer@example.test', 'telephone' => '5550001', 'address' => 'Street', 'created_at' => now(), 'updated_at' => now()]);

        $this->ask($owner, $store, $order->id);
        Mail::assertNothingSent();   // the foreign variant is skipped and the product itself has no file

        DB::table('orders')->where('id', $order->id)->update(['product_json' => json_encode([['product_id' => $product, 'name' => 'Shirt', 'variant_id' => $variant, 'qty' => 1, 'final_price' => 10]])]);
        (new Order())->flushCache();   // Order is Cachable: the raw update above would otherwise be invisible to the handler's own read
        $this->ask($owner, $store, $order->id);

        Mail::assertSent(ProdcutMail::class, fn (ProdcutMail $mail) => str_contains((string) $mail->product_link, 'uploads/' . $store->id . '/files/variant.pdf'));
    }

    /** Defence in depth: an order that names this customer but sits in ANOTHER store is not answered through this store's slug (the mail would go out over that other store's relay). */
    public function test_an_order_recorded_in_another_store_is_refused_even_if_it_names_this_customer(): void
    {
        Mail::fake();
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->mailSettings($storeB);
        $customer = $this->shopper($storeA);
        [$crossStoreOrder] = $this->deliveredOrder($storeB, $customer, 'cross-store@example.test');

        $this->ask($customer, $storeA, $crossStoreOrder->id);

        Mail::assertNothingSent();
    }

    public function test_a_merchant_session_cannot_use_the_shoppers_route(): void
    {
        Mail::fake();
        [$merchant, $store] = $this->makeTenant();
        $this->mailSettings($store);
        $owner = $this->shopper($store);
        [$order] = $this->deliveredOrder($store, $owner, 'owner-buyer@example.test');

        $this->actingAs($merchant->fresh())->postJson('/' . $store->slug . '/downloadable_prodcut', ['order_id' => $order->id, 'download_product' => self::ATTACKER_LINK]);

        Mail::assertNothingSent();
    }
}
