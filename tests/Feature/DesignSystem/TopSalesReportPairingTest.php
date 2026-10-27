<?php

namespace Tests\Feature\DesignSystem;

use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P2-05-6 — the Top Selling Products chart labelled the wrong slices.
 * =============================================================================================
 *
 * `top_product()` built two parallel arrays and they were ordered differently:
 *
 *   $topSellingProductIds   — the top five ids, sorted by COUNT DESCENDING
 *   $productNames           — Product::whereIn('id', $ids)->get()->pluck('name'), which comes
 *                             back in whatever order the database gives (id order, in practice)
 *   $productCounts          — mapped over $topSellingProductIds, so still in COUNT order
 *
 * The chart then paired name[i] with count[j] for two different products. Nothing on the screen
 * could reveal it, because the chart was a pie in an empty div with no table to check against —
 * which is why this test asserts the PAIRING rather than the set.
 *
 * AND THE ARRAYS COULD BE DIFFERENT LENGTHS. `whereIn` silently drops an id whose product was
 * deleted or belongs to another store, while the counts array always had five, so every slice
 * after the gap shifted by one.
 *
 * THE FIXTURE IS BUILT SO THE TWO ORDERS DISAGREE. Products are created in one order and made
 * popular in the reverse order, so an implementation that trusts row order pairs every single
 * label with the wrong number. A fixture where id order happens to match popularity would pass
 * against the broken code.
 *
 * WHY THIS CALLS THE METHOD INSTEAD OF THE ROUTE. The rest of `top_product()` joins with
 * `SUBSTRING_INDEX`, which SQLite does not have, so `/top-all-reports` returns 500 in the suite
 * and the screen cannot be rendered here at all. The pairing is plain Eloquent and plain PHP, so
 * it was extracted into `topSellingProducts()` and is driven directly, against real rows in a
 * real database. What that cannot see is the rendering, which is stated rather than implied: no
 * test in this repository renders this screen.
 */
class TopSalesReportPairingTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::factory()->create([
            'type' => 'admin', 'created_by' => 1, 'is_active' => 1, 'email_verified_at' => now(),
        ]);
        $this->store = Store::create([
            'name' => 'Top Store', 'email' => 'top@example.test',
            'slug' => 'top-store-' . uniqid(), 'theme_id' => 'stylique',
            'created_by' => $this->merchant->id, 'is_active' => 1, 'default_language' => 'en',
        ]);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->merchant = $this->merchant->fresh();
    }

    /** The shape the rest of the suite uses: `products` has NOT NULL columns with no defaults. */
    private function product(string $name, ?int $storeId = null, ?int $by = null): int
    {
        return Product::forceCreate([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'product_stock' => 50, 'track_stock' => 0, 'variant_product' => 0,
            'price' => 10.00, 'sale_price' => 10.00,
            'store_id' => $storeId ?? $this->store->id,
            'created_by' => $by ?? $this->merchant->id,
        ])->id;
    }

    private function orderFor(int $productId): void
    {
        Order::create([
            'order_id' => 'T' . uniqid(),
            'store_id' => $this->store->id,
            'product_id' => (string) $productId,
            'order_date' => now(), 'price' => 10, 'customer_id' => 0, 'is_guest' => 0,
            'delivered_status' => 1,
        ]);
    }

    /** name => count, exactly as the chart receives the two arrays, paired by index. */
    private function rows(): array
    {
        $method = new \ReflectionMethod(\App\Http\Controllers\ReportController::class, 'topSellingProducts');
        $method->setAccessible(true);

        [$names, $counts] = $method->invoke(
            (new \ReflectionClass(\App\Http\Controllers\ReportController::class))->newInstanceWithoutConstructor(),
            \App\Models\Order::where('store_id', $this->store->id),
            \App\Models\Product::where('store_id', $this->store->id)
        );

        /* THE PAIRING IS THE PROPERTY, so the arrays are zipped rather than compared as sets.
           Equal lengths is part of it: the defect made them differ. */
        $this->assertSame(count($names), count($counts),
            'the chart receives ' . count($names) . ' labels for ' . count($counts) . ' values, '
            . 'so every slice after the first mismatch is labelled with another product\'s number');

        return array_combine($names, $counts);
    }

    /**
     * THE CENTRAL CASE. Created in ascending id order, made popular in descending order, so id
     * order and count order are exact opposites.
     */
    public function test_each_product_is_labelled_with_its_own_count(): void
    {
        /* THE FIRST VERSION OF THIS FIXTURE MADE THE LOWEST ID THE MOST POPULAR, so id order and
           count order AGREED and the case passed against the broken pairing — the exact mistake
           this file's docblock warns about, made in the file that warns about it. The two
           orders are now exact opposites: ascending id, ascending popularity. */
        $alpha = $this->product('Alpha');    // lowest id, LEAST popular
        $bravo = $this->product('Bravo');
        $delta = $this->product('Delta');    // highest id, MOST popular

        foreach ([[$alpha, 1], [$bravo, 3], [$delta, 5]] as [$id, $times]) {
            for ($i = 0; $i < $times; $i++) {
                $this->orderFor($id);
            }
        }

        $rows = $this->rows();

        $this->assertNotSame([], $rows, 'Premise: the products chart rendered no rows.');
        $this->assertSame(1, $rows['Alpha'] ?? null, 'Alpha was labelled with another product\'s count. Rows: ' . json_encode($rows));
        $this->assertSame(3, $rows['Bravo'] ?? null, 'Rows: ' . json_encode($rows));
        $this->assertSame(5, $rows['Delta'] ?? null, 'Rows: ' . json_encode($rows));
    }

    /**
     * A COUNTED ID THAT RESOLVES TO NO PRODUCT MUST NOT SHIFT THE REST. Under the old code the
     * counts array kept its entry while the names array lost one, so every later pair slid by
     * one position.
     */
    public function test_a_deleted_product_does_not_shift_the_other_labels(): void
    {
        $alpha = $this->product('Alpha');
        $ghost = $this->product('Ghost');
        $bravo = $this->product('Bravo');

        foreach ([[$alpha, 2], [$ghost, 3], [$bravo, 4]] as [$id, $times]) {
            for ($i = 0; $i < $times; $i++) {
                $this->orderFor($id);
            }
        }

        Product::where('id', $ghost)->forceDelete();

        $rows = $this->rows();

        $this->assertArrayNotHasKey('Ghost', $rows);
        $this->assertSame(2, $rows['Alpha'] ?? null, 'Rows: ' . json_encode($rows));
        $this->assertSame(4, $rows['Bravo'] ?? null,
            'the deleted product\'s count was handed to the next label. Rows: ' . json_encode($rows));
    }

    /**
     * `Product` is NOT in `config/tenancy.php`'s `scoped_models`, so it adds no predicate for
     * anyone — a plain `Product::` query reaches every tenant's catalogue. The handler must
     * scope it itself.
     */
    public function test_another_stores_product_is_never_named(): void
    {
        $other = User::factory()->create(['type' => 'admin', 'created_by' => 1, 'is_active' => 1, 'email_verified_at' => now()]);
        $otherStore = Store::create([
            'name' => 'Other', 'email' => 'other-t@example.test', 'slug' => 'other-t-' . uniqid(),
            'theme_id' => 'stylique', 'created_by' => $other->id, 'is_active' => 1, 'default_language' => 'en',
        ]);

        $mine = $this->product('Mine');
        $theirs = $this->product('TheirSecretProduct', $otherStore->id, $other->id);

        $this->orderFor($mine);
        /* An order of OUR store that names THEIR product id — the shape a stale or forged
           `orders.product_id` takes. The name must still not leak. */
        $this->orderFor($theirs);

        $rows = $this->rows();

        $this->assertArrayHasKey('Mine', $rows, 'Premise: our own product is missing.');
        $this->assertArrayNotHasKey('TheirSecretProduct', $rows,
            "another tenant's product name reached this store's report — Product is not in "
            . 'scoped_models, so the handler must scope it');
    }

    /**
     * PRD-001 and accessibility, asserted against the VIEW rather than the route, because the
     * route cannot run here (see the class docblock). This renders the Blade file with the data
     * the handler would pass, which catches a markup regression but not a controller one.
     */
    public function test_the_view_states_its_period_and_ships_its_numbers(): void
    {
        /* The layout reads the signed-in user's store, so the guard has to be set before the
           view renders —  on its own has no request behind it. */
        $this->be($this->merchant);

        /* Rendered through a ROUTE, not `view()->render()`. The admin layout reads the
           signed-in user's store and calls `Request::route()->getName()`, so a bare render has
           no request behind it and dies in the header. A throwaway route gives it both, without
           needing the MySQL-only joins the real handler does. */
        \Illuminate\Support\Facades\Route::get('/__top5_render', fn () => view('reports.top_5_reports', [
            'productNames' => ['Alpha'],
            'productCounts' => [3],
            'top_sales' => collect(),
            'top_brand_sales' => collect(),
            'paymentMethods' => collect(['COD' => 2]),
            'periodLabel' => __('All time'),
        ]))->middleware(['web', 'auth']);

        $html = $this->actingAs($this->merchant)->get('/__top5_render')->assertOk()->getContent();

        $this->assertStringContainsString('<div class="ss-chart" aria-hidden="true">', $html);
        $this->assertStringContainsString('<th scope="col">Product</th>', $html);
        $this->assertStringContainsString('All time', $html);
        $this->assertStringNotContainsString("type: 'pie'", $html,
            'a pie claims the five slices are the whole; these are the top of a longer list');

        /* An empty series is an empty state, not a chart of nothing — the category and brand
           collections above are empty on purpose. */
        $this->assertStringContainsString('No data for this period yet', $html);
    }

    /** Only the top FIVE, however many products have sold. */
    public function test_only_five_products_are_returned(): void
    {
        foreach (range(1, 7) as $n) {
            $id = $this->product('P' . $n);
            for ($i = 0; $i < $n; $i++) {
                $this->orderFor($id);
            }
        }

        $rows = $this->rows();

        $this->assertCount(5, $rows);
        $this->assertArrayHasKey('P7', $rows);
        $this->assertSame(7, $rows['P7']);
        $this->assertArrayNotHasKey('P1', $rows, 'the least popular product is not in a top five');
        $this->assertArrayNotHasKey('P2', $rows);
    }
}
