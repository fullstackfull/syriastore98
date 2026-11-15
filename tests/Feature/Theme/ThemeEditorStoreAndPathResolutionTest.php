<?php

namespace Tests\Feature\Theme;

use App\Facades\ModuleFacade as Module;
use App\Models\Addon;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\ThemeCustomize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M4-1 — WHOSE STORE THE EDITOR READS, AND WHAT IT WILL OPEN AS A FILE.
 * =================================================================================================
 *
 * M4-1 changed three things in `ThemeCustomizeController`'s read path, and the M4-0 floor did not
 * notice any of them -- 345 tests passed before the change and 345 after. That is the point of this
 * file: a change nothing can observe being made is a change nothing can observe being REVERTED.
 *
 *   1. `themeCustomize()` resolved its store with `getCurrentStore()`, the last of the eight
 *      methods still doing so. That resolver returns the literal `1` for an operator whose
 *      `current_store` is empty -- a fallback artefact, not a store anyone chose.
 *   2. NEITHER `{theme}` NOR `{slug}` WAS CONTAINED. Both went into
 *      `base_path('themes/'.$theme.'/theme_json/'.$slug.'.json')` as received, and a path that did
 *      not resolve answered **500** rather than "no such page", because `file_get_contents()`
 *      raises a warning and this project promotes warnings to `ErrorException`.
 *   3. `changeOrder()` validated `{theme}` for its ORDER LOOKUP while reading `pages.json` from the
 *      unvalidated segment forty lines earlier -- half-validating one parameter for one of its two
 *      uses, which reads as containment and is not.
 *
 * -------------------------------------------------------------------------------------------------
 * TWO WAYS THIS FILE WOULD PASS WITHOUT PROVING ANYTHING, both closed deliberately:
 *
 *   - DRIVING ONLY A MERCHANT TAKES THE SAFE BRANCH. An owning merchant's `getCurrentStore()` and
 *     their gate-resolved store are THE SAME VALUE, so every assertion about store resolution is
 *     satisfied by the old code. The operator is the principal whose two values differ, so the
 *     operator is driven -- and is GRANTED `Manage Themes` first, because otherwise the permission
 *     check refuses them at the door and the test proves nothing about the gate behind it.
 *   - ASSERTING A VALUE THAT IS ALSO A THEME DEFAULT. `getThemeSetting()` merges
 *     `default_data/settings.php` UNDER the stored rows for ~500 keys, so "the right value is on
 *     the page" can be true without any store being read. `footer_description` ships the default
 *     "Delivering farm-fresh organic groceries..." -- so the fixtures write a SENTINEL that no
 *     theme carries, and the assertions are on that.
 */
class ThemeEditorStoreAndPathResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const SENTINEL = 'M4-1-OWN-STORE-SENTINEL-9f2a';
    private const OTHER_SENTINEL = 'M4-1-OTHER-STORE-SENTINEL-4b7c';

    private User $operator;
    private User $merchant;
    private User $otherMerchant;
    private Store $store;
    private Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::forceCreate([
            'name' => 'Path Op', 'email' => 'path-op@example.test', 'password' => bcrypt('x'),
            'type' => 'super admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $this->merchant = User::forceCreate([
            'name' => 'Path A', 'email' => 'path-a@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);
        $this->store = Store::create([
            'name' => 'Path Store A', 'email' => 'path-sa@example.test', 'slug' => 'path-store-a',
            'theme_id' => 'stylique', 'created_by' => $this->merchant->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        $this->otherMerchant = User::forceCreate([
            'name' => 'Path B', 'email' => 'path-b@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);
        $this->otherStore = Store::create([
            'name' => 'Path Store B', 'email' => 'path-sb@example.test', 'slug' => 'path-store-b',
            'theme_id' => 'stylique', 'created_by' => $this->otherMerchant->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);
        $this->otherMerchant->current_store = $this->otherStore->id;
        $this->otherMerchant->save();

        /* Through Eloquent, never DB::table(): ThemeCustomize is Cachable and a raw write leaves
         * the read serving the pre-write row for the rest of the test. */
        ThemeCustomize::create([
            'store_id' => $this->store->id, 'theme_id' => 'stylique',
            'name' => 'footer_description', 'value' => self::SENTINEL,
        ]);
        ThemeCustomize::create([
            'store_id' => $this->otherStore->id, 'theme_id' => 'stylique',
            'name' => 'footer_description', 'value' => self::OTHER_SENTINEL,
        ]);

        $this->operator->current_store = null;
        $this->operator->save();

        foreach (['stylique', 'greentic', 'techzonix'] as $theme) {
            Addon::create(['theme_id' => $theme, 'status' => '1']);
        }

        $this->letTheOperatorReachTheController();
    }

    private function grantManageThemes(User $user): User
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'Manage Themes'], ['display_name' => 'Manage Themes']
        );
        $role = Role::forceCreate([
            'name' => 'path-role-' . $user->id, 'display_name' => 'Path Role',
            'guard_name' => 'web', 'created_by' => $user->creatorId(),
            'store_id' => $user->current_store,
        ]);
        $role->givePermission($permission);
        $user->addRole($role);
        $user->flushCache();

        return $user->fresh();
    }

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

    /* ============================================================== 1. whose store is read */

    #[Test]
    public function test_the_editor_prefills_from_the_callers_own_store(): void
    {
        $merchant = $this->grantManageThemes($this->merchant);

        $response = $this->actingAs($merchant)->get('/theme-customize/stylique/page/footer');

        $response->assertOk();
        $body = (string) $response->getContent();

        $this->assertStringContainsString(self::SENTINEL, $body,
            'The footer editor did not render this store\'s own stored value. The sentinel is a '
            . 'string no theme default carries, so its absence means the settings came from '
            . 'somewhere other than this merchant\'s store.');

        $this->assertStringNotContainsString(self::OTHER_SENTINEL, $body,
            'The editor rendered ANOTHER tenant\'s stored footer description.');
    }

    /**
     * THE SAME ROUTE, THE OTHER TENANT. Each merchant must see only their own value; this is the
     * assertion that fails if store resolution is ever keyed on anything but the principal.
     */
    #[Test]
    public function test_each_tenant_sees_only_their_own_stored_values(): void
    {
        $other = $this->grantManageThemes($this->otherMerchant);

        $response = $this->actingAs($other)->get('/theme-customize/stylique/page/footer');

        $response->assertOk();
        $body = (string) $response->getContent();

        $this->assertStringContainsString(self::OTHER_SENTINEL, $body);
        $this->assertStringNotContainsString(self::SENTINEL, $body,
            'Tenant B\'s editor rendered tenant A\'s stored footer description.');
    }

    /**
     * THE OPERATOR, AND THE ONE PRINCIPAL WHOSE TWO STORE VALUES DIFFER.
     *
     * `Manage Themes` is granted FIRST so the operator passes the permission check and actually
     * reaches the resolution code. Without that grant this test passes against any implementation,
     * including the one it exists to refuse.
     */
    #[Test]
    public function test_an_operator_naming_no_store_is_refused_rather_than_served_store_one(): void
    {
        $operator = $this->grantManageThemes($this->operator);

        $this->assertTrue($operator->isAbleTo('Manage Themes'),
            'PREMISE: the operator must hold the permission, or this asserts the door and not the '
            . 'gate behind it.');
        $this->assertNull($operator->current_store,
            'PREMISE: an empty current_store is the state that makes getCurrentStore() return 1.');

        $response = $this->actingAs($operator)->get('/theme-customize/stylique/page/footer');

        $this->assertTrue($response->isRedirect(),
            'The platform operator, holding Manage Themes and naming no store, was SERVED the '
            . 'theme editor. storeIdForSelfService() must refuse rather than fall back to the '
            . 'literal store 1.');

        $this->assertStringNotContainsString(self::SENTINEL, (string) $response->getContent(),
            'The operator was served a merchant store\'s stored settings.');
    }

    /* ======================================================== 2. what will be opened as a file */

    #[Test]
    public function test_an_unknown_theme_is_refused_rather_than_answering_500(): void
    {
        $merchant = $this->grantManageThemes($this->merchant);

        foreach ([
            'customize' => '/theme-customize/no-such-theme/page/footer',
            'pages'     => '/theme-customize/no-such-theme/pages',
            'order'     => '/theme-customize/no-such-theme/pages/order/home',
        ] as $label => $url) {
            $response = $this->actingAs($merchant)->get($url);

            $this->assertTrue($response->isRedirect(),
                "`{$label}` did not refuse an unknown theme; it answered {$response->status()}. "
                . 'Before M4-1 this built a path from the raw segment and 500ed on the missing '
                . 'file, because file_get_contents() warnings are promoted to ErrorException.');
        }
    }

    /**
     * CONTAINMENT, asserted on the identifier rather than on a specific escape string: the check is
     * `basename($name) !== $name`, which refuses every separator and traversal segment without
     * enumerating them. These inputs are three shapes of the same question.
     */
    #[Test]
    public function test_a_page_identifier_that_is_not_a_plain_file_name_is_refused(): void
    {
        $merchant = $this->grantManageThemes($this->merchant);

        foreach ([
            '..%2F..%2Fcomposer',
            'sub%2Ffooter',
            '..',
            'pages.json',
        ] as $slug) {
            $response = $this->actingAs($merchant)->get("/theme-customize/stylique/page/{$slug}");

            /*
             * REFUSED, NOT "REDIRECTED" -- and the distinction was a failing assertion before it
             * was a comment. These shapes are refused at TWO different layers: Laravel's router
             * never matches an encoded separator against a single `{slug}` segment and answers 404
             * before the controller runs, while `..` and `pages.json` do reach the handler and are
             * refused there. Asserting a redirect pinned the wrong layer and failed on inputs the
             * application was already handling correctly.
             *
             * What actually matters is the pair below: the page was NOT SERVED, and the refusal was
             * not the 500 that an uncontained path used to produce.
             */
            $this->assertNotSame(200, $response->status(),
                "The page identifier `{$slug}` was SERVED. Only a plain file name that exists "
                . 'under the theme may be opened.');

            $this->assertNotSame(500, $response->status(),
                "The page identifier `{$slug}` produced a 500 rather than a refusal -- the "
                . 'uncontained-path behaviour M4-1 exists to remove.');
        }
    }

    #[Test]
    public function test_an_omitted_page_falls_back_to_the_first_page_instead_of_500ing(): void
    {
        $merchant = $this->grantManageThemes($this->merchant);

        $response = $this->actingAs($merchant)->get('/theme-customize/stylique/page');

        $response->assertOk();
        $this->assertStringContainsString('Theme Customize', (string) $response->getContent(),
            'An omitted {slug} used to build `.../theme_json/.json` and answer 500. It now falls '
            . 'back to the first page in pages.json.');
    }

    /* ================================================= 3. the order screen with nothing saved */

    /**
     * A REGRESSION GUARD, AND IT DOES NOT DIFFERENTIATE -- which is recorded here rather than left
     * for a reader to discover, because a test that cannot fail is not evidence and should not be
     * counted as any.
     *
     * It passes against the pre-M4-1 controller as well as the new one (measured: 3 of this file's
     * 7 tests do). The `$orders` null-to-empty-string change is PHP deprecation hygiene, not a
     * rendering change -- `explode(',', null)` and `explode(',', '')` both yield `['']`, measured
     * directly. So nothing about THIS screen's output changed.
     *
     * It is kept because the order screen is the surface M4-1 restructured most -- theme validation
     * hoisted above the path build, `$theme_json` actually loaded, the dead `else` removed -- and a
     * smoke test that it still renders for the ordinary case is worth having. The containment and
     * refusal behaviour that M4-1 DID change on this route is proved by
     * `test_an_unknown_theme_is_refused_rather_than_answering_500`, which drives the order URL and
     * does fail against the old controller.
     */
    #[Test]
    public function test_the_order_screen_still_renders_for_a_store_with_no_saved_ordering(): void
    {
        $merchant = $this->grantManageThemes($this->merchant);

        $response = $this->actingAs($merchant)->get('/theme-customize/stylique/pages/order/home');

        $response->assertOk();
        $this->assertStringContainsString('Theme Customize', (string) $response->getContent());
    }
}
