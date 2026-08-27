<?php

namespace workdo\ReviewReminder\app\Providers;

use App\Events\SendOrderReviewMail;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as Provider;
use App\Events\CompanyMenuEvent;
use Workdo\ReviewReminder\app\Listeners\CompanyMenuListener;
use Workdo\ReviewReminder\app\Listeners\SendOrderReviewMailListner;

class EventServiceProvider extends Provider
{
    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    protected $listen = [
        CompanyMenuEvent::class => [
            CompanyMenuListener::class,
        ],
        SendOrderReviewMail::class => [
            SendOrderReviewMailListner::class,
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
