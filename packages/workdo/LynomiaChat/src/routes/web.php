<?php

use Illuminate\Support\Facades\Route;
use Workdo\LynomiaChat\app\Http\Controllers\AudienceApiController;
use Workdo\LynomiaChat\app\Http\Controllers\CampaignApiController;
use Workdo\LynomiaChat\app\Http\Controllers\CampaignController;
use Workdo\LynomiaChat\app\Http\Controllers\AudienceController;
use Workdo\LynomiaChat\app\Http\Controllers\AutomationApiController;
use Workdo\LynomiaChat\app\Http\Controllers\AutomationController;
use Workdo\LynomiaChat\app\Http\Controllers\CommunicationCenterController;
use Workdo\LynomiaChat\app\Http\Controllers\ContactVerificationController;
use Workdo\LynomiaChat\app\Http\Controllers\PlatformEventIngressController;
use Workdo\LynomiaChat\app\Http\Controllers\ConnectionController;
use Workdo\LynomiaChat\app\Http\Controllers\CommerceApiController;
use Workdo\LynomiaChat\app\Http\Controllers\CommerceConnectionController;
use Workdo\LynomiaChat\app\Http\Controllers\EventIngressController;
use Workdo\LynomiaChat\app\Http\Middleware\GuardEventIngress;
use Workdo\LynomiaChat\app\Http\Controllers\InboxApiController;
use Workdo\LynomiaChat\app\Http\Controllers\InboxController;
use Workdo\LynomiaChat\app\Http\Controllers\StoreConsentController;
use Workdo\LynomiaChat\app\Http\Controllers\TemplateApiController;
use Workdo\LynomiaChat\app\Http\Controllers\TemplateController;
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

