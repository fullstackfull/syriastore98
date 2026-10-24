<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\AddOnManager;
use Illuminate\Support\Facades\Schema;

class PackageServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // LynomiaChat is loaded statically, like LandingPage, rather than waiting for an
        // add_on_managers row.
        //
        // WHY, AND WHY IT IS NOT A WAY AROUND ENTITLEMENT. loadDynamicAddons() runs inside
        // $this->app->booted() and reads add_on_managers, so a dynamically-loaded module is
        // invisible until that row exists. For a purchasable add-on that is correct. For a
        // first-party integration it would mean the module's routes, migrations and menu
        // simply do not exist on a fresh install, in CI, or in the test database -- a suite
        // cannot assert that a route is properly gated when the route was never registered.
        //
        // Loading the provider is NOT the same as a merchant having the feature. Entitlement
        // is enforced where it belongs and is unchanged by this line: every merchant route
        // carries PlanModuleCheck:LynomiaChat, which resolves the store's plan `modules`
        // list, and App\Classes\Menu::add() hides the navigation unless ActivatedModule()
        // contains it. A merchant whose plan omits the module still gets a 403.
        $staticAddons = ['LandingPage', 'LynomiaChat'];
        $this->loadAddons($staticAddons);
        
        // Defer database queries until after DB connection is ready
        $this->app->booted(function () {
            $this->loadDynamicAddons();
        });
    }
    
    private function loadDynamicAddons(): void
    {
        try {
            if (! Schema::hasTable('add_on_managers')) {
                return;
            }

            $dbAddons = AddOnManager::pluck('module')->toArray();

            if (! in_array('LandingPage', $dbAddons)) {
                $dbAddons[] = 'LandingPage';
            }

            $this->loadAddons($dbAddons);
        } catch (\Throwable $e) {
            /*
             * P1-PLT-04 (#36). THIS CATCH USED TO READ `// Handle silently`, AND SILENTLY IS
             * EXACTLY WHAT IT DID: a broken install did not fail, it DISAPPEARED. Every module's
             * routes, migrations, menu entries and policies were simply absent, with nothing in
             * any log to say so, and the first symptom an operator saw was a 404 on a page that
             * worked yesterday.
             *
             * THE LEVEL IS `critical`, NOT `error`. `error` is this codebase's level for a
             * third-party integration failing a call; this is the whole add-on subsystem not
             * loading, and P1-PLT-05's inventory enumerates `critical` and `warning` as the levels
             * that mean "a human should look at this". A row for this line is in
             * docs/security/P01-OBSERVABILITY-INVENTORY.md.
             *
             * IT STILL CONTINUES, and that is deliberate rather than timid. Rethrowing from
             * `app->booted()` takes the entire application down -- storefront included -- for
             * every tenant, over one module's broken composer.json. A business that is live
             * prefers a degraded install that reports to a dead one that does not. The report is
             * the fix for #36; the recovery is the operator's.
             *
             * `\Throwable`, not `\Exception`: a PHP `Error` -- a missing class in a provider's
             * constructor, which is the most likely way a half-installed module fails -- is not an
             * `Exception` and was NOT caught here at all. So the old catch did not even cover the
             * failure it was most likely to meet; that one took the app down while the ones it did
             * catch vanished.
             */
            \Illuminate\Support\Facades\Log::critical('addon loader: the dynamic add-on pass failed, so some modules are not loaded', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Boot method - can be used for other initialization if needed
    }

    /**
     * Load and register addon packages dynamically.
     */
    protected function loadAddons(array $addons): void
    {
        $loader = require base_path('vendor/autoload.php');

        foreach ($addons as $addon) {
            /*
             * P1-PLT-04 (#36). PER MODULE, so one broken install cannot silence the rest.
             *
             * Without this the loop is all-or-nothing in two different wrong ways, depending on
             * who called it. From loadDynamicAddons() the outer catch swallowed the throw and
             * ABANDONED EVERY REMAINING MODULE in the list -- so a single malformed composer.json
             * on an alphabetically early add-on removed all the ones after it, and the operator
             * saw a set of unrelated features vanish at once with no line connecting them. From
             * register(), where the static add-ons are loaded, there was no catch at all and the
             * throw took the whole application down.
             *
             * Now each module stands or falls alone, and the one that failed is NAMED. Which it
             * was is the single most useful fact about this failure and neither path recorded it.
             */
            try {
                $packageDir = base_path("packages/workdo/{$addon}");
                $composerFile = "{$packageDir}/composer.json";

                if (! file_exists($composerFile)) {
                    continue;
                }

                $composerConfig = json_decode(file_get_contents($composerFile), true);

                // Register PSR-4 namespaces
                if (! empty($composerConfig['autoload']['psr-4'])) {
                    foreach ($composerConfig['autoload']['psr-4'] as $namespace => $path) {
                        $loader->addPsr4($namespace, "{$packageDir}/{$path}");
                    }
                }

                // Register Laravel service providers
                if (! empty($composerConfig['extra']['laravel']['providers'])) {
                    foreach ($composerConfig['extra']['laravel']['providers'] as $provider) {
                        $this->app->register($provider);
                    }
                }

                $this->registerAddonMigrations($packageDir);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::critical('addon loader: a module failed to load and its features are absent', [
                    'module' => $addon,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile() . ':' . $e->getLine(),
                ]);
            }
        }
    }

    /**
     * REGISTER AN ADDON'S MIGRATIONS FROM THE LOADER, because a module cannot be trusted to.
     *
     * THE DEFECT THIS CLOSES. Every addon registers its own migrations from its service provider
     * with `loadMigrationsFrom(__DIR__ . '/...')`, and that method does NOT check that the path
     * exists -- it appends the string to the migrator's list, and a path resolving to nothing
     * contributes no migrations and no warning. So a wrong path is completely silent.
     *
     * LandingPage had one. Its provider asked for `src/database/Migrations`, capital M, while the
     * directory on disk is `src/database/migrations`. That resolves on a case-INSENSITIVE
     * filesystem -- a developer's macOS or Windows checkout -- and resolves to nothing on Linux,
     * which is every server this ships to. Six migrations were therefore never contributed, and
     * one of them creates `owner_menu_settings`, which `resources/views/layouts/guest.blade.php`
     * reads on every guest screen. A Linux installation that ran
     * `composer install && php artisan migrate` came up with /login and /register both answering
     * HTTP 500 on `no such table: owner_menu_settings` -- measured on a live server. Nobody could
     * sign in, which put every page behind auth out of reach.
     *
     * WHY THE FIX IS HERE AND NOT IN THE MODULE. `packages/workdo/` is vendored commercial code
     * and `.github/scripts/guard-no-workdo-changes.sh` refuses to let a pull request modify it,
     * for a reason that applies exactly to this case: an in-place edit is silently reverted by the
     * next upstream module update and leaves no patch record. That guard's own guidance is that
     * such a fix "almost always belongs in the host application instead", and this is the host
     * application's addon loader -- the one place that already knows where every module lives.
     *
     * WHY IT IS GENERAL RATHER THAN A PATCH FOR ONE MODULE. Eighteen further modules declare a
     * path that does not resolve either; they are harmless only because they currently ship no
     * migrations, and any one of them gaining its first migration would strand it the same silent
     * way. Registering the directory that actually exists makes the whole defect class
     * unreachable instead of fixing one instance of it.
     *
     * SAFE TO OVERLAP. For the fifty modules whose own declaration is already correct this
     * registers the same directory twice, which costs nothing: `Migrator::getMigrationFiles`
     * keys the files it finds by migration name, so duplicates collapse to one entry and nothing
     * can run twice. And it adds no migration that was not already intended to run -- every
     * addon provider registers its migrations unconditionally today, so this changes which
     * directories are found, never the policy about when they apply.
     */
    private function registerAddonMigrations(string $packageDir): void
    {
        $path = "{$packageDir}/src/database/migrations";

        if (is_dir($path)) {
            $this->loadMigrationsFrom($path);
        }
    }
}