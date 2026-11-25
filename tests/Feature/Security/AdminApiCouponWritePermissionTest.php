<?php

namespace Tests\Feature\Security;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * GAP-142 / P4-F065 — THE THREE COUPON WRITERS IN THE ADMIN API HAD NO PERMISSION CHECK.
 * =================================================================================================
 *
 * `AddCoupon` (`:2063`), `UpdateCoupon` (`:2104`) and `DeleteCoupon` (`:2159`) each resolve TENANCY
 * — `resolveOwnedStore()` refuses a foreign store id and the per-row lookups carry a `store_id`
 * predicate — and then ask nothing about who inside the store may act. So any authenticated member
 * of the tenant, holding no coupon permission of any kind, could mint a coupon, change an existing
 * coupon's `discount_amount` (written verbatim at `:2093`) or delete a live campaign.
 *
 * THIS IS PRICE-AFFECTING, which is why the refusal cases below read the row back rather than only
 * the status. A coupon's `discount_amount`, `coupon_limit` and `coupon_expiry_date` are what a
 * shopper's basket is reduced by; `UpdateCoupon` assigns each one straight from the request when the
 * key is present. A 403 that returns AFTER `$coupon->save()` is the failure mode that matters.
 *
 * -------------------------------------------------------------------------------------------------
 * THE NAMES ARE THE WEB TWIN'S OWN, PER HANDLER, AND NOT ONE BROAD NAME FOR ALL THREE.
 *
 * `CouponController` gates `store()` on `Create Coupon` (`:89`), `update()` on `Edit Coupon`
 * (`:417`) and `destroy()` on `Delete Coupon` (`:483`), and all four of
 * `Manage/Create/Edit/Delete Coupon` are seeded by `Utility.php` in the merchant block. Each API
 * handler therefore takes its own twin's name.
 *
 * `Manage Coupon` IS ALSO ACCEPTED ON EACH, and that needs its own justification rather than being
 * waved at as "the broad one". `CouponController::index()` and `show()` gate the coupon SCREEN on
 * `Manage Coupon`, so a staff member holding only that name already reads every coupon in the
 * store, `discount_amount` included. Admitting it to the writes is wider than the web and it is
 * stated as a deliberate choice: the admin API is the Flutter app's only coupon surface, it has no
 * separate read screen, and a merchant who ticked the single `Manage Coupon` box for a staff member
 * expects that person to be able to run coupons in the app. Refusing them would be a NEW refusal
 * for a principal who has that power on the web today, which this row is not authorised to take.
 * If the owner later decides the API must match the web's narrower split, delete `Manage Coupon`
 * from the three gates and this suite's `test_staff_holding_manage_coupon_*` cases are what change.
 *
 * -------------------------------------------------------------------------------------------------
 * THE OPERATOR IS ADMITTED HERE AND THE WEB REFUSES THEM, AND THAT DIVERGENCE IS DELIBERATE.
 *
 * `CouponController::refuseOperatorWrite()` refuses the platform operator every coupon write by
 * name, on the owner's decision of 2026-10-28 ("read and export only"). The API does NOT adopt
 * that, and the reason is not symmetry but direction of change: the operator can write coupons
 * through these three routes TODAY, because `resolveOwnedStore()` admits them to any store and
 * nothing else asked a question. Adding a gate that refuses them would ride an unstated second
 * decision — a withdrawal of a live capability — on top of a security fix that was asked to close
 * a permission hole. GAP-142's recorded next action is "gate the remaining routes with the
 * operator-first predicate already in `MerchantPermissions::allowsAny`", and that predicate
 * answers `WILDCARD` for the operator.
 *
 * So this is RECORDED, NOT RESOLVED, and `test_the_platform_operator_still_writes_coupons` pins the
 * current behaviour so that a later owner decision to extend F157 to the API is a visible change to
 * a named test rather than a silent one.
 *
 * -------------------------------------------------------------------------------------------------
 * THE REFUSAL IS AN EXPLICIT 403 and the assertions read `status` as well.
 *
 * These three handlers refuse everything else through `$this->error()`, which is HTTP **200** with
 * `{status: 0}` — so a refusal in that shape is invisible to a client. The gate answers
 * `orderPermissionDenied()`'s real 403 instead. 401 is never used: the shipped Flutter app
 * force-clears its session on one.
 */
