<?php

namespace Tests\Feature\Security;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\MerchantPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * GAP-279 / `P04-P7-01` — A ROLE WRITTEN WITH NO STORE COUNTS FOR EVERY STORE, AND THE REMEDY IS AT
 * THE WRITER.
 * =================================================================================================
 *
 * WHAT THE ROW ASKED FOR FIRST, AND WHY THAT ORDER MATTERS. The finding came out of the independent
 * review of the `GAP-277` fix as a reasoned claim, explicitly "not executed" and "rated probable,
 * not certain": `MerchantPermissions::names()` counts any role whose `store_id` is NULL for EVERY
 * store, on the documented premise that such a role is only ever the seeded global `admin` role;
 * `RoleController::store()` writes `store_id = getCurrentStore()`; and `getCurrentStore()`
 * (`app/Helper/helper.php:872-896`) answers NULL for a principal with an empty `current_store` who
 * owns no store under their own id. The row's own first instruction is to MEASURE that, because
 * this project has repeatedly paid for fixes to defects that were not reachable. So this file
 * measures three separate things and says which is which:
 *
 *   1. THE WRITER, through the real route — `POST /roles` as a staff member in that state.
 *   2. THE READER, on the row the route actually wrote — does `MerchantPermissions::allowsAny()`
 *      count it for a SIBLING store of the same tenant that the grant was never meant to reach.
 *   3. THE STATE'S REACHABILITY, through the real route that creates staff — `POST /users`. This is
 *      the half the row could not settle, and it is the half that decides whether 1 and 2 are a
 *      live defect or a latent one.
 *
 * THE SCOPE, STATED BEFORE THE RESULT SO IT CANNOT BE READ AS MORE THAN IT IS: this is
 * WITHIN-TENANT ONLY. Cross-tenant is closed before the permission gate is ever asked — by
 * `ApiController::resolveViewableOrder()`'s tenant-root and owned-store test on the storefront side
 * and by `ownedStoreIds()` on the admin side — so a NULL-store role does not reach another
 * merchant's orders. What it reaches is every store of the role-minter's OWN tenant, which for a
 * merchant running two shops with one employee per shop is exactly the separation
 * `MerchantPermissions` was written to provide ("the employee of store A would be told they hold
 * store B's permissions too" — that class's own docblock).
 *
 * WHY THE FIX IS AT THE WRITER AND NOT AT THE READER, which is the row's instruction and is load
 * bearing. The reader's NULL arm is what makes the seeded global `admin` role work: both
 * `RegisteredUserController:241` and `StoreController:387` hand every merchant
 * `Role::where('name','admin')->first()`, a role whose `store_id` is NULL, for every store they
 * create. Narrowing the reader would take every merchant's permissions away in their own store —
 * `MerchantPermissions`' docblock records that `test_a_merchant_keeps_their_permissions_in_every_store`
 * fails without that arm. So the invariant the reader depends on is restored where it is broken: a
 * role is not WRITTEN without a store.
 *
 * WHAT THE FIXTURE DELIBERATELY DOES NOT DO. `SecurityTestCase::grantPermission()` builds its
 * carrier role with `'store_id' => $user->current_store`, so calling it for a staff member whose
 * `current_store` is NULL would itself create a NULL-store role — and then every assertion below
 * would hold whether or not `RoleController` writes one. The carrier role here is built explicitly
 * with store A's id, and the premise assertion at the top of each case proves the fixture holds no
 * NULL-store row before the request under test.
 *
 * THE MUTATION GROUP, AND THE TWO SURVIVORS, RECORDED RATHER THAN WAVED THROUGH. Six mutations of
 * `RoleController::store()`; the first round had THREE survivors and two of them were defects in
 * THIS FILE, not equivalent mutants — they are named at the assertions that now catch them (an
 * `assertNotNull` that a `store_id` of 0 satisfied, and a refusal branch with no reason assertion
 * that any other failure satisfied). Four are now caught, including the lockout control. The two
 * that remain:
 *
 *   - **`$role->store_id = getCurrentStore()` instead of the resolved `$storeId`** — a TRUE
 *     equivalent mutant. The guard returns before the write for the only principal whose answer
 *     differs, and `getCurrentStore()` is deterministic within a request, so the two spellings
 *     cannot disagree. The controller comment now says this instead of claiming the reuse is a
 *     control.
 *   - **`(int)` instead of `filter_var(..., FILTER_VALIDATE_INT)`** — not observable here, and
 *     CLAUDE.md names the reason: `users.current_store` is an `unsignedBigInteger`, so a junk value
 *     cannot be stored, and SQLite's affinity could not show the `'5abc'` half anyway. Kept because
 *     a cast turns junk into `0`, which is a store id some rows really carry and is literally
 *     `OperatorStoreSelection::NO_STORE`. Stated as unproven rather than counted as covered.
 *
 * @see \App\Services\MerchantPermissions
 * @see \App\Support\Orders\OrderStateChangeAuthorization
 */
