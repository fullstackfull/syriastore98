<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Authorization for role administration -- SEC-F3 and the RoleController limbs
 * of R4-04.
 *
 * RoleController::update was gated only on the 'Edit Role' permission, which
 * every merchant holds, and then operated on an implicitly bound Role with no
 * ownership test. Because Role declared `$guarded = []`, the subsequent
 * `$input['store_id'] = getCurrentStore(); $role->fill($input)->save();` also
 * STOLE the victim role into the attacker's store.
 *
 * Role tenancy is `roles.created_by` + `roles.store_id`, which is exactly the
 * predicate UserController::edit already applies correctly when listing roles
 * for a form -- proving omission rather than design.
 */
class RolePolicy
{
    public const PLATFORM_ADMIN = 'super admin';

    /**
     * Role names that may never be created or assigned through this controller,
     * because the application compares against these literals to decide
     * platform-operator status.
     */
    public const RESERVED_NAMES = [
        'super admin', 'superadmin', 'Super Admin', 'SuperAdmin', 'SUPER ADMIN',
        // S13-b. `admin` BELONGS HERE AND ITS ABSENCE WAS A LIVE ESCALATION.
        //
        // This list existed to stop a tenant naming a role into platform-operator status, and it
        // covered only the `super admin` spellings. But `admin` is an authority discriminator in
        // its own right -- the codebase compares users.type against 'admin' 47 times, against
        // 'super admin' 77 -- and writing it does three things at once:
        //
        //   App\Models\User::creatorId():100  returns $this->id for type 'admin', so the row
        //       becomes THE ROOT OF ITS OWN TENANT and every created_by = creatorId() predicate
        //       in the application starts answering about the actor instead of their merchant.
        //   StoreController::create():62, store():94  gate store creation on
        //       `type == 'admin'` with NO permission check at all, so the flip hands over a
        //       capability that answered "Permission denied" a moment earlier.
        //   Utility.php, helper.php, AuthenticatedSessionController and PaymentController all
        //       branch merchant-owner behaviour on the same literal.
        //
        // REPRODUCED before this change: a staff member holding 'Create Role' and 'Edit User' --
        // both grantable by any merchant -- created a tenant-scoped role named `admin`, assigned
        // it to themselves through the ALREADY-HARDENED UserController::update(), and watched
        // users.type go 'staff' -> 'admin', creatorId() go from the merchant's id to their own,
        // and GET /stores/create go from 302-deny to 200. Both of update()'s controls were
        // intact; the LIST was the hole.
        //
        // THE SEEDED MERCHANT ROLE IS ALSO LITERALLY NAMED `admin`, which sounds like a conflict
        // and is not: it carries store_id NULL, so the tenant-scoped role lookup in
        // UserController already refuses it on both write paths. Only a tenant-authored role of
        // that name was ever assignable, and that is precisely what must not be.
        //
        // The case variants are here because RoleController's validator USED to compare
        // case-SENSITIVELY while UserController lowercased first. Both consumers now go through
        // `reservedNameRule()` / `isReservedName()` below, which lowercase, so these five
        // mixed-case entries are redundancy rather than coverage. They are KEPT, not deleted:
        // removing entries from a reserved-name list is the kind of change that looks safe and is
        // not, and they cost nothing. What they must not do any more is read as protection that
        // something depends on -- see `isReservedName()`.
        'admin', 'Admin', 'ADMIN',
    ];

