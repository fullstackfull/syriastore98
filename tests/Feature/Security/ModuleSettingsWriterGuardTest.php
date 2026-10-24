<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Security\EnablesWorkdoModules;

/**
 * P1-SEC-31 -- VENDORED MODULE CONTROLLERS THAT WRITE `settings` BY CALLER-SUPPLIED NAME, WITH NO PERMISSION CHECK.
 * ===================================================================================================================
 *
 * Roughly thirty WorkDo module handlers do `foreach ($request->all() as $name => $value) Setting::updateOrCreate(['name' => $name, store...])`,
 * and their routes carry no permission middleware (four, as P1-SEC-04 found, not even authentication). So any signed-in user of a store - staff
 * holding neither settings permission included - could write MAIL_*, a gateway secret, `bank_transfer`, `CURRENCY_NAME`, or the domain-routing
 * names by posting them to a module's settings route. packages/workdo/ is vendored; the control is host-side
 * (ModuleAdministrativeRouteGuard rules `settings:store` and `settings:global`, entries in App\Support\ModuleRouteManifest).
 *
 * Design choice recorded (acceptance criterion 3): ROUTE MIDDLEWARE KEYED ON METHOD+URI, not a `saving` rule on App\Models\Setting. The route
 * guard covers every writer including raw ones (`DB::table('settings')`, `insert ... on duplicate key`) and refuses BEFORE the handler runs, so
 * nothing is half-written; a model rule covers only Eloquent writers (which the Setting `saving` hook of P1-SEC-29 already does for host claims)
 * and cannot know the writing screen. The census test (ModuleSettingsWriterCensusTest) fails when a module adds a writer the manifest does not
 * route through one of the rules.
 */
class ModuleSettingsWriterGuardTest extends SecurityTestCase
{
    use EnablesWorkdoModules;
    use RefreshDatabase;

    private function enable(array $modules): void
    {
        $failed = $this->enableWorkdoModules($modules);

        foreach ($modules as $module) {
            $this->assertNotEmpty(
                array_filter(Route::getRoutes()->getRoutes(), fn ($r) => str_starts_with(ltrim($r->getActionName(), '\\'), 'Workdo\\' . $module . '\\')),
                "Premise: {$module} is not loaded. Failed migrations: " . implode('; ', $failed)
            );
        }
    }

    private function entitle(\App\Models\User $owner, string $module): void
    {
        DB::table('user_active_modules')->insert(['user_id' => $owner->id, 'module' => $module, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertContains($module, ActivatedModule($owner->id), 'The entitlement fixture did not take.');
    }

    private function stored(int $storeId, string $name): ?string
    {
        $value = DB::table('settings')->where('store_id', $storeId)->where('name', $name)->value('value');

        return $value === null ? null : (string) $value;
    }

    // ---- settings:store  (BoostSales, /boost-sales-setting) ------------------------------------

    public function test_an_anonymous_caller_writes_no_store_setting(): void
    {
        $this->enable(['BoostSales']);
        [, $store] = $this->makeTenant();

        $response = $this->post('/boost-sales-setting', ['MAIL_HOST' => 'attacker.example.test', 'boost_sales_enabled' => 'on']);

        $this->assertTrue($response->isRedirect() || $response->status() === 403);
        $this->assertNull($this->stored($store->id, 'MAIL_HOST'));
        $this->assertSame(0, DB::table('settings')->where('name', 'MAIL_HOST')->count());
    }

    public function test_staff_with_no_settings_permission_write_nothing(): void
    {
        $this->enable(['BoostSales']);
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);

        $this->actingAs($staff->fresh())->post('/boost-sales-setting', ['MAIL_HOST' => 'attacker.example.test', 'boost_sales_enabled' => 'on']);

        $this->assertNull($this->stored($store->id, 'MAIL_HOST'), 'Staff with no permission wrote a restricted setting by name.');
        $this->assertNull($this->stored($store->id, 'boost_sales_enabled'), 'Staff with no permission wrote an ordinary module setting.');
    }

    /** An ORDINARY-names-only post: the refusal here is the permission, not the restricted-name rule (which the test above exercises). */
    public function test_staff_with_no_settings_permission_cannot_save_even_ordinary_names(): void
    {
        $this->enable(['BoostSales']);
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);

        $response = $this->actingAs($staff->fresh())->post('/boost-sales-setting', ['boost_sales_enabled' => 'on', 'boost_sales_product_display' => 'grid']);

        $this->assertTrue($response->isRedirect() || $response->status() === 403);
        $this->assertNull($this->stored($store->id, 'boost_sales_enabled'), 'Staff with neither settings permission saved a module setting.');
    }

    public function test_staff_holding_only_the_store_setting_permission_save_ordinary_names(): void
    {
        $this->enable(['BoostSales']);
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);
        $this->grantPermission($staff, 'Manage Store Setting');

        $this->actingAs($staff->fresh())->post('/boost-sales-setting', ['boost_sales_enabled' => 'on', 'boost_sales_product_display' => 'grid']);

