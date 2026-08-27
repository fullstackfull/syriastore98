<?php

namespace workdo\PurchaseNotification\app\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as Provider;

class EventServiceProvider extends Provider
{
    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    protected $listen = [
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
