<?php

namespace Tests\Feature\Theme;

use App\Facades\ModuleFacade as Module;
use App\Models\Addon;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M4-0 — THE FOUR GET ROUTES OF THE THEME EDITOR, DRIVEN OVER HTTP FOR THE FIRST TIME.
 * =================================================================================================
 *
 * WHY THIS FILE EXISTS. Before it, **no test in the suite issued a GET against any theme route** --
 * measured, `grep -rn "get('/theme-customize" tests/` found nothing, and all eleven methods of the
 * sibling `ThemeCustomizeWebRoutesTest` are `->post()`. So M4 would have begun rewriting these four
 * screens with zero cover on what they render today.
 *
 * It also pins the half of the guard that the POST file cannot see. The two verbs **disagree about
 * who may act**: the four GETs here require `isAbleTo('Manage Themes')`
 * (`ThemeCustomizeController.php:20, :43, :55, :263`) while the three POSTs require only
 * `ThemeEditorGate` ownership and carry no permission middleware at all. A suite that drives one
 * verb proves nothing about the other, which is why this file is a prerequisite for M4-2 rather
 * than a nicety.
 *
 * -------------------------------------------------------------------------------------------------
 * THREE WAYS A TEST OF THESE ROUTES PASSES WITHOUT PROVING ANYTHING. All three were measured here,
 * not anticipated:
 *
 *   1. THE EMPTY GALLERY. `index.blade.php:19-22` does `@if (!in_array($folder, $addons)) @continue`,
 *      and `$addons` is `Addon::where('status','1')->pluck('theme_id')` -- rows that no test fixture
 *      creates. MEASURED on the first probe: HTTP **200**, 62469 bytes of perfectly good chrome,
 *      and **not one theme card** ("Stylique?=no"). `assertStatus(200)` on this screen is satisfied
 *      by a page with nothing on it, so this file seeds `Addon` rows and asserts a named theme.
 *
 *   2. EMPTY CHROME. `layouts/app.blade.php` `@yield`s and holds zero `$slot` references, so a view
 *      written in the `<x-*-layout>` shape renders the furniture and an empty content area, also at
 *      200 -- the defect `tests/Unit/Ui/SlotLayoutViewsRenderTest` exists to catch because it is
 *      otherwise invisible. Every assertion below is on CONTENT.
 *
 *   3. THE OPERATOR NEVER ARRIVES. `XSS::handle()` redirects any `type = 'super admin'` user to the
 *      installer whenever a migration is unrecorded, and no WorkDo module migration ever runs in a
 *      test, so that is permanent. An operator assertion written without the premise helper passes
 *      while the handler never executes. `ThemeCustomizeWebRoutesTest`'s first version did exactly
 *      this against a completely unguarded controller, so the helper is reused here verbatim and
 *      its premise is re-asserted rather than assumed.
 */
class ThemeEditorReadRoutesTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;
    private User $merchant;
    private User $otherMerchant;
    private User $staff;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::forceCreate([
            'name' => 'Read Op', 'email' => 'read-op@example.test', 'password' => bcrypt('x'),
            'type' => 'super admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $this->merchant = User::forceCreate([
            'name' => 'Read Merchant', 'email' => 'read-m@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $this->store = Store::create([
            'name' => 'Read Store', 'email' => 'read-store@example.test', 'slug' => 'read-store',
            'theme_id' => 'stylique', 'created_by' => $this->merchant->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        $this->otherMerchant = User::forceCreate([
            'name' => 'Other', 'email' => 'read-other@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $this->staff = User::forceCreate([
            'name' => 'Read Staff', 'email' => 'read-staff@example.test', 'password' => bcrypt('x'),
            'type' => 'staff', 'created_by' => $this->merchant->id, 'email_verified_at' => now(),
        ]);
        $this->staff->current_store = $this->store->id;
        $this->staff->save();

        /* Deliberately no current_store: the state that makes getCurrentStore() return a literal 1. */
        $this->operator->current_store = null;
        $this->operator->save();

        /* Without these the theme gallery renders zero cards at HTTP 200 -- see trap 1 above. */
        foreach (['stylique', 'greentic', 'techzonix'] as $theme) {
            Addon::create(['theme_id' => $theme, 'status' => '1']);
        }

        $this->letTheOperatorReachTheController();
    }

    /** Mirrors SecurityTestCase::grantPermission(); forceCreate because create() drops store_id. */
    private function grantManageThemes(User $user): User
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'Manage Themes'], ['display_name' => 'Manage Themes']
        );

        $role = Role::forceCreate([
            'name' => 'themes-role-' . $user->id, 'display_name' => 'Themes Role',
            'guard_name' => 'web', 'created_by' => $user->creatorId(),
            'store_id' => $user->current_store,
        ]);

        $role->givePermission($permission);
        $user->addRole($role);
        $user->flushCache();

        return $user->fresh();
    }

    /** Verbatim from ThemeCustomizeWebRoutesTest -- see trap 3. */
    private function letTheOperatorReachTheController(): void
    {
        $ran = DB::table('migrations')->pluck('migration')->all();

        $files = collect(File::glob(database_path('migrations/*.php')))
            ->map(fn ($path) => File::name($path));

        foreach (Module::allModules() as $module) {
            foreach ([
                "packages/workdo/{$module->name}/src/Database/Migrations",
                "packages/workdo/{$module->name}/src/database/migrations",
            ] as $dir) {
                if (File::exists($dir)) {
                    $files = $files->merge(
                        collect(File::glob("{$dir}/*.php"))->map(fn ($path) => File::name($path))
                    );
                }
            }
        }

        $batch = 1 + (int) DB::table('migrations')->max('batch');

        foreach (array_diff($files->unique()->all(), $ran) as $name) {
            DB::table('migrations')->insert(['migration' => $name, 'batch' => $batch]);
        }
    }

    /** @return array<string,string> label => url */
    public static function readRoutes(): array
    {
        return [
            'index'     => ['/theme-customize'],
            'pages'     => ['/theme-customize/stylique/pages'],
            'customize' => ['/theme-customize/stylique/page/home'],
            'order'     => ['/theme-customize/stylique/pages/order/home'],
        ];
    }

    /* =============================================================== the premise, asserted first */

    /**
     * WITHOUT THIS, EVERY OPERATOR ROW BELOW IS AN ASSERTION ABOUT A REDIRECT. Kept as its own test
     * so that if the installer redirect returns it fails by name instead of quietly turning the
     * operator cases green.
     */
    #[Test]
    public function test_the_operator_actually_reaches_the_controller(): void
    {
        $response = $this->actingAs($this->operator)->get('/theme-customize');

        $this->assertStringNotContainsString(
            '/update',
            (string) $response->headers->get('Location'),
            'The operator was redirected to the installer updater by the `xss` middleware and never '
            . 'reached the controller. Every operator assertion in this file is vacuous until '
            . 'letTheOperatorReachTheController() is repaired.'
        );
    }

    /* ================================================================= what the screens render */

    /**
     * The gallery, with its own trap closed: this asserts a THEME CARD, not a status code, because
     * the screen renders 200 with zero cards whenever no `Addon` row is active.
     */
    #[Test]
    public function test_the_gallery_lists_the_installed_themes_for_a_permitted_merchant(): void
    {
        $merchant = $this->grantManageThemes($this->merchant);

        $response = $this->actingAs($merchant)->get('/theme-customize');

        $response->assertOk();
        $body = (string) $response->getContent();

        $this->assertStringContainsString('Stylique', $body,
            'The theme gallery rendered no card for stylique. HTTP 200 alone is satisfied by an '
            . 'empty gallery -- index.blade.php @continues past every theme absent from the active '
            . 'Addon list, which is the measured false green this assertion exists to close.');

        foreach (['Greentic', 'Techzonix'] as $other) {
            $this->assertStringContainsString($other, $body,
                "The gallery is missing {$other}; it renders from a fixed three-theme list when the "
                . 'user has no plan, so a missing one means the Addon filter dropped it.');
        }
    }

    /**
     * The other three screens, each asserted on content. `Theme Customize` is the heading all three
     * render; the theme name proves the route parameter actually reached the view rather than the
     * page falling back to chrome.
     */
    #[Test]
    public function test_the_three_editor_screens_render_their_own_content(): void
    {
        $merchant = $this->grantManageThemes($this->merchant);

        foreach ([
            'pages'     => '/theme-customize/stylique/pages',
            'customize' => '/theme-customize/stylique/page/home',
            'order'     => '/theme-customize/stylique/pages/order/home',
        ] as $label => $url) {
            $response = $this->actingAs($merchant)->get($url);

            $response->assertOk();
            $body = (string) $response->getContent();

            $this->assertStringContainsString('Theme Customize', $body,
                "The `{$label}` screen rendered no heading. A view in the <x-*-layout> shape "
                . 'returns 200 with empty chrome, so status alone proves nothing here.');

            $this->assertStringContainsString('Stylique', $body,
                "The `{$label}` screen does not name the theme it was asked for, so the {theme} "
                . 'route parameter did not reach the view.');
        }
    }

    /* ====================================================================== who is refused what */

    /**
     * SUPERSEDED BY M4-2, AND INVERTED DELIBERATELY — OWNER DECISION 2026-11-15.
     *
     * This test used to assert that every read screen refuses a merchant who does not hold
     * `Manage Themes`, including the merchant who OWNS the store. That was the measured behaviour
     * and it was half of an inversion: the owner could not open their own editor while
     * zero-permission staff could drive every write in it by direct POST.
     *
     * The owner closed it. All eight theme routes now share one boundary -- operator naming a store,
     * the verified tenant owner, or a same-tenant holder of `Manage Themes` -- so the store owner
     * reaches their own editor WITHOUT the permission, and that is now what this asserts.
     *
     * The docblock on the old version said: "If this now passes, the read guard changed and M4-2
     * should be the reason." It did, and it is.
     */
    #[Test]
    public function test_the_store_owner_reaches_every_read_screen_without_the_permission(): void
    {
        $this->assertFalse($this->merchant->isAbleTo('Manage Themes'),
            'PREMISE: this asserts OWNER access without the permission; a grant would hide the bug.');

        foreach (static::readRoutes() as $label => [$url]) {
            $response = $this->actingAs($this->merchant)->get($url);

            $response->assertOk();

            $this->assertStringNotContainsString('Permission denied', (string) $response->getContent(),
                "`{$label}` refused the store's own owner.");
        }
    }

    /**
     * The other half: a principal who is neither the owner nor a permission holder is still refused
     * every read screen. Without this, the test above is satisfied by removing the guard entirely.
     */
    #[Test]
    public function test_a_merchant_from_another_tenant_is_refused_every_read_screen(): void
    {
        foreach (static::readRoutes() as $label => [$url]) {
            $response = $this->actingAs($this->otherMerchant)->get($url);

            $this->assertTrue(
                $response->isRedirect(),
                "`{$label}` served a merchant who neither owns this store nor holds the permission."
            );
        }
    }

    /**
     * Staff hold no permission, so the read side refuses them -- while the WRITE side does not,
     * which `ThemeCustomizeWebRoutesTest` pins from the other direction. The inversion is the point;
     * it is recorded here so M4-2 cannot "fix" one verb and leave the other open.
     */
    #[Test]
    public function test_zero_permission_staff_are_refused_every_read_screen(): void
    {
        foreach (static::readRoutes() as $label => [$url]) {
            $response = $this->actingAs($this->staff)->get($url);

            $this->assertTrue($response->isRedirect(),
                "`{$label}` served a staff user holding no permission of any kind.");
        }
    }

    /** An anonymous caller must never reach any of them. */
    #[Test]
    public function test_an_anonymous_caller_is_sent_to_sign_in(): void
    {
        foreach (static::readRoutes() as $label => [$url]) {
            $response = $this->get($url);

            $this->assertTrue($response->isRedirect(),
                "`{$label}` served an anonymous caller.");
            $this->assertStringContainsString('login',
                (string) $response->headers->get('Location'),
                "`{$label}` redirected an anonymous caller somewhere other than sign-in.");
        }
    }

    /**
     * THE OPERATOR, AND THE FINDING THIS RECORDS RATHER THAN FIXES. `Manage Themes` is absent from
     * `Utility::addNewData()`'s `$superadminNewPermission`, so the platform operator holds it on no
     * seeded install -- while `ThemeEditorGate` admits the operator on every WRITE. There is
     * therefore no supported way for support to LOOK at what they are permitted to change.
     *
     * This asserts the behaviour as it stands today so that M4-2, which reconciles the two verbs,
     * has to change this test deliberately rather than drift past it.
     */
    #[Test]
    public function test_the_operator_without_the_permission_is_refused_the_read_screens(): void
    {
        foreach (static::readRoutes() as $label => [$url]) {
            $response = $this->actingAs($this->operator)->get($url);

            $this->assertTrue($response->isRedirect(),
                "`{$label}` served the platform operator, who holds no `Manage Themes`. If this "
                . 'now passes, the read guard changed and M4-2 should be the reason.');
        }
    }
}
