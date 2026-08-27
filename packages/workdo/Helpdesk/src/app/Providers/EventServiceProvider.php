<?php

namespace workdo\Helpdesk\app\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as Provider;
use App\Events\CompanyMenuEvent;
use App\Events\SuperAdminMenuEvent;
use Workdo\Helpdesk\app\Listeners\CompanyMenuListener;
use Workdo\Helpdesk\app\Listeners\SuperAdminMenuListener;

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
        SuperAdminMenuEvent::class => [
            SuperAdminMenuListener::class,
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
