<?php

namespace Workdo\ReviewReminder\app\Providers;

use Illuminate\Support\ServiceProvider;

class ReviewReminderServiceProvider extends ServiceProvider
{

    protected $moduleName = 'ReviewReminder';
    protected $moduleNameLower = 'reviewreminder';

    public function register()
    {
        $this->app->register(RouteServiceProvider::class);
        $this->app->register(EventServiceProvider::class);
        $this->app->register(ReviewReminderSettingProvider::class);

    }

    public function boot()
    {
        $this->loadRoutesFrom(__DIR__ . '/../../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'review-reminder');
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
            $this->loadTranslationsFrom(__DIR__ . '/../../resources/lang', $this->moduleNameLower);
            $this->loadJsonTranslationsFrom(__DIR__ . '/../../resources/lang');
        }
    }
}