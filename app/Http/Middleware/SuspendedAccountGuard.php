<?php

namespace App\Http\Middleware;

use App\Support\Accounts\AccountSuspension;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A SUSPENDED ACCOUNT'S EXISTING SESSION IS ENDED, ON EVERY REQUEST.
 *
 * The web half of the measured defect. Suspension was enforced only at the login FORM
 * (`Auth\AuthenticatedSessionController:68` and `:72`,
 * `Customer\Auth\CustomerLoginController:862`), so a principal who was already signed in when the
 * flag was flipped kept working indefinitely. The operator's only account-suspension control had no
 * effect after sign-in. The token half is closed in `CustomAuth`.
 *
 * WHAT THIS DOES, AND WHY IT IS THE SMALLEST THING THAT WORKS
 * ==========================================================
 *
 * It logs the suspended principal out of THAT GUARD and lets the request continue. It does not
 * redirect, does not abort, and does not render anything -- because it does not need to:
 * Laravel's own `auth` middleware already redirects an unauthenticated visitor away from a
 * protected route, and a public route should render for them as a guest. Reusing that is both less
 * code and loop-free; a redirect of our own would have to know which login page each guard belongs
 * to, and the customer one is per-store (`customer.login` takes a store slug).
 *
 * PER GUARD, NOT PER SESSION, AND THAT DISTINCTION IS LOAD-BEARING. `auth($guard)->logout()` removes
 * one guard's id from the session; `$request->session()->invalidate()` would remove them all.
 * CLAUDE.md records that `web` and `customers` are separate sessions and that registration is open,
 * so one visitor can hold both -- a merchant whose PANEL login is suspended must not also lose their
 * unrelated shopper session on some other store. Invalidating would do exactly that.
 *
 * ORDER: appended to the `web` group, so it runs after `StartSession` and can see the session at
 * all. `bootstrap/app.php` already appends 22 first-party guards there, so the seam and its
 * ordering are established rather than invented.
 *
 * THE LOGIN FORM STILL WORKS. On `POST /login` the principal is not yet authenticated when this
 * runs, so `auth('web')->user()` is null and this is a no-op; the form's own two checks refuse the
 * sign-in as before. Nothing here replaces them -- it closes the window AFTER them.
 *
 * NO NEW TRANSLATION KEY. The flash reuses the exact strings the web form already returns, so a
 * suspended merchant sees the same sentence whether they were refused at the door or removed from
 * behind it. Flashed only for the panel guard: a storefront page is the wrong place for
 * "contact your Administrator", and a shopper whose account a merchant disabled simply becomes a
 * guest.
 */
class SuspendedAccountGuard
{
    /**
     * The session guards that can carry a principal. `sanctum` is absent on purpose: a token is
     * `CustomAuth`'s business, and by the time this runs `CustomAuth` has already either refused the
     * token or logged its owner into one of these three -- in which case this check would be the
     * second one on the same principal. Harmless, but the token path is refused earlier and more
     * cleanly, with the 401 envelope the Flutter app expects.
     *
     * @var list<string>
     */
    private const SESSION_GUARDS = ['web', 'customers', 'deliveryboy'];

    public function handle(Request $request, Closure $next): Response
    {
        foreach (self::SESSION_GUARDS as $guard) {
            /*
             * `user()` on a guard with no id in the session returns null WITHOUT a query, so the
             * cost here is one lookup per guard that is actually signed in -- one, in practice --
             * and `User` and `Customer` are both `Cachable`, so even that is usually not a query.
             */
            $principal = auth($guard)->user();

            if (! $principal) {
                continue;
            }

            $reason = AccountSuspension::reasonFor($principal);

            if ($reason === null) {
                continue;
            }

            auth($guard)->logout();

            if ($guard === 'web' && $request->hasSession()) {
                $request->session()->flash(
                    'status',
                    $reason === 'the account is deactivated'
                        ? __('Your Account is de-activate,please contact your Administrator.')
                        : __('Your Account is disable,please contact your Administrator.')
                );
            }
        }

        return $next($request);
    }
}
