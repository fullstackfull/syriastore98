<?php

namespace Tests\Feature\Platform;

use App\Models\Store;
use App\Support\Metrics\GrossMerchandiseValue;
use App\Support\Metrics\MetricScope;
use App\Support\Metrics\MetricWindow;
use App\Support\Metrics\PlatformAnalyticsFigures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * PERF-OPS — THE OPERATOR'S TOP-STORES PANEL READS A BOUNDED NUMBER OF ROWS AGAIN.
 * =============================================================================================
 *
 * `OperatorDashboardTopStoresTest` pins WHAT the panel says. This pins HOW MUCH it reads to say
 * it, which is a separate property and the one that regressed.
 *
 * THE PANEL THIS REPLACED RANKED AND LIMITED IN SQL: `SUM(final_price) … ORDER BY total_amount
 * DESC LIMIT 5`, five rows. P5-R3 moved it onto the metric layer to stop it ranking currencies
 * against each other, and in the move the ranking went into PHP — necessarily, because the bucket
 * a row belongs to is not the column it is grouped by: an order carrying no currency, or a symbol
 * where a code belongs, takes its STORE's setting, which lives in another table. So
 * `perStore()` returned one row per (store, currency) FOR EVERY STORE IN THE SCOPE, and the
 * operator's scope is every store on the platform. Three reads then grew with the number of
 * tenants — the aggregate, the `stores` name lookup and the `settings` lookup, the last two as IN
 * lists of every store id — on the first page an operator sees.
 *
 * NONE OF THESE CASES ASSERTS A FIGURE THAT THE OTHER FILE DOES NOT ALREADY ASSERT. They assert
 * the reads: that the aggregate carries a LIMIT, that the name lookup names candidates rather than
 * the platform, that a store's own setting is read only when some row actually needs it, and —
 * the case that makes the bound safe rather than merely small — that limiting per recorded
 * SPELLING instead of per CURRENCY would give the wrong answer, so the limit has to be where it is.
 */
