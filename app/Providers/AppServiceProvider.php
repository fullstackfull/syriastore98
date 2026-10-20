<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Classes\Module;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use App\Listeners\EnforceLotteryTicketPrice;
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

        /*
         * P1-INV-21 / #68 item 1 -- the lottery ticket's recorded price comes from a client-posted
         * hidden input while the CHARGE comes from the database. This rewrites the recorded value
         * from the authoritative `lottery_settings.ticket_price`.
         *
         * REGISTERED BY EVENT-NAME STRING rather than `PurchaseTicket::observe()`, because the
         * model lives in a vendored module that may not be loaded. `Event::listen` on a string key
         * needs no class, so this cannot fatal when LotteryPrizes is absent and cannot race the
         * module loader when it is present -- module providers boot from `app->booted()`, after
         * this one. The listener's docblock records how it was verified that `Cachable` does not
         * suppress the `creating` event, and why it rewrites the amount and nothing else.
         */
        Event::listen(EnforceLotteryTicketPrice::EVENT, EnforceLotteryTicketPrice::class);

        $cachePath = storage_path('framework/cache/data');

        if (! File::exists($cachePath)) {
            File::makeDirectory($cachePath, 0755, true);
        }
    }
}