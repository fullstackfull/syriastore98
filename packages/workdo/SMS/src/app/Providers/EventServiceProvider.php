<?php

namespace workdo\SMS\app\Providers;

use App\Events\AuthorizeNetPayment;
use App\Events\GetProductStatus;
use App\Events\OrderPaymentStatus;
use App\Events\PaiementProPayment;
use App\Events\SuperAdminMenuEvent;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as Provider;
use Workdo\SMS\app\Listeners\AuthorizeNetPaymentLis;
use Workdo\SMS\app\Listeners\GetProductStatusLis;
use Workdo\SMS\app\Listeners\OrderPaymentStatusLis;
use Workdo\SMS\app\Listeners\PaiementProPaymentLis;
use Workdo\SMS\app\Listeners\SuperAdminMenuListener;

class EventServiceProvider extends Provider
{
    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    protected $listen = [
        SuperAdminMenuEvent::class => [
            SuperAdminMenuListener::class,
        ],
        AuthorizeNetPayment::class => [
            AuthorizeNetPaymentLis::class,
        ],
        OrderPaymentStatus::class => [
            OrderPaymentStatusLis::class,
        ],
        PaiementProPayment::class => [
            PaiementProPaymentLis::class,
        ],
        GetProductStatus::class => [
            GetProductStatusLis::class,
        ],

    ];

    /**
     * Get the listener directories that should be used to discover events.
     *
     * @return array
     */
    protected function discoverEventsWithin()
    {
        return [
            __DIR__ . '/../Listeners',
        ];
    }
}
