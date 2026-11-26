<?php

namespace App\Support\SupportAccess;

/**
 * "محدد" — THE SCOPED HALF OF `SUPPORT-004`, AND THE REASON IT IS NOT DECORATIVE.
 *
 * A grant names ONE slice of the store's data. The vocabulary lives in
 * `config/support_access.php` because which slices exist is an owner question; what this class adds
 * is the one rule that makes the column load-bearing rather than documentary:
 *
 *   **ONLY `full` AUTHORISES IMPERSONATION.**
 *
 * That follows from measuring what impersonation actually does rather than from a preference.
 * `StoreController::LoginWithAdmin` calls `Impersonate::take()`, which signs the operator in AS the
 * merchant through the `web` guard — so from that moment every permission the merchant holds is
 * held by the session, and `getCurrentStore()` answers for the merchant's store everywhere. There
 * is no filter anywhere in that path that could confine an impersonated session to orders and keep
 * it out of settings. A grant for `orders` that admitted impersonation would therefore be a grant
 * for everything with "orders" written on it, which is worse than no scope at all: it would appear
 * in the merchant's consent prompt as a narrow request and deliver a broad one.
 *
 * So `coversImpersonation()` is `=== 'full'`, and a merchant consenting to `orders` has consented
 * to support being SHOWN their orders, not to support becoming them. Narrower scopes are enforced
 * by whichever read surface consults the gate with that scope name; the only door wired today is
 * impersonation, and `SupportAccessScopeTest` asserts that the four narrow scopes are all refused
 * there. That is a deliberately small first door: the alternative — claiming four scopes are
 * enforced when only one surface consults them — is the kind of claim this project's gap register
 * exists to catch.
 */
final class SupportAccessScope
{
    public const ORDERS = 'orders';

    public const CATALOGUE = 'catalogue';

    public const CUSTOMERS = 'customers';

    public const SETTINGS = 'settings';

    public const FULL = 'full';

    /**
     * The configured vocabulary.
     *
     * Read from config so the owner can answer question 3 without a code change, but ALWAYS through
     * this method: a caller that read `config('support_access.scopes')` directly would get `[]` if
     * the key were deleted, and `isKnown()` would then refuse every scope including `full`, which
     * fails closed but silently. The fallback keeps the mechanism's own invariant — that `full`
     * exists — independent of the config file.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $configured = config('support_access.scopes');

        if (! is_array($configured) || $configured === []) {
            return [self::ORDERS, self::CATALOGUE, self::CUSTOMERS, self::SETTINGS, self::FULL];
        }

        return array_values(array_filter($configured, 'is_string'));
    }

    public static function isKnown(?string $scope): bool
    {
        return $scope !== null && in_array($scope, self::all(), true);
    }

    /**
     * Does a grant for `$granted` satisfy a request for `$requested`?
     *
     * `full` satisfies everything; otherwise the names must match exactly. There is deliberately NO
     * hierarchy beyond that — no "settings implies catalogue" — because every implication is a
     * decision about what a merchant consented to when they ticked one box, and the plan states
     * none. An unknown scope on either side satisfies nothing.
     */
    public static function covers(?string $granted, ?string $requested): bool
    {
        if (! self::isKnown($granted) || ! self::isKnown($requested)) {
            return false;
        }

        return $granted === self::FULL || $granted === $requested;
    }

    /** See the class docblock: impersonation is full access, so only `full` admits it. */
    public static function coversImpersonation(?string $granted): bool
    {
        return $granted === self::FULL;
    }
}
