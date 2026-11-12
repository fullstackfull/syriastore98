<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\HomeController;
use App\Http\Controllers\PaymentController;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderBillingDetail;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

/**
 * TWO CUSTOMER LOOKUPS BY E-MAIL WITH NO STORE PREDICATE — owner safeguard 1 of 2026-11-07.
 * =================================================================================================
 *
 * The owner's condition on approving per-store e-mail uniqueness: *"First reproduce and fix the two
 * unscoped email lookups in `PaymentController` and `HomeController`."*
 *
 * **ONE OF THE TWO IS A CROSS-TENANT WRITE, which is sharper than my own proposal recorded.** I had
 * described both as wrong-attribution reads. `PaymentController::handleCustomerRegistration()` does
 * not only read the row it finds:
 *
 *     $customer = Customer::where('email', $billing['email'])->first();   // NO store predicate
 *     if (!$customer) { $customer = $this->createCustomer($billing, $store, false); }
 *     else { $customer->last_active = date('Y-m-d'); $customer->save(); }   // <-- WRITES IT
 *     return $customer;
 *
 * So with two live customers sharing an address across stores, a guest checkout in store B can
 * **update store A's customer row** and then **attribute store B's order to store A's customer**.
 * `->first()` carries no `orderBy`, so the engine's natural order decides which — in practice the
 * lower id, i.e. whichever store registered that shopper first.
 *
 * WHY THE GLOBAL SCOPE IS NOT A BACKSTOP HERE, AND THIS IS THE WHOLE POINT. `Customer` IS in
 * `config/tenancy.php`'s `scoped_models`, so `StoreScope` normally supplies a predicate. But a
 * guest checkout is **anonymous** — `StoreScope::resolveTenant()` returns `MODE_UNRESOLVED`, and
 * `null_tenant => 'open'` means the scope then adds **nothing at all**. The one path where the
 * shopper is not signed in is the one path the scope does not cover.
 *
 * `$store` IS ALREADY A PARAMETER of both enclosing methods, so neither fix needs new information.
 *
 * `HomeController::order_track()` is the milder half: read-only, the resolved customer is passed to
 * a view. Its ORDER lookup is already correctly store-scoped (`:1203`) — only the customer lookup
 * is not, which is what makes it an oversight rather than a design.
 *
 * DRIVEN BY CALLING THE METHODS, not the HTTP routes, and deliberately. `handleCustomerRegistration`
 * is private and sits behind a full payment flow; `order_track` returns a view whose storefront
 * theme would have to render. Calling them directly measures **the lookup**, which is the thing
 * that was wrong, instead of measuring a theme. For `order_track` the returned `View` object is
 * inspected without rendering.
 *
 * NO PAYMENT BEHAVIOUR CHANGES. Nothing about amounts, gateways, order totals or transitions is
 * touched. The change is which `customers` row a lookup is allowed to return, and it can only ever
 * NARROW that — a store can no longer be handed another store's row.
 */
