<?php

namespace App\Support\Accounts;

use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\User;

/**
 * THE ONE PLACE THAT ANSWERS "IS THIS PRINCIPAL SUSPENDED?", FOR EVERY GUARD AND EVERY TOKEN.
 *
 * MEASURED BEFORE THIS CLASS EXISTED: suspension was enforced at exactly two places in the whole
 * application, and both are login FORMS.
 *
 *   `Auth\AuthenticatedSessionController:68`  $user->is_active == 0        (web form)
 *   `Auth\AuthenticatedSessionController:72`  $user->is_enable_login == 0  (web form)
 *   `Customer\Auth\CustomerLoginController:862`  $customer->status == 1 to proceed (storefront form)
 *
 * NOWHERE ELSE. `CustomAuth` (`app/Http/Middleware/CustomAuth.php:60-91`) resolves the Sanctum
 * token, checks only `expires_at` and that the tokenable is non-null, then calls
 * `auth(<guard>)->login($tokenable)`. And CLAUDE.md records that Sanctum tokens in this platform
 * NEVER EXPIRE (there is no `config/sanctum.php`), so `expires_at` is null for every token ever
 * minted. So disabling an account stopped the next SIGN-IN and nothing else: an existing session
 * kept working, and an existing token kept working forever.
 *
 * THIS CLASS IS A MIRROR, NOT A NEW POLICY. Each branch reproduces its own login form's semantics,
 * including the comparisons, so that the per-request answer and the form's answer cannot drift.
 * `AccountSuspensionMatchesTheLoginFormsTest` asserts that equivalence over a table of values
 * rather than trusting this docblock.
 *
 * THE COLUMNS, READ OFF THE BOOTED SCHEMA RATHER THAN THE MIGRATIONS (CLAUDE.md: "the migrations
 * are not the schema"):
 *
 *   users.is_active        varchar  NOT NULL  DEFAULT '1'
 *   users.is_enable_login  integer  NOT NULL  DEFAULT '1'
 *   customers.status       integer  NOT NULL  DEFAULT '1'
 *
 * ALL THREE ARE NOT NULL WITH A DEFAULT OF 1, which is the fact that makes per-request enforcement
 * safe: there is no legacy row whose null flag would suddenly read as suspended and lock out a live
 * account. That was checked first, because getting it wrong locks every merchant out of the
 * platform.
 *
 * `is_active` IS A VARCHAR AND THE COMPARISON STAYS LOOSE. The form writes `== 0`, and in PHP 8
 * that is true for `'0'` and `0` and false for `'1'`, `''` and any other string. A stricter
 * `(int) === 0` would also treat `''` as suspended, which the form does not -- so the loose
 * comparison is the faithful one, and the equivalence test pins it.
 */
final class AccountSuspension
{
    /**
     * Why this principal may not act, or null when it may.
     *
     * @return string|null a short reason for a log and for tests; never rendered to the principal
     */
    public static function reasonFor(mixed $principal): ?string
    {
        if ($principal instanceof User) {
            return self::reasonForUser($principal);
        }

        if ($principal instanceof Customer) {
            /*
             * Mirrors `CustomerLoginController:862`, which requires `status == 1` to proceed -- so
             * anything that is not 1 is suspended, not merely 0. Stated that way round because the
             * form is, and because a future third value (2 = pending?) must then refuse rather than
             * silently admit. An ABSENT attribute is handled by `flagSays()` below, for the reason
             * set out there.
             */
            return self::flagIsSet($principal, 'status') && $principal->status != 1
                ? 'the customer account is disabled'
                : null;
        }

        if ($principal instanceof DeliveryBoy) {
            /*
             * THERE IS NOTHING TO ENFORCE, AND THAT IS MEASURED, NOT ASSUMED. The real column list
             * of `delivery_boys` is: id, name, email, profile_image, type, password, contact,
             * created_by, store_id, created_at, updated_at, commission_percentage,
             * is_commission_enabled. No `is_active`, no `is_enable_login`, no `status`.
             *
             * SO A DRIVER CANNOT BE SUSPENDED AT ALL. That is a schema gap plus a product question
             * -- whether a store may suspend a driver, and what it should do to work already
             * assigned -- and inventing a column here would be inventing policy. It is recorded for
             * the owner and pinned by `DeliveryBoyHasNoSuspensionFlagTest`, so that if a column is
             * ever added this branch fails instead of silently admitting a suspended driver.
             */
            return null;
        }

        /*
         * A NULL PRINCIPAL HAS NO SUSPENSION STATE. It is not suspended, it is unauthenticated, and
         * that is a different question answered by a different middleware. Callers must not read
         * `isSuspended(null) === false` as "may act".
         */
        if ($principal === null) {
            return null;
        }

        /*
         * ANY OTHER TYPE FAILS CLOSED. A tokenable this class has never heard of is not something to
         * wave through: `CustomAuth` dispatches exactly three classes, so a fourth arriving here
         * means a new principal type was added without deciding how it is suspended. Returning null
         * would be the one way this class could widen access.
         */
        return 'the principal type cannot be checked for suspension';
    }

