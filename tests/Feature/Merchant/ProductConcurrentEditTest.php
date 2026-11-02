<?php

namespace Tests\Feature\Merchant;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-03-5 -- a second save does not silently discard the first person's work.
 * =============================================================================================
 *
 * THE RACE. Two people open the same product. A saves. B saves. B's form carries the values it
 * loaded BEFORE A's write, so B's save overwrites A's -- price, stock, everything -- and both of
 * them are told it worked. On a screen with a dozen fields the loser usually never finds out.
 *
 * `updated_at` IS THE TOKEN. No migration: the column is already there, and Workstream G's rule
 * is not to add one when an existing column answers the question. The edit form emits the value
 * it loaded; the controller refuses a save whose token does not match the row's current value.
 *
 * THE REFUSAL IS A JSON BODY, NOT A REDIRECT, and that is load-bearing rather than incidental.
 * `resources/views/product/edit.blade.php` submits this route with `$.ajax` in both of its
 * branches and reads `data.flag` / `data.msg` in `success`; it never navigates on its own. A
 * `redirect()->back()` is followed by jQuery, so the handler would be handed the edit page's HTML,
 * read `data.flag` as undefined, and raise a BLANK error toast -- the merchant told nothing, by
 * the mechanism that exists to tell them. So every assertion below reads the RESPONSE BODY. An
 * assertion on the session would pass against the broken shape, which is how the first version of
 * this file passed against a defect.
 *
 * `flag => 'error'` ALONE IS NOT ENOUGH to assert. Every validation failure in `update()` returns
 * the same flag, so a refusal test that checked only the flag would pass if the save were rejected
 * for a missing field instead -- the conflict check never reached. The message is asserted too.
 *
 * NOT AN AUTHORIZATION CONTROL, deliberately. A missing or unparseable token lets the save
 * through, because failing closed on it would refuse every client that posts the form without
 * the field -- the API and the Flutter app among them -- and this exists to prevent DATA LOSS,
 * not access. The ownership and permission checks are separate and unchanged; cross-tenant
 * product writes are covered by P1-INV-01's own tests.
 */
class ProductConcurrentEditTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * A real category row. `products.category_id` carries a FOREIGN KEY, so posting an id that
     * does not exist fails the UPDATE with a constraint violation rather than a validation
     * message -- measured, and it is why this exists rather than a literal 1.
     */
    private function makeCategory(int $storeId): int
    {
        // Columns read from the live schema: `categories` has NO `created_by` -- unlike
        // `products`, which requires one. Assuming the two tables agree costs an insert error.
        return (int) \DB::table('categories')->insertGetId([
            'name' => 'Textiles',
            'slug' => 'textiles-' . uniqid(),
            'trending' => 0,
            'status' => 1,
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeProduct(int $storeId, int $createdBy, string $name = 'Scarf'): Product
    {
        return Product::forceCreate([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            // NO `cost_price` HERE. It is one of GATE F's fifteen unmanaged tables/columns --
            // live in production, declared by no migration (the draft is parked at
            // database/migrations/_gate_f_pending/0004_draft_add_cost_price_to_products_table.php.draft),
            // so it does not exist on the test schema and the insert fails outright. It stays in
            // the PAYLOAD below because the update's validator requires it as a request field.
            'track_stock' => 0,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 5,
            'product_type' => null,
            'status' => 1,
            'variant_product' => 0,
            'store_id' => $storeId,
            'created_by' => $createdBy,
        ]);
    }

    /** The fields the update's validator requires for a non-variant product. */
    private function payload(array $overrides = []): array
    {
        /*
         * `trending` and `track_stock` are here because the controller writes them STRAIGHT
         * from the request into NOT NULL columns, so omitting them is a 500 rather than a
         * validation message -- measured. `cost_price` is required by the validator but has no
         * column on this schema (GATE F), which is why it is in the payload and not the fixture.
         */
        return array_merge([
            'name' => 'Renamed by B',
            'category_id' => 1,
            'status' => 1,
            'variant_product' => 0,
            'price' => 20,
            'sale_price' => 0,
            'cost_price' => 5,
            'trending' => 0,
            'track_stock' => 0,
            'product_stock' => 5,
            'stock_status' => 'in_stock',
            'low_stock_threshold' => 0,
        ], $overrides);
    }

    public function test_a_save_carrying_a_stale_token_is_refused_and_changes_nothing(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Products');

        $categoryId = $this->makeCategory($store->id);
        $product = $this->makeProduct($store->id, $merchant->id);

        // B opens the form and holds the token it loaded.
        $staleToken = $product->updated_at->toJSON();

        /*
         * TIME HAS TO MOVE. `updated_at` is second-precision on this engine, so a second save
         * in the same second produces the SAME token and the premise below fails -- measured,
         * and it is the reason this line is here rather than an incidental detail.
         */
        $this->travel(2)->seconds();

        // A saves first. Through Eloquent so the model cache is flushed: Product is Cachable and
        // a raw write would leave reads serving the pre-write row.
        $product->forceFill(['name' => 'Renamed by A'])->save();
        $product->refresh();

        // PREMISE: the row really did move, or there is no conflict to detect and this test
        // would pass against a controller that checks nothing.
        $this->assertNotSame($staleToken, $product->updated_at->toJSON(),
            'premise: the first save must have moved updated_at');

        $response = $this->actingAs($merchant)->put(
            route('product.update', $product->id),
            $this->payload(['category_id' => $categoryId, 'ss_last_seen_at' => $staleToken])
        );

        /*
         * The shape the page reads. `assertOk()` matters on its own: `update()` catches nothing
         * here, so a 500 would otherwise be invisible to the row assertions below -- "the row
         * did not change" is satisfied by a handler that crashed before writing.
         */
        $response->assertOk();
        $response->assertJson(['flag' => 'error']);
        $this->assertStringContainsString('Someone else saved this product',
            (string) $response->json('msg'),
            'the save was refused, but not BY THE CONFLICT CHECK -- a validation failure returns '
            . 'the same flag, so this assertion is what distinguishes the two');

        // THE POINT: A's write survives. Asserted through DB::table() because Product is
        // Cachable and an Eloquent read can serve a mutated in-memory instance.
        $row = \DB::table('products')->where('id', $product->id)->first();

        $this->assertSame('Renamed by A', $row->name, "B's stale save overwrote A's work");
        $this->assertSame(10.0, (float) $row->price, 'the price was overwritten by a stale save');
    }

    public function test_a_save_carrying_the_current_token_goes_through(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Products');

        $categoryId = $this->makeCategory($store->id);
        $product = $this->makeProduct($store->id, $merchant->id);

        $response = $this->actingAs($merchant)->put(
            route('product.update', $product->id),
            $this->payload([
                'category_id' => $categoryId,
                'ss_last_seen_at' => $product->updated_at->toJSON(),
            ])
        );

        $response->assertOk();
        $response->assertJson(['flag' => 'success']);

        $row = \DB::table('products')->where('id', $product->id)->first();
        $this->assertSame('Renamed by B', $row->name, 'a current-token save must not be refused');
    }

    /**
     * NO TOKEN MEANS NO CHECK. The API and the Flutter app post this form without the field, and
     * refusing them would be a compatibility break dressed up as a safety feature.
     */
    public function test_a_save_with_no_token_is_not_refused(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Products');

        $categoryId = $this->makeCategory($store->id);
        $product = $this->makeProduct($store->id, $merchant->id);
        $product->forceFill(['name' => 'Moved on'])->save();

        $response = $this->actingAs($merchant)->put(
            route('product.update', $product->id),
            $this->payload(['category_id' => $categoryId])
        );

        $response->assertOk();
        $response->assertJson(['flag' => 'success']);

        $row = \DB::table('products')->where('id', $product->id)->first();
        $this->assertSame('Renamed by B', $row->name);
    }

    /**
     * An unparseable token is treated as absent, not as a conflict.
     *
     * `Carbon::parse('not-a-date')` throws, and the `catch (\Throwable)` around it is what makes
     * that a no-op rather than a refusal. ASSERTING THE ABSENCE OF A REFUSAL IS NOT ENOUGH: a
     * caught exception that aborted the save, or a 500, would both show "no refusal" too. So this
     * asserts the save SUCCEEDED and the row actually moved -- the positive fact.
     */
    public function test_an_unparseable_token_is_treated_as_absent(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Products');

        $categoryId = $this->makeCategory($store->id);
        $product = $this->makeProduct($store->id, $merchant->id);

        $response = $this->actingAs($merchant)->put(
            route('product.update', $product->id),
            $this->payload(['category_id' => $categoryId, 'ss_last_seen_at' => 'not-a-date'])
        );

        $response->assertOk();
        $response->assertJson(['flag' => 'success']);

        $row = \DB::table('products')->where('id', $product->id)->first();
        $this->assertSame('Renamed by B', $row->name,
            'an unparseable token must let the save through, not swallow it');
    }

    /** The edit form emits the token, or the whole mechanism is inert in the browser. */
    public function test_the_edit_form_carries_the_token(): void
    {
        $view = (string) file_get_contents(base_path('resources/views/product/edit.blade.php'));

        // Comment-proofed: the Blade comment above the field explains the mechanism and names it.
        $view = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $view);

        $this->assertStringContainsString('name="ss_last_seen_at"', $view,
            'the product edit form no longer emits the concurrent-edit token, so the check in '
            . 'ProductController::update can never fire from the browser');
    }
}
