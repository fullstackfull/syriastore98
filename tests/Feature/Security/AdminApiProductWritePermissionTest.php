<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * GAP-250 / P4-F119 — `POST api/admin/updateproduct` LET ANY EMPLOYEE REWRITE EVERY PRICE.
 * =============================================================================================
 *
 * Found by the behavioural route census of 2026-11-25 (group 4), driving the route with an
 * unpermissioned staff member of the owning store and reading the row back:
 *
 *     price          100.00  ->  1.00
 *     sale_price      90.00  ->  1.00
 *     cost_price      40.00  ->  1.00      <-- the merchant's margin
 *     product_stock      50  ->  0
 *
 * A money write, by a principal holding no permission at all.
 *
 * ---------------------------------------------------------------------------------------------
 * WHY IT SURVIVED, AND WHY IT IS NOT THE SAME AS THE OPERATOR FIX ABOVE IT.
 *
 * The handler already carries TWO controls and neither is this one:
 *
 *   `OperatorCatalogueWrite::refuses()` (F206, 2026-10-30) withdraws the door from the **platform
 *   operator** — the opposite axis. Its own comment records that this door "had no permission check
 *   of any kind", which was true then and stayed true for every other principal.
 *
 *   `resolveOwnedStore()` settles **tenancy**, and admits the owner's staff BY DESIGN — that is
 *   what it is for. So a store's employees were inside both controls.
 *
 * THE SHARPEST FACT ABOUT THIS ROW: `searchProductsByName`, one route over, was gated on
 * `Manage Products` the same day **precisely because it READS `cost_price`** (GAP-142 / P4-F111).
 * The route that WRITES `cost_price` was not in the census that produced that work, because that
 * census was a list of nine named families rather than a measurement of the population. This is the
 * concrete cost of the difference, and the reason the behavioural census exists.
 *
 * ---------------------------------------------------------------------------------------------
 * THE NAME IS `Edit Products`, PLURAL, AND THAT IS MEASURED RATHER THAN GUESSED.
 *
 * It is what the web twin gates on (`ProductController:1646`) and it is seeded in both of
 * `Utility.php`'s arrays. The singular `Edit Product` is seeded NOWHERE — a gate naming it would
 * admit nobody a merchant can grant, which is the defect `DashboardPermissionNamesTest` exists for
 * and which this batch already produced once (`GAP-246`, withdrawn: a single-quote-only search
 * missed the double-quoted product names).
 *
 * The limb sits BELOW both existing controls, so the operator still gets `OperatorCatalogueWrite`'s
 * own refusal and a foreign tenant still gets `Store not found!` — neither message changes.
 */
class AdminApiProductWritePermissionTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const ORIGINAL = ['price' => 100, 'sale_price' => 90, 'cost_price' => 40, 'product_stock' => 50];

    private User $merchant;

    private Store $store;

    private Product $product;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * AN OPERATOR ROW FIRST, which is not decoration. `makeMerchant()` sets `created_by => 1`,
         * so without a platform admin occupying id 1 the first merchant created IS user 1 and
         * `merchantTenantRoot()` collapses onto it — the `created_by = 1` trap CLAUDE.md records as
         * an account-takeover. The group-4 census first reported a takeover on a sibling route for
         * exactly this reason and corrected it; this fixture starts in the production shape.
         */
        $this->makePlatformAdmin();
        \Cache::forget('admin_details');

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        /*
         * A REAL CATEGORY, AND THIS IS THE FIXTURE THAT MADE THE FIRST VERSION OF THIS FILE LIE.
         *
         * I passed `category_id => 1` in the payload with no such row. The handler then refused
         * EVERY principal, so the defect case passed (the row was unchanged — but for the wrong
         * reason) while both positive controls failed. That is the exact shape this batch has now
         * hit three times: a fixture that never reaches the code under test, with the result reading
         * as a verdict. The premise assertion in `test_the_write_path_is_reachable_at_all` is the
         * guard, and it is deliberately the FIRST test in the file.
         */
        $this->categoryId = (int) DB::table('categories')->insertGetId([
            'name' => 'F119-CATEGORY', 'slug' => 'f119-cat-' . uniqid(),
            'store_id' => $this->store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->product = Product::forceCreate(array_merge([
            'name' => 'F119-PRODUCT', 'slug' => 'f119-' . uniqid(),
            'description' => 'x', 'detail' => 'x',
            'track_stock' => 1, 'variant_product' => 0, 'stock_status' => 'in_stock',
            'trending' => 0, 'status' => 1, 'category_id' => $this->categoryId,
            'store_id' => $this->store->id, 'created_by' => $this->merchant->id,
        ], self::ORIGINAL));
    }

    private function staffIn(?string $permission = null): User
    {
        $staff = $this->makeStaff($this->merchant);
        $staff->current_store = $this->store->id;
        $staff->save();
        $staff = User::find($staff->id);

        if ($permission !== null) {
            $this->grantPermission($staff, $permission);
            $staff = User::find($staff->id);
        }

        return $staff;
    }

    /** The attacker's payload: every money column at 1, stock at 0. */
    private function rewrite(User $actor): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer ' . $actor->createToken('app')->plainTextToken)
            ->postJson('/api/admin/updateproduct', [
                'store_id' => $this->store->id,
                'product_id' => $this->product->id,
                'name' => 'F119-PRODUCT', 'category_id' => $this->categoryId, 'description' => 'x',
                'price' => 1, 'sale_price' => 1, 'cost_price' => 1, 'product_stock' => 0,
                'trending' => 0, 'variant_product' => 0, 'status' => 1,
            ]);
    }

    /** @return array<string,mixed> the row, read raw — Product is Cachable and NOT tenant-scoped. */
    private function row(): array
    {
        return (array) DB::table('products')->where('id', $this->product->id)->first();
    }

    /**
     * THE PREMISE, AND IT COMES FIRST ON PURPOSE: the write path is reachable at all.
     *
     * Without this, a fixture that cannot reach the write makes the defect case below pass while
     * proving nothing — which is precisely what the first version of this file did. If this fails,
     * every other verdict here is void.
     */
    public function test_the_write_path_is_reachable_at_all(): void
    {
        $response = $this->rewrite(User::find($this->merchant->id));

        $this->assertSame(1, (int) $response->json('status'), sprintf(
            'the store OWNER could not reach the write at all (HTTP %d, body %s), so this fixture '
            . 'does not drive updateproduct and no verdict in this file means anything. Check the '
            . 'category id and the validator rules first.',
            $response->status(),
            mb_substr((string) $response->getContent(), 0, 200)
        ));

        $this->assertSame(1.0, (float) $this->row()['price'],
            'the owner got a success envelope and the price did not change');
    }

    /** THE DEFECT: a staff member holding nothing rewrote every price. */
    public function test_an_unpermissioned_staff_member_cannot_rewrite_the_prices(): void
    {
        $response = $this->rewrite($this->staffIn());

        $row = $this->row();

        foreach (self::ORIGINAL as $column => $expected) {
            $this->assertSame((float) $expected, (float) $row[$column], sprintf(
                'a staff member of the store holding NO permission rewrote `products.%s` '
                . '(%s -> %s). This is a MONEY WRITE: `price`, `sale_price`, the merchant\'s own '
                . '`cost_price` margin and `product_stock`, by a principal who was granted nothing. '
                . 'The handler\'s two existing controls are a different axis -- '
                . 'OperatorCatalogueWrite refuses the platform OPERATOR, and resolveOwnedStore '
                . 'admits the owner\'s staff by design. HTTP %d, body %s',
                $column,
                $expected,
                $row[$column],
                $response->status(),
                mb_substr((string) $response->getContent(), 0, 160)
            ));
        }
    }

    /** AND THE REFUSAL MUST NOT BE A 401 — the shipped app force-clears its session on one. */
    public function test_the_refusal_is_not_a_401(): void
    {
        $this->assertNotSame(401, $this->rewrite($this->staffIn())->status(),
            'updateproduct refused an unpermitted staff member with a 401, which signs the '
            . 'merchant out of the whole app instead of telling them they lack a right');
    }

    /** CONTROL — AND IT IS THE POINT: staff GRANTED `Edit Products` still write. Without this the
     *  test above would also pass against a gate that admits nobody, which is the regression this
     *  family has already produced. The ROW is the assertion, because on this API a refusal can be
     *  an HTTP 200. */
    public function test_staff_granted_edit_products_still_write(): void
    {
        $response = $this->rewrite($this->staffIn('Edit Products'));

        $row = $this->row();

        $this->assertSame(1.0, (float) $row['price'], sprintf(
            'staff GRANTED `Edit Products` could not update the product, so the gate admits nobody '
            . 'a merchant can actually grant. `Edit Products` is the plural, seeded name the web '
            . 'twin uses (ProductController:1646); the singular is seeded nowhere. HTTP %d, body %s',
            $response->status(),
            mb_substr((string) $response->getContent(), 0, 160)
        ));
        $this->assertSame(0.0, (float) $row['product_stock']);
    }

    /** CONTROL: the store's own owner still writes, holding no role at all. */
    public function test_the_store_owner_still_writes(): void
    {
        $this->rewrite(User::find($this->merchant->id));

        $this->assertSame(1.0, (float) $this->row()['price'],
            'the store\'s own owner was refused their own catalogue. A merchant does not implicitly '
            . 'hold Edit Products through a fixture, so without the store-owner limb this gate locks '
            . 'every fixture merchant out -- and any real merchant whose role was edited.');
    }

    /** CONTROL: the operator refusal is UNCHANGED and is still its own message, not the permission
     *  one. F206 withdrew this door from the operator deliberately; the new limb must not convert
     *  that decision into a permission refusal. */
    public function test_the_operator_still_gets_the_catalogue_refusal_not_the_permission_one(): void
    {
        $operator = $this->makePlatformAdmin(['email' => 'f119-operator@example.test']);

        $response = $this->rewrite(User::find($operator->id));

        $this->assertSame(
            \App\Services\OperatorCatalogueWrite::message(),
            (string) data_get($response->json(), 'data.message', data_get($response->json(), 'message')),
            'the platform operator stopped getting OperatorCatalogueWrite\'s own refusal, so the '
            . 'new permission limb has been placed ABOVE F206\'s decision and reworded it'
        );

        $this->assertSame(100.0, (float) $this->row()['price'],
            'the operator wrote the catalogue, which F206 withdrew');
    }

    /** CONTROL: a foreign tenant still gets the TENANCY refusal, unchanged. */
    public function test_a_foreign_tenant_still_gets_the_store_refusal(): void
    {
        $outsider = $this->makeMerchant();
        $outsiderStore = $this->makeStore($outsider);
        $outsider->current_store = $outsiderStore->id;
        $outsider->save();

        $response = $this->rewrite(User::find($outsider->id));

        $this->assertStringContainsString('Store not found', (string) $response->getContent(),
            'a caller from another tenant got something other than the STORE refusal, so the '
            . 'permission limb has been hoisted above resolveOwnedStore()');

        $this->assertSame(100.0, (float) $this->row()['price']);
    }
}
