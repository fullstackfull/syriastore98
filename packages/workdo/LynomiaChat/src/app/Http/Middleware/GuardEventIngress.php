<?php

namespace Workdo\LynomiaChat\app\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Workdo\LynomiaChat\app\Services\EventSignature;
use Symfony\Component\HttpFoundation\Response;

/**
 * The cheap refusals, before anything expensive happens to an unauthenticated body.
 *
 * WHY THIS IS A MIDDLEWARE AND NOT A CHECK IN THE CONTROLLER. By the time the controller runs,
 * the body has already been read, decoded and -- for Content-Type: application/json -- walked
 * recursively twice by TrimStrings and ConvertEmptyStringsToNull. An attacker who sends the
 * right Content-Type therefore gets a full parse out of an unsigned request. The size check has
 * to come before that work to be worth anything, and route middleware is the earliest point this
 * module can reach without editing the global stack for every other route on the platform.
 * The module's provider additionally tells those two transforms to skip this path entirely.
 *
 * CONTENT-LENGTH IS CHECKED, AND SO IS THE BODY. Laravel's ValidatePostSize reads
 * CONTENT_LENGTH only, so a chunked request with no Content-Length compares `null > max` and
 * sails through. The actual string is measured too, which costs nothing because the controller
 * reads it anyway.
 */
class GuardEventIngress
{
    /**
     * A conversation-change notification carries identifiers, a short message preview and some
     * provider metadata. Lynomia's own payloads sit in the low tens of kilobytes; 256 KiB is
     * roughly an order of magnitude of headroom over anything observed, and still small enough
     * that a flood of them cannot exhaust a worker.
     */
    public const MAX_BYTES = 262144;

    /** Set once the HMAC has been checked, and read by the per-store rate limiter. */
    public const VERIFIED_STORE = 'lynomia.verified_store';

    public function handle(Request $request, Closure $next): Response
    {
        $declared = $request->server('CONTENT_LENGTH');

        if ($declared !== null && (int) $declared > self::MAX_BYTES) {
            return $this->refuse($request, 'declared_too_large', (int) $declared, 413);
        }

        $length = strlen($request->getContent());

        if ($length > self::MAX_BYTES) {
            return $this->refuse($request, 'body_too_large', $length, 413);
        }

        // THE SIGNATURE IS CHECKED HERE, BEFORE THE PER-STORE RATE LIMIT, AND THAT ORDERING IS
        // THE WHOLE POINT. It used to be checked in the controller, which runs AFTER the
        // throttle -- so an unauthenticated caller who merely knew a store id could spend that
        // store's entire delivery budget on garbage. Every genuine delivery after that got a
        // 429, and because Lynomia never retries an account webhook, each one was destroyed.
        // A rate limiter that an anonymous attacker can aim at one merchant is a remote
        // data-destruction primitive, not a protection.
        //
        // Verifying first costs one HMAC over a body already capped at 256 KiB, and the
        // unauthenticated flood is bounded by the separate per-IP limit in front of this.
        $store = (int) $request->route('store');

        if (! EventSignature::verify(
            $store,
            $request->getContent(),
            $request->header(EventSignature::SIGNATURE_HEADER),
            $request->header(EventSignature::TIMESTAMP_HEADER)
        )) {
            // Deliberately uninformative TO THE CALLER: telling them whether the signature, the
            // timestamp or the store was wrong is three oracles in one response.
            return $this->refuse($request, 'bad_signature', 0, 401);
        }

        $request->attributes->set(self::VERIFIED_STORE, $store);

        return $next($request);
    }

    private function refuse(Request $request, string $reason, int $bytes, int $status): Response
    {
        // Identifiers and sizes only. The body is unauthenticated at this point and must not be
        // logged; the store id comes from the path, which is not trusted either but is safe to
        // record because it is already a bounded integer.
        Log::warning('[lynomia] refused an inbound event', [
            'store_id' => (int) $request->route('store'),
            'reason' => $reason,
            'bytes' => $bytes,
            'limit' => self::MAX_BYTES,
        ]);

        return response()->json(['error' => 'Payload too large'], $status);
    }
}
