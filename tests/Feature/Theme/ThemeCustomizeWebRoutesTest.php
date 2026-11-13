<?php

namespace Tests\Feature\Theme;

use App\Models\Store;
use App\Models\ThemeCustomize;
use App\Models\ThemeCustomizeOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use App\Facades\ModuleFacade as Module;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE THREE WRITE ROUTES OF THE MERCHANT THEME EDITOR, DRIVEN OVER HTTP.
 * =================================================================================================
 *
 * WHY THIS FILE EXISTS, and it is a gap a review found rather than a precaution. Before it, NOTHING
 * in the suite drove `POST theme-customize/{theme}/update`: the service was tested directly and the
 * Flutter endpoint was tested over HTTP, so reverting `ThemeCustomizeController` to its old
 * `getCurrentStore()` + per-key `updateOrCreate` loop left the ENTIRE M3-C suite green. A fix no
 * test can notice being removed is not a fix, it is a comment.
 *
 * ALL THREE ROUTES ARE REACHED BY ANY AUTHENTICATED VERIFIED USER. `routes/web.php:1444`, `:1447`
 * and `:1448` sit in the `['auth','xss','setlocate','verified']` group -- there is no permission
 * middleware -- so a direct POST is the live path whatever the UI offers. Two of the three had no
 * check of any kind until M3-C; the third had its check COMMENTED OUT.
 *
 * THE OPERATOR CASES ARE THE POINT. `getCurrentStore()` returns the literal `1` for a platform
 * operator with an empty `current_store`, and store 1 belongs to a merchant. Every assertion below
 * that drives the operator is asserting that a support engineer cannot silently rewrite a tenant's
 * live storefront by clicking around their own dashboard.
 */
class ThemeCustomizeWebRoutesTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;
    private User $merchant;
    private User $staff;
    private Store $merchantStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::forceCreate([
            'name' => 'Operator', 'email' => 'web-op@example.test',
            'password' => bcrypt('x'), 'type' => 'super admin',
            'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $this->merchant = User::forceCreate([
            'name' => 'Merchant', 'email' => 'web-merchant@example.test',
            'password' => bcrypt('x'), 'type' => 'admin',
            'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $this->merchantStore = Store::create([
            'name' => 'Web Store', 'email' => 'web-store@example.test', 'slug' => 'web-store',
            'theme_id' => 'stylique', 'created_by' => $this->merchant->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);

        $this->merchant->current_store = $this->merchantStore->id;
        $this->merchant->save();

        $this->staff = User::forceCreate([
            'name' => 'Staff', 'email' => 'web-staff@example.test',
            'password' => bcrypt('x'), 'type' => 'staff',
            'created_by' => $this->merchant->id, 'email_verified_at' => now(),
        ]);
        $this->staff->current_store = $this->merchantStore->id;
        $this->staff->save();

        /* The operator deliberately has NO current_store -- the state that makes
         * getCurrentStore() hand back the literal 1. */
        $this->operator->current_store = null;
        $this->operator->save();

        $this->letTheOperatorReachTheController();
    }

    /**
     * WITHOUT THIS, EVERY OPERATOR ASSERTION IN THIS FILE PASSES FOR THE WRONG REASON. Measured.
     *
     * `App\Http\Middleware\XSS::handle():56-78` -- the `xss` middleware every route in this group
     * carries -- redirects ANY user whose type is `super admin` to the installer's updater
     * (`LaravelUpdater::welcome`, i.e. `/update`) when it finds a single unrecorded migration. It
     * runs BEFORE the controller, so the operator never reaches the code under test and the store is
     * unchanged whatever the gate does.
     *
     * The first version of this file asserted "an operator cannot repoint a merchant's active theme"
     * and PASSED against the completely unguarded pre-M3-C controller. Measured directly: the
     * operator's POST answered 302 to `http://localhost/update` with the row untouched, while the
     * merchant's identical POST answered 302 to `http://localhost` and DID write.
     *
     * WHY THERE IS ANYTHING PENDING AT ALL, since this is not obvious: `database/migrations/*.php`
     * is fully recorded in a test run -- measured, 0 pending. The middleware ALSO walks
     * `Module::allModules()` and merges `"packages/workdo/{$module->name}/src/Database/Migrations"`,
     * a RELATIVE path, which `File::exists()` resolves against the working directory -- the project
     * root -- so those files are found. No WorkDo module migration ever runs in a test (no
     * `add_on_managers` row), so every one of them counts as pending, forever.
     *
     * This replicates the middleware's own computation and records the names, which is narrower
     * than disabling `xss` -- the middleware under the gate still runs, and still sanitises input.
     */
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

    /**
     * THE PREMISE THE OPERATOR CASES DEPEND ON. If the installer redirect comes back -- a new
     * module, a renamed path, a change in `XSS` -- this fails loudly instead of quietly turning
     * three security assertions into assertions about a redirect.
     */
    #[Test]
    public function test_the_operator_actually_reaches_the_controller(): void
    {
        $response = $this->actingAs($this->operator)
            ->post('/theme-preview/make-active', ['theme_id' => 'greentic']);

        $this->assertStringNotContainsString('/update',
            (string) $response->headers->get('Location'),
            'The operator was redirected to the installer updater by the `xss` middleware and never '
            . 'reached the controller. Every operator assertion in this file is therefore vacuous '
            . 'until letTheOperatorReachTheController() is repaired.');
    }

    private function projection(string $name): ?string
    {
        return ThemeCustomize::withoutGlobalScopes()
            ->where('store_id', $this->merchantStore->id)
            ->where('name', $name)
            ->value('value');
    }

    /* ===================================================== POST theme-customize/{theme}/update */

    #[Test]
    public function test_a_merchant_can_save_their_own_theme_settings(): void
    {
        $this->actingAs($this->merchant)
            ->post('/theme-customize/stylique/update', ['footer_description' => 'Our shop'])
            ->assertRedirect();

        $this->assertSame('Our shop', $this->projection('footer_description'),
            'A merchant could not save their own storefront settings. Narrowing this route to a '
            . 'permission no role holds would lock every merchant out of their own editor, which is '
            . 'a worse failure than the one M3-C fixed.');
    }

    #[Test]
    public function test_staff_of_the_merchant_can_save(): void
    {
        $this->actingAs($this->staff)
            ->post('/theme-customize/stylique/update', ['footer_description' => 'By staff'])
            ->assertRedirect();

        $this->assertSame('By staff', $this->projection('footer_description'),
            'Staff resolve to their employer as tenant root, so they are entitled by ownership.');
    }

    /**
     * THE LITERAL-STORE-1 TRAP, over HTTP.
     */
    #[Test]
    public function test_an_operator_with_no_current_store_writes_nothing(): void
    {
        $before = $this->projection('footer_description');

        $this->actingAs($this->operator)
            ->post('/theme-customize/stylique/update', ['footer_description' => 'OPERATOR WROTE THIS'])
            ->assertRedirect();

        $this->assertSame($before, $this->projection('footer_description'),
            "A platform operator with no store selected wrote into a MERCHANT'S storefront "
            . 'configuration. getCurrentStore() returns the literal 1 in this state; the gate must '
            . 'refuse rather than infer.');
        $this->assertNotSame('OPERATOR WROTE THIS', $this->projection('footer_description'));
    }

    /**
     * The form posts five control keys that are not settings. Writing them produced junk rows that
     * `getThemeSetting()` would then merge into `$themeSettings['slug']`.
     */
    #[Test]
    public function test_the_forms_control_keys_are_never_stored_as_settings(): void
    {
        $this->actingAs($this->merchant)->post('/theme-customize/stylique/update', [
            'footer_description' => 'real',
            'slug' => 'footer', 'image' => 'x', 'background_image' => 'y',
            'menu' => 'z', 'button_link' => 'w',
        ])->assertRedirect();

        foreach (['slug', 'image', 'background_image', 'menu', 'button_link'] as $control) {
            $this->assertNull($this->projection($control),
                "The form control key '{$control}' was stored as a theme setting.");
        }

        $this->assertSame('real', $this->projection('footer_description'),
            'Dropping the control keys must not drop the real setting posted beside them.');
    }

    #[Test]
    public function test_a_rejected_value_is_reported_and_not_stored(): void
    {
        $this->actingAs($this->merchant)
            ->post('/theme-customize/stylique/update',
                ['footer_description' => '<script>steal()</script>'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($this->projection('footer_description'),
            'A refused value was stored anyway.');
    }

    /* ============================================================ POST theme-preview/make-active */

    #[Test]
    public function test_an_operator_cannot_repoint_a_merchants_active_theme(): void
    {
        $this->actingAs($this->operator)
            ->post('/theme-preview/make-active', ['theme_id' => 'greentic'])
            ->assertRedirect();

        $this->assertSame('stylique', Store::find($this->merchantStore->id)->theme_id,
            "An operator re-pointed a MERCHANT'S live storefront to another theme. This route wrote "
            . 'Store::where(id, getCurrentStore()) with no check of any kind until M3-C.');
    }

    #[Test]
    public function test_an_unknown_theme_is_refused(): void
    {
        $this->actingAs($this->merchant)
            ->post('/theme-preview/make-active', ['theme_id' => str_repeat('a', 240)])
            ->assertRedirect();

        $this->assertSame('stylique', Store::find($this->merchantStore->id)->theme_id,
            'An unvalidated theme_id was written. stores.theme_id is varchar(191) and a longer '
            . 'value TRUNCATES SILENTLY on MySQL, after which getThemeSetting() falls back to '
            . "config('theme.active') and the storefront renders a theme nobody chose.");
    }

    #[Test]
    public function test_a_merchant_can_change_their_own_active_theme(): void
    {
        $this->actingAs($this->merchant)
            ->post('/theme-preview/make-active', ['theme_id' => 'greentic'])
            ->assertRedirect();

        $this->assertSame('greentic', Store::find($this->merchantStore->id)->theme_id,
            'The fix must not stop a merchant choosing their own theme.');
    }

    /* ==================================================== POST theme-customize/{theme}/order/update */

    #[Test]
    public function test_an_operator_cannot_reorder_a_merchants_sections(): void
    {
        $this->actingAs($this->operator)->post('/theme-customize/stylique/order/update', [
            'page_slug' => 'home', 'orders' => 'banner,footer',
        ])->assertRedirect();

        $this->assertSame(0, ThemeCustomizeOrder::withoutGlobalScopes()
            ->where('store_id', $this->merchantStore->id)->count(),
            "An operator reordered a MERCHANT'S storefront sections. The GET sibling checks "
            . "isAbleTo('Manage Themes'); this POST checked nothing at all.");
    }

    #[Test]
    public function test_a_merchant_can_reorder_their_own_sections(): void
    {
        $this->actingAs($this->merchant)->post('/theme-customize/stylique/order/update', [
            'page_slug' => 'home', 'orders' => 'banner,footer',
        ])->assertRedirect();

        $this->assertSame(1, ThemeCustomizeOrder::withoutGlobalScopes()
            ->where('store_id', $this->merchantStore->id)->count());
    }
}
