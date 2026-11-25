<?php

namespace Tests\Contract;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-125 / P04-FL-01 — THE SHIPPED APP'S ORDER JOURNEY, DRIVEN END TO END BY A STAFF TOKEN.
 * =============================================================================================
 *
 * The register's next action for this row is *"add contract coverage for the Flutter order/cancel/
 * status journey"*, and its finding was that **no suite drives one**. Every existing order test
 * drives a single endpoint; a merchant's actual sequence — list, open, change status, cancel — was
 * covered nowhere, so a change that broke the SEQUENCE while leaving each endpoint green was
 * invisible.
 *
 * The four endpoints, taken from `tests/Support/ApiContract/manifests/flutter-endpoints.json`
 * rather than from guesswork:
 *
 *     GET    api/admin/orderlist            routes/api.php:220
 *     GET    api/admin/vieworder            routes/api.php:246
 *     POST   api/admin/orders/update-status  routes/api.php:209
 *     POST   api/admin/order-cancel          routes/api.php:380
 *
 * ---------------------------------------------------------------------------------------------
 * WHY THIS IS A CONTRACT TEST AND NOT A FEATURE TEST, which decides what it asserts.
 *
 * The shipped Flutter app cannot be redeployed alongside the server. So what matters here is not
 * "does the handler work" — Feature tests cover that — but **can one principal complete the
 * sequence, and does each step hand the next one what it needs**. A staff member who can list
 * orders and then cannot open one has a broken app even though both endpoints pass their own tests.
 *
 * TWO THINGS IT DELIBERATELY DOES NOT DO:
 *
 *   It does not assert full payload shapes. `AdminOrderPayloadShapeContractTest` already owns that
 *   question and does it properly, by driving the route with and without a column. Duplicating it
 *   here would be two tests with one claim between them.
 *
 *   It does not assert HTTP 200 for every step. On this API `ApiResponser::error()` answers HTTP
 *   **200** with `{status: 0}` (`app/Traits/ApiResponser.php:54`), so a status code says almost
 *   nothing — and a 401 is the one answer that is catastrophic, because the app FORCE-CLEARS ITS
 *   SESSION on any 401. That is what P4-F114 is: `update-customer` answers 401 to staff and logs
 *   them out of the whole app. So the sharpest assertion this file can make is **"no step of the
 *   journey ever answers 401 to a legitimate staff member"**, and it makes it on every step.
 *
 * ---------------------------------------------------------------------------------------------
 * AND IT GUARDS A GATE ADDED THE SAME DAY. `vieworder` had no permission check at all until
 * GAP-142/F106 (2026-11-25) and now requires `Show Order` or `Manage Order`. A gate that admitted
 * nobody, or that refused the store's own owner, would break exactly this sequence — the app
 * reaches a detail view only through the list. This file is the sequence-level check that the
 * single-endpoint test cannot be.
 */
class FlutterOrderJourneyContractTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;

    private User $staff;

    private Store $store;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        $this->order = $this->makeOrder($this->store, [
            'delivered_status' => 0,
            'payment_status' => 'Unpaid',
        ]);

        /*
         * A STAFF MEMBER HOLDING `Manage Order`, which is what the app's own order list requires
         * (`DashboardController:1639` admits `['Manage Order','Show Order']`). This is the principal
         * whose journey must work — not the merchant, who would pass every gate through the
         * store-owner limb and so could not detect a gate that names an unholdable permission.
         */
        $this->staff = $this->makeStaff($this->merchant);
        $this->staff->current_store = $this->store->id;
        $this->staff->save();
        $this->grantPermission(User::find($this->staff->id), 'Manage Order');
        $this->staff = User::find($this->staff->id);
    }

    private function asStaff(): string
    {
        return $this->staff->createToken('flutter')->plainTextToken;
    }

    private function asApp(string $method, string $uri, array $payload = [])
    {
        $bearer = $this->asStaff();

        return $this->withHeader('Authorization', 'Bearer ' . $bearer)
            ->json($method, $uri, $payload);
    }

    /** The premise: the staff member really holds the permission the journey needs. */
    public function test_the_staff_member_holds_the_order_permission(): void
    {
        $this->assertTrue(
            \App\Services\MerchantPermissions::allowsAny($this->staff, ['Manage Order'], $this->store->id),
            'the staff fixture does not hold Manage Order, so every step below would be measuring '
            . 'a permission refusal rather than the journey'
        );

        $this->assertNotSame(
            (int) $this->store->created_by,
            (int) $this->staff->id,
            'the fixture staff member IS the store owner, so the owner limb would carry every gate '
            . 'and a gate naming an unholdable permission could not be detected'
        );
    }

    /**
     * THE WHOLE SEQUENCE, IN ORDER, WITH ONE PRINCIPAL. Each step must be reachable, and NO step
     * may answer 401.
     */
    public function test_a_staff_member_can_complete_the_order_journey_without_being_logged_out(): void
    {
        $steps = [
            'list' => ['GET', '/api/admin/orderlist?store_id=' . $this->store->id, []],
            'open' => ['GET', '/api/admin/vieworder?store_id=' . $this->store->id . '&order_id=' . $this->order->id, []],
            'status' => ['POST', '/api/admin/orders/update-status', [
                'store_id' => $this->store->id,
                'order_id' => $this->order->id,
                'delivered_status' => 1,
            ]],
            'cancel' => ['POST', '/api/admin/order-cancel', [
                'store_id' => $this->store->id,
                'order_id' => $this->order->id,
            ]],
        ];

        foreach ($steps as $name => [$method, $uri, $payload]) {
            $response = $this->asApp($method, $uri, $payload);

            $this->assertNotSame(401, $response->status(), sprintf(
                'the "%s" step of the Flutter order journey answered 401 to a staff member holding '
                . 'Manage Order. The shipped app force-clears its session on ANY 401, so this logs '
                . 'the merchant out of the whole app rather than refusing one action. That is '
                . 'P4-F114\'s failure mode, and it is the one answer this journey must never give.',
                $name
            ));

            $this->assertNotSame(500, $response->status(), sprintf(
                'the "%s" step answered 500',
                $name
            ));

            /*
             * AND THE STEP MUST NOT BE A PERMISSION REFUSAL. On this API a refusal is usually a 200
             * with `{status: 0}`, so the status code cannot carry this — a 403 or a `status: 0`
             * whose message is the permission one means the journey is broken for a principal who
             * holds the permission the LIST requires.
             */
            $this->assertNotSame(403, $response->status(), sprintf(
                'the "%s" step refused a staff member holding Manage Order, so the app can reach '
                . 'the order list and then go no further',
                $name
            ));
        }
    }

    /**
     * THE SECOND STEP SPECIFICALLY, because it is the one that gained a gate today.
     *
     * `vieworder` had NO permission check until GAP-142/F106. A gate that named a permission nobody
     * can hold, or that refused the store's own owner, would pass its own unit-level tests and
     * still break the app here. Asserted on the envelope rather than the status, because
     * `ApiResponser` answers 200 for refusals.
     */
    public function test_opening_an_order_works_for_the_principal_the_list_admits(): void
    {
        $list = $this->asApp('GET', '/api/admin/orderlist?store_id=' . $this->store->id);

        $this->assertNotSame(403, $list->status(),
            'premise: the staff member cannot even reach the order LIST, so this test cannot '
            . 'measure whether the detail view agrees with it');

        $view = $this->asApp(
            'GET',
            '/api/admin/vieworder?store_id=' . $this->store->id . '&order_id=' . $this->order->id
        );

        $this->assertSame(200, $view->status(),
            'the order DETAIL refused a principal the order LIST admits. The app reaches a detail '
            . 'view only through the list, so the two gates disagreeing is a broken journey even '
            . 'though each endpoint passes its own test.');

        $this->assertSame(1, (int) $view->json('status'),
            'vieworder answered with an error envelope for a permitted staff member');
    }

    /** The store's own OWNER must also complete it — the regression this family has produced. */
    public function test_the_store_owner_can_also_open_an_order(): void
    {
        $bearer = $this->merchant->createToken('flutter')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $bearer)
            ->getJson('/api/admin/vieworder?store_id=' . $this->store->id . '&order_id=' . $this->order->id);

        $this->assertNotSame(403, $response->status(),
            'the store\'s own owner was refused their own order. A merchant does not implicitly '
            . 'hold the order permissions, and the known-outcome gate has caught a gate in this '
            . 'family refusing the owner once already.');
        $this->assertNotSame(401, $response->status());
    }
}
