<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * ORDER AND ACCOUNTING RECORDS SURVIVE A DELETED ACCOUNT.  P1-ORD-03 (#22), OWNER DECISION D14.
 * =================================================================================================
 *
 * TWO SITES, and the task row only named one of them. Its acceptance criterion says "deleting a
 * MERCHANT account cannot destroy order history", while the defect it pointed at is the CUSTOMER
 * self-delete. Both were live:
 *
 *   Api\ApiController::user_delete          Order::where('customer_id', $id)->delete()
 *   StoreController::ownerstoredestroy      Order::where('store_id', $store->id)->delete()
 *                                           PlanOrder::where('store_id', $store->id)->delete()
 *
 * `Order` does not use SoftDeletes, so all three were irreversible. `Customer` DOES soft-delete,
 * which is the asymmetry that made the first one absurd: the account row survived and the
 * merchant's sales history did not.
 *
 * Assertions read through `DB::table()` because `Order` is `Cachable`.
 */
class OrderRecordPreservationTest extends SecurityTestCase
{
    use RefreshDatabase;

    public function test_a_customer_deleting_their_account_does_not_destroy_their_orders(): void
    {
        [, $store] = $this->makeTenant();

        $customer = Customer::forceCreate([
            'first_name' => 'A', 'last_name' => 'B', 'email' => 'del-' . uniqid() . '@e.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('x'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        $order = $this->makeOrder($store, [
            'customer_id' => $customer->id, 'is_guest' => 0,
            'final_price' => 250.00, 'payment_status' => 'Paid',
        ]);

        // `custom.auth`, not a session guard: the route resolves a Sanctum token and logs its
        // owner into whichever guard matches the tokenable class. actingAs() alone is refused.
        $response = $this->withHeader('Authorization', 'Bearer ' . $customer->createToken('t')->plainTextToken)
            ->postJson('/api/' . $store->slug . '/user-delete', ['user_id' => $customer->id]);

        $this->assertNotSame(404, $response->status(), 'Premise: the endpoint exists.');

        $row = DB::table('orders')->where('id', $order->id)->first();

        $this->assertNotNull($row,
            'the order was destroyed when the customer closed their account. Order has no '
            . 'SoftDeletes, so this is irreversible, and it is the merchant\'s financial record '
            . 'rather than the customer\'s own content.');
        // Numeric, not string: SQLite stores the decimal as 250 while MySQL returns '250.00',
        // and the question here is the VALUE, not the engine's formatting of it.
        $this->assertEqualsWithDelta(250.00, (float) $row->final_price, 0.005,
            'the order survived but its money did not.');
        $this->assertSame('Paid', (string) $row->payment_status);
    }

    /**
     * THE PREMISE FOR THE ABOVE: the endpoint really did run and really did delete something.
     *
     * Without this, "the order is still there" is satisfied by an endpoint that refused the
     * request entirely, and the test would pass against unfixed code.
     */
    public function test_the_delete_endpoint_still_removes_the_customers_own_content(): void
    {
        [, $store] = $this->makeTenant();

        $customer = Customer::forceCreate([
            'first_name' => 'A', 'last_name' => 'B', 'email' => 'del-' . uniqid() . '@e.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('x'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        DB::table('delivery_addresses')->insert([
            'customer_id' => $customer->id, 'address' => 'x', 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $customer->createToken('t')->plainTextToken)
            ->postJson('/api/' . $store->slug . '/user-delete', ['user_id' => $customer->id]);

        $this->assertSame(0, (int) DB::table('delivery_addresses')->where('customer_id', $customer->id)->count(),
            'Premise: the endpoint ran and deleted the customer\'s own content. If this is non-zero '
            . 'the endpoint refused, and the order assertion above proves nothing.');
        $this->assertNotNull(DB::table('customers')->where('id', $customer->id)->first(),
            'Premise: Customer soft-deletes, so the row survives. That asymmetry is why deleting '
            . 'the orders was the wrong half to make irreversible.');
    }

    /**
     * THE CRITERION'S OWN SURFACE: deleting a store does not destroy its order history.
     *
     * Driven at the model layer rather than through `ownerstoredestroy`, deliberately: that
     * handler needs a merchant holding two or more stores, runs ProductController::destroy over
     * every product and unlinks media from disk. The question here is only whether the ORDER
     * delete is still in that block, which a source assertion answers exactly and cheaply.
     */
    public function test_the_store_delete_no_longer_carries_an_order_delete(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/StoreController.php'));

        // Comments are stripped, because this file now carries a docblock that QUOTES the removed
        // line in order to explain it -- the recorded prose trap, which has fired here before.
        $code = implode('', array_map(
            fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($t) ? $t[1] : $t),
            token_get_all($source)
        ));

        $this->assertStringNotContainsString("Order::where('store_id', \$store->id)->delete()", $code,
            'deleting a store still destroys its orders. Owner decision D14: order and accounting '
            . 'records are preserved.');
        $this->assertStringNotContainsString("PlanOrder::where('store_id', \$store->id)->delete()", $code,
            'deleting a store still destroys its plan orders, which are the record of what the '
            . 'merchant paid the platform.');

        // And the census is not vacuous: the handler still exists and still deletes other things.
        $this->assertStringContainsString('ownerstoredestroy', $code);
        $this->assertStringContainsString("Page::where('store_id', \$store->id)->delete()", $code,
            'Premise: the delete block is still there. If this is gone the two assertions above '
            . 'hold because the whole method was removed.');
    }
}
