<?php

use Illuminate\Support\Facades\Route;
use Workdo\LynomiaChat\app\Http\Controllers\CommunicationCenterController;
use Workdo\LynomiaChat\app\Http\Controllers\ConnectionController;
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
 * FOUR LAYERS GUARD EVERY MERCHANT ROUTE:
 *   auth/xss/setlocate/verified  the standard administrative stack
 *   PlanModuleCheck:LynomiaChat  entitlement -- the plan's module list must include it
 *   EnsureStoreOwnsLink          tenancy -- resolves the caller's OWN store's link
 *   (no route parameter)         there is no id to tamper with, so no IDOR surface
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
Route::middleware(['web', 'auth', 'xss', 'setlocate', 'verified'])->group(function () {

    // ---------------------------------------------------------------- merchant
    Route::middleware(['PlanModuleCheck:LynomiaChat'])
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
        });
});
