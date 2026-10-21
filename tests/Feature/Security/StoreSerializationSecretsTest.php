<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1-SEC-20 -- a `Store` row is serialised into anonymous responses, and the Duo2FA module puts two
 * secrets on it.
 * =============================================================================================
 *
 * The Donation and LotteryPrizes payment handlers answer their Telegram / WhatsApp / Khalti branches
 * with `response()->json([... 'store' => $store ...])` (Donation CustomerDonationController :294, :308;
 * LotteryPrizes LotteryPrizesController :628, :642), and the routes (`donation-telegram`, `ticket-
 * telegram`, ...) are in the `web` group with no authentication. `Store` had no `$hidden`, so every
 * column of the row went out -- the merchant's contact email and `created_by` always, and, once the
 * Duo2FA module has added them (`stores.duo_secret_key`, `stores.duo_integration_key`, migration
 * 2024_06_25_093610), the merchant's Duo secret and integration keys.
 *
 * MEASURED before the fix, through the real route with the Donation module enabled by the production
 * loader: HTTP 200, and the body contained both Duo values.
 *
 * The fix is on the model, not the modules (packages/workdo/ cannot be edited): the two columns are
 * `$hidden`, which changes SERIALISATION only. Every reader -- LaravelDuo.php:31-41, Duo2FAController
 * :159-160, the module's settings form -- reads them as properties, which `$hidden` does not touch; a test
 * below pins that.
 *
 * `customer_donations.theme_id` is written by the Donation handler but declared by none of the module's
 * migrations (schema drift in the vendored module), so without it the handler 500s before it reaches the
 * response and a "no secret in the body" assertion passes because there is no body. The column is added
 * here and the premise test asserts a real `"store":{` payload.
 *
 * Sentinels carry `FIXTURE` so the CI secret scan's generic-assigned-secret rule exempts them.
 */
class StoreSerializationSecretsTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const DUO_SECRET = 'FIXTURE-duo-secret-0001';

    private const DUO_INTEGRATION = 'FIXTURE-duo-integration-0001';

    private function withDuoColumns(): void
    {
        if (! Schema::hasColumn('stores', 'duo_secret_key')) {
            (require base_path('packages/workdo/Duo2FA/src/database/migrations/2024_06_25_093610_add_duo_keys_in_stores_table.php'))->up();
        }

        $this->assertTrue(Schema::hasColumn('stores', 'duo_secret_key'), 'Premise: the Duo2FA columns are on stores.');
    }

    private function storeWithDuo(): Store
    {
        $this->withDuoColumns();

        [, $store] = $this->makeTenant();

        DB::table('stores')->where('id', $store->id)->update([
            'duo_setting_enabled' => 'on',
            'duo_api_host_name' => 'api-FIXTURE.duosecurity.com',
            'duo_secret_key' => self::DUO_SECRET,
            'duo_integration_key' => self::DUO_INTEGRATION,
        ]);

        return Store::findOrFail($store->id);
    }

    public function test_the_model_does_not_serialise_the_duo_secrets(): void
    {
        $store = $this->storeWithDuo();

        $this->assertSame(self::DUO_SECRET, $store->getAttributes()['duo_secret_key'], 'Premise: the secret is on the loaded row.');

        foreach ([json_encode($store), $store->toJson(), json_encode($store->toArray()), json_encode(['store' => $store])] as $serialised) {
            $this->assertStringNotContainsString(self::DUO_SECRET, $serialised);
            $this->assertStringNotContainsString(self::DUO_INTEGRATION, $serialised);
        }
    }

    /** Every reader in the Duo2FA module uses property access, which `$hidden` must not break. */
    public function test_the_modules_own_property_reads_still_work(): void
    {
        $store = $this->storeWithDuo();

        $this->assertSame(self::DUO_SECRET, $store->duo_secret_key);
        $this->assertSame(self::DUO_INTEGRATION, $store->duo_integration_key);
        $this->assertSame('api-FIXTURE.duosecurity.com', $store->duo_api_host_name);

        // And the save path (Duo2FAController::..., `$store->update($post)`).
        $store->update(['duo_secret_key' => 'FIXTURE-duo-secret-0002']);
        $this->assertSame('FIXTURE-duo-secret-0002', DB::table('stores')->where('id', $store->id)->value('duo_secret_key'));
    }

    public function test_the_non_secret_store_columns_are_still_serialised(): void
    {
        $store = $this->storeWithDuo();

        $array = $store->toArray();

        foreach (['id', 'name', 'slug', 'theme_id', 'duo_setting_enabled', 'duo_api_host_name'] as $column) {
            $this->assertArrayHasKey($column, $array, "{$column} stopped being serialised.");
        }
    }

    private function enableDonation(): void
    {
        AddOnManager::create([
            'module' => 'Donation', 'name' => 'Donation', 'monthly_price' => 0, 'yearly_price' => 0,
            'is_enable' => 1, 'package_name' => 'donation',
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['Donation']);

        foreach (glob(base_path('packages/workdo/Donation/src/database/migrations/*.php')) ?: [] as $file) {
            (require $file)->up();
        }

        if (! Schema::hasColumn('customer_donations', 'theme_id')) {
            Schema::table('customer_donations', fn ($t) => $t->string('theme_id')->nullable());
        }

        $this->assertTrue(\Illuminate\Support\Facades\Route::has('donation.customer.telegram'), 'Premise: the Donation module is active.');
    }

    public function test_an_anonymous_donation_response_carries_no_duo_secret(): void
    {
        $store = $this->storeWithDuo();
        $this->enableDonation();
        DB::table('countries')->insertOrIgnore(['id' => 101, 'name' => 'Syria', 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->post('/' . $store->slug . '/donation-telegram', [
            'amount' => '10', 'donationpayment_method' => 'Telegram', 'name' => 'A', 'email' => 'a@b.test',
            'number' => '1', 'address' => 'x', 'country_id' => 101, 'state_id' => 1, 'city_id' => 1, 'donation' => 1,
        ]);

        $response->assertOk();
        $this->assertStringContainsString('"store":{', $response->getContent(), 'Premise: the response carries a serialised store, so the assertions below examine it.');
        $this->assertStringContainsString($store->slug, $response->getContent());
        $this->assertStringNotContainsString(self::DUO_SECRET, $response->getContent(), 'The Duo secret key was returned to an anonymous caller.');
        $this->assertStringNotContainsString(self::DUO_INTEGRATION, $response->getContent(), 'The Duo integration key was returned to an anonymous caller.');
    }
}
