<?php

namespace Tests\Feature\Security;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * P1-SEC-67c -- THE SETTINGS SCREENS SERVED THE PLATFORM'S OWN SETTINGS TO A PRINCIPAL ROOTED AT THE OPERATOR.
 * ====================================================================================================
 *
 * Found by the fourth independent review (of P1-SEC-67b), lens "blast radius", and confirmed by two skeptics that could not refute it. It is the same
 * predicate family as P1-SEC-66/67/67b and none of those fixes could reach it, because it is not a store-ownership query: it is the Settings helper.
 *
 * `getAdminAllSetting()` (app/Helper/helper.php) turns any user whose `type` is not `admin` / `super admin` into `User::find($user->created_by)` -- "the
 * merchant who employs this staff member" -- and then reads THAT user's settings in THAT user's current store. For a merchant's staff that is exactly right.
 * For a principal whose `created_by` is the platform operator (a merchant who demoted their own `type` through `PUT /users/{self}`, or staff the operator
 * created) it is the OPERATOR, and the Settings tabs they open print the platform's own mail host, port, user and from-address, its storage bucket, region
 * and key id, and the non-secret half of its payment gateways. (Secret inputs are masked since F117, so what was served is the non-secret half; it is still
 * the platform's, and `check_file()` -- called from the profile-image accessor -- also loads the resolved row's storage settings into the request's runtime
 * filesystem config.) Both routes are gated only by `StoreSettingsAccess::permits()` -- `Manage Setting`, which the shared `admin` role holds and a
 * self-demoted merchant keeps, because `UserController::update` only ADDS the new role.
 *
 * The demotion is done THROUGH THE PRODUCT, and its premise is asserted: if it stops working, every refusal below would be measuring something else.
 *
 * THE FIX fails CLOSED -- an empty settings array -- and not "no user": the code after the resolution treats a missing user as a guest and falls through to
 * the LOWEST-ID MERCHANT's settings (P1-SEC-18 F4), which would be a worse leak than the one being closed.
 */
class OperatorRootedSettingsTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const OPERATOR_HOST = 'smtp.platform-operator-fixture.test';
    private const OPERATOR_USER = 'platform-mailer-fixture';
    private const OPERATOR_BUCKET = 'platform-operator-bucket-fixture';
    private const MERCHANT_HOST = 'smtp.merchant-own-fixture.test';
    private const MERCHANT_BUCKET = 'merchant-own-bucket-fixture';

    private User $operator;
    private Store $operatorStore;
    private User $merchant;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        // The operator FIRST, so that it is user 1 -- the id every merchant has as `created_by`.
        $this->operator = $this->makePlatformAdmin();
        $this->assertSame(1, (int) $this->operator->id, 'premise: the operator is user 1');
        $this->operatorStore = $this->makeStore($this->operator);
        $this->operator->current_store = $this->operatorStore->id;
        $this->operator->save();
        $this->operator = $this->operator->fresh();

        [$this->merchant, $this->store] = $this->makeTenant();

        $this->setting($this->operatorStore, [
            'MAIL_DRIVER' => 'smtp', 'MAIL_HOST' => self::OPERATOR_HOST, 'MAIL_PORT' => '2525', 'MAIL_USERNAME' => self::OPERATOR_USER,
            'MAIL_FROM_ADDRESS' => 'ops@platform-operator-fixture.test', 's3_bucket' => self::OPERATOR_BUCKET, 'storage_setting' => 'local',
        ]);
        $this->setting($this->store, [
            'MAIL_DRIVER' => 'smtp', 'MAIL_HOST' => self::MERCHANT_HOST, 'MAIL_PORT' => '587', 'MAIL_USERNAME' => 'merchant-mailer-fixture',
            's3_bucket' => self::MERCHANT_BUCKET, 'storage_setting' => 'local',
        ]);
    }

    /** Raw rows, written before anything reads `settings` (the model is `Cachable`). */
    private function setting(Store $store, array $rows): void
    {
        foreach ($rows as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value, 'created_by' => $store->created_by, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * The P0 test's demotion, with its premise asserted. Needs `Edit User` (a real merchant's seeded `admin` role holds it).
     *
     * @return User the demoted merchant; their own store is `$user->current_store`
     */
    private function selfDemotedMerchant(): User
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit User');
        $role = Role::forceCreate([
            'name' => 'Manager', 'display_name' => 'Manager', 'guard_name' => 'web',
            'created_by' => $merchant->creatorId(), 'store_id' => $merchant->current_store,
        ]);

        $this->actingAs($merchant)->put("/users/{$merchant->id}", ['name' => $merchant->name, 'email' => $merchant->email, 'role' => $role->id]);

        $fresh = $merchant->fresh();
        $this->assertSame('Manager', $fresh->type, 'the self-demotion no longer happens, so these cases cannot reach the state they exist to test');
        $this->assertSame(1, (int) $fresh->creatorId(), "the principal's tenant root is no longer the platform, so the collapse is not being reproduced");

        // They keep the shared `admin` role in the real product; here the grant that matters is made explicit.
        $this->grantPermission($fresh, 'Manage Setting');

        return $fresh->fresh();
    }

    private function emailFields(User $user): string
    {
        $response = $this->actingAs($user)->postJson('/get-email-fields', ['emailsetting' => 'custom']);
        $response->assertOk();

        return (string) ($response->json('html') ?? '');
    }

    private function storageTab(User $user): string
    {
        $response = $this->actingAs($user)->postJson('/setting-form', ['tab_type' => 'storage_setting']);

        // `settingForm()` turns every exception into HTTP 200 with is_success = false and no content, so a "string absent" assertion on its own would pass for a
        // tab that threw. The tab must have RENDERED for the principal it is asked about.
        $this->assertTrue((bool) $response->json('is_success'), 'Premise: the storage tab rendered (it answered '.$response->getContent().').');

        return (string) $response->getContent();
    }

    public function test_a_self_demoted_merchant_is_not_served_the_platforms_mail_settings(): void
    {
        $demoted = $this->selfDemotedMerchant();

        $html = $this->emailFields($demoted);

        $this->assertStringNotContainsString(self::OPERATOR_HOST, $html, "The Settings screen printed the PLATFORM's mail host to a self-demoted merchant.");
        $this->assertStringNotContainsString(self::OPERATOR_USER, $html, "The Settings screen printed the PLATFORM's mail user to a self-demoted merchant.");
        $this->assertStringNotContainsString('ops@platform-operator-fixture.test', $html, "The Settings screen printed the PLATFORM's from-address to a self-demoted merchant.");
        // and not another tenant's either: an unresolved user must not fall through to "the lowest-id merchant" (P1-SEC-18 F4)
        $this->assertStringNotContainsString(self::MERCHANT_HOST, $html, "A self-demoted merchant was served ANOTHER merchant's mail host.");
    }

    public function test_a_self_demoted_merchant_is_not_served_the_platforms_storage_settings(): void
    {
        $demoted = $this->selfDemotedMerchant();

        $content = $this->storageTab($demoted);

        $this->assertStringNotContainsString(self::OPERATOR_BUCKET, $content, "The storage tab printed the PLATFORM's bucket to a self-demoted merchant.");
        $this->assertStringNotContainsString(self::MERCHANT_BUCKET, $content, "A self-demoted merchant was served ANOTHER merchant's storage bucket.");
    }

    public function test_staff_the_operator_created_are_not_served_the_platforms_settings_either(): void
    {
        $operatorsStaff = User::factory()->create([
            'type' => 'staff', 'created_by' => $this->operator->id, 'current_store' => $this->store->id, 'is_active' => 1, 'email_verified_at' => now(),
        ])->fresh();
        $this->grantPermission($operatorsStaff, 'Manage Setting');

        $html = $this->emailFields($operatorsStaff->fresh());
        $this->assertStringNotContainsString(self::OPERATOR_HOST, $html, "Staff the operator created were served the platform's mail host.");
        $this->assertStringNotContainsString(self::MERCHANT_HOST, $html, "Staff the operator created were served ANOTHER merchant's mail host.");
    }

    /**
     * THE RULE, NOT A LEAK: staff of a self-demoted merchant are rooted at a user who is no longer a merchant (their `created_by` is a `Manager`, id other
     * than 1). They resolve to that former merchant's own settings -- nothing cross-tenant -- but `UserPolicy::merchantTenantRoot()` calls their root nobody's
     * tenant (TenantRootSinksTest pins it for the ten sinks and the scope), and a helper that asked only "is the root the OPERATOR" would disagree with every
     * other copy of the question. This is the case that tells the two apart.
     */
    public function test_staff_of_a_self_demoted_merchant_are_not_served_their_employers_settings(): void
    {
        $demoted = $this->selfDemotedMerchant();
        $this->setting(Store::find($demoted->current_store), ['MAIL_HOST' => 'smtp.demoted-own-fixture.test', 'MAIL_PORT' => '25']);
        $staff = $this->makeStaff($demoted);
        $this->grantPermission($staff, 'Manage Setting');

        $html = $this->emailFields($staff->fresh());
        $this->assertStringNotContainsString('smtp.demoted-own-fixture.test', $html, "Staff of a self-demoted merchant were served a non-merchant root's settings.");
        $this->assertStringNotContainsString(self::OPERATOR_HOST, $html, "Staff of a self-demoted merchant were served the platform's mail host.");
        // and NOT another merchant's: a missing root must not fall through to the guest branches, whose last one serves the LOWEST-ID MERCHANT's settings
        // (P1-SEC-18 F4). This principal's root is a non-merchant that is NOT user 1, so it is the case a "refuse root 1, leave the null unguarded" half-fix
        // would serve -- the fifth review's surviving mutant.
        $this->assertStringNotContainsString(self::MERCHANT_HOST, $html, "Staff of a self-demoted merchant were served ANOTHER merchant's mail host (a missing root fell through to the lowest-id merchant).");
    }

    /**
     * THE REGRESSION THE FULL FEATURE RUN CAUGHT IN THE FIRST DRAFT, which no targeted test had touched: a `Customer` on the default guard (`actingAs($customer,
     * 'customers')` sets it) also reaches the branch where `$user->type` is not admin / super admin, and `merchantTenantRoot()` takes a `User`. The rule is about
     * admin-panel principals; a customer resolves its creator exactly as before, and must not throw.
     */
    public function test_a_customer_principal_still_resolves_its_creators_settings_and_does_not_throw(): void
    {
        $customer = \App\Models\Customer::forceCreate([
            'first_name' => 'Buyer', 'last_name' => 'Fixture', 'email' => 'buyer-'.uniqid().'@example.test', 'password' => bcrypt('secret'),
            'store_id' => $this->store->id, 'type' => 'customer', 'created_by' => $this->merchant->id,
        ]);
        $this->actingAs($customer, 'customers');

        $this->assertSame(self::MERCHANT_HOST, getAdminAllSetting()['MAIL_HOST'] ?? null, "Premise: a customer resolves its creator's (the store owner's) settings, as before.");
    }

    /**
     * THE PLATFORM'S OWN STOREFRONT: a customer of the operator's store has `created_by` = the operator, and "the creator" is the intended answer for them -- the
     * settings of the store they shop in. The merchant-root rule is for admin-panel principals; applied to a customer it would blank the currency format of every
     * priced cell on the platform's own `my-account` page (`currency_format_with_sym()` -> `getAdminAllSetting()`).
     */
    public function test_a_customer_of_the_operators_own_store_still_resolves_the_operators_settings(): void
    {
        $customer = \App\Models\Customer::forceCreate([
            'first_name' => 'Buyer', 'last_name' => 'Fixture', 'email' => 'buyer-'.uniqid().'@example.test', 'password' => bcrypt('secret'),
            'store_id' => $this->operatorStore->id, 'type' => 'customer', 'created_by' => $this->operator->id,
        ]);
        $this->actingAs($customer, 'customers');

        $this->assertSame(self::OPERATOR_HOST, getAdminAllSetting()['MAIL_HOST'] ?? null, "A customer of the platform's own store lost the settings of the store they shop in.");
    }

    /**
     * ONE READ OF THE EMPLOYER. The helper runs per priced cell of a staff member's list pages; the first form of the fix read the employer twice (a merchant test by id
     * and then the user), a query per cell. The merchant rule is asked of the row already in hand.
     */
    public function test_the_helper_reads_the_employer_once(): void
    {
        $staff = $this->makeStaff($this->merchant);
        $this->actingAs($staff->fresh());
        (new User())->flushCache();

        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        getAdminAllSetting();
        $reads = array_filter(\Illuminate\Support\Facades\DB::getQueryLog(), fn (array $q) => str_contains($q['query'], 'from "users"'));
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertCount(1, $reads, 'getAdminAllSetting() read the users table '.count($reads).' times for a staff principal: '.json_encode(array_column($reads, 'query')));
    }

    /** The controls: every refusal above could be a route that refuses everyone, or a fixture that never wrote the rows. */
    public function test_the_screens_still_serve_the_settings_they_always_did(): void
    {
        $staff = $this->makeStaff($this->merchant);
        $this->grantPermission($staff, 'Manage Setting');

        // a merchant's staff are served their EMPLOYER's settings -- the resolution `getAdminAllSetting()` exists to make
        $this->assertStringContainsString(self::MERCHANT_HOST, $this->emailFields($staff->fresh()), "Premise: a merchant's staff see their employer's mail host.");
        $this->assertStringNotContainsString(self::OPERATOR_HOST, $this->emailFields($staff->fresh()), "A merchant's staff were served the platform's mail host.");

        // the merchant sees their own
        $this->assertStringContainsString(self::MERCHANT_HOST, $this->emailFields($this->merchant->fresh()), 'Premise: a merchant sees their own mail host.');
        $this->assertStringContainsString(self::MERCHANT_BUCKET, $this->storageTab($this->merchant->fresh()), 'Premise: a merchant sees their own storage bucket.');

        // and the operator sees the platform's, through the path that was always theirs
        $this->assertStringContainsString(self::OPERATOR_HOST, $this->emailFields($this->operator->fresh()), 'Premise: the operator sees the platform mail host.');

        // The two screens above send the operator to `getSuperAdminAllSetting()`, never to the changed helper. THE CHANGED HELPER, called for the operator: the
        // exemption at its top (`super admin` is not rewritten to anybody's creator) must still hold, or every no-argument caller would get [] for the operator.
        $this->actingAs($this->operator->fresh());
        $this->assertSame(self::OPERATOR_HOST, getAdminAllSetting()['MAIL_HOST'] ?? null, 'Premise: getAdminAllSetting() still resolves the operator to themselves.');
        $this->actingAs($this->merchant->fresh());
        $this->assertSame(self::MERCHANT_HOST, getAdminAllSetting()['MAIL_HOST'] ?? null, 'Premise: getAdminAllSetting() still resolves a merchant to themselves.');
    }
}