    public static function isSuspended(mixed $principal): bool
    {
        return self::reasonFor($principal) !== null;
    }

    /**
     * Is the flag PRESENT on this model instance at all?
     *
     * A MISSING ATTRIBUTE IS NOT A SUSPENDED ACCOUNT, AND GETTING THAT BACKWARDS WOULD HAVE BEEN A
     * PLATFORM-WIDE OUTAGE. All three columns are NOT NULL with a default of 1, verified against the
     * booted schema -- so a null here cannot mean "the operator suspended this account". It can only
     * mean the attribute was never loaded onto this particular instance: a model built by a factory,
     * a partial `select('id', 'email')`, a freshly `new`-ed object.
     *
     * AND IN PHP 8 `null == 0` IS TRUE. The first version of this class compared
     * `$user->is_enable_login == 0` directly, mirroring the login form literally -- and an ordinary
     * ACTIVE merchant read as suspended, because `User::factory()` sets neither flag and the column
     * default applies to the stored ROW, not to the in-memory model. Measured: an active merchant
     * fixture was redirected to `/login`, and the control test failed before any suspension was
     * applied. The blast radius, had it reached the suite: 425 Feature test files and 3,114
     * `actingAs(` call sites, none of whose fixtures set either flag.
     *
     * THE LOGIN FORM HAS THE SAME LITERAL BEHAVIOUR AND IT IS HARMLESS THERE, which is why copying
     * it was not enough: `AuthenticatedSessionController` only ever sees a row it just loaded with
     * `User::where('email', ...)->first()`, so its flags are always the stored 1 or 0. This class is
     * called on whatever instance a caller happens to hold, which is a strictly wider contract.
     *
     * The deliberate consequence: a principal whose flag is genuinely unknown is ADMITTED. That is
     * the right direction for an availability control whose false positive is "nobody can use the
     * platform", and it opens nothing -- a suspended row carries `0`, never null, and both seams
     * that call this (`CustomAuth` via the tokenable, the web guard via `auth()->user()`) load the
     * full row.
     */
    private static function flagIsSet(mixed $model, string $attribute): bool
    {
        return $model->getAttribute($attribute) !== null;
    }

    /** The flag is present AND equals `$value`, using the login form's own loose comparison. */
    private static function flagSays(mixed $model, string $attribute, mixed $value): bool
    {
        $actual = $model->getAttribute($attribute);

        return $actual !== null && $actual == $value;
    }

    /**
     * The `User` branch, mirroring both of the web form's own checks.
     */
    private static function reasonForUser(User $user): ?string
    {
        /*
         * THE OPERATOR IS EXEMPT, AND THE COMPARISON IS NORMALISED WHERE THE FORM'S IS NOT.
         *
         * The form compares `$user->type != 'super admin'` raw. This normalises case and
         * whitespace, so it exempts MORE spellings than the form does. That is a deliberate,
         * fail-safe divergence: an operator refused on EVERY REQUEST cannot sign in to undo it, and
         * CLAUDE.md records a letter-case reconciliation defect (P5-R9) as a real one in this
         * codebase. The cost of being permissive here is bounded to "a row whose `type` spells
         * super admin in another case keeps access", and that row is the platform operator by
         * definition. The cost of being strict is bricking the platform.
         */
        if (strtolower(trim((string) $user->type)) === 'super admin') {
            return null;
        }

        if (self::flagSays($user, 'is_active', 0)) {
            return 'the account is deactivated';
        }

        if (self::flagSays($user, 'is_enable_login', 0)) {
            return "the account's login is disabled";
        }

        /*
         * THE FORM HAS A THIRD CHECK AND IT IS DELIBERATELY NOT MIRRORED HERE.
         *
         * `AuthenticatedSessionController:72`'s second limb is
         * `$companyUser->is_enable_login == 0`, where
         * `$companyUser = User::where('id', $user->created_by)->first()` -- the CREATOR's flag, with
         * NO operator exemption on that limb. Enforcing it per-request would be wrong in a specific
         * way:
         *
         *   - Every first-party creation site writes `created_by = 1`
         *     (`RegisteredUserController:133`, `:286`, `StoreController:280`) and user 1 is the
         *     operator. So this would consult the OPERATOR'S OWN `is_enable_login` on every
         *     authenticated request by every merchant, turning one row's flag into a platform-wide
         *     kill switch. At the login form it only blocks a new sign-in.
         *   - CLAUDE.md records reading `created_by` as authority as the exact trap that produced an
         *     account takeover here. Amplifying its blast radius from "one login" to "every request"
         *     is the wrong direction.
         *   - It is already redundant where it matters: `UserController:915` cascades
         *     `is_enable_login` to the store's non-admin users DIRECTLY, so a disabled merchant's
         *     staff have their own flag at 0 and are caught by the two checks above.
         *
         * Stated as a difference rather than hidden, and pinned by a test asserting this class does
         * NOT consult `created_by`. If the owner wants it per-request, it is a one-line addition --
         * but it needs that decision first.
         */

        return null;
    }
}