class AdminApiCouponWritePermissionTest extends SecurityTestCase
{
    /** Distinctive values, so a "the row did not change" assertion cannot pass on a coincidence. */
    private const ORIGINAL_DISCOUNT = 17.50;

    private const ATTACKER_DISCOUNT = 99.99;

    private const ORIGINAL_CODE = 'GAP142-ORIGINAL';

    use RefreshDatabase;

    /** @return array{0:User,1:Store,2:int} the merchant, their store, and a live coupon's id */
    private function tenantWithACoupon(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $couponId = (int) DB::table('coupons')->insertGetId([
            'coupon_name' => 'GAP-142 fixture coupon',
            'coupon_type' => 'fixed',
            'discount_amount' => self::ORIGINAL_DISCOUNT,
            'coupon_limit' => 5,
            'coupon_expiry_date' => now()->addMonth()->toDateString(),
            'coupon_code' => self::ORIGINAL_CODE,
            'status' => 1,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [User::find($merchant->id), $store, $couponId];
    }

    private function staffIn(User $owner, Store $store, ?string $permission = null): User
    {
        $staff = $this->makeStaff($owner);
        $staff->current_store = $store->id;
        $staff->save();

        if ($permission !== null) {
            $this->grantPermission(User::find($staff->id), $permission);
        }

        return User::find($staff->id);
    }

    private function as(User $user)
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . $user->createToken('auth_token')->plainTextToken
        );
    }

    private function addCoupon(User $user, Store $store, string $code)
    {
        return $this->as($user)->postJson('/api/admin/addcoupon', [
            'store_id' => $store->id,
            'coupon_name' => 'minted by ' . $user->id,
            'coupon_type' => 'fixed',
            'discount_amount' => self::ATTACKER_DISCOUNT,
            'coupon_limit' => 100,
            'coupon_expiry_date' => now()->addYear()->toDateString(),
            'coupon_code' => $code,
            'status' => 1,
        ]);
    }

    private function updateCoupon(User $user, Store $store, int $couponId)
    {
        return $this->as($user)->postJson('/api/admin/updatecoupon', [
            'store_id' => $store->id,
            'coupon_id' => $couponId,
            'discount_amount' => self::ATTACKER_DISCOUNT,
        ]);
    }

    private function deleteCoupon(User $user, Store $store, int $couponId)
    {
        return $this->as($user)->deleteJson('/api/admin/deletecoupon', [
            'store_id' => $store->id,
            'coupon_id' => $couponId,
        ]);
    }

    /**
     * The coupon row read from the TABLE.
     *
     * `Coupon` is `Cachable` and is NOT in `config/tenancy.php`'s `scoped_models`, so a plain
     * `Coupon::find()` here would answer from the model cache under `CACHE_STORE=array` and keep
     * serving a pre-write row for the rest of the test — three measured false readings in this
     * codebase. The query builder is the only read that is a statement about the table.
     */
    private function row(int $couponId): ?object
    {
        return DB::table('coupons')->where('id', $couponId)->first();
    }

    /**
     * The live discount, AS A NUMBER.
     *
     * A STRING COMPARISON HERE IS A FALSE READING WAITING TO HAPPEN, and it happened on the first
     * run of this suite: `coupons.discount_amount` holds `17.50`, and SQLite's numeric affinity
     * hands it back as the string `'17.5'` while MySQL's `decimal` hands back `'17.50'`. The
     * tenancy case failed against a tenancy control that was working perfectly -- the same shape of
     * defect as asserting a non-200 status on an `ApiResponser::error()` refusal, which is the trap
     * this suite's sibling fell into. The question is "is the price still 17.50", so the comparison
     * is numeric and engine-independent.
     */
    private function discount(int $couponId): float
    {
        return (float) $this->row($couponId)->discount_amount;
    }

