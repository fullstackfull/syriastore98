<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Customer;
use App\Models\User;
use App\Models\DeliveryBoy;
use App\Support\Accounts\AccountSuspension;
use App\Traits\ApiResponser;
use Laravel\Sanctum\PersonalAccessToken;

class CustomAuth
{
    use ApiResponser;

    /**
     * Handle an incoming request.
     *
     * SEC-R1-01 / R2-01 / R2-02 / NEW-custom-auth-forgeable.
     *
     * This middleware guards 189 route declarations in routes/api.php and
     * previously authenticated on `personal_access_tokens.id` alone:
     *
     *   - the 40-character token secret was parsed off the header and never
     *     compared, so `Bearer 7` authenticated as the owner of token id 7;
     *   - a request with no Authorization header fell past the `if` straight to
     *     `$next($request)` and reached the controller with no principal;
     *   - `expires_at` was never read and `last_used_at` was never written.
     *
     * The repair delegates the lookup to Sanctum's own findToken(), which
     * splits on `|` with limit 2, finds by id, and performs the timing-safe
     * hash_equals(stored_sha256, sha256(presented)) comparison that was
     * missing. The `tokenable_type` branch below is preserved so the 236
     * existing auth('customers') / auth('deliveryboy') call sites keep working.
     *
     * Wire compatibility: the shipped Flutter app sends
     * `Authorization: Bearer {id}|{secret}` (repo-B lib/network/network.dart:60)
     * holding the verbatim `plainTextToken` minted at
     * Api/DashboardController::login. findToken() consumes exactly that format,
     * so already-issued tokens continue to authenticate and no re-login or app
     * release is required.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Laravel's own scheme-correct parser, replacing the blind
        // substr($token, 7) which accepted any seven-character prefix.
        $bearer = $request->bearerToken();

        // Closes the anonymous pass-through. This is the one intentionally
        // behaviour-changing line: requests that previously reached the
        // controller with no credentials now receive 401.
        if (!$bearer) {
            return $this->unauthenticated();
        }

        $accessToken = PersonalAccessToken::findToken($bearer);

        if (!$accessToken) {
            return $this->unauthenticated();
        }

        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            return $this->unauthenticated();
        }

        $tokenable = $accessToken->tokenable;

        if (!$tokenable) {
            return $this->unauthenticated();
        }

        /*
         * A SUSPENDED PRINCIPAL'S TOKEN IS REFUSED HERE, BEFORE IT IS LOGGED IN ANYWHERE.
         *
         * MEASURED DEFECT: this middleware checked only `expires_at` and that the tokenable was
         * non-null -- and CLAUDE.md records that Sanctum tokens in this platform NEVER EXPIRE (there
         * is no `config/sanctum.php`), so `expires_at` is null for every token ever minted.
         * Suspension lived only at the two login FORMS
         * (`Auth\AuthenticatedSessionController:68` and `:72`,
         * `Customer\Auth\CustomerLoginController:862`), which a bearer token never touches. So
         * disabling an account left its API access intact indefinitely: the operator's only
         * suspension control had no effect on any client that had already signed in once.
         *
         * THIS IS THE RIGHT SEAM because it is the single point every token type passes through --
         * `Customer`, `User` and `DeliveryBoy` all dispatch below it -- so one check covers all three
         * and a fourth tokenable cannot be added without passing it.
         *
         * REFUSED, NOT REVOKED. The token row is left alone. A refusal makes it unusable for as long
         * as the account is suspended and costs nothing to undo, where deleting
         * `personal_access_tokens` rows is irreversible and forces every client of that account to
         * sign in again afterwards. Which of those the product wants is an owner decision and is in
         * the confidential handoff; the refusal is needed either way. Deleting on a GET would also
         * mean a cached or retried request silently destroying credentials.
         *
         * 401 IS CORRECT AND IS NOT A NEW REGRESSION. CLAUDE.md warns that the shipped Flutter app
         * forces a logout on any 401, so a NEW 401 on a path a client calls is user-visible -- but
         * for a SUSPENDED account a forced logout is precisely the intended outcome, and no
         * non-suspended path gains a 401. `unauthenticated()` is reused rather than reinvented, so
         * the JSON shape the app parses at repo-B `lib/network/network.dart:285-288` is unchanged.
         */
        if (AccountSuspension::isSuspended($tokenable)) {
            return $this->unauthenticated();
        }

        // Preserved verbatim from the original :36-44 so the existing guard
        // semantics, and every call site that reads them, are unchanged.
        if ($tokenable instanceof Customer) {
            auth('customers')->login($tokenable);
        } elseif ($tokenable instanceof User) {
            auth()->login($tokenable);
        } elseif ($tokenable instanceof DeliveryBoy) {
            auth('deliveryboy')->login($tokenable);
        } else {
            return $this->unauthenticated();
        }

        $accessToken->forceFill(['last_used_at' => now()])->save();

        return $next($request);
    }

    /**
     * The 401 envelope, reusing the exact ApiResponser shape that was already
     * returned at the original :48 so the JSON the Flutter app parses, and its
     * 401 handling at repo-B lib/network/network.dart:285-288, are unchanged.
     */
    protected function unauthenticated(): Response
    {
        return $this->error(
            ['message' => __('Unauthenticated.')],
            __('Unauthenticated.'),
            401
        );
    }
}
