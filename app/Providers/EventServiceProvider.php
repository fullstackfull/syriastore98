<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;
use App\Events\SuperAdminMenuEvent;
use App\Events\VerifyReCaptchaToken;
use App\Listeners\VerifyReCaptchaTokenLis;
use App\Events\OrderPaymentStatus;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
 protected $listen = [

    Registered::class => [
        SendEmailVerificationNotification::class,
    ],

    VerifyReCaptchaToken::class => [
        VerifyReCaptchaTokenLis::class,
    ],

    // OrderPaymentStatus is DELIBERATELY KEPT with no listeners.
    //
    // Its only listener was SendChatwootOrderStatusNotification, retired with the rest of the
    // legacy integration. The event itself is a legitimate domain signal, dispatched from six
    // sites in App\Models\Order::order_status_change plus the admin and courier APIs, and it is
    // the commerce-event surface the approved Store Syria x Lynomia architecture consumes. It
    // stays registered so the dispatch sites keep a declared event and the replacement has
    // something to subscribe to. An event with no listeners is inert -- Laravel's dispatcher
    // simply finds nothing to call. No placeholder listener is added here.
    OrderPaymentStatus::class => [],

];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}