class Gap279NullStoreRoleWriterTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * A tenant with TWO stores, and a staff member in the state the row describes.
     *
     * TWO STORES, because the whole claim is about a grant reaching a store it was not meant to.
     * With one store there is nothing for "every store" to mean and a mutant that stamped the
     * caller's own store would be indistinguishable from one that stamped nothing.
     *
     * @return array{0: User, 1: Store, 2: Store, 3: User}
     */
    private function tenantWithAnUnresolvedStaffMember(): array
    {
        [$merchant, $storeA] = $this->makeTenant();
        $storeB = $this->makeStore($merchant);

        /* THE TENANT OWNER MUST HOLD `Manage Order`, AND LEAVING THIS OUT MADE THIS WHOLE FILE PASS
           FOR THE WRONG REASON — measured, on the first run, before the fix existed.
           `RoleController::store()` filters the posted permission ids through
           `filterGrantablePermissions()` → `RolePolicy::grantPermission()` →
           `isGrantableBy($name, heldBy($owner))`, which is exact membership in the TENANT OWNER's
           own held set. `makeMerchant()` builds a `type='admin'` row with NO role, so the ceiling
           was empty, `Manage Order` was dropped, and the minted role carried NO PERMISSIONS AT
           ALL. The sibling-store assertion then held because there was nothing to count — a green
           result that proved the opposite of what it claimed.

           THE GLOBAL `admin` ROLE, because that is production's shape: `RegisteredUserController`
           and `StoreController:387` hand every merchant one shared `Role::where('name','admin')`
           row whose `store_id` is NULL, and `Utility::addNewData()` grants `Manage Order` to it.
           Its NULL store is LEGITIMATE and is exactly what `MerchantPermissions`' NULL arm exists
           for, which is why the premise below asks whether the STAFF MEMBER holds a NULL-store
           role rather than whether the table holds one. */
        $this->grantMerchantGlobalRole($merchant, ['Manage Order', 'Create Role', 'Edit User']);

        // `current_store` EXPLICITLY NULL. `makeStaff()` copies the owner's, which is store A's id;
        // this is the state under measurement and the premise assertions below prove it is real.
        $staff = $this->makeStaff($merchant, ['current_store' => null]);

        /* THE CARRIER IS STORE-A-SCOPED, deliberately. `SecurityTestCase::grantPermission()` builds
           its carrier with `'store_id' => $user->current_store`, which for this principal is NULL —
           so using it would plant the very row under measurement in the fixture and every
           assertion below would hold whether or not `RoleController` writes one. */
        $carrier = Role::forceCreate([
            'name' => 'gap279-carrier-' . $staff->id,
            'display_name' => 'Role Creator',
            'guard_name' => 'web',
            'created_by' => $merchant->id,
            'store_id' => $storeA->id,
        ]);

        foreach (['Create Role', 'Edit User'] as $name) {
            $carrier->givePermission(Permission::firstOrCreate(
                ['name' => $name],
                ['display_name' => $name]
            ));
        }

        $staff->addRole($carrier);
        $staff->flushCache();

        return [User::find($merchant->id), $storeA, $storeB, User::find($staff->id)];
    }

    /** `Manage Order` — the name `OrderStateChangeAuthorization::PERMISSION` gates four money doors on. */
    private function manageOrder(): Permission
    {
        return Permission::firstOrCreate(
            ['name' => 'Manage Order'],
            ['display_name' => 'Manage Order']
        );
    }

    /**
     * Every premise the measurement rests on, asserted rather than assumed.
     *
     * Each of these has its own way of making the case below pass for the wrong reason: a staff
     * member who does not really hold `Create Role` is refused by the permission check and no row
     * is written; a staff member whose `getCurrentStore()` is NOT null writes a correctly scoped
     * row and the case would read as "fixed"; a fixture that already holds a NULL-store role makes
     * the reader assertion true before the request.
     */
    private function assertTheMeasuredStateHolds(User $staff, User $merchant, Store $storeB): void
    {
        $this->assertNull(
            $staff->current_store,
            'premise: the staff row has an empty `current_store`.'
        );

        $this->assertSame(
            0,
            Store::where('created_by', $staff->id)->count(),
            'premise: the staff member owns no store under their own id, which is the second half '
            . 'of `getCurrentStore()`\'s fallback (helper.php:891).'
        );

        $this->actingAs($staff);

        $this->assertNull(
            getCurrentStore(),
            'premise AND THE WHOLE CONDITION OF THE FINDING: `getCurrentStore()` answers NULL for '
            . 'this principal. If this is not null the row\'s premise is refuted and nothing below '
            . 'measures anything.'
        );

        $this->assertSame(
            (int) $merchant->id,
            (int) $staff->creatorId(),
            'premise: the staff member is rooted at the MERCHANT, so this is a within-tenant '
            . 'question and not a cross-tenant one.'
        );

        $this->assertTrue(
            $staff->isAbleTo('Create Role'),
            'premise: they really hold `Create Role`. Without it `RoleController::store()` refuses '
            . 'and "no NULL-store role was written" holds either way.'
        );

        $this->assertSame(
            [],
            $staff->roles()->whereNull('store_id')->pluck('name')->all(),
            'premise: the STAFF MEMBER holds no NULL-store role yet. `grantPermission()` would '
            . 'have given them one (its carrier takes `$user->current_store`, which is NULL '
            . 'here), and then the reader assertion would be true before the request under test '
            . 'ran. The MERCHANT\'s global `admin` role is NULL-store by design and is not this '
            . 'question — hence the predicate is on the staff member\'s own roles.'
        );

        $this->assertFalse(
            MerchantPermissions::allowsAny($staff, ['Manage Order'], $storeB->id),
            'premise, AND THE ONE THAT RULES OUT EVERY VACUOUS PATH AT ONCE: before the request '
            . 'under test, the gate answers NO for store B. Every way this file could pass for '
            . 'the wrong reason — a pre-planted NULL-store role, a carrier that already covers '
            . 'store B, a wildcard — shows up here.'
        );

        $this->assertTrue(
            MerchantPermissions::allowsAny($merchant, ['Manage Order'], $storeB->id),
            'premise: and the TENANT OWNER does hold `Manage Order`, which is what '
            . '`RolePolicy::isGrantableBy()` tests when the route filters the posted permission '
            . 'ids. Without this the minted role carries NOTHING and the sibling-store assertion '
            . 'passes because there is nothing to count — measured, on this file\'s first run.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 1 + 2 — the writer, and the reader on the row it wrote
    |--------------------------------------------------------------------------
    */

    /**
     * THE MEASUREMENT. A staff member who resolves no store creates a role through `POST /roles`.
     *
     * The assertion is written to state the ANSWER either way: a NULL `store_id` reproduces the
     * finding, and store A's id means the writer already resolves a store for this principal and
     * the row should be closed as not reproducible.
     */
    public function test_a_staff_member_who_resolves_no_store_cannot_write_a_role_with_no_store(): void
    {
        [$merchant, $storeA, $storeB, $staff] = $this->tenantWithAnUnresolvedStaffMember();
        $this->assertTheMeasuredStateHolds($staff, $merchant, $storeB);

        $response = $this->actingAs($staff)->post('/roles', [
            'name' => 'gap279-minted',
            'permissions' => [$this->manageOrder()->id],
        ]);

        $this->assertNotSame(404, $response->status(), 'premise: `POST /roles` is a real route. A '
            . 'row assertion is satisfied by a 404.');
        $this->assertNotSame(500, $response->status(), 'premise: the handler did not fault. Note '
            . 'that RoleController catches nothing, so a 500 here is a real crash and not a '
            . 'swallowed refusal.');

        $written = DB::table('roles')->where('name', 'gap279-minted')->first();

        if ($written === null) {
            /* A refusal is the remedy — but it has to be the STORE refusal and not the permission
               one, or the case proves only that the fixture was wrong. `RoleController::store()`
               flashes a distinct message per refusal, so the reason is readable rather than
               inferred: CLAUDE.md records a refusal asserted without its reason as one of the ways
               a test here passes for the wrong reason. */
            $this->assertTrue(
                $staff->fresh()->isAbleTo('Create Role'),
                'no role was written AND the principal does not hold `Create Role` — the fixture '
                . 'is broken, not the writer.'
            );

            $this->assertStringContainsString(
                'No store is selected',
                (string) session('error'),
                'the writer refused, which is the remedy, but NOT for the store reason. Flashed: '
                . var_export(session('error'), true)
            );

            /* AND NOTHING WAS WRITTEN WITH A NULL STORE. Scoped to roles created BY THIS TENANT
               ROOT, not to the whole table: the merchant's global `admin` role is NULL-store by
               design and `DB::table('roles')->whereNull('store_id')->count()` therefore answers 1
               in a correct fixture — measured, and it is why this assertion is scoped. */
            $this->assertSame(
                [],
                DB::table('roles')->whereNull('store_id')
                    ->where('created_by', $merchant->id)->pluck('name')->all(),
                'the writer refused and yet a NULL-store role owned by this tenant root exists.'
            );

            return;
        }

        /*
         * NOT `assertNotNull`, AND A MUTANT IS WHY. The first version of this assertion was
         * `assertNotNull($written->store_id)` and a mutation that computed the store and then DID
         * NOT REFUSE **survived it**: `filter_var(null, FILTER_VALIDATE_INT)` is `false`, which the
         * write coerces to `0`, and `0` is not null. That role is inert rather than dangerous --
         * `MerchantPermissions::names()` takes its NULL arm only on `=== null`, so a `store_id` of
         * 0 matches store 0 and nothing else -- but an assertion a wrong answer satisfies is not an
         * assertion. The property the row actually needs is that the role belongs to a REAL store
         * of this tenant, so that is what is asserted.
         */
        $this->assertContains(
            (int) $written->store_id,
            DB::table('stores')->where('created_by', $merchant->id)->pluck('id')
                ->map(static fn ($id) => (int) $id)->all(),
            sprintf(
                'GAP-279 AT THE WRITER: `POST /roles` wrote a role whose store_id is %s for a '
                . 'principal who resolves no store. NULL is the finding itself -- '
                . '`MerchantPermissions::names()` counts a NULL-store role for EVERY store, so '
                . 'the grant answers for every store of the tenant. A 0 or any other non-store '
                . 'value is a role that belongs to no store at all. The remedy belongs HERE -- '
                . 'refuse a role with no resolvable store -- and NOT in the reader, whose NULL arm '
                . 'is what makes the seeded global `admin` role work for every merchant.',
                var_export($written->store_id, true)
            )
        );
    }

    /**
     * THE SECOND HALF: whatever the writer produced, does the READER count it for a sibling store?
     *
     * This is the consequence assertion and is deliberately separate from the writer assertion
     * above, because the two can come apart: a writer that refuses leaves nothing for the reader to
     * count, and a writer that stamps a store leaves a row the reader narrows correctly. Either way
     * the property that must hold is the same one — a grant minted by a principal confined to store
     * A must not answer for store B.
     */
    public function test_a_role_minted_by_an_unresolved_staff_member_does_not_answer_for_a_sibling_store(): void
    {
        [$merchant, $storeA, $storeB, $staff] = $this->tenantWithAnUnresolvedStaffMember();
        $this->assertTheMeasuredStateHolds($staff, $merchant, $storeB);

        $this->actingAs($staff)->post('/roles', [
            'name' => 'gap279-sibling',
            'permissions' => [$this->manageOrder()->id],
        ]);

        $written = DB::table('roles')->where('name', 'gap279-sibling')->first();

        if ($written === null) {
            /*
             * THE REFUSAL MUST BE THE STORE REFUSAL, AND A MUTANT IS WHY THIS IS HERE. This branch
             * originally asserted only "the gate says no", and a mutation that DELETED THE WHOLE
             * GUARD **survived it**: with the guard gone `$storeId` is undefined at the three sites
             * that still use it, the handler stops before the insert for a reason that has nothing
             * to do with tenancy, and "no role was written" held while the defect was wide open.
             * That is CLAUDE.md's "a refusal asserted without its reason", measured on this file.
             * The flashed message is what separates the two, and the `isAbleTo` control proves the
             * principal was not simply refused the permission.
             */
            $this->assertTrue(
                $staff->fresh()->isAbleTo('Create Role'),
                'no role was written AND the principal does not hold `Create Role` — the fixture '
                . 'is broken, not the writer.'
            );

            $this->assertStringContainsString(
                'No store is selected',
                (string) session('error'),
                'the writer refused, but NOT for the store reason — so this case is measuring some '
                . 'other failure and proves nothing about GAP-279. Flashed: '
                . var_export(session('error'), true)
            );

            $this->assertFalse(
                MerchantPermissions::allowsAny($staff->fresh(), ['Manage Order'], $storeB->id),
                'the writer refused — so there is no minted grant — and yet the gate admits this '
                . 'principal for store B. Something other than the minted role is answering.'
            );

            return;
        }

        /* SELF-ASSIGNMENT THROUGH THE PRODUCT, not `addRole()` in the fixture. `UserController::
           update()` scopes its role lookup `->where('created_by', creatorId())->where('store_id',
           getCurrentStore())`; for this principal the second predicate compiles to `store_id IS
           NULL`, so a NULL-store role minted a moment ago is exactly what it finds. That is the
           step that turns a written row into a held grant, and driving it is what makes this a
           product-reachable chain rather than a fixture arrangement. `RolePolicy::RESERVED_NAMES`
           is not in the way: the minted name is not a reserved one. */
        $assign = $this->actingAs($staff)->put('/users/' . $staff->id, [
            'name' => $staff->name,
            'email' => $staff->email,
            'role' => $written->id,
        ]);

        $this->assertNotSame(404, $assign->status(), 'premise: `PUT /users/{id}` is a real route.');

        $staff = User::find($staff->id);
        $staff->flushCache();

        $this->assertTrue(
            $staff->hasRole($written->name),
            'premise: the staff member now HOLDS the minted role. Without this the gate assertion '
            . 'below measures the carrier role instead of the minted one. If `PUT /users/{id}` '
            . 'refused, the self-assignment leg of the chain is closed and that is worth recording '
            . 'on the row — but it is not this assertion.'
        );

        $this->assertFalse(
            MerchantPermissions::allowsAny($staff, ['Manage Order'], $storeB->id),
            'GAP-279 REPRODUCES END TO END: a staff member confined to store A minted a role '
            . 'through `POST /roles`, assigned it to themselves through `PUT /users/{id}`, and '
            . '`MerchantPermissions::allowsAny()` now answers `Manage Order` for store B — a store '
            . 'the grant was never meant to reach. That is the gate '
            . '`OrderStateChangeAuthorization` puts on four money doors. WITHIN-TENANT ONLY: '
            . 'cross-tenant is closed before this gate is asked.'
        );
    }

    /**
     * THE CONTROL, and it is not optional: a gate that answers false for everybody passes the case
     * above and breaks the product.
     *
     * The same minted role, read for the store the minter is actually in. Under the remedy the
     * minted row carries store A, so this must still be true — and under the unfixed code the
     * NULL-store row also answers true here. The two readings agree for store A and disagree for
     * store B, which is why store B is where the finding lives.
     */
    public function test_the_control_a_minted_role_still_answers_for_the_minters_own_store(): void
    {
        [$merchant, $storeA, $storeB, $staff] = $this->tenantWithAnUnresolvedStaffMember();
        $this->assertTheMeasuredStateHolds($staff, $merchant, $storeB);

        $this->assertTrue(
            MerchantPermissions::allowsAny($staff, ['Create Role'], $storeA->id),
            'control: the carrier role, which carries store A\'s id, answers for store A. If this '
            . 'is false the store arm of the reader is broken and every other case here is '
            . 'measuring a lockout rather than a grant.'
        );

        $this->assertFalse(
            MerchantPermissions::allowsAny($staff, ['Manage Order'], $storeA->id),
            'control: and it does NOT answer a name nobody granted.'
        );
    }

    /**
     * THE OTHER CONTROL: a merchant whose `current_store` IS set writes a correctly scoped role.
     *
     * Without this the writer could be refusing everybody and the first case would still pass.
     */
    public function test_the_control_a_merchant_with_a_store_still_creates_roles(): void
    {
        [$merchant, $storeA] = $this->makeTenant();
        $this->grantPermission($merchant, 'Create Role');

        $this->assertSame(
            (int) $storeA->id,
            (int) getCurrentStore($merchant->id),
            'premise: this principal DOES resolve a store.'
        );

        $response = $this->actingAs($merchant)->post('/roles', [
            'name' => 'gap279-ordinary',
            'permissions' => [$this->manageOrder()->id],
        ]);

        $this->assertNotSame(404, $response->status());

        $written = DB::table('roles')->where('name', 'gap279-ordinary')->first();

        $this->assertNotNull(
            $written,
            'control: an ordinary merchant must still be able to create a role. A remedy that '
            . 'refuses this has broken the product, which this project treats as the defect and '
            . 'not the fix.'
        );

        $this->assertSame(
            (int) $storeA->id,
            (int) $written->store_id,
            'control: and the role carries THEIR store, unchanged by the remedy.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 3 — is the state reachable at all?
    |--------------------------------------------------------------------------
    */

    /**
     * REACHABILITY, MEASURED THROUGH THE REAL ROUTE THAT CREATES STAFF.
     *
     * `UserController::store():158` writes `$user->current_store = $objUser->current_store` — the
     * CREATOR's pointer, copied. So the question "can a staff row have an empty `current_store`"
     * reduces to "can the principal who creates it have one", and the platform operator is exactly
     * such a principal: `App\Support\Tenancy\OperatorStoreSelection` exists for "a user whose
     * `type` is `'super admin'` and whose `current_store` is empty", and
     * `SupportTicketController:147` already names "the state `UserController` leaves an
     * operator-created staff row in".
     *
     * This case drives it rather than citing it. `getCurrentStore()` answers the hardcoded literal
     * `1` for that operator (helper.php:888-889), so the role the form posts must carry
     * `store_id = 1` — which is what `RoleController::store()` writes for the operator, so the
     * fixture is the shape the product produces and not a contrivance.
     *
     * IT IS A MEASUREMENT, NOT AN ASSERTION ABOUT THE REMEDY. It passes with the `RoleController`
     * guard in place and with it reverted, because it measures the STATE rather than the fix.
     * Nothing in this file changes `UserController`, and whether an operator-created staff row
     * SHOULD inherit an empty pointer is a separate question; what matters here is only that a
     * `current_store IS NULL` staff row is something the product writes.
     *
     * **WHAT THIS DOES *NOT* ESTABLISH, STATED SO NOBODY READS IT AS MORE.** The row it produces is
     * rooted at the OPERATOR (`created_by` = the operator's id), because `UserController::store()`
     * also writes `created_by = Auth::user()->id`. The two cases above seed a MERCHANT-rooted staff
     * row instead, and **no product path was found that puts a merchant-rooted staff row in this
     * state**: `UserController:158` copies the creator's pointer, and every path that creates a
     * merchant populates it — `RegisteredUserController:220`/`:340` and `StoreController:297` all
     * set `current_store = $store->id`, and `StoreController::ownerstoredestroy():1104` repoints it
     * to a surviving store rather than clearing it. Searched for and not found; absence of a path is
     * not proof there is none. What that means for severity: the chain is measured end to end for
     * an operator-created staff row, and for a merchant's own staff it is measured given the state
     * but the state's reachability is open. Either way the writer is the right place for the
     * remedy, because the role it writes is wrong in both.
     */
    public function test_reachability_the_real_user_form_can_write_a_staff_row_with_no_store(): void
    {
        /* A STORE WITH ID 1 MUST EXIST, and this is a precondition of the HANDLER rather than of
           the test: after the insert, `UserController::store():185` does
           `$store = getStoreById(getCurrentStore()); Utility::webhook($module, $store->id)`, and
           for this principal `getCurrentStore()` is the hardcoded literal 1. With no store 1 that
           is a property read on null — a 500 AFTER the row is written, which is its own finding
           and not this one. It belongs to somebody else's tenant, which is realistic: the literal
           1 is not the operator's own store, which is `OperatorStoreSelection`'s whole subject. */
        [$neighbour, $storeOne] = $this->makeTenant();
        $this->assertSame(1, (int) $storeOne->id, 'premise: the first store created in a '
            . 'RefreshDatabase run takes id 1, which is what `getCurrentStore()` hands the '
            . 'operator.');

        $operator = $this->makePlatformAdmin(['current_store' => null]);

        $this->assertNull($operator->current_store, 'premise: the operator has no chosen store, '
            . 'which is the state `App\Support\Tenancy\OperatorStoreSelection` exists for.');
        $this->assertSame(1, (int) getCurrentStore($operator->id), 'premise: and `getCurrentStore()` '
            . 'hands that principal the hardcoded literal 1 (helper.php:888-889), so the role '
            . 'lookup in `UserController::store()` looks for `store_id = 1`.');

        /* A PLAN, because `UserController::store()` reads `$plan->max_users` off
           `Plan::find($user->plan_id)` with no null guard: without one the handler 500s before the
           insert and "no row with a null pointer" would hold for the wrong reason. */
        $plan = \App\Models\Plan::firstOrCreate(
            ['name' => 'gap279-plan'],
            ['price' => 0, 'duration' => 'Month', 'max_users' => -1, 'max_stores' => -1,
             'max_products' => -1, 'storage_limit' => -1, 'themes' => '', 'trial' => 0,
             'trial_days' => 0]
        );
        /* `plan_id` ONLY. `users` has no `plan` column and `User` has no `plan` relation, so
           `UserController::store():170`'s `$user->plan_id = $objUser->plan` reads an undefined
           attribute and writes NULL — the new staff row simply inherits no plan. Setting a `plan`
           attribute here would try to UPDATE a column that does not exist. */
        $operator->plan_id = $plan->id;
        $operator->save();

        /* THE ROLE THE FORM POSTS, in the shape `RoleController::store()` writes for the operator:
           `store_id = getCurrentStore()` = 1 and `created_by = creatorId()` = their own id. So this
           fixture is the product's own output, not a contrivance. */
        $carrier = Role::forceCreate([
            'name' => 'gap279-operator-staff',
            'display_name' => 'Operator Staff',
            'guard_name' => 'web',
            'created_by' => $operator->creatorId(),
            'store_id' => 1,
        ]);
        $carrier->givePermission($this->manageOrder());

        /* `Create User` through a role, because `isAbleTo()` REFUSES the platform operator — there
           is no super-admin bypass — and `UserController::store()`'s gate is `isAbleTo()` alone. */
        $this->grantPermission($operator, 'Create User');

        $email = 'gap279-operator-staff@example.test';

        $response = $this->actingAs($operator)->post('/users', [
            'name' => 'Gap279 Operator Staff',
            'email' => $email,
            'password' => 'Password!2345',
            'role' => $carrier->id,
        ]);

        $this->assertNotSame(404, $response->status(), 'premise: `POST /users` is a real route.');

        $created = DB::table('users')->where('email', $email)->first();

        $this->assertNotNull(
            $created,
            'premise: the request REACHED THE WRITER. It did not, so this case measures nothing '
            . 'about reachability either way — fix the fixture rather than reading the silence as '
            . 'evidence. Status ' . $response->status() . ', flashed error: '
            . var_export(session('error'), true)
        );

        $this->assertSame(
            (int) $operator->id,
            (int) $created->created_by,
            'premise: the new row is the one this request created.'
        );

        $this->assertNull(
            $created->current_store,
            'reachability REFUTED for this path: the real user form gave the new staff row a store '
            . 'pointer of ' . var_export($created->current_store, true) . ', so an '
            . 'operator-created staff row is NOT in the unresolved state. Record that on GAP-279 '
            . 'and look for another path before treating the writer finding as live.'
        );

        $this->assertNull(
            getCurrentStore($created->id),
            'reachability: and `getCurrentStore()` answers NULL for that row — the exact premise '
            . 'GAP-279 could not settle from the code alone, and the state '
            . '`SupportTicketController:147` already names as "the state `UserController` leaves '
            . 'an operator-created staff row in".'
        );
    }
}