class OperatorTopStoresAreRankedByTheDatabaseTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function order(Store $store, float $amount, ?string $currency): void
    {
        DB::table('orders')->insert([
            'product_order_id' => 'RBD-' . $this->uniqueFixtureRef(),
            'store_id' => $store->id,
            'customer_id' => 0,
            'is_guest' => 1,
            'delivered_status' => 0,
            'final_price' => $amount,
            'currency_code' => $currency,
            'order_date' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<Store> named so a failure names the store, in creation order */
    private function stores(int $count): array
    {
        $made = [];

        for ($i = 0; $i < $count; $i++) {
            [, $store] = $this->makeTenant();
            $store->name = 'Shop ' . ($i + 1);
            $store->save();
            $made[] = $store;
        }

        return $made;
    }

    private function setting(Store $store, string $value): void
    {
        DB::table('settings')->insert([
            'name' => 'defult_currancy', 'value' => $value, 'created_by' => $store->created_by,
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        (new \App\Models\Setting)->flushCache();
    }

    /**
     * @return array{0: mixed, 1: list<array{sql: string, bindings: array}>}
     */
    private function watching(callable $work): array
    {
        $log = [];

        DB::listen(function ($query) use (&$log) {
            $log[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });

        $answer = $work();

        // Laravel keeps no public way to detach one listener; the suite's connection is torn down
        // between tests, so the closure dies with it.
        return [$answer, $log];
    }

    /**
     * THE NEEDLE IS SEARCHED IN THE BINDINGS TOO, and that is not a convenience.
     *
     * `defult_currancy` is a BOUND VALUE -- the SQL reads `where "name" = ?` -- so a filter over
     * the SQL text alone finds no settings query ever, and
     * `test_no_setting_is_read_when_every_recorded_currency_is_a_code` passed against a version
     * that read the table on every request. Its sibling's premise assertion is what caught that,
     * which is the whole reason each of these cases carries one.
     *
     * @param  list<array{sql: string, bindings: array}>  $log
     * @return list<array{sql: string, bindings: array}>
     */
    private function queriesAgainst(array $log, string $table, string $mustContain = ''): array
    {
        return array_values(array_filter($log, static function (array $q) use ($table, $mustContain): bool {
            if (! str_contains($q['sql'], '"' . $table . '"')) {
                return false;
            }

            if ($mustContain === '') {
                return true;
            }

            if (str_contains($q['sql'], $mustContain)) {
                return true;
            }

            foreach ($q['bindings'] as $binding) {
                if (is_scalar($binding) && (string) $binding === $mustContain) {
                    return true;
                }
            }

            return false;
        }));
    }

    private function scope(): MetricScope
    {
        return MetricScope::stores(
            Store::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all()
        );
    }

    public function test_the_aggregate_that_ranks_one_currencys_stores_carries_a_limit(): void
    {
        $stores = $this->stores(9);

        foreach ($stores as $i => $store) {
            $this->order($store, 100.0 + $i, 'USD');
        }

        [$ranked, $log] = $this->watching(fn () => PlatformAnalyticsFigures::topStoresByOrderValue($this->scope()));

        $this->assertCount(5, $ranked['USD'] ?? [],
            'The panel no longer shows five stores for a currency that has nine.');

        $grouped = $this->queriesAgainst($log, 'orders', 'group by');

        $this->assertNotSame([], $grouped, 'PREMISE: no grouped read of the orders table was made at all.');

        foreach ($grouped as $query) {
            $this->assertStringContainsString('limit', $query['sql'],
                'A grouped read of every order on the platform came back without a LIMIT. That is '
                . 'the bound this panel lost: one row per store, on the operator\'s landing page, '
                . 'growing with the number of tenants. SQL: ' . $query['sql']);
        }
    }

    public function test_the_store_name_lookup_names_the_candidates_and_not_the_platform(): void
    {
        $stores = $this->stores(9);

        foreach ($stores as $i => $store) {
            $this->order($store, 100.0 + $i, 'USD');
        }

        [, $log] = $this->watching(fn () => PlatformAnalyticsFigures::topStoresByOrderValue($this->scope()));

        $lookups = $this->queriesAgainst($log, 'stores', '"name"');

        $this->assertNotSame([], $lookups, 'PREMISE: the figures never looked a store name up.');

        foreach ($lookups as $query) {
            $this->assertLessThanOrEqual(5, count($query['bindings']),
                'The name lookup still binds one id per store with an order — nine of them here, '
                . 'and one per tenant in production. It should name only the stores that survived '
                . 'the ranking. SQL: ' . $query['sql']);
        }
    }

    /**
     * THE READ THAT COSTS ONE ROW PER STORE IS THE LEGACY ONE, AND IT IS ONLY FOR LEGACY STORES.
     *
     * A store's own currency setting is consulted for an order that recorded no currency, or
     * recorded a symbol — the rows owner decision D-N34 left in place. Eight of these nine stores
     * recorded a proper code, so their settings are nobody's business here.
     */
    public function test_a_stores_own_setting_is_read_only_for_the_stores_that_need_it(): void
    {
        $stores = $this->stores(9);

        foreach ($stores as $i => $store) {
            $this->order($store, 100.0 + $i, 'USD');
        }

        // The ninth also took an order through the storefront API, which stamped the symbol.
        $legacy = $stores[8];
        $this->setting($legacy, 'USD');
        $this->order($legacy, 1000.00, 'ل.س');

        [$ranked, $log] = $this->watching(fn () => PlatformAnalyticsFigures::topStoresByOrderValue($this->scope()));

        $settingReads = $this->queriesAgainst($log, 'settings', 'defult_currancy');

        $this->assertNotSame([], $settingReads,
            'PREMISE: the symbol row did not send the figures to the store\'s own setting at all.');

        foreach ($settingReads as $query) {
            $this->assertLessThanOrEqual(2, count($query['bindings']),
                'The currency-setting lookup still names every store with an order. Only the '
                . 'stores carrying a row the database cannot bucket need it. SQL: ' . $query['sql']);
        }

        $first = ($ranked['USD'] ?? [])[0] ?? null;

        $this->assertNotNull($first, 'The USD list is empty.');
        $this->assertSame((int) $legacy->id, $first['store_id'],
            'The store whose symbol row was folded into USD should lead the list on 1,108.00; '
            . 'narrowing the setting read must not have narrowed the figure.');
        $this->assertSame(1108.00, $first['amount']);
    }

    public function test_no_setting_is_read_when_every_recorded_currency_is_a_code(): void
    {
        foreach ($this->stores(3) as $i => $store) {
            $this->order($store, 100.0 + $i, $i === 1 ? 'usd' : 'USD');
        }

        [, $log] = $this->watching(fn () => PlatformAnalyticsFigures::topStoresByOrderValue($this->scope()));

        $this->assertSame([], $this->queriesAgainst($log, 'settings', 'defult_currancy'),
            'Every order named a currency, so no store\'s own setting decides anything — yet the '
            . 'figures still read the settings table once per store. On a platform with no legacy '
            . 'rows this read should not happen at all.');
    }

    /**
     * THE LIMIT IS PER CURRENCY, AND IT HAS TO BE. This is the case that makes the bound safe.
     *
     * 'USD' and 'usd' are ONE currency; a store sixth in each spelling can be FIRST once they are
     * added. So the ranked query takes every spelling of a currency in one IN list and limits the
     * result — a `LIMIT 5` per spelling would be small, bounded, and wrong.
     */
    public function test_a_store_sixth_in_two_spellings_of_one_currency_still_leads_the_list(): void
    {
        $stores = $this->stores(6);
        $split = $stores[5];

        foreach (array_slice($stores, 0, 5) as $store) {
            $this->order($store, 150.00, 'USD');
        }

        $this->order($split, 100.00, 'USD');
        $this->order($split, 100.00, 'usd');

        $ranked = PlatformAnalyticsFigures::topStoresByOrderValue($this->scope());

        $this->assertArrayHasKey('USD', $ranked);
        $this->assertCount(5, $ranked['USD']);

        $this->assertSame((int) $split->id, $ranked['USD'][0]['store_id'],
            'A store with 100 under "USD" and 100 under "usd" holds 200 in ONE currency and leads '
            . 'five stores on 150. It is sixth in each spelling taken alone, so a per-spelling '
            . 'limit drops it from its own currency\'s list entirely.');
        $this->assertSame(200.00, $ranked['USD'][0]['amount']);
        $this->assertSame(2, $ranked['USD'][0]['orders']);
    }

    /**
     * AN EMPTY CURRENCY SET IS REFUSED, because a closure that adds no predicate leaves the query
     * reading every currency — a cross-currency ranking arriving as the ABSENCE of a clause, which
     * is the defect the per-currency panel exists to prevent.
     */
    public function test_a_currency_restriction_with_no_spellings_is_refused(): void
    {
        $this->stores(1);

        $this->expectException(\InvalidArgumentException::class);

        (new GrossMerchandiseValue())->topStoresForCurrencies(
            $this->scope(), MetricWindow::allTime(), [], 5
        );
    }

    public function test_a_limit_below_one_is_refused_rather_than_read_as_no_limit(): void
    {
        $this->stores(1);

        $this->expectException(\InvalidArgumentException::class);

        (new GrossMerchandiseValue())->topStoresForCurrencies(
            $this->scope(), MetricWindow::allTime(), ['USD'], 0
        );
    }

    public function test_narrowing_per_store_to_no_store_is_refused_rather_than_reporting_zero(): void
    {
        $this->stores(1);

        $this->expectException(\InvalidArgumentException::class);

        (new GrossMerchandiseValue())->perStore($this->scope(), MetricWindow::allTime(), []);
    }

    /**
     * A NULL CURRENCY IS A MEMBER OF THE SET, which `whereIn` can never match: in SQL
     * `NULL IN (…)` is NULL, not true. So the null spelling becomes `OR currency_code IS NULL`,
     * and an order that recorded no currency reaches its store's setting.
     */
    public function test_an_order_that_recorded_no_currency_is_reachable_through_the_spelling_set(): void
    {
        $stores = $this->stores(2);
        $this->setting($stores[0], 'SYP');
        $this->order($stores[0], 75.00, null);

        // A second store with a PROPER code, so a read that lost its predicate altogether -- which
        // is what an empty spelling set would compile to -- answers two rows rather than one.
        $this->order($stores[1], 5000.00, 'EUR');

        $rows = (new GrossMerchandiseValue())->perStore(
            $this->scope(), MetricWindow::allTime(), null, [null]
        );

        $this->assertCount(1, $rows,
            'The null spelling matched nothing, so an order with no recorded currency is invisible '
            . 'to the read that finds the stores needing their own setting.');
        $this->assertNull($rows[0]['currency']);
        $this->assertSame(75.00, $rows[0]['amount']);

        $ranked = PlatformAnalyticsFigures::topStoresByOrderValue($this->scope());

        $this->assertSame(75.00, ($ranked['SYP'][0]['amount'] ?? null),
            'The order took neither its store\'s setting nor any currency at all.');
    }
}
