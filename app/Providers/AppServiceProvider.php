<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Classes\Module;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use App\Listeners\EnforceLotteryTicketPrice;
use App\Listeners\NormaliseRewardRedemptionFlag;
use App\Models\Order;
use App\Observers\OrderObserver;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * PHASE 03 M4-5. `scoped`, NOT `singleton`: this holds an unpublished draft, and a
         * singleton survives between requests in a worker that keeps the application alive
         * (Octane, queue workers). A draft leaking into an anonymous shopper's request is the one
         * failure the preview slice exists to prevent, and shared state is how it would happen.
         */
        $this->app->scoped(\App\Support\Theme\ThemePreviewContext::class);

        // P1-SEC-77. `decrypt()` is `unserialize()` with no class restriction, APP_KEY is in git history, and over a hundred call sites hand it a value from the
        // request: an object-injection door at every one. The framework registers its encrypter before any app provider, so this EXTENDS that singleton (the key,
        // the cipher and the previous keys come from it) with one whose `decrypt()` hands PHP nothing but plain data and refuses the rest as a tampered payload.
        // See ObjectFreeEncrypter for what the application encrypts (ids and arrays of scalars) and why nothing legitimate can notice.
        $this->app->extend('encrypter', function (\Illuminate\Encryption\Encrypter $encrypter, $app) {
            return (new \App\Support\Security\ObjectFreeEncrypter($encrypter->getKey(), $app['config']['app.cipher']))
                ->previousKeys($encrypter->getPreviousKeys());
        });

        // P1-SEC-75. The Helpdesk module's ticket list builds the name, subject, creator, category and status cells as raw markup (`rawColumns`) from text a merchant typed. The
        // controller injects the module's class, so binding the subclass that escapes those cells over it is what changes what the operator's browser is handed. Bound by NAME:
        // nothing here loads either class, so this costs nothing while the module is off.
        $this->app->bind(\Workdo\Helpdesk\DataTables\HelpdeskData::class, \App\DataTables\SafeHelpdeskData::class);
        // ... and the module's category and status lists, whose colour swatch writes a stored value into a `style` attribute of a raw column (the operator's pages)
        $this->app->bind(\Workdo\Helpdesk\DataTables\HelpdeskCategoryData::class, \App\DataTables\SafeHelpdeskCategoryData::class);
        $this->app->bind(\Workdo\Helpdesk\DataTables\HelpdeskStatusData::class, \App\DataTables\SafeHelpdeskStatusData::class);

        // P1-SEC-69. The Campaigns module's list applies its tenant predicate only when `users.type` is the literal 'admin' -- a merchant OWNER's type -- so a merchant's STAFF were served
        // every tenant's campaigns, and the expiry loop at the end of that same query wrote to every row it returned. Measured by driving the route with the module loaded. Bound by NAME for
        // the same reason as the three above: nothing here loads either class, so this costs nothing while the module is off.
        $this->app->bind(\Workdo\Campaigns\DataTables\AllCampaignsData::class, \App\DataTables\TenantScopedCampaignsData::class);

        $this->app->singleton('module', function ($app) {
            return new Module();
        });

        // P1-SEC-13. Replaces the package's own binding (package providers register before app providers)
        // with a subclass that does not persist the request body or credential headers. See the class.
        // P1-SEC-14. The error tracker's payload carried cookies, the session, the request body and the user model; this subclass scrubs it
        // (package providers register first, so this replaces the package's own 'larabug' binding, as the visitor binding below does).
        $this->app->singleton('larabug', function () {
            return new \App\Services\ScrubbedLaraBug(new \LaraBug\Http\Client(
                config('larabug.login_key', 'login_key'),
                config('larabug.project_key', 'project_key')
            ));
        });

        $this->app->singleton('shetabit-visitor', function () {
            return new \App\Services\SanitisedVisitor(app(\Illuminate\Http\Request::class), config('visitor'));
        });
       
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Order::observe(OrderObserver::class);

        /*
         | ANA — A STOREFRONT VISIT NOW SAYS WHICH STORE IT WAS. Measured 2026-11-20: it said nobody's.
         |
         | `ActiveTheme` logs every themed page view with `visitor()->visit($store)`, but the vendored
         | package records the subject only when the model it is handed has a `visitLogs()` method
         | (`Shetabit\Visitor\Visitor::visit()`, the `method_exists` branch). `App\Models\Store` has
         | neither that method nor the package's `Visitable` trait, so the call fell through to
         | `Visit::create($data)` and the `$store` argument was DISCARDED — the row carried no
         | `visitable_*` and no `store_id`.
         |
         | Nine first-party queries filter those rows by `store_id`: `Api\DashboardController`'s visitor
         | and unique-visitor counts (twice each), `ThemeAnalyticController`'s urls, device, browser,
         | platform, count and unique IPs, and `HomeController`'s `whereNotNull('store_id')`. Each read
         | nothing for every store and reported **0 visitors** instead of failing — the "fallback that
         | never fires" shape P5-10 recorded.
         |
         | Filling the column as the row is created is ONE write, needs no vendored edit and no migration
         | (the column has existed since 2024-01-26). `SanitisedVisitor` supplies the attribution: the
         | store the caller named, else `getRouteStoreId()`. `store_id` is absent from the package model's
         | `$fillable`, which is why this is a direct assignment and not mass assignment. A console run, a
         | queue job or an unknown slug resolves null and the row honestly names no store. Attribution is
         | by this COLUMN, not by the package's morph, because the column is what every reader filters on.
         | Pinned by tests/Feature/Analytics/StorefrontVisitAttributionTest.
         */
        \Shetabit\Visitor\Models\Visit::creating(static function (\Shetabit\Visitor\Models\Visit $visit): void {
            if ($visit->store_id === null) {
                $visit->store_id = \App\Services\SanitisedVisitor::attributedStoreId();
            }
        });

        /*
         * P1-INV-21 / #68 item 1 -- the lottery ticket's recorded price comes from a client-posted
         * hidden input while the CHARGE comes from the database. This rewrites the recorded value
         * from the authoritative `lottery_settings.ticket_price`.
         *
         * REGISTERED BY EVENT-NAME STRING rather than `PurchaseTicket::observe()`, because the
         * model lives in a vendored module that may not be loaded. `Event::listen` on a string key
         * needs no class, so this cannot fatal when LotteryPrizes is absent and cannot race the
         * module loader when it is present -- module providers boot from `app->booted()`, after
         * this one. The listener's docblock records how it was verified that `Cachable` does not
         * suppress the `creating` event, and why it rewrites the amount and nothing else.
         */
        Event::listen(EnforceLotteryTicketPrice::EVENT, EnforceLotteryTicketPrice::class);

        /*
         * D7 -- the vendored `AddRewardClubPointLis:38` mis-binds `??` against `==`/`&&`, so its
         * guard collapses to the TRUTHINESS of the posted `club_point_is_active`. `'off'` is
         * truthy, and `'off'` is exactly what the shipped checkout form posts on every order, so
         * the redemption branch runs and REPLACES `customers.total_club_point` with just that
         * order's earned points. This listener reduces the flag to `'on'`-or-absent and touches
         * nothing else.
         *
         * THE ORDERING IS THE POINT: this runs during the host's own `boot()`, while module
         * providers are brought in from `app->booted()` — strictly later. The event object is
         * passed by handle, so the array rewritten here is the array the vendored listener then
         * reads. MEASURED, not assumed: a probe listener registered from `app->booted()`, exactly
         * as a module provider arrives, observed the flag as ABSENT.
         *
         * THIS LINE IS BELT-AND-BRACES, NOT THE ONLY REGISTRATION, and saying so here matters
         * because a mutation that deletes it does NOT break the behaviour. Laravel 11's
         * `Application::configure()` calls `->withEvents()` unconditionally, and the framework
         * EventServiceProvider it registers has discovery ON over `app/Listeners` — so the class
         * is auto-registered by its `handle()` signature as well. It is kept explicit anyway,
         * because `App\Providers\EventServiceProvider::shouldDiscoverEvents()` returns FALSE and
         * would lead a reader to conclude discovery is off. The handler is idempotent and a test
         * pins that, so running twice is harmless. Full mechanism in the listener's docblock.
         */
        Event::listen(NormaliseRewardRedemptionFlag::EVENT, NormaliseRewardRedemptionFlag::class);

        $cachePath = storage_path('framework/cache/data');

        if (! File::exists($cachePath)) {
            File::makeDirectory($cachePath, 0755, true);
        }
    }
}