    /**
     * THE ONE PREDICATE, so the two gates over this invariant cannot disagree again.
     *
     * THEY DID DISAGREE, and the asymmetry was measured rather than reasoned about.
     * `RoleController::store():124` and `::update():254` validated with
     * `Rule::notIn(self::RESERVED_NAMES)`, which is CASE-SENSITIVE, while the only two
     * tenant-reachable writers of `users.type` -- `UserController:154` and `:305` -- are gated by
     * `in_array(strtolower(trim((string) $role->name)), self::RESERVED_NAMES, true)` at `:147` and
     * `:302`, which LOWERCASES THE NEEDLE and so is case-INSENSITIVE by construction. Measured
     * against the real constant, the validator let through `'Super admin'`, `'sUper admin'`,
     * `'SUPER Admin'`, `'Super ADMIN'` and `'aDmin'` while refusing `'super admin'`, `'admin'`,
     * `'Admin'` and `'ADMIN'`.
     *
     * NO ESCALATION FOLLOWED FROM IT, and that was established by execution before anything was
     * changed -- the chain breaks twice over:
     *   - engine-independently at `UserController:147`/`:302`, because the lowercasing gate
     *     collapses every variant onto one of the three lowercase entries and refuses it, so the
     *     validator was never the last line of defence;
     *   - and on PRODUCTION MySQL before that, because `roles.name` carries a UNIQUE index and
     *     `utf8mb4_0900_ai_ci` is case-insensitive, so `'Super admin'` is a duplicate-key
     *     violation against the platform's own `'super admin'`. Measured on MySQL 8.0.46:
     *     `Duplicate entry 'Super admin' for key 'roles_name_unique'`.
     *
     * AND THAT SECOND POINT IS WHY THIS DOCBLOCK IS LONG. SQLite's unique index is BINARY, so the
     * test suite ACCEPTS a row production REFUSES -- the engine hiding the truth in the SAFE
     * direction, which is the more dangerous way round: an investigation run only on the test
     * engine over-states what an attacker can do, and three independent agents duly concluded that
     * `app/Models/Utility.php:3117`'s `Role::where('name','LIKE','super admin')->first()` was "the
     * real defect" because two rows could match it. On MySQL exactly one row can ever match, so
     * that line is unambiguous in production and the ambiguity is a test-environment artefact.
     * Proof, both engines side by side:
     * `tests/Support/Security/bin/verify-reserved-role-name-collation-on-mysql.php`.
     *
     * So this change closes an ASYMMETRY, not an escalation. It is worth making because a defence
     * that only looks like one is worse than none: the next reader of `:124` would reasonably
     * assume the spelling list is what stops the name existing, and it is not.
     *
     * @return \Closure a validation rule usable anywhere `Rule::notIn` was
     */
    public static function reservedNameRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (self::isReservedName($value)) {
                $fail(__('That role name is reserved.'));
            }
        };
    }

    /**
     * The predicate itself, byte-for-byte the comparison `UserController:147` and `:302` already
     * make. Public so a test can drive it directly and so a future third consumer has one obvious
     * thing to call rather than a fourth hand-written comparison.
     *
     * `trim` then `strtolower`: `TrimStrings` is global so the outer value is already trimmed in a
     * real request, but this is also called from tests and from console contexts where it is not.
     * Interior whitespace is deliberately NOT collapsed -- `'super  admin'` is a different string
     * that matches no comparison in the application and grants nothing, and collapsing it would
     * refuse legitimate names for no gain.
     */
    public static function isReservedName(mixed $value): bool
    {
        if (! is_scalar($value)) {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), self::RESERVED_NAMES, true);
    }

    /**
     * NORMALISED, per CLAUDE.md's prescribed form and the house pattern at
     * `StoreController::resolveOwnedStore` and `BulkDeleteTenantGuard::handle`. P1-SEC-02.
     *
     * It was an exact `===`, which fails CLOSED and so was never an escalation -- but P1-SEC-02
     * changed what failing closed COSTS here, and that is why it is being normalised now rather
     * than left alone. Before: an operator row reading `'Super Admin'` or `' super admin'` missed
     * the exemption and fell through to the denylist, still able to grant 187 of 200 names.
     * After: it falls through to `tenantOwner()`, where `User::creatorId()` compares against the
     * same exact literals and so returns `created_by` -- `null` for a root operator row -- giving
     * `User::find(0)`, no owner, and NOTHING grantable. The operator would lose the role editor
     * outright.
     *
     * Widening who matches is safe because `users.type` is not client-writable: the only writers
     * are `UserController:154` and `:305` (both derived from a tenant-scoped role name and both
     * behind the `RESERVED_NAMES` refusal), `StoreController:265` (inside an operator-only
     * branch), and the registration controllers, which hardcode the value.
     *
     * Raised by adversarial review of P1-SEC-02 as speculative-pending-measurement. The live
     * `users.type` values cannot be read from here, so rather than leave an availability risk
     * resting on an unverified assumption about production data, the comparison is made
     * insensitive to case and surrounding whitespace -- which costs nothing and is what the rest
     * of the codebase already does.
     */
    protected function isPlatformAdmin(?User $actor): bool
    {
        return $actor !== null
            && strtolower(trim((string) $actor->type)) === self::PLATFORM_ADMIN;
    }

    protected function ownsRole(User $actor, Role $role): bool
    {
        $actorTenant = $actor->creatorId();

        if (empty($actorTenant) || empty($role->created_by)) {
            return false;
        }

        if ((int) $role->created_by !== (int) $actorTenant) {
            return false;
        }

        // The global `admin` role seeded by PermissionTableSeeder has a null
        // store_id and is SHARED by every self-registered merchant. It must
        // never be editable through a per-tenant screen, or one merchant
        // rewrites every merchant's role.
        if (empty($role->store_id)) {
            return false;
        }

        return (int) $role->store_id === (int) getCurrentStore();
    }

    /**
     * May the actor open the role-creation screen?
     *
     * NEW-ROLE-FORM-UNGATED-01. RoleController::create() carried no check of any
     * kind: any authenticated user could GET /roles/create and the view renders
     * `Permission::all()` (:54), i.e. the entire permission catalogue of the
     * platform, including the config/superadminaccess.php names. store() has
     * always required 'Create Role' (:67), so the form was reachable by people who
     * could not use it -- the disclosure was the whole of the exposure, but it is a
     * real one and it is the sibling-endpoint shape that SEC-F3 was.
     *
     * The permission name is NOT invented: 'Create Role' is seeded by
     * Utility::addNewData() (app/Models/Utility.php:2655 and :2910) and is the same
     * literal store() already tests. Inventing a name no seeder creates ('Edit
     * Order') was a real defect on this codebase, so this is checked rather than
     * assumed.
     */
    public function create(User $actor): bool
    {
        if ($this->isPlatformAdmin($actor)) {
            return true;
        }

        return $actor->isAbleTo('Create Role');
    }

    public function update(User $actor, Role $role): bool
    {
        if (!$actor->isAbleTo('Edit Role')) {
            return false;
        }

        if ($this->isPlatformAdmin($actor)) {
            return true;
        }

        return $this->ownsRole($actor, $role);
    }

    public function delete(User $actor, Role $role): bool
    {
        if (!$actor->isAbleTo('Delete Role')) {
            return false;
        }

        if ($this->isPlatformAdmin($actor)) {
            return true;
        }

        return $this->ownsRole($actor, $role);
    }

    /**
     * May the actor grant this permission name? P1-SEC-02 / S15-B row 8.
     *
     * THIS WAS A DENYLIST AND IS NOW POSITIVE AUTHORIZATION. The previous body was
     * `!in_array($permissionName, config('superadminaccess'))` -- thirteen platform-operator
     * names. A denylist answers *is this name forbidden* and never *is this actor entitled to
     * hand it out*, so every name nobody thought to list was grantable by anybody holding
     * `Create Role`.
     *
     * WHAT IT MISSED, measured against the application's own seeder rather than assumed.
     * `Utility::addNewData()` is the only mechanism that creates Permission rows; it seeds 200,
     * grants 181 to the merchant role and 29 to the operator role. Nineteen are outside a
     * merchant's own grant and the denylist covered only twelve. The seven it did not:
     *
     *   Add Finance Balance, Deduct Finance Balance, Edit Finance Payment Status,
     *   Request Finance Withdrawal, View Finance Dashboard, View Finance Withdrawal Requests
     *       -- the six money-moving finance permissions. Their rows exist and NO SEEDED ROLE
     *          HOLDS THEM, deliberately: Utility.php:2866-2876 records that which role holds a
     *          money power is "a privilege decision for the owner, not something to infer from a
     *          route file". A merchant ticking the box made that decision for them.
     *   Manage Email Template
     *       -- operator-only, and simply absent from the list.
     *
     * BOUNDED BY THE TENANT OWNER, NOT BY THE ACTOR, AND THE DIFFERENCE IS DELIBERATE.
     * `User::creatorId()` returns the actor's own id when `users.type` is `admin`, so for a
     * MERCHANT the two readings are identical and the rule reads exactly as the requirement does:
     * a merchant cannot grant a permission they do not themselves hold. They diverge only for
     * STAFF, where bounding by the actor would also forbid a merchant delegating role
     * composition to a staff member -- a working feature, and this project's standing instruction
     * is to prefer the narrowest change that closes the defect. Bounding by the owner closes the
     * escalation (no principal can exceed its tenant's ceiling) and leaves that delegation
     * intact. The residual -- staff composing a role up to parity with their own merchant --
     * stays inside one tenant, is strictly narrower than what this closes, and is recorded as
     * its own queue row rather than quietly chosen here.
     *
     * THE DENYLIST IS KEPT, as defence in depth rather than as the control: a platform-operator
     * name stays refused even if a tenant somehow came to hold one. Note that one of its
     * thirteen entries, `Delete Plan`, matches no Permission row at all, so the list is not a
     * reliable enumeration of anything -- another reason not to let it be the control.
     *
     * FAILS CLOSED. An actor whose tenant owner cannot be resolved grants nothing.
     */
    public function grantPermission(User $actor, string $permissionName): bool
    {
        if ($this->isPlatformAdmin($actor)) {
            return true;
        }

        $owner = $this->tenantOwner($actor);

        return $this->isGrantableBy($permissionName, $this->heldBy($owner));
    }

    /**
     * The subset of $candidateNames this actor may grant — the same rules, resolved once.
     *
     * WHY THIS EXISTS, MEASURED. The role editor asks the question once per permission per
     * module tab, and the first version of the P1-SEC-02 view change called the Gate each time.
     * On a 200-permission table that measured **601 GateEvaluated events and 1,742 database
     * queries** for one `GET /roles/create`, against **1 event and 14 queries** before the
     * change — a 124x query regression on a modal a merchant opens routinely, and invisible to
     * the suite because `SecurityTestCase` disables the Laratrust cache and the fixtures hold
     * nine permissions. `Gate::resolvePolicy()` builds a fresh policy instance per call
     * (framework `Gate.php:734`) and fires an event per call (`:457`), and for STAFF the old
     * shape re-ran `User::find()` inside every one of them.
     *
     * So the bulk question resolves the tenant owner ONCE and then asks the same per-name
     * predicate the single-name path asks. `grantPermission()` remains the one decision point
     * for the WRITE path (`filterGrantablePermissions`, a handful of ids per request); this is
     * the same decision for the READ path, not a second copy of it — both end in
     * `isGrantableBy()`.
     *
     * @param  list<string>  $candidateNames
     * @return list<string>
     */
    public function grantableNames(?User $actor, array $candidateNames): array
    {
        if ($actor === null) {
            return [];
        }

        if ($this->isPlatformAdmin($actor)) {
            return array_values($candidateNames);
        }

        $owner = $this->tenantOwner($actor);
        $held = $this->heldBy($owner);

        return array_values(array_filter(
            $candidateNames,
            fn (string $name): bool => $this->isGrantableBy($name, $held)
        ));
    }

    /**
     * The per-name rule, shared by the single-name and bulk paths so they cannot drift.
     *
     * Order matters and is asserted: the denylist is consulted BEFORE the positive limb, so a
     * platform-operator name stays refused even for a tenant that somehow came to hold one.
     */
    private function isGrantableBy(string $permissionName, array $ownerHeld): bool
    {
        if (in_array($permissionName, (array) config('superadminaccess', []), true)) {
            return false;
        }

        return in_array($permissionName, $ownerHeld, true);
    }

    /**
     * Every permission NAME the tenant owner holds, fetched once.
     *
     * EXACT MEMBERSHIP RATHER THAN `isAbleTo()`, and that is a deliberate tightening.
     * `isAbleTo($name)` resolves through `Str::is($requested, $held)` in Laratrust's checkers
     * (`UserDefaultChecker.php:124`, `RoleDefaultChecker.php:42`) -- note the argument order:
     * THE REQUESTED NAME IS THE PATTERN and the held name is the subject. A `permissions.name`
     * containing `*` or `?` would therefore have matched any held permission and cleared the
     * ceiling, while the denylist's `in_array(..., true)` above would not have caught it.
     *
     * Not reachable today -- nothing under `app/` creates a Permission row from request input,
     * the only writers are `Utility::addNewData()` and the module seeders, and Laratrust's own
     * panel is unrouted (`config/laratrust.php` `panel.register => false`) -- but a ceiling that
     * can be cleared by a wildcard in data is not a ceiling, and exact membership costs nothing.
     * So this closes it rather than recording it.
     *
     * It also makes the two paths structurally identical: one fetch, then `in_array` on both the
     * single-name and bulk routes, so the form and the write path cannot differ in MATCHING
     * SEMANTICS either -- not just in rules.
     *
     * @return list<string>
     */
    private function heldBy(?User $owner): array
    {
        if ($owner === null) {
            return [];
        }

        return $owner->allPermissions(['name'])
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The root of the actor's tenant: themselves for a merchant, their merchant for staff.
     *
     * `creatorId()` is the application's own answer to this question (`User::creatorId()`
     * returns `$this->id` for type `admin`/`super admin`, else `created_by`), so this follows it
     * rather than introducing a second notion of tenancy.
     *
     * FOR A USER THE PLATFORM OPERATOR CREATED, THE OWNER *IS* THE OPERATOR. `UserController:159`
     * stamps `created_by = Auth::user()->id`, so a non-admin user created through the operator's
     * own `/users` screen has the operator as its tenant owner and its ceiling is the operator's
     * grant minus the denylist -- roughly sixteen names such as `Manage Plan`, `Manage Setting`
     * and the Coupon and User groups. That is NOT an escalation above a tenant ceiling (the
     * operator IS their tenant) and it is strictly NARROWER than before this change, when the
     * same principal could grant anything not denylisted, the finance six included. It is spelled
     * out because the sentence "no principal can exceed its tenant's ceiling" is only true if
     * "tenant" is read as including the platform. Raised by adversarial review; deliberately NOT
     * special-cased, because an operator-owner exception would be a wider change than the finding
     * warrants and would break legitimate operator-side delegation.
     *
     * THIS CONTROL IS COUPLED TO `RESERVED_NAMES`, and the coupling should be visible. Staff at
     * parity with their merchant inherit `Create Role` and `Edit User` -- precisely the S13-b
     * escalation kit. It is contained only because `RESERVED_NAMES` (`:64`) refuses a
     * tenant-authored role named `admin`; if that list regressed, owner-bounding would again let
     * a staff member mint a tenant root. Do not relax one without re-reading the other.
     */
    private function tenantOwner(User $actor): ?User
    {
        $ownerId = (int) $actor->creatorId();

        if ($ownerId === (int) $actor->id) {
            return $actor;
        }

        return User::find($ownerId);
    }
}
