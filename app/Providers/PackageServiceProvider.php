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
        $staticAddons = ['LandingPage'];
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