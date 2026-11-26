<?php

namespace Tests\Unit\Kyc;

use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\KycVerification;
use App\Services\Kyc\KycAccess;
use App\Services\Kyc\KycAuthority;
use App\Services\Kyc\KycRefusal;
use App\Services\Kyc\KycReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Kyc\MakesKycFixtures;
use Tests\TestCase;

/**
 * WHO MAY TOUCH IDENTITY PAPERS — THE GATE, DRIVEN DIRECTLY RATHER THAN THROUGH A ROUTE.
 * =================================================================================================
 *
 * THE METHODS ARE CALLED DIRECTLY, AND THAT IS THE POINT. CLAUDE.md: *"A HANDLER-LEVEL CHECK BEHIND
 * AN `abort()`ING MIDDLEWARE IS INVISIBLE TO AN HTTP TEST"* — the middleware answers 403 and the
 * handler never runs, so the route returns 403 whether or not the handler checks anything.
 * `PlanController::planDisable` ADMITTED a `Customer` typed `super admin` (200, plan disabled)
 * behind a gate whose HTTP tests were green. The HTTP doors are tested separately; this file tests
 * the rule.
 *
 * EVERY PRINCIPAL THE CODEBASE CAN PRODUCE IS DRIVEN, not just a merchant:
 *
 *   the operator                    admitted everywhere — `isAbleTo()` REFUSES them, so a gate that
 *                                   asked permissions first would lock the platform out.
 *   the merchant                    their own store only.
 *   staff with the permission       their employer's store only.
 *   staff without it                refused — the feature's permission is deliberately NOT seeded,
 *                                   so staff fail CLOSED until the merchant grants it.
 *   staff of another merchant       refused.
 *   a SELF-DEMOTED merchant         refused, and this is the account-takeover shape: a merchant can
 *                                   change their own `type` through the product, which drops
 *                                   `creatorId()` to 1 and used to make `sameTenant()` true against
 *                                   EVERY merchant. The fixture builds the operator as user 1 so
 *                                   the trap is real rather than absent.
 *   a `Customer`, a `DeliveryBoy`   refused — they reach handlers through `custom.auth`, which logs
 *                                   a token's owner into whichever guard matches the TOKENABLE
 *                                   CLASS, so a non-`User` principal really does arrive at panel
 *                                   code.
 *   an object merely TYPED          refused — the `instanceof` clause, not the string comparison,
 *   `'super admin'`                 is what stopped `plan.disable` admitting a `Customer`.
 */
class KycAccessGateTest extends TestCase
{
    use MakesKycFixtures;
    use RefreshDatabase;

    public function test_the_operator_may_reach_any_stores_documents(): void
    {
        $operator = $this->kycOperator();
        [, $store] = $this->kycMerchantWithStore();

        $this->assertTrue(KycAccess::permitsOwnDocuments($operator, $store->id));
        $this->assertTrue(KycAccess::permitsReview($operator));
        $this->assertTrue(KycAccess::permitsAnyStore($operator));
    }

    public function test_a_merchant_reaches_their_own_store_and_not_anothers(): void
    {
        [$merchantA, $storeA] = $this->kycMerchantWithStore();
        [, $storeB] = $this->kycMerchantWithStore();

        $this->assertTrue(KycAccess::permitsOwnDocuments($merchantA, $storeA->id));
        $this->assertFalse(
            KycAccess::permitsOwnDocuments($merchantA, $storeB->id),
            'a merchant reached another tenant\'s identity documents'
        );
    }

    /** A MERCHANT MAY NOT APPROVE THEIR OWN PAPERS. The invariant the whole record rests on. */
    public function test_a_merchant_may_not_review_even_their_own_record(): void
    {
        [$merchant, $store] = $this->kycMerchantWithStore();
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);

        $this->assertFalse(KycAccess::permitsReview($merchant));

        try {
            KycReview::decideVerification($record, $merchant, KycReview::APPROVE);
            $this->fail('a merchant approved their own verification');
        } catch (KycRefusal $e) {
            $this->assertSame('not_a_reviewer', $e->reason);
        }

        $this->assertSame(
            KycVerification::UNSUBMITTED,
            \Illuminate\Support\Facades\DB::table('kyc_verifications')->where('id', $record->id)->value('status')
        );
    }

    /** And the refused attempt leaves a row in the trail. */
    public function test_a_refused_review_is_recorded(): void
    {
        [$merchant, $store] = $this->kycMerchantWithStore();
        $record = $this->kycRecord($store, KycAuthority::STORE_SYRIA);

        try {
            KycReview::decideVerification($record, $merchant, KycReview::APPROVE);
        } catch (KycRefusal) {
            // expected
        }

        $row = \Illuminate\Support\Facades\DB::table('audit_logs')
            ->where('action', \App\Services\Kyc\KycAccessLog::REFUSED)
            ->first();

        $this->assertNotNull($row, 'an attempt to approve somebody\'s papers must leave a trace');
        $this->assertSame((int) $merchant->id, (int) $row->user_id);
        $this->assertSame((int) $store->id, (int) $row->store_id);
    }

    public function test_staff_without_the_permission_are_refused(): void
    {
        [$merchant, $store] = $this->kycMerchantWithStore();
        $staff = $this->kycStaff($merchant, $store);

        $this->assertFalse(
            KycAccess::permitsOwnDocuments($staff, $store->id),
            'the verification permission is deliberately not seeded, so staff must fail closed'
        );
    }

    public function test_staff_with_the_permission_reach_their_employers_store_only(): void
    {
        [$merchant, $store] = $this->kycMerchantWithStore();
        [, $otherStore] = $this->kycMerchantWithStore();
        $staff = $this->kycStaff($merchant, $store);

        $this->grantKycPermission($staff, KycAccess::PERMISSION, $store->id);

        $this->assertTrue(KycAccess::permitsOwnDocuments($staff, $store->id));
        $this->assertFalse(KycAccess::permitsOwnDocuments($staff, $otherStore->id));
        $this->assertFalse(
            KycAccess::permitsReview($staff),
            'the permission is about a store\'s OWN documents, never about reviewing them'
        );
    }

    /**
     * A STAFF GRANT IN STORE A DOES NOT REACH STORE B — the defect `MerchantPermissions` exists to
     * not have: `allPermissions()` unions every role with no store predicate.
     */
    public function test_a_grant_in_one_store_does_not_reach_another_store_of_the_same_merchant(): void
    {
        [$merchant, $storeA] = $this->kycMerchantWithStore();
        $storeB = \App\Models\Store::create([
            'name' => 'Second store',
            'email' => 'second-' . bin2hex(random_bytes(4)) . '@example.test',
            'theme_id' => 'stylique',
            'slug' => 'second-' . bin2hex(random_bytes(5)),
            'created_by' => $merchant->id,
            'is_active' => 1,
        ]);

        $staff = $this->kycStaff($merchant, $storeA);
        $this->grantKycPermission($staff, KycAccess::PERMISSION, $storeA->id);

        $this->assertTrue(KycAccess::permitsOwnDocuments($staff, $storeA->id));
        $this->assertFalse(
            KycAccess::permitsOwnDocuments($staff, $storeB->id),
            'a staff grant in store A reached store B'
        );
    }

    /**
     * THE SELF-DEMOTION TRAP, BUILT SO IT IS REAL. The operator is created FIRST so they are user 1,
     * which is what every merchant's `created_by` points at; without that the trap would be absent
     * from the fixture and this test would pass for the wrong reason.
     */
    public function test_a_self_demoted_merchant_reaches_nothing(): void
    {
        $operator = $this->kycOperator();
        $this->assertSame(1, (int) $operator->id, 'the fixture premise: the operator is user 1');

        [$merchant, $store] = $this->kycMerchantWithStore();
        $operatorStore = \App\Models\Store::create([
            'name' => 'Platform store',
            'email' => 'platform-' . bin2hex(random_bytes(4)) . '@example.test',
            'theme_id' => 'stylique',
            'slug' => 'platform-' . bin2hex(random_bytes(5)),
            'created_by' => $operator->id,
            'is_active' => 1,
        ]);

        $this->assertTrue(KycAccess::permitsOwnDocuments($merchant, $store->id));

        // The demotion a merchant can perform on themselves through PUT /users/{self}.
        $merchant->type = 'user';
        $merchant->save();
        $merchant->flushCache();

        /*
         * THE PERMISSION LIMB IS NEUTRALISED SO THE OWNERSHIP LIMB IS WHAT IS MEASURED, and the
         * first version of this test did NOT do that — a mutant replacing
         * `UserPolicy::merchantTenantRoot()` with a bare `creatorId()` SURVIVED it. With
         * `creatorId()` the demoted merchant's root is 1, the operator's store really is
         * `created_by = 1`, and the only thing still refusing them was that they held no
         * permission. `SecurityTestCase::grantPermission()`'s own docblock states the rule this
         * breaks: a test must *"isolate the OWNERSHIP defect from the permission defect"*.
         *
         * A `store_id`-NULL ROLE IS THE REALISTIC SHAPE, not a contrivance: `PermissionTableSeeder`
         * creates exactly one shared global role with a null store and hands it to every merchant,
         * and `MerchantPermissions` counts a null-store role in EVERY store by design (a merchant
         * must keep their own permissions across their stores). So the day the owner seeds this
         * feature's permission into that role — the obvious thing to do when the policy lands — the
         * ownership limb is the only thing left standing between a self-demoted merchant and the
         * platform's own identity documents.
         */
        $globalRole = \App\Models\Role::forceCreate([
            'name' => 'kyc-global-' . bin2hex(random_bytes(3)),
            'display_name' => 'Global',
            'guard_name' => 'web',
            'created_by' => $merchant->id,
            'store_id' => null,
        ]);
        $globalRole->givePermission(\App\Models\Permission::firstOrCreate(
            ['name' => KycAccess::PERMISSION],
            ['display_name' => KycAccess::PERMISSION]
        ));
        $merchant->addRole($globalRole);
        $merchant->flushCache();

        $this->assertTrue(
            \App\Services\MerchantPermissions::allows($merchant->fresh(), KycAccess::PERMISSION, $operatorStore->id),
            'fixture premise: the permission limb would admit them, so the refusal below is the '
            . 'ownership limb and nothing else'
        );

        $this->assertFalse(
            KycAccess::permitsOwnDocuments($merchant->fresh(), $operatorStore->id),
            'a self-demoted merchant whose creatorId() is now 1 reached the OPERATOR\'S store'
        );
        $this->assertNull(
            KycAccess::ownStore($merchant->fresh()),
            'a principal rooted at the platform has no own store and must not be handed one'
        );
    }

    /**
     * NON-`User` PRINCIPALS, INCLUDING ONE TYPED `'super admin'`.
     *
     * `custom.auth` logs a token's owner into whichever guard matches the TOKENABLE CLASS, so a
     * `Customer` or a `DeliveryBoy` really does reach panel code. And `plan.disable` admitted a
     * `Customer` typed `'super admin'` because its check was a string comparison that never asked
     * what class the principal was.
     */
    public function test_no_non_user_principal_is_admitted(): void
    {
        [, $store] = $this->kycMerchantWithStore();

        $customer = new Customer();
        $customer->type = 'super admin';

        $driver = new DeliveryBoy();
        $driver->type = 'super admin';

        $imposter = new \stdClass();
        $imposter->type = 'super admin';

        foreach ([null, $customer, $driver, $imposter, 'super admin', 1] as $principal) {
            $this->assertFalse(
                KycAccess::permitsOwnDocuments($principal, $store->id),
                'a non-User principal was admitted to identity documents'
            );
            $this->assertFalse(
                KycAccess::permitsReview($principal),
                'a non-User principal was admitted as a reviewer'
            );
            $this->assertNull(KycAccess::ownStore($principal));
        }
    }

    /** A store id that is not a store owns nothing, not even a folder called `0`. */
    public function test_a_store_id_that_is_not_a_store_is_refused(): void
    {
        $operator = $this->kycOperator();
        [$merchant] = $this->kycMerchantWithStore();

        foreach ([0, -1] as $notAStore) {
            $this->assertFalse(KycAccess::permitsOwnDocuments($operator, $notAStore));
            $this->assertFalse(KycAccess::permitsOwnDocuments($merchant, $notAStore));
        }
    }

    /**
     * THE OPERATOR HAS NO "OWN STORE" AND IS NOT HANDED STORE 1.
     *
     * `getCurrentStore()` returns a hardcoded `1` for `type === 'super admin'` — 1,024 call sites
     * depend on that and it is not changed — so a KYC door that used it would file the operator's
     * own uploads under whichever merchant happens to be store 1.
     */
    public function test_the_operator_is_not_handed_store_one_as_their_own(): void
    {
        $operator = $this->kycOperator();

        $this->assertNull(KycAccess::ownStore($operator));
    }

    /** A merchant's own store resolves from `current_store`, and only when they really own it. */
    public function test_own_store_comes_from_the_principal_and_is_verified(): void
    {
        [$merchant, $store] = $this->kycMerchantWithStore();
        [, $otherStore] = $this->kycMerchantWithStore();

        $this->assertSame((int) $store->id, KycAccess::ownStore($merchant));

        // A current_store pointing at somebody else's store is not honoured.
        $merchant->current_store = $otherStore->id;
        $merchant->save();
        $merchant->flushCache();

        $this->assertSame(
            (int) $store->id,
            KycAccess::ownStore($merchant->fresh()),
            'current_store naming another tenant\'s store must fall back to a store they own'
        );
    }
}