    // ════════════════════════════════════════════════════════════════════════════════ THE DEFECT

    /** `discount_amount` is what a basket is reduced by, and an unpermitted staff member set it. */
    public function test_a_staff_member_without_a_coupon_permission_cannot_change_a_discount(): void
    {
        [$merchant, $store, $couponId] = $this->tenantWithACoupon();
        $staff = $this->staffIn($merchant, $store);

        $response = $this->updateCoupon($staff, $store, $couponId);

        $this->assertSame(403, $response->status(),
            'a staff member holding no coupon permission rewrote a coupon\'s discount through the '
            . 'admin API. Body: ' . substr((string) $response->getContent(), 0, 300));

        /* AND THE PRICE DID NOT MOVE -- asserted separately, because a 403 returned after
           `$coupon->save()` is the failure mode that matters. */
        $this->assertEqualsWithDelta(self::ORIGINAL_DISCOUNT, $this->discount($couponId), 0.001,
            'the refused call still wrote discount_amount, so the coupon is live at a value the '
            . 'caller was not permitted to set');
    }

    public function test_a_staff_member_without_a_coupon_permission_cannot_delete_a_coupon(): void
    {
        [$merchant, $store, $couponId] = $this->tenantWithACoupon();
        $staff = $this->staffIn($merchant, $store);

        $response = $this->deleteCoupon($staff, $store, $couponId);

        $this->assertSame(403, $response->status(),
            'a staff member holding no coupon permission deleted a live coupon. Body: '
            . substr((string) $response->getContent(), 0, 300));

        $this->assertNotNull($this->row($couponId),
            'the refused call still deleted the coupon');
    }

    public function test_a_staff_member_without_a_coupon_permission_cannot_mint_a_coupon(): void
    {
        [$merchant, $store] = $this->tenantWithACoupon();
        $staff = $this->staffIn($merchant, $store);

        $response = $this->addCoupon($staff, $store, 'MINTED-BY-UNPERMITTED');

        $this->assertSame(403, $response->status(),
            'a staff member holding no coupon permission minted a coupon. Body: '
            . substr((string) $response->getContent(), 0, 300));

        $this->assertSame(0, DB::table('coupons')->where('coupon_code', 'MINTED-BY-UNPERMITTED')->count(),
            'the refused call still inserted the coupon, so a discount the caller was not '
            . 'permitted to create is redeemable');
    }

