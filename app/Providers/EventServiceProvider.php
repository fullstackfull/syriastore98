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
use App\Services\Marketing\EnforceMarketingConsentOnMail;
use App\Support\Audit\RecordImpersonation;
use Illuminate\Mail\Events\MessageSending;
use Lab404\Impersonate\Events\LeaveImpersonation;
use Lab404\Impersonate\Events\TakeImpersonation;

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

    // THE EMAIL SEAM OF THE MARKETING CONSENT GATE (D8 / GAP-200). INERT unless MARKETING_CONSENT_ENFORCEMENT is `report` or
    // `enforce`: with the shipped default, `off`, the listener returns after one configuration read and touches nothing else, on
    // the path of every email the product sends. It is the only place MarketingSendGate is called, and a census says so.
    MessageSending::class => [
        EnforceMarketingConsentOnMail::class,
    ],

    // THE AUDIT TRAIL FOR "LOG IN AS". The operator could become any merchant and nothing recorded
    // that it happened, so every write afterwards read in the trail as the merchant's own action.
    //
    // A LISTENER AND NOT TWO CONTROLLER LINES: lab404 dispatches both events from
    // `ImpersonateManager`, so every impersonation is recorded -- including one taken through a door
    // this repository does not have yet. Two controller lines record only the doors someone
    // remembered. `RecordImpersonation` has the measurement that makes it non-trivial: `take()`
    // dispatches AFTER the guard has already been switched to the impersonated merchant.
    //
    // THIS CHANGES NO PERMISSION. `UserPolicy::impersonate` is not touched.
    // THE RECORDER LIVES IN `app/Support/Audit/`, NOT `app/Listeners/`, AND THAT IS THE WHOLE
    // POINT OF THIS COMMENT. This repository already records why, in two places I should have read
    // first: `AppServiceProvider` (on `NormaliseRewardRedemptionFlag`) and
    // `EnforceMarketingConsentOnMail`'s own docblock. Laravel 11's `Application::configure()` calls
    // `->withEvents()` unconditionally and the framework EventServiceProvider it registers has
    // DISCOVERY ON over `app/Listeners` -- so a class there is auto-registered by its handler
    // signature IN ADDITION to any mapping here, however that mapping is spelled.
    // `shouldDiscoverEvents()` returning false below is about THIS provider and misleads a reader
    // into thinking discovery is off.
    //
    // I re-derived it the hard way: the recorder was written to `app/Listeners/`, and every
    // impersonation wrote TWO audit rows. Rewriting the mapping from `[Class::class, 'method']` to
    // the `Class@method` string changed nothing; MOVING THE CLASS fixed it. The control case is
    // `MessageSending` above, whose listener is outside the scanned directory and has exactly one
    // registration.
    //
    // The two listeners that DO sit in `app/Listeners/` are handled the other way -- they are
    // idempotent, so running twice is harmless. AN AUDIT ROW IS NOT IDEMPOTENT: two rows is two
    // rows, and a trail that double-counts what an operator did is worse than no trail. So this one
    // follows the `EnforceMarketingConsentOnMail` precedent instead.
    // `ImpersonationIsAuditedTest` pins the single registration in both directions.
    TakeImpersonation::class => [
        RecordImpersonation::class . '@handleTake',
    ],

    LeaveImpersonation::class => [
        RecordImpersonation::class . '@handleLeave',
    ],

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