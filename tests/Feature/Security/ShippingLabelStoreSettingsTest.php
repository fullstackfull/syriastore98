<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * P1-SEC-11 -- A SHIPPING LABEL PRINTED ANOTHER STORE'S ADDRESS.
 * ==============================================================
 *
 * OrderController::shippinglabel() read the "FROM" address through `Utility::Setting()` with no argument. For a signed-in user that reads
 * `settings` for (the user's tenant, the user's CURRENT store); when that store has no rows of its own it FALLS BACK to
 * `Setting::where('created_by', 1)->get()` -- every setting row of user id 1, whatever store it belongs to, last row winning. So a label for
 * merchant A's own order printed the address of whichever store user 1 configured last (MEASURED in the P1-SEC-03 round-2 review: store B's
 * address, city, state, zip and country at HTTP 200, twice; the transcript was lost, so this is that measurement re-established).
 *
 * (The second shape the ledger suspected - a merchant with two stores printing a label for the store they are NOT in - cannot occur:
 * OrderDocumentAccessGuard refuses an order outside the merchant's current store with a 403, so the only order reaching the handler is the
 * current store's.)
 *
 * The label now prints the settings of THE ORDER'S store and nothing else's: a store with none prints an empty FROM block.
 */
class ShippingLabelStoreSettingsTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function setting(int $storeId, int $createdBy, string $name, string $value): void
    {
        DB::table('settings')->insert(['name' => $name, 'value' => $value, 'store_id' => $storeId, 'created_by' => $createdBy, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function address(int $storeId, int $createdBy, string $tag): void
    {
        foreach (['store_address' => "{$tag}-STREET", 'store_city' => "{$tag}-CITY", 'store_state' => "{$tag}-STATE", 'store_zipcode' => "{$tag}-ZIP", 'store_country' => "{$tag}-COUNTRY"] as $name => $value) {
            $this->setting($storeId, $createdBy, $name, $value);
        }
    }

    private function label($actor, $order)
    {
        Cache::forget('admin_details');

        return $this->actingAs($actor->fresh())->get('/shippinglabel/pdf/' . Crypt::encrypt($order->id));
    }

    public function test_a_store_without_settings_does_not_print_the_platform_users_last_configured_address(): void
    {
        $operator = $this->makePlatformAdmin();                       // user id 1: the `created_by = 1` fallback
        $this->assertSame(1, (int) $operator->id, 'Premise: the platform user is id 1, the literal the fallback reads.');
        $operatorStore = $this->makeStore($operator);
        $this->address($operatorStore->id, 1, 'OTHERSTORE');

        [$merchant, $store] = $this->makeTenant();                   // no settings rows of its own
        $this->grantPermission($merchant, 'Show Order');
        $order = $this->makeOrder($store);

        $response = $this->label($merchant, $order);

        $response->assertOk();
        $this->assertStringNotContainsString('OTHERSTORE', $response->getContent(), "A label printed another store's address.");
    }

    public function test_the_label_prints_the_address_of_the_orders_own_store(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Order');
        $this->address($store->id, $merchant->id, 'OWNSTORE');
        $order = $this->makeOrder($store);

        $response = $this->label($merchant, $order);

        $response->assertOk();
        foreach (['STREET', 'CITY', 'STATE', 'ZIP', 'COUNTRY'] as $part) {
            $this->assertStringContainsString("OWNSTORE-{$part}", $response->getContent(), "Premise: the order's own store address is printed ({$part}).");
        }
    }

    /**
     * The handler is correct by construction, not only by virtue of the guard in front of it: called DIRECTLY (as a future route or a changed
     * guard would), a merchant whose current store is A and an order of store B still gets store B's address.
     */
    public function test_the_handler_itself_reads_the_orders_store_not_the_viewers(): void
    {
        [$merchant, $current] = $this->makeTenant();
        $other = $this->makeStore($merchant);
        $this->address($current->id, $merchant->id, 'CURRENTSTORE');
        $this->address($other->id, $merchant->id, 'ORDERSTORE');
        $order = $this->makeOrder($other);
        $this->actingAs($merchant->fresh());
        Cache::forget('admin_details');

        $view = app(\App\Http\Controllers\OrderController::class)->shippinglabel(request(), Crypt::encrypt($order->id));

        $this->assertInstanceOf(\Illuminate\Contracts\View\View::class, $view);
        $this->assertSame('ORDERSTORE-STREET', $view->getData()['settings']['store_address']);
        $this->assertSame('ORDERSTORE-COUNTRY', $view->getData()['settings']['store_country']);
    }
}
