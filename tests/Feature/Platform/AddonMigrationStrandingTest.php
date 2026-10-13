<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * NEW-ADDON-SCHEMA-02 -- ATTRIBUTION, NOT A WORKAROUND.
 * =============================================================================================
 *
 * WHAT THIS FILE IS FOR. Six of the Feature suite's eight failures are page renders that die on
 * `no such table: owner_menu_settings`. The standing account is that they are stock Breeze
 * scaffolding testing behaviour this product never implemented. THAT ACCOUNT IS WRONG, and this
 * file is the measurement that settles it: the six screens exist, this product serves them, and
 * they render 200 the moment one stranded migration path is registered.
 *
 * THE DEFECT, with the file:line that causes it.
 *
 *   packages/workdo/LandingPage/src/Providers/LandingPageServiceProvider.php:28
 *       $this->loadMigrationsFrom(__DIR__ . '/../database/Migrations');
 *
 *   The directory on disk is  packages/workdo/LandingPage/src/database/migrations
 *                                                                    ^ lowercase m
 *
 * One character. The declared path does not exist on a case-sensitive filesystem, so
 * loadMigrationsFrom() registers a directory that holds nothing and SIX migrations are never
 * discovered by any `migrate` run that relies on the provider:
 *
 *   2023_06_05_043450_create_landing_page_settings_table.php       landing_page_settings
 *   2023_06_10_114031_create_join_us_table.php                     join_us
 *   2024_03_19_091750_create_marketplace_page_settings_table.php   marketplace_page_settings
 *   2024_07_31_123647_create_owner_menus_table.php                 owner_menus
 *   2024_08_01_113043_create_owner_menu_items_table.php            owner_menu_items
 *   2024_08_05_060912_create_owner_menu_settings_table.php         owner_menu_settings
 *
 * resources/views/layouts/guest.blade.php:96 reads `owner_menu_settings`, and every unauthenticated
 * screen in the product extends that layout -- so the whole guest surface 500s on a database built
 * by migration alone.
 *
 * WHY THIS FILE DOES NOT MAKE THE SIX GREEN. The fix belongs in
 * packages/workdo/LandingPage/src/Providers/LandingPageServiceProvider.php, which is inside the
 * tree .github/scripts/guard-no-workdo-changes.sh refuses to let a pull request modify, for the
 * reason that guard states: an in-place edit to a vendored commercial module is reverted by the
 * next upstream update and leaves no patch record. Correcting it is an OWNER DECISION.
 *
 * So the six tests in tests/Feature/Auth/ STAY RED. Registering the path from a test base class
 * would have greened them and simultaneously destroyed the only automated signal that the bug
 * exists -- and the bug is not confined to the test database: `php artisan migrate` in production
 * misses these six tables for exactly the same reason, which is why the production database has
 * them (the installer ran `migrate --path=` explicitly, see app/Console/Commands/PackageMigrate.php:55)
 * while a migrate-only environment does not.
 *
 * WHAT IT DOES INSTEAD. It registers the real on-disk path for ITS OWN TESTS ONLY and measures
 * two things that could not otherwise be distinguished:
 *
 *   1. POSITIVE -- the six screens render. This is the evidence that the six red tests are
 *      asserting a TRUE property about this product and must not be retired.
 *   2. DISCRIMINATING -- /profile still 500s with the path registered, on a DIFFERENT line.
 *      So the eighth failure is NOT this bug, and fixing the casing will not clear it. Asserting
 *      "six screens render" alone would have let the eighth be filed under the same cause, which
 *      is the mistake that produced the previously-recorded "7 page renders" figure.
 *
 * NO LEAKAGE. The extra `migrate` runs inside the transaction beginDatabaseTransaction() has
 * already opened (RefreshDatabase.php:62-71 calls refreshTestDatabase() BEFORE
 * afterRefreshingDatabase()), and SQLite DDL is transactional, so the six tables are rolled back
 * in tearDown and no other test class sees them. Verified by running this file together with
 * tests/Feature/Auth/: the six Auth failures still fail, still on `owner_menu_settings`.
 */
class AddonMigrationStrandingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The path the provider SHOULD have declared. Spelled out rather than derived from the
     * provider's own string so that correcting the provider does not silently change what this
     * test runs.
     */
    private const STRANDED_PATH = 'packages/workdo/LandingPage/src/database/migrations';

    protected function afterRefreshingDatabase(): void
    {
        $this->artisan('migrate', [
            '--path'     => self::STRANDED_PATH,
            '--realpath' => false,
        ]);
    }

    public function test_the_stranded_migrations_do_declare_the_six_tables(): void
    {
        foreach ([
            'landing_page_settings',
            'join_us',
            'marketplace_page_settings',
            'owner_menus',
            'owner_menu_items',
            'owner_menu_settings',
        ] as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                'Running ' . self::STRANDED_PATH . ' did not create `' . $table . '`. Either the '
                . 'migration was removed from the module or the directory moved. Both change what '
                . 'NEW-ADDON-SCHEMA-02 costs, so re-measure before editing this list.'
            );
        }
    }

    public static function guestScreens(): array
    {
        return [
            'login'            => ['/login'],
            'register'         => ['/register'],
            'forgot password'  => ['/forgot-password'],
            // Breeze serves this screen for any token string; the token is only checked on POST.
            'reset password'   => ['/reset-password/a-token-that-need-not-be-valid'],
        ];
    }

    /**
     * POSITIVE TEST. Each of these is one of the six red tests in tests/Feature/Auth/, and each
     * renders once the stranded path is registered.
     */
    #[DataProvider('guestScreens')]
    public function test_a_guest_auth_screen_renders_once_the_stranded_path_is_registered(string $uri): void
    {
        $this->get($uri)->assertOk();
    }

    public function test_the_email_verification_screen_renders_once_the_stranded_path_is_registered(): void
    {
        // unverified() deliberately: a verified user is redirected to the dashboard by
        // EnsureEmailIsVerified, which would make a 200 assertion here untestable and a
        // "not 500" assertion a proxy rather than the property.
        $this->actingAs(User::factory()->unverified()->create())
            ->get('/verify-email')
            ->assertOk();
    }

    public function test_the_confirm_password_screen_renders_once_the_stranded_path_is_registered(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/confirm-password')
            ->assertOk();
    }

    /**
     * THE DISCRIMINATOR, and the reason this file asserts more than "the six screens render".
     *
     * ProfileTest::test_profile_page_is_displayed is the eighth failure. It is NOT
     * NEW-ADDON-SCHEMA-02: with every stranded table present, GET /profile still answers 500, and
     * on a different site --
     *
     *   app/Http/Controllers/HomeController.php:367
     *       return route('landing_page', $store->slug);
     *
     * -- an unguarded property read on a null $store. /profile is served by UserController@profile
     * (routes/web.php), not by Breeze's ProfileController@edit.
     *
     * THIS ASSERTION REDDENS WHEN THE DEFECT IS FIXED, which is deliberate and is the only honest
     * shape available: it pins an attribution, and the attribution changes when app/ changes. If
     * it fails with 200, the null-store guard has landed -- delete this test and green
     * ProfileTest::test_profile_page_is_displayed in the same commit, and drop
     * ProfileTest::test_profile_page_is_displayed from .github/known-test-outcomes.json.
     */
    public function test_the_profile_page_failure_is_not_this_bug(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/profile');

        $this->assertSame(
            500,
            $response->getStatusCode(),
            'GET /profile now answers ' . $response->getStatusCode() . ' with the stranded '
            . 'LandingPage migrations present. If that is 200, the null-store dereference at '
            . 'app/Http/Controllers/HomeController.php:367 has been guarded: retire this test, '
            . 'green ProfileTest::test_profile_page_is_displayed, and remove its entry from '
            . '.github/known-test-outcomes.json in the same commit.'
        );
    }
}