class UnscopedCustomerEmailLookupTest extends SecurityTestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        // Store A is created first, so its customer takes the LOWER id — which is the row an
        // unordered ->first() returns, and the whole point of the finding.
        $this->storeA = $this->makeStore($this->makeMerchant());
        $this->storeB = $this->makeStore($this->makeMerchant());
    }

    private function customerIn(Store $store, string $first, string $email, string $lastActive): Customer
    {
        return Customer::forceCreate([
            'first_name' => $first, 'last_name' => 'Shopper',
            'email' => $email, 'password' => bcrypt('x'),
            'store_id' => $store->id, 'created_by' => $store->created_by,
            'status' => 1,
            'last_active' => $lastActive,
        ]);
    }

    private static function rows()
    {
        return Customer::withoutGlobalScopes();
    }

    // =============================================================================================
    //  PaymentController — the cross-tenant WRITE
    // =============================================================================================

    private function guestCheckout(Store $store, string $email)
    {
        // Through the container: PaymentController::__construct() takes a PaymentService, so a
        // bare `new` raises ArgumentCountError (which is how my first draft failed).
        $controller = app(PaymentController::class);
        $method = new \ReflectionMethod($controller, 'handleCustomerRegistration');
        $method->setAccessible(true);

        /*
         * `billing_info`, AS A JSON STRING, which is how the checkout posts it:
         * `$billing = is_string($request->billing_info) ? (array) json_decode(...) : ...`
         * (PaymentController:2023). My first draft sent `billing` as an array and the method
         * received null, which surfaced as a TypeError inside Validator::make rather than as a
         * wrong answer -- a reminder that a reflection probe has to match the real request shape
         * or it measures nothing.
         *
         * `register` is absent, so the guest branch is taken rather than the account-creating one.
         */
        $request = Request::create('/checkout', 'POST', [
            'billing_info' => json_encode([
                'firstname' => 'Guest',
                'lastname' => 'Buyer',
                'billing_address' => '1 Test Street',
                'billing_country' => 'SY',
                'email' => $email,
            ]),
        ]);

        return $method->invoke($controller, $request, $store);
    }

    public function test_premise_store_a_holds_the_older_row_for_the_shared_address(): void
    {
        $a = $this->customerIn($this->storeA, 'InStoreA', 'shared@example.test', '2020-01-01');
        $b = $this->customerIn($this->storeB, 'InStoreB', 'shared@example.test', '2020-01-01');

        $this->assertLessThan($b->id, $a->id,
            'premise: store A must hold the LOWER id, or an unordered ->first() would not prefer '
            . 'it and this test would prove nothing');
    }

    /**
     * THE CROSS-TENANT WRITE. A guest checkout in store B must not touch store A's row.
     */
    public function test_a_guest_checkout_in_store_b_does_not_write_to_store_as_customer(): void
    {
        $a = $this->customerIn($this->storeA, 'InStoreA', 'shared@example.test', '2020-01-01');
        $this->customerIn($this->storeB, 'InStoreB', 'shared@example.test', '2020-01-01');

        $this->guestCheckout($this->storeB, 'shared@example.test');

        $this->assertSame('2020-01-01', (string) self::rows()->find($a->id)->last_active,
            "a guest checkout in store B UPDATED store A's customer row. The lookup has no store "
            . 'predicate, the global scope adds none on an anonymous checkout (MODE_UNRESOLVED + '
            . "null_tenant 'open'), and the else branch writes last_active to whatever row it found");
    }

    /**
     * AND THE ATTRIBUTION. The order must be attributed to the store's own customer.
     */
    public function test_a_guest_checkout_in_store_b_resolves_store_bs_own_customer(): void
    {
        $a = $this->customerIn($this->storeA, 'InStoreA', 'shared@example.test', '2020-01-01');
        $b = $this->customerIn($this->storeB, 'InStoreB', 'shared@example.test', '2020-01-01');

        $resolved = $this->guestCheckout($this->storeB, 'shared@example.test');

        $this->assertInstanceOf(Customer::class, $resolved,
            'premise: the handler did not return a Customer, so nothing can be said about which one');
        $this->assertSame($b->id, (int) $resolved->id,
            "the guest checkout resolved store A's customer for an order in store B, so the order "
            . "would be attributed to another merchant's shopper");
        $this->assertNotSame($a->id, (int) $resolved->id,
            'positive control: the two rows are distinct, so the assertion above is meaningful');
    }

    /**
     * POSITIVE CONTROL for the two above, and the half that proves the fix is a narrowing rather
     * than a break: a guest checkout whose address exists in THIS store must still find it, and
     * must not create a duplicate.
     */
    public function test_a_guest_checkout_still_finds_its_own_stores_customer(): void
    {
        $b = $this->customerIn($this->storeB, 'InStoreB', 'mine@example.test', '2020-01-01');

        $resolved = $this->guestCheckout($this->storeB, 'mine@example.test');

        $this->assertSame($b->id, (int) $resolved->id,
            'the guest checkout no longer finds its own store\'s customer, so the fix broke the '
            . 'lookup instead of scoping it');
        $this->assertSame(1, self::rows()->where('email', 'mine@example.test')->count(),
            'the guest checkout created a DUPLICATE row in its own store rather than finding the '
            . 'existing one');
        $this->assertSame(date('Y-m-d'), (string) self::rows()->find($b->id)->last_active,
            "the handler no longer refreshes its own store's customer last_active");
    }

    /**
     * And an address that exists only in ANOTHER store must produce a NEW row in this one, not
     * adopt the other store's. This is the case per-store uniqueness makes ordinary.
     */
    public function test_an_address_known_only_in_another_store_creates_a_row_here(): void
    {
        $a = $this->customerIn($this->storeA, 'InStoreA', 'elsewhere@example.test', '2020-01-01');

        $resolved = $this->guestCheckout($this->storeB, 'elsewhere@example.test');

        $this->assertNotSame($a->id, (int) $resolved->id,
            "store B's checkout adopted store A's customer row");
        $this->assertSame($this->storeB->id, (int) $resolved->store_id,
            'the row the checkout resolved does not belong to store B');
        $this->assertSame('2020-01-01', (string) self::rows()->find($a->id)->last_active,
            "and store A's row was written to");
    }

    // =============================================================================================
    //  HomeController — the read-only half
    // =============================================================================================

    private function orderIn(Store $store, string $number, string $email): Order
    {
        $order = $this->makeOrder($store, [
            'product_order_id' => $number,
            'customer_id' => 0,
            'is_guest' => 1,
            'email' => $email,
        ]);

        // NO store_id: `order_billing_details` has no such column, which CLAUDE.md records as
        // the reason "the billing table cannot be scoped on its own" -- the scoping goes through
        // the order. My first draft inserted one and SQLite refused it.
        OrderBillingDetail::forceCreate([
            'order_id' => $order->id,
            'email' => $email,
        ]);

        return $order;
    }

    /**
     * A CORRECTION TO MY OWN FINDING, measured rather than reasoned.
     *
     * I reported `HomeController::order_track()`'s customer lookup as a second unscoped read that
     * could surface another store's customer. **It cannot, because it can never surface anybody.**
     *
     * MEASURED: the `orders` table has **no `email` column** (`pragma table_info(orders)` on the
     * 133-table schema), and `email` is **not in `Order::$fillable`** (33 entries, checked by
     * reflection). So `$order->email` is always `null`, and
     * `Customer::where('email', null)->first()` compiles to `where email is null` against a column
     * that is `varchar NOT NULL` — it matches nothing, every time.
     *
     * So that lookup is unreachable dead code and the store predicate I added to it fixes nothing
     * reachable. **It is kept** because a lookup that is one day given a real column should
     * already be scoped, and because leaving the only unscoped sibling in place after fixing the
     * other invites the next reader to copy it — but it is recorded as consistency, not as a
     * repair.
     *
     * AND THE REAL DEFECT THERE IS WORSE THAN THE ONE I WENT LOOKING FOR. Because `$customer` is
     * always null, `front_end/pages/order-status.blade.php:29` evaluates
     * `$customer->first_name` on null whenever `delivery_informations.name` is empty:
     *
     *     {{ !empty($order_detail['delivery_informations']['name'])
     *          ? $order_detail['delivery_informations']['name']
     *          : $customer->first_name }}
     *
     * Line 26 guards its own use with `??`; line 29 does not. That is a shopper-facing 500 on the
     * order-status page, not a tenancy fault, so it is reported separately and not fixed in a
     * security slice.
     */
    public function test_order_track_never_resolves_a_customer_at_all(): void
    {
        $this->customerIn($this->storeA, 'InStoreA', 'tracked@example.test', '2020-01-01');
        $this->customerIn($this->storeB, 'InStoreB', 'tracked@example.test', '2020-01-01');

        $order = $this->orderIn($this->storeB, 'TRK-0001', 'tracked@example.test');

        /*
         * THE PREMISE IS ON THE SCHEMA, NOT ON THE MODEL — and my first draft got this wrong in a
         * way that mattered. It asserted `$order->email` is null, which it is, but for the WRONG
         * REASON: `makeOrder()` is `Order::create()`, `email` is not in `Order::$fillable`, and
         * mass assignment silently drops an unfillable key WHETHER OR NOT THE COLUMN EXISTS. So if
         * `orders.email` were ever added without a `$fillable` entry -- the exact mistake
         * `Order.php:53-55` records against `created_by` -- that assertion would keep reporting
         * "dead" while the handler read a real value, because a READ does not consult `$fillable`.
         *
         * Raised by the tenant-authz review of this change, which also settled the conclusion on
         * stronger evidence than my own: the committed production DDL has 37 `orders` columns and
         * no `email`, so this is true in production and not only in the migration-built schema.
         */
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('orders', 'email'),
            'premise: orders.email now EXISTS, so the lookup is no longer dead and this test '
            . 'should be rewritten to assert the store predicate instead');

        $controller = app(HomeController::class);
        $request = Request::create('/' . $this->storeB->slug . '/order-track', 'POST', [
            'order_number' => 'TRK-0001',
            'email' => 'tracked@example.test',
        ]);

        $view = $controller->order_track($request, $this->storeB->slug);
        $data = method_exists($view, 'getData') ? $view->getData() : [];

        $this->assertNotNull($data['order'] ?? null,
            'premise: the order was not found, so nothing about the customer lookup is measured');

        $this->assertNull($data['customer'] ?? null,
            'OPEN DEFECT, PINNED. The order-status page resolves NO customer, ever, because it '
            . 'keys on orders.email and that column does not exist. If this now returns a '
            . 'customer the column has been added — rewrite this test to assert the store '
            . 'predicate, which is already in place');
    }

}
