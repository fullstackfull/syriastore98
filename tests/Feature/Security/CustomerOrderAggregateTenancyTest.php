<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderBillingDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2.5-PKG-C (F-C-07) — a customer's order COUNT and TOTAL SPEND include other stores' orders.
 * ================================================================================================
 *
 * `Customer::Ordercount()` and `Customer::total_spend()` are the same five lines twice, and neither
 * carries a store predicate in ANY of its three queries:
 *
 *     Order::where('customer_id', $this->id)                      -- no store_id
 *     OrderBillingDetail::where('email', $this->email)            -- no store_id, AND the table
 *                                                                    HAS NO store_id COLUMN
 *     Order::whereIn('id', $ids)->where('customer_id', 0)         -- no store_id
 *
 * `Order` is absent from `config/tenancy.php`'s `scoped_models`, so `StoreScope` adds no predicate
 * for any principal, and `OrderBillingDetail` registers no scope and could not be scoped anyway --
 * measured: `order_billing_details` has columns id, order_id, product_order_id, first_name,
 * last_name, email, telephone, company_name, address, ... and **no store_id at all**. The only
 * route to a tenant is through `order_id` -> `orders.store_id`.
 *
 * THE GUEST BRANCH IS THE LIVE ONE. It runs whenever the customer has no order carrying their own
 * id -- the ordinary state for a shopper who checked out as a guest -- and it matches billing
 * records by EMAIL ACROSS EVERY STORE ON THE PLATFORM. The same person shopping at two stores with
 * one email address is not an exotic case; it is the normal one.
 *
 * WHAT A MERCHANT SEES, and why the money one is the sharper half:
 *   - the order count on `/customer` and `/customer-grid`, inflated by another store's orders;
 *   - `total_spend()`, which SUMS `final_price` -- so another store's REVENUE appears in this
 *     store's figure;
 *   - AOV, computed as `total_spend() / Ordercount()` in four places, so both inputs are wrong.
 *
 * NO API OR FLUTTER CONSUMER. Every caller is merchant-admin: `CustomerDataTable:87,95,96,201,202,227`
 * and `CustomerController:559,560,586`. So the wire contract is not involved.
 *
 * THE FIX ONLY EVER REMOVES other stores' rows from these figures, so a merchant's numbers can go
 * down and never up. That is a visible change to a displayed money figure and is reported to the
 * owner as such rather than slipped in.
 */
class CustomerOrderAggregateTenancyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function makeCustomerIn(int $storeId, string $email): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shared', 'last_name' => 'Shopper',
            'email' => $email, 'password' => bcrypt('x'), 'store_id' => $storeId,
        ]);
    }

    /** A GUEST order (customer_id = 0) in $storeId whose billing record carries $email. */
    private function makeGuestOrderWithBillingEmail(int $storeId, string $email, float $price): Order
    {
        $order = $this->makeOrder(
            \App\Models\Store::withoutGlobalScopes()->findOrFail($storeId),
            ['customer_id' => 0, 'final_price' => $price]
        );

        OrderBillingDetail::forceCreate([
            'order_id' => $order->id,
            'product_order_id' => $order->product_order_id,
            'first_name' => 'Shared', 'last_name' => 'Shopper',
            'email' => $email, 'telephone' => '0100000000',
        ]);

        return $order;
    }

    public function test_the_order_count_excludes_another_stores_guest_order_for_the_same_email(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $email = 'shared-' . uniqid() . '@example.test';
        $customer = $this->makeCustomerIn($storeA->id, $email);

        // One guest order in EACH store, both billed to the same address.
        $this->makeGuestOrderWithBillingEmail($storeA->id, $email, 100.00);
        $this->makeGuestOrderWithBillingEmail($storeB->id, $email, 900.00);

        $this->assertSame(
            1,
            $customer->Ordercount(),
            "Store A's customer shows an order count that includes store B's guest order."
        );
    }

    public function test_the_total_spend_excludes_another_stores_revenue(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $email = 'shared-' . uniqid() . '@example.test';
        $customer = $this->makeCustomerIn($storeA->id, $email);

        $this->makeGuestOrderWithBillingEmail($storeA->id, $email, 100.00);
        $this->makeGuestOrderWithBillingEmail($storeB->id, $email, 900.00);

        $this->assertSame(
            100.0,
            (float) $customer->total_spend(),
            "Store A's customer shows a total spend that includes store B's revenue."
        );
    }

    /**
     * THE POSITIVE CONTROL, and it is load-bearing: a fix that scoped these to nothing, or that
     * broke the guest branch outright, would satisfy both assertions above by returning 0.
     */
    public function test_the_stores_own_guest_orders_are_still_counted_and_summed(): void
    {
        [, $storeA] = $this->makeTenant();

        $email = 'own-' . uniqid() . '@example.test';
        $customer = $this->makeCustomerIn($storeA->id, $email);

        $this->makeGuestOrderWithBillingEmail($storeA->id, $email, 40.00);
        $this->makeGuestOrderWithBillingEmail($storeA->id, $email, 60.00);

        $this->assertSame(2, $customer->Ordercount(), 'The guest branch stopped counting its own store.');
        $this->assertSame(100.0, (float) $customer->total_spend(), 'The guest branch stopped summing its own store.');
    }

    /**
     * THE FAIL-CLOSED LIMB, and this test exists because the MUTATION CHECK found nothing covering
     * it. Replacing `Order::whereRaw('1 = 0')` with `Order::query()` left all four of the tests
     * above green — and that mutant returns EVERY store's orders for a customer with no store,
     * which is the original defect inverted and total. An untested limb guarding the widest
     * failure is the worst kind of gap, so it is measured here.
     *
     * `customers.store_id` is nullable in the live schema, so this row is constructible.
     */
    public function test_a_customer_with_no_store_matches_no_orders_at_all(): void
    {
        [, $storeA] = $this->makeTenant();

        $email = 'storeless-' . uniqid() . '@example.test';

        $storeless = Customer::forceCreate([
            'first_name' => 'Storeless', 'last_name' => 'Shopper',
            'email' => $email, 'password' => bcrypt('x'), 'store_id' => null,
        ]);

        // Real orders exist, in a real store, billed to this very address.
        $this->makeGuestOrderWithBillingEmail($storeA->id, $email, 250.00);
        $this->makeOrder($storeA, ['customer_id' => $storeless->id, 'final_price' => 750.00]);

        $this->assertSame(
            0,
            $storeless->Ordercount(),
            'A customer with no store matched orders; the fail-closed limb is not holding.'
        );
        $this->assertSame(
            0.0,
            (float) $storeless->total_spend(),
            'A customer with no store summed money; the fail-closed limb is not holding.'
        );
    }

    /**
     * AND THE DIRECT BRANCH, which runs when orders carry the customer's own id. Asserted
     * separately because it is a different code path and a fix to one is not a fix to the other.
     */
    public function test_the_direct_branch_also_excludes_another_stores_order(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $customer = $this->makeCustomerIn($storeA->id, 'direct-' . uniqid() . '@example.test');

        $this->makeOrder($storeA, ['customer_id' => $customer->id, 'final_price' => 50.00]);
        // The same customer id appearing on an order in ANOTHER store -- which nothing in the
        // schema prevents, since `orders.customer_id` carries no composite key with `store_id`.
        $this->makeOrder($storeB, ['customer_id' => $customer->id, 'final_price' => 500.00]);

        $this->assertSame(1, $customer->Ordercount(), 'The direct branch counted another store.');
        $this->assertSame(50.0, (float) $customer->total_spend(), 'The direct branch summed another store.');
    }
}
