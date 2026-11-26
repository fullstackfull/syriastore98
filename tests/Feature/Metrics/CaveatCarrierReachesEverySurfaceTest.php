<?php

namespace Tests\Feature\Metrics;

use App\Models\Permission;
use App\Models\Store;
use App\Models\User;
use App\Support\Metrics\CaveatedFigure;
use App\Support\Metrics\FinanceLedgerFigures;
use App\Support\Metrics\MetricCaveat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-152 / P05-04 — THE GATE. EVERY CAVEAT A METRIC ATTACHES IS STATED ON EVERY SURFACE THAT
 * RENDERS THE FIGURE: three screens and the export file.
 * =================================================================================================
 *
 * WHAT THIS TEST IS FOR, and why it is not a list of sentences. The caveats are enumerated FROM THE
 * CARRIER at run time -- `CaveatedFigure::caveats()` -- and each one's sentence is then required on
 * the page and in the file. So a caveat added to `StoreRevenueMargin` or
 * `CollectedCustomerPayments` tomorrow tightens this test by itself, without anybody remembering to
 * come back here. That is the difference GAP-152's next action asks for: "a screen cannot render a
 * figure without it, rather than adding caveats per-view".
 *
 * THE DEFECT IT WAS WRITTEN AGAINST, measured 2026-11-25. `StoreRevenueMargin::for()` attaches
 * `MetricCaveat::EXCLUDES_PAYMENT_FEES` unconditionally -- ANA-006, because nothing in this product
 * records what a gateway charged, so every card-taking store's margin is overstated by the
 * gateway's cut. Its sentence was translated into Arabic and ratcheted by
 * `MetricCaveatMessagesAreTranslatedTest`, and it appeared on NONE of the five surfaces that render
 * that figure: `/finance`, `/analytics`, `reports/finance_report`, the `/analytics` CSV and the
 * scheduled-report mail. A grep of `resources/views/` and `app/` for the sentence returned one hit,
 * a docblock. Each surface composed its hint by hand, in a different file from the metric.
 *
 * WHY THE SCREEN IS READ OFF THE PAGE. `viewData()` would prove the controller handed the view a
 * figure, which is not the thing that failed -- the figure arrived at all five surfaces and its
 * caveat did not. Only the rendered bytes can say whether a merchant reads it.
 */
final class CaveatCarrierReachesEverySurfaceTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const ANALYTICS_EXPORT = '/analytics/export';

    /** A merchant who can see /finance, /analytics and the sales reports, with one paid order. */
    private function merchantWithPaidOrder(array $ledger = []): array
    {
        [$merchant, $store] = $this->makeTenant();

        /*
         * THREE PERMISSIONS, ONE PER SURFACE: `/finance` and `/finance-reports` both route through
         * `View Finance Dashboard` (ReportPermission::FINANCE, ANA-001: the report centre entry
         * takes the finance permission so the audience does not widen), while `/analytics` and its
         * export take `Manage Reports`.
         */
        foreach (['View Finance Dashboard', 'Manage Reports'] as $permission) {
            Permission::firstOrCreate(['name' => $permission], ['display_name' => $permission]);
            $this->grantPermission(User::find($merchant->id), $permission);
        }

        $this->storeCurrency($store, (int) $merchant->id);

        /* Several handlers read the platform admin through Cache::remember('admin_details'). */
        Cache::forget('admin_details');

        $order = $this->makeOrder($store, ['order_date' => '2026-11-05 10:00:00']);

        $updated = DB::table('store_order_financials')
            ->where('order_id', $order->id)
            ->where('store_id', $store->id)
            ->update(array_merge([
                'payment_status' => 'paid',
                'paid_amount' => '100.00',
                'paid_at' => '2026-11-10 12:00:00',
                'currency_code' => 'USD',
                'product_cost' => '40.00',
                'gross_profit' => '60.00',
            ], $ledger) + ['updated_at' => now()]);

        $this->assertSame(1, $updated, 'OrderObserver::created() wrote no ledger row, so this fixture measures nothing');

        return [User::find($merchant->id), $store];
    }

    /** One in-stock product, so the catalogue valuation has something to value. */
    private function stockedProduct(Store $store, array $attributes): void
    {
        $name = 'GAP-152 Product ' . uniqid();
        $catName = 'GAP-152 Cat ' . uniqid();

        $category = \App\Models\Category::forceCreate([
            'name' => $catName,
            'slug' => \Illuminate\Support\Str::slug($catName) . '-' . uniqid(),
            'parent_id' => 0, 'trending' => 0, 'status' => 1, 'store_id' => $store->id,
        ]);

        \App\Models\Product::forceCreate(array_merge([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'description' => 'x', 'track_stock' => 0, 'trending' => 0,
            'stock_status' => 'in_stock', 'product_type' => null,
            'status' => 1, 'variant_product' => 0,
            'category_id' => $category->id,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ], $attributes));
    }

    /** The store-currency setting the figures read, under the product's own misspelled key. */
    private function storeCurrency(Store $store, int $createdBy): void
    {
        DB::table('settings')->insert([
            'name' => 'defult_currancy',
            'value' => 'USD',
            'store_id' => $store->id,
            'created_by' => $createdBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The same figures the controllers build, by tile label.
     *
     * THE PREMISE IS ASSERTED HERE, IN THE HELPER, AND A MUTATION IS WHY. Every surface test below
     * loops over `$figure->caveats()` and requires each sentence on the page or in the file -- so a
     * mutation that builds the tile WITHOUT the result's caveats makes every one of those loops
     * pass on an empty list. Measured: `CaveatedFigure::of(..., [], $notes)` in place of
     * `::from($result, ...)` was reported SURVIVED by the first version of this file, because the
     * gate read its expectations from the same code it was testing.
     *
     * One assertion in the shared helper fixes the whole family at once: `EXCLUDES_PAYMENT_FEES` is
     * attached UNCONDITIONALLY by `StoreRevenueMargin`, so a tile that has lost it has lost the
     * caveats, and every test that asks for figures finds that out before asserting anything else.
     *
     * @return array<string, CaveatedFigure>
     */
    private function figures(Store $store): array
    {
        $figures = FinanceLedgerFigures::forStore($store->id, 'USD');

        $this->assertTrue($figures['profit']->hasCaveat(MetricCaveat::EXCLUDES_PAYMENT_FEES),
            'the margin tile reached this test carrying no fee caveat, so every caveat loop below '
            . 'would pass over an empty list. Either StoreRevenueMargin stopped attaching it or the '
            . 'tile stopped reading the caveats off its MetricResult.');

        return [
            'Payments received' => $figures['payments'],
            'Estimated gross profit' => $figures['profit'],
        ];
    }

    /**
     * Readable text: scripts and styles removed, entities decoded, bidi isolates dropped,
     * whitespace collapsed.
     *
     * THE SCRIPT AND STYLE BLOCKS GO FIRST, and not for tidiness. `strip_tags()` keeps the CONTENTS
     * of both, and the merchant layout carries several thousand lines of inline JavaScript -- so a
     * failure message printed the whole of it, and worse, a sentence appearing only inside a script
     * would read as a sentence a merchant can see.
     */
    private function text(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);

        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/[\x{2066}-\x{2069}]/u', '', $plain);
        $plain = $plain === null ? null : preg_replace('/\s+/u', ' ', $plain);

        /*
         * A NULL FROM `preg_replace` IS NOT AN EMPTY PAGE, and casting it to one is how this helper
         * would turn every `assertStringNotContainsString` below into a pass. It really happened:
         * with the layout's inline scripts still in the text, `/u` returned null -- so the first
         * version of this helper handed back '' and four assertions failed for the wrong reason.
         */
        $this->assertNotNull($plain, 'the page could not be read as UTF-8 text, so no assertion over it means anything');

        return trim($plain);
    }

    private function sentenceOf(CaveatedFigure $figure, string $caveat): string
    {
        $index = array_search($caveat, $figure->caveats(), true);

        $this->assertNotFalse($index, 'the figure does not carry ' . $caveat);

        return (string) preg_replace('/\s+/u', ' ', $figure->caveatSentences()[$index]);
    }

    public function test_the_premise_that_the_profit_figure_carries_a_caveat_at_all(): void
    {
        [, $store] = $this->merchantWithPaidOrder();

        $profit = $this->figures($store)['Estimated gross profit'];

        // Without this the loops below would pass vacuously on a figure carrying nothing, which is
        // how a caveat gate proves nothing at all.
        $this->assertNotSame([], $profit->caveats(), 'the margin figure carries no caveat, so this gate is blind');
        $this->assertTrue($profit->hasCaveat(MetricCaveat::EXCLUDES_PAYMENT_FEES),
            'ANA-006 attaches the fee caveat unconditionally; if that changed, this test must be re-read');
        $this->assertNotSame('', trim((string) $profit->value()), 'premise: the tile shows a figure');
    }

    public function test_every_caveat_is_stated_on_the_finance_screen(): void
    {
        [$merchant, $store] = $this->merchantWithPaidOrder();

        $page = $this->text($this->actingAs($merchant->fresh())->get('/finance')->getContent());

        foreach ($this->figures($store) as $label => $figure) {
            foreach ($figure->caveats() as $caveat) {
                $this->assertStringContainsString($this->sentenceOf($figure, $caveat), $page,
                    "/finance renders '{$label}' and says nothing about {$caveat}");
            }
        }
    }

    public function test_every_caveat_is_stated_on_the_analytics_screen(): void
    {
        [$merchant, $store] = $this->merchantWithPaidOrder();

        $page = $this->text($this->actingAs($merchant->fresh())->get('/analytics')->getContent());

        foreach ($this->figures($store) as $label => $figure) {
            foreach ($figure->caveats() as $caveat) {
                $this->assertStringContainsString($this->sentenceOf($figure, $caveat), $page,
                    "/analytics renders '{$label}' and says nothing about {$caveat}");
            }
        }
    }

    public function test_every_caveat_is_stated_on_the_finance_report_screen(): void
    {
        [$merchant, $store] = $this->merchantWithPaidOrder();

        $response = $this->actingAs($merchant->fresh())->get('/finance-reports');

        $this->assertSame(200, $response->status(), 'the finance report did not render: ' . $response->status());

        $page = $this->text($response->getContent());

        foreach ($this->figures($store) as $label => $figure) {
            foreach ($figure->caveats() as $caveat) {
                $this->assertStringContainsString($this->sentenceOf($figure, $caveat), $page,
                    "the finance report renders '{$label}' and says nothing about {$caveat}");
            }
        }
    }

    /**
     * THE EXPORT HALF. §11 rule 6: "An export carries the same disclosure as the screen, as a
     * COLUMN" -- a caveat in a header preamble survives neither sorting nor copying a range.
     */
    public function test_every_caveat_travels_into_the_analytics_export_on_the_figure_s_own_row(): void
    {
        [$merchant, $store] = $this->merchantWithPaidOrder();

        $body = $this->actingAs($merchant->fresh())->get(self::ANALYTICS_EXPORT)->getContent();

        $rows = array_map(
            fn (array $row): array => array_map(fn ($cell): string => $this->text((string) $cell), $row),
            $this->csv($body)
        );

        foreach ($this->figures($store) as $label => $figure) {
            $row = null;

            foreach ($rows as $candidate) {
                if (($candidate[0] ?? '') === $label) {
                    $row = $candidate;
                }
            }

            $this->assertNotNull($row, "the export has no row for '{$label}'");

            foreach ($figure->caveats() as $caveat) {
                $this->assertStringContainsString($this->sentenceOf($figure, $caveat), $row[3] ?? '',
                    "the export's '{$label}' row drops {$caveat} from its disclosure column");
            }
        }
    }

    /** @return list<list<string>> */
    private function csv(string $body): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, ltrim($body, "\u{FEFF}"));
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = array_map('strval', $row);
        }

        fclose($handle);

        return $rows;
    }

    /**
     * THE SECOND MEASURED OMISSION, AND THE OTHER SHAPE OF THE CARRIER.
     *
     * `StoreInventoryValuation` attaches `MONEY_FLOAT_PRECISION` unconditionally -- `products.price`,
     * `sale_price` and the two variant price columns are `double`, measured on MariaDB (§16) -- and
     * `/finance` renders four figures off it. Measured 2026-11-25: the controller passed the seven
     * NUMBERS to the view and dropped the caveat list, so that caveat reached no surface in the
     * product at all while the view disclosed the cost gap in prose of its own.
     *
     * The valuation attaches ONE list to SEVEN figures, so it takes the set form of the carrier
     * (`forFigures()`): a per-tile subset would be the view choosing which caveats to show, which
     * is the defect.
     */
    public function test_every_caveat_the_inventory_valuation_attaches_is_stated_on_the_finance_screen(): void
    {
        [$merchant, $store] = $this->merchantWithPaidOrder();

        $this->stockedProduct($store, ['price' => 100, 'cost_price' => 60, 'product_stock' => 4]);

        $valuation = \App\Support\Metrics\StoreInventoryValuation::forStore((int) $store->id);

        $this->assertNotSame([], $valuation->caveats(), 'premise: the valuation carries a caveat at all');
        $this->assertTrue($valuation->hasCaveat(MetricCaveat::MONEY_FLOAT_PRECISION),
            'premise: the retail columns are double, so the precision caveat is attached');

        $page = $this->text($this->actingAs($merchant->fresh())->get('/finance')->getContent());

        $this->assertStringContainsString(MetricCaveat::message(MetricCaveat::MONEY_FLOAT_PRECISION), $page,
            '/finance renders four inventory figures and says nothing about their precision');
    }

    /**
     * THE SAME GATE IN ARABIC, because a caveat a merchant cannot read is not a disclosure.
     *
     * Resolved through the TRANSLATOR and compared against what the translator returns for the
     * Arabic session -- not against a sentence typed into this file, and not against a lang file. A
     * key present in `en.json` and missing from `ar.json` falls back to English, and the only way
     * to see that is to ask the resolver in the locale the merchant is in.
     */
    public function test_every_caveat_is_stated_in_arabic_on_an_arabic_finance_screen(): void
    {
        [$merchant, $store] = $this->merchantWithPaidOrder();

        $merchant->language = 'ar';
        $merchant->save();

        $english = MetricCaveat::message(MetricCaveat::EXCLUDES_PAYMENT_FEES);

        $page = $this->text($this->actingAs($merchant->fresh())->get('/finance')->getContent());

        /* THE ORDER MATTERS. The controller built its figures in the REQUEST's locale, so the
           expectations have to be rebuilt in that locale too -- figures built before this line
           carry English sentences and would be looked for on an Arabic page. */
        $this->app->setLocale('ar');

        $figures = $this->figures($store);

        $arabic = MetricCaveat::message(MetricCaveat::EXCLUDES_PAYMENT_FEES);

        $this->assertNotSame($english, $arabic, 'the fee caveat has no Arabic, so this test proves nothing');
        $this->assertStringContainsString($arabic, $page, 'the Arabic page shows no fee caveat');
        $this->assertStringNotContainsString($english, $page, 'an Arabic merchant was shown the English sentence');

        foreach ($figures as $label => $figure) {
            foreach ($figure->caveats() as $caveat) {
                $this->assertStringContainsString($this->sentenceOf($figure, $caveat), $page,
                    "the Arabic /finance says nothing about {$caveat} on '{$label}'");
            }
        }
    }

    /**
     * THE COST CAVEAT IS ANSWERED IN THE SURFACE'S OWN WORDS, NOT THE VOCABULARY'S.
     *
     * `COST_UNKNOWN`'s sentence is written for §8 and says "for some ORDERS ... those ROWS"; this
     * tile already says how many paid orders have no cost and what that does to the figure. The
     * carrier lets a surface declare that answer, and the declaration is what keeps the omission
     * visible: it cannot be answered with silence.
     */
    public function test_a_caveat_the_surface_answers_itself_is_not_also_printed_generically(): void
    {
        [$merchant, $store] = $this->merchantWithPaidOrder(['product_cost' => '0.00', 'gross_profit' => '100.00']);

        $profit = $this->figures($store)['Estimated gross profit'];

        $this->assertTrue($profit->hasCaveat(MetricCaveat::COST_UNKNOWN), 'premise: this order records no cost');

        $page = $this->text($this->actingAs($merchant->fresh())->get('/finance')->getContent());

        $this->assertStringContainsString('no product cost on record', $page);
        $this->assertStringNotContainsString(MetricCaveat::message(MetricCaveat::COST_UNKNOWN), $page,
            'the §8 wording names orders and rows and must not reach this tile');
    }
}
