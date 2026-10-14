<?php

use Illuminate\Support\Facades\Route;
use Workdo\LynomiaChat\app\Http\Controllers\AutomationApiController;
use Workdo\LynomiaChat\app\Http\Controllers\AutomationController;
use Workdo\LynomiaChat\app\Http\Controllers\CommunicationCenterController;
use Workdo\LynomiaChat\app\Http\Controllers\ConnectionController;
use Workdo\LynomiaChat\app\Http\Controllers\CommerceApiController;
use Workdo\LynomiaChat\app\Http\Controllers\EventIngressController;
use Workdo\LynomiaChat\app\Http\Middleware\GuardEventIngress;
use Workdo\LynomiaChat\app\Http\Controllers\InboxApiController;
use Workdo\LynomiaChat\app\Http\Controllers\InboxController;
use Workdo\LynomiaChat\app\Http\Controllers\SuperAdmin\IntegrationController;
use Workdo\LynomiaChat\app\Http\Middleware\EnsureStoreOwnsLink;

/**
 * Lynomia Chat module routes.
 *
 * THE MIDDLEWARE STACK IS WRITTEN OUT HERE RATHER THAN INHERITED. The retired Chatwoot
 * integration declared its routes at the top level of routes/web.php, outside every group:
 * two were reachable with no session at all and seven carried a bare `auth` with no `xss`,
 * no `verified` and no permission check. Declaring the stack explicitly on each group, in
 * the module that owns the routes, makes that failure mode visible in review instead of
 * depending on where a brace happens to sit in a 1,100-line file.
 *
 * NO MODULE RouteServiceProvider IS REGISTERED. The Workdo template ships one that maps the
 * same files a second time with the `web` group. Identical method+URI registrations collapse
 * in Laravel's RouteCollection, but this project has already been bitten by duplicate
 * registration making route-middleware reasoning unreliable
 * (docs/development/phase-1/02-P0-SECURITY-REVALIDATION.md §5.10). One registration, from
 * LynomiaChatServiceProvider::boot(), is easier to reason about and to assert on.
 *
 * FIVE LAYERS GUARD EVERY MERCHANT ROUTE, and they answer five different questions:
 *   auth/xss/setlocate/verified   is there a verified session
 *   PlanModuleCheck:LynomiaChat   ENTITLEMENT -- does this tenant's PLAN include the module
 *   permission:Manage Lynomia…    AUTHORIZATION -- may THIS user, not just this tenant, act
 *   EnsureStoreOwnsLink           TENANCY -- is the current store this tenant's
 *   (no route parameter)          there is no id to tamper with, so no IDOR surface
 *
 * THE PERMISSION LAYER IS NOT REDUNDANT WITH THE OTHER TWO, which is exactly how it came to
 * be missing. PlanModuleCheck resolves the module list of the tenant OWNER, so every staff
 * member inherits the owner's entitlement and passes it. EnsureStoreOwnsLink resolves staff
 * to their owning admin through creatorId() -- deliberately, so a merchant's staff reach the
 * merchant's store -- so they match the owner's link too. Neither is a role check, and the
 * module's menu entry was gated on a permission that nothing server-side enforced: hiding a
 * menu item is not authorization, the staff member types the path.
 *
 * What that cost, before this line existed: any staff user in the tenant -- a warehouse
 * packer with an empty role -- could GET /lynomia/open and be redirected into the merchant's
 * workspace signed in as its ADMINISTRATOR (AccountProvisioner attaches the service user with
 * that role), with full read/write over every customer conversation, contact and channel.
 * They could also consume the tenant's single provisioning slot and read a claim code.
 *
 * Laratrust's own middleware rather than a hand-rolled one: it is already a dependency, and
 * `laratrust.middleware.handling` is 'abort' => 403 here, which is the right answer for a
 * user who is legitimately signed in and simply may not do this.
 */

/**
 * `web` IS LISTED EXPLICITLY, AND IT HAS TO BE.
 *
 * These routes are registered through loadRoutesFrom() in the module's service provider,
 * which -- unlike the application's own RouteServiceProvider -- applies NO middleware group.
 * Without `web` there is no StartSession, so `auth` can never resolve a session user, and no
 * ValidateCsrfToken, so every POST below would be unprotected. Measured on the booted router
 * before this was added: the stack was Authenticate,XSS,SetLocate,EmailIsVerified with no
 * session and no CSRF middleware at all.
 */
