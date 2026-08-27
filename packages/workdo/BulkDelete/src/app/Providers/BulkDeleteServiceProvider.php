<?php

namespace Workdo\BulkDelete\app\Providers;

use Illuminate\Support\ServiceProvider;
// use Workdo\BulkDelete\app\Providers\EventServiceProvider;
// use Workdo\BulkDelete\app\Providers\RouteServiceProvider;

class BulkDeleteServiceProvider extends ServiceProvider
{

    protected $moduleName = 'BulkDelete';
    protected $moduleNameLower = 'bulkdelete';

    public function register()
    {
        $this->app->register(RouteServiceProvider::class);
        $this->app->register(EventServiceProvider::class);
    }

    public function boot()
    {
        $this->loadRoutesFrom(__DIR__ . '/../../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'bulk-delete');
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        $this->registerTranslations();
    }

    /**
     * Register translations.
     *
     * @return void
     */
    public function registerTranslations()
    {
        $langPath = resource_path('lang/modules/' . $this->moduleNameLower);

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->moduleNameLower);
            $this->loadJsonTranslationsFrom($langPath);
        } else {
            $this->loadTranslationsFrom(__DIR__.'/../../resources/lang', $this->moduleNameLower);
            $this->loadJsonTranslationsFrom(__DIR__.'/../../resources/lang');
        }
    }
}