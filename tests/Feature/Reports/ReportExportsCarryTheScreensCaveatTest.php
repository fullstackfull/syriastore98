<?php

namespace Tests\Feature\Reports;

use App\Support\Metrics\MetricCaveat;
use App\Support\Reports\ReportFigureNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P05-15 — THE DOWNLOADED FILE SAYS WHAT THE SCREEN SAYS.
 * =================================================================================================
 *
 * Phase 05's rule is that an export carries the same caveats as the screen it came from, and that
 * an export which drops a caveat is a defect. `ReportScreensShowTheirCaveatTest` closed the first
 * half: the category and brand screens really do disclose `FLOAT_PRECISION`. This file closes the
 * second, which was still open and is the half a merchant actually keeps: measured 2026-11-21, all
 * five report CSV exports handed over a bare money column. Nothing in the file said the figure is
 * not payments received and not profit; nothing said it is held at limited precision.
 *
 * WHY THE ASSERTION IS IDENTITY AND NOT `assertStringContainsString`. `assertSame` against
 * `ReportFigureNote`'s own return value pins the whole composition, so a handler that sent only
 * half of it, or the wrong one of the two, fails here. The last test goes further and compares the
 * EXPORT's sentence against the SCREEN's rendered HTML, so the rule is measured at both ends
 * rather than inferred from the fact that both call the same method.
 *
 * WHAT THIS FILE CANNOT SEE, stated because a mutation proved it rather than left to be assumed:
 * a VERBATIM copy. Pasting the composition back into `ReportController` in place of the call left
 * all seven tests here green -- an identical string is identical. That hole is covered
 * structurally by `Tests\Unit\Reports\ReportFigureNoteIsTheOnlySourceTest`, which asserts each
 * sentence appears in exactly one first-party file. Both halves are needed: this file proves the
 * export says the right thing, that one proves there is only one place saying it.
 *
 * THE EXPORTS ARE DRIVEN WITH AN EMPTY SESSION ON PURPOSE. Each handler reads its series from
 * `Session::get('<report>_report')`, which a fresh test request has never populated, so
 * `monthList` and the money arrays come back `[]`. The disclosure must be there ANYWAY: a caveat
 * that only appears once there is data is a caveat the first reader of an empty report never sees,
 * and the shape of the JSON contract must not depend on whether a chart was rendered first.
 */
final class ReportExportsCarryTheScreensCaveatTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * The five exports, and which composition each one owes its reader.
     *
     * The split is not stylistic. A row with ONE money column can name its figure, so it carries
     * the definition AND the caveat. A row with SIX cannot -- `order_report` and
     * `bar_chart_order_report` write Order value, Average order value, both of the
     * including-shipping-and-tax pair, Shipping charged and Coupon value applied against a single
     * period -- so "This figure" would leave the reader guessing which, and those two carry the
     * plural precision statement while their column HEADERS carry each figure's settled label.
     */
    private const EXPORTS = [
        'category' => ['/category-order-export', 'single'],
        'brand' => ['/brand-order-export', 'single'],
        'product' => ['/product-order-export', 'single'],
        'order line chart' => ['/order-report-export', 'multi'],
        'order bar chart' => ['/order-barchart-report-export', 'multi'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    /** The JSON an export answers, decoded, with the envelope checked rather than assumed. */
    private function payload(string $uri, $as): array
    {
        $response = $this->actingAs($as)->get($uri);

        $response->assertStatus(200);

        $decoded = json_decode($response->getContent(), true);

        $this->assertIsArray($decoded,
            "{$uri} did not answer JSON: " . json_last_error_msg());

        return $decoded;
    }

    public function test_the_premise_that_all_five_exports_answer_at_all(): void
    {
        [$merchant] = $this->makeTenant();

        foreach (self::EXPORTS as $name => [$uri, $_shape]) {
            $response = $this->actingAs($merchant->fresh())->get($uri);

            $this->assertNotSame(500, $response->status(),
                "The {$name} export answered 500, so this file proves nothing about it. These five "
                . "handlers only read the caller's own session and run no dated SQL, so a 500 here "
                . "is a real regression rather than the MySQL-only-construct problem that keeps "
                . "most of ReportController out of the suite.");
            $response->assertStatus(200);
        }
    }

    public function test_every_export_carries_a_disclosure(): void
    {
        [$merchant] = $this->makeTenant();

        foreach (self::EXPORTS as $name => [$uri, $_shape]) {
            $payload = $this->payload($uri, $merchant->fresh());

            $this->assertArrayHasKey('figureNote', $payload, sprintf(
                'The %s export answers JSON with no `figureNote`, so the CSV the browser builds '
                . 'from it has no disclosure column and the merchant gets a bare money column.',
                $name
            ));

            $this->assertNotSame('', trim((string) $payload['figureNote']), sprintf(
                'The %s export sent an EMPTY disclosure. An empty cell in a spreadsheet reads as '
                . '"nothing to say about this number", which is the opposite of the truth.',
                $name
            ));
        }
    }

    public function test_the_disclosure_is_the_single_sources_own_string_and_not_a_copy(): void
    {
        [$merchant] = $this->makeTenant();

        /*
         * P5-R7-B NOTE, from the independent review. THE `multi` EXPECTATION IS NOW COUPLED TO
         * THIS FILE'S EMPTY-SESSION PREMISE, stated in its own docblock.
         *
         * `order_report_export` and `order_bar_report_export` build their cell with
         * `ReportFigureNote::salesRowHint($collection)`, which returns `moneyRowHint()` VERBATIM
         * only while the session carries no collected-payments tile for the current store. This
         * file drives the exports with an empty session, so it does. Seed `collection` here, or
         * let a request reach a sales chart handler before the export inside one test method, and
         * this assertion goes red for a non-reason.
         *
         * The collected-figure behaviour is covered by
         * `SalesExportCarriesTheCollectedFigureTest` instead, including the Arabic case, so there
         * is nothing to add here -- only something not to be surprised by.
         */
        $expected = [
            'single' => ReportFigureNote::orderValueHint(),
            'multi' => ReportFigureNote::moneyRowHint(),
        ];

        foreach (self::EXPORTS as $name => [$uri, $shape]) {
            $payload = $this->payload($uri, $merchant->fresh());

            $this->assertSame($expected[$shape], $payload['figureNote'], sprintf(
                'The %s export no longer sends ReportFigureNote::%s() verbatim. If the sentence '
                . 'has been copied into the controller it will drift from the screen; if the wrong '
                . 'composition is being sent, a %s-money row is being described as the other kind.',
                $name,
                $shape === 'single' ? 'orderValueHint' : 'moneyRowHint',
                $shape
            ));
        }
    }

    /**
     * The single-figure exports carry BOTH sentences, and the test names them separately because
     * they answer different questions and were missing for different reasons: the definition was
     * on four screens and in no export, the caveat was on two screens and in no export.
     */
    public function test_the_single_figure_exports_state_the_definition_and_the_caveat(): void
    {
        [$merchant] = $this->makeTenant();

        $definition = 'The total value of items ordered. This is not payments received, and not profit.';
        $caveat = MetricCaveat::message(MetricCaveat::FLOAT_PRECISION);

        foreach (self::EXPORTS as $name => [$uri, $shape]) {
            if ($shape !== 'single') {
                continue;
            }

            $note = (string) $this->payload($uri, $merchant->fresh())['figureNote'];

            $this->assertStringContainsString($definition, $note,
                "The {$name} export does not say what the figure is NOT. That sentence is the whole "
                . "point of the D1/D2 labelling work and it is the one a spreadsheet cannot infer.");
            $this->assertStringContainsString($caveat, $note,
                "The {$name} export does not carry the precision caveat its own screen shows.");
        }
    }

    /**
     * THE PLURAL STATEMENT IS USED WHERE IT IS TRUE AND NOWHERE ELSE.
     *
     * A nine-column row has six money columns, so `FLOAT_PRECISION`'s "This figure" cannot name
     * the one it means; `MONEY_FLOAT_PRECISION` says it of all of them. The reverse matters too --
     * sending the plural sentence for a single-figure row would be a vaguer claim than the
     * evidence supports -- so both directions are asserted.
     */
    public function test_the_multi_money_exports_use_the_plural_statement_and_the_others_do_not(): void
    {
        [$merchant] = $this->makeTenant();

        $plural = MetricCaveat::message(MetricCaveat::MONEY_FLOAT_PRECISION);
        $singular = MetricCaveat::message(MetricCaveat::FLOAT_PRECISION);

        $this->assertNotSame($plural, $singular,
            'MONEY_FLOAT_PRECISION and FLOAT_PRECISION now read identically, so this test can no '
            . 'longer tell which one a handler sent.');

        foreach (self::EXPORTS as $name => [$uri, $shape]) {
            $note = (string) $this->payload($uri, $merchant->fresh())['figureNote'];

            if ($shape === 'multi') {
                $this->assertSame($plural, $note,
                    "The {$name} export writes six money columns per row but does not use the "
                    . "plural precision statement.");
            } else {
                $this->assertStringNotContainsString($plural, $note,
                    "The {$name} export writes ONE money column per row, so the plural statement "
                    . "is a weaker claim than the evidence supports.");
            }
        }
    }

    /**
     * END TO END IN ARABIC. `ReportFigureNote` resolves through `__()`, so an Arabic merchant must
     * download Arabic -- and the English must NOT be there beside it.
     *
     * The locale comes from the user's own `language` column: `SetLocate` re-resolves the locale
     * per request, so `app()->setLocale()` in a test does not survive one. Both sentences in the
     * single-figure composition and the plural statement were added to `ar.json` with this change;
     * without those entries `__()` returns the English key and this test is what says so.
     */
    public function test_an_arabic_merchant_downloads_an_arabic_disclosure(): void
    {
        [$merchant] = $this->makeTenant();

        $merchant->language = 'ar';
        $merchant->save();

        foreach (self::EXPORTS as $name => [$uri, $shape]) {
            $note = (string) $this->payload($uri, $merchant->fresh())['figureNote'];

            $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $note, sprintf(
                'The %s export sent an Arabic merchant a disclosure with no Arabic in it: %s',
                $name,
                $note
            ));

            $this->assertStringNotContainsString('stored at limited precision', $note,
                "The {$name} export sent the ENGLISH precision sentence to an Arabic merchant.");

            if ($shape === 'single') {
                $this->assertStringNotContainsString('not payments received', $note,
                    "The {$name} export sent the ENGLISH definition to an Arabic merchant.");
            }
        }
    }

    /**
     * THE CLAIM IN ITS STRONGEST FORM: the sentence in the file is the sentence on the screen.
     *
     * Everything above compares the export against `ReportFigureNote`. This compares it against
     * the SCREEN -- the real rendered fragment of `order_category_chart` / `order_brand_chart` --
     * so the rule "an export carries the same caveats as the screen" is measured at both ends
     * rather than inferred from the fact that both call the same method.
     *
     * The screens need one category and one brand to render a tile at all: the hint lives inside
     * `@foreach ($NetSalesofcategory as $name => $saleAmount)`, so an empty fixture yields HTTP 200
     * with no tile and therefore no sentence. `categories` has NO `created_by` column while
     * `product_brands` does -- read from the migrations, not assumed from the sibling table.
     */
    public function test_the_exported_sentence_is_the_one_the_screen_renders(): void
    {
        [$merchant, $store] = $this->makeTenant();

        \App\Models\Category::forceCreate([
            'name' => 'Lighting',
            'slug' => 'lighting-' . $this->uniqueFixtureRef(),
            'store_id' => $store->id,
        ]);

        \App\Models\ProductBrand::forceCreate([
            'name' => 'Lumen',
            'slug' => 'lumen-' . $this->uniqueFixtureRef(),
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);

        $pairs = [
            'category' => ['/category-order-reports', '/category-order-export'],
            'brand' => ['/brand-order-reports', '/brand-order-export'],
        ];

        foreach ($pairs as $name => [$screenUri, $exportUri]) {
            $screen = $this->actingAs($merchant->fresh())->get($screenUri);
            $screen->assertStatus(200);

            /*
             * These report routes answer `{"html": "..."}` -- they are the AJAX chart fragments the
             * full page loads. `json_encode` escapes non-ASCII, so reading the RAW body for Arabic
             * finds nothing while the Arabic sits there escaped; decoding is what makes this
             * comparison honest in both languages.
             */
            $decoded = json_decode($screen->getContent(), true);
            $this->assertIsArray($decoded);
            $this->assertArrayHasKey('html', $decoded,
                "{$screenUri} no longer answers an `html` envelope.");

            $exported = (string) $this->payload($exportUri, $merchant->fresh())['figureNote'];

            $this->assertStringContainsString($exported, (string) $decoded['html'], sprintf(
                'The %s export sends a sentence the %s SCREEN does not show. The two have drifted, '
                . 'which is exactly what routing both through ReportFigureNote is meant to prevent.',
                $name,
                $name
            ));
        }
    }
}
