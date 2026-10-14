<?php

namespace Workdo\LynomiaChat\app\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Http\Middleware\EnsureStoreOwnsLink;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\AgentApiClient;
use Workdo\LynomiaChat\app\Services\MerchantInboxGateway;

/**
 * How a merchant BFF route becomes a Lynomia call, and how a Lynomia failure becomes an answer
 * a browser can act on.
 *
 * EXTRACTED WHEN THERE WAS A SECOND SURFACE, not before. Phase 2's inbox was the only BFF, so
 * this lived in its controller. Phase 4's Automation Studio is the second, and the two must
 * agree about which failure is a 403 and which is a 503 -- the mapping below is the kind of
 * thing that is copied once and then diverges on the next edit, after which one surface tells
 * a merchant to try again and the other tells them to call support for the identical fault.
 *
 * WHAT IS *NOT* SHARED, DELIBERATELY: the sentences. `merchantMessage` stays with the surface
 * that owns it, because "you do not have access to that conversation" and "only the store owner
 * can manage automations" are the same `kind` and completely different advice. A shared
 * sentence would have to be vague enough to fit both, which is how an actionable refusal turns
 * into a support ticket.
 *
 * @property-read MerchantInboxGateway $gateway
 */
trait ProxiesToLynomia
{
    /**
     * Resolve the caller's workspace, run the operation, and turn any failure into something a
     * merchant can read.
     *
     * THE UPSTREAM BODY IS NEVER FORWARDED. The retired Chatwoot integration echoed the
     * provider's raw response to the browser; that leaked internal error text to a merchant and
     * gave the caller nothing to branch on. LynomiaApiException carries a machine-readable kind
     * and a safe sentence, and only those cross this boundary.
     */
    private function proxy(Request $request, callable $operation): JsonResponse
    {
        /** @var LynomiaStoreLink|null $link */
        $link = $request->attributes->get(EnsureStoreOwnsLink::ATTRIBUTE);

        if (! $link) {
            return response()->json(['error' => __('This store is not connected to Lynomia Chat.')], 409);
        }

        try {
            /** @var AgentApiClient $client */
            $client = $this->gateway->clientFor($link, $request->user());

            return response()->json($operation($client));
        } catch (LynomiaApiException $e) {
            return response()->json(['error' => $this->merchantMessage($e), 'kind' => $e->kind], $this->statusFor($e));
        }
    }

    private function statusFor(LynomiaApiException $e): int
    {
        return match ($e->kind) {
            LynomiaApiException::KIND_CONFLICT => 409,
            LynomiaApiException::KIND_UNAUTHORIZED, LynomiaApiException::KIND_NOT_PERMISSIBLE => 403,
            LynomiaApiException::KIND_NOT_FOUND => 404,
            LynomiaApiException::KIND_RATE_LIMITED => 429,
            // Unreachable, disabled and not-configured are all "try again or call support", and
            // 503 is the one a browser and a load balancer both read the same way.
            default => 503,
        };
    }

    /** The sentence the merchant reads. Written per surface -- see the class comment. */
    abstract private function merchantMessage(LynomiaApiException $e): string;
}
