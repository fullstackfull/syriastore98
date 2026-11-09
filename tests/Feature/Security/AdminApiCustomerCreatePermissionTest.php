<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `POST api/admin/AddCustomer` HAS NO PERMISSION CHECK — and the web form that does the same thing
 * has one, which this file measures side by side.
 * =================================================================================================
 *
 * FOUND BY the tenant-authorization review of the e-mail uniqueness policy change (2026-11-07),
 * which reviewed the handler's `validate()` call and reported the gate above it. **It is
 * pre-existing. No line of that change caused it, and no line of that change widened it.** It is
 * recorded here because it is in the handler that change edited, on the same `validate()` call, and
 * because the slice's own parity table compared the two surfaces on uniqueness while this axis —
 * *who may call them at all* — was never compared.
 *
 * THE ASYMMETRY, MEASURED RATHER THAN DESCRIBED:
 *
 *   `CustomerController::store()` (the merchant web form, `:46-54`)
 *     operator first, then `isAbleTo('Manage Customer')`, else redirect with "Permission denied."
 *
 *   `Api\DashboardController::addCustomer()` (the shipped admin app, `:8585-8640`)
 *     `if (!$adminUser)` -> 401, and `resolveOwnedStore()` -> 403. **No `isAbleTo` anywhere, and
 *     no operator limb either.**
 *
 * SO THE SAME PRINCIPAL IS REFUSED ON ONE SURFACE AND ACCEPTED ON THE OTHER. `resolveOwnedStore()`
 * scopes on `created_by = $user->creatorId()` and `User::creatorId()` returns `created_by` for any
 * type that is not `admin`/`super admin` — so a STAFF member resolves their owning merchant's
 * store and passes the 403. The admin login (`Api\DashboardController::login`, routed at
 * `routes/api.php:83` and `:170`) refuses only inactive or login-disabled accounts; it places no
 * restriction on `type`, so staff can obtain the token in the first place.
 *
 * AND THE WRITE IS A CREDENTIAL. The create sets `password` from the request and
 * `email_verified_at => now()`, so the resulting account can sign in to that merchant's storefront
 * immediately. That is exactly the property that made the same gap on the web form an escalation
 * worth closing — `CustomerController.php:21-26` records it, *"Any authenticated user could create
 * a customer... A HIDDEN BUTTON IS NOT A FIX"* — and it is closed there and open here.
 *
 * `updateCustomerInfo()` IS NOT AFFECTED, and that was checked rather than assumed: `:9123` refuses
 * any `type` outside `['admin','super admin']`, so staff get 401 from it. The gap is specific to
 * `addCustomer`.
 *
 * IT IS FIXED NOW, UNDER A NARROW OWNER AUTHORISATION (2026-11-07): *"I authorize a focused
 * repair to the documented S2 AddCustomer authorization defect. Use the existing
 * customer-management permission policy, including the correct operator-first handling.
 * Preserve authorized merchant and operator capabilities. Return 403 for authenticated users
 * lacking permission, not 401, to avoid unintended Flutter logout behavior."*
 *
 * EVERYTHING ABOVE THIS LINE IS THE MEASUREMENT THAT PRECEDED THE REPAIR, kept because it is the
 * evidence for it. Everything below is the repair.
 *
 * WHAT SHIPPED, AND WHY IT IS NOT THE SHAPE AN EARLIER DRAFT OF THIS DOCBLOCK PROPOSED. That
 * draft proposed a bare operator-first `isAbleTo`:
 *
 *     $isOperator = strtolower(trim((string) $adminUser->type)) === 'super admin';
 *     if (! $isOperator && ! $adminUser->isAbleTo('Manage Customer')) { ... 403 ... }
 *
 * which is correct about the operator and WRONG about the store. `isAbleTo()` is not store-scoped,
 * so a staff member holding a role scoped to store A would have passed for store B of the same
 * merchant. The repair uses `MerchantPermissions::allowsAny($user, [...], $store->id)` instead --
 * the helper this codebase already uses at `DashboardController:2403`, `:4804` and `:6442`, which
 * gives the operator the wildcard AND filters roles to `store_id === null || store_id === $storeId`.
 * `test_the_permission_is_asked_PER_STORE_and_not_globally` is the test that separates the two
 * shapes; the proposed one would fail it.
 *
 * THE PREMISE THE TESTS BELOW CANNOT PROVE, so it was checked in production code instead. Every
 * test here GRANTS `Manage Customer` to the merchant's global role in its own fixture, which
 * manufactures the premise it depends on: these tests would all pass even if real merchants held
 * nothing and the gate were a platform-wide lockout. The grant is real --
 * `Utility::addNewData()` selects the global `admin` role (`Utility.php:3020`), `'Manage Customer'`
 * is in `$companyNewPermission` (`:3192`) and is granted at `:3234`, and that runs from
 * `AuthenticatedSessionController::store():83` as well as registration, so it is self-healing.
 * Had that list omitted the name, this change would have locked out every merchant on the
 * platform and nothing in this file could have seen it.
 *
 * WHICH TESTS ACTUALLY BITE: revert the gate and FIVE fail, measured by doing it --
 * three here (`test_a_staff_member_with_no_permission_is_REFUSED`,
 * `test_the_refusal_is_403_and_never_401`, `test_the_permission_is_asked_PER_STORE_and_not_globally`)
 * and two in `AddCustomerPermissionRefusalContractTest`
 * (`..._byte_identical_in_shape_to_the_ownership_refusal` and `..._never_401...`). The review that
 * found this counted four, correctly for the code as it then stood: the `..._never_401...` loop
 * could NOT fail for the change it guards, because a reverted gate answers 201 and that satisfies
 * `assertNotSame(401, ...)`. An `assertSame(403, ...)` was added inside the loop and it is the
 * fifth. Note also that `test_the_refusal_is_403_and_never_401` here is carried entirely by its
 * `assertSame(403, ...)` for the same reason -- the assertion its NAME describes is the weak one.
 * The three positive controls pass with the gate removed BY DESIGN: they detect a lockout, not the
 * gate. The premises pass either way and say so.
 */
class AdminApiCustomerCreatePermissionTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;
    private Store $secondStore;
    private Store $otherStore;
    private User $staff;
    private string $staffToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        // A SECOND store of the SAME merchant, for the per-store permission test, and a third
        // belonging to somebody else, for the tenancy test.
        $this->secondStore = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->otherStore = $this->makeStore($this->makeMerchant());
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        // THE MERCHANT MUST HOLD THEIR OWN PERMISSIONS, as they do in production: both
        // RegisteredUserController and StoreController hand every merchant the GLOBAL `admin`
        // role, whose store_id is NULL. Without it this fixture is not a merchant, it is a user
        // with a store — and the positive control below would fail for the fixture's reason
        // rather than the gate's.
        // Via the IDEMPOTENT helper: `roles.name` is UNIQUE and makeGlobalAdminRole() is an
        // unconditional forceCreate.
        $this->merchant = $this->grantMerchantGlobalRole($this->merchant);

        // A staff member of that merchant, holding NO permissions at all. No grantPermission call.
        $this->staff = $this->makeStaff($this->merchant, [
            'current_store' => $this->store->id,
            'is_active' => 1,
        ]);
        $this->staff = User::find($this->staff->id);
        $this->staffToken = $this->staff->createToken('auth_token')->plainTextToken;
    }

    private static function rows()
    {
        return Customer::withoutGlobalScopes();
    }

    private function apiPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Unpermitted',
            'last_name'  => 'Creation',
            'email'      => 'staffmade@example.test',
            'mobile'     => '555000777',
            // The endpoint's own regex is /^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d@$!%*?&]{8,}$/.
            'password'   => 'Passw0rdLong',
            'status'     => '1',
        ], $overrides);
    }

    // =============================================================================================
    //  PREMISES — so a failure below cannot be read as the gap when it is the fixture
    // =============================================================================================

    public function test_premise_the_staff_member_holds_no_relevant_permission(): void
    {
        $this->assertFalse($this->staff->isAbleTo('Manage Customer'),
            'the fixture staff member holds Manage Customer, so nothing below measures a gap');
        $this->assertSame('staff', strtolower(trim((string) $this->staff->type)),
            'the fixture is not a staff row, so creatorId() does not resolve to the merchant');
    }

    public function test_premise_creatorId_resolves_to_the_owning_merchant(): void
    {
        // NOT an authentication premise, despite what this method was first called. A token that
        // does not authenticate produces 401 from CustomAuth, which the assertion below cannot
        // see; `test_the_refusal_is_403_and_never_401` is what covers that. This asserts only
        // that the staff row hangs off the right merchant.
        $this->assertSame($this->merchant->id, (int) $this->staff->creatorId(),
            'creatorId() must resolve to the owning merchant, or resolveOwnedStore() would refuse '
            . 'for a tenancy reason and the permission question would never be reached');
    }

    public function test_premise_the_web_form_and_the_api_create_the_same_kind_of_row(): void
    {
        // Both write a Customer with a caller-chosen password and email_verified_at set, which is
        // what makes "who may call this" a credential question rather than a data-entry one.
        $source = file_get_contents(app_path('Http/Controllers/Api/DashboardController.php'));
        // Sliced to the NEXT method rather than to a fixed byte count. My first version took
        // 4000 characters, and adding a comment block to the handler pushed its `Customer::create`
        // past that window -- so the premise failed for my reason rather than the code's, which is
        // precisely the class of mistake a premise exists to rule out.
        $from = strpos($source, 'public function addCustomer');
        $this->assertNotFalse($from, 'premise: addCustomer still exists in this controller');
        $to = strpos($source, 'public function ', $from + 10);
        $handler = substr($source, $from, $to === false ? null : $to - $from);

        // Whitespace-normalised: the file aligns its array arrows, and an alignment change is not
        // a behaviour change.
        $flat = preg_replace('/\s+/', ' ', $handler);

        $this->assertStringContainsString("'email_verified_at' => now()", $flat,
            'addCustomer no longer marks the new account verified, so the credential framing in '
            . 'this file needs re-reading');
        $this->assertStringContainsString("'password' => \\Hash::make(\$validated['password'])", $flat,
            'addCustomer no longer sets a caller-chosen password');
    }

    // =============================================================================================
    //  THE CONTROL — the web form refuses this principal
    // =============================================================================================

    public function test_the_web_form_refuses_a_staff_member_with_no_permission(): void
    {
        $response = $this->actingAs($this->staff)->post('/customer', [
            'first_name' => 'Unpermitted',
            'last_name' => 'Creation',
            'email' => 'webattempt@example.test',
            'mobile' => '555000888',
            'password' => 'a-long-enough-password',
            'status' => '1',
        ]);

        $response->assertRedirect();

        // WHICH GATE FIRED IS THE WHOLE POINT OF THIS FILE, and "redirected with no row" cannot
        // see it. `CustomerController::store()` returns exactly that for at least three different
        // reasons: the permission gate at :52, the empty-store tenancy limb at :90 (which flashes
        // the SAME string), and ordinary validation failure -- and the web rule set differs from
        // the API payload, so a payload drift would refuse here and look identical. The flash is
        // asserted so a validation refusal cannot masquerade as a permission refusal. The staff
        // fixture's `current_store` is set, so :52 is the limb that actually fires.
        // Raised by the adversarial review of this change.
        $response->assertSessionHas('error', __('Permission denied.'));

        $this->assertSame(0, self::rows()->where('email', 'webattempt@example.test')->count(),
            'the merchant web form created a customer for a principal holding no permission. That '
            . 'gate is CustomerController::store():46-54 and closing it is recorded at :21-26 — if '
            . 'this fails, that repair has regressed');
    }

    // =============================================================================================
    //  THE REPAIR — owner-authorised 2026-11-07, "NARROW FIX AUTHORIZED"
    // =============================================================================================

    /**
     * THE NEGATIVE. This is the inverse of the test that used to stand here asserting 201.
     */
    public function test_a_staff_member_with_no_permission_is_REFUSED(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->staffToken)
            ->postJson('/api/admin/AddCustomer?store_id=' . $this->store->id, $this->apiPayload());

        $response->assertStatus(403);

        $this->assertSame(0, self::rows()->where('email', 'staffmade@example.test')->count(),
            'the endpoint refused with 403 and created the customer anyway');
    }

    /**
     * **403, NEVER 401** — the owner named this explicitly, and it is not a style preference.
     * `ApiResponser::error()` returns HTTP 200 by default and 401 must be passed deliberately; the
     * shipped Flutter app FORCES A LOGOUT on any 401, so answering 401 to a permission refusal
     * would sign the merchant's staff out of the app instead of telling them they lack a right.
     */
    public function test_the_refusal_is_403_and_never_401(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->staffToken)
            ->postJson('/api/admin/AddCustomer?store_id=' . $this->store->id, $this->apiPayload());

        $this->assertNotSame(401, $response->getStatusCode(),
            'a permission refusal answered 401. The shipped Flutter app force-logs-out on any 401, '
            . 'so this would sign a merchant\'s staff out of the app rather than tell them they '
            . 'lack a right — a user-visible regression on a path a client calls');
        $this->assertSame(403, $response->getStatusCode());
    }

    // ---------------------------------------------------------------------------------------------
    //  THE POSITIVE CONTROLS. The owner's instruction is "Preserve authorized merchant and operator
    //  capabilities", and a gate that refuses everyone satisfies the negative above perfectly.
    //  Each of these FAILS if the repair is a lockout rather than a tightening.
    // ---------------------------------------------------------------------------------------------

    public function test_POSITIVE_the_merchant_who_owns_the_store_still_creates_customers(): void
    {
        $token = $this->merchant->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/AddCustomer?store_id=' . $this->store->id,
                $this->apiPayload(['email' => 'byowner@example.test']))
            ->assertStatus(201);

        $this->assertSame(1, self::rows()->where('store_id', $this->store->id)
            ->where('email', 'byowner@example.test')->count(),
            'THE REPAIR IS A LOCKOUT, NOT A TIGHTENING: the merchant who owns the store can no '
            . 'longer create a customer in it. Merchants hold the GLOBAL `admin` role, whose '
            . '`store_id` is NULL — `MerchantPermissions::names()` keeps the null arm for exactly '
            . 'this reason, and if that arm is ever dropped this is the test that says so');
    }

    public function test_POSITIVE_staff_WITH_the_permission_still_create_customers(): void
    {
        $permitted = $this->makeStaff($this->merchant, [
            'current_store' => $this->store->id,
            'is_active' => 1,
        ]);
        $this->grantPermission(User::find($permitted->id), 'Manage Customer');
        $permitted = User::find($permitted->id);
        $token = $permitted->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/AddCustomer?store_id=' . $this->store->id,
                $this->apiPayload(['email' => 'bypermitted@example.test']))
            ->assertStatus(201);

        $this->assertSame(1, self::rows()->where('store_id', $this->store->id)
            ->where('email', 'bypermitted@example.test')->count(),
            'a staff member WHO HOLDS `Manage Customer` was refused. The gate is then not a '
            . 'permission check at all — it is refusing by type, which is a different and wrong rule');
    }

    public function test_POSITIVE_the_platform_operator_still_creates_customers(): void
    {
        /*
         * THE ONE THIS CODEBASE GETS WRONG MOST OFTEN. Laratrust has no super-admin bypass here:
         * `isAbleTo()` ALONE REFUSES THE PLATFORM OPERATOR, because operator status is a string on
         * `users.type` and not a role. A gate written as `isAbleTo('Manage Customer')` would lock
         * the operator out of an endpoint they may legitimately use.
         *
         * `MerchantPermissions` answers WILDCARD for the operator, which is why the repair uses it
         * rather than `isAbleTo()` directly.
         */
        $operator = $this->makePlatformAdmin();
        $operator->current_store = $this->store->id;
        $operator->save();
        $operator = User::find($operator->id);
        $token = $operator->createToken('auth_token')->plainTextToken;

        $this->assertFalse($operator->isAbleTo('Manage Customer'),
            'premise: `isAbleTo()` really does refuse the operator in this codebase. If this ever '
            . 'passes, Laratrust has gained a super-admin bypass and the operator-first ordering '
            . 'this project enforces everywhere needs re-reading');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/AddCustomer?store_id=' . $this->store->id,
                $this->apiPayload(['email' => 'byoperator@example.test']))
            ->assertStatus(201);

        $this->assertSame(1, self::rows()->where('store_id', $this->store->id)
            ->where('email', 'byoperator@example.test')->count(),
            'THE PLATFORM OPERATOR IS LOCKED OUT. The gate was written operator-last — '
            . '`isAbleTo()` refuses them, so a permission-only check turns this repair into a '
            . 'lockout for the one principal who may act in any store');
    }

    // ---------------------------------------------------------------------------------------------
    //  TENANT ISOLATION IS UNCHANGED BY THE REPAIR
    // ---------------------------------------------------------------------------------------------

    public function test_the_tenancy_refusal_still_answers_for_a_store_the_caller_does_not_own(): void
    {
        // The merchant holds the permission. The store is somebody else's. The answer must still
        // be 403 and must not become 201 because the permission check passed.
        $token = $this->merchant->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/AddCustomer?store_id=' . $this->otherStore->id,
                $this->apiPayload(['email' => 'crosstenant@example.test']))
            ->assertStatus(403);

        $this->assertSame(0, self::rows()->where('email', 'crosstenant@example.test')->count(),
            'a permitted merchant created a customer in a store they do not own');
    }

    public function test_the_permission_is_asked_PER_STORE_and_not_globally(): void
    {
        /*
         * `isAbleTo()` is NOT store-scoped — `allPermissions()` unions every role the user holds
         * with no store predicate, which `MerchantPermissions` exists to correct. This asserts the
         * correction is actually in force on this endpoint: a staff member granted the permission
         * in ONE store must not carry it into the merchant's OTHER store.
         */
        $permitted = $this->makeStaff($this->merchant, [
            'current_store' => $this->store->id,
            'is_active' => 1,
        ]);
        $this->grantPermission(User::find($permitted->id), 'Manage Customer');
        $permitted = User::find($permitted->id);
        $token = $permitted->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/AddCustomer?store_id=' . $this->secondStore->id,
                $this->apiPayload(['email' => 'wrongstore@example.test']))
            ->assertStatus(403);

        $this->assertSame(0, self::rows()->where('email', 'wrongstore@example.test')->count(),
            'a staff member granted `Manage Customer` in one store created a customer in a '
            . 'DIFFERENT store of the same merchant. The permission is being read globally, which '
            . 'is the defect MerchantPermissions was written to prevent');
    }

    // =============================================================================================
    //  AND THE SIBLING THAT IS NOT AFFECTED — checked, not assumed
    // =============================================================================================

    public function test_update_customer_refuses_staff_because_it_checks_the_type(): void
    {
        $existing = Customer::forceCreate([
            'first_name' => 'Existing', 'last_name' => 'Shopper',
            'email' => 'existing@example.test', 'password' => bcrypt('x'),
            'store_id' => $this->store->id, 'created_by' => $this->merchant->id,
            'status' => 1,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->staffToken)
            ->postJson('/api/admin/update-customer?store_id=' . $this->store->id, [
                'customer_id' => $existing->id,
                'first_name' => 'Renamed',
            ]);

        $this->assertSame(401, $response->getStatusCode(),
            'updateCustomerInfo():9123 restricts type to admin/super admin. If this changes, the '
            . 'gap recorded in this file has grown a second site');

        $this->assertSame('Existing', (string) self::rows()->find($existing->id)->first_name,
            'the refusal did not prevent the write');
    }
}
