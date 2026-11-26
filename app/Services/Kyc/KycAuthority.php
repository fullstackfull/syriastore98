<?php

namespace App\Services\Kyc;

use InvalidArgumentException;

/**
 * KYC-003 — WHO DID THE VERIFYING. THE ONE PLACE THAT ENUMERATES THEM.
 * =================================================================================================
 *
 *   KYC-003  فصل توثيق Store Syria عن توثيق مزودي الدفع والتراخيص الحكومية.
 *            Separate Store Syria's verification from payment-provider and government-licence
 *            verification.
 *
 * THE SENTENCE STATES A BOUNDARY AND NOT WHAT FALLS ON EACH SIDE. That second half is an owner
 * decision — `D-P6-46` in `docs/development/phase-6/PHASE-06-OWNER-DECISION-PACKAGE.md` says so in
 * those words: *"states a boundary without saying what falls on each side"*. So this class does NOT
 * decide which documents belong to which authority, nor which authority a payment method requires.
 * It establishes that there are three separate answers and makes it impossible to write code that
 * has only one.
 *
 * WHY THREE AND NOT TWO: the sentence names Store Syria's own verification, payment-provider
 * verification and government licences as things to be SEPARATED from each other — three nouns, so
 * three authorities. Reading it as "Store Syria versus everyone else" would re-conflate the two
 * external ones, which is the mistake in the direction the sentence warns about.
 *
 * WHAT THE SEPARATION IS WORTH, concretely, because an enum on its own is decoration:
 *
 *   - `kyc_verifications` is unique on `(store_id, authority)`, so a store has up to three
 *     independent records and no column anywhere answers "verified" without naming one.
 *   - `KycDocument` carries its authority and refuses to disagree with its profile, so a passport
 *     filed for a payment provider cannot be counted as a Store Syria document.
 *   - `config('kyc.document_kinds')` is keyed by subject type AND authority, so the three can ask
 *     for different papers — which they do in reality, and which a single list could not express.
 *   - `KycAuthoritySeparationTest` asserts that approving one leaves the others untouched, in all
 *     six ordered pairs.
 *
 * NO `trim()` AND NO CASE FOLDING IN `isKnown()`, deliberately. These are machine names this
 * application writes itself; they never arrive from a user. `normalise()` exists for the one place
 * a value DOES arrive from a request (the operator's review filter), and it refuses rather than
 * guesses — the same posture as `ConfinedDirectory::safeSegment()`, whose docblock records a
 * `trim()` that turned `"Api\0"` into `"Api"` and would have destroyed the wrong directory.
 */
final class KycAuthority
{
    /** Store Syria's own verification of the merchant. */
    public const STORE_SYRIA = 'store_syria';

    /** A payment provider's KYC, which Store Syria records and does not perform. */
    public const PAYMENT_PROVIDER = 'payment_provider';

    /** A government trade licence or registration. */
    public const GOVERNMENT_LICENCE = 'government_licence';

    /** @var list<string> */
    public const ALL = [
        self::STORE_SYRIA,
        self::PAYMENT_PROVIDER,
        self::GOVERNMENT_LICENCE,
    ];

    private function __construct()
    {
    }

    public static function isKnown(mixed $authority): bool
    {
        return is_string($authority) && in_array($authority, self::ALL, true);
    }

    /**
     * The authority a request named, or a refusal.
     *
     * @throws InvalidArgumentException
     */
    public static function normalise(mixed $authority): string
    {
        if (! self::isKnown($authority)) {
            throw new InvalidArgumentException('unknown KYC authority ' . json_encode($authority));
        }

        return (string) $authority;
    }

    /**
     * The document kinds the owner has decided this authority collects for this subject type.
     *
     * EMPTY IS THE SHIPPED DEFAULT AND IS NOT A BUG. `config/kyc.php` says why: the three approved
     * sentences name no documents, identity papers are sensitive personal data under this project's
     * standing safety boundary, and a mechanism that collected them before anybody decided which
     * ones were required would be collecting data nobody asked for. An empty list refuses every
     * upload — see `KycDocumentStore::refusalFor()`.
     *
     * @return list<string>
     */
    public static function documentKinds(string $subjectType, string $authority): array
    {
        $configured = config('kyc.document_kinds.' . $subjectType . '.' . $authority, []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(
            $configured,
            static fn ($kind): bool => is_string($kind) && preg_match('/^[a-z0-9_]{1,64}$/', $kind) === 1
        ));
    }

    /**
     * WHAT TO CALL A DOCUMENT KIND ON SCREEN, with a readable fallback.
     *
     * THE KINDS ARE OWNER-DECIDED, SO THEIR TRANSLATIONS CANNOT BE SHIPPED IN ADVANCE — and
     * `__('kyc.kind.commercial_register')` with no translation returns THE KEY ITSELF, which is how
     * a merchant ends up reading `kyc.kind.commercial_register` on their own compliance screen.
     * Measured, not guessed: that is Laravel's documented behaviour for a missing key and it is
     * what the first version of these views would have rendered.
     *
     * So the key is used when one exists — `lang/<locale>/kyc.php`, `kind.<name>`, which is where a
     * translator puts the Arabic — and otherwise the machine name is humanised. The fallback is
     * never pretty prose; it is readable, and it never leaks a dotted key.
     */
    public static function kindLabel(string $kind): string
    {
        $key = 'kyc.kind.' . $kind;

        if (\Illuminate\Support\Facades\Lang::has($key)) {
            return (string) __($key);
        }

        return ucfirst(str_replace('_', ' ', $kind));
    }
}
