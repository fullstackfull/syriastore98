<?php

namespace App\Services\Payments\Reconciliation;

/**
 * WHICH PROVIDERS CAN BE ASKED AUTHORITATIVELY. One list, and it is short on purpose.
 *
 * A provider is here only when an implementation of `CaptureVerifier` meets that interface's whole
 * contract WITH EVIDENCE. Today that is Paytm and nothing else:
 *
 * | provider | plan bound to an intent | authoritative amount contract | here |
 * |---|---|---|---|
 * | Paytm | yes | yes -- `PaytmTransactionVerifier` | **yes** |
 * | Cashfree | yes | no -- BLOCKED until a captured sandbox response exists | no |
 * | Mollie | yes | no -- same | no |
 * | every other provider | no intent is recorded at all | -- | no |
 *
 * The two "no" rows with an intent are the ones worth reading twice. Their intents ARE durable
 * proof that a purchase was initiated, and a reconciliation run will find them -- and will report
 * `NO_VERIFIER` and settle nothing, because D-31 requirement 8 blocks a provider whose amount
 * contract cannot be established and a reconciliation that guessed one would be the guess
 * requirement 8 forbids, made unattended. Adding a row to this map is the act that unblocks a
 * provider; it needs that provider's own verifier and its own evidence, never a shared one.
 */
final class CaptureVerifierRegistry
{
    /** @var array<string,class-string<CaptureVerifier>> canonical provider name => verifier */
    private const VERIFIERS = [
        'Paytm' => PaytmCaptureVerifier::class,
    ];

    public function for(string $provider): ?CaptureVerifier
    {
        $class = self::VERIFIERS[$provider] ?? null;

        if ($class === null) {
            return null;
        }

        $verifier = app($class);

        /* The key and the verifier's own name must agree, or an intent recorded for one provider
           could be answered by another's API. A mismatch is a programming error and is treated as
           "no verifier" rather than trusted. */
        return $verifier instanceof CaptureVerifier && $verifier->provider() === $provider ? $verifier : null;
    }

    /** @return list<string> */
    public function providers(): array
    {
        return array_keys(self::VERIFIERS);
    }
}
