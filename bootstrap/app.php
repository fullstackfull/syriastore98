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
        // P1-INV-20. Three more LotteryPrizes defects, each measured before anything was
        // written and each needing its own vehicle -- they share a module, not a mechanism.
        //
        //   1. `StoreLotteryPayment` writes a `purchase_tickets` row ANONYMOUSLY into whichever
        //      store the caller's URL slug names, with the caller's own `amount`. Measured: an
        //      unauthenticated GET to /{slug}/store-lottery-payment returned HTTP 200 and took
        //      purchase_tickets from 0 to 1 in the victim's store. Eight routes reach that one
        //      method from a ['web']-only group (routes/web.php:41-52) -- the same shape as the six
        //      in P1-INV-14.
        //
        //      THIS ONE IS NOT CLOSED, AND THE GUARD'S NAME SHOULD NOT SUGGEST OTHERWISE. An
        //      earlier version of this comment said requiring the session "refuses the attacker".
        //      It costs them one request. MEASURED: POST /{victim}/lottery-form-data (no auth on
        //      that route either) with the victim's product and payment_methods=telegram returns
        //      200 and leaves `lottery_request_data` in the caller's own session --
        //      processTelegram (LotteryPaymentService:1135-1140) is just
        //      `Session::put(...); return view(...)` -- after which GET
        //      /{victim}/store-lottery-payment returns 200 and writes the SAME row: victim's
        //      store, customer_id 0, the caller's amount.
        //
        //      What the guard does close is the single-request, validator-free variant: the
        //      `$request->all()` fallback at :567-569, the only path to the five gateway-callback
        //      routes, through which fields the form never collects can be supplied by hand. All
        //      34 `Session::put('lottery_request_data', ...)` sites in LotteryPaymentService
        //      populate the key on the real path, and the session-less path cannot serve a genuine
        //      cross-site callback anyway (the gateway's POST body has no `ticket_price` and :571
        //      reads one, so it 500s) -- so refusing it takes nothing from a legitimate caller and
        //      replaces their 500 with a message.
        //
        //      Closing it properly needs the gateway's own signature or a transaction lookup
        //      INSIDE the handler; no middleware can ask Paystack whether money moved, and
        //      requiring auth('customers') would break guest purchase, which is a real flow
        //      (:613 writes customer_id = 0 for it). #67 item 1 stays OPEN; the forgeable amount
        //      is #68.
        //      -> LotteryTicketWriteGuard
        //
        //   2. `refund_lottery_form_data` DELETES a ticket keyed on the posted code plus the
        //      customer id, with no store predicate -- `PurchaseTicket` is a Workdo model, absent
        //      from config/tenancy.php's `scoped_models`, so it adds no predicate for anyone.
        //      Measured: a refund posted on store A's form returned 302 "Refund sucessfully" and
        //      deleted store B's row. Reachable because `customers` is ONE session guard, not one
        //      per store, so a shopper's id ends up on rows in several stores. An unmatched code is
        //      separately a 500: `->delete()` on null.
        //      -> LotteryRefundOwnershipGuard
        //
        //   3. A LEGITIMATE own-store draw returns 500 AFTER writing, because
        //      LotteryResultController:157 does `$FailTemplate['id']` on null when the merchant has
        //      no 'Lottery Fail' template. Ordinary, not an edge case: the only code that creates
        //      that template is `EmailTemplateDefaultData()`, called from exactly one place -- the
        //      mail-settings screen at LotteryPrizesController:315 -- where sibling modules wire
        //      the same call into a ViewComposer that runs on every render (SpinToWinReward's
        //      ViewComposer:42). A merchant who never opens that screen has no template.
        //      -> LotteryMailTemplatePrerequisite
        //
        // All three are keyed on the CONTROLLER ACTION rather than the uri. P1-INV-17's census
        // missed live routes twice by keying on uri shape -- once on uri words, once on a prefix --
        // and item 1 alone spans eight uris, two with an optional {storeSlug?} segment.
        //
        // Registered on the `web` GROUP, not on the routes: module route files load from
        // `app->booted()`, after routes/web.php, and re-declaring a method+uri there replaces the
        // earlier declaration and silently drops its middleware. The group survives
        // `route:cache`, which stores declared middleware names unexpanded.
        //
        // NOT CLOSED, and recorded rather than implied: a caller who submits the lottery form
        // legitimately may still skip the gateway and call the return URL directly, so the ticket
        // is written unpaid -- that needs the gateway's own signature check, inside the handler.
        // `ticket_price` is also a client-posted hidden input (lottery-form.blade.php:277) that
        // lands in `purchase_tickets.amount`, so the RECORDED amount is forgeable even when the
        // charge is not. And `sendOwnerEmailTemplate:715` resolves the email template with no
        // `created_by`, which is cross-tenant: it is a static call from inside vendored
        // controllers, with no middleware, policy or scope seam between caller and callee, and the
        // only host-side lever -- a global scope on the shared App\Models\EmailTemplate -- would
        // reach every module's template reads and the operator's own management screens. Left to
        // upstream, stated, not quietly dropped.
        $middleware->appendToGroup('web', \App\Http\Middleware\LotteryTicketWriteGuard::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\LotteryRefundOwnershipGuard::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\LotteryMailTemplatePrerequisite::class);
        // P1-INV-22 item 5. `POST lotteryproduct` -> LotteryPrizesController::store():90-137 writes
        // `lottery_settings` rows whose `lottery_products` is a CALLER-SUPPLIED product id, with no
        // check that the product is the caller's. REPRODUCED BY EXECUTION: a merchant holding
        // `Edit Lottery Setting` posted two repeater rows, one naming their own product and one
        // naming store 2's, and both were written into store 1 with the caller's own ticket_price.
        // The row lands in the attacker's OWN tenant -- `:107` scopes the existing rows by
        // getCurrentStore() and `:114` writes that same store_id -- which is precisely why no
        // store_id predicate in the application notices. The cross-tenant part is the REFERENCE,
        // and it is financial: `lottery_form_data:535` resolves the product with `Product::find()`,
        // no store predicate and `Product` in no scoped_models list, then `:537-539` matches the
        // setting on (store_id, lottery_products) -- so the forged row makes the attacker's own
        // storefront sell tickets for the victim's product at the attacker's price, and the draw
        // awards the victim's product. The guard asks the one question store() never asks.
        //
        // IT DOES NOT PASS THE OPERATOR THROUGH, and this comment used to say it did. That was
        // wrong, and it was the most dangerous thing in the change: it described the guard's FIRST
        // version -- the one removal check R8 withdrew -- at the registration site a future reader
        // consults, so following it would have re-opened the gap R8 found. The three guards that do
        // pass the operator through are ModuleOrderMutationGuard, ModuleResourceOwnershipGuard and
        // LotteryDrawOwnershipGuard, and they are right to, because THEIR handlers let the operator
        // in. This one does not: `:92`'s isAbleTo has no operator limb, so the handler refuses a
        // bare super admin anyway (item 3, a vendored fail-closed defect). A pass-through would
        // therefore have changed nothing observable while leaving a hole for the moment item 3 is
        // fixed -- and that hole is reachable today, because a super admin GRANTED a role carrying
        // the permission does pass isAbleTo. The operator is subject to the ownership rule like
        // anyone else; LotterySettingProductOwnershipTest asserts it twice.
        $middleware->appendToGroup('web', \App\Http\Middleware\LotterySettingProductOwnershipGuard::class);
        // P1-INV-19. `POST /{storeSlug}/product-bid` writes ALL FOUR of its columns from request
        // input, so any customer with a session on ANY store can forge a bid attributed to another
        // customer on another store's product. AuctionProductController:1600-1603. The
        // authenticated principal is used only for a PRESENCE test at :1582 and then discarded;
        // $storeSlug is used only in the failure redirect; $product at :1585 is assigned and never
        // read. The route's gatherMiddleware() is literally ["web"].
        //
        // MEASURED -- attacker customer 1 in store 1, victim customer 2 in store 2:
        //   POST /store-a/product-bid  product_id=<store B's product>  bid_amount=999999
        //                              customer_id=<victim>  store_id=<attacker's choice>
        //   -> 302 "Your Bid place successfully."
        //      ROW product_id=2 customer_id=2 store_id=1 bid_amount=999999
        //      and store B's displayed maximum went 150 -> 999999, because :1568 and :1635 read
        //      max('bid_amount') keyed on product_id alone.
        //
        // THE FIX DERIVES RATHER THAN REFUSES, because the module's own form
        // (place_bid.blade.php:312-314) posts customer_id = the signed-in customer and
        // store_id = $product->store_id -- both values the server already holds. Middleware
        // rewrites them with $request->merge(), which the handler then reads through
        // Request::__get -> all(). It also enforces the bid rule the module only ever enforced in
        // the browser: an HTML `min` and a JS border recolour that does not block submit, with a
        // controller validator asking only `required`.
        //
        // IT DOES NOT RESTRICT WHICH STORE'S PRODUCT MAY BE BID ON, deliberately. `customers` is
        // one session guard and storefronts are public, so a customer of store A really can open
        // store B's auction page -- the form renders $product->store_id for exactly that reason. A
        // slug-versus-store check would add nothing (the attacker can already see the product) and
        // would refuse a real flow. That is the mistake the guard above shipped and had to
        // withdraw; it is not repeated here.
        //
        // NOT CLOSED: rows already written before this guard. A forged bid in the table still
        // counts toward max('bid_amount'), which has no store predicate in the vendored reads. A
        // reconciliation pass belongs with the P1-OWN-07 data-residue work, not here.
        $middleware->appendToGroup('web', \App\Http\Middleware\AuctionBidIdentityGuard::class);
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
                // P1-SEC-01 item D. `'/login'` WAS HERE AND IS DELETED. It made `POST /login`
                // -- the MERCHANT AND OPERATOR login -- exempt from CSRF, which is login CSRF:
                // an attacker can log a victim into the ATTACKER'S account and then observe or
                // capture whatever the victim does in it. `inExceptArray()` trims the slashes, so
                // the leading one did not make the entry dead.
                //
                // MEASURED BOTH WAYS, by two independent agents on a patched tree: with the entry
                // present, `POST /login` with NO token succeeds; with it deleted, the full real
                // flow still works -- GET /login, scrape the 40-char `_token`, POST -> 302
                // /dashboard, GET /dashboard -> 200 rendering the user's name. The form already
                // emits `@csrf` (resources/views/auth/login.blade.php:38), so the token was always
                // being sent and always being ignored. Nothing depended on the exemption.
                //
                // `'/duologin'` is LEFT ALONE deliberately: it is a different route with a
                // different caller, and nothing in this item measured it.
                //
                // The acceptance criterion as filed asked that the exemption "not be satisfied by
                // a prefixed or localised path". That half was already true and is not the defect:
                // `inExceptArray` matches with `Str::is`, which honours only `*`, so `ar/login` and
                // `{storeSlug}/login` never matched. The defect was the bare path itself.
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