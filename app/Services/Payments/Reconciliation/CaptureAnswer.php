<?php

namespace App\Services\Payments\Reconciliation;

/**
 * WHAT A PROVIDER SAID ABOUT ONE REFERENCE, in the three states this project already uses.
 *
 * The vocabulary is `PaytmTransactionVerifier`'s, not a new one: `VERIFIED` (the provider confirms
 * a successful capture and says how much), `REFUSED` (the provider answered, and the answer is not
 * a successful capture), `UNVERIFIABLE` (no authoritative answer could be obtained). The third is
 * never a pass and never a "no" either -- an answer that could not be obtained says nothing about
 * the money, which is why a reconciliation run writes nothing on it and asks again next time.
 *
 * AN UNKNOWN STATE IS `UNVERIFIABLE`. `fromVerifierArray()` reads a verifier's array, and a verifier
 * that one day returns a state this class has not heard of must not be read as success by a
 * `!== REFUSED` somewhere downstream. The permissive direction is the one that moves money.
 */
final class CaptureAnswer
{
    public const VERIFIED = 'verified';
    public const REFUSED = 'refused';
    public const UNVERIFIABLE = 'unverifiable';

    private function __construct(
        public readonly string $state,
        public readonly ?string $amount,
        public readonly ?string $currency,
        public readonly ?string $transactionId,
        public readonly string $reason,
    ) {
    }

    /**
     * @param  array<string,mixed>  $answer  `{state, amount, currency, txn_id, reason}`, the shape
     *                                       `PaytmTransactionVerifier::verify()` returns
     */
    public static function fromVerifierArray(array $answer): self
    {
        $state = is_string($answer['state'] ?? null) ? $answer['state'] : '';

        if (! in_array($state, [self::VERIFIED, self::REFUSED, self::UNVERIFIABLE], true)) {
            return self::unverifiable('the verifier returned a state this application does not recognise');
        }

        return new self(
            $state,
            self::scalarOrNull($answer['amount'] ?? null),
            self::scalarOrNull($answer['currency'] ?? null),
            self::scalarOrNull($answer['txn_id'] ?? null),
            is_string($answer['reason'] ?? null) ? $answer['reason'] : '',
        );
    }

    public static function unverifiable(string $reason): self
    {
        return new self(self::UNVERIFIABLE, null, null, null, $reason);
    }

    public function isVerified(): bool
    {
        return $this->state === self::VERIFIED;
    }

    private static function scalarOrNull(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }
}
