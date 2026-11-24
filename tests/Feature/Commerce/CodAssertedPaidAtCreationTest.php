<?php

namespace Tests\Feature\Commerce;

use App\Models\Order;
use App\Models\User;
use App\Services\PaymentVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P4-F126 — A MERCHANT COULD ASSERT THAT AN UNCOLLECTED COD PARCEL WAS ALREADY PAID.
 * =================================================================================================
 *
 * Both manual order-creating doors take `payment_type` and `payment_status` from the caller and
 * write them verbatim, and **neither consults `PaymentVerificationService`**:
 *
 *   - `OrderController::store()` — the merchant web screen, validator `in:Paid,Unpaid`;
 *   - `Api\DashboardController::createOrderViaApi()` — the Flutter admin app, same shape.
 *
 * So `payment_type = 'cod'` with `payment_status = 'Paid'` was accepted on both, and
 * `OrderObserver::created()` then credited the merchant's wallet **at creation** — for a parcel
 * that has not left the building and cash nobody has handled.
 *
 * THAT IS THE LITERAL CONDITION OWNER DECISION D-06 FORBIDS, and the storefront already obeys it:
 * `resolveOrderPaymentStatus()`'s FIRST branch returns `STATUS_PENDING` unconditionally for COD and
 * logs "COD order recorded as pending collection". The gap was never the policy — it was that these
 * two doors never asked.
 *
 * OWNER INSTRUCTION, verbatim in the parts that constrain this file:
 *
 *   - "Uncollected COD must not be marked Paid."
 *   - "Enforce this on manual order creation as well as storefront checkout."
 *   - "If immediate counter cash sales need to be supported, distinguish them from delivery COD
 *      using an explicit supported payment type and actual collection evidence."
 *   - "Do not confuse a merchant asserting payment with payment verification."
 *   - "Preserve historical records without automatic reclassification."
 *
 * THE COUNTER SALE IS ALREADY SAYABLE, WHICH IS WHY NO NEW VOCABULARY IS INVENTED HERE.
 * `payment_type = 'POS'` already exists, is already written as a literal `Paid` by the POS door,
 * and is already listed in `PaymentVerificationService::OFFLINE_PAYMENT_TYPES` — the set whose own
 * comment reads "settles off-platform by arrangement, so record Paid at once". A merchant who
 * genuinely took cash across a counter records a POS sale. A merchant dispatching a parcel for
 * collection records COD, and COD is `Pending` until somebody collects.
 *
 * AND "ACTUAL COLLECTION EVIDENCE" ALREADY HAS A HOME THAT CANNOT YET BE FILLED.
 * `CodCollectionService` enforces exactly the four fields the owner named — collector type,
 * collector id, a strictly positive amount, a timestamp — refusing each rather than defaulting it,
 * and additionally refuses a partial collection. It stores nothing today because
 * `cod_collection_events` is declared by **no migration**, so `codMayBeSettled()` cannot return true
 * in shipped code. That is recorded, not worked around: creating that table is a production
 * migration and is not authorised here.
 *
 * NOTHING HISTORICAL IS RECLASSIFIED. This is a creation-time refusal only. Existing `cod` + `Paid`
 * rows keep their status, following the precedent this codebase already set for the withdrawn COD
 * trigger: "COD orders already marked Paid … keep that status. Nothing is reversed or back-filled."
 * Reclassifying them would move real money through `OrderObserver`.
 *
 * @see \App\Services\PaymentVerificationService::isCashOnDelivery()
 * @see \App\Services\CodCollectionService
 */
class CodAssertedPaidAtCreationTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: \App\Models\Store} */
    private function merchantWithStore(): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();
        $this->grantPermission(User::find($merchant->id), 'Manage Order');

        return [User::find($merchant->id), $store];
    }

    private function orderCount(): int
    {
        return (int) DB::table('orders')->count();
    }

    /**
     * A COMPLETE, VALID payload for the admin order-creation door.
     *
     * Built from the handler's own validator rather than guessed, and this matters: the first
     * version of this file sent a thin body, every request died on `store_id` (which is a QUERY
     * parameter, not a body field) and the two defect cases PASSED -- because no order was created,
     * for a reason that had nothing to do with COD. A test that cannot create an order cannot
     * measure a refusal to create one.
     */
    private function validPayload(\App\Models\Store $store, array $overrides = []): array
    {
        $product = \App\Models\Product::forceCreate([
            'name' => 'codorder', 'slug' => 'codorder-' . uniqid(),
            'price' => 10, 'sale_price' => 10, 'cost_price' => 4,
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'stock_status' => 'in_stock', 'status' => 1,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        // `status, 1` is part of the customer rule, so an inactive customer is refused for the
        // wrong reason.
        $customer = \App\Models\Customer::forceCreate([
            'first_name' => 'Cod', 'last_name' => 'Buyer',
            'email' => 'codorder' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'status' => 1, 'store_id' => $store->id,
        ]);

        return array_merge([
            'customer_id' => $customer->id,
            'products' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 10]],
            'payment_type' => 'cod',
            'payment_status' => 'Paid',
            'first_name' => 'Cod', 'last_name' => 'Buyer',
            'email' => 'codorder@example.test', 'telephone' => '1',
            'address' => 'x',
        ], $overrides);
    }

    /** `store_id` is a QUERY parameter on this route, not a body field. */
    private function createViaApi(User $user, \App\Models\Store $store, array $overrides = [])
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . $user->createToken('auth_token')->plainTextToken
        )->postJson(
            '/api/admin/createOrderViaApi?store_id=' . $store->id,
            $this->validPayload($store, $overrides)
        );
    }

    /**
     * THE SIEVE. A request that never reached the COD question proves nothing about it.
     *
     * Every "the order was not created" assertion below calls this first, so a payload fault,
     * a permission refusal or a tenant miss cannot stand in for the guard.
     */
    private function assertReachedTheCodQuestion($response): void
    {
        $body = (string) $response->getContent();

        foreach ([
            'store id field is required',
            'customer id',
            'products field is required',
            'do not have permission',
            'Store not found',
        ] as $fragment) {
            $this->assertStringNotContainsStringIgnoringCase($fragment, $body,
                'the request was refused before the COD question was asked -- a fixture fault, '
                . 'not evidence. Body: ' . substr($body, 0, 300));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PREMISE
    |--------------------------------------------------------------------------
    */

    /** PREMISE: the platform really does treat COD as pending at the storefront. */
    public function test_premise_the_storefront_records_cod_as_pending(): void
    {
        $resolved = app(PaymentVerificationService::class)
            ->resolveOrderPaymentStatus('cod', null, null, 'test');

        $this->assertSame(PaymentVerificationService::STATUS_PENDING, $resolved,
            'the storefront no longer records COD as Pending, so D-06 is not in force and this '
            . 'file is measuring a policy the platform does not have');
    }

    /** PREMISE: `cod` is recognised as COD, and `POS` is NOT. */
    public function test_premise_cod_and_pos_are_distinguishable(): void
    {
        $service = app(PaymentVerificationService::class);

        $this->assertTrue($service->isCashOnDelivery('cod'),
            'cod is not recognised as cash on delivery, so the guard cannot key on it');

        $this->assertFalse($service->isCashOnDelivery('POS'),
            'POS is treated as COD, so the counter sale and the parcel cannot be told apart — '
            . 'which is the distinction the owner asked for');

        $this->assertContains('pos', PaymentVerificationService::OFFLINE_PAYMENT_TYPES,
            'POS is no longer an offline settle-at-once type, so a counter sale has nowhere to go');
    }

    /*
    |--------------------------------------------------------------------------
    | THE DEFECT — both manual doors
    |--------------------------------------------------------------------------
    */

    /** THE DEFECT, at the Flutter admin door. */
    public function test_the_admin_api_refuses_cod_asserted_as_paid(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        $before = $this->orderCount();

        $response = $this->createViaApi($merchant, $store);

        $this->assertReachedTheCodQuestion($response);

        $this->assertSame($before, $this->orderCount(),
            'a COD order was created asserting the cash was already in hand. The observer credits '
            . 'the merchant at creation, so this is a wallet credit for a parcel that has not '
            . 'left. Body: ' . substr((string) $response->getContent(), 0, 300));

        $this->assertNotSame(401, $response->getStatusCode(),
            'a 401 force-logs-out the shipped app');
    }

    /** AND THE SAME DOOR STILL ACCEPTS A LEGITIMATE COD ORDER — it is not refusing COD itself. */
    public function test_the_admin_api_still_accepts_an_unpaid_cod_order(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        $before = $this->orderCount();

        $response = $this->createViaApi($merchant, $store, [
            'payment_status' => 'Unpaid',
        ]);

        $this->assertGreaterThan($before, $this->orderCount(),
            'an ordinary unpaid COD order was refused, so the guard is too wide and merchants '
            . 'cannot take COD at all. Body: ' . substr((string) $response->getContent(), 0, 300));
    }

    /** AND A COUNTER SALE IS STILL PAID AT CREATION — the case the owner explicitly preserved. */
    public function test_a_pos_counter_sale_may_still_be_paid_at_creation(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        $before = $this->orderCount();

        $response = $this->createViaApi($merchant, $store, [
            'payment_type' => 'POS',
            'payment_status' => 'Paid',
        ]);

        $this->assertGreaterThan($before, $this->orderCount(),
            'a POS counter sale asserting Paid was refused. The owner asked for counter sales to '
            . 'remain possible, distinguished from delivery COD by an explicit payment type — and '
            . 'POS is that type. Body: ' . substr((string) $response->getContent(), 0, 300));
    }

    /** A non-COD gateway type is unaffected in either direction. */
    public function test_a_gateway_order_is_unaffected(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        $before = $this->orderCount();

        $this->createViaApi($merchant, $store, [
            'payment_type' => 'stripe',
            'payment_status' => 'Paid',
        ]);

        $this->assertGreaterThan($before, $this->orderCount(),
            'a gateway order asserting Paid was refused by a guard that should only see COD');
    }

    /** THE SPELLINGS. `cash_on_delivery` and casing variants must not slip past. */
    public function test_every_cod_spelling_is_refused(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        foreach (['cod', 'COD', ' Cod ', 'cash_on_delivery'] as $spelling) {
            $before = $this->orderCount();

            $spellingResponse = $this->createViaApi($merchant, $store, [
                'payment_type' => $spelling,
                'payment_status' => 'Paid',
            ]);

            $this->assertReachedTheCodQuestion($spellingResponse);

            $this->assertSame($before, $this->orderCount(),
                'the spelling ' . var_export($spelling, true) . ' slipped past the guard. '
                . '`isCashOnDelivery()` lowercases and trims, so the guard must too.');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | THE WEB DOOR — the merchant's own order screen
    |--------------------------------------------------------------------------
    |
    | Driven by constructing the request and calling the controller, which is how
    | `OrderStoreOwnershipTest` drives this door: `OrderController::store()` answers
    | `redirect()->back()->with('error', ...)` on failure, so there is no status code to assert and
    | the flash message is the discriminator.
    */

    private function webPayload(\App\Models\Store $store, array $overrides = []): array
    {
        $product = \App\Models\Product::forceCreate([
            'name' => 'webcod', 'slug' => 'webcod-' . uniqid(),
            'price' => 10, 'sale_price' => 10, 'cost_price' => 4,
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'stock_status' => 'in_stock', 'status' => 1,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        $customer = \App\Models\Customer::forceCreate([
            'first_name' => 'Web', 'last_name' => 'Buyer',
            'email' => 'webcod' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'status' => 1, 'store_id' => $store->id,
        ]);

        return array_merge([
            'customer_id' => $customer->id,
            'products' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 10]],
            'payment_type' => 'cod',
            'payment_status' => 'Paid',
            'delivery_price' => 0,
            'tax_price' => 0,
            'final_price' => 10,
            'first_name' => 'Web', 'last_name' => 'Buyer',
            'email' => 'webcod@example.test', 'telephone' => '0100000000',
            'address' => '1 Test Street', 'city' => 'Damascus',
            'state' => 'Damascus', 'country' => 'SY', 'postcode' => '0000',
        ], $overrides);
    }

    private function postToWebDoor(User $merchant, \App\Models\Store $store, array $overrides = [])
    {
        $this->actingAs(User::find($merchant->id));

        $request = \Illuminate\Http\Request::create('/order', 'POST', $this->webPayload($store, $overrides));
        $request->setLaravelSession(app('session.store'));

        return (new \App\Http\Controllers\OrderController())->store($request);
    }

    /** THE DEFECT, at the merchant web door. */
    public function test_the_web_door_refuses_cod_asserted_as_paid(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        $before = $this->orderCount();

        $this->postToWebDoor($merchant, $store);

        $this->assertSame($before, $this->orderCount(),
            'the merchant web screen created a COD order asserting the cash was already in hand, '
            . 'which credits the wallet at creation for a parcel that has not left');
    }

    /** And the same door still takes an ordinary COD order. */
    public function test_the_web_door_still_accepts_an_unpaid_cod_order(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        $before = $this->orderCount();

        $this->postToWebDoor($merchant, $store, ['payment_status' => 'Unpaid']);

        $this->assertGreaterThan($before, $this->orderCount(),
            'an ordinary unpaid COD order was refused on the web screen, so the guard is too wide');
    }

    /*
    |--------------------------------------------------------------------------
    | HISTORY IS NOT TOUCHED
    |--------------------------------------------------------------------------
    */

    /**
     * AN EXISTING `cod` + `Paid` ROW KEEPS ITS STATUS.
     *
     * Owner: "Preserve historical records without automatic reclassification." Reclassifying would
     * move real money through `OrderObserver`, which is exactly what the withdrawn COD trigger's
     * own note warned about.
     */
    public function test_an_existing_cod_paid_order_is_not_reclassified(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        $order = $this->makeOrder($store, [
            'payment_type' => 'cod',
            'payment_status' => 'Paid',
            'product_json' => '[]',
        ]);

        /* Something unrelated happens to the order. */
        $fresh = Order::withoutGlobalScopes()->find($order->id);
        $fresh->delivery_comment = 'touched';
        $fresh->save();

        $this->assertSame('Paid',
            (string) DB::table('orders')->where('id', $order->id)->value('payment_status'),
            'an existing cod+Paid order was reclassified. The guard is a CREATION-time refusal and '
            . 'must never reach back.');
    }
}
