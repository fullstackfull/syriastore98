<?php

namespace workdo\CouponEmail\app\Providers;

use App\Events\AuthorizeNetPayment;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as Provider;
use App\Events\CreateCustomer;
use App\Events\GetProductStatus;
use App\Events\PaiementProPayment;
use Workdo\CouponEmail\app\Listeners\AuthorizeNetPaymentLis;
use Workdo\CouponEmail\app\Listeners\CreateCustomerLis;
use Workdo\CouponEmail\app\Listeners\CreateOrderLis;
use Workdo\CouponEmail\app\Listeners\PaiementProPaymentLis;

class EventServiceProvider extends Provider
{
    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    protected $listen = [
        AuthorizeNetPayment::class => [
            AuthorizeNetPaymentLis::class,
        ],
        CreateCustomer::class => [
            CreateCustomerLis::class,
        ],
        GetProductStatus::class => [
            CreateOrderLis::class,
        ],
        PaiementProPayment::class => [
            PaiementProPaymentLis::class,
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
            __DIR__ . '/../../Listeners',
        ];
    }
}
