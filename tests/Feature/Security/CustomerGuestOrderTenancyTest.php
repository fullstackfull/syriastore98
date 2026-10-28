<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * F169 — a merchant's customer page listed GUEST orders placed in other stores.
 * =============================================================================
 *
 * `CustomerController::show()` store-checks the CUSTOMER ROW and nothing else. When that customer
 * has no orders of their own it falls to a guest branch keyed on their EMAIL ADDRESS:
 *
 *     $orders_detail = OrderBillingDetail::where('email', $customers->email)->get();
 *     $orders = Order::whereIn('id', $order_id)->where('customer_id', 0)->get();
 *
 * Neither query carries a store predicate, and neither model supplies one: `OrderBillingDetail`
 * is absent from `config/tenancy.php`'s `scoped_models` and registers no `StoreScope`, so
 * `StoreScope::isActiveFor()` short-circuits and the scope adds no predicate **for any
 * principal** — the `Product` defect from the project skill, not the `null_tenant => 'open'` one.
 * `Order` is likewise unscoped, by design.
 *
 * So the join key is an email address, which carries no tenancy at all. The ownership check above
 * decides only WHICH customer row may be opened; it does not constrain which orders the email
 * then matches. `order_billing_details.email` is a plain nullable string with no unique
 * constraint and no `store_id` column, and the value is taken from checkout input — so the same
 * address appearing in two stores is the ordinary case for any shopper who buys from two tenants.
 *
 * WHAT LEAKS: the other store's order id, reference, date, value, payment type and status, on a
 * page the merchant reaches with `Show Customer`.
 *
 * THE PREDICATE IS THE CUSTOMER'S OWN STORE, NOT `getCurrentStore()`. The helper returns the
 * hardcoded literal `1` for a `super admin` with an empty `current_store` (helper.php:636-637),
 * and the operator deliberately SKIPS the customer store check above — so a `getCurrentStore()`
 * predicate would show the operator a store-7 customer alongside the assertion that they have no
 * orders, which is a fabrication rather than a refusal. The customer row has already been
 * resolved and, for a merchant, already store-checked; its `store_id` is the right authority.
 */
class CustomerGuestOrderTenancyTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return array{0:int,1:int} the customer id and the order id, both in $storeId */
    private function guestOrderFor(int $storeId, string $email): array
    {
        $order = $this->makeOrder(\App\Models\Store::find($storeId), ['customer_id' => 0]);

        /*
         * NO `store_id` HERE BECAUSE THE TABLE HAS NONE. `order_billing_details` carries
         * `order_id`, `product_order_id`, the name and address fields and `email` — and no store
         * column in any migration. That is why the predicate has to go on `Order`: there is
         * nothing on the billing row to scope by, and adding a column would be a schema change
         * this slice is not authorised to make.
         */
        \DB::table('order_billing_details')->insert([
            'order_id' => $order->id,
            'product_order_id' => $order->product_order_id,
            'email' => $email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [(int) $order->id, (int) $order->id];
    }

    private function customerIn(int $storeId, string $email): int
    {
        return (int) \DB::table('customers')->insertGetId([
            'first_name' => 'Nadia',
            'last_name' => 'Haddad',
            'email' => $email,
            'password' => bcrypt('x'),
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_guest_order_in_another_store_is_not_listed_on_this_merchants_customer_page(): void
    {
        $email = 'shopper' . uniqid() . '@example.test';

        /* The SAME shopper has bought as a guest from another tenant. */
        [, $otherStore] = $this->makeTenant();
        [$foreignOrderId] = $this->guestOrderFor((int) $otherStore->id, $email);

        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Customer');
        $customerId = $this->customerIn((int) $store->id, $email);

        /* And a guest order of their own, so the branch has something legitimate to show. */
        [$ownOrderId] = $this->guestOrderFor((int) $store->id, $email);

        $response = $this->actingAs($merchant)->get('/customer/' . $customerId);
        $response->assertOk();

        $rows = $this->orderRows((string) $response->getContent());

        $this->assertNotSame([], $rows,
            'premise: the guest branch rendered at least one order, so an absence assertion means something');

        $this->assertContains($ownOrderId, $rows, "the merchant's own guest order is missing");
        $this->assertNotContains($foreignOrderId, $rows,
            "another store's guest order was listed on this merchant's customer page");
    }

    public function test_the_ordinary_branch_is_also_scoped(): void
    {
        $email = 'named' . uniqid() . '@example.test';

        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Customer');
        $customerId = $this->customerIn((int) $store->id, $email);

        $own = $this->makeOrder($store, ['customer_id' => $customerId]);

        /*
         * `Order` is not a scoped model and `customer_id` is a plain integer, so the non-guest
         * branch's `where('customer_id', $id)` would also cross stores if another tenant's order
         * carried the same id. That is far less likely than a shared email, but it costs one
         * predicate to close and the branch is two lines from the guest one.
         */
        [, $otherStore] = $this->makeTenant();
        $foreign = $this->makeOrder($otherStore, ['customer_id' => $customerId]);

        $response = $this->actingAs($merchant)->get('/customer/' . $customerId);
        $response->assertOk();

        $rows = $this->orderRows((string) $response->getContent());

        $this->assertContains((int) $own->id, $rows, "the merchant's own order is missing");
        $this->assertNotContains((int) $foreign->id, $rows,
            "another store's order was listed on this merchant's customer page");
    }

    /**
     * The order ids the page actually rendered, read out of the first table's rows.
     *
     * Read from the DOM rather than by substring, for the reason `PaymentTypeDisplayTest`
     * records: an id is a short numeric string and a whole-page `assertStringContainsString`
     * matches prices, dates and element ids too.
     */
    private function orderRows(string $html): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $ids = [];

        foreach ($dom->getElementsByTagName('table') as $table) {
            $headers = [];
            foreach ($table->getElementsByTagName('th') as $th) {
                $headers[] = trim($th->textContent);
            }

            if (! in_array('Payment Type', $headers, true)) {
                continue;
            }

            foreach ($table->getElementsByTagName('tr') as $row) {
                $cells = [];
                foreach ($row->getElementsByTagName('td') as $td) {
                    $cells[] = trim($td->textContent);
                }

                if ($cells === []) {
                    continue;
                }

                /*
                 * The first cell is the order reference, rendered as `#<product_order_id>` inside
                 * a link (customer/view.blade.php:44-49). Mapped back to an order id through the
                 * table rather than parsed, so a change to the reference format cannot silently
                 * empty this list -- and the `#` is stripped because it is presentation.
                 */
                $reference = ltrim($cells[0], "# \t\n\r");

                $id = \DB::table('orders')->where('product_order_id', $reference)->value('id');

                if ($id !== null) {
                    $ids[] = (int) $id;
                }
            }
        }

        return $ids;
    }
}
