<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * F81 — an unrecognised order status answered with a 500, on three endpoints.
 * =================================================================================================
 *
 * `Order::order_status_change()` is a chain of `if ($order_status == '...')` arms, each of which
 * returns. An order that exists but a status that matches no arm fell off the end of the method
 * and returned NULL — and all three callers dereference the result with no guard:
 *
 *     OrderController::order_status_change():1345   if ($responce['status'] == 'success')
 *     Api\ApiController:7449                        if ($date['status'] == 'success')
 *     Api\DashboardController::statusChange         $responce['order_status'] = ...
 *
 * so the answer was `Trying to access array offset on null`. MEASURED as a 500 on the web route
 * before the fix. The two API callers read the value straight out of the request
 * (`$request->order_status`, `$request->delivered`), so any client can produce it.
 *
 * IT IS REACHABLE WITHOUT A CRAFTED REQUEST. The `pre_order` arm is gated on
 * `module_is_active('PreOrder')`, so a caller sending that perfectly legitimate value — it is
 * `orders.delivered_status` 8, which F29 added to the API's own status vocabulary — fell through
 * whenever the module is off, which is the default.
 *
 * WHAT THIS IS NOT. Not a security defect and not a data defect: the order is read at the top and
 * every arm that touches it returns, so an unmatched status has already changed nothing. The
 * assertions below check both halves of that — a clean refusal AND an untouched row.
 *
 * A HYPOTHESIS THIS FILE REFUTED, kept because the method matters more than the conclusion. The
 * first reading of `order_status_change` found six handled values by grepping
 * `\$order_status == '[a-z_]+'`, and the order page's dropdown offers `delivered` — which that
 * list did not contain. That looked like "a merchant cannot mark an order delivered", a serious
 * defect on the most-used merchant screen. Driving all six dropdown values answered 200 and
 * "Delivered". The arm is written `if ($order_status ==  'delivered')`, with TWO spaces, which the
 * regex's single space missed. A grep is a hypothesis, not a finding.
 */
class OrderStatusUnknownValueTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function orderIn($store, $merchant): Order
    {
        return Order::forceCreate([
            'product_order_id' => 'F81-' . uniqid(),
            'order_date' => now()->toDateString(),
            'customer_id' => 0,
            'is_guest' => 1,
            'product_json' => json_encode([['product_id' => 7, 'qty' => 2, 'price' => 33.0]]),
            'product_id' => '7',
            'product_price' => 33.0,
            'final_price' => 33.0,
            'payment_status' => 'Unpaid',
            'delivered_status' => 0,
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);
    }

    public function test_the_six_values_the_order_page_offers_all_work(): void
    {
        /* THE PREMISE FOR EVERYTHING BELOW, and the assertion that refutes the wrong hypothesis in
         * this file's header. `order/view.blade.php:101-143` offers exactly these six, and the page
         * sends them as `delivered`. If one of them stopped being handled, THAT is the serious
         * defect, and this is where it would surface. */
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');
        $this->grantPermission($merchant, 'Edit Order');

        $answers = [];

        foreach (['confirmed', 'pickedup', 'shipped', 'delivered', 'cancel'] as $value) {
            $order = $this->orderIn($store, $merchant);
            $response = $this->actingAs($merchant)
                ->post('/order-status-change/' . $order->id, ['delivered' => $value, 'id' => $order->id]);
            $answers[$value] = [$response->getStatusCode(), $response->json('status')];
        }

        foreach ($answers as $value => [$code, $status]) {
            $this->assertSame(200, $code, 'the order page offers "' . $value . '" and it answered '
                . $code . '; a dropdown item a merchant cannot use is the defect this test exists '
                . 'to catch');
            $this->assertSame('success', $status,
                '"' . $value . '" answered status=' . json_encode($status));
        }
    }

    /** @return list<array{0:string}> */
    public static function unknownValues(): array
    {
        return [
            'a value nothing handles' => ['no_such_status'],
            'the empty string' => [''],
            'a numeric status, which is what the COLUMN holds' => ['2'],
            /* The realistic one: a legitimate status whose arm is behind an inactive module. */
            'pre_order with the PreOrder module off' => ['pre_order'],
        ];
    }

    /**
     * @dataProvider unknownValues
     */
    public function test_an_unrecognised_status_is_refused_cleanly_on_the_web_route(string $value): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');
        $this->grantPermission($merchant, 'Edit Order');
        $order = $this->orderIn($store, $merchant);

        $before = (array) DB::table('orders')->where('id', $order->id)->first();

        $response = $this->actingAs($merchant)
            ->post('/order-status-change/' . $order->id, ['delivered' => $value, 'id' => $order->id]);

        $this->assertLessThan(500, $response->getStatusCode(),
            'an unrecognised status answered ' . $response->getStatusCode()
            . '. Before F81 this was a 500 from dereferencing a null return. Value: '
            . json_encode($value));

        $after = (array) DB::table('orders')->where('id', $order->id)->first();
        unset($before['updated_at'], $after['updated_at']);

        $this->assertSame($before, $after,
            'an unrecognised status changed the order row; it must change nothing at all');
    }

    public function test_the_model_method_always_returns_an_array(): void
    {
        /* The contract the three callers depend on, asserted directly. A caller added tomorrow
         * will dereference this the same way the three existing ones do. */
        [$merchant, $store] = $this->makeTenant();
        $order = $this->orderIn($store, $merchant);

        foreach (['no_such_status', '', 'pre_order', '2', 'delivered'] as $value) {
            $result = Order::order_status_change([
                'order_id' => $order->id,
                'order_status' => $value,
            ]);

            $this->assertIsArray($result,
                'order_status_change(' . json_encode($value) . ') returned '
                . get_debug_type($result) . '; all three callers do $result[\'status\'] on it');
            $this->assertArrayHasKey('status', $result, json_encode($value));
            $this->assertArrayHasKey('message', $result, json_encode($value));
        }
    }

    public function test_a_missing_order_is_still_reported_as_before(): void
    {
        // The arm F81 sits beside, so the fix cannot have displaced it.
        $result = Order::order_status_change(['order_id' => 999999, 'order_status' => 'delivered']);

        $this->assertIsArray($result);
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('not found', strtolower((string) $result['message']));
    }
}