/**
 * WHERE LYNOMIA TELLS US SOMETHING CHANGED.
 *
 * OUTSIDE THE AUTHENTICATED GROUP, AND THAT IS THE POINT RATHER THAN AN OVERSIGHT. This is called
 * server to server by Lynomia, which has no Store Syria session and never will, so `auth` would
 * refuse every legitimate delivery. What authenticates it is the per-store HMAC signature
 * Lynomia already sends on every webhook, verified in EventIngressController before anything
 * else happens.
 *
 * `web` is omitted deliberately too: it would add CSRF protection to a request that has no
 * browser, no session and no token to carry, and the delivery would be rejected for lacking
 * something it could not have.
 *
 * The route carries a store id, which is NOT trusted: each store's deliveries are signed with
 * that store's own derived key, and the account id in the body is checked against the link that
 * store actually holds.
 */
// NOT `->middleware('api')`, which is how this started. That group applies the platform-wide
// `throttle:120,1` -- a single IP-keyed bucket shared by every store AND by the two hundred-odd
// routes/api.php endpoints. Lynomia sends every store's deliveries from one egress address, so
// one merchant's burst silently destroyed other merchants' events: a rejected account webhook is
// never retried upstream. The group also brought SubstituteBindings, which this route cannot use
// -- `store` is a bare integer deliberately, so there is no model to bind and nothing to tamper
// with. See LynomiaChatServiceProvider::registerIngressLimiter.
Route::post('/lynomia/events/{store}', [EventIngressController::class, 'store'])
    ->whereNumber('store')
    // ORDER MATTERS. The per-IP limit is first and is the only one an unverified caller can
    // spend. GuardEventIngress then caps the body and checks the HMAC. Only a verified delivery
    // reaches the per-store budget, so no anonymous caller can exhaust a merchant's allowance
    // and destroy their events.
    ->middleware(['throttle:lynomia-ingress', GuardEventIngress::class, 'throttle:lynomia-events'])
    ->name('lynomia.events.ingress');

// ---------------------------------------------------------------- commerce API (Phase 3)
//
// A SURFACE OF ITS OWN, and not part of the `api` group. That group carries the platform-wide
// throttle:120,1 keyed on the caller IP -- the same shared bucket that was silently destroying
// webhook deliveries before A1 -- and Lynomia's adapter calls from one address on behalf of
// every connected store. It also brings SubstituteBindings, which this surface cannot use: there
// is no store id in any of these paths to bind, because the CREDENTIAL names the store.
//
// No `web` either: no session, no CSRF, no cookies. This is server to server.
Route::prefix('api/lynomia/commerce/v1')
    ->middleware([
        'throttle:lynomia-commerce',
        \Workdo\LynomiaChat\app\Http\Middleware\AuthenticateCommerceApi::class,
    ])
    ->name('lynomia.commerce.v1.')
    ->group(function () {
        Route::get('/store', [CommerceApiController::class, 'store'])->name('store');

        // Customers are found by ONE verified identifier, never by name. The {customer} segment
        // below is a reference this surface issued, not a tenant: the credential already decided
        // which store answers, so there is nothing here that widens what a caller can read.
        Route::post('/customers/lookup', [CommerceApiController::class, 'lookupCustomers'])->name('customers.lookup');
        Route::get('/customers/{customer}/orders', [CommerceApiController::class, 'customerOrders'])->name('customers.orders');

        Route::get('/orders', [CommerceApiController::class, 'orders'])->name('orders.search');
        Route::get('/orders/{order}', [CommerceApiController::class, 'order'])->name('orders.show');

        Route::get('/carts/abandoned', [CommerceApiController::class, 'abandonedCarts'])->name('carts.abandoned');
    });

