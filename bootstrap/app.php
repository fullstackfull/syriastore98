<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'setlocate' => \App\Http\Middleware\SetLocate::class,
            'themelanguage' => \App\Http\Middleware\ThemeLanguage::class,
            'APILog' => \App\Http\Middleware\APILog::class,
            'AdminApiLog' => \App\Http\Middleware\AdminApiLog::class,
            'xss' => \App\Http\Middleware\XSS::class,
            'activeTheme' => \App\Http\Middleware\ActiveTheme::class,
            'custom.auth' => \App\Http\Middleware\CustomAuth::class,
            'PlanModuleCheck' => \App\Http\Middleware\PlanModuleCheck::class,
            'verified' => \App\Http\Middleware\EmailIsVerified::class,
            'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
            '2fa' => \PragmaRX\Google2FALaravel\Middleware::class,
            'demo_mode' => \App\Http\Middleware\DemoModeMiddleware::class,
            // Finance route guard. Routes through the `finance` gate, which has a
            // super-admin path that does not depend on a seeded permission row --
            // see App\Http\Middleware\FinancePermission for why neither
            // Laratrust's `permission:` nor Laravel's `can:` is usable here.
            'finance.permission' => \App\Http\Middleware\FinancePermission::class,
        ]);
        // Append middleware to the 'web' group
        $middleware->appendToGroup('web', \App\Http\Middleware\SetLocate::class);
        // NEW-BULKDELETE-01. The addon route
        // packages/workdo/BulkDelete/src/routes/web.php:17-18 is gated only by `auth` +
        // PlanModuleCheck, and its controller deletes by bare id over 61 models with no tenant
        // predicate. Registered HERE, on the `web` group, rather than on the route: the addon
        // registers its routes from `$this->app->booted()`
        // (app/Providers/PackageServiceProvider.php:20), i.e. after routes/web.php is read, so a
        // re-declaration there would lose route matching and the fix would silently not apply;
        // and bootstrap/app.php still executes under `route:cache`, where route files do not.
        // The guard is inert on every other route -- it returns early unless the MATCHED route is
        // that endpoint. See the class docblock for the full argument and the measured stack.
        $middleware->appendToGroup('web', \App\Http\Middleware\BulkDeleteTenantGuard::class);
        // P01-SEC-2. Order documents -- order details, receipts, shipping labels, print labels
        // and the invoice preview -- were readable across tenants. Nine of the seventeen guarded
        // uris were ANONYMOUS, declared in a ['web']-only group in a module route file
        // (AutomaticOrderPrinting :24-31, AuctionProduct :24-35, LotteryPrizes :32-58). The
        // subtle one is AutomaticOrderPrinting:31: it re-declares the SAME uri AND the SAME
        // controller action as the authenticated host declaration at routes/web.php:1178, so the
        // later declaration wins the method+uri slot and `Authenticate` silently disappears from
        // the stack while the action name stays identical -- nothing a reviewer comparing
        // controllers would see. Measured both ways in
        // tests/Feature/Security/OrderDocumentRouteResolutionTest.
        //
        // The other eight uris are host routes that DO carry a principal requirement -- four
        // admin routes behind `Authenticate`, two storefront routes behind
        // EnsureCustomerAuthenticated, plus the refund screen and a second uri on an
        // already-guarded handler -- and every one of them is live with no module enabled at all.
        // Their handlers load the order by id with no store predicate, and most check no
        // permission either. Authentication was never the whole gap. Four of the eight were found
        // by an adversarial audit AFTER this guard's first commit, because the first uri census
        // matched on uri WORDS and none of those four spells anything it looked for; the handler
        // census in OrderDocumentRouteResolutionTest replaces that reasoning.
        //
        // Registered HERE for the same two reasons as the guard above, and for one more: the
        // module route files are required from $this->app->booted()
        // (app/Providers/PackageServiceProvider.php:35), i.e. AFTER routes/web.php, so
        // re-declaring them there would simply lose the slot again. The guard is inert unless the
        // MATCHED route is one of seventeen named uris. See the class docblock for the rule, for
        // why a Policy is not the vehicle, and for the two disclosed behaviour changes.
        $middleware->appendToGroup('web', \App\Http\Middleware\OrderDocumentAccessGuard::class);
        // SEC-R1-02 / R2-07 / NEW-throttle-bypass: the RateLimiter named 'api'
        // is defined at app/Providers/RouteServiceProvider.php:27-29 but was
        // never applied to the 'api' middleware group, so it was dead code and
        // all 211 API route declarations were unthrottled. 120/min is a
        // deliberately generous starting value -- APILog and AdminApiLog
        // already instrument the traffic, so tune it down from real logs.
        $middleware->throttleApi('120,1');
        // Exclude specific routes from CSRF protection
        $middleware->validateCsrfTokens(
            except: [
                'iyzipay/callback/*',
                '/aamarpay/*',
                '*/aamarpay/callback?*',
                '*/get-payment-paytm',
                '*/get-payment-aamarpay?*',
                '*/get-payment-iyzico',
                'plan-get-phonepe-status',
                '*/store-payment-phonepe',
                '*/get-donation-payment-paytm',
                '*/get-donation-payment-iyzipay',
                '*/get-donation-payment-aamarpay',
                'plan-easebuzz-payment-notify*',
                '*get-payment-easebuzz*',
                '/login',
                '/duologin',
                'plan-get-Powertranz-status*',
                'store-get-Powertranz-status*',
                'campaigns-card-payment/',
                'campaigns-Powertranz',
                'plan-payfast-payment',
                '/plan/sslcommerz/status/*',
                '*/get-payment-sslcommerz',
                '*/checkout',
                '*/get-ticket-payment-paytm',
                '*/get-ticket-payment-aamarpay',
                'join_us/store/',
              'store/paymera/trigger/*',
              'delivery-syria/status',
            ] // Add your routes here
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();