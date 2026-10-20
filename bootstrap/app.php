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
        // P1-INV-14. Six ANONYMOUS admin mutations, declared in `['web']`-only groups in two
        // vendored module route files -- no `auth`, no `PlanModuleCheck`, no permission:
        //   LotteryPrizes/src/routes/web.php:56,59  (lottery-order-status-change/{id},
        //                                            lottery-order-return)  -> App\Models\Order
        //   AuctionProduct/src/routes/web.php:28,31-33 (update-auction-status,
        //                                            auction-order-assign,
        //                                            auction-order-status-change/{id},
        //                                            auction-order-return)
        // The two lottery routes are the worst of the six because they drive the HOST Order
        // model: `lottery-order-status-change` reaches the `cancel` limb that writes
        // payment_status = 'Unpaid', which fires OrderObserver -> OrderFinancialService, so an
        // anonymous caller could cancel any store's order AND walk back its finance record.
        //
        // Registered HERE for the same reasons as the two guards above -- the module route files
        // are required from $this->app->booted() (app/Providers/PackageServiceProvider.php:35),
        // i.e. AFTER routes/web.php, and RouteCollection keys on method+domain+uri, so a host
        // re-declaration would LOSE the slot to the module's; and bootstrap/app.php still
        // executes under `route:cache`, where route files do not. Editing the module is not an
        // option: packages/workdo/ is vendored and guard-no-workdo-changes.sh enforces it.
        //
        // FIVE OF THE SIX ARE LIVE; `auction-order-return` is LATENT and the difference is
        // stated rather than rounded up. Its handler iterates `product_json` as a list of order
        // lines, but the only writer of that column on an auction order
        // (AuctionProduct/src/app/Console/Auction.php:97) stores a single product OBJECT, so the
        // loop reads an offset off a scalar and the route 500s before any write, for every
        // principal. Guarding an anonymous entry point to a broken handler is still right;
        // calling it a live hole would not be. Raised as #61.
        //
        // The guard is inert unless the MATCHED route is one of those six uris. See the class
        // docblock for the uri census, for why each row id is read from the same request key the
        // handler reads -- the BODY, because Request::__get reads all() before route parameters,
        // and a key that is PRESENT decides even when it is unusable (an `id=5abc` that fell
        // through to the route segment was a live cross-tenant write, measured on MySQL) -- and
        // for why the permission limb fires only when a `permissions` row of that name exists.
        // That last point corrects an earlier claim here that the auction permissions are not
        // seeded: the MODULE ships its own PermissionTableSeeder and the enable path runs it.
        $middleware->appendToGroup('web', \App\Http\Middleware\ModuleOrderMutationGuard::class);
        // P1-INV-17. FOUR CROSS-TENANT WRITES BEHIND AuctionProduct's Route::resource routes,
        // and two of them are not auction tables at all: AuctionProductController::update:861
        // and destroy:1509 resolve App\Models\Product::find($id) -- the HOST catalogue model,
        // which carries NO effective global scope (absent from config/tenancy.php's
        // scoped_models) -- so any entitled merchant could edit or DELETE another store's
        // product, its images, and whatever products.category_id's ON DELETE CASCADE takes with
        // it. P1-INV-01 closed the same defect on the host ProductController; this is a separate
        // copy in vendored code it could not reach.
        //
        // AuctionProductSettingController::update:115 is the subtlest of the four: it writes
        // store_id = getCurrentStore(), so it does not merely modify another store's row -- it
        // MOVES it into the caller's tenant, where the victim can no longer see it and the
        // attacker can.
        //
        // These are NOT anonymous -- the module's resource group carries auth +
        // PlanModuleCheck:AuctionProduct -- so they need a plan carrying the module. Reproduced
        // by execution: four of the five cases wrote across tenants on the first run. The fifth,
        // the product UPDATE, writes NULL into NOT NULL columns from unposted fields, so SQLite
        // REFUSES it while production MySQL (config/database.php:55, strict => false) coerces and
        // the write lands -- the P1-INV-01 engine-hiding again, proven in a MySQL script rather
        // than claimed.
        //
        // Registered HERE for the same reasons as the three guards above. The guard is inert
        // unless the matched route's METHOD+uri is one of nine entries -- keyed on method+uri
        // because Route::resource puts GET, PUT, PATCH and DELETE on one uri and those are not
        // the same question. The id comes from the ROUTE PARAMETER only, the opposite of the
        // guard above, because these handlers take $id as a method argument.
        $middleware->appendToGroup('web', \App\Http\Middleware\ModuleResourceOwnershipGuard::class);
        // P1-INV-18. `POST lotteryresult` draws ANOTHER TENANT'S ticket codes.
        // LotteryResultController::store():66-193 has NO permission check (only index():32 checks
        // 'Manage Lottery Winner'), takes `selected_codes` from request input, resolves
        // PurchaseTicket::whereIn('lottery_code', ...) with NO store predicate, and then writes a
        // HOST App\Models\Order per ticket carrying the VICTIM'S product_id and ticket_price at
        // payment_status => 'Paid' with store_id => the ATTACKER'S store -- plus
        // LotteryCustomer::insert() rows with store_id => the VICTIM'S, which is the unusual
        // direction: planting rows INSIDE another tenant, past every global scope.
        //
        // REPRODUCED BY EXECUTION: a merchant in store 1 drew store 2's code and produced an
        // `orders` row with store_id 1, payment_status 'Paid' and a product_json whose embedded
        // store_id is 2.
        //
        // THE PREDICATE IS A SET, which is why this is a third guard and not an entry in either
        // sibling: the request names no row, only a comma-separated list of ticket CODES. Every
        // code must resolve to a purchase_tickets row in the caller's store or the WHOLE call is
        // refused -- filtering the list would authorise a subset while the handler re-read the
        // whole of it.
        //
        // NEITHER SIBLING'S CENSUS COULD HAVE FOUND THIS, and that was my own mistake: P1-INV-17's
        // scope was `update()` and `destroy()` only, and the census exemption for this very
        // resource read "stub: update() and destroy() are both //" -- true of those two methods and
        // falsely reassuring about the resource. See the guard docblock.
        $middleware->appendToGroup('web', \App\Http\Middleware\LotteryDrawOwnershipGuard::class);
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