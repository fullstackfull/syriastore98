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
use Workdo\LynomiaChat\app\Services\CommerceCredential;
use Workdo\LynomiaChat\app\Services\EventSignature;
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
        $this->app->bind(\Workdo\LynomiaChat\app\Services\MarketingAllowanceSync::class,
            fn ($app) => new \Workdo\LynomiaChat\app\Services\MarketingAllowanceSync(
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
            \Workdo\LynomiaChat\app\Console\Commands\ReconcileLynomiaDeliveries::class,
            \Workdo\LynomiaChat\app\Console\Commands\ReconcileLynomiaCommerce::class,
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
            // EVERY FIFTEEN MINUTES, which is the grace window the reconciler itself waits
            // out before asking about a notice. A faster sweep would re-read notices it had
            // already decided were too young, and a slower one would leave a merchant's
            // broken-integration notice sitting at `accepted` for longer than an operator
            // can act on. Unlike consents this reads ONE workspace, not one per merchant,
            // so the cost does not grow with the number of stores.
            $schedule->command('lynomia:reconcile-deliveries')->everyFifteenMinutes()->withoutOverlapping();
            // HOURLY, and for the same reason as the consent sweep: it repairs a belief rather
            // than carrying traffic. The states it corrects -- an operator disabling a store in
            // Lynomia, a credential rejected unattended, a response lost after Lynomia had
            // already committed -- move on the scale of a person noticing something, not on the
            // scale of a message arriving. One read per connected store per hour.
            //
            // It cannot install, mint or rotate anything (see ReconcileLynomiaCommerce), so
            // there is no frequency at which this sweep could attach a merchant's store to a
            // workspace unattended.
            $schedule->command('lynomia:reconcile-commerce')->hourly()->withoutOverlapping();
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
     * ONE NUMBER CONFIGURES THE COMMERCE BUDGETS, because two could contradict each other and
     * did: the installation ceiling shipped below a single store's budget, which made the
     * per-store budget unreachable and kept the cross-merchant 429 the tiers exist to stop.
     *
     * The ratio is fixed in code rather than configured. An installation ceiling is only
     * meaningful relative to what one store may spend, so the thing an operator tunes is the
     * store's budget; the ceiling follows it. Isolation across N simultaneously busy stores
     * needs the ceiling to be at least N times the budget, so ten is the number of busy stores
     * this installation is sized for -- raise the multiple, in code and with a reason, before
     * the eleventh.
     */
    private const COMMERCE_INSTALLATION_MULTIPLE = 10;

    /** What ONE verified store may spend per minute. The floor is the events limiter's. */
    private static function commerceStoreBudget(): int
    {
        return max(60, (int) config('lynomia.commerce_rate_per_minute', 600));
    }

    /**
     * THE EVENT BUDGETS, derived the same way and for the same reason as the commerce pair
     * below -- see the note there. `lynomia-ingress` had the identical defect: 300/minute keyed
     * on the caller IP, in front of a per-store budget of 600, on a route Lynomia delivers every
     * merchant's events to from one egress address. No store could reach its own budget and
     * `lynomia-events:all` was unreachable.
     *
     * IT IS WORSE HERE THAN IT WAS THERE, in two ways. A refused commerce read degrades a panel;
     * a refused delivery is GONE -- an account webhook failure is swallowed by Webhooks::Trigger
     * and never retried, as the note on registerIngressLimiter records. And `lynomia-ingress` is
     * tier 1 for TWO routes, merchant events and the platform's own notices, so the 300 was
     * shared between them: a busy merchant's traffic silently consumed the budget the
     * installation needed to tell its merchants their integration was broken.
     */
    private const INGRESS_INSTALLATION_MULTIPLE = 10;

    /** What ONE verified store may spend on deliveries per minute. */
    private static function ingressStoreBudget(): int
    {
        return max(60, (int) config('lynomia.ingress_rate_per_minute', 600));
    }

    /** What the platform's own notices may spend per minute. */
    private static function platformIngressBudget(): int
    {
        return max(60, (int) config('lynomia.platform_ingress_rate_per_minute', 600));
    }

    /** What ALL merchant deliveries together may spend per minute. */
    private static function ingressEventsCeiling(): int
    {
        return self::ingressStoreBudget() * self::INGRESS_INSTALLATION_MULTIPLE;
    }

    /**
     * What tier 1 allows, which has to cover BOTH tiers beneath it: `lynomia-ingress` is the
     * first element of the merchant-events stack and of the platform-events stack, so a ceiling
     * sized for one of them starves the other.
     */
    private static function ingressSharedCeiling(): int
    {
        return self::ingressEventsCeiling() + self::platformIngressBudget();
    }

    /** What ALL commerce traffic may spend per minute, which on one egress address is the ceiling. */
    private static function commerceInstallationCeiling(): int
    {
        return self::commerceStoreBudget() * self::COMMERCE_INSTALLATION_MULTIPLE;
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
        // TIER 1 -- what a delivery pays BEFORE its signature is checked, for both ingress routes.
        //
        // KEYED ON THE IP, WHICH HERE MEANS KEYED ON THE WHOLE INSTALLATION, because Lynomia
        // delivers every merchant's events from a single egress address. So this is the ceiling on
        // all inbound deliveries there are, and it is sized as one -- see ingressSharedCeiling.
        // It used to be 300, below one store's 600, which is why no store could reach its own
        // budget and why a busy merchant could silently consume the allowance the platform's own
        // notices needed.
        //
        // WHAT STILL BOUNDS GARBAGE CHEAPLY. A request carrying no signature header at all cannot
        // be Lynomia, and seeing that is a header lookup -- no HMAC over the body, no key
        // derivation, no database read. It gets its own much smaller allowance, which a real
        // delivery never touches. A signed-but-forged delivery does reach the HMAC, bounded by the
        // ceiling: that work is one hash, and a tight bound in front of it is shared with honest
        // deliveries, which is the defect above.
        RateLimiter::for('lynomia-ingress', function (Request $request) {
            $limits = [
                Limit::perMinute(self::ingressSharedCeiling())
                    ->by('lynomia-ingress:ip:' . $request->ip()),
            ];

            if (! $request->hasHeader(EventSignature::SIGNATURE_HEADER)) {
                $limits[] = Limit::perMinute(max(1, (int) config('lynomia.ingress_unverified_rate_per_minute', 300)))
                    ->by('lynomia-ingress:unsigned:' . $request->ip());
            }

            return $limits;
        });

        // THE COMMERCE API, TIER 1 -- what a request pays BEFORE its credential is parsed.
        //
        // KEYED ON THE IP, WHICH ON THIS ROUTE MEANS KEYED ON THE WHOLE INSTALLATION. Lynomia is
        // the only caller of this API and it reads every merchant's orders from a single egress
        // address, so a per-IP limit here is not a per-caller limit -- it is the ceiling on all
        // commerce traffic there is. That is why the number is the INSTALLATION ceiling and is
        // derived from the per-store budget rather than configured beside it.
        //
        // THE FIRST ATTEMPT AT THIS FIX GOT IT WRONG, and the way it was wrong is worth keeping
        // written down. Reordering the stack made the per-store budget reachable, but this tier
        // kept the 120/minute the single shared bucket used to have -- below one store's 600 --
        // so the binding constraint was still one 120/minute allowance for the entire
        // installation, on the very same `lynomia-commerce:ip:` key as the defect. A store could
        // not reach its own budget, `lynomia-commerce:all` was unreachable, and one busy
        // merchant still returned 429 to every other merchant's panel. A per-store budget behind
        // an installation ceiling smaller than itself is not a per-store budget.
        //
        // WHAT STILL BOUNDS GARBAGE CHEAPLY. A request whose Authorization header does not even
        // carry a `lsc_`-shaped bearer token cannot be Lynomia, and recognising that is a string
        // comparison -- no HMAC, no database read. It gets its own much smaller allowance, which
        // honest traffic never touches. A well-formed but forged credential does reach the HMAC
        // and the link lookup, bounded by the installation ceiling: that work is one hash and one
        // indexed read, and the alternative -- a tight shared bound in front of it -- is the
        // defect above.
        RateLimiter::for('lynomia-commerce-ip', function (Request $request) {
            $limits = [
                Limit::perMinute(self::commerceInstallationCeiling())
                    ->by('lynomia-commerce:ip:' . $request->ip()),
            ];

            if (! str_starts_with((string) $request->header('Authorization', ''), 'Bearer ' . CommerceCredential::PREFIX)) {
                $limits[] = Limit::perMinute(max(1, (int) config('lynomia.commerce_unverified_rate_per_minute', 120)))
                    ->by('lynomia-commerce:malformed:' . $request->ip());
            }

            return $limits;
        });

        // THE COMMERCE API, TIER 2 -- the real per-store budget, and ONLY a credential-verified
        // request reaches it.
        //
        // THIS USED TO BE ONE TIER AND IT WAS THE IP ONE, FOR EVERY MERCHANT AT ONCE. The route
        // listed `throttle:lynomia-commerce` BEFORE AuthenticateCommerceApi, and this limiter
        // read the store from a request attribute that the middleware sets at the END of its
        // handle(). Laravel resolves a named limiter when the throttle middleware runs, so the
        // attribute was always unset, `is_int($store)` was always false, and every request --
        // every store's -- fell to one shared bucket keyed on the caller IP. Lynomia reads every
        // merchant's orders from one egress address, so that bucket was ONE 120/minute allowance
        // for the entire installation: one busy merchant's agents returned 429 to every other
        // merchant's commerce panel, and Lynomia does not retry a 429 (its Commerce::HttpClient
        // retries only 502/503/504), so the panel degraded or went blank for a tenant who had
        // done nothing. The per-store branch below was unreachable dead code.
        //
        // The route now carries the three-element stack the events ingress has carried all
        // along -- IP ceiling, then verification, then this -- which is what makes the store id
        // here a VERIFIED one rather than an absent one.
        RateLimiter::for('lynomia-commerce', function (Request $request) {
            $store = $request->attributes->get(
                \Workdo\LynomiaChat\app\Http\Middleware\AuthenticateCommerceApi::STORE_ID
            );

            // Unreachable in the configured stack -- AuthenticateCommerceApi rejects an
            // unverified request before this runs, and the store id is stamped from the
            // credential it just verified, never from anything the caller supplied. If some
            // future rewiring lets one through, it gets no budget at all rather than somebody
            // else's: the same choice `lynomia-events` makes, for the same reason.
            if (! is_int($store)) {
                return [Limit::perMinute(1)->by('lynomia-commerce:unverified:' . $request->ip())];
            }

            return [
                Limit::perMinute(self::commerceStoreBudget())->by('lynomia-commerce:store:' . $store),
                // The same installation ceiling tier 1 applies, measured again behind the
                // credential check. Redundant while Lynomia calls from one address, and not
                // redundant the day it calls from two: tier 1's key is the address, this one's
                // is the installation. Both read one number so they cannot drift apart.
                Limit::perMinute(self::commerceInstallationCeiling())->by('lynomia-commerce:all'),
            ];
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

            return [
                Limit::perMinute(self::ingressStoreBudget())->by('lynomia-events:store:' . $store),
                // Every merchant's deliveries together. Tier 1 covers this plus the platform's
                // share; both read the same helpers, so the three numbers cannot contradict.
                Limit::perMinute(self::ingressEventsCeiling())->by('lynomia-events:all'),
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

        // The platform delivery feed. One producer, Lynomia's own workspace webhook, so this is
        // a backstop against a loop on the far side rather than a per-tenant fairness budget --
        // there are no tenants to be fair between. Generous, because a burst of delivery
        // receipts after a queue drains is ordinary and must not look like an attack.
        RateLimiter::for('lynomia-platform-events', function (Request $request) {
            return [Limit::perMinute(self::platformIngressBudget())->by('lynomia-platform-events')];
        });

        // B4. Confirming a merchant's number makes a phone ring, so the budget is small and it
        // is keyed on the PERSON, not the number: the service's own cooldown stops a merchant
        // asking again for the same number every second, and this stops one session walking a
        // list of other people's numbers a minute at a time. Both are needed -- the cooldown is
        // per challenge and a new number replaces the challenge.
        RateLimiter::for('lynomia-contact-verify', function (Request $request) {
            $user = $request->user();

            return is_null($user)
                ? [Limit::perMinute(3)->by('lynomia-contact-verify:anonymous:' . $request->ip())]
                : [Limit::perHour((int) config('lynomia.contact_verify_rate_per_hour', 10))
                    ->by('lynomia-contact-verify:user:' . $user->id)];
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
        // BOTH ingress doors. `lynomia/platform-events` does not match `lynomia/events/*`, so
        // without the second pattern the platform feed got the full recursive walk of an
        // unauthenticated body that this method exists to prevent for the other one.
        $isIngress = static fn (Request $request): bool => $request->is('lynomia/events/*')
            || $request->is('lynomia/platform-events');

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
