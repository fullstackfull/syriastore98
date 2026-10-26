<?php

namespace Tests\Feature\Merchant;

use App\DataTables\ProductDataTable;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-03-9 — the stock indicator must agree with the list's own filter, and carry its rule.
 * =================================================================================================
 *
 * THE ACCEPTANCE, verbatim from the ledger: "status and stock are distinguishable without colour,
 * carry their rule, and agree with the product list's own predicate". It did not agree, in two
 * ways, both reachable from the UI:
 *
 *   1. THE BOUNDARY. The badge said out-of-stock at `product_stock <= out_of_stock_threshold` --
 *      the global setting the merchant edits at `setting/stock_setting.blade.php:19` -- while the
 *      filter says out at `product_stock <= 0`. Set the threshold to 5 and a product at stock 3
 *      showed "Out of stock" while `?ss_stock=out` did not return it and `?ss_stock=low` did. The
 *      badge and the filter contradicted each other on the same screen.
 *
 *   2. NO "LOW STOCK" BADGE EXISTED. The filter has offered that option since P2-03-2
 *      (`product/partials/filters.blade.php:41`), so a merchant could filter to Low stock and read
 *      "In stock" on every row.
 *
 * WHY THE FILTER'S SEMANTICS WON. Not only because the acceptance names it as the reference:
 * `ProductListFilterTest` proves the three states PARTITION the tracked products and that an
 * untracked product is in none of them, because `track_stock = 0` makes its zero "not counted"
 * rather than "none left". The badge had no such property and no third state.
 *
 * THIS FILE DRIVES BOTH AND COMPARES THEM, rather than asserting that two expressions written to
 * match still match. That distinction is the whole point: they were written to match once.
 */
class ProductStockIndicatorTest extends SecurityTestCase
{
    use RefreshDatabase;

    /* The same headers `ProductListFilterTest` uses, copied verbatim rather than shared, because
       the two files must drive the list IDENTICALLY for their comparison to mean anything -- and a
       shared helper in the base class would let one of them be changed without the other. */
    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    private function makeProduct(int $storeId, int $createdBy, string $name, array $overrides = []): Product
    {
        return Product::forceCreate(array_merge([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'track_stock' => 1,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 50,
            'low_stock_threshold' => 5,
            'product_type' => null,
            'status' => 1,
            'variant_product' => 0,
            'store_id' => $storeId,
            'created_by' => $createdBy,
        ], $overrides));
    }

    /** The rows the list served for a filter, as `name => stock_status cell`. */
    private function rows($user, array $filters = []): array
    {
        $query = array_merge($filters, ['draw' => 1, 'start' => 0, 'length' => 100]);

        foreach (['name', 'stock_status'] as $i => $column) {
            $query["columns[{$i}][data]"] = $column;
            $query["columns[{$i}][name]"] = $column;
            $query["columns[{$i}][searchable]"] = 'true';
            $query["columns[{$i}][orderable]"] = 'true';
            $query["columns[{$i}][search][value]"] = '';
            $query["columns[{$i}][search][regex]"] = 'false';
        }

        $query['order[0][column]'] = 0;
        $query['order[0][dir]'] = 'asc';
        $query['search[value]'] = '';
        $query['search[regex]'] = 'false';

        $response = $this->actingAs($user)
            ->withHeaders(self::AJAX)
            ->getJson('/product?' . http_build_query($query));

        $this->assertNotSame(404, $response->getStatusCode(), 'the product route must exist');
        $this->assertNotSame(500, $response->getStatusCode(), 'the list must not 500');

        $payload = $response->json();
        $this->assertArrayHasKey('draw', $payload, 'premise: yajra served the AJAX leg');

        $out = [];

        foreach ($payload['data'] ?? [] as $row) {
            $out[strip_tags((string) ($row['name'] ?? ''))] = (string) ($row['stock_status'] ?? '');
        }

        return $out;
    }

    /** The badge's own word, read out of the rendered cell. */
    private function badgeWord(string $cell): string
    {
        $text = trim(strip_tags($cell));

        return $text === '' ? '(empty)' : $text;
    }

