<?php

namespace App\Services;

/**
 * The outcome of verifying a payment against the gateway, as a value.
 *
 * Deliberately a value object rather than a bare boolean: the difference
 * between "the gateway said this was not paid" and "we could not establish
 * whether this was paid" is the whole point of the exercise, and both must be
 * distinguishable from success at the call site and in the log.
 */
class PaymentVerificationResult
{
    /** The gateway positively confirmed the payment, for the expected amount. */
    public const VERIFIED = 'verified';

    /** The gateway was reachable and answered: not paid, or the wrong amount. */
    public const REJECTED = 'rejected';

    /**
     * Verification could not be performed at all — a missing field name, a
     * missing shared secret, an unparseable amount, an unreachable gateway.
     *
     * This is NOT success. Treating it as success is exactly the defect being
     * repaired: the callback used to mark an order Paid on a bare status test.
     */
    public const UNVERIFIABLE = 'unverifiable';

    public function __construct(
        public readonly string $outcome,
        public readonly string $reason,
        public readonly ?string $capturedAmount = null,
        public readonly ?string $expectedAmount = null
    ) {
    }

    public static function verified(string $reason, ?string $captured = null, ?string $expected = null): self
    {
        return new self(self::VERIFIED, $reason, $captured, $expected);
    }

    public static function rejected(string $reason, ?string $captured = null, ?string $expected = null): self
    {
        return new self(self::REJECTED, $reason, $captured, $expected);
    }

    public static function unverifiable(string $reason, ?string $captured = null, ?string $expected = null): self
    {
        return new self(self::UNVERIFIABLE, $reason, $captured, $expected);
    }

    public function isVerified(): bool
    {
        return $this->outcome === self::VERIFIED;
    }

    /** Whether the payment may be treated as settled. Fails closed. */
    public function allowsPaid(): bool
    {
        return $this->outcome === self::VERIFIED;
    }

    /** Log-safe context. Carries amounts, never credentials. */
    public function context(): array
    {
        return [
            'outcome' => $this->outcome,
            'reason' => $this->reason,
            'captured_amount' => $this->capturedAmount,
            'expected_amount' => $this->expectedAmount,
        ];
    }
}
