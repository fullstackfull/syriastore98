<?php

namespace App\Services\Kyc;

use App\Models\Store;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\MerchantPermissions;

/**
 * WHO MAY TOUCH A PRIVATE IDENTITY DOCUMENT. THE ONE PLACE THAT DECIDES.
 * =================================================================================================
 *
 * THE OPERATOR COMES FIRST, AND THAT IS NOT STYLE. There is no super-admin bypass in this codebase:
 * `permissions_as_gates => false`, so `$user->can('X')` is ALWAYS false, and Laratrust's
 * `isAbleTo()` REFUSES the platform operator because operator status is a string on `users.type`
 * rather than a role. A gate that asked `isAbleTo()` first would lock the platform out of the
 * review queue it exists to work. `UserPolicy::isOperator()` is reused rather than re-derived — it
 * `rtrim`s SPACE only, to match MySQL's PAD SPACE, a distinction `trim()` gets WIDER than the engine
 * (`' super admin'` is not the operator to the database and `trim()` said it was), and
 * `OperatorCheckCensusTest` holds a ceiling against new local copies of that comparison.
 *
 * `$storeId` MUST COME FROM THE PRINCIPAL, NEVER FROM THE REQUEST, and this class gives callers the
 * tool to get it right: `ownStore()`. A predicate built from `$request->store_id` is not a tenant
 * check — it compares the caller's own two values — and CLAUDE.md records that shape producing four
 * separate defects, with the `custom.auth` variant producing thirty-one more. The merchant doors
 * resolve their store from the signed-in `User` and the operator doors take it from the document
 * ROW, so neither has a request-supplied store to get wrong.
 *
 * THREE QUESTIONS, NOT ONE, because they have different answers:
 *
 *   ownDocuments   may this principal upload and open THEIR OWN store's documents, and read the
 *                  trail of who else has? The operator, the merchant themselves, and staff holding
 *                  the permission.
 *   review         may this principal APPROVE OR REJECT somebody's papers? THE OPERATOR ONLY. A
 *                  merchant approving their own identity documents would make the whole record
 *                  worthless, so that is a safety invariant rather than a policy choice. WHICH
 *                  operator staff may review — whether a support agent should, and under what
 *                  record — is an owner decision and is named as such in the report; today the
 *                  answer is the platform operator.
 *   anyStore       may this principal see the review QUEUE across stores? The operator only, for the
 *                  same reason.
 *
 * WHY STAFF NEED AN EXPLICIT PERMISSION AND THE MERCHANT DOES NOT. `PERMISSION` is deliberately NOT
 * added to `Utility.php`'s per-store permission seeding, which runs for every store that has ever
 * been created: adding a name there grants nothing by itself but does change what every merchant's
 * role editor offers, and it would ship a permission for a feature whose policy the owner has not
 * settled. So the merchant — the type-`admin` tenant root who owns the store — is admitted by
 * OWNERSHIP, and a staff member is admitted only once the merchant creates a role carrying the
 * permission. That FAILS CLOSED for staff, which is the right direction for identity papers.
 *
 * `MerchantPermissions::allows()` AND NOT `$user->isAbleTo()` for the staff limb, because
 * `allPermissions()` unions the permissions of EVERY role a user holds with no store predicate: for
 * a merchant with two stores and one employee in each it reports the union, so store A's employee
 * would be told they hold store B's grants. That class exists to not have that defect.
 */
final class KycAccess
{
    /**
     * The permission a STAFF member needs for their own store's verification documents. Not seeded —
     * see the class docblock.
     */
    public const PERMISSION = 'Manage Store Verification';

    private function __construct()
    {
    }

    /**
     * May this principal upload, open and read the trail for THIS STORE's own documents?
     *
     * @param  int  $storeId  derived from the principal (see `ownStore()`) or from a document row —
     *                        NEVER from request input
     */
    public static function permitsOwnDocuments(mixed $user, int $storeId): bool
    {
        if (! $user instanceof User || $storeId <= 0) {
            return false;
        }

        if (UserPolicy::isOperator($user)) {
            return true;
        }

        $root = UserPolicy::merchantTenantRoot($user);

        if ($root === null) {
            return false;
        }

        /*
         * `Store` carries no global scope at all (CLAUDE.md's table), so this predicate IS the
         * tenancy check and not a belt over one. `merchantTenantRoot()` is what makes
         * `created_by` safe to compare against: for a user rooted at the platform — a merchant who
         * demoted their own `type` through the product, or staff the operator created — `creatorId()`
         * is the OPERATOR's id and a bare `created_by = creatorId()` would select the operator's
         * stores. That helper returns null for them instead.
         */
        $ownsStore = Store::query()
            ->where('id', $storeId)
            ->where('created_by', $root)
            ->exists();

        if (! $ownsStore) {
            return false;
        }

        if ((int) $user->id === $root) {
            return true;        // the merchant themselves
        }

        return MerchantPermissions::allows($user, self::PERMISSION, $storeId);
    }

    /**
     * May this principal APPROVE or REJECT? The platform operator only. See the class docblock.
     */
    public static function permitsReview(mixed $user): bool
    {
        return $user instanceof User && UserPolicy::isOperator($user);
    }

    /** May this principal see the cross-store review queue? The platform operator only. */
    public static function permitsAnyStore(mixed $user): bool
    {
        return self::permitsReview($user);
    }

    /**
     * THE STORE A MERCHANT IS ACTING IN, resolved from the principal and from nothing else.
     *
     * Reproduced from `AuditAttribution::storeFor()`'s rule 3 rather than delegated to
     * `getCurrentStore()`, and for that method's own stated reason: the helper FABRICATES store 1
     * for the platform operator (`helper.php`'s `if ($user->type == 'super admin') { return 1; }`),
     * so an operator would be handed a tenant they are not. Here the operator gets null — they have
     * no "own store" and reach documents through the review queue, by the row's store.
     *
     * A principal that is not a merchant tenant (staff of the operator, a demoted merchant) also
     * gets null rather than a guess.
     */
    public static function ownStore(mixed $user): ?int
    {
        if (! $user instanceof User || UserPolicy::isOperator($user)) {
            return null;
        }

        $root = UserPolicy::merchantTenantRoot($user);

        if ($root === null) {
            return null;
        }

        $current = (int) ($user->current_store ?: 0);

        if ($current > 0 && Store::query()->where('id', $current)->where('created_by', $root)->exists()) {
            return $current;
        }

        $store = Store::query()->where('created_by', $root)->orderBy('id')->first();

        return $store === null ? null : (int) $store->id;
    }
}
