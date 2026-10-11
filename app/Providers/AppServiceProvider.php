<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Classes\Module;
use Illuminate\Support\Facades\File;
use App\Models\Order;
use App\Observers\OrderObserver;


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
        Order::observe(OrderObserver::class);
        $cachePath = storage_path('framework/cache/data');

        if (! File::exists($cachePath)) {
            File::makeDirectory($cachePath, 0755, true);
        }
    }
}