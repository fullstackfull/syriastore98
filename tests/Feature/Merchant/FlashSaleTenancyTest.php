<?php

namespace Tests\Feature\Merchant;

use App\Models\FlashSale;
use App\Models\FlashSaleCondition;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F266 — the coupon sibling's defect, on the resource the earlier pass did not reach.
 *
 * `FlashSale` is absent from `config/tenancy.php`'s `scoped_models` — only `FlashSaleCondition`
 * is allowlisted — so `StoreScope` adds no predicate to the sale itself. Every handler resolved
 * one by bare id, and route-model binding is the same thing with nicer syntax.
 *
 *   create()   had NO CHECK OF ANY KIND, between an `index()` that requires `Manage Flash Sale`
 *              and a `store()` that requires `Create Flash Sale`.
 *   edit()     rendered another tenant's sale, and 500d on an unknown id.
 *   update()   wrote another tenant's discount.
 *   destroy()  deleted another tenant's sale AND their `FlashSaleCondition` rows, because that
 *              delete carried no store predicate either.
 *
 * `CouponController` records the identical gap as F157 and already carries the fix.
 *
 * THE TWO TRAPS THIS FILE WAS WRITTEN AGAINST, both of which bit the shipping tests first:
 *
 *   - A REQUEST THAT NEVER REACHES THE HANDLER satisfies every "it is unchanged" assertion. The
 *     shipping tests POSTed to a `Route::resource` update that is PUT|PATCH and passed while the
 *     router rejected them. `assertReachedHandler()` fails on 405 and 500.
 *   - A FIXTURE THAT MODELS NOTHING passes for free. The order-panel tests built an order whose
 *     `product_json` was `'[]'`, so the panel rendered zero rows and three absence assertions
 *     were vacuous. Every victim row here is asserted to EXIST before it is asserted unchanged.
 *
 * Victim reads go through `withoutGlobalScopes()` and every `Cachable` read-back is preceded by a
 * `flushCache()`.
 */
class FlashSaleTenancyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchantA;

    private Store $storeA;

    private Store $storeB;

    private FlashSale $saleB;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->merchantA, $this->storeA] = $this->tenant();

        $merchantB = $this->makeMerchant();
        $this->storeB = $this->makeStore($merchantB, ['default_language' => 'en']);

        $this->saleB = $this->makeSale($this->storeB, 'Store B winter sale', '40');
    }

    /** @return array{0: User, 1: Store} */
    private function tenant(): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();

        foreach ([
            'Manage Flash Sale', 'Create Flash Sale', 'Edit Flash Sale', 'Delete Flash Sale',
        ] as $permission) {
            $this->grantPermission(User::find($merchant->id), $permission);
        }

        return [User::find($merchant->id), $store];
    }

    private function makeSale(Store $store, string $name, string $amount): FlashSale
    {
        $sale = FlashSale::forceCreate([
            'name' => $name,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'start_time' => '00:00',
            'end_time' => '23:59',
            'sale_product' => 'all',
            'discount_amount' => $amount,
            'discount_type' => 'percentage',
            'store_id' => $store->id,
            // `flash_sales.created_by` is NOT NULL with no default.
            'created_by' => (int) $store->created_by,
        ]);

        FlashSaleCondition::forceCreate([
            'flashsale_id' => $sale->id,
            'store_id' => $store->id,
        ]);

        (new FlashSale)->flushCache();

        return $sale;
    }

    private function victim(): FlashSale
    {
        (new FlashSale)->flushCache();

        return FlashSale::withoutGlobalScopes()->findOrFail($this->saleB->id);
    }

    private function assertReachedHandler(\Illuminate\Testing\TestResponse $response, string $what): void
    {
        $this->assertNotSame(405, $response->getStatusCode(),
            "{$what}: the router rejected the verb, so the handler never ran and whatever this "
            . 'test asserts afterwards is vacuous');
        $this->assertNotSame(500, $response->getStatusCode(), "{$what}: the handler raised a 500");
    }

    // ---------------------------------------------------------------------------------------
    //  THE PREMISE
    // ---------------------------------------------------------------------------------------

    public function test_the_fixture_really_builds_a_victim_row(): void
    {
        $this->assertSame('40', (string) $this->victim()->discount_amount);
        $this->assertSame(1, FlashSaleCondition::withoutGlobalScopes()
            ->where('flashsale_id', $this->saleB->id)->count(),
            'the victim has no condition row, so the delete assertion below would be vacuous');
    }

    // ---------------------------------------------------------------------------------------
    //  THE CROSS-TENANT WRITE AND DELETE
    // ---------------------------------------------------------------------------------------

    public function test_another_tenant_cannot_rewrite_this_stores_discount(): void
    {
        $response = $this->actingAs($this->merchantA)
            ->put(route('flash-sale.update', $this->saleB->id), [
                'name' => 'Taken',
                'discount_type' => 'percentage',
                'discount_amount' => '99',
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'start_time' => '00:00',
                'end_time' => '23:59',
                'sale_product' => 'all',
            ]);
        $this->assertReachedHandler($response, 'cross-tenant flash sale update');

        $this->assertSame('40', (string) $this->victim()->discount_amount,
            "store A rewrote store B's flash-sale discount");
    }

    public function test_another_tenant_cannot_delete_this_stores_flash_sale(): void
    {
        $response = $this->actingAs($this->merchantA)
            ->delete(route('flash-sale.destroy', $this->saleB->id));
        $this->assertReachedHandler($response, 'cross-tenant flash sale delete');

        $this->assertNotNull(FlashSale::withoutGlobalScopes()->find($this->saleB->id),
            "store B's flash sale was deleted by store A");
    }

    /**
     * THE SECOND HALF OF THE DELETE, and it was a separate predicate.
     * `FlashSaleCondition::where('flashsale_id', …)->delete()` carried no store filter, so the
     * victim's conditions went even when their sale survived.
     */
    public function test_another_tenants_flash_sale_conditions_are_not_deleted(): void
    {
        $this->actingAs($this->merchantA)
            ->delete(route('flash-sale.destroy', $this->saleB->id));

        $this->assertSame(1, FlashSaleCondition::withoutGlobalScopes()
            ->where('flashsale_id', $this->saleB->id)->count(),
            "store B's flash-sale conditions were deleted by store A");
    }

    public function test_another_tenants_flash_sale_is_not_readable(): void
    {
        $response = $this->actingAs($this->merchantA)
            ->get(route('flash-sale.edit', $this->saleB->id));

        $this->assertNotSame(500, $response->getStatusCode(),
            'the edit form raised a 500 rather than refusing');
        $this->assertStringNotContainsString('Store B winter sale',
            (string) $response->getContent(),
            "store B's flash sale was rendered on store A's edit form");
    }

    // ---------------------------------------------------------------------------------------
    //  THE PERMISSION THAT DID NOT EXIST
    // ---------------------------------------------------------------------------------------

    public function test_the_create_form_is_no_longer_open_to_anyone(): void
    {
        $staff = $this->makeStaff($this->merchantA, ['current_store' => $this->storeA->id]);
        $this->grantPermission(User::find($staff->id), 'Manage Blog');

        $response = $this->actingAs(User::find($staff->id))->get(route('flash-sale.create'));

        $this->assertNotSame(200, $response->getStatusCode(),
            'the flash-sale create form had no check of any kind and still has none');
    }

    public function test_the_platform_operator_is_refused_a_flash_sale_write(): void
    {
        $operator = $this->makeMerchant(['type' => 'super admin']);
        $operator->current_store = $this->storeB->id;
        $operator->save();

        /* THE GRANT IS WHAT MAKES THIS MEAN ANYTHING — without it `isAbleTo()` refuses the
           operator anyway and deleting the operator limb would change nothing the test can see.
           That exact vacuity was found in the shipping sibling by an independent review. */
        $this->grantPermission(User::find($operator->id), 'Edit Flash Sale');
        (new User)->flushCache();

        $response = $this->actingAs(User::find($operator->id))
            ->put(route('flash-sale.update', $this->saleB->id), [
                'name' => 'Operator edit',
                'discount_type' => 'percentage',
                'discount_amount' => '5',
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'start_time' => '00:00',
                'end_time' => '23:59',
                'sale_product' => 'all',
            ]);
        $this->assertReachedHandler($response, 'operator flash sale write');

        $this->assertSame('40', (string) $this->victim()->discount_amount);
    }

    public function test_the_platform_operator_may_still_open_a_flash_sale_form(): void
    {
        $operator = $this->makeMerchant(['type' => 'super admin']);
        $operator->current_store = $this->storeB->id;
        $operator->save();
        (new User)->flushCache();

        $response = $this->actingAs(User::find($operator->id))
            ->get(route('flash-sale.edit', $this->saleB->id));

        $this->assertSame(200, $response->getStatusCode(),
            'the operator can no longer open a flash-sale form they could open before this change');
    }

    // ---------------------------------------------------------------------------------------
    //  AND THE GATE IS NOT A DELETION
    // ---------------------------------------------------------------------------------------

    public function test_a_permitted_merchant_still_edits_their_own_flash_sale(): void
    {
        $own = $this->makeSale($this->storeA, 'Store A summer sale', '10');

        $response = $this->actingAs($this->merchantA)
            ->put(route('flash-sale.update', $own->id), [
                'name' => 'Store A summer sale',
                'discount_type' => 'percentage',
                'discount_amount' => '15',
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'start_time' => '00:00',
                'end_time' => '23:59',
                'sale_product' => 'all',
            ]);
        $this->assertReachedHandler($response, 'permitted own-store update');

        (new FlashSale)->flushCache();
        $this->assertSame('15',
            (string) FlashSale::withoutGlobalScopes()->findOrFail($own->id)->discount_amount,
            'the gate refuses the merchant their own flash sale, so it is a deletion rather than '
            . 'a control');
    }

    public function test_a_permitted_merchant_still_opens_their_own_forms(): void
    {
        $own = $this->makeSale($this->storeA, 'Store A summer sale', '10');

        $this->actingAs($this->merchantA)->get(route('flash-sale.create'))->assertStatus(200);
        $this->actingAs($this->merchantA)->get(route('flash-sale.edit', $own->id))->assertStatus(200);
    }

    /**
     * F267 — the edit form 500d on a condition row whose JSON column was null.
     *
     * `@foreach (json_decode($sale_condition->condition) as …)` and `json_decode(null)` is null,
     * so the form answered 500 rather than opening. The surrounding guard tests that the ROW
     * exists, which is a different question from whether its one meaningful column holds
     * anything — and `json_decode` answers null for malformed JSON too.
     *
     * Pre-existing, not introduced here: the handler reached the same view before this change.
     * Found because the anti-deletion case drove the real form, which is what those cases are for.
     */
    public function test_the_edit_form_opens_for_a_sale_whose_condition_json_is_null(): void
    {
        $own = $this->makeSale($this->storeA, 'Store A summer sale', '10');

        $condition = FlashSaleCondition::withoutGlobalScopes()
            ->where('flashsale_id', $own->id)->firstOrFail();
        $this->assertNull($condition->condition,
            'the fixture no longer models a null condition, so this test proves nothing');

        $this->actingAs($this->merchantA)
            ->get(route('flash-sale.edit', $own->id))
            ->assertStatus(200);
    }

    public function test_an_unknown_id_is_refused_rather_than_raising_a_500(): void
    {
        $response = $this->actingAs($this->merchantA)->get(route('flash-sale.edit', 999999));

        $this->assertNotSame(500, $response->getStatusCode());
    }
}
