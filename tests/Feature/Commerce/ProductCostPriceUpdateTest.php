<?php

namespace Tests\Feature\Commerce;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-228 — THE EDIT FORM DEMANDS A COST PRICE, ACCEPTS IT, AND THROWS IT AWAY.
 * =============================================================================================
 *
 * MEASURED 2026-11-22 by direct read, while re-measuring `ANA-006`.
 * `ProductController::update()` (`:1714`) validates `cost_price` as
 * `required|numeric|min:0|lte:price` at `:1869`, with its own custom messages at `:1892-1893` —
 * and **never assigns it**. The only `cost_price` write in the whole 5,000-line file is `:837`,
 * inside `store()`. `cost_price` is not in `Product::$fillable`, so mass assignment does not save
 * it either.
 *
 * WHAT A MERCHANT EXPERIENCES. They open the edit form. The form REFUSES TO SUBMIT without a cost
 * price — the field is `required` and has a bespoke error message telling them so. They type one.
 * The save succeeds and reports success. The value is discarded in silence. There is no error, no
 * warning and no second place to enter it.
 *
 * WHY THIS IS A MONEY DEFECT AND NOT A FORM BUG. Every profit figure in the product subtracts
 * `products.cost_price`, and four merchant surfaces disclose how many paid orders have no cost on
 * record (`/finance`, `/analytics`, `/finance-reports`, and the analytics CSV). Because the only
 * write is on the create path, **that count can never be reduced by any action available to a
 * merchant**: a product that was created without a cost — imported, created before the column, or
 * created through any path but the simple branch of `store()` — carries no cost for ever, and the
 * disclosure that names it is permanent by construction.
 *
 * `ANA-006` is satisfied by DISCLOSING the omission, and that disclosure is correct. This is the
 * separate fact that the omission cannot be repaired, which no amount of honest labelling fixes.
 *
 * NOT A POLICY QUESTION. No threshold, rate or business rule is involved: the form already demands
 * the value, the column already exists, and the validation already states the rule
 * (`min:0|lte:price`). The only question was whether anyone had noticed, and the answer was no —
 * no test in the suite drove a cost change through the editor.
 */
class ProductCostPriceUpdateTest extends SecurityTestCase
{
    use RefreshDatabase;

    private $categoryId;

    private function arrange(): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();
        $merchant = User::find($merchant->id);

        $this->grantPermission($merchant, 'Edit Products');
        $this->grantPermission($merchant, 'Manage Products');

