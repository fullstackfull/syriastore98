<?php

namespace App\Services\Payments\Reconciliation;

/**
 * ONE PROVIDER'S AUTHORITATIVE ANSWER ABOUT A REFERENCE THIS SERVER MINTED.
 *
 * "Authoritative" has a specific meaning here and an implementation must meet all of it, because
 * the reconciliation surface settles on this answer with nobody's browser in the loop:
 *
 *   - it is obtained SERVER TO SERVER, over a channel this server authenticates itself (validated
 *     TLS, and the provider's own signature where it offers one). A value relayed through a buyer's
 *     browser is a claim, not an answer;
 *   - it is keyed on a reference the SERVER minted, never on anything a request supplied;
 *   - the amount it reports is in units established from evidence for THAT provider. The
 *     conventions genuinely differ per provider (`PaytmTransactionVerifier`'s header has the
 *     measurement), so there is no shared normaliser and there must not be one;
 *   - when it cannot answer, it says `UNVERIFIABLE`. It never guesses.
 *
 * A PROVIDER WITH NO IMPLEMENTATION HAS NO VERIFIER, AND THAT IS A STATE, NOT A GAP TO PAPER OVER.
 * D-31 requirement 8 blocks a provider whose contract cannot be established; Cashfree and Mollie
 * have a plan binding and no amount contract, Benefit and Flutterwave have neither. Nothing settles
 * for them from here. `CaptureVerifierRegistry` is where that is decided, in one place.
 */
interface CaptureVerifier
{
    /** The canonical provider name, exactly as `plan_purchase_intents.provider` stores it. */
    public function provider(): string;

    public function verify(string $reference): CaptureAnswer;
}
