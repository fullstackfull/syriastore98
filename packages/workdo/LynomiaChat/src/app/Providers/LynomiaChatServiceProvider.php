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

        $this->commands([\Workdo\LynomiaChat\app\Console\Commands\ReconcileLynomiaEvents::class]);

        $this->app->booted(function () {
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
            $schedule->command('lynomia:reconcile-events')->everyFiveMinutes()->withoutOverlapping();
        });
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
        RateLimiter::for('lynomia-events', function (Request $request) {
            $store = (int) $request->route('store');
            $perMinute = max(60, (int) config('lynomia.ingress_rate_per_minute', 600));

            return [
                // The store's own ceiling.
                Limit::perMinute($perMinute)->by('lynomia-events:store:' . $store),
                // A whole-installation ceiling, so a flood spread across many fabricated store
                // ids cannot multiply the per-store allowance into an unbounded one.
                Limit::perMinute($perMinute * 10)->by('lynomia-events:all'),
            ];
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
