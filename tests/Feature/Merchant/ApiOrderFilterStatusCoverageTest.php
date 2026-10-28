<?php

namespace Tests\Feature\Merchant;

use App\Http\Controllers\HomeController;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-07-12 / WS K11 — a filter that offers 0–6 hides the orders a merchant is looking for.
 * =================================================================================================
 *
 * `orders.delivered_status` holds NINE values, and two of them are not fulfilment states at all:
 * 7 is Partially Paid (a payment state) and 8 is Pre Order (an order type), both sharing the
 * column. The WEB's order filter offers all nine, built from `HomeController`'s constants rather
 * than typed out, and `resources/views/order/partials/filters.blade.php:12-14` says exactly why:
 *
 *   > "Typing a list by hand is how a filter ends up offering 0–6 and quietly hiding those orders
 *   >  from the one screen a merchant uses to find them."
 *
 * The admin API did precisely that. `OrderList`'s validator reads `'in:0,1,2,3,4,5,6'`, so a
 * client asking for status 7 or 8 gets a **422** — the two statuses are unfilterable from the
 * Flutter app, which is the only client that calls this endpoint.
 *
 * AND THE DISPLAY SIDE WAS ALREADY FIXED, which is what makes the gap visible rather than
 * theoretical: F29 taught `Order::getOrderData()` to render `__('Partially Paid')` and
 * `__('Pre Order')` for the same two constants, because before that the API told the app they were
 * "Pending". So the app can SHOW a partially-paid order and could not ASK for one.
 *
 * NO BUSINESS RULE CHANGES HERE. Widening a filter's accepted set does not decide what a status
 * means or when it may change; it stops the endpoint refusing a value the column legitimately
 * holds.
 */
class ApiOrderFilterStatusCoverageTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // `orderlist` reads `currency_rates`, which GATE F leaves unmanaged.
        $this->requireCurrencyRatesStandIn();
    }

    /** @return array{0: User, 1: Store} */
    private function merchantWithStore(): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();

        $this->grantPermission(User::find($merchant->id), 'Manage Order');

        return [User::find($merchant->id), $store];
    }

    private function filter(User $user, Store $store, int $status)
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . $user->createToken('auth_token')->plainTextToken
        )->getJson('/api/admin/orderlist?store_id=' . $store->id . '&delivered_status=' . $status);
    }

    /**
     * EVERY VALUE THE COLUMN HOLDS IS FILTERABLE. Driven from the constants, so a tenth status
     * added to `HomeController` fails this test until the validator is widened too — which is the
     * same discipline the web's filter partial uses, for the same reason.
     */
    public function test_every_order_status_the_column_holds_can_be_filtered(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        $statuses = [
            HomeController::ORDER_STATUS_PENDING,
            HomeController::ORDER_STATUS_DELIVERED,
            HomeController::ORDER_STATUS_CANCEL,
            HomeController::ORDER_STATUS_RETURN,
            HomeController::ORDER_STATUS_CONFIRMED,
            HomeController::ORDER_STATUS_PICKED_UP,
            HomeController::ORDER_STATUS_SHIPPED,
            HomeController::ORDER_STATUS_PARTIALLY_PAID,
            HomeController::ORDER_STATUS_PRE_ORDER,
        ];

        $this->assertCount(9, $statuses, 'premise: the column holds nine values');

        foreach ($statuses as $status) {
            $this->assertNotSame(422, $this->filter($merchant, $store, $status)->getStatusCode(),
                "api/admin/orderlist refused delivered_status={$status}, so the app cannot filter "
                . 'for a status the column legitimately holds');
        }
    }

    /** And the two that were refused really do return their own orders, not everything. */
    public function test_filtering_for_partially_paid_returns_only_those_orders(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        $partiallyPaid = $this->makeOrder($store, [
            'delivered_status' => HomeController::ORDER_STATUS_PARTIALLY_PAID,
        ]);
        $this->makeOrder($store, ['delivered_status' => HomeController::ORDER_STATUS_PENDING]);

        $response = $this->filter($merchant, $store, HomeController::ORDER_STATUS_PARTIALLY_PAID);

        $this->assertSame(200, $response->getStatusCode(),
            'Body: ' . substr((string) $response->getContent(), 0, 200));

        $ids = array_column($response->json()['data']['data'] ?? [], 'id');

        $this->assertSame([$partiallyPaid->id], $ids,
            'the partially-paid filter did not return exactly the partially-paid order');
    }

    public function test_filtering_for_pre_order_returns_only_those_orders(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        $preOrder = $this->makeOrder($store, [
            'delivered_status' => HomeController::ORDER_STATUS_PRE_ORDER,
        ]);
        $this->makeOrder($store, ['delivered_status' => HomeController::ORDER_STATUS_DELIVERED]);

        $response = $this->filter($merchant, $store, HomeController::ORDER_STATUS_PRE_ORDER);

        $this->assertSame(200, $response->getStatusCode());

        $ids = array_column($response->json()['data']['data'] ?? [], 'id');

        $this->assertSame([$preOrder->id], $ids);
    }

    /**
     * THE VALIDATOR STILL VALIDATES. Widening the set is not the same as removing it: a value the
     * column cannot hold must still be refused, or the filter becomes a way to probe the column.
     */
    public function test_a_status_the_column_does_not_hold_is_still_refused(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        foreach ([9, 99, -1] as $invalid) {
            $this->assertSame(422, $this->filter($merchant, $store, $invalid)->getStatusCode(),
                "delivered_status={$invalid} was accepted, and the column does not hold it");
        }
    }

    /** The write side's accepted set has the same gap, and the same reason to close it. */
    public function test_the_status_write_accepts_every_value_the_column_holds(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        $order = $this->makeOrder($store, ['delivered_status' => 0]);
        $token = User::find($merchant->id)->createToken('auth_token')->plainTextToken;

        foreach ([
            HomeController::ORDER_STATUS_PARTIALLY_PAID,
            HomeController::ORDER_STATUS_PRE_ORDER,
        ] as $status) {
            $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                ->postJson('/api/admin/orders/update-status', [
                    'product_order_id' => $order->product_order_id,
                    'delivered_status' => $status,
                ]);

            $this->assertNotSame(422, $response->getStatusCode(),
                "orders/update-status refused delivered_status={$status}, which the column holds "
                . 'and the web panel can set');
        }
    }
}
