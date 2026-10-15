<?php

namespace Workdo\LynomiaChat\app\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Workdo\LynomiaChat\app\Services\AccountLinker;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;
use Workdo\LynomiaChat\app\Services\EventSubscriptionManager;
use Workdo\LynomiaChat\app\Services\IdentityProvisioner;
use Workdo\LynomiaChat\app\Services\MerchantInboxGateway;
use Workdo\LynomiaChat\app\Services\PlatformApiClient;
use Workdo\LynomiaChat\app\Services\SeatAllowanceSync;
use Workdo\LynomiaChat\app\Services\SsoLinkIssuer;

class LynomiaChatServiceProvider extends ServiceProvider
{
    protected string $moduleName = 'LynomiaChat';
    protected string $moduleNameLower = 'lynomia-chat';

    public function register(): void
    {
        $this->app->register(EventServiceProvider::class);
        $this->app->register(ViewComposer::class);

        // Merged rather than published, so a deployment cannot end up with a stale copy of
        // the module's own defaults in config/.
        $this->mergeConfigFrom(__DIR__ . '/../../config/lynomia.php', 'lynomia');

        // One client per request. Every service takes it by constructor injection, so a test
        // swaps the single binding and the whole module is exercised against a fake.
        $this->app->singleton(PlatformApiClient::class, fn () => PlatformApiClient::fromConfig());

        $this->app->bind(IdentityProvisioner::class, fn ($app) => new IdentityProvisioner($app->make(PlatformApiClient::class)));

        $this->app->bind(AccountProvisioner::class, fn ($app) => new AccountProvisioner(
            $app->make(PlatformApiClient::class),
            $app->make(IdentityProvisioner::class),
            $app->make(SeatAllowanceSync::class),
            $app->make(EventSubscriptionManager::class)
        ));
        $this->app->bind(AccountLinker::class, fn ($app) => new AccountLinker(
            $app->make(PlatformApiClient::class),
            $app->make(IdentityProvisioner::class),
            $app->make(SeatAllowanceSync::class)
        ));
        $this->app->bind(EventSubscriptionManager::class, fn ($app) => new EventSubscriptionManager(
            $app->make(MerchantInboxGateway::class)
        ));
        $this->app->bind(SeatAllowanceSync::class, fn ($app) => new SeatAllowanceSync(
            $app->make(PlatformApiClient::class)
        ));
        $this->app->bind(MerchantInboxGateway::class, fn ($app) => new MerchantInboxGateway(
            $app->make(PlatformApiClient::class),
            $app->make(IdentityProvisioner::class)
        ));
        $this->app->bind(\Workdo\LynomiaChat\app\Services\ConsentReconciler::class, fn ($app) => new \Workdo\LynomiaChat\app\Services\ConsentReconciler(
            $app->make(PlatformApiClient::class),
            $app->make(\Workdo\LynomiaChat\app\Services\ConsentStateProjector::class)
        ));
        $this->app->bind(SsoLinkIssuer::class, fn ($app) => new SsoLinkIssuer(
            $app->make(PlatformApiClient::class),
            $app->make(IdentityProvisioner::class)
        ));
    }

