<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * SEC-INTAKE #188 — THE ONE CONFIRMED DEFECT: A GUEST ORDER CARRYING A REAL CUSTOMER ID CAN BE
 * SELF-CANCELLED, WHICH THE ENDPOINT'S OWN DOCBLOCK SAYS IS DELIBERATELY IMPOSSIBLE.
 * =================================================================================================
 *
 * `OrderController::status_cancel` states its own intent, and the statement is correct about what
 * it wants:
 *
 *     "DISCLOSED BEHAVIOUR CHANGE: `orders.customer_id` defaults to 0, so a GUEST order can no
 *      longer be cancelled through this endpoint. That is deliberate. Self-service cancellation of
 *      a guest order over an unauthenticated endpoint keyed on a guessable integer is the
 *      vulnerability itself, not a feature; a guest cancellation has to go through the merchant."
 *
 * IT EXPRESSES THAT INTENT BY ASSUMING `customer_id = 0`, and that assumption is FALSE for a whole
 * class of guest orders. Measured in `app/Services/PaymentService.php:3019-3033`:
 *
 *     $orderCustomerId = ! empty($request->customer_id) ? $request->customer_id : 0;
 *     $is_guest = 1;
 *     if (auth('customers')->check()) { $orderCustomerId = auth('customers')->id(); $is_guest = 0; }
 *     ...
 *     $order->customer_id = $orderCustomerId;   // the matched id on the guest branch
 *     $order->is_guest    = $is_guest;          // stays 1, because nobody is signed in
 *
 * A guest checkout whose billing e-mail matches an existing customer of that store is written with
 * that customer's REAL id and `is_guest = 1`. The guest arm of
 * `PaymentController::handleCustomerRegistration` is where the match happens, and that match is
 * APPROVED, PINNED BEHAVIOUR -- `UnscopedCustomerEmailLookupTest::
 * test_a_guest_checkout_still_finds_its_own_stores_customer` asserts it, and it follows from
 * per-store e-mail uniqueness, an owner decision. NOTHING HERE CHANGES IT. This file is about what
 * the CANCELLATION endpoint does with the result.
 *
 * SO THE GUARD IS KEYED ON THE WRONG COLUMN. `customer_id` answers "whose record is this attached
 * to"; `is_guest` answers "was anybody signed in when it was placed". The docblock wants the
 * second and tests the first. The fix uses the column that already carries the fact, the way
 * `App\Support\Marketing\RecoveryCandidates:187` already does (`->where('orders.is_guest', 0)`).
 *
 * WHAT IS *NOT* CLAIMED HERE, because the measured read paths do not support it: no customer data
 * is disclosed to the guest. `Order::order_detail()` reads the `Customer` row and its
 * `DeliveryAddress` only inside the `else` of `if ($order->payment_type != 'POS')`, and `'POS'` is
 * absent from `PaymentController::getValidPaymentTypes()`, so a storefront order's billing and
 * delivery fields come from `order_billing_details` -- what the guest typed. This is an
 * ACCOUNT-INTEGRITY defect, not a disclosure, and it is stated that way on purpose.
 *
 * TWO CUSTOMERS AND TWO STORES, because the tenant limb has to stay nailed down while this one
 * changes: store A's customer must not be able to reach store B's order even when both exist, and
 * the already-fixed unscoped-lookup finding must not regress.
 */
class GuestOrderSelfCancellationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const CANCELLED = 2;

    private Store $storeA;

    private Store $storeB;

    private Customer $customerA;

    private Customer $customerB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->storeA = $this->makeStore($this->makeMerchant());
        $this->storeB = $this->makeStore($this->makeMerchant());

        $this->customerA = $this->shopperIn($this->storeA, 'shared@example.test');
        $this->customerB = $this->shopperIn($this->storeB, 'shared@example.test');
    }

    private function shopperIn(Store $store, string $email): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shop',
            'last_name' => 'Per',
            'email' => $email,
            'password' => Hash::make('not-a-real-credential'),
            'store_id' => $store->id,
            'status' => 1,
            'billable_id' => $store->created_by,
            'billable_type' => User::class,
        ]);
    }

    /**
     * THE SHAPE THE INTAKE ACTUALLY PRODUCES: `is_guest = 1` with a real `customer_id`. Written
     * directly rather than driven through checkout, because what is under test is the CANCELLATION
     * guard, not the intake -- and driving a full payment flow would make a refusal here
     * indistinguishable from a refusal anywhere upstream.
     */
    private function adoptedGuestOrder(Store $store, Customer $attachedTo): Order
    {
        $order = $this->makeOrder($store, [
            'customer_id' => $attachedTo->id,
            'is_guest' => 1,
            'delivered_status' => 0,
        ]);

        $this->assertSame(1, (int) $order->is_guest,
            'premise failed: the order is not marked as a guest order, so it is not the shape the '
            . 'guest intake produces and this test measures nothing.');
        $this->assertSame((int) $attachedTo->id, (int) $order->customer_id,
            'premise failed: the order does not carry the matched customer id, so the guard being '
            . 'tested would refuse it for the ordinary reason instead of the one under test.');

        return $order;
    }

    private function cancelAs(Customer $customer, Store $store, Order $order)
    {
        return $this->actingAs($customer, 'customers')
            ->post('/' . $store->slug . '/status-cancel', [
                'order_id' => $order->id,
                'order_status' => 'cancel',
            ]);
    }

    /** THE HEADLINE. */
    public function test_a_guest_order_attached_to_a_customer_cannot_be_self_cancelled(): void
    {
        $order = $this->adoptedGuestOrder($this->storeA, $this->customerA);

        $this->cancelAs($this->customerA, $this->storeA, $order);

        /* THROUGH THE RAW TABLE. `Order` registers no StoreScope, but a plain `find()` still goes
           through Eloquent's cache, and this route writes through the model -- so read the row the
           database actually holds. */
        $after = DB::table('orders')->where('id', $order->id)->first();

        $this->assertSame(0, (int) $after->delivered_status,
            "A guest order was cancelled through the customer self-service endpoint. The order was "
            . 'placed by someone who was not signed in; it carries this customer id only because '
            . "the checkout e-mail matched their record. The endpoint's own docblock says this is "
            . 'deliberately impossible, and it relies on `customer_id = 0` to say so.');

        $this->assertNull($after->cancel_date,
            'The cancellation was refused but a cancel_date was still written.');
    }

    /**
     * THE SIDE EFFECTS, AND THE INVENTORY ONE HAD TO BE MADE REAL. A first draft asserted that
     * `final_price` and the financial-row count were unchanged -- and PASSED against the unfixed
     * code, because cancelling does not touch either. It was measuring nothing. The restore loop's
     * observable effects are the product's stock and the restore markers it writes back into
     * `product_json` (`RestoresCancelledOrderStock`, which re-encodes the lines), so the order
     * needs a real product line for either to mean anything.
     */
    public function test_the_refusal_restores_no_stock_and_leaves_the_record_untouched(): void
    {
        $product = Product::forceCreate([
            'name' => 'sec188-' . uniqid(),
            'slug' => 'sec188-' . uniqid(),
            'product_stock' => 5,
            'track_stock' => 1,
            'variant_product' => 0,
            'price' => 10.00,
            'sale_price' => 10.00,
            'store_id' => $this->storeA->id,
            'created_by' => $this->storeA->created_by,
        ]);

        $lines = [[
            'product_id' => $product->id,
            'variant_id' => 0,
            'qty' => 3,
            'price' => 10.00,
            'name' => $product->name,
        ]];

        $order = $this->makeOrder($this->storeA, [
            'customer_id' => $this->customerA->id,
            'is_guest' => 1,
            'delivered_status' => 0,
            'product_json' => json_encode($lines),
        ]);

        $jsonBefore = (string) DB::table('orders')->where('id', $order->id)->value('product_json');
        $financialBefore = DB::table('store_order_financials')->where('order_id', $order->id)->count();

        $this->cancelAs($this->customerA, $this->storeA, $order);

        $this->assertSame(5, (int) DB::table('products')->where('id', $product->id)->value('product_stock'),
            'THE REFUSED CANCELLATION RESTORED STOCK. Three units came back for an order nobody '
            . 'was authorised to cancel, which is the inventory-inflation primitive this '
            . "endpoint's docblock already records as the reason guest self-cancellation is "
            . 'refused at all.');

        $this->assertSame($jsonBefore,
            (string) DB::table('orders')->where('id', $order->id)->value('product_json'),
            'The restore loop rewrote the order lines, so it ran despite the refusal.');

        $this->assertSame(0, (int) DB::table('orders')->where('id', $order->id)->value('delivered_status'),
            'The order was cancelled.');

        $this->assertSame($financialBefore,
            DB::table('store_order_financials')->where('order_id', $order->id)->count(),
            'The refused cancellation wrote or removed a financial ledger row.');
    }

    /** THE ORACLE. A real customer's OWN order must still cancel, or the fix has broken the feature. */
    public function test_an_authenticated_customers_own_order_still_cancels(): void
    {
        $order = $this->makeOrder($this->storeA, [
            'customer_id' => $this->customerA->id,
            'is_guest' => 0,
            'delivered_status' => 0,
        ]);

        $this->assertSame(0, (int) $order->is_guest, 'premise failed: not a signed-in order.');

        $this->cancelAs($this->customerA, $this->storeA, $order);

        $this->assertSame(
            self::CANCELLED,
            (int) DB::table('orders')->where('id', $order->id)->value('delivered_status'),
            'A CUSTOMER CAN NO LONGER CANCEL THEIR OWN ORDER. The guard is refusing legitimate '
            . 'self-service cancellation, which is worse than the defect it closes.'
        );
    }

    /** The tenant limb, which must not regress while the guest limb changes. */
    public function test_a_customer_cannot_cancel_another_stores_order(): void
    {
        $order = $this->makeOrder($this->storeB, [
            'customer_id' => $this->customerB->id,
            'is_guest' => 0,
            'delivered_status' => 0,
        ]);

        $this->cancelAs($this->customerA, $this->storeA, $order);

        $this->assertSame(0,
            (int) DB::table('orders')->where('id', $order->id)->value('delivered_status'),
            "Store A's customer cancelled an order belonging to store B."
        );
    }

    /** And a guest order with the ordinary `customer_id = 0` stays refused, as it already was. */
    public function test_an_ordinary_guest_order_remains_uncancellable(): void
    {
        $order = $this->makeOrder($this->storeA, [
            'customer_id' => 0,
            'is_guest' => 1,
            'delivered_status' => 0,
        ]);

        $this->cancelAs($this->customerA, $this->storeA, $order);

        $this->assertSame(0,
            (int) DB::table('orders')->where('id', $order->id)->value('delivered_status'),
            'The pre-existing refusal for a customer_id = 0 guest order has regressed.'
        );
    }
}
