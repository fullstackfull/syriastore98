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
 * WHY IT IS NOT FIXED IN THIS SLICE.
 * -------------------------------------------------------------------------------------------------
 * Adding `isAbleTo('Manage Customer')` here would make a **shipped Flutter endpoint start
 * refusing a principal it accepts today**. Any merchant whose staff use the admin app to add
 * customers would see that stop working at the next release, and nothing in this repository
 * records whether that is current practice. The owner's instruction in the message authorising this
 * slice is *"No silent payment behavior or authentication policy changes"*, and *who may create a
 * login credential* is squarely that. So it is measured, pinned, and escalated.
 *
 * THE FIX, when it is authorised, is the operator-first shape this codebase already uses, because
 * Laratrust has no super-admin bypass and testing the permission alone would refuse the platform
 * operator:
 *
 *     $isOperator = strtolower(trim((string) $adminUser->type)) === 'super admin';
 *     if (! $isOperator && ! $adminUser->isAbleTo('Manage Customer')) { ... 403 ... }
 *
 * **403 and not 401**, because the shipped Flutter app forces a logout on any 401 — a new 401 on a
 * path a client calls is a user-visible regression, which `ApiResponser` and CLAUDE.md both record.
 *
 * EVERY TEST BELOW PASSES, AND THAT IS DELIBERATE. They assert the behaviour as it is measured
 * today, including the part that is wrong. A test failing on purpose would be an undeclared failure
 * and `.github/known-test-outcomes.json` asserts the Feature outcome set in BOTH directions, so it
 * would break that gate for every later change. When the fix lands, the two tests marked THE GAP
 * are the ones to invert.
 */
class AdminApiCustomerCreatePermissionTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;
    private User $staff;
    private string $staffToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->merchant = User::find($this->merchant->id);

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

    public function test_premise_the_staff_token_really_authenticates(): void
    {
        // Without this, a 403 below could be an unauthenticated refusal wearing the wrong number.
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
        $this->assertSame(0, self::rows()->where('email', 'webattempt@example.test')->count(),
            'the merchant web form created a customer for a principal holding no permission. That '
            . 'gate is CustomerController::store():46-54 and closing it is recorded at :21-26 — if '
            . 'this fails, that repair has regressed');
    }

    // =============================================================================================
    //  THE GAP — the shipped admin app accepts the same principal. INVERT THESE WHEN FIXED.
    // =============================================================================================

    public function test_THE_GAP_the_admin_api_accepts_the_same_principal(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->staffToken)
            ->postJson('/api/admin/AddCustomer?store_id=' . $this->store->id, $this->apiPayload());

        $this->assertSame(201, $response->getStatusCode(),
            'MEASUREMENT, not an endorsement: if this is no longer 201 the permission gate has '
            . 'been added and this test is what should be inverted. Body: ' . $response->getContent());

        $created = self::rows()->where('email', 'staffmade@example.test')->first();

        $this->assertNotNull($created,
            'the endpoint answered 201 without creating a row, so this file is measuring the wrong '
            . 'thing');
        $this->assertSame((int) $this->store->id, (int) $created->store_id,
            'premise: the row landed in the merchant\'s store, which is what makes this a '
            . 'permission question rather than a tenancy one');
    }

    public function test_THE_GAP_and_the_account_it_creates_can_sign_in(): void
    {
        $this->withHeader('Authorization', 'Bearer ' . $this->staffToken)
            ->postJson('/api/admin/AddCustomer?store_id=' . $this->store->id, $this->apiPayload());

        $created = self::rows()->where('email', 'staffmade@example.test')->first();
        $this->assertNotNull($created, 'premise: the row exists');

        $this->assertTrue(\Hash::check('Passw0rdLong', (string) $created->password),
            'MEASUREMENT: the password is the one the unpermitted caller chose, so they know the '
            . 'credential for an account in a store they hold no customer permission over');
        $this->assertNotNull($created->email_verified_at,
            'MEASUREMENT: the account is created already verified, so no e-mail confirmation '
            . 'stands between the create and a working storefront login');
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
