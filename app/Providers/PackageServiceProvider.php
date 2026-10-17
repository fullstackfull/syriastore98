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
        } catch (\Exception $e) {
            // Handle silently
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
        }
    }
}