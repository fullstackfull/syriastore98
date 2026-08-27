<?php

namespace workdo\HubSpot\app\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as Provider;
use App\Events\CompanyMenuEvent;
use App\Events\CompanySettingEvent;
use App\Events\CompanySettingMenuEvent;
use workdo\HubSpot\app\Listeners\CompanyMenuListener;
use workdo\HubSpot\app\Listeners\CompanySettingListener;
use workdo\HubSpot\app\Listeners\CompanySettingMenuListener;

class EventServiceProvider extends Provider
{
    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    protected $listen = [
        // CompanyMenuEvent::class => [
        //     CompanyMenuListener::class,
        // ],
        // CompanySettingEvent::class => [
        //     CompanySettingListener::class,
        // ],
        // CompanySettingMenuEvent::class => [
        //     CompanySettingMenuListener::class,
        // ],
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
