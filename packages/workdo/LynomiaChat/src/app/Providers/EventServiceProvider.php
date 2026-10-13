<?php

namespace Workdo\LynomiaChat\app\Providers;

use App\Events\CompanyMenuEvent;
use App\Events\SuperAdminMenuEvent;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as Provider;
use Workdo\LynomiaChat\app\Listeners\CompanyMenuListener;
use Workdo\LynomiaChat\app\Listeners\SuperAdminMenuListener;

class EventServiceProvider extends Provider
{
    protected $listen = [
        CompanyMenuEvent::class => [
            CompanyMenuListener::class,
        ],
        SuperAdminMenuEvent::class => [
            SuperAdminMenuListener::class,
        ],
    ];

    protected function discoverEventsWithin(): array
    {
        return [__DIR__ . '/../Listeners'];
    }
}