    public function boot(): void
    {
        // Single registration -- see the note in routes/web.php about why no module
        // RouteServiceProvider is registered.
        $this->loadRoutesFrom(__DIR__ . '/../../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'lynomia-chat');
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        $this->registerTranslations();
        $this->registerIngressLimiter();
        $this->registerCommands();
        $this->registerCommerceEventObserver();
        $this->registerCheckoutConsentObserver();
        $this->skipBodyTransformsOnIngress();
    }

    /**
     * The reconciliation sweep is scheduled here rather than in routes/console.php, so the
     * module stays self-contained and an installation that does not load it schedules nothing.
     * Every five minutes, with an overlap guard -- the same shape as the platform's existing
     * zid:process-events drain.
     */
    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            \Workdo\LynomiaChat\app\Console\Commands\ReconcileLynomiaEvents::class,
            \Workdo\LynomiaChat\app\Console\Commands\DeliverLynomiaCommerceEvents::class,
            \Workdo\LynomiaChat\app\Console\Commands\DeliverLynomiaConsentDecisions::class,
            \Workdo\LynomiaChat\app\Console\Commands\ReconcileLynomiaConsents::class,
        ]);

        $this->app->booted(function () {
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
            $schedule->command('lynomia:reconcile-events')->everyFiveMinutes()->withoutOverlapping();
            // Every minute, because this one is the live path: an order's status changing is
            // what an agent is waiting to see, and the sweep above is the safety net behind it.
            $schedule->command('lynomia:deliver-commerce-events')->everyMinute()->withoutOverlapping();
            // Every minute too: a consent decision the shopper just made is what decides
            // whether the next campaign may reach them, and a decision sitting in an outbox
            // is a decision that has not taken effect.
            $schedule->command('lynomia:deliver-consent-decisions')->everyMinute()->withoutOverlapping();
            // HOURLY, because this repairs rare LOSSES rather than carrying live traffic.
            // Lynomia's webhook sender swallows a failed delivery instead of retrying it, so
            // an opt-out that arrived while this side was deploying is gone and nothing in
            // the push path notices. One read per connected store per hour is what notices.
            $schedule->command('lynomia:reconcile-consents')->hourly()->withoutOverlapping();
        });
    }

    /**
     * EVERY ORDER WRITE PASSES THROUGH THE MODEL, which is why the commerce outbox is filled
     * from an observer rather than from App\Events\OrderPaymentStatus. That event is not
     * dispatched on creation and not dispatched by the ~52 gateway controllers that write
     * payment_status directly; the retired Chatwoot integration listened to it and therefore
     * never saw a new order.
     *
     * Registered unconditionally, including in the web process: the observer's first act is to
     * check the kill switch and the store's link, so an installation with the integration off
     * or a store with no workspace writes nothing.
     */
    protected function registerCommerceEventObserver(): void
    {
        \App\Models\Order::observe(\Workdo\LynomiaChat\app\Observers\CommerceEventObserver::class);
    }

    /**
     * The checkout marketing opt-in, captured where every order writer passes -- the same
     * reasoning as the commerce outbox above, and for the same reason: an order is written by
     * PaymentService, PaymentController, the admin form, two API controllers and roughly fifty
     * gateway controllers, and `App\Events\AddAdditionalFields` reaches only about twenty of
     * them (and in PaymentService fires before the saving transaction opens).
     */
    protected function registerCheckoutConsentObserver(): void
    {
        \App\Models\Order::observe(\Workdo\LynomiaChat\app\Observers\CheckoutConsentObserver::class);
    }

    /**
     * A BUCKET PER STORE, BECAUSE THE INHERITED ONE WAS A BUCKET PER PLANET.
     *
     * The ingress route carried `->middleware('api')`, and bootstrap/app.php applies
     * `throttle:120,1` to that whole group. Measured on the booted router, the effective stack
     * was ThrottleRequests:120,1 + SubstituteBindings. Passing a literal rather than a limiter
     * NAME makes ThrottleRequests take its unnamed branch, which keys on
     * sha1(domain|ip) -- so every store's deliveries shared ONE counter, together with the two
     * hundred-odd routes/api.php endpoints reached from the same address. Lynomia sends every
     * store's events from one egress IP, so one busy merchant throttled everybody.
     *
     * That would be merely unfair if a refusal were retried. It is not: an account webhook
     * failure is swallowed by Webhooks::Trigger and never enters Sidekiq's retry or dead set
     * (the fork documents this deliberately in custom/app/services/custom/webhooks/trigger.rb).
     * A 429 therefore DESTROYS the event. The limit has to be per store so that one store can
     * never consume another's budget, and generous enough that honest traffic never reaches it
     * -- it is an abuse ceiling, not a queue.
     *
     * THE COUNTER IS ONLY AS ATOMIC AS THE CACHE STORE. This installation runs CACHE_STORE=file,
     * and Illuminate\Cache\FileStore::increment is a read-modify-write with no lock, so under a
     * genuine burst it undercounts. That is acceptable for a ceiling whose job is to stop abuse
     * rather than to meter, and it is recorded in the integration docs rather than papered over.
     */
    protected function registerIngressLimiter(): void
    {
        // TIER 1 -- the anonymous flood. Everyone pays this, before anything is parsed or
        // verified, keyed on the caller. It is the only limit an UNVERIFIED request can consume,
        // so no amount of garbage can touch a merchant's delivery budget.
        RateLimiter::for('lynomia-ingress', function (Request $request) {
            return [
                Limit::perMinute((int) config('lynomia.ingress_unverified_rate_per_minute', 300))
                    ->by('lynomia-ingress:ip:' . $request->ip()),
            ];
        });

        // The commerce API: per credential-bearing store, resolved from the token rather than
        // the IP, so one merchant's adapter traffic cannot starve another's. Unauthenticated
        // callers fall to the IP tier below because the middleware rejects them before any
        // store is known.
        RateLimiter::for('lynomia-commerce', function (Request $request) {
            $store = $request->attributes->get(
                \Workdo\LynomiaChat\app\Http\Middleware\AuthenticateCommerceApi::STORE_ID
            );

            return is_int($store)
                ? [Limit::perMinute((int) config('lynomia.commerce_rate_per_minute', 600))->by('lynomia-commerce:store:' . $store)]
                : [Limit::perMinute(120)->by('lynomia-commerce:ip:' . $request->ip())];
        });

        // TIER 2 -- the real per-store budget, and ONLY a signature-verified delivery reaches
        // it. GuardEventIngress checks the HMAC first and stamps the verified store id; the
        // route parameter is never trusted here, because it is attacker-chosen and keying an
        // unbounded cache on it is itself a resource leak on a file-backed store.
        RateLimiter::for('lynomia-events', function (Request $request) {
            $store = $request->attributes->get(\Workdo\LynomiaChat\app\Http\Middleware\GuardEventIngress::VERIFIED_STORE);

            // Unreachable in the configured stack -- the guard rejects an unverified request
            // before this runs. If some future wiring lets one through, it gets no budget at
            // all rather than somebody else's.
            if (! is_int($store)) {
                return [Limit::perMinute(1)->by('lynomia-events:unverified:' . $request->ip())];
            }

            $perMinute = max(60, (int) config('lynomia.ingress_rate_per_minute', 600));

            return [
                Limit::perMinute($perMinute)->by('lynomia-events:store:' . $store),
                // A whole-installation ceiling, so many verified stores together still cannot
                // saturate the host.
                Limit::perMinute($perMinute * 10)->by('lynomia-events:all'),
            ];
        });

        // THE STUDIO, WHICH IS THE FIRST SURFACE THAT AMPLIFIES.
        //
        // An inbox request is roughly one Lynomia call. A Studio request is not: opening the
        // builder is a flow read AND four reference reads AND the condition vocabulary, and the
        // rules page is a listing AND the vocabulary. One merchant holding refresh therefore
        // costs Lynomia several times what the same behaviour costs from the inbox, and a
        // merchant does not have to be hostile to do it -- a stuck poll or a reopened laptop
        // will.
        //
        // KEYED ON THE PERSON, NOT THE STORE OR THE IP. The cost is per credential minted, which
        // is per person; keying on the store would let one person's loop exhaust their
        // colleagues' budget, and keying on the IP would do the same to everyone behind one
        // office connection. An unauthenticated caller never reaches here -- `auth` refuses
        // first -- so the fallback exists only so a future wiring mistake gets a small bucket
        // rather than somebody else's.
        RateLimiter::for('lynomia-studio', function (Request $request) {
            $user = $request->user();

            return is_null($user)
                ? [Limit::perMinute(10)->by('lynomia-studio:anonymous:' . $request->ip())]
                : [Limit::perMinute((int) config('lynomia.studio_rate_per_minute', 240))->by('lynomia-studio:user:' . $user->id)];
        });
    }

    /**
     * TrimStrings and ConvertEmptyStringsToNull are global, run before any route middleware, and
     * for Content-Type: application/json each one fully decodes the body and walks every scalar
     * in it recursively. On this route that work is both useless and harmful: the signature is
     * computed over the RAW body, so a transformed parameter bag is never consulted, and the
     * walk happens while the request is still unauthenticated -- an attacker gets two recursive
     * passes over megabytes for free. Skipping them here changes nothing for any other route.
     */
    protected function skipBodyTransformsOnIngress(): void
    {
        $isIngress = static fn (Request $request): bool => $request->is('lynomia/events/*');

        TrimStrings::skipWhen($isIngress);
        ConvertEmptyStringsToNull::skipWhen($isIngress);
    }

    protected function registerTranslations(): void
    {
        $langPath = resource_path('lang/modules/' . $this->moduleNameLower);

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->moduleNameLower);
            $this->loadJsonTranslationsFrom($langPath);

            return;
        }

        $this->loadTranslationsFrom(__DIR__ . '/../../resources/lang', $this->moduleNameLower);
        $this->loadJsonTranslationsFrom(__DIR__ . '/../../resources/lang');
    }
}
