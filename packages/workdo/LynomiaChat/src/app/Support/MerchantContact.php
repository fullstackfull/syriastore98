<?php

namespace Workdo\LynomiaChat\app\Support;

use App\Models\User;

/**
 * WHETHER A MERCHANT CAN BE REACHED, AND WHY NOT WHEN THEY CANNOT.
 *
 * ONE DEFINITION, because there are now three readers and they must agree: the resolver that
 * decides whether an operational notice may be sent, the Super Admin panel that tells an
 * operator who is unreachable, and the verification flow that promotes a number once its
 * holder has proved it. Three copies of "is this a phone number" is three answers.
 *
 * WHAT THIS INSTALLATION ACTUALLY HAS IN THE COLUMN. `users.mobile` is nullable and until now
 * had no format rule and no verification of any kind, and it is actively written with values
 * that are not phone numbers: self-registration writes an empty string, and Super Admin store
 * creation wrote the literal 7878787878 for every merchant it created. So the states below are
 * not hypothetical categories -- each one is a population that exists in the table today.
 *
 * VERIFIED IS A SEPARATE QUESTION FROM WELL-FORMED, and the distinction is the whole point.
 * A number can be perfectly well-formed and belong to somebody else: a typo in a merchant's own
 * profile form is a valid number for a stranger. Only `users.mobile_verified_at` says the
 * holder proved it, and only a merchant can set that, by receiving a code on it.
 */
final class MerchantContact
{
    /** No number at all. Self-registration writes '' rather than NULL, so both land here. */
    public const MISSING = 'missing';

    /**
     * A value in the column that was never anybody's number. Not a general-purpose filter:
     * this is the one Super Admin store creation wrote, and the seeders still write for the
     * installation's own accounts.
     */
    public const PLACEHOLDER = 'placeholder';

    /** Present, not a placeholder, and not something that can be dialled. */
    public const NOT_USABLE = 'not_usable';

    /** Dialable, and nobody has proved they hold it. */
    public const UNVERIFIED = 'unverified';

    /** Dialable, and its holder answered a code sent to it. */
    public const VERIFIED = 'verified';

    /** Values that are in the column and are not phone numbers. */
    public const PLACEHOLDERS = ['7878787878'];

    /** Shortest and longest an international subscriber number can be (ITU E.164). */
    public const MIN_DIGITS = 8;
    public const MAX_DIGITS = 15;

    private function __construct(
        public readonly string $state,
        public readonly ?string $digits,
    ) {
    }

    public static function of(?User $merchant): self
    {
        if ($merchant === null) {
            return new self(self::MISSING, null);
        }

        $state = self::classify((string) ($merchant->mobile ?? ''));

        if ($state !== self::UNVERIFIED) {
            return new self($state, null);
        }

        // Well-formed. Whether it is OURS to use is the second question.
        return new self(
            $merchant->mobile_verified_at === null ? self::UNVERIFIED : self::VERIFIED,
            self::digitsOf((string) $merchant->mobile)
        );
    }

    /** May an operational notice be sent to this merchant? */
    public function reachable(): bool
    {
        return $this->state === self::VERIFIED;
    }

    /**
     * What to tell the MERCHANT about their own contact state.
     *
     * Here rather than in the view, so the five states have one vocabulary: a Blade @switch
     * over class constants is the same policy written a second time, in a file nobody greps.
     */
    public function explanation(): string
    {
        return match ($this->state) {
            self::MISSING => __('There is no mobile number on your profile yet.'),
            self::PLACEHOLDER => __('The number on your profile is a placeholder that was filled in for you, not a real one.'),
            self::NOT_USABLE => __('The number on your profile cannot receive messages.'),
            self::UNVERIFIED => __('Nobody has confirmed this number belongs to you yet.'),
            default => __('We can tell you if this integration stops working.'),
        };
    }

    /**
     * The refusal reason an operator reads, or null when there is nothing to refuse.
     *
     * The strings are the ones already recorded on lynomia_platform_notices.failure_reason, so
     * rows written before verification existed keep meaning what they said.
     */
    public function refusal(): ?string
    {
        return match ($this->state) {
            self::MISSING => 'no_phone_on_record',
            self::PLACEHOLDER => 'placeholder_phone',
            self::NOT_USABLE => 'phone_not_usable',
            self::UNVERIFIED => 'phone_not_verified',
            default => null,
        };
    }

    /**
     * Classify a raw value WITHOUT asking whether it was verified -- what the verification
     * flow needs when a merchant offers a candidate number, before anybody has proved it.
     *
     * Digits only, a leading + stripped, and judged on length. A stricter rule would need a
     * real phone library and a country to parse against; what this must prevent is admitting
     * an empty string, a placeholder or something that is plainly not a number, and it does
     * that without pretending to validate more than it can.
     */
    public static function classify(string $raw): string
    {
        $trimmed = trim($raw);

        if ($trimmed === '') {
            return self::MISSING;
        }

        $digits = self::digitsOf($trimmed);

        foreach (self::PLACEHOLDERS as $placeholder) {
            if ($digits === $placeholder || str_ends_with($digits, $placeholder)) {
                return self::PLACEHOLDER;
            }
        }

        if (strlen($digits) < self::MIN_DIGITS || strlen($digits) > self::MAX_DIGITS) {
            return self::NOT_USABLE;
        }

        return self::UNVERIFIED;
    }

    /** The form a number is stored and compared in: digits, no punctuation, no leading plus. */
    public static function digitsOf(string $raw): string
    {
        return preg_replace('/\D+/', '', trim($raw)) ?? '';
    }
}
