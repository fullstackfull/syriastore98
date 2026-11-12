<?php

namespace Tests\Feature\Security;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Directive §3 — explicit authorization on the sensitive StoreController operations.
 * =================================================================================================
 *
 * `GET /stores/{id}/edit` and `PUT /stores/{id}` administer OTHER PEOPLE'S stores. `update()` in
 * particular rewrites:
 *
 *   * `users.email` of the store's owner — an account-takeover primitive, because changing the
 *     address then lets password reset be driven against the new one;
 *   * `stores.slug` — the storefront's public identity, and the key every storefront handler
 *     resolves its tenant from (`getStore($slug)`, `getRouteStoreId()`).
 *
 * Shipped, both were gated on `auth()->user()->type == 'super admin'` and nothing else: no
 * permission, no policy, and no null handling, so an unresolvable id was a 500 on
 * `$store->created_by` rather than a refusal.
 *
 * WHAT THE CHANGE ACTUALLY FIXED — MEASURED, because the directive's premise was broader than
 * the defect and saying so is more useful than implying a bigger repair.
 *
 * Run against the SHIPPED controller, 8 cases: **6 pass, 2 fail.** The six that pass were already
 * passing. `type == 'super admin'` genuinely refused merchants and staff, and the route group
 * `['auth', 'xss', 'setlocate', 'verified']` admits any authenticated user to the method, which
 * then turned them away. So there was **no cross-tenant authorization hole here** — unlike the
 * product reads in `ProductReadTenancyTest`, which leaked for real.
 *
 * The two that fail are the defects:
 *
 *   * `PUT /stores/{unknown}` — 500, `$store->created_by` on null;
 *   * `GET /stores/{unknown}/edit` — 500, `$user->id` on null.
 *
 * A 500 on an authorization surface is worth fixing on its own, and the tenant predicate work
 * elsewhere in this change makes the null path ordinary rather than exceptional. Beyond that, the
 * change centralises an operator test that was repeated inline — which is how `UserPolicy` came
 * to have four methods checking operator status four different ways.
 *
 * **So treat the six as REGRESSION PINS, not as evidence of a repair.** They exist because this
 * surface rewrites a merchant's email and their storefront's slug, and nothing stopped a future
 * edit from loosening it. They are not proof that something was broken and is now fixed.
 *
 * WHAT THIS FILE PINS, AND WHAT IT DELIBERATELY DOES NOT.
 *
 * It pins that the surface stays operator-only and that the two null paths refuse. It does NOT
 * assert `UserPolicy::isOperator()` semantics here, because the operator test in this controller
 * is deliberately left byte-identical to what shipped — `== 'super admin'`. Normalising it would
 * ADMIT an actor whose `type` is `'Super Admin'` or carries a trailing space and who is refused
 * today, and the owner instruction of 2026-11-11 is *"Do not broaden operator permissions."*
 * `test_an_operator_spelling_mysql_accepts_is_still_refused_here` pins that gap as a KNOWN,
 * DELIBERATE divergence so that a future consistency pass has to read this note before closing it.
 * That divergence is reported as an owner decision; it is not a defect this file is hiding.
 */
class StoreAdministrationAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Store} a victim merchant and their store */
    private function victim(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $merchant->forceFill(['email' => 'victim-' . $merchant->id . '@example.test'])->save();
        $merchant->flushCache();

        return [$merchant->fresh(), $store];
    }

    /** The payload that rewrites a store's identity and its owner's email. */
    private function takeoverPayload(): array
    {
        return [
            'name' => 'Attacker Controlled',
            'storename' => 'Attacker Store',
            'email' => 'attacker@example.test',
        ];
    }

    private function assertStoreUntouched(Store $store, User $owner, string $why): void
    {
        // Raw table, not Eloquent. `Store` is not in config/tenancy.php's `scoped_models` and has
        // no `store_id` of its own, and `User` is unscoped too, so a plain `Store::find()` here
        // would answer against whichever tenant happened to resolve rather than against the table.
        $storeRow = DB::table('stores')->where('id', $store->id)->first();
        $userRow = DB::table('users')->where('id', $owner->id)->first();

        $this->assertNotSame('Attacker Store', $storeRow->name, $why . ' (stores.name was rewritten)');
        $this->assertSame($store->slug, $storeRow->slug, $why . ' (stores.slug was rewritten — this is the storefront tenant key)');
        $this->assertNotSame('attacker@example.test', $userRow->email, $why . ' (the owner\'s email was rewritten — account takeover)');
        $this->assertNotSame('Attacker Controlled', $userRow->name, $why . ' (users.name was rewritten)');
    }

    // ---------------------------------------------------------------------------------------
    // Refusals
    // ---------------------------------------------------------------------------------------

    public function test_a_merchant_cannot_open_another_tenants_store_edit_form(): void
    {
        [$victim] = $this->victim();
        [$attacker] = $this->makeTenant();

        $response = $this->actingAs($attacker)->get('/stores/' . $victim->id . '/edit');

        $this->assertNotEquals(
            200,
            $response->getStatusCode(),
            'A merchant opened the platform store-administration form for another tenant. '
            . 'This surface is operator-only: it hands over the victim\'s name and email and '
            . 'posts to a handler that rewrites both.'
        );
    }

    public function test_a_merchant_cannot_rewrite_another_tenants_store_or_its_owners_email(): void
    {
        [$victim, $victimStore] = $this->victim();
        [$attacker] = $this->makeTenant();

        $this->actingAs($attacker)->put('/stores/' . $victimStore->id, $this->takeoverPayload());

        $this->assertStoreUntouched(
            $victimStore,
            $victim,
            'A merchant rewrote another tenant\'s store through PUT /stores/{id}, which was '
            . 'gated on a bare type string comparison with no permission and no policy'
        );
    }

    public function test_permissionless_staff_cannot_rewrite_a_store_either(): void
    {
        [$victim, $victimStore] = $this->victim();
        [$owner] = $this->makeTenant();
        $staff = $this->makeStaff($owner);

        $this->actingAs(User::find($staff->id))
            ->put('/stores/' . $victimStore->id, $this->takeoverPayload());

        $this->assertStoreUntouched(
            $victimStore,
            $victim,
            'A staff member with no permissions rewrote a store and its owner\'s email'
        );
    }

    public function test_an_operator_spelling_mysql_accepts_is_still_refused_here(): void
    {
        // PINNED DIVERGENCE, NOT AN OVERSIGHT. MySQL's utf8mb4_unicode_ci treats 'Super Admin' as
        // equal to 'super admin', so this row IS the platform operator to the database and to
        // every query shaped `where('type', 'super admin')`. This controller's `==` refuses it.
        //
        // Resolving that by normalising would ADMIT an actor currently refused, which the owner
        // instruction of 2026-11-11 forbids: "Do not broaden operator permissions." So the
        // divergence is pinned here rather than closed, and a consistency pass that wants to
        // change it has to come through this test and the note on
        // StoreController::callerMayAdministerStores() first.
        [$victim, $victimStore] = $this->victim();

        $oddlyTyped = $this->makeMerchant();
        $oddlyTyped->type = 'Super Admin';
        $oddlyTyped->save();
        $oddlyTyped->flushCache();

        $this->actingAs(User::find($oddlyTyped->id))
            ->put('/stores/' . $victimStore->id, $this->takeoverPayload());

        $this->assertStoreUntouched(
            $victimStore,
            $victim,
            'A user whose type is "Super Admin" reached this surface. That may look like the '
            . 'right outcome, but it is a BROADENING of who may administer stores and it is not '
            . 'authorised. If the intent is to accept that spelling, raise it as an owner '
            . 'decision — do not relax the comparison to make a consistency pass tidy'
        );
    }

    // ---------------------------------------------------------------------------------------
    // Null handling -- these were 500s, and a 500 on an authorization surface is a finding
    // ---------------------------------------------------------------------------------------

    public function test_an_unknown_store_id_is_refused_rather_than_erroring(): void
    {
        $operator = $this->makePlatformAdmin();

        $response = $this->actingAs(User::find($operator->id))
            ->put('/stores/99999999', $this->takeoverPayload());

        $this->assertLessThan(
            500,
            $response->getStatusCode(),
            'PUT /stores/{unknown} raised a server error. `Store::find($id)` was dereferenced '
            . 'unguarded on `$store->created_by`. The tenant predicate added in this change makes '
            . 'the null path ordinary rather than exceptional, so it has to answer properly.'
        );
    }

    public function test_an_unknown_user_id_on_the_edit_form_is_refused_rather_than_erroring(): void
    {
        $operator = $this->makePlatformAdmin();

        $response = $this->actingAs(User::find($operator->id))->get('/stores/99999999/edit');

        $this->assertLessThan(
            500,
            $response->getStatusCode(),
            'GET /stores/{unknown}/edit raised a server error. `User::find($id)` was dereferenced '
            . 'unguarded on `$user->id`. Note this route takes a USER id while PUT /stores/{id} '
            . 'takes a STORE id — see the notes on both methods.'
        );
    }

    // ---------------------------------------------------------------------------------------
    // The legitimate path -- a guard that breaks the feature gets reverted
    // ---------------------------------------------------------------------------------------

    public function test_the_platform_operator_can_still_administer_a_store(): void
    {
        [$merchant, $store] = $this->victim();
        $operator = $this->makePlatformAdmin();

        $this->actingAs(User::find($operator->id))->put('/stores/' . $store->id, [
            'name' => 'Renamed Owner',
            'storename' => 'Renamed Store',
            'email' => 'renamed@example.test',
        ]);

        $storeRow = DB::table('stores')->where('id', $store->id)->first();
        $userRow = DB::table('users')->where('id', $merchant->id)->first();

        $this->assertSame(
            'Renamed Store',
            $storeRow->name,
            'The platform operator can no longer administer a store. The guards added here must '
            . 'only refuse MORE than the shipped code did — if this fails they refuse the one '
            . 'principal the surface exists for, and someone will revert the whole change.'
        );

        $this->assertSame('Renamed Owner', $userRow->name, 'the owner name update must still land');
        $this->assertSame('renamed@example.test', $userRow->email, 'the owner email update must still land');
    }

    public function test_the_operator_can_still_open_the_store_edit_form(): void
    {
        [$merchant] = $this->victim();
        $operator = $this->makePlatformAdmin();

        // Reached with the OWNER'S USER ID, which is what this route takes.
        $response = $this->actingAs(User::find($operator->id))->get('/stores/' . $merchant->id . '/edit');

        $this->assertLessThan(
            400,
            $response->getStatusCode(),
            'The operator can no longer open the store edit form. It is reached with the store '
            . 'OWNER\'S user id, not the store id — if this fails with a 404 check that first.'
        );
    }
}
