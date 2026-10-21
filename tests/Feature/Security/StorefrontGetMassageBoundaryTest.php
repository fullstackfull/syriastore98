<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * P1-SEC-21 -- two boundary defects in `PaymentController::getWhatsappUrl` (POST {slug}/get-massage),
 * the anonymous WhatsApp/Telegram message builder.
 * =============================================================================================
 *
 * (a) THE CART IT DELETES IS THE BODY'S. For every line of the caller's own cart the handler ran
 *     `Cart::where('customer_id', $request->customer_id)->where(product)->where(variant)->where(store)
 *     ->delete()`. On the guest branch nothing overwrites `customer_id`, so it is whatever was POSTED:
 *     a guest holding product P in their own cart could post `customer_id=<victim>` and remove the
 *     victim's basket line for P in that store. (The authenticated branch merges the stored state over
 *     the request, which is why only the guest/merchant/driver arm is open -- the same shape as the
 *     twenty-six handlers in S19.) For a legitimate guest the delete was a no-op: guest rows carry
 *     customer_id 0, never NULL.
 *
 * (b) THE MESSAGE TEMPLATE IS ANOTHER TENANT'S. `getAdminAllSetting()` with no arguments resolves the user
 *     from `auth()->user()` -- the `web` guard, null for every storefront shopper -- and falls through to
 *     `User::where('type','admin')->first()`, i.e. the lowest-id merchant on the platform. So
 *     `whatsapp_content`, the text of every store's WhatsApp/Telegram order message, was the FIRST
 *     merchant's, whoever's store the shopper was in. For WhatsApp that text is returned to the browser in
 *     the wa.me link.
 *
 * Both are driven through the real route with the session state processOrder() leaves behind.
 * Assertions on carts read the raw table: Cart is store-scoped and a model read answers for the resolved
 * tenant, not for the table.
 */
class StorefrontGetMassageBoundaryTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function tenant(string $template): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'gm-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        foreach ([
            'stock_management' => 'on', 'out_of_stock_threshold' => '0', 'notification' => '[]',
            'whatsapp_number' => '963900000000',
            'whatsapp_item_variable' => '{quantity} x {product_name}',
            'whatsapp_content' => $template,
        ] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$merchant, $store];
    }

    private function productIn($store): Product
    {
        return Product::forceCreate([
            'name' => 'gm-' . uniqid(), 'slug' => 'gm-' . uniqid(),
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 100.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function guestCart($store, Product $product): string
    {
        $sid = 'gm-cookie-' . uniqid();

        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id, 'cookie_session_id' => $sid,
        ]);

        return $sid;
    }

    private function state($store): array
    {
        return [
            'slug' => $store->slug, 'store_id' => $store->id, 'customer_id' => '',
            'payment_type' => 'whatsapp', 'shipping_final_price' => 0, 'tax_price' => 0,
            'cartlist_final_price' => 100.00,
            'billing_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
                'billing_user_telephone' => '123', 'billing_address' => 'x', 'billing_postecode' => '1',
                'billing_country' => '101', 'billing_state' => '1', 'billing_city' => '1',
                'delivery_address' => 'x', 'delivery_postcode' => '1',
                'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
            ],
        ];
    }

    private function getMassage($store, string $sid, array $extra = [], string $type = 'whatsapp')
    {
        return $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $this->state($store)])
            ->post('/' . $store->slug . '/get-massage', $extra + ['data' => ['type' => $type, 'order_id' => 'ORD-1']]);
    }

    private function victimIn($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Victim', 'last_name' => 'Shopper',
            'email' => 'victim-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    private static function cartLinesOf(int $customerId, int $productId): int
    {
        return (int) DB::table('carts')->where('customer_id', $customerId)->where('product_id', $productId)->count();
    }

    // ---------------------------------------------------------------------------------------------
    // (a) the cart rows it deletes
    // ---------------------------------------------------------------------------------------------

    private function victimLineAndAttackerCart(): array
    {
        [, $store] = $this->tenant('Order {order_no}: {item_variable}');
        $product = $this->productIn($store);
        $victim = $this->victimIn($store);

        Cart::forceCreate([
            'customer_id' => $victim->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 2, 'price' => 200.00, 'store_id' => $store->id,
        ]);

        return [$store, $product, $victim, $this->guestCart($store, $product)];
    }

    /** THE PREMISE: the request reaches the loop (HTTP 200 with a wa.me link), so the deletion line was executed. */
    public function test_a_plain_guest_request_builds_its_link(): void
    {
        [$store, , $victim, $sid] = $this->victimLineAndAttackerCart();

        $response = $this->getMassage($store, $sid);

        $response->assertOk();
        $this->assertStringStartsWith('https://api.whatsapp.com/send?phone=963900000000', (string) $response->getContent());
        $this->assertSame(1, self::cartLinesOf($victim->id, DB::table('carts')->where('customer_id', $victim->id)->value('product_id')),
            'A request that names no customer touched the victim\'s basket.');
    }

    public function test_a_guest_cannot_delete_another_customers_cart_line_by_posting_their_id(): void
    {
        [$store, $product, $victim, $sid] = $this->victimLineAndAttackerCart();

        $this->assertSame(1, self::cartLinesOf($victim->id, $product->id), 'Premise: the victim has the line.');

        $response = $this->getMassage($store, $sid, ['customer_id' => $victim->id]);

        $response->assertOk();
        $this->assertStringStartsWith('https://api.whatsapp.com/send', (string) $response->getContent(), 'Premise: the handler ran to its end.');
        $this->assertSame(1, self::cartLinesOf($victim->id, $product->id), 'A guest deleted another customer\'s basket line by posting their customer_id.');
    }

    /**
     * The guard on the fix itself. A guest has no customer id; deleting "where customer_id = 0" would
     * remove EVERY guest's basket line for the product -- a worse hole than the one being closed.
     */
    public function test_one_guests_request_does_not_clear_another_guests_basket(): void
    {
        [, $store] = $this->tenant('Order {order_no}: {item_variable}');
        $product = $this->productIn($store);

        $mine = $this->guestCart($store, $product);
        $theirs = $this->guestCart($store, $product);

        $response = $this->getMassage($store, $mine);

        $this->assertStringStartsWith('https://api.whatsapp.com/send', (string) $response->getContent(), 'Premise: the handler ran to its end.');
        $this->assertSame(1, (int) DB::table('carts')->where('cookie_session_id', $theirs)->count(),
            'A guest\'s request deleted another guest\'s basket line.');
    }

    /** The legitimate case the line exists for: a logged-in customer's OWN lines are still cleared. */
    public function test_a_logged_in_customer_still_clears_their_own_lines(): void
    {
        [, $store] = $this->tenant('Order {order_no}: {item_variable}');
        $product = $this->productIn($store);
        $customer = $this->victimIn($store);

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $state = $this->state($store);
        $state['customer_id'] = $customer->id;

        $response = $this->actingAs($customer, 'customers')
            ->withSession(['request_data' => $state])
            ->post('/' . $store->slug . '/get-massage', ['data' => ['type' => 'whatsapp', 'order_id' => 'ORD-1']]);

        $this->assertStringStartsWith('https://api.whatsapp.com/send', (string) $response->getContent(),
            'Premise: the authenticated branch ran to its end (HTTP ' . $response->getStatusCode() . '). Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame(0, self::cartLinesOf($customer->id, $product->id), 'The customer\'s own basket line was no longer cleared.');
    }

    // ---------------------------------------------------------------------------------------------
    // (b) whose message template
    // ---------------------------------------------------------------------------------------------

    private function messageFor($store): string
    {
        $product = $this->productIn($store);
        $response = $this->getMassage($store, $this->guestCart($store, $product));

        $response->assertOk();
        parse_str((string) parse_url((string) $response->getContent(), PHP_URL_QUERY), $query);

        return (string) ($query['text'] ?? '');
    }

    public function test_the_message_uses_the_stores_own_template_not_the_first_merchants(): void
    {
        [, $first] = $this->tenant('TEMPLATE-OF-THE-FIRST-MERCHANT {order_no}');
        [, $second] = $this->tenant('TEMPLATE-OF-THE-SECOND-MERCHANT {order_no}');

        $this->assertStringContainsString('TEMPLATE-OF-THE-FIRST-MERCHANT', $this->messageFor($first), 'Premise: the first merchant\'s own store uses their template.');

        $text = $this->messageFor($second);

        $this->assertStringContainsString('TEMPLATE-OF-THE-SECOND-MERCHANT', $text, 'The store\'s own template was not used.');
        $this->assertStringNotContainsString('FIRST-MERCHANT', $text, 'Another tenant\'s message template was used for this store.');
    }
}
