<?php

namespace Workdo\SignInWithLinkedIn\app\Providers;

use Illuminate\Support\ServiceProvider;
use Workdo\SignInWithLinkedIn\app\Providers\EventServiceProvider;
use Workdo\SignInWithLinkedIn\app\Providers\RouteServiceProvider;

class SignInWithLinkedInServiceProvider extends ServiceProvider
{

    protected $moduleName = 'SignInWithLinkedIn';
    protected $moduleNameLower = 'signinwithlinkedin';

    public function register()
    {
    }

    public function boot()
    {
        $this->loadRoutesFrom(__DIR__ . '/../../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'sign-in-with-linked-in');
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
