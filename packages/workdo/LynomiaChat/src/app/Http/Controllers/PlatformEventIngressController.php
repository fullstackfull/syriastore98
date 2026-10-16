<?php

namespace Workdo\LynomiaChat\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Workdo\LynomiaChat\app\Http\Middleware\GuardEventIngress;
use Workdo\LynomiaChat\app\Services\EventSignature;
use Workdo\LynomiaChat\app\Services\PlatformDeliveryProjector;

/**
 * WHAT BECAME OF THE NOTICES THIS INSTALLATION SENT ITS OWN MERCHANTS.
 *
 * A SECOND DOOR, AND WHY IT IS NOT THE EXISTING ONE. `/lynomia/events/{store}` is keyed on a
 * store: its secret is derived from the store id, and it refuses any delivery whose account is
 * not the one that store is linked to. The platform workspace is not a store and never will be
 * -- it is the installation's own account -- so there is no store id to key on and no link to
 * check against. Reusing a merchant's door would have meant deriving the platform's secret
 * from a merchant's id, which is a merchant who can forge the platform's deliveries.
 *
 * EVERYTHING ELSE IS THE SAME CODE. The same `EventSignature` implementation with a different
 * scope, the same `GuardEventIngress` for the size cap and the HMAC, the same insert-and-catch
 * idempotency on a unique delivery id, the same refusal to log an unauthenticated body.
 *
 * WHAT IT CHECKS, IN ORDER:
 *   the guard   size, then the signature, before anything expensive touches the body
 *   the account the payload must be about THE configured platform workspace. A signature
 *               proves who sent a delivery; this proves what it is allowed to talk about.
 *   a delivery id without one there is no idempotency, so there is no delivery.
 */
class PlatformEventIngressController extends Controller
{
    public function __construct(private readonly PlatformDeliveryProjector $projector)
    {
    }

    public function store(Request $request): JsonResponse
    {
        // The guard sets this after verifying the HMAC. Re-asserted here so a future routing
        // change that drops the middleware fails closed rather than accepting anything.
        if ($request->attributes->get(GuardEventIngress::VERIFIED_PLATFORM) !== true) {
            return $this->refuse('unverified_request', 401);
        }

        $payload = $request->json()->all();

        if (! is_array($payload)) {
            return $this->refuse('malformed_payload', 400);
        }

        $workspace = config('lynomia.platform_workspace_account_id');

        if (! is_int($workspace) || (int) ($payload['account']['id'] ?? 0) !== $workspace) {
            // The same answer whether the workspace is unconfigured or the delivery is about
            // somebody else's account: neither is something a caller gets to learn by asking.
            return $this->refuse('unknown_workspace', 404);
        }

        $deliveryId = (string) $request->header(EventSignature::DELIVERY_HEADER, '');

        if ($deliveryId === '') {
            return $this->refuse('missing_delivery_id', 400);
        }

        $event = $this->projector->apply($deliveryId, $payload);

        // 200 EITHER WAY. A duplicate and a stale event are both correct outcomes, not errors,
        // and answering non-2xx would make Lynomia retry something this side has deliberately
        // decided about.
        return response()->json([
            'recorded' => true,
            'applied' => (bool) $event->applied,
            'skipped' => $event->skip_reason,
        ]);
    }

    private function refuse(string $reason, int $status): JsonResponse
    {
        // Identifiers only. The body may be unauthenticated at this point and must not be
        // logged, and the caller is told nothing it could use as an oracle.
        Log::warning('[lynomia] refused a platform delivery event', ['reason' => $reason]);

        return response()->json(['error' => 'Refused'], $status);
    }
}
