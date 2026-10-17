<?php

namespace Workdo\LynomiaChat\app\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use App\Models\AuditLog;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\CommerceCredential;

/**
 * The only door into the commerce API, and the only thing that decides which store a request is
 * about.
 *
 * IT LOGS NOBODY IN. Deliberately. CustomAuth resolves a Sanctum token and calls auth()->login()
 * on the web guard, after which getCurrentStore() answers from users.current_store -- a mutable
 * column shared across that person's sessions and devices. Any handler written after that is one
 * getCurrentStore() call away from silently serving the wrong tenant. There is no authenticated
 * user here at all, so that mistake is not available to make: the store comes from the
 * credential and is handed to the controller as a request attribute.
 *
 * ISOLATION IS STRUCTURAL, NOT POLICY. A credential for store A produces store A's id and
 * nothing else; there is no parameter, body field or header a caller can add to widen it. Every
 * query the controllers run is scoped by that id, and the tests assert a store B credential
 * cannot read store A's orders, customers or carts.
 */
class AuthenticateCommerceApi
{
    public const STORE_ID = 'lynomia.commerce_store_id';
    public const LINK = 'lynomia.commerce_link';

    /**
     * The routes that disclose the store's OWN IDENTITY and nothing else: external_store_id,
     * name, slug, currency, active, link_state, the capability flags and the contract version.
     * No customer, order or cart data is reachable through any of them.
     *
     * Named here rather than parameterised on the route, because the group declares this
     * middleware once and a second declaration with an argument would run it twice. One list, in
     * the class that enforces it, is also the only place a reader has to look.
     */
    private const IDENTITY_ROUTES = ['lynomia.commerce.v1.store'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('lynomia.enabled')) {
            return $this->refuse($request, 'integration_disabled', 503);
        }

        $token = $this->presentedToken($request);
        $storeId = CommerceCredential::resolveStoreId($token);

        if ($storeId === null) {
            // One answer for malformed, forged, revoked and unknown. Telling them apart is three
            // oracles, and the only caller that should ever see this is a misconfigured adapter.
            return $this->refuse($request, 'bad_credential', 401);
        }

        $link = LynomiaStoreLink::forStore($storeId)->first();

        if ($link === null) {
            return $this->refuse($request, 'link_not_active', 403);
        }

        // THE ORDINARY GATE, unchanged and still the answer for every request but one.
        // A revoked or pending link must not serve commerce data even while its credential
        // still verifies: the credential proves WHICH store, the link state proves WHETHER.
        $permitted = $link->allowsSso();

        // THE ONE EXCEPTION, AND IT IS NARROWER THAN IT LOOKS.
        //
        // Lynomia will not re-enable an installation it has not verified: its `enable` runs the
        // provider health check, which is exactly ONE GET of the identity route below. This side
        // pushes a lifecycle transition to Lynomia BEFORE writing it locally, so at the moment of
        // that health check the link is still `suspended` -- and refusing it there is what made
        // suspension a one-way door (resume() raised a conflict, reauthenticate() reported
        // back_online=false, and the link stayed suspended with no path back).
        //
        // WHAT IS **NOT** DONE HERE. The state list is not widened. `allowsSso()` is not relaxed.
        // No data route gains anything. A suspended installation still cannot read a customer, an
        // order or a cart, and still cannot send a message, for the whole of its suspension.
        //
        // WHAT IS DONE. A recovery window, opened only by an authorised lifecycle recovery on this
        // side, is SPENT here -- once, by one identity read, inside a window measured in seconds,
        // and only for the store the presented credential already resolved to. The window
        // authorises nothing by itself: this request still had to carry that store's own valid
        // commerce credential to get this far. Spending is a single conditional UPDATE, so two
        // concurrent health checks cannot both be let through on one window.
        if (! $permitted
            && in_array($request->route()?->getName(), self::IDENTITY_ROUTES, true)
            && $link->recoveryEligible()
            && LynomiaStoreLink::spendRecoveryWindow($storeId)) {
            $permitted = true;

            // Audited as its own event, because it is the only time this gate answers for a link
            // that is not active and a reader must be able to find every instance.
            AuditLog::record('lynomia_recovery_window_spent', $link, [], [
                'state' => $link->state,
                'purpose' => $link->recovery_window_purpose,
                'opened_by' => $link->recovery_window_opened_by,
                'route' => $request->route()?->getName(),
            ]);
        }

        if (! $permitted) {
            return $this->refuse($request, 'link_not_active', 403);
        }

        $request->attributes->set(self::STORE_ID, $storeId);
        $request->attributes->set(self::LINK, $link);

        return $next($request);
    }

    private function presentedToken(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : null;
    }

    private function refuse(Request $request, string $reason, int $status): Response
    {
        Log::warning('[lynomia] refused a commerce API request', [
            'reason' => $reason,
            'status' => $status,
            'path' => $request->path(),
        ]);

        return response()->json(['error' => 'Unauthorized'], $status);
    }
}
