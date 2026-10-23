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

    /**
     * CRITERION 4: "the legitimate journey is byte-identical for the order's owner" -- the one the
     * read-only audit left UNPROVEN, and it needs measuring rather than asserting, because
     * criterion 3 (derive the link server-side) and criterion 4 (change nothing for the owner) pull
     * against each other: if the client used to POST the link and the server now computes it, the
     * two are only identical where they agree.
     *
     * WHAT THE SHIPPED CLIENT ACTUALLY POSTED. One element in the whole tree carries the
     * `data-value` this handler read: refund_order.blade.php:104,
     * `data-value="{{ asset($download_prod->downloadable_product) }}"`. (order_view.blade.php
     * binds the same class but renders no element carrying it -- its own download anchors are
     * plain hrefs.) So the comparison is `asset($path)` against the server's `get_file($path)`.
     *
     * MEASURED, both limbs:
     *   - on LOCAL storage, which is the default and what `storage_setting` is in this fixture,
     *     `get_file()` falls to `url($path)` (helper.php:198-200) and the two strings are EQUAL.
     *     So the owner's journey is byte-identical, and that is asserted below rather than argued.
     *   - on s3 or wasabi they DIFFER, and the server's value is the correct one: `get_file()`
     *     returns the bucket URL while `asset()` returns the app host, where the file is not. So
     *     for those deployments the fix CORRECTS the link instead of preserving it -- which is a
     *     better outcome than byte-identical and is recorded here so the criterion is not read as
     *     unconditionally met.
     *
     * The same element proves the point on its own: its `href` at :103 is `get_file(...)` while the
     * `data-value` it posted was `asset(...)`. The view already disagreed with itself about which
     * URL the file has; the server now answers once.
     */
    public function test_the_owners_mailed_link_is_what_the_shipped_view_posted(): void
    {
        Mail::fake();
        [, $store] = $this->makeTenant();
        $this->mailSettings($store);
        $owner = $this->shopper($store);
        [$order] = $this->deliveredOrder($store, $owner, 'owner-buyer@example.test');

        $path = 'uploads/' . $store->id . '/files/ebook.pdf';

        // The premise: this deployment is on local storage, where the two helpers agree.
        $this->assertSame(
            asset($path),
            get_file($path),
            'get_file() and asset() no longer agree on local storage, so the byte-identical claim '
            . 'for the owner\'s journey needs re-deriving -- the shipped view posted asset().'
        );

        $response = $this->ask($owner, $store, $order->id);

        $this->assertSame('success', $response->json('status'), $response->getContent());

        Mail::assertSent(ProdcutMail::class, function (ProdcutMail $mail) use ($path) {
            return (string) $mail->product_link === asset($path);
        });

        // And the response SHAPE is the handler's own, unchanged: the caller has a `success:`
        // handler only and branches on data.status, so the three keys and their values matter.
        $this->assertSame(
            ['status' => 'success', 'msg' => 'Please check your email', 'message' => 'successfully send'],
            $response->json()
        );
    }

    /**
     * THE OTHER LIMB, stated as a difference rather than left implied: on remote storage the
     * server's link is NOT what the view posted, and that is the point.
     */
    public function test_on_remote_storage_the_server_corrects_the_link_the_view_would_have_posted(): void
    {
        [, $store] = $this->makeTenant();

        /*
         * AN OPERATOR HAS TO EXIST, and the first run of this test failed for exactly that reason:
         * makeTenant() builds merchants (type 'admin'), and `get_file()` reads the SUPER ADMIN's
         * store settings (helper.php:143-163). With no operator row it falls to local storage --
         * the documented null guard -- so the s3 limb would have measured nothing.
         */
        $admin = $this->makePlatformAdmin(['current_store' => $store->id]);

        foreach ([
            'storage_setting' => 's3', 's3_key' => 'k', 's3_secret' => 's', 's3_region' => 'us-east-1', 's3_bucket' => 'bucket-x',
        ] as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value, 'store_id' => $admin->current_store,
                'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        (new \App\Models\Setting())->flushCache();

        $path = 'uploads/' . $store->id . '/files/ebook.pdf';
        $derived = get_file($path);

        $this->assertNotSame(asset($path), $derived,
            'On s3 the two helpers must differ -- if they agree, get_file() is no longer resolving the bucket.');
        $this->assertStringContainsString('bucket-x', $derived,
            'get_file() did not resolve the s3 bucket, so the "server corrects the link" claim does not hold.');
        $this->assertStringNotContainsString('bucket-x', asset($path),
            'asset() names the bucket, which would make the old posted value correct after all.');
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

    /**
     * THE STORE PREDICATE ON THE PRODUCT READ, which a mutation run showed was pinned by nothing.
     *
     * `downloadLinkFor()` is only reached for an order already proved to be this store's and this
     * shopper's, so the predicate looks redundant -- and it is not, because the order's
     * `product_json` is a JSON blob of product IDS, not a join. An order line naming ANOTHER
     * store's product is exactly the shape this project has already recorded live
     * (ForeignProductCartChainTest: a foreign product reaching a cart and then an order), and
     * without the predicate the mail would carry that store's downloadable file URL -- a
     * cross-tenant leak of a paid digital product, to the attacker's own billing address.
     */
    public function test_a_line_naming_another_stores_downloadable_product_is_not_mailed(): void
    {
        Mail::fake();
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->mailSettings($storeA);
        $owner = $this->shopper($storeA);

        $foreignProduct = DB::table('products')->insertGetId([
            'name' => 'Their Ebook', 'slug' => 'their-ebook-' . uniqid(), 'store_id' => $storeB->id, 'track_stock' => 0,
            'created_by' => $storeB->created_by, 'downloadable_product' => 'uploads/' . $storeB->id . '/files/secret.pdf',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = $this->makeOrder($storeA, [
            'customer_id' => $owner->id, 'is_guest' => 0, 'delivered_status' => 1,
            'product_json' => json_encode([['product_id' => $foreignProduct, 'name' => 'Their Ebook', 'variant_id' => 0, 'qty' => 1, 'final_price' => 10]]),
        ]);

        DB::table('order_billing_details')->insert(['order_id' => $order->id, 'product_order_id' => $order->product_order_id,
            'first_name' => 'Buyer', 'last_name' => 'X', 'email' => 'attacker-buyer@example.test', 'telephone' => '5550001',
            'address' => 'Street', 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->ask($owner, $storeA, $order->id);

        Mail::assertNotSent(ProdcutMail::class, fn (ProdcutMail $mail) => str_contains((string) $mail->product_link, 'secret.pdf'));
        $this->assertSame('error', $response->json('status'),
            "A line naming another store's downloadable product was treated as this order's own file.");
    }

    /**
     * AND THE HANDLER USES THE STORAGE-AWARE HELPER, not a bare `asset()`.
     *
     * A mutant swapping `get_file()` for `asset()` SURVIVED on local storage -- correctly, because
     * the two are equal there, which the byte-identical test above asserts. The separating
     * deployment is remote storage, and this drives the whole handler with s3 configured: the
     * mailed link must name the bucket, as `get_file()` resolves it, because that is where the file
     * actually is. With `asset()` the customer would receive a link to the app host and a 404.
     */
    public function test_on_remote_storage_the_mailed_link_names_the_bucket(): void
    {
        Mail::fake();
        [, $store] = $this->makeTenant();
        $this->mailSettings($store);
        $owner = $this->shopper($store);
        [$order] = $this->deliveredOrder($store, $owner, 'owner-buyer@example.test');

        $admin = $this->makePlatformAdmin(['current_store' => $store->id]);

        foreach ([
            'storage_setting' => 's3', 's3_key' => 'k', 's3_secret' => 's', 's3_region' => 'us-east-1', 's3_bucket' => 'bucket-x',
        ] as $name => $value) {
            DB::table('settings')->insert(['name' => $name, 'value' => $value, 'store_id' => $admin->current_store,
                'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        (new \App\Models\Setting())->flushCache();

        $response = $this->ask($owner, $store, $order->id);

        $this->assertSame('success', $response->json('status'), $response->getContent());

        Mail::assertSent(ProdcutMail::class, function (ProdcutMail $mail) {
            return str_contains((string) $mail->product_link, 'bucket-x')
                && str_contains((string) $mail->product_link, 'ebook.pdf');
        });
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
