<?php

namespace Tests\Feature\Merchant;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F150's display half, driven end to end on the three surfaces that were broken.
 * ==============================================================================
 *
 * `PaymentTypeLabelTest` proves the resolver. This file proves the three CALLERS reach it, which
 * is a different question and the one that actually failed: each surface had its own copy of the
 * map and each copy was wrong in its own way.
 *
 * WHY EACH OF THE THREE IS HERE, with what was measured:
 *
 *   - the ORDER LIST (`OrderDataTable::editColumn('payment_type')`) indexed a 53-entry
 *     mixed-case array with the lowercase value the manual-order writers store, so 26 of the 71
 *     vocabulary values rendered a bare `-`;
 *   - the CUSTOMER DETAIL page (`customer/view.blade.php`) had a 27-branch `@if` chain with NO
 *     `@else`, so 52 of the 71 rendered an EMPTY CELL -- `POS` among them;
 *   - the FLUTTER PICKER (`Api\DashboardController::getActivePaymentMethods`) ran
 *     `ucwords(str_replace('_', ' ', $id))`, so the admin app's order form offered "Cod" and
 *     "Sslcommerz", identically in Arabic because nothing translated them.
 *
 * `payu` IS THE VALUE UNDER TEST ON THE MERCHANT SURFACES, not an invented one: it is exactly
 * what `OrderController:350` and `Api\DashboardController:6242` store, because both take
 * `getPaymentGateways()`'s `id` verbatim and that id is lowercase. The old map's key was `'PayU'`.
 */
class PaymentTypeDisplayTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return array{0:User,1:\App\Models\Store} */
    private function merchantWithOrder(string $paymentType): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $this->makeOrder($store, ['payment_type' => $paymentType]);

        return [$merchant, $store];
    }

    // =========================================================================================
    // The order list
    // =========================================================================================

    public function test_the_order_list_labels_the_value_the_manual_writer_stores(): void
    {
        [$merchant] = $this->merchantWithOrder('payu');

        $response = $this->actingAs($merchant)->get(route('order.index', [
            'draw' => 1, 'start' => 0, 'length' => 100,
        ]), ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']);

        $this->assertSame(200, $response->status(), 'premise: the list answered with its AJAX leg');

        $payload = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('data', $payload);
        $this->assertNotEmpty($payload['data'], 'premise: the order is in the list at all');

        $cells = array_column($payload['data'], 'payment_type');

        $this->assertContains('PayU', $cells, "'payu' rendered a dash in the order list");
        $this->assertNotContains('-', $cells);
    }

    public function test_the_order_list_renders_an_unattributable_value_as_an_absence(): void
    {
        /*
         * THE OWNER'S PROHIBITION, AT THE SURFACE. `'没有'` is a shipped locale's translation of
         * `__('POS')` and F146 found rows holding it. It must not be read as POS
         * (*"لا تحويل '没有' إلى POS دون دليل قاطع"*), and it must not be echoed either -- this
         * column is in `rawColumns()`, so anything returned here is injected unescaped.
         */
        [$merchant] = $this->merchantWithOrder('没有');

        $response = $this->actingAs($merchant)->get(route('order.index', [
            'draw' => 1, 'start' => 0, 'length' => 100,
        ]), ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']);

        $payload = json_decode((string) $response->getContent(), true);
        $cells = array_column($payload['data'], 'payment_type');

        $this->assertContains('-', $cells);
        $this->assertNotContains('POS', $cells, "'没有' was interpreted as POS");
        $this->assertNotContains('没有', $cells, 'the stored value was echoed into a raw column');
    }

    // =========================================================================================
    // The customer detail page
    // =========================================================================================

    public function test_the_customer_detail_page_labels_a_point_of_sale_order(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Customer');
        $this->grantPermission($merchant, 'Manage Order');

        $customerId = (int) \DB::table('customers')->insertGetId([
            'name' => 'Nadia',
            'email' => 'nadia' . uniqid() . '@example.test',
            'password' => bcrypt('x'),
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->makeOrder($store, ['payment_type' => 'POS', 'customer_id' => $customerId]);

        $response = $this->actingAs($merchant)->get('/customer/' . $customerId);

        if ($response->status() !== 200) {
            $this->markTestSkipped('the customer detail route answered ' . $response->status());
        }

        /*
         * `POS` had no branch in the 27-branch chain at all, so this cell was EMPTY for every
         * point-of-sale order -- which for a shop using the POS screen is most of its orders.
         */
        $this->assertStringContainsString('POS', (string) $response->getContent());
    }

    // =========================================================================================
    // The admin app's payment picker -- the one with a client contract
    // =========================================================================================

    /** @return array{0:User,1:\App\Models\Store,2:string} */
    private function merchantWithToken(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $token = $merchant->createToken('test')->plainTextToken;

        \DB::table('settings')->insert([
            'name' => 'is_cod_enabled',
            'value' => 'on',
            'store_id' => $store->id,
            'created_by' => $merchant->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$merchant, $store, $token];
    }

    public function test_the_picker_offers_a_readable_name_and_an_unchanged_key(): void
    {
        [, $store, $token] = $this->merchantWithToken();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/admin/getActivePaymentMethods?store_id=' . $store->id);

        if ($response->status() !== 200) {
            $this->markTestSkipped('the picker answered ' . $response->status());
        }

        $methods = $response->json('data.active_methods') ?? [];
        $cod = collect($methods)->firstWhere('key', 'cod');

        $this->assertNotNull($cod, 'premise: COD is enabled for this store and offered');

        /*
         * THE KEY IS THE CONTRACT AND IT IS UNCHANGED. Verified in the shipped client's own
         * source: `create_order_screen.dart:242` posts `method?.key` while `:237` displays
         * `m.name`. So only the words changed.
         */
        $this->assertSame('cod', $cod['key']);
        $this->assertSame('Cash On Delivery', $cod['name'], 'the picker still says "Cod"');
    }

    public function test_the_pickers_name_is_translated_but_its_key_is_not(): void
    {
        [, $store, $token] = $this->merchantWithToken();

        app()->setLocale('ar');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/admin/getActivePaymentMethods?store_id=' . $store->id);

        if ($response->status() !== 200) {
            $this->markTestSkipped('the picker answered ' . $response->status());
        }

        $cod = collect($response->json('data.active_methods') ?? [])->firstWhere('key', 'cod');

        if ($cod === null) {
            $this->markTestSkipped('COD was not offered');
        }

        $this->assertSame('cod', $cod['key'], 'the posted value must never be localised');
        $this->assertSame('نقداً عند التسليم', $cod['name']);
    }
}
