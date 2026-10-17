<?php

namespace Workdo\LynomiaChat\app\Http\Middleware;

use App\Models\Store;
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
 * THE STORE IS AUTHORIZED BEFORE THE LINK, AND THAT ORDER IS THE WHOLE POINT.
 * getCurrentStore() reads users.current_store, a column the caller can move --
 * /change-store/{id} exists and writes it. That route checks ownership today
 * (StoreController::changeStore), but a tenancy control that depends on another controller's
 * check staying correct is one refactor away from being wrong.
 *
 * An earlier version of this middleware authorized only the LINK: it refused when a link for
 * the store belonged to a different owner. That left the case where the foreign store has no
 * link YET wide open, which is the worst one -- there is nothing to compare against, so the
 * foreign store id reached the controller and provisioning claimed it. Proven, not theorised:
 * a merchant pointed current_store at another tenant's unconnected store, POSTed
 * /lynomia/connect/provision, and got an ACTIVE link with that tenant's store_id and their own
 * owner_user_id, three calls on the wire, and -- because store_id is unique -- the real owner
 * permanently unable to connect.
 *
 * So the store itself is authorized first, from Store.created_by against the resolved owner,
 * before any link is looked up and whether or not a link exists. The link lookup stays scoped
 * by owner as well: the store check answers "is this tenant's store", the link's own
 * owner_user_id answers "is this tenant's link", and they are not the same question once a
 * store can change hands.
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

        $ownerId = (int) $user->creatorId();
        $store = Store::find($storeId);

        // Authorize the STORE first -- before the link, and whether or not a link exists.
        // Without this, a caller whose current_store points at another tenant's unconnected
        // store reaches the controller holding that store's id.
        if (! $store || (int) $store->created_by !== $ownerId) {
            return $this->refuse($request, __('You do not have access to this store.'), 403);
        }

        $link = LynomiaStoreLink::forOwnedStore($storeId, $ownerId)->first();

        if ($requirement === 'existing' && $link === null) {
            return $this->refuse($request, __('This store is not connected to Lynomia Chat.'));
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