// WHAT BECAME OF THE NOTICES THIS INSTALLATION SENT ITS OWN MERCHANTS.
//
// A second ingress, and not the one above, because that one is keyed on a store: its secret is
// derived from the store id and it refuses any delivery whose account is not that store's. The
// platform workspace is the installation's own account and has no store, so there is nothing to
// key on -- and deriving its secret from some merchant's id would be a merchant who can forge
// the platform's deliveries.
//
// SAME STACK, SAME ORDER, SAME REASONS. The per-IP limit first, because it is the only one an
// unverified caller can spend; then the guard, in its platform scope, for the size cap and the
// HMAC; then the platform's own budget, which only a verified delivery reaches.
Route::post('/lynomia/platform-events', [PlatformEventIngressController::class, 'store'])
    ->middleware([
        'throttle:lynomia-ingress',
        GuardEventIngress::class . ':' . \Workdo\LynomiaChat\app\Services\EventSignature::PLATFORM_SCOPE,
        'throttle:lynomia-platform-events',
    ])
    ->name('lynomia.platform.events.ingress');

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
    // ORDER MATTERS, THE SAME WAY IT MATTERS ON THE EVENTS INGRESS ABOVE. The per-IP ceiling is
    // first and is the only one an unverified caller can spend. AuthenticateCommerceApi then
    // verifies the `lsc_` credential and stamps the store id it resolved from it. Only a
    // verified request reaches the per-store budget, so no anonymous caller can exhaust a
    // merchant's allowance -- and, because the stamp comes from the credential rather than from
    // anything in the request, no merchant can spend another merchant's.
    //
    // This group previously carried only two of the three, with the throttle FIRST: the limiter
    // read a store id the middleware had not stamped yet, so every merchant shared one IP-keyed
    // bucket. See LynomiaChatServiceProvider::registerIngressLimiter.
    ->middleware([
        'throttle:lynomia-commerce-ip',
        \Workdo\LynomiaChat\app\Http\Middleware\AuthenticateCommerceApi::class,
        'throttle:lynomia-commerce',
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

            // B4. A merchant proving they hold the number the platform would reach them on.
            //
            // INSIDE the merchant group, so the full stack applies: session and CSRF from
            // `web`, `auth`, the module entitlement and the Lynomia permission. Neither action
            // takes a user id or a store id -- both act on the authenticated merchant and the
            // store they are currently in, so there is no parameter to tamper with.
            //
            // Throttled on top of all that, because `send` makes somebody's phone ring: the
            // service's own cooldown stops a merchant hammering their own number, and this
            // stops a session hammering a succession of them.
            Route::post('/contact/verify/send', [ContactVerificationController::class, 'send'])
                ->middleware('throttle:lynomia-contact-verify')
                ->name('contact.verify.send');
            Route::post('/contact/verify/confirm', [ContactVerificationController::class, 'confirm'])
                ->middleware('throttle:lynomia-contact-verify')
                ->name('contact.verify.confirm');

            Route::post('/connect/provision', [ConnectionController::class, 'provision'])
                ->middleware(EnsureStoreOwnsLink::class)
                ->name('connect.provision');

            Route::post('/connect/link', [ConnectionController::class, 'requestLink'])
                ->middleware(EnsureStoreOwnsLink::class)
                ->name('connect.link');

            // RECOVERING A GRANT THE MERCHANT REMOVED ON THE LYNOMIA SIDE. `existing`, because
            // there is nothing to recover without a link, and the controller reads the account
            // from that row rather than from anything the request carries -- the code proves who
            // the merchant is, the row decides which workspace they are allowed to prove it for.
            Route::post('/connect/recover', [ConnectionController::class, 'recoverAuthorization'])
                ->middleware(EnsureStoreOwnsLink::class . ':existing')
                ->name('connect.recover');

            // ------------------------------------------------- commerce activation
            //
            // FINISHING THE JOB, WITHOUT A TOKEN CHANGING HANDS. Connecting a workspace and
            // connecting the store's ORDERS are two different things on two different
            // systems, and until now only the first was something a merchant could do for
            // themselves: the second needed an operator to mint a commerce credential and a
            // human to paste it into Lynomia. These two routes are the merchant's half of
            // that, and they accept nothing -- no store id, no account id, no token -- for
            // the same reason the connect routes above accept nothing.
            //
            // `existing` because there is nothing to connect commerce TO without a link, and
            // the middleware refusing is better than the controller discovering it.
            Route::post('/commerce/connect', [CommerceConnectionController::class, 'connect'])
                ->middleware(EnsureStoreOwnsLink::class . ':existing')
                ->name('commerce.connect');

            Route::post('/commerce/verify', [CommerceConnectionController::class, 'verify'])
                ->middleware(EnsureStoreOwnsLink::class . ':existing')
                ->name('commerce.verify');

            // POST rather than DELETE, like every other state-changing merchant action in this
            // module, so one Blade form with @csrf is all it takes and no route needs a method
            // spoof field to work.
            Route::post('/commerce/disconnect', [CommerceConnectionController::class, 'disconnect'])
                ->middleware(EnsureStoreOwnsLink::class . ':existing')
                ->name('commerce.disconnect');

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

            // ------------------------------------------------------------ Audiences
            //
            // ITS OWN PERMISSION, BECAUSE AN AUDIENCE DECIDES WHO GETS MESSAGED. It is a
            // narrower decision than building an automation and a different one from reading
            // the inbox: a merchant can reasonably let a marketer define segments without
            // letting them publish a flow, and can reasonably let support read conversations
            // without letting them build the list a campaign sends to.
            //
            // THE PERMISSION IS NOT THE AUTHORIZATION, AND IS NOT CLAIMED TO BE. Lynomia
            // treats sharing an audience as a workspace-administrator act and only the store
            // owner holds that identity, so this layer narrows WITHIN the merchant's staff and
            // can never widen; a staff member who gets past it is refused upstream. Both are
            // asserted in tests, because a permission that only hides a menu entry is the
            // mistake this module already made once.
            Route::middleware([
                EnsureStoreOwnsLink::class . ':existing',
                \Laratrust\Middleware\Permission::class . ':Manage Lynomia Audiences',
                // Keyed on the PERSON, like the Studio: the cost is per credential minted, and
                // keying on the store would let one person's stuck poll exhaust their
                // colleagues' budget.
                'throttle:lynomia-studio',
            ])->group(function () {
                Route::get('/audiences', [AudienceController::class, 'index'])->name('audiences');

                Route::prefix('audiences/api')->name('audiences.api.')->group(function () {
                    Route::get('/', [AudienceApiController::class, 'index'])->name('index');
                    // No audience id: the stores, currencies and gaps are the WORKSPACE's, and
                    // the credential already decided which workspace that is.
                    Route::get('/references', [AudienceApiController::class, 'references'])->name('references');
                    Route::post('/', [AudienceApiController::class, 'store'])->name('store');
                    Route::patch('/{audience}', [AudienceApiController::class, 'update'])
                        ->whereNumber('audience')->name('update');
                    Route::delete('/{audience}', [AudienceApiController::class, 'destroy'])
                        ->whereNumber('audience')->name('destroy');
                    // A count, never a list: the people in an audience are Lynomia's contacts
                    // and this surface has no business enumerating them.
                    Route::post('/preview', [AudienceApiController::class, 'preview'])->name('preview');
                });
            });

            // ------------------------------------------------------------ Campaigns
            //
            // THE HEAVIEST PERMISSION OF THE MARKETING FOUR, and the reason it is its own.
            // An audience says who could be reached and a template says what the words are;
            // a campaign is the act of SENDING those words to those people, and it cannot be
            // taken back once the first message leaves. A merchant can reasonably let a
            // marketer draft audiences and templates while reserving the send.
            //
            // IT NARROWS, IT NEVER WIDENS. Upstream authorizes campaign writes with its own
            // policy; a staff member who passes this layer is still answerable to that.
            Route::middleware([
                EnsureStoreOwnsLink::class . ':existing',
                \Laratrust\Middleware\Permission::class . ':Manage Lynomia Campaigns',
                'throttle:lynomia-studio',
            ])->group(function () {
                Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns');

                Route::prefix('campaigns/api')->name('campaigns.api.')->group(function () {
                    Route::get('/', [CampaignApiController::class, 'index'])->name('index');
                    Route::post('/', [CampaignApiController::class, 'store'])->name('store');
                    // No campaign id: the preview answers "how many would THIS audience
                    // reach", which is asked while a campaign is still being drafted.
                    Route::post('/preview', [CampaignApiController::class, 'preview'])->name('preview');
                    Route::patch('/{campaign}', [CampaignApiController::class, 'update'])
                        ->whereNumber('campaign')->name('update');
                    // A POST, not a DELETE: cancelling KEEPS the campaign and its recipient
                    // snapshot, which is what an operator stopping a half-sent campaign
                    // needs to read afterwards.
                    Route::post('/{campaign}/cancel', [CampaignApiController::class, 'cancel'])
                        ->whereNumber('campaign')->name('cancel');
                    Route::delete('/{campaign}', [CampaignApiController::class, 'destroy'])
                        ->whereNumber('campaign')->name('destroy');
                    Route::get('/{campaign}/results', [CampaignApiController::class, 'results'])
                        ->whereNumber('campaign')->name('results');
                    // What is left of this period's allowance, read from the side that
                    // enforces it. No campaign id: it is the store's number, not one
                    // campaign's.
                    Route::get('/allowance', [CampaignApiController::class, 'allowance'])->name('allowance');
                });
            });

            // ------------------------------------------------------------ Message templates
            //
            // ITS OWN PERMISSION. A template is the text Meta approves and every campaign and
            // automation then sends; editing one changes what thousands of customers read,
            // and submitting one spends a review cycle the merchant cannot take back. That is
            // a different decision from defining an audience and from answering a
            // conversation.
            //
            // IT NARROWS, IT NEVER WIDENS. Upstream requires an account ADMINISTRATOR for
            // every template action and only the store owner holds that identity, so a staff
            // member who passes this layer is still refused there. Both are asserted in tests.
            Route::middleware([
                EnsureStoreOwnsLink::class . ':existing',
                \Laratrust\Middleware\Permission::class . ':Manage Lynomia Templates',
                'throttle:lynomia-studio',
            ])->group(function () {
                Route::get('/templates', [TemplateController::class, 'index'])->name('templates');

                Route::prefix('templates/api')->name('templates.api.')->group(function () {
                    Route::get('/', [TemplateApiController::class, 'index'])->name('index');
                    Route::post('/', [TemplateApiController::class, 'store'])->name('store');
                    Route::patch('/{template}', [TemplateApiController::class, 'update'])
                        ->whereNumber('template')->name('update');
                    Route::delete('/{template}', [TemplateApiController::class, 'destroy'])
                        ->whereNumber('template')->name('destroy');
                    // Handing a draft to Meta. A write verb because it is irreversible: a
                    // review cycle spent cannot be taken back.
                    Route::post('/{template}/submit', [TemplateApiController::class, 'submit'])
                        ->whereNumber('template')->name('submit');
                    Route::post('/{template}/duplicate', [TemplateApiController::class, 'duplicate'])
                        ->whereNumber('template')->name('duplicate');
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

                            // ------------------------------------------ this store's own ledger
                            //
                            // A SECOND CONSENT SURFACE, NOT A DUPLICATE OF THE ONE ABOVE. Those
                            // routes proxy Lynomia's per-contact consent API -- "what does the
                            // workspace hold about this person". These read THIS SIDE's rows:
                            // what the store's own checkout captured, whether Lynomia has been
                            // told, and whether it agreed. They differ exactly when something is
                            // wrong, which is when a merchant looks.
                            //
                            // No store id in any path: EnsureStoreOwnsLink resolves it from the
                            // session and has already authorized it, so there is no IDOR surface.
                            Route::get('/store-consents', [StoreConsentController::class, 'index'])
                                ->name('store_consents');
                            Route::post('/store-consents/settings', [StoreConsentController::class, 'updateSettings'])
                                ->name('store_consents.settings');
                            // Read-only towards Lynomia -- it refreshes this side's picture and
                            // never pushes a local decision to force agreement. See
                            // docs/partner-consent/AUTHORITY.md on why that direction is closed.
                            Route::post('/store-consents/reconcile', [StoreConsentController::class, 'reconcile'])
                                ->name('store_consents.reconcile');
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

            // P6B. The installation lifecycle. Each one carries across to Lynomia's commerce
            // store as well as moving the link here, which is the transition channel the two
            // state machines never had.
            Route::post('/links/{link}/suspend', [IntegrationController::class, 'suspend'])
                ->whereNumber('link')
                ->name('links.suspend');

            Route::post('/links/{link}/resume', [IntegrationController::class, 'resume'])
                ->whereNumber('link')
                ->name('links.resume');

            // Terminal, and the only transition that drops Lynomia's stored credentials.
            Route::post('/links/{link}/decommission', [IntegrationController::class, 'decommission'])
                ->whereNumber('link')
                ->name('links.decommission');

            // The way out of a rejected credential: mint a replacement and bring it back.
            Route::post('/links/{link}/reauthenticate', [IntegrationController::class, 'reauthenticate'])
                ->whereNumber('link')
                ->name('links.reauthenticate');

            // WHAT LYNOMIA SEES OF THIS INSTALLATION. A separate request rather than part of
            // the page, because the page is deliberately built from local tables so that it
            // still renders when the integration is unreachable -- which is exactly when an
            // operator opens it. This is the one panel that does reach across, so it is
            // fetched on demand and its failure is a message in one card, not a blank page.
            Route::get('/links/{link}/remote-health', [IntegrationController::class, 'remoteHealth'])
                ->whereNumber('link')
                ->name('links.remote_health');

            // Included agent seats per plan. Same PlatformOperatorOnly group as the link
            // actions above -- a seat ceiling is a commercial control and belongs behind the
            // same door, not on the core plan form where it would have two owners.
            Route::post('/seats', [IntegrationController::class, 'updateSeats'])
                ->name('seats.update');

            // Marketing sends: what a plan includes, the add-on catalogue, and a grant to one
            // store. Same operator-only door as the seat ceiling, because they are the same
            // kind of decision -- a commercial allowance, not a merchant preference.
            //
            // AN ADD-ON IS GRANTED HERE, NOT BOUGHT BY THE MERCHANT, which is the only safe
            // shape while every payment gateway in this installation is switched off.
            Route::post('/marketing-allowance', [IntegrationController::class, 'updateMarketingAllowance'])
                ->name('marketing.allowance');
            Route::post('/marketing-addons', [IntegrationController::class, 'storeAddon'])
                ->name('marketing.addons.store');
            Route::post('/marketing-addons/grant', [IntegrationController::class, 'grantAddon'])
                ->name('marketing.addons.grant');

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
