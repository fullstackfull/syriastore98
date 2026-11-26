<?php

namespace App\Services\Payments\Reconciliation;

use App\Services\PaytmTransactionVerifier;

/**
 * PAYTM, AS A `CaptureVerifier`. An adapter and nothing more.
 *
 * Every property the interface asks for is `PaytmTransactionVerifier`'s, and none is restated here:
 * the server-to-server `getTxnStatus` call over TLS this server validates, the response checksum
 * checked with the vendored official helper, the identity amount normaliser corroborated by the
 * write side, and `UNVERIFIABLE` for everything that is not an answer. This class adds the one
 * thing a session-less caller lacks -- the platform's credentials -- and hands the question on.
 *
 * The verifier is resolved from the container on every call rather than injected once, because
 * that is the seam the existing controller tests swap, and a reconciliation test wants the same
 * double they use.
 */
final class PaytmCaptureVerifier implements CaptureVerifier
{
    public function provider(): string
    {
        return 'Paytm';
    }

    public function verify(string $reference): CaptureAnswer
    {
        PaytmPlatformCredentials::apply();

        return CaptureAnswer::fromVerifierArray(
            app(PaytmTransactionVerifier::class)->verify($reference)
        );
    }
}