        $merchant->plan_id = DB::table('plans')->insertGetId([
            'name' => 'cost-plan-' . $this->uniqueFixtureRef(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'max_products' => 1000, 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->save();

        $this->categoryId = $this->makeCategory($store->id);

        return [User::find($merchant->id), $store];
    }

    private function makeCategory(int $storeId): int
    {
        return (int) DB::table('categories')->insertGetId([
            'name' => 'Textiles', 'slug' => 'textiles-' . $this->uniqueFixtureRef(),
            'trending' => 0, 'status' => 1, 'store_id' => $storeId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function productIn(Store $store, User $merchant, $cost = 40): Product
    {
        return Product::forceCreate([
            'name' => 'Cost ' . $this->uniqueFixtureRef(),
            'slug' => 'cost-' . $this->uniqueFixtureRef(),
            'description' => 'x', 'price' => 100, 'cost_price' => $cost,
            'track_stock' => 1, 'trending' => 0, 'stock_status' => 'in_stock',
            'product_stock' => 25, 'status' => 1, 'variant_product' => 0,
            'category_id' => $this->categoryId,
            'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);
    }

    /** The payload the edit form really posts, with the cost the merchant typed. */
    private function savePayload($cost, int $price = 150): array
    {
        return [
            'name' => 'Cost edited',
            'category_id' => $this->categoryId,
            'status' => 1,
            'variant_product' => 0,
            'price' => $price,
            'sale_price' => '',
            'cost_price' => $cost,
            'product_weight' => '',
            'product_stock' => 25,
            'track_stock' => 1,
            'stock_status' => 'in_stock',
            'trending' => 0,
            'description' => 'x',
        ];
    }

    /** Read WITHOUT the global scope — `Product` adds no predicate for anybody, but the habit is the rule. */
    private function freshCost($id)
    {
        $row = Product::withoutGlobalScopes()->disableCache()->find($id);

        return $row === null ? null : (float) $row->cost_price;
    }

    public function test_a_merchant_can_change_a_product_cost_price(): void
    {
        [$merchant, $store] = $this->arrange();
        $product = $this->productIn($store, $merchant, 40);

        $this->assertSame(40.0, $this->freshCost($product->id),
            'Premise: the fixture cost was not stored, so nothing below is about the editor.');

        $response = $this->actingAs($merchant)->put(
            route('product.update', $product->id),
            $this->savePayload(75)
        );

        $this->assertStringContainsString('"flag":"success"', (string) $response->getContent(),
            'Premise: the save was refused, so the cost below did not fail to persist — it was never attempted. Body: '
            . substr((string) $response->getContent(), 0, 300));

        $this->assertSame(75.0, $this->freshCost($product->id),
            'The edit form demanded a cost price, accepted it, reported success and discarded it. '
            . 'ProductController::update() validates cost_price at :1869 and never assigns it.');
    }

    /**
     * THE CASE THE DEFECT IS REALLY ABOUT: a product sitting at a cost of ZERO can be given a real
     * one.
     *
     * `products.cost_price` is NOT NULL, so "no cost on record" means 0 rather than NULL — checked
     * rather than assumed, after a first draft of this test tried to write NULL and SQLite refused
     * it with a NOT NULL constraint violation.
     *
     * WHAT THIS DOES NOT CLAIM. `store_order_financials.product_cost` is a SNAPSHOT taken at order
     * time, so fixing the product does not change orders already placed, and must not: those are
     * historical financial records. What it changes is that the product is no longer stuck at 0
     * for ever, so orders placed AFTER the correction snapshot a real cost instead of joining the
     * uncosted count. The first draft of this file asserted the stronger claim and was wrong.
     */
    public function test_a_product_sitting_at_zero_cost_can_be_given_a_real_one(): void
    {
        [$merchant, $store] = $this->arrange();
        $product = $this->productIn($store, $merchant, 0);

        $this->assertSame(0.0, $this->freshCost($product->id),
            'Premise: the fixture did not start at zero cost, so this is not the uncosted case.');

        $this->actingAs($merchant)->put(
            route('product.update', $product->id),
            $this->savePayload(60)
        );

        $this->assertSame(60.0, $this->freshCost($product->id),
            'A product sitting at a cost of 0 cannot be given a real one through the only form that offers the field, '
            . 'so every future order of it snapshots product_cost = 0 and is counted as uncosted for ever.');
    }

    /** A cost of zero is a real answer — "this costs me nothing" — and must not be read as absent. */
    public function test_a_cost_of_zero_is_saved_rather_than_ignored(): void
    {
        [$merchant, $store] = $this->arrange();
        $product = $this->productIn($store, $merchant, 40);

        $this->actingAs($merchant)->put(
            route('product.update', $product->id),
            $this->savePayload(0)
        );

        $this->assertSame(0.0, $this->freshCost($product->id),
            'A cost of 0 was treated as "no value supplied" and the old cost was left in place. '
            . 'The validation says min:0, so zero is valid input and means something.');
    }

    /**
     * THE GUARD THAT KEEPS THE FIX HONEST. `cost_price` is validated `lte:price`, so a cost above
     * the price must be refused — and refused means the STORED value is untouched, not that a
     * larger value is silently clamped.
     */
    public function test_a_cost_above_the_price_is_refused_and_changes_nothing(): void
    {
        [$merchant, $store] = $this->arrange();
        $product = $this->productIn($store, $merchant, 40);

        $this->actingAs($merchant)->put(
            route('product.update', $product->id),
            $this->savePayload(999, 150)
        );

        $this->assertSame(40.0, $this->freshCost($product->id),
            'A cost greater than the price was stored. The rule lte:price exists so a product cannot record a negative margin by typo.');
    }
}