        $this->assertSame('on', $this->stored($store->id, 'boost_sales_enabled'), 'A Store Setting holder can no longer save an ordinary module setting.');
    }

    /** The whole post is refused (as the three Store Setting handlers do, P1-SEC-27): nothing of it is written, restricted or not. */
    public function test_a_store_setting_holder_posting_a_restricted_name_has_the_whole_post_refused(): void
    {
        $this->enable(['BoostSales']);
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);
        $this->grantPermission($staff, 'Manage Store Setting');

        foreach (['MAIL_HOST' => 'attacker.example.test', 'stripe_secret_key' => 'sk_live_FIXTURE-not-a-secret', 'domains' => 'attacker.example.test', 'CURRENCY_NAME' => 'XXX', 'bank_transfer' => 'on'] as $name => $value) {
            $this->actingAs($staff->fresh())->post('/boost-sales-setting', ['boost_sales_enabled' => 'on', $name => $value]);

            $this->assertNull($this->stored($store->id, $name), "A Store Setting holder wrote the restricted name {$name} through a module route.");
            $this->assertNull($this->stored($store->id, 'boost_sales_enabled'), "The ordinary part of a post carrying {$name} was written; the post must be refused whole.");
        }
    }

    public function test_the_owner_and_the_operator_keep_full_use_of_the_route(): void
    {
        $this->enable(['BoostSales']);
        [$owner, $store] = $this->makeTenant();

        $this->actingAs($owner->fresh())->post('/boost-sales-setting', ['boost_sales_enabled' => 'on', 'MAIL_HOST' => 'smtp.owner.example.test']);

        $this->assertSame('on', $this->stored($store->id, 'boost_sales_enabled'), 'Premise: the owner can still save the tab.');
        $this->assertSame('smtp.owner.example.test', $this->stored($store->id, 'MAIL_HOST'), 'The owner (a Manage Setting principal) may write any name.');
    }

    // ---- settings:global  (GoogleLogin, /googlelogin-setting) ----------------------------------

    private function googleLoginPost(): array
    {
        return ['google_login_setting_enabled' => 'on', 'Google_authorized_url' => 'https://example.test/cb', 'Google_client_id' => 'client-id-FIXTURE', 'Google_client_secret_key' => 'client-secret-FIXTURE'];
    }

    public function test_a_store_setting_holder_cannot_write_integration_credentials(): void
    {
        $this->enable(['GoogleLogin']);
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);
        $this->grantPermission($staff, 'Manage Store Setting');
        $this->entitle($owner, 'GoogleLogin');

        $this->actingAs($staff->fresh())->post('/googlelogin-setting', $this->googleLoginPost());

        $this->assertNull($this->stored($store->id, 'Google_client_secret_key'), 'A Store Setting holder wrote an OAuth client secret.');
    }

    public function test_staff_with_the_settings_permission_and_the_owner_can_save_the_integration(): void
    {
        $this->enable(['GoogleLogin']);
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);
        $this->grantPermission($staff, 'Manage Setting');
        $this->entitle($owner, 'GoogleLogin');

        $this->actingAs($staff->fresh())->post('/googlelogin-setting', $this->googleLoginPost());
        $this->assertSame('client-secret-FIXTURE', $this->stored($store->id, 'Google_client_secret_key'), 'Premise: a Manage Setting holder can still save it.');

        DB::table('settings')->where('store_id', $store->id)->delete();
        \App\Models\Setting::query()->getModel()->flushCache();

        $this->actingAs($owner->fresh())->post('/googlelogin-setting', $this->googleLoginPost());
        $this->assertSame('client-secret-FIXTURE', $this->stored($store->id, 'Google_client_secret_key'), 'Premise: the owner can still save it.');
    }

    public function test_staff_with_no_permission_cannot_write_the_integration(): void
    {
        $this->enable(['GoogleLogin']);
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);
        $this->entitle($owner, 'GoogleLogin');

        $this->actingAs($staff->fresh())->post('/googlelogin-setting', $this->googleLoginPost());

        $this->assertNull($this->stored($store->id, 'Google_client_secret_key'));
    }

    /** settings:global means `Manage Setting` OUTRIGHT: a Store Setting holder is refused even when every posted name is ordinary (Tawk.to posts two). */
    public function test_a_store_setting_holder_cannot_write_an_ordinary_named_integration(): void
    {
        $this->enable(['TawktoMessenger']);
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);
        $this->grantPermission($staff, 'Manage Store Setting');
        $this->entitle($owner, 'TawktoMessenger');

        $this->actingAs($staff->fresh())->post('/tawk-to-setting', ['tawkto_setting_enabled' => 'on', 'tawk_to_messenger' => 'FIXTURE-widget']);
        $this->assertNull($this->stored($store->id, 'tawk_to_messenger'), 'A Store Setting holder saved an integration that needs Manage Setting.');

        $holder = $this->makeStaff($owner);
        $this->grantPermission($holder, 'Manage Setting');
        $this->actingAs($holder->fresh())->post('/tawk-to-setting', ['tawkto_setting_enabled' => 'on', 'tawk_to_messenger' => 'FIXTURE-widget']);
        $this->assertSame('FIXTURE-widget', $this->stored($store->id, 'tawk_to_messenger'), 'Premise: a Manage Setting holder can still save it.');
    }
}