    public function test_the_badge_and_the_filter_agree_on_every_boundary(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');

        /* A GLOBAL THRESHOLD OF 5, which is what made the two disagree. With the setting at its
         * default 0 the old boundary and the new one coincide, so a test that left it unset could
         * not see this defect at all -- it is latent on the default and live the moment a merchant
         * uses the setting the product offers them. */
        \App\Models\Setting::updateOrCreate(
            ['name' => 'out_of_stock_threshold', 'created_by' => 1, 'store_id' => $store->id],
            ['value' => '5']
        );
        \Illuminate\Support\Facades\Cache::flush();

        // Values ON the boundaries, where an off-by-one lives, with no per-product override so
        // the global fallback is the one in play.
        $stocks = [0, 1, 3, 5, 6, 50];
        foreach ($stocks as $stock) {
            $this->makeProduct($store->id, $merchant->id, "Stock {$stock} Scarf", [
                'product_stock' => $stock,
                'low_stock_threshold' => 0,          // 0 means "unset", so the fallback applies
            ]);
        }

        $unfiltered = $this->rows($merchant);
        $this->assertCount(6, $unfiltered, 'premise: the unfiltered list serves all six rows');

        $buckets = [
            'out' => array_keys($this->rows($merchant, ['ss_stock' => 'out'])),
            'low' => array_keys($this->rows($merchant, ['ss_stock' => 'low'])),
            'in' => array_keys($this->rows($merchant, ['ss_stock' => 'in'])),
        ];

        // The filter's own property, restated here so a change to it fails HERE too rather than
        // only in ProductListFilterTest.
        $this->assertCount(6, array_merge(...array_values($buckets)),
            'the three filters must still partition the tracked products');

        $expectedWord = ['out' => 'Out of stock', 'low' => 'Low stock', 'in' => 'In stock'];
        $disagreements = [];

        foreach ($buckets as $bucket => $names) {
            foreach ($names as $name) {
                $word = $this->badgeWord($unfiltered[$name] ?? '');
                if ($word !== $expectedWord[$bucket]) {
                    $disagreements[] = $name . ': the filter puts it in "' . $bucket
                        . '" but its badge says "' . $word . '"';
                }
            }
        }

        $this->assertSame([], $disagreements,
            "The stock badge and the stock filter disagree about the same rows:\n  "
            . implode("\n  ", $disagreements)
            . "\n\nBoth must come from ProductDataTable::trackedStockState(). See P2-03-9/F79.");
    }

    public function test_the_low_stock_state_is_rendered_at_all(): void
    {
        /* Its own case, because the agreement test above would also pass if the filter's `low`
         * bucket happened to be empty -- and before this change there was no Low stock badge in
         * the codebase at all. */
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');

        $this->makeProduct($store->id, $merchant->id, 'Low Scarf', [
            'product_stock' => 3,
            'low_stock_threshold' => 5,
        ]);

        $rows = $this->rows($merchant);

        $this->assertSame('Low stock', $this->badgeWord($rows['Low Scarf'] ?? ''),
            'a product at 3 against a low-stock level of 5 must read "Low stock"; before P2-03-9 '
            . 'the badge had only two states and said "In stock" here');
        $this->assertStringContainsString('ss-badge--warning', $rows['Low Scarf'] ?? '',
            'and it is the warning tone, not danger: it is still sellable');
    }

    public function test_the_badge_carries_its_rule(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');

        $this->makeProduct($store->id, $merchant->id, 'Low Scarf', [
            'product_stock' => 3,
            'low_stock_threshold' => 5,
        ]);
        $this->makeProduct($store->id, $merchant->id, 'Plenty Scarf', [
            'product_stock' => 40,
            'low_stock_threshold' => 5,
        ]);
        $this->makeProduct($store->id, $merchant->id, 'Untracked Scarf', [
            'track_stock' => 0,
            'stock_status' => 'out_of_stock',
            'product_stock' => 0,
        ]);

        $rows = $this->rows($merchant);

        /* "CARRY THEIR RULE" is the half of the acceptance that a colour and a word cannot
         * satisfy: a merchant seeing "Low stock" cannot act on it, or tell whether it agrees with
         * the filter they just used, unless it says what counts as low. */
        $this->assertStringContainsString('title=', $rows['Low Scarf'] ?? '',
            'the Low stock badge must state the rule behind it');
        $this->assertStringContainsString('5', $rows['Low Scarf'] ?? '',
            'and name the threshold it is measured against');
        $this->assertStringContainsString('3', $rows['Low Scarf'] ?? '',
            'and the count it has');
        $this->assertStringContainsString('title=', $rows['Plenty Scarf'] ?? '',
            'the In stock badge must state its rule too, or the quiet state is the unexplained one');

        /* The untracked row says why the question does not apply, rather than reporting a level
         * it does not have. Same honesty as the filter, which excludes it from all three states. */
        $untracked = $rows['Untracked Scarf'] ?? '';
        $this->assertStringContainsString('title=', $untracked);
        $this->assertStringContainsString('not tracked', $untracked,
            'an untracked product must say its stock is not tracked, not invent a level');
    }

    /** @return list<array{0:int,1:int,2:int,3:string}> stock, per-product low, fallback, expected */
    public static function stockStates(): array
    {
        return [
            'zero is out whatever the thresholds' => [0, 5, 5, 'out'],
            'negative is out'                     => [-3, 5, 5, 'out'],
            'one against a low of 5 is low'       => [1, 5, 5, 'low'],
            'exactly the low level is low'        => [5, 5, 5, 'low'],
            'one above it is in'                  => [6, 5, 5, 'in'],
            'a per-product 0 falls back'          => [4, 0, 5, 'low'],
            'a per-product override wins'         => [4, 2, 99, 'in'],
            'both zero: only 0 itself is out'     => [1, 0, 0, 'in'],
        ];
    }

    /**
     * @dataProvider stockStates
     */
    public function test_the_shared_rule_itself(int $stock, int $low, int $fallback, string $expected): void
    {
        /* The rule in isolation, so a boundary mistake is named precisely rather than surfacing as
         * one odd row in the agreement test. `a per-product 0 falls back` is the NULLIF(…, 0)
         * semantics the filter's SQL uses: a stored 0 means "unset", not "zero". */
        $this->assertSame($expected,
            ProductDataTable::trackedStockState($stock, $low, $fallback),
            "stock={$stock}, low={$low}, fallback={$fallback}");
    }
}