    /** 403, NEVER 401, on all three — the shipped app force-clears its session on a 401. */
    public function test_none_of_the_three_refusals_is_a_401(): void
    {
        [$merchant, $store, $couponId] = $this->tenantWithACoupon();
        $staff = $this->staffIn($merchant, $store);

        foreach ([
            'addcoupon' => $this->addCoupon($staff, $store, 'NOT-A-401'),
            'updatecoupon' => $this->updateCoupon($staff, $store, $couponId),
            'deletecoupon' => $this->deleteCoupon($staff, $store, $couponId),
        ] as $route => $response) {
            $this->assertNotSame(401, $response->status(),
                $route . ': a 401 logs the merchant out of the whole app');
            $this->assertSame(403, $response->status(),
                $route . ': the refusal is not an explicit 403. ApiResponser::error() answers HTTP '
                . '200 with {status: 0}, which no client distinguishes from success');
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════════ THE CONTROLS

    /** The store's own owner still runs their own coupons, holding no coupon permission row. */
    public function test_the_store_owner_still_writes_their_own_coupons(): void
    {
        [$merchant, $store, $couponId] = $this->tenantWithACoupon();

        $this->assertSame(200, $this->addCoupon($merchant, $store, 'OWNER-MINTED')->status(),
            'the gate refused the store\'s OWN owner on addcoupon');
        $this->assertSame(200, $this->updateCoupon($merchant, $store, $couponId)->status(),
            'the gate refused the store\'s OWN owner on updatecoupon');
        $this->assertSame(200, $this->deleteCoupon($merchant, $store, $couponId)->status(),
            'the gate refused the store\'s OWN owner on deletecoupon');

        /* AND THE WRITES ACTUALLY HAPPENED, so a 200 from a gate that silently did nothing cannot
           pass as a control. */
        $this->assertSame(1, DB::table('coupons')->where('coupon_code', 'OWNER-MINTED')->count());
        $this->assertNull($this->row($couponId), 'the owner got a 200 but the delete did not run');
    }

    /** Staff holding each web twin's own name — `Create`/`Edit`/`Delete Coupon` — still write. */
    public function test_staff_holding_the_web_twins_name_still_write(): void
    {
        [$merchant, $store, $couponId] = $this->tenantWithACoupon();

        $creator = $this->staffIn($merchant, $store, 'Create Coupon');
        $this->assertSame(200, $this->addCoupon($creator, $store, 'CREATE-COUPON-STAFF')->status(),
            '`Create Coupon` is what CouponController::store() names, and it was refused on addcoupon');

        $editor = $this->staffIn($merchant, $store, 'Edit Coupon');
        $this->assertSame(200, $this->updateCoupon($editor, $store, $couponId)->status(),
            '`Edit Coupon` is what CouponController::update() names, and it was refused on updatecoupon');
        $this->assertEqualsWithDelta(self::ATTACKER_DISCOUNT, $this->discount($couponId), 0.001,
            'the permitted editor got a 200 but the discount did not change, so the gate is '
            . 'short-circuiting the handler rather than admitting it');

        $deleter = $this->staffIn($merchant, $store, 'Delete Coupon');
        $this->assertSame(200, $this->deleteCoupon($deleter, $store, $couponId)->status(),
            '`Delete Coupon` is what CouponController::destroy() names, and it was refused on deletecoupon');
        $this->assertNull($this->row($couponId));
    }

    /**
     * And `Manage Coupon`, which the web coupon SCREEN uses — see the class docblock on why it is
     * admitted to the writes here and what changes if the owner decides otherwise.
     */
    public function test_staff_holding_manage_coupon_still_write(): void
    {
        [$merchant, $store, $couponId] = $this->tenantWithACoupon();
        $staff = $this->staffIn($merchant, $store, 'Manage Coupon');

        /*
         * EACH 200 IS PAIRED WITH A ROW READ, and the independent review was right to ask for it:
         * these handlers refuse everything except the gate through `$this->error()`, which is HTTP
         * **200** with `{status: 0}`. So `assertSame(200, ...)` alone cannot tell "admitted" from
         * "refused", and this case would still have passed if somebody narrowed the gate AND
         * converted its refusal to `error()`. The row is the only witness that the handler ran.
         */
        $this->assertSame(200, $this->addCoupon($staff, $store, 'MANAGE-COUPON-STAFF')->status());
        $this->assertNotNull(
            /* `coupon_code`, not `code` -- read from the migration after this very assertion failed
               against a working gate and briefly looked like the widener was broken. Checking the
               column rather than believing the failure is the whole point of adding the row read. */
            \Illuminate\Support\Facades\DB::table('coupons')->where('coupon_code', 'MANAGE-COUPON-STAFF')->first(),
            'the holder of `Manage Coupon` got a 200 and no coupon was minted, so the gate is '
            . 'short-circuiting the handler rather than admitting it'
        );

        $this->assertSame(200, $this->updateCoupon($staff, $store, $couponId)->status());
        $this->assertEqualsWithDelta(self::ATTACKER_DISCOUNT, $this->discount($couponId), 0.001,
            'the holder of `Manage Coupon` got a 200 and the discount did not change');

        $this->assertSame(200, $this->deleteCoupon($staff, $store, $couponId)->status());
        $this->assertNull(\Illuminate\Support\Facades\DB::table('coupons')->where('id', $couponId)->first(),
            'the holder of `Manage Coupon` got a 200 and the coupon survived');
    }

    /**
     * The platform operator still writes. RECORDED DIVERGENCE: the web refuses them every coupon
     * write (F157, owner 2026-10-28). See the class docblock — this pins what the API does today so
     * that extending that decision to the API is a visible change to this named test.
     */
    public function test_the_platform_operator_still_writes_coupons_PENDING_AN_OWNER_DECISION(): void
    {
        [, $store, $couponId] = $this->tenantWithACoupon();

        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);

        /*
         * THE NAME CARRIES `PENDING_AN_OWNER_DECISION` DELIBERATELY. The independent review pointed
         * out that `test_the_platform_operator_still_writes_coupons` READS AS AN APPROVAL of
         * behaviour the owner explicitly withheld on the web surface -- `CouponController::
         * refuseOperatorWrite()` cites "the owner's decision of 2026-10-28" -- and that the
         * authority cited for admitting them here (GAP-142's "gate these routes with the
         * operator-first predicate") is authority about WHICH PREDICATE to use, not about whether
         * the operator may write coupons. It is right. This pins what the API does today so the
         * divergence is visible; it does not claim the divergence is correct.
         */
        $this->assertSame(200, $this->updateCoupon($operator, $store, $couponId)->status(),
            'the platform operator was refused. `isAbleTo()` has no super-admin bypass, which is '
            . 'why the gate goes through MerchantPermissions -- it answers WILDCARD for them. If '
            . 'this refusal is INTENDED it is the owner\'s F157 decision reaching the API, which '
            . 'is a product change and not a side effect of closing a permission hole.');

        /* AND THE WRITE REALLY HAPPENED -- a 200 here is also what `error()` answers. */
        $this->assertEqualsWithDelta(self::ATTACKER_DISCOUNT, $this->discount($couponId), 0.001,
            'the operator got a 200 and the discount did not change');
    }

    // ══════════════════════════════════════════════════════════════════════ TENANCY IS UNCHANGED

    /**
     * A member of another tenant holding `Edit Coupon` in their OWN store is still refused, and
     * `resolveOwnedStore()` refuses them long before any permission question — so this proves the
     * two axes are independent rather than that either works.
     *
     * ASSERTED ON `status` IN THE ENVELOPE, NOT ON A NON-200 HTTP STATUS. The tenancy refusal in
     * these handlers is `$this->error(['message' => 'Store not found!'])`, which is HTTP **200**
     * with `{status: 0}` (`ApiResponser::error()`). A `assertNotSame(200, ...)` here fails against
     * working code — the measured trap that the first draft of
     * `AdminApiOrderViewPermissionTest`'s equivalent case fell into.
     */
    public function test_another_tenants_member_is_still_refused_and_changes_no_price(): void
    {
        [, $store, $couponId] = $this->tenantWithACoupon();

        $outsiderMerchant = $this->makeMerchant();
        $outsiderStore = $this->makeStore($outsiderMerchant);
        $outsiderMerchant->current_store = $outsiderStore->id;
        $outsiderMerchant->save();
        $outsider = $this->staffIn(User::find($outsiderMerchant->id), $outsiderStore, 'Edit Coupon');

        $response = $this->updateCoupon($outsider, $store, $couponId);

        $this->assertSame(0, $response->json('status'),
            'a member of another tenant was answered with a success envelope for this store\'s '
            . 'coupon while holding `Edit Coupon` in their OWN store');
        $this->assertEqualsWithDelta(self::ORIGINAL_DISCOUNT, $this->discount($couponId), 0.001,
            'the cross-tenant call changed another tenant\'s discount');

        $deletion = $this->deleteCoupon($outsider, $store, $couponId);
        $this->assertSame(0, $deletion->json('status'));
        $this->assertNotNull($this->row($couponId),
            'the cross-tenant call deleted another tenant\'s coupon');
    }
}
