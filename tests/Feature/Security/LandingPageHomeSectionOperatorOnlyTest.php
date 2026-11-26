<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1-SEC-85 — THE PLATFORM'S OWN HOME PAGE WAS WRITABLE BY ANY MERCHANT.
 *
 * `Workdo\LandingPage\...\HomeController` (vendored, not edited) states its own audience in
 * `index()`:
 *
 *     if (\Auth::user() && \Auth::user()->type == 'super admin') { ...the screen... }
 *     else { return redirect()->back()->with('error', __('Permission Denied.')); }
 *
 * That is the module saying this screen belongs to the platform operator. A grep for EVERY
 * authorization reference in the whole file -- `isAbleTo`, `'super admin'`, `authorize`, `Gate::`,
 * `__construct` -- returns exactly ONE line: that one, inside `index()`. So `store()`, `update()`,
 * `destroy()`, `create()`, `edit()` and `show()` carry none. The READ was gated and the WRITES
 * were not.
 *
 * `store()` iterates a hard-coded list of ten key names and writes them with
 * `LandingPageSetting::updateOrCreate(['name' => $key], ['value' => $value])`. `landing_page_settings`
 * is `id`, `name` (unique), `value`, `timestamps` -- NO `store_id` and NO `created_by` -- so it is
 * a single GLOBAL key-value table by design. There is no tenant predicate missing here; the rows
 * simply are not a tenant's. They are the content of the platform's PUBLIC home page.
 *
 * The route group is `['auth', 'web', 'xss', 'setlocate', 'verified']` with NO `PlanModuleCheck`,
 * and the eight routes `Route::resource('homesection', ...)` registers were in no manifest table,
 * so `ModuleAdministrativeRouteGuard` -- which IS on the request, measured -- had no rule and
 * returned `$next` before it read the user.
 *
 * LandingPage loads UNCONDITIONALLY: `PackageServiceProvider::register()` has
 * `$staticAddons = ['LandingPage', 'LynomiaChat']`, and the dynamic loader force-appends it again.
 * So the usual "no WorkDo module loads in a test run" caveat does not apply, and these routes
 * exist on every install.
 */
final class LandingPageHomeSectionOperatorOnlyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const KEY = 'home_heading';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (glob(base_path('packages/workdo/LandingPage/src/database/migrations/*.php')) ?: [] as $file) {
            if (! Schema::hasTable('landing_page_settings')) {
                (require $file)->up();
            }
        }

        $this->assertTrue(Schema::hasTable('landing_page_settings'),
            'PREMISE: the module table is absent, so every write below would error for reasons '
            . 'unrelated to authorization.');

        $this->assertNotNull(app('router')->getRoutes()->getByName('homesection.store'),
            'PREMISE: `POST homesection` is not registered, so this file measures nothing. '
            . 'LandingPage is supposed to load unconditionally through $staticAddons.');
    }

    private function value(string $name): ?string
    {
        $v = DB::table('landing_page_settings')->where('name', $name)->value('value');

        return $v === null ? null : (string) $v;
    }

    public function test_a_merchant_cannot_write_the_platforms_home_page(): void
    {
        [$merchant] = $this->makeTenant();

        $this->actingAs(User::find($merchant->id))->post('homesection', [
            self::KEY => 'Defaced by a tenant',
            'home_description' => 'and the description too',
        ]);

        $this->assertNotSame('Defaced by a tenant', $this->value(self::KEY),
            'A merchant rewrote the PLATFORM\'s public home page. '
            . '`Workdo\LandingPage\Http\Controllers\HomeController::store()` has no '
            . 'authorization check at all, while the `index()` it sits beside refuses everyone but '
            . 'the operator.');
    }

    /**
     * THE POSITIVE CONTROL, and this file is useless without it: "the row did not change" is also
     * what a 404, a redirect to login or a 500 looks like. If the operator cannot write either,
     * the refusal above says nothing about authorization.
     */
    public function test_the_platform_operator_can_still_write_it(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator)->post('homesection', [
            self::KEY => 'Set by the operator',
            'home_description' => 'operator description',
        ]);

        $this->assertSame('Set by the operator', $this->value(self::KEY),
            'The platform operator can no longer write their own home page, so the merchant '
            . 'refusal is vacuous -- it is satisfied by a door that works for nobody.');
    }

    public function test_an_anonymous_caller_cannot_write_it_either(): void
    {
        $this->post('homesection', [self::KEY => 'Anonymous']);

        $this->assertNotSame('Anonymous', $this->value(self::KEY));
    }
}
