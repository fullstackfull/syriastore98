<?php

namespace Tests\Feature\Security;

use App\Models\DeliveryBoy;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GAP-142 / P4-F109 — THE FIVE DELIVERY-DRIVER ROUTES ON THE ADMIN API HAD NO PERMISSION LIMB.
 * =================================================================================================
 *
 * `Api\DeliveryBoyApiController` has exactly one gate, `refuseUnlessOperatorOwns()`, and it settles
 * TENANCY: the caller must be the platform operator, or a `User` whose merchant tenant root owns the
 * store named in `?store_id=`. That boundary is sound and earlier packages closed it (P1-PLT-08,
 * P1-SEC-66). What never existed on any of the five routes is the OTHER axis — whether this
 * principal, inside the merchant's own tenant, may do this at all:
 *
 *     index          GET    api/admin/deliveryboysIndex              the whole roster, login e-mails
 *     store          POST   api/admin/deliveryboyStore               creates a driver credential
 *     update         PUT    api/admin/deliveryboysUpdate/{id}        REWRITES A DRIVER'S LOGIN E-MAIL
 *     destroy        DELETE api/admin/deliveryboysDestroy/{id}       irreversible
 *     resetPassword  POST   api/admin/deliveryboysReset-password/{id} A PASSWORD WRITE
 *
 * So a staff member of the merchant's own tenant, holding a role with no permissions at all, could
 * repoint a driver's login address or set its password. `DeliveryBoy` is an authentication model
 * with its own guard, so an e-mail write on one is an ACCOUNT-TAKEOVER PRIMITIVE and not a privacy
 * row: whoever holds the address holds recovery, and whoever sets the password holds the account.
 * That is why this was ranked first of GAP-142's remaining eight.
 *
 * -------------------------------------------------------------------------------------------------
 * THE PERMISSION NAMES ARE THE WEB SCREENS' OWN, ONE PER ROUTE, AND NOTHING WAS INVENTED.
 *
 * `Utility::addNewData()` seeds exactly five delivery-driver names, and it seeds the SAME five in
 * both of its arrays — `$arrPermissions` (`app/Models/Utility.php:3058-3062`, which creates the
 * `Permission` rows) and `$companyNewPermission` (`:3223-3227`, which grants them to the shared
 * global `admin` role every merchant holds). There is NO plural delivery family at all, unlike the
 * order family where the platform block spells `Manage Orders`: a search of the whole repository for
 * `Deliveryboys`/`DeliveryBoys` as a permission name returns nothing. So the choice that bit
 * P4-F106 cannot arise here, and the only live question was which of the five goes on which route.
 *
 * `app/Http/Controllers/DeliveryBoyController.php` — the merchant WEB screens for the same five
 * operations — answers it, and this gate copies it method for method rather than reasoning by
 * analogy:
 *
 *     web :17  index / :261 datatable  →  Manage Deliveryboy          api index
 *     web :31  create / :43 store      →  Create DeliveryBoy          api store
 *     web :97  edit   / :122 update    →  Edit Deliveryboy            api update
 *     web :165 destroy                 →  Delete Deliveryboy          api destroy
 *     web :189 / :212 reset password   →  Reset password Deliveryboy  api resetPassword
 *
 * Note `Create DeliveryBoy` carries a capital B while the other four do not. That is the seeded
 * spelling and `MerchantPermissions::held()` compares with `in_array(..., true)`, so the casing is
 * load-bearing; `Manage Deliveryboy` is also the name `CompanyMenuListener:153` hangs the menu entry
 * on. ONE name per route, exactly as the web, so a staff member's grants mean the same thing in both
 * products. Widening any route to also admit `Manage Deliveryboy` would be a product decision and
 * would let a roster-reader rewrite credentials.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THE GATE IS SHAPED THE WAY IT IS. Three principals must keep working, and this family has
 * already broken two of them once.
 *
 *   THE PLATFORM OPERATOR. There is no super-admin bypass in this codebase — operator status is a
 *   string comparison on `users.type` — so `isAbleTo('Manage Deliveryboy')` REFUSES the operator,
 *   who holds no roles. The five names are also absent from `$superadminNewPermission`, so even a
 *   seeded platform install grants the operator none of them. `MerchantPermissions::names()` answers
 *   `WILDCARD` for them, which is the whole reason the gate goes through that class.
 *
 *   THE STORE'S OWN OWNER. A merchant does not implicitly hold these names: `makeMerchant()` creates
 *   a `User` with no role, and in production the grant depends on the global `admin` role actually
 *   carrying them. `AdminApiOrderDeletePermissionTest` records a gate in this family refusing the
 *   store's own owner, and `DeliveryAndOrderApiAuthorizationTest` carries a failure message written
 *   for exactly that — *"The predicate is too tight -- that is a defect, not a fix."* It is not an
 *   escalation: the owner is the principal who creates the roles and grants the permissions in their
 *   own tenant. `$store->created_by === $user->id` and NOT `creatorId()`, which returns the owner's
 *   id for staff too and would dissolve the gate entirely.
 *
 *   STAFF WHO HOLD THE NAMED PERMISSION.
 *
 * -------------------------------------------------------------------------------------------------
 * THE PERMISSION LIMB SITS BELOW THE TENANCY GATE, NOT ABOVE IT, and that ordering is pinned by
 * `test_a_member_of_another_tenant_is_still_refused_even_holding_the_permission` below. Three
 * shipped suites assert the tenancy refusal's exact 404 —
 * `CredentialBoundaryTest::test_a_merchant_cannot_rewrite_a_drivers_address_in_another_store`,
 * `DeliveryBoyScopeTest::test_a_merchant_cannot_list_a_store_they_do_not_own` and
 * `TenantRootSinksTest`'s self-demoted-merchant case — so a permission check placed first would turn
 * those 404s into 403s and break them. Tenancy answers first; the permission question is only ever
 * asked about a store the caller is already admitted to.
 *
 * 403, NEVER 401, AND NEVER `$this->error()`. `ApiResponser::error()` answers HTTP **200** with
 * `{status: 0}` (`app/Traits/ApiResponser.php:54`), a refusal no client distinguishes from success,
 * and the shipped Flutter admin app force-clears its session on any 401 — so a 401 here logs
 * merchants out of the whole app. `test_the_refusal_is_not_a_401_...` is its own case for that
 * reason.
 */
class AdminApiDeliveryBoyPermissionTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The driver's login address — the PII the roster serialises and the takeover target. */
    private const DRIVER_EMAIL = 'driver-login-address-pii@example.test';

    private const DRIVER_PASSWORD = 'driver-password-fixture-1';

    /** What an attacker tries to set the password to. Distinctive, so `Hash::check` cannot coincide. */
    private const ATTACKER_PASSWORD = 'attacker-chosen-value-f109';

    /**
     * The five doors, each with the single permission its web twin gates on.
     *
     * KEYED ON THE HANDLER, not on the uri words — CLAUDE.md records four completeness claims that
     * missed what they covered because they were keyed on spelling. `DeliveryBoyScopeTest`'s census
     * already asserts that this controller's public handler set is exactly these five, so a new
     * handler fails there and a renamed one fails here.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function doors(): array
    {
        /* THE KEYS ARE THE BARE HANDLER NAMES so that `--filter '<method>.*"update"'` selects ONE
           data set. A descriptive key reads better in the output and makes a per-door mutation run
           impossible to target, and a mutation group that can only be run per METHOD cannot show
           that each gate kills exactly the case claiming it. */
        return [
            'index' => ['index', 'Manage Deliveryboy'],
            'store' => ['store', 'Create DeliveryBoy'],
            'update' => ['update', 'Edit Deliveryboy'],
            'destroy' => ['destroy', 'Delete Deliveryboy'],
            'resetPassword' => ['resetPassword', 'Reset password Deliveryboy'],
        ];
    }

    /**
     * A merchant, their store, and one driver in it.
     *
     * THE SCHEMA IS ASSERTED AS A PREMISE RATHER THAN SKIPPED. `deliveryboyStore` and
     * `deliveryboysUpdate` both write `commission_percentage` and `is_commission_enabled`; until
     * GATE F (2026-11-02) no migration declared either, so both routes answered 500 to EVERY caller
     * and a "nothing changed" assertion passed with or without a gate —
     * `CredentialBoundaryTest` records that its first version did exactly that. A revert must fail
     * here loudly, not skip: a conditional skip would also break the declared outcome set in
     * `.github/known-test-outcomes.json`.
     *
     * @return array{0: User, 1: Store, 2: DeliveryBoy}
     */
    private function tenantWithADriver(): array
    {
        $this->assertTrue(Schema::hasTable('delivery_boys'),
            'delivery_boys does not exist in the test schema, so every assertion below is vacuous');

        foreach (['commission_percentage', 'is_commission_enabled'] as $column) {
            $this->assertTrue(Schema::hasColumn('delivery_boys', $column),
                "delivery_boys.{$column} is missing again. Both writing handlers put it in their "
                . 'insert, so without it they answer 500 to every caller and the refusal cases '
                . 'below cannot tell a gate from a crash. GATE F added it on 2026-11-02 -- check '
                . '2026_11_02_000500_add_commission_columns_to_delivery_boys_table.php.');
        }

        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();

        $driver = DeliveryBoy::forceCreate([
            'name' => 'Driver F109',
            'email' => self::DRIVER_EMAIL,
            'password' => Hash::make(self::DRIVER_PASSWORD),
            'contact' => '0100000000',
            'profile_image' => 'uploads/profile/avatar.png',
            'type' => 'deliveryboy',
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);

        return [User::find($merchant->id), $store, $driver];
    }

    private function staffIn(User $owner, Store $store, ?string $permission = null): User
    {
        $staff = $this->makeStaff($owner);
        $staff->current_store = $store->id;
        $staff->save();

        if ($permission !== null) {
            // grantPermission() builds the role with `store_id => $user->current_store`, which is
            // why current_store is written FIRST: MerchantPermissions::names() only counts a role
            // whose store is null or the store being served.
            $this->grantPermission(User::find($staff->id), $permission);
        }

        return User::find($staff->id);
    }

    /** The address a refused caller tries to repoint the driver's account at, or create one with. */
    private function hostileEmail(string $nonce): string
    {
        return 'attacker-owns-this-' . $nonce . '@example.test';
    }

    /**
     * Drive one door, exactly as the shipped admin app does: `store_id` on the QUERY STRING and the
     * row id in the PATH.
     *
     * The id is a ROUTE PARAMETER on all three single-row handlers (`update(Request $request, $id)`),
     * so a body key cannot change which row is touched — the distinction CLAUDE.md records between
     * `$request->id` and a method argument.
     */
    private function send(string $door, User $actor, Store $store, DeliveryBoy $driver, string $nonce)
    {
        $request = $this->withHeader(
            'Authorization',
            'Bearer ' . $actor->createToken('auth_token')->plainTextToken
        );

        $query = '?store_id=' . $store->id;

        return match ($door) {
            'index' => $request->getJson('/api/admin/deliveryboysIndex' . $query),

            'store' => $request->postJson('/api/admin/deliveryboyStore' . $query, [
                'name' => 'Injected ' . $nonce,
                'email' => $this->hostileEmail($nonce),
                'password' => 'injected-password-1',
            ]),

            'update' => $request->putJson('/api/admin/deliveryboysUpdate/' . $driver->id . $query, [
                'email' => $this->hostileEmail($nonce),
            ]),

            'destroy' => $request->deleteJson('/api/admin/deliveryboysDestroy/' . $driver->id . $query),

            'resetPassword' => $request->postJson(
                '/api/admin/deliveryboysReset-password/' . $driver->id . $query,
                [
                    'password' => self::ATTACKER_PASSWORD,
                    'password_confirmation' => self::ATTACKER_PASSWORD,
                ]
            ),
        };
    }

    /**
     * READ THROUGH THE RAW TABLE, DELIBERATELY, AND FOR TWO SEPARATE REASONS.
     *
     * `DeliveryBoy` is in `config/tenancy.php`'s `scoped_models` AND it is `Cachable`. A plain
     * `DeliveryBoy::find()` answers against whichever tenant happens to be resolved rather than
     * against the table — CLAUDE.md records a cart test reporting a row deleted while the raw table
     * still held it — and the model cache can serve a pre-write instance for the rest of a test
     * under `CACHE_STORE=array`.
     */
    private function row(DeliveryBoy $driver): ?object
    {
        return DB::table('delivery_boys')->where('id', $driver->id)->first();
    }

    /**
     * THE DOOR'S EFFECT DID NOT HAPPEN.
     *
     * Each door has its own observable, because "the status was 403" and "nothing was written" are
     * different claims and a 403 that still performed the write would satisfy only the first.
     */
    private function assertDoorDidNothing(
        string $door,
        Store $store,
        DeliveryBoy $driver,
        string $nonce,
        string $why
    ): void {
        $row = $this->row($driver);

        if ($door === 'index') {
            // A read: its only effect is disclosure, asserted on the body by the caller.
            $this->assertNotNull($row, 'Premise: the driver row exists. ' . $why);

            return;
        }

        if ($door === 'store') {
            $this->assertNull(
                DB::table('delivery_boys')->where('email', $this->hostileEmail($nonce))->first(),
                'a working delivery-driver LOGIN CREDENTIAL was created. ' . $why
            );

            return;
        }

        $this->assertNotNull($row, 'the driver row was destroyed. ' . $why);

        if ($door === 'update') {
            /* `$driver->email` -- the in-memory fixture, never refreshed -- and NOT the constant,
               so this helper is correct for a second driver in a second store too. */
            $this->assertSame((string) $driver->email, (string) $row->email,
                "a driver's LOGIN ADDRESS was repointed. Whoever holds the address holds that "
                . 'account\'s recovery, so this is a takeover primitive and not a privacy row. '
                . $why);

            return;
        }

        if ($door === 'resetPassword') {
            $this->assertFalse(Hash::check(self::ATTACKER_PASSWORD, (string) $row->password),
                "a driver's PASSWORD was set to a value the caller chose. " . $why);
        }
    }

    /**
     * THE DOOR'S EFFECT DID HAPPEN — the liveness half, and the reason the refusal cases bite.
     *
     * Without this, "nobody may touch a driver any more" passes every refusal case perfectly. It
     * also rules out the two traps CLAUDE.md names first: a handler that 500s for everyone, and a
     * route that does not exist, both leave the row unchanged.
     */
    private function assertDoorWorked(
        string $door,
        $response,
        Store $store,
        DeliveryBoy $driver,
        string $nonce,
        string $why
    ): void {
        $context = ' Status: ' . $response->status()
            . ' body: ' . substr((string) $response->getContent(), 0, 300);

        if ($door === 'index') {
            $this->assertSame(200, $response->status(), $why . $context);
            /* The ROSTER really came back, which is what distinguishes an admitted read from an
               empty 200 -- and `$driver->email` rather than the constant, so this is right for a
               driver in any store. */
            $response->assertSee((string) $driver->email, false);

            return;
        }

        if ($door === 'store') {
            $this->assertNotNull(
                DB::table('delivery_boys')->where('email', $this->hostileEmail($nonce))->first(),
                $why . ' No driver was created.' . $context
            );

            return;
        }

        if ($door === 'destroy') {
            $this->assertNull($this->row($driver), $why . ' The driver row survived.' . $context);

            return;
        }

        $row = $this->row($driver);
        $this->assertNotNull($row, 'the driver row vanished. ' . $why . $context);

        if ($door === 'update') {
            $this->assertSame($this->hostileEmail($nonce), (string) $row->email,
                $why . ' The e-mail was not rewritten.' . $context);

            return;
        }

        $this->assertTrue(Hash::check(self::ATTACKER_PASSWORD, (string) $row->password),
            $why . ' The password was not set.' . $context);
    }

    // ═════════════════════════════════════════════════════════════════════════════════ THE DEFECT

    /**
     * A staff member of the merchant's own tenant, holding a role with NO permissions, reached all
     * five routes: read every driver's login e-mail, created credentials, repointed a login address,
     * destroyed a driver and set a driver's password.
     *
     */
    #[DataProvider('doors')]
    public function test_a_staff_member_without_the_named_permission_is_refused(
        string $door,
        string $permission
    ): void {
        [$merchant, $store, $driver] = $this->tenantWithADriver();
        $staff = $this->staffIn($merchant, $store);
        $nonce = $this->uniqueFixtureRef();

        $response = $this->send($door, $staff, $store, $driver, $nonce);

        $this->assertSame(403, $response->status(),
            "`{$door}` admitted a staff member of the merchant's own tenant holding no permission "
            . "at all. refuseUnlessOperatorOwns() settles TENANCY and settles nothing about WHO "
            . "inside the store may act, and `{$permission}` was asked for nowhere. Body: "
            . substr((string) $response->getContent(), 0, 300));

        $this->assertDoorDidNothing($door, $store, $driver, $nonce,
            "Through `{$door}`, by a principal holding nothing.");

        /*
         * AND THE LOGIN ADDRESS IS ABSENT FROM THE REFUSED BODY, asserted separately from the
         * status. A refusal that still serialised the roster would be a 403 with the disclosure
         * intact, and a status-only assertion could not tell the two apart. `DeliveryBoy::$hidden`
         * covers the bcrypt hash since P1-PLT-08; the e-mail is not hidden and cannot be, because
         * the merchant's own roster screen prints it.
         */
        $response->assertDontSee(self::DRIVER_EMAIL, false);
    }

    /**
     * A 401 HERE IS A USER-VISIBLE REGRESSION IN A DIFFERENT PRODUCT, so it is its own case.
     *
     * The shipped Flutter admin app force-clears its session on any 401 from this base URL. And
     * `ApiResponser::error()` answers HTTP 200 by default, so the refusal cannot go through it
     * either — on this controller a 403 can only come from an explicit `response()->json(..., 403)`,
     * which is what makes `assertSame(403)` above specific to the new control rather than merely
     * "not 200".
     *
     */
    #[DataProvider('doors')]
    public function test_the_refusal_is_not_a_401_which_would_log_the_shipped_app_out(
        string $door,
        string $permission
    ): void {
        [$merchant, $store, $driver] = $this->tenantWithADriver();
        $staff = $this->staffIn($merchant, $store);

        $response = $this->send($door, $staff, $store, $driver, $this->uniqueFixtureRef());

        $this->assertNotSame(401, $response->status(),
            "`{$door}` refuses with 401, which force-clears the shipped app's session and logs the "
            . 'merchant out of the whole product. Use 403.');
    }

    /**
     * THE PASSWORD HASH IS READ BEFORE AND AFTER, which is a stronger claim than `Hash::check`
     * against the attacker's value: it also catches a handler that wrote SOMETHING else to the
     * column, and it is the column `auth('deliveryboy')` compares against.
     */
    public function test_a_refused_reset_leaves_the_stored_hash_byte_for_byte_unchanged(): void
    {
        [$merchant, $store, $driver] = $this->tenantWithADriver();
        $staff = $this->staffIn($merchant, $store);

        $before = (string) $this->row($driver)->password;

        $this->assertTrue(Hash::check(self::DRIVER_PASSWORD, $before),
            'Premise: the fixture hash really is the driver\'s password, so a comparison against '
            . 'it means something.');

        $this->send('resetPassword', $staff, $store, $driver, $this->uniqueFixtureRef());

        $this->assertSame($before, (string) $this->row($driver)->password,
            "a staff member holding no permission rewrote a delivery driver's stored password "
            . 'hash. Setting the password IS taking the account.');
    }

    // ═══════════════════════════════════════════════════════════════════════════════ THE CONTROLS

    /**
     * THE STORE'S OWN OWNER STILL GETS THROUGH, holding no role at all.
     *
     * This is the regression this family has already produced: `DeliveryAndOrderApiAuthorization
     * Test`'s message for it reads *"The predicate is too tight -- that is a defect, not a fix."*
     * Three shipped suites also depend on it here —
     * `DeliveryBoyScopeTest::test_the_owning_merchant_still_gets_their_roster_without_any_password_hash`,
     * and `CredentialBoundaryTest`'s live-create and forced-reset cases.
     *
     */
    #[DataProvider('doors')]
    public function test_the_store_owner_still_gets_through(string $door, string $permission): void
    {
        [$merchant, $store, $driver] = $this->tenantWithADriver();
        $nonce = $this->uniqueFixtureRef();

        $response = $this->send($door, $merchant, $store, $driver, $nonce);

        $this->assertDoorWorked($door, $response, $store, $driver, $nonce,
            "the gate refused the store's OWN owner on `{$door}` while they held no role. A "
            . 'merchant does not implicitly hold these names, and they are the principal who '
            . 'creates the roles in their own tenant, so refusing them is a live breakage with no '
            . 'security gain.');
    }

    /**
     * STAFF WHO HOLD THE NAMED PERMISSION STILL GET THROUGH — otherwise the gate names a permission
     * that admits nobody, which is a permanent 403 dressed as a fix.
     *
     */
    #[DataProvider('doors')]
    public function test_staff_holding_the_named_permission_still_get_through(
        string $door,
        string $permission
    ): void {
        [$merchant, $store, $driver] = $this->tenantWithADriver();
        $staff = $this->staffIn($merchant, $store, $permission);
        $nonce = $this->uniqueFixtureRef();

        /* THE PREMISE, or a green refusal case proves nothing about the grant: a typo in the
           permission name would make this fixture hold a DIFFERENT permission from the one the gate
           asks for, and the case below would then "pass" for the wrong reason. */
        $this->assertContains($permission,
            \App\Services\MerchantPermissions::names($staff, $store->id),
            "Premise: the fixture did not actually grant `{$permission}` in this store.");

        $response = $this->send($door, $staff, $store, $driver, $nonce);

        $this->assertDoorWorked($door, $response, $store, $driver, $nonce,
            "a staff member who DOES hold `{$permission}` was refused on `{$door}`, so the gate "
            . 'names a permission that does not admit anyone -- check the spelling against '
            . 'Utility::addNewData() (`Create DeliveryBoy` has a capital B; the other four do not) '
            . 'and the store the grants are read against.');
    }

    /**
     * THE PLATFORM OPERATOR STILL GETS THROUGH — the single most repeated trap in this codebase.
     *
     * There is no super-admin bypass: operator status is a string comparison on `users.type`, so
     * `isAbleTo('Manage Deliveryboy')` REFUSES them. The five delivery names are not even in
     * `$superadminNewPermission`, so a seeded platform install grants the operator none of them.
     * This case fails against an `isAbleTo()` spelling and passes against
     * `MerchantPermissions::allowsAny()`, which answers WILDCARD for the operator first.
     *
     */
    #[DataProvider('doors')]
    public function test_the_platform_operator_still_gets_through(
        string $door,
        string $permission
    ): void {
        [, $store, $driver] = $this->tenantWithADriver();

        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);
        $nonce = $this->uniqueFixtureRef();

        $this->assertSame([\App\Services\MerchantPermissions::WILDCARD],
            \App\Services\MerchantPermissions::names($operator, $store->id),
            'Premise: the operator fixture is not being read as the operator, so this case cannot '
            . 'tell an operator-first gate from one without the limb.');

        $response = $this->send($door, $operator, $store, $driver, $nonce);

        $this->assertDoorWorked($door, $response, $store, $driver, $nonce,
            "the platform operator was refused on `{$door}`. They hold no roles and no delivery "
            . 'permission is seeded to them, so a gate built on isAbleTo() locks out the one '
            . 'principal who may do everything.');
    }

    // ══════════════════════════════════════════════════════════════════════ TENANCY IS UNCHANGED

    /**
     * A MEMBER OF ANOTHER TENANT IS STILL REFUSED, EVEN HOLDING THE PERMISSION IN THEIR OWN STORE.
     *
     * This is PRE-EXISTING tenancy, not the new gate, and the case is here to prove the two axes are
     * independent — a permission limb placed above `refuseUnlessOperatorOwns()`, or one that
     * replaced it, would leave the older and worse defect open while every case above passed.
     *
     * ASSERTED ON THE ENVELOPE, NOT ON A NON-200 STATUS. The tenancy refusal is this controller's
     * own `response()->json(['status' => 'error', ...], 404)`, so `json('status')` is the field that
     * carries it; `AdminApiOrderViewPermissionTest` records the sibling version of this assertion
     * being written against the status and FAILING on working code, because `ApiResponser::error()`
     * answers HTTP 200. Asserting the envelope also means this case cannot be satisfied by the new
     * 403 — if the permission limb ever moved above the tenancy gate, the status would change but
     * this assertion would still hold.
     *
     * SO THE EXACT 404 IS ASSERTED TOO, as a second and separate claim, because the envelope alone
     * CANNOT see a reordering: a foreign-tenant staff member holds the permission in their own store
     * and not in this one, so a permission limb placed first would refuse them as well — same
     * `{status: 'error'}` body, same unchanged row, different status. Three shipped suites depend on
     * that status being 404 (`CredentialBoundaryTest::test_a_merchant_cannot_rewrite_a_drivers_
     * address_in_another_store`, `DeliveryBoyScopeTest::test_a_merchant_cannot_list_a_store_they_do_
     * not_own`, `TenantRootSinksTest`'s self-demoted-merchant case), so the ordering is a shipped
     * contract and this is the case in the file that owns the gate which states it. `assertSame(404)`
     * is exact rather than a "not 200" assertion — the trap the sibling suite recorded was a
     * `assertNotSame(200)` against a refusal that really was HTTP 200.
     */
    #[DataProvider('doors')]
    public function test_a_member_of_another_tenant_is_still_refused_even_holding_the_permission(
        string $door,
        string $permission
    ): void {
        [, $store, $driver] = $this->tenantWithADriver();

        $outsiderMerchant = $this->makeMerchant();
        $outsiderStore = $this->makeStore($outsiderMerchant);
        $outsiderMerchant->current_store = $outsiderStore->id;
        $outsiderMerchant->save();

        $outsider = $this->staffIn(User::find($outsiderMerchant->id), $outsiderStore, $permission);
        $nonce = $this->uniqueFixtureRef();

        $response = $this->send($door, $outsider, $store, $driver, $nonce);

        $this->assertSame('error', $response->json('status'),
            "a member of ANOTHER tenant was answered with a success envelope on `{$door}` for this "
            . "store's drivers while holding `{$permission}` only in their own store. Body: "
            . substr((string) $response->getContent(), 0, 300));

        $this->assertSame(404, $response->status(),
            "the tenancy refusal on `{$door}` is no longer a 404. The likeliest cause is the new "
            . 'permission limb having been placed ABOVE refuseUnlessOperatorOwns(): tenancy must '
            . 'answer first, because three shipped suites assert this exact status for a caller who '
            . 'names a store they do not own. Body: '
            . substr((string) $response->getContent(), 0, 300));

        $this->assertDoorDidNothing($door, $store, $driver, $nonce,
            "Through `{$door}`, by a member of another tenant holding `{$permission}` at home.");

        $response->assertDontSee(self::DRIVER_EMAIL, false);
    }

    /**
     * A GRANT IN THE MERCHANT'S *OTHER* STORE DOES NOT ADMIT HERE — the store the gate reads.
     *
     * WHY THIS CASE EXISTS, AND IT IS THE ONE EVERY OTHER CASE IN THIS FILE IS BLIND TO. Every
     * fixture above sets the staff member's `current_store` to the store under test, so
     * `$storeId` (the store being served) and `auth()->user()->current_store` are the SAME NUMBER
     * and no case can tell them apart — CLAUDE.md's "the test cannot tell the two readings apart",
     * which cost a real surviving mutant here before this case was written. A gate spelled
     * `allowsAny($user, [$permission], auth()->user()->current_store)` passes all thirty-one of
     * them.
     *
     * The inputs where the two readings DISAGREE are one merchant with two stores and one employee
     * per store, which is exactly the defect `MerchantPermissions::names()` was written for: this
     * handler serves whichever store `?store_id=` names and `refuseUnlessOperatorOwns()` admits ANY
     * store in the caller's tenant, so a gate keyed on `current_store` would evaluate store B's
     * grants against store A's rows. Both legs are driven here, so the case cannot pass by refusing
     * everybody: the refusal for store A, then the SAME principal succeeding in store B.
     */
    #[DataProvider('doors')]
    public function test_a_grant_in_the_merchants_other_store_does_not_admit_here(
        string $door,
        string $permission
    ): void {
        [$merchant, $storeA, $driverA] = $this->tenantWithADriver();

        /* A second store of the SAME merchant, so tenancy admits the caller to both and only the
           permission question can separate them. */
        $storeB = $this->makeStore($merchant);

        $driverB = DeliveryBoy::forceCreate([
            'name' => 'Driver F109 B',
            'email' => 'driver-in-store-b@example.test',
            'password' => Hash::make(self::DRIVER_PASSWORD),
            'contact' => '0100000000',
            'profile_image' => 'uploads/profile/avatar.png',
            'type' => 'deliveryboy',
            'store_id' => $storeB->id,
            'created_by' => $merchant->id,
        ]);

        /* Granted in store B only: staffIn() writes current_store first and grantPermission()
           builds the role with `store_id => current_store`. */
        $staff = $this->staffIn($merchant, $storeB, $permission);

        $this->assertNotContains($permission,
            \App\Services\MerchantPermissions::names($staff, $storeA->id),
            'Premise: the fixture leaked the grant into store A, so the refusal below would be '
            . 'about nothing.');

        /* THE TWO LEGS' NONCES DIFFER BY CONSTRUCTION, not by `uniqid()`'s resolution. Both legs of
           the `store` door create a driver and the handler validates `unique:delivery_boys,email`,
           so two equal nonces would make the CONTROL leg fail validation and report as a refusal
           -- a flake that reads as a finding. */
        $nonceA = $this->uniqueFixtureRef() . '-a';
        $refused = $this->send($door, $staff, $storeA, $driverA, $nonceA);

        $this->assertSame(403, $refused->status(),
            "`{$door}` honoured a grant of `{$permission}` made in ANOTHER store of the same "
            . 'merchant. The gate must read the store it is about to serve, not the caller\'s '
            . '`current_store`. Body: ' . substr((string) $refused->getContent(), 0, 300));

        $this->assertDoorDidNothing($door, $storeA, $driverA, $nonceA,
            "Through `{$door}`, on store A, by staff granted `{$permission}` in store B.");
        $refused->assertDontSee(self::DRIVER_EMAIL, false);

        /* AND THE CONTROL, in the same case: the fixture was usable and the grant is real. Without
           it the refusal above is also what a broken fixture, a 500 or a permanently-closed gate
           produces. */
        $nonceB = $nonceA . '-b';
        $allowed = $this->send($door, $staff, $storeB, $driverB, $nonceB);

        $this->assertDoorWorked($door, $allowed, $storeB, $driverB, $nonceB,
            "the same staff member was refused in store B, where they DO hold `{$permission}`, so "
            . 'the refusal above proves nothing about which store the gate reads.');
    }
}
