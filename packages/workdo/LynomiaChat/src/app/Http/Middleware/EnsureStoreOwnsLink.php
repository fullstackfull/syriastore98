<?php

namespace Workdo\LynomiaChat\app\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;

/**
 * Resolves the caller's own Lynomia link, or refuses.
 *
 * THE ROUTES CARRY NO {link} PARAMETER, ON PURPOSE. Every merchant-facing action in this
 * module acts on "the current store's link", resolved here from the session, never from an
 * id in the request. There is nothing for a caller to tamper with: no IDOR surface exists
 * because no identifier is accepted.
 *
 * TWO PREDICATES, NOT ONE. getCurrentStore() alone is not sufficient. It reads
 * users.current_store, which is a column the caller can move -- /change-store/{id} exists and
 * writes it. That route now checks ownership (StoreController::changeStore), but a tenancy
 * control that depends on another controller's check staying correct is one refactor away
 * from being wrong. So the lookup is scoped by BOTH store_id AND owner_user_id resolved from
 * the authenticated principal, and a mismatch is a 403 rather than an empty result that
 * reads like "not connected yet".
 *
 * creatorId() resolves a staff member to their owning admin (User.php), so a merchant's staff
 * reach the same tenant as the merchant -- which is intended -- while a user from another
 * tenant resolves to a different owner and matches nothing.
 */
class EnsureStoreOwnsLink
{
    public const ATTRIBUTE = 'lynomia_link';
    public const STORE_ATTRIBUTE = 'lynomia_store_id';

    public function handle(Request $request, Closure $next, string $requirement = 'any'): Response
    {
        $user = auth()->user();

        if (! $user) {
            // `auth` runs ahead of this on every route in the module and owns the anonymous
            // case; answering here would replace its sign-in redirect with a bare 403.
            return $next($request);
        }

        $storeId = (int) getCurrentStore();

        if ($storeId <= 0) {
            return $this->refuse($request, __('No store is selected.'));
        }

        $link = LynomiaStoreLink::forOwnedStore($storeId, (int) $user->creatorId())->first();

        if ($requirement === 'existing' && $link === null) {
            return $this->refuse($request, __('This store is not connected to Lynomia Chat.'));
        }

        // A row belonging to the store but to a DIFFERENT owner means the caller moved
        // current_store onto someone else's store. forOwnedStore() already returned null, so
        // this only distinguishes the message -- and it is the one case worth naming,
        // because "not connected" would be a lie.
        if ($link === null && LynomiaStoreLink::forStore($storeId)->exists()) {
            return $this->refuse($request, __('You do not have access to this store.'), 403);
        }

        $request->attributes->set(self::ATTRIBUTE, $link);
        $request->attributes->set(self::STORE_ATTRIBUTE, $storeId);

        return $next($request);
    }

    private function refuse(Request $request, string $message, int $status = 403): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['is_success' => false, 'msg' => $message], $status);
        }

        return redirect()->route('dashboard')->with('error', $message);
    }
}
