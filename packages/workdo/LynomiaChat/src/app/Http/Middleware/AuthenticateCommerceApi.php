<?php

namespace Workdo\LynomiaChat\app\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
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

        if ($link === null || ! $link->allowsSso()) {
            // A revoked or pending link must not serve commerce data even while its credential
            // still verifies: the credential proves WHICH store, the link state proves WHETHER.
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
