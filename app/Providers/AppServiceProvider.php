<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Classes\Module;
use Illuminate\Support\Facades\File;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton('module', function ($app) {
            return new Module();
        });
       
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $cachePath = storage_path('framework/cache/data');

        if (! File::exists($cachePath)) {
            File::makeDirectory($cachePath, 0755, true);
        }
    }
}
