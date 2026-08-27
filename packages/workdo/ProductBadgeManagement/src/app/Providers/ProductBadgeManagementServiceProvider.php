<?php

namespace Workdo\ProductBadgeManagement\app\Providers;

use Illuminate\Support\ServiceProvider;

class ProductBadgeManagementServiceProvider extends ServiceProvider
{

    protected $moduleName = 'ProductBadgeManagement';
    protected $moduleNameLower = 'productbadgemanagement';

    public function register()
    {
        $this->app->register(RouteServiceProvider::class);
        $this->app->register(EventServiceProvider::class);
        $this->app->register(ViewComposer::class);
    }

    public function boot()
    {
        $this->loadRoutesFrom(__DIR__ . '/../../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'product-badge-management');
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