Route::middleware(['web', 'auth', 'xss', 'setlocate', 'verified'])->group(function () {

    // ---------------------------------------------------------------- merchant
    Route::middleware([
        'PlanModuleCheck:LynomiaChat',
        \Laratrust\Middleware\Permission::class . ':Manage Lynomia Chat',
    ])
        ->prefix('lynomia')
        ->name('lynomia.')
        ->group(function () {

            Route::get('/', [CommunicationCenterController::class, 'index'])
                ->middleware(EnsureStoreOwnsLink::class)
                ->name('overview');

            Route::post('/connect/provision', [ConnectionController::class, 'provision'])
                ->middleware(EnsureStoreOwnsLink::class)
                ->name('connect.provision');

            Route::post('/connect/link', [ConnectionController::class, 'requestLink'])
                ->middleware(EnsureStoreOwnsLink::class)
                ->name('connect.link');

            // `existing` makes the middleware refuse before the controller runs when the
            // store has no link at all, so "open my workspace" can never reach the SSO
            // issuer with a null link.
            Route::get('/open', [ConnectionController::class, 'openWorkspace'])
                ->middleware(EnsureStoreOwnsLink::class . ':existing')
                ->name('open');

            // ------------------------------------------------------------ the inbox
            //
            // The page itself needs only the module permission; everything it can DO is gated
            // per action below, because reading what a customer asked and answering them in
            // the store's name are different decisions a merchant should be able to make
            // separately. A staff member who may look is not thereby allowed to reply.
            Route::get('/inbox', [InboxController::class, 'index'])
                ->middleware(EnsureStoreOwnsLink::class . ':existing')
                ->name('inbox');

            // The browser's only door to Lynomia. Every route carries `:existing`, so a store
            // with no link is refused by the middleware before any controller runs.
            Route::prefix('inbox/api')->name('inbox.api.')->group(function () {
                Route::middleware(EnsureStoreOwnsLink::class . ':existing')->group(function () {
                    Route::get('/conversations', [InboxApiController::class, 'conversations'])->name('conversations');
                    Route::get('/conversations/{conversation}/messages', [InboxApiController::class, 'messages'])
                        ->whereNumber('conversation')->name('messages');
                    Route::get('/agents', [InboxApiController::class, 'agents'])->name('agents');
                    // What changed since the caller last looked. Carries ids, never content.
                    Route::get('/events', [InboxApiController::class, 'events'])->name('events');
                    Route::get('/contacts/{contact}', [InboxApiController::class, 'contact'])
                        ->whereNumber('contact')->name('contact');

                    // The conversation's commerce context. Read-only, and carried by the
                    // agent's own Lynomia credential, so it is visible to exactly the people
                    // the conversation itself is.
                    Route::get('/conversations/{conversation}/commerce', [InboxApiController::class, 'commerce'])
                        ->whereNumber('conversation')->name('commerce');
                    Route::post('/conversations/{conversation}/read', [InboxApiController::class, 'markRead'])
                        ->whereNumber('conversation')->name('read');

                    // Writing to a customer is its own permission.
                    Route::post('/conversations/{conversation}/reply', [InboxApiController::class, 'reply'])
                        ->middleware(\Laratrust\Middleware\Permission::class . ':Reply Lynomia Chat')
                        ->whereNumber('conversation')->name('reply');

                    // Moving work between colleagues, and declaring it done, is another.
                    Route::post('/conversations/{conversation}/assign', [InboxApiController::class, 'assign'])
                        ->middleware(\Laratrust\Middleware\Permission::class . ':Assign Lynomia Chat')
                        ->whereNumber('conversation')->name('assign');
                    Route::post('/conversations/{conversation}/status', [InboxApiController::class, 'updateStatus'])
                        ->middleware(\Laratrust\Middleware\Permission::class . ':Assign Lynomia Chat')
                        ->whereNumber('conversation')->name('status');
                });
            });

            // ------------------------------------------------------------ Automation Studio
            //
            // ITS OWN PERMISSION, ON TOP OF THE MODULE'S. A flow decides what every customer of
            // the store is told, automatically, with nobody reading it first -- which is a
            // different decision from being allowed to read the inbox, and a merchant must be
            // able to grant one without the other.
            //
            // THE PERMISSION IS NOT THE AUTHORIZATION, AND IS NOT CLAIMED TO BE. Lynomia
            // authorizes every flows action with AgentBotPolicy#update? -- workspace
            // administrator -- and only the store owner holds that identity. So this layer can
            // narrow who reaches the Studio within the merchant's own staff; it can never widen
            // it, and a staff member who gets past it is still refused by Lynomia. Both are
            // asserted in tests, because a permission that only hides a menu entry is the
            // mistake this module already made once.
            Route::middleware([
                EnsureStoreOwnsLink::class . ':existing',
                \Laratrust\Middleware\Permission::class . ':Manage Lynomia Automation',
                // THE FIRST SURFACE THAT AMPLIFIES. An inbox request is roughly one Lynomia
                // call; opening the builder is the flow AND four reference reads AND the
                // condition vocabulary. Keyed on the PERSON, because the cost is per credential
                // minted -- keying on the store would let one person's stuck poll exhaust their
                // colleagues' budget. See LynomiaChatServiceProvider::registerIngressLimiter.
                'throttle:lynomia-studio',
            ])->group(function () {
                Route::get('/automation', [AutomationController::class, 'index'])->name('automation');

                // Commerce automation and consent: one page, two tabs, because they are the two
                // halves of one question -- what the store sends on its own, and whom it is
                // allowed to send it to.
                Route::get('/automation/commerce', [AutomationController::class, 'commerce'])
                    ->name('automation.commerce');

                // The visual builder. A page of its own rather than a panel on the list, because it
                // owns the whole viewport and has its own unsaved state -- navigating away from a
                // modal by accident is how a merchant loses a flow they were drawing.
                Route::get('/automation/{flow}/builder', [AutomationController::class, 'builder'])
                    ->whereNumber('flow')->name('automation.builder');

                Route::prefix('automation/api')->name('automation.api.')->group(function () {
                    Route::get('/flows', [AutomationApiController::class, 'index'])->name('flows');
                    Route::post('/flows', [AutomationApiController::class, 'store'])->name('flows.store');
                    Route::get('/flows/{flow}', [AutomationApiController::class, 'show'])
                        ->whereNumber('flow')->name('flows.show');
                    Route::patch('/flows/{flow}', [AutomationApiController::class, 'update'])
                        ->whereNumber('flow')->name('flows.update');
                    Route::delete('/flows/{flow}', [AutomationApiController::class, 'destroy'])
                        ->whereNumber('flow')->name('flows.destroy');
                    Route::post('/flows/{flow}/duplicate', [AutomationApiController::class, 'duplicate'])
                        ->whereNumber('flow')->name('flows.duplicate');
                    Route::post('/flows/{flow}/publish', [AutomationApiController::class, 'publish'])
                        ->whereNumber('flow')->name('flows.publish');
                    Route::post('/flows/{flow}/disable', [AutomationApiController::class, 'disable'])
                        ->whereNumber('flow')->name('flows.disable');

                    // ---------------------------------------------------- the builder
                    //
                    // `references` carries no flow id: the labels, teams, agents, attributes and
                    // condition vocabulary are the WORKSPACE's, and the credential already decided
                    // which workspace that is.
                    Route::get('/references', [AutomationApiController::class, 'references'])->name('references');

                    // Which channels can run an automation, and connecting one to a channel.
                    // Disconnecting is keyed on the INBOX rather than the flow, because one
                    // inbox runs one bot: "clear this channel" is the operation, and naming a
                    // flow as well would invite a request that disconnects a different one.
                    Route::get('/channels', [AutomationApiController::class, 'channels'])->name('channels');
                    Route::post('/flows/{flow}/channels', [AutomationApiController::class, 'connectChannel'])
                        ->whereNumber('flow')->name('flows.channels.connect');
                    Route::delete('/channels/{inbox}', [AutomationApiController::class, 'disconnectChannel'])
                        ->whereNumber('inbox')->name('channels.disconnect');
                    Route::get('/flows/{flow}/builder', [AutomationApiController::class, 'builder'])
                        ->whereNumber('flow')->name('flows.builder');
                    Route::put('/flows/{flow}/draft', [AutomationApiController::class, 'draft'])
                        ->whereNumber('flow')->name('flows.draft');
                    // Test Mode runs the DRAFT and leaves nothing behind; it is still a write verb
                    // because it is not a cacheable read and must carry the CSRF token.
                    Route::post('/flows/{flow}/simulate', [AutomationApiController::class, 'simulate'])
                        ->whereNumber('flow')->name('flows.simulate');

                    // Starting points, and how a flow is actually running.
                    Route::get('/starters', [AutomationApiController::class, 'starters'])->name('starters');
                    Route::get('/flows/{flow}/history', [AutomationApiController::class, 'history'])
                        ->whereNumber('flow')->name('flows.history');

                    // ---------------------------------------------------- commerce automation
                    //
                    // RULES ARE NOT FLOWS and are a different model upstream: a flow answers a
                    // conversation the customer started, a rule reacts to something that
                    // happened. The Commerce triggers this integration exists for are rules.
                    Route::get('/rules', [AutomationApiController::class, 'rules'])->name('rules');
                    Route::post('/rules', [AutomationApiController::class, 'storeRule'])->name('rules.store');
                    Route::patch('/rules/{rule}', [AutomationApiController::class, 'updateRule'])
                        ->whereNumber('rule')->name('rules.update');
                    Route::delete('/rules/{rule}', [AutomationApiController::class, 'destroyRule'])
                        ->whereNumber('rule')->name('rules.destroy');

                    // ---------------------------------------------------- consent
                    //
                    // Recording what a shopper decided changes whether they are messaged at all,
                    // which is a heavier act than editing an automation -- so it carries its own
                    // permission on top of the Studio's.
                    //
                    // The contact search is declared BEFORE the numeric {contact} routes so that
                    // /consents/contacts is read as a path rather than as a contact whose id
                    // happens to be a word. whereNumber would refuse it anyway; relying on that
                    // alone would make the ordering look accidental.
                    Route::middleware(\Laratrust\Middleware\Permission::class . ':Manage Lynomia Consent')
                        ->group(function () {
                            Route::get('/consents', [AutomationApiController::class, 'consents'])->name('consents');
                            Route::get('/consents/contacts', [AutomationApiController::class, 'searchContacts'])
                                ->name('consents.search');
                            Route::get('/consents/{contact}', [AutomationApiController::class, 'contactConsent'])
                                ->whereNumber('contact')->name('consents.show');
                            Route::put('/consents/{contact}', [AutomationApiController::class, 'recordConsent'])
                                ->whereNumber('contact')->name('consents.record');
                            Route::delete('/consents/{contact}', [AutomationApiController::class, 'clearConsentSuppression'])
                                ->whereNumber('contact')->name('consents.clear');
                        });
                });
            });
        });

    // ---------------------------------------------------------------- super admin
    //
    // PlatformOperatorOnly is the same control the plan-order rail uses. Approving a
    // workspace claim grants access to a merchant's communication data, so it is an operator
    // act, not a permission a merchant role could be granted.
    Route::middleware([\App\Http\Middleware\PlatformOperatorOnly::class])
        ->prefix('super-admin/lynomia-integration')
        ->name('lynomia.admin.')
        ->group(function () {

            Route::get('/', [IntegrationController::class, 'show'])->name('index');

            Route::post('/links/{link}/approve', [IntegrationController::class, 'approve'])
                ->whereNumber('link')
                ->name('links.approve');

            Route::post('/links/{link}/revoke', [IntegrationController::class, 'revoke'])
                ->whereNumber('link')
                ->name('links.revoke');

            Route::post('/links/{link}/restore', [IntegrationController::class, 'restore'])
                ->whereNumber('link')
                ->name('links.restore');

            // Included agent seats per plan. Same PlatformOperatorOnly group as the link
            // actions above -- a seat ceiling is a commercial control and belongs behind the
            // same door, not on the core plan form where it would have two owners.
            Route::post('/seats', [IntegrationController::class, 'updateSeats'])
                ->name('seats.update');

            // The commerce credential. Same operator-only door as the link actions: it grants a
            // machine read access to a merchant's orders and customers, which is an operator
            // act, not a permission a merchant role could carry.
            Route::post('/links/{link}/commerce-credential', [IntegrationController::class, 'issueCommerceCredential'])
                ->whereNumber('link')
                ->name('commerce.issue');

            Route::delete('/links/{link}/commerce-credential', [IntegrationController::class, 'revokeCommerceCredential'])
                ->whereNumber('link')
                ->name('commerce.revoke');
        });
});
