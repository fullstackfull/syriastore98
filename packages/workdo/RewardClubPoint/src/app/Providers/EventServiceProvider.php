<?php

namespace workdo\RewardClubPoint\app\Providers;

use App\Events\AddRewardClubPoint;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as Provider;
use Workdo\RewardClubPoint\app\Listeners\AddRewardClubPointLis;

class EventServiceProvider extends Provider
{
    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    protected $listen = [
        AddRewardClubPoint::class => [
            AddRewardClubPointLis::class,
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
