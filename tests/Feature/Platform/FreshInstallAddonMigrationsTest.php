<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * NEW-ADDON-SCHEMA-02, THE REGRESSION TEST FOR THE FIX ITSELF: a fresh installation must load an
 * addon's migrations through the ORDINARY boot path, with nothing registered by hand.
 *
 * WHY THIS FILE EXISTS SEPARATELY FROM AddonMigrationStrandingTest. That class measures what the
 * stranded migrations WOULD do, and to do so it registers the real on-disk path for its own tests
 * in `afterRefreshingDatabase()`. That makes it the wrong place to assert this property: with the
 * path registered by the test, the assertion would pass whether or not the application registers
 * it, which is precisely the distinction that matters here. So this class registers NOTHING. The
 * six tables are either created by `php artisan migrate` as the application configures it, or
 * they are not there.
 *
 * WHAT WOULD MAKE IT FAIL. Reverting `registerAddonMigrations()` in
 * app/Providers/PackageServiceProvider.php, or narrowing it so it stops looking in a module's
 * conventional `src/database/migrations`. Either would restore the original defect: six
 * migrations never contributed, `owner_menu_settings` absent, and HTTP 500 from
 * resources/views/layouts/guest.blade.php:96 on every guest screen -- /login and /register
 * included, which is every route behind authentication.
 *
 * THE TABLE LIST IS THE SAME SIX, spelled out rather than derived, so that a module release which
 * drops or renames a migration reddens this instead of silently reducing what is covered.
 */
class FreshInstallAddonMigrationsTest extends TestCase
{
    use RefreshDatabase;

    public static function strandedTableProvider(): array
    {
        return [
            'landing_page_settings' => ['landing_page_settings'],
            'join_us' => ['join_us'],
            'marketplace_page_settings' => ['marketplace_page_settings'],
            'owner_menus' => ['owner_menus'],
            'owner_menu_items' => ['owner_menu_items'],
            'owner_menu_settings' => ['owner_menu_settings'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('strandedTableProvider')]
    public function test_a_fresh_installation_declares_the_table(string $table): void
    {
        $this->assertTrue(
            Schema::hasTable($table),
            "`{$table}` is missing from a fresh installation's schema. The LandingPage module's own "
            . 'provider declares a mis-cased migration path that does not exist on a case-sensitive '
            . 'filesystem, so this table exists only because '
            . 'app/Providers/PackageServiceProvider::registerAddonMigrations() registers the real '
            . 'on-disk directory. If this failed, that fix was removed or narrowed and /login is '
            . 'answering HTTP 500 again.'
        );
    }

    /**
     * THE CONSEQUENCE, asserted directly rather than inferred from the schema -- this is the
     * screen that was HTTP 500 on a real Linux server and the reason L2 was release-blocking.
     */
    public function test_the_login_screen_renders_on_a_fresh_installation(): void
    {
        $this->get('/login')->assertStatus(200);
    }
}
