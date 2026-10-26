<?php

namespace Tests\Feature\Merchant;

use App\Models\Product;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F59 / F61 / F62 -- `update()` wrote NULL into NOT NULL columns, and created tags with no slug.
 * =============================================================================================
 *
 * FOUR COLUMNS, ONE SHAPE. `update()` assigned `$product->X = $request->Y` straight into a
 * NOT NULL column for `track_stock` (F59), `stock_status` and `product_stock` (F61) and
 * `trending` (F61, third of its kind), while `store()` guarded the same assignments. A column
 * DEFAULT does not rescue an EXPLICIT NULL, so each one was:
 *
 *     SQLSTATE[23000]: NOT NULL constraint failed: products.<column>      (SQLite, and CI)
 *     silently coerced to the implicit default                            (production, strict off)
 *
 * and `update()` has no try/catch, so on SQLite the merchant got a bare 500.
 *
 * WHY NO TEST CAUGHT THEM, which is the useful part. `ProductConcurrentEditTest::payload()`
 * carries all four keys, and its own comment says they are there *because* omitting them is a
 * 500 -- so the suite had measured the defect, worked around it in a fixture, and moved on. A
 * payload that always posts every field cannot find a handler that depends on it. These tests
 * therefore REMOVE one key at a time, which is the only shape that reaches the branch.
 *
 * `assertOk()` IS LOAD-BEARING, not decoration. Without it, "the stored value is unchanged"
 * passes just as well when the endpoint 500s and writes nothing -- the trap this project has
 * recorded more than once. Each case asserts the save SUCCEEDED and then that the value
 * survived.
 *
 * ON THE TAG SIDE: two of the four places the controller creates a `Tag` never set the NOT NULL
 * `tags.slug` (F62), reachable from the UI only once F16 let select2 load and turned the tag
 * field into a free-text control. The driven case below covers the non-variant branch; the
 * variant branch needs `choice_no` plus a full attribute/combination payload, so instead of
 * half-driving it there is a STATIC RATCHET asserting every `new Tag()` in the controller sets
 * a slug before saving. That holds for all four sites and for any fifth one somebody adds.
 */
class ProductUpdateNullGuardsTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function makeCategory(int $storeId): int
    {
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

    private function makeProduct(int $storeId, int $createdBy, array $overrides = []): Product
    {
        // No `cost_price`: GATE F, no migration, so no column on this schema. It stays in the
        // PAYLOAD because the validator requires it as a request field.
        return Product::forceCreate(array_merge([
            'name' => 'Scarf',
            'slug' => 'scarf-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'track_stock' => 1,
            'trending' => 1,
            'stock_status' => 'on_backorder',
            'product_stock' => 7,
            'product_type' => null,
            'status' => 1,
            'variant_product' => 0,
            'store_id' => $storeId,
            'created_by' => $createdBy,
        ], $overrides));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Renamed',
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

    /** @return array{0:\App\Models\User,1:\App\Models\Product,2:int} */
    private function arrange(array $productOverrides = []): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Products');
        $categoryId = $this->makeCategory($store->id);
        $product = $this->makeProduct($store->id, $merchant->id, $productOverrides);

        return [$merchant, $product, $categoryId];
    }

    /**
     * THE GUARDS ARE NOT ALL THE SAME, AND THE ASYMMETRY IS DELIBERATE. Two coalesce to the
     * stored value and two to 0, so this table is also the record of which is which and why --
     * an undocumented asymmetry here would read as a bug to the next person.
     *
     *   trending, stock_status  ->  the STORED value.
     *       Both are rendered by a control with a hidden `0` partner (trending) or by radios
     *       with no empty option (stock_status), so a browser can never omit them. An absent
     *       key therefore means "this is not the form", and the least destructive answer is to
     *       leave the merchant's value alone. For `stock_status` this is also FAIL-CLOSED:
     *       production's non-strict UPDATE stored `''`, and `'' == 'out_of_stock'` is false,
     *       so the old behaviour silently made the product PURCHASABLE.
     *
     *   track_stock, product_stock  ->  0.
     *       Here 0 is what production already stores (a non-strict MySQL UPDATE writes the
     *       column's implicit default for a NULL), and it is what `store()` and the sibling
     *       `track_stock == 0` branch write for the same input. Preserving the old quantity
     *       instead would be a change to what a save does to STOCK, which is a commercial
     *       behaviour change and not this slice's to make. So these two keep production's
     *       value exactly and only stop the crash.
     */
    public static function omittedKeys(): array
    {
        return [
            // key omitted      column            value before    what must be stored after
            'trending'      => ['trending',       'trending',      1,              1],
            'stock_status'  => ['stock_status',   'stock_status', 'on_backorder', 'on_backorder'],
            'track_stock'   => ['track_stock',    'track_stock',   1,              0],
            'product_stock' => ['product_stock',  'product_stock', 7,              0],
        ];
    }

    /**
     * @dataProvider omittedKeys
     */
    public function test_a_save_that_omits_a_not_null_field_succeeds_and_writes_no_null(
        string $omit,
        string $column,
        $before,
        $expected
    ): void {
        [$merchant, $product, $categoryId] = $this->arrange();

        $this->assertSame($before, $this->readColumn($product->id, $column),
            'premise: the fixture must hold a value that a NULL write would destroy');

        $payload = $this->payload(['category_id' => $categoryId]);

        // `product_stock` is only read when track_stock is truthy, so that case needs the
        // branch kept open rather than the default 0 the payload carries.
        if ($omit === 'product_stock') {
            $payload['track_stock'] = 1;
        }
        unset($payload[$omit]);
        $this->assertArrayNotHasKey($omit, $payload, 'premise: the key really is absent');

        $response = $this->actingAs($merchant)->put(route('product.update', $product->id), $payload);

        // Without this the "value survived" assertion below passes on a 500 that wrote nothing.
        $response->assertOk();
        $this->assertSame('success', $response->json('flag'),
            'the save must succeed, not be refused: ' . json_encode($response->json()));

        $after = $this->readColumn($product->id, $column);
        // The point of the whole file: never NULL. What the value SHOULD be is the provider's
        // fourth column, and the docblock above says why it differs between these four.
        $this->assertNotNull($after, "{$column} was written as NULL");
        $this->assertSame($expected, $after,
            "{$column} is not what the guard should have stored for an omitted key");
    }

    /** Read through the query builder: `Product` is Cachable, so an Eloquent read can serve
     *  the pre-write row and report a fixture as applied when it is not. */
    private function readColumn(int $productId, string $column)
    {
        $value = \DB::table('products')->where('id', $productId)->value($column);

        return is_numeric($value) && $column !== 'stock_status' ? (int) $value : $value;
    }

    public function test_a_free_text_tag_is_created_with_a_slug(): void
    {
        [$merchant, $product, $categoryId] = $this->arrange();

        // What select2's `tags: true` posts: a NAME where the controller looks for an id.
        $typed = 'brand new tag ' . uniqid();

        $response = $this->actingAs($merchant)->put(
            route('product.update', $product->id),
            $this->payload(['category_id' => $categoryId, 'tag_id' => [$typed]])
        );

        $response->assertOk();
        $this->assertSame('success', $response->json('flag'), json_encode($response->json()));

        // Query builder, not Tag::find: `Tag` is Cachable too.
        $row = \DB::table('tags')->where('name', $typed)->first();
        $this->assertNotNull($row, 'the typed tag was not created at all');
        $this->assertNotNull($row->slug, 'tags.slug is NOT NULL and was written as NULL');
        $this->assertNotSame('', $row->slug, 'tags.slug was written empty');
    }

    public function test_every_tag_the_controller_creates_is_given_a_slug(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/ProductController.php'));
        // Comments out first, so this cannot be satisfied by the prose explaining it.
        $src = preg_replace('#//[^\n]*#', '', $src);
        $src = preg_replace('#/\*.*?\*/#s', '', $src);

        $sites = preg_split('/\$tag_data\s*=\s*new\s+Tag\(\)\s*;/', $src);
        $count = count($sites) - 1;
        $this->assertSame(4, $count,
            'the number of `new Tag()` sites changed; re-read them before adjusting this number');

        // For each site, the text up to its `->save()` must assign a slug.
        for ($i = 1; $i <= $count; $i++) {
            $tail = $sites[$i];
            $save = strpos($tail, '$tag_data->save()');
            $this->assertNotFalse($save, "tag site {$i}: no save() found after it");
            $block = substr($tail, 0, $save);
            $this->assertMatchesRegularExpression('/\$tag_data->slug\s*=/', $block,
                "tag site {$i} creates a Tag and saves it without setting the NOT NULL tags.slug");
        }
    }

    public static function slugInputs(): array
    {
        return [
            'ordinary'            => ['Handmade Scarf'],
            'spaces and case'     => ['  Damascus  COTTON '],
            'punctuation only'    => ['!!!'],
            'symbols only'        => ['+++ ---'],
            'arabic'              => ['مصنوع يدوياً'],
            'already hyphenated'  => ['a--b'],
        ];
    }

    /**
     * @dataProvider slugInputs
     */
    public function test_slugs_never_returns_null_or_empty(string $input): void
    {
        $slug = Tag::slugs($input);

        $this->assertNotNull($slug, 'Tag::slugs() returned NULL; callers write it into a NOT NULL column');
        $this->assertNotSame('', $slug, 'Tag::slugs() returned an empty string, which is not a slug');
    }

    public function test_slugs_survives_an_exhausted_numeric_suffix_pool(): void
    {
        // `slug`, `slug-1` ... `slug-100` all taken. The method used to fall off the end and
        // return NULL here, which is the same NOT NULL insert failure F62 closed, a hundred
        // rows further on -- and because the pool is built with the query builder it is
        // cross-tenant, so one store exhausting it broke every other store's first such tag.
        $rows = [['name' => 'sale', 'slug' => 'sale', 'store_id' => 1, 'created_by' => 1,
                  'created_at' => now(), 'updated_at' => now()]];
        for ($i = 1; $i <= 100; $i++) {
            $rows[] = ['name' => 'sale', 'slug' => 'sale-' . $i, 'store_id' => 1, 'created_by' => 1,
                       'created_at' => now(), 'updated_at' => now()];
        }
        \DB::table('tags')->insert($rows);
        $this->assertSame(101, \DB::table('tags')->where('slug', 'like', 'sale%')->count(),
            'premise: the whole numeric pool must really be taken');

        $slug = Tag::slugs('sale');

        $this->assertNotNull($slug, 'Tag::slugs() returned NULL once sale-1..100 were taken');
        $this->assertNotSame('', $slug);
        $this->assertSame(0, \DB::table('tags')->where('slug', $slug)->count(),
            'Tag::slugs() returned a slug that already exists');
    }
}
