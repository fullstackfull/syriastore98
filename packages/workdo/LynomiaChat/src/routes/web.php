<?php

use Illuminate\Support\Facades\Route;
use Workdo\LynomiaChat\app\Http\Controllers\CommunicationCenterController;
use Workdo\LynomiaChat\app\Http\Controllers\ConnectionController;
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
    ->middleware([GuardEventIngress::class, 'throttle:lynomia-events'])
    ->name('lynomia.events.ingress');

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
        });
});
