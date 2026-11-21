<?php

namespace Tests\Feature\Reports;

use App\Support\Metrics\MetricCaveat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ANA-007 — A REPORT SCREEN DISCLOSES SOMETHING, AND THESE TWO HANDLERS REALLY DO RUN IN THE SUITE.
 *
 * Two claims, and the second is why this file can exist at all.
 *
 * 1. `MetricCaveat` had **zero consumers** outside `app/Support/Metrics/` and `tests/` — the
 *    unclosed half of `GAP-201`. So "an export must carry the same caveats as the screen" was
 *    VACUOUS for all six `ReportController` exports: there was nothing on the screens to carry.
 *    The category and brand sales screens now show `FLOAT_PRECISION`, which is not invented —
 *    both handlers accumulate money as `+= (float) ($product['final_price'])` and then
 *    `array_sum()`, i.e. repeated binary-float addition of a decimal column, which is exactly
 *    "stored at limited precision and may differ from the ledger in the smallest units".
 *
 * 2. CLAUDE.md said `ReportController` "cannot run under the suite AT ALL" (36 `MONTH(`/9 `YEAR(`
 *    in `selectRaw`, absent from SQLite, so every handler answers HTTP 500 in a test). THAT WAS
 *    TOO STRONG and it is why nobody tried: measured 2026-11-21, `order_category_reports`
 *    (1893-2360) and `order_brand_reports` (2382-2903) contain **0** MySQL-only constructs, while
 *    the whole file holds 37 `MONTH(` / 10 `YEAR(`. This test is the executable proof of that
 *    correction — if either screen answered 500, the first assertion would say so.
 *
 * WHY THE ARABIC CASE IS NOT DECORATION. `MetricCaveat::message()` is `__(self::MESSAGES[$caveat])`,
 * and **11 of the 13 messages were in neither language file** before this work. Putting an
 * untranslated caveat on a screen would have shown an Arabic merchant raw English — the exact
 * defect fixed one commit earlier for `AbandonedMessageDispatch`. The locale is set through the
 * user's own `language` column because `SetLocate` re-resolves the locale per request, so
 * `app()->setLocale()` in a test does not survive it.
 */
final class ReportScreensShowTheirCaveatTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The two handlers measured to hold zero MySQL-only constructs. */
    private const SCREENS = [
        'category' => '/category-order-reports',
        'brand' => '/brand-order-reports',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    /**
     * ONE CATEGORY AND ONE BRAND FOR THE STORE, which is what makes a tile render at all.
     *
     * Both views loop `@foreach ($NetSalesofcategory as $name => $saleAmount)` (and the brand
     * equivalent) and the hint lives INSIDE that loop, so with no category and no brand the screen
     * answers 200 with no tile and therefore no caveat. The first run of this file failed exactly
     * that way, which is worth recording: an empty fixture made a disclosure test look like a
     * missing disclosure.
     *
     * The handlers fall back to every row of the store's own `Category` / `ProductBrand` when the
     * request names none (`Category::where('store_id', getCurrentStore())->pluck('id')`), so no
     * request input is needed — and none is supplied, which is also the point.
     */
    private function seedOneCategoryAndBrand($merchant, $store): void
    {
        /*
         * THE TWO TABLES DO NOT HAVE THE SAME COLUMNS, which the first version of this fixture got
         * wrong: `categories` has NO `created_by` at all (name, slug, image_url, image_path,
         * icon_path, parent_id, trending, status, store_id) while `product_brands` does. Read from
         * the migrations rather than assumed — CLAUDE.md's standing warning is that the migrations
         * are not the schema, so the columns are checked, not inferred from a sibling table.
         */
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
    }

    public function test_the_premise_that_these_two_report_screens_run_in_the_suite_at_all(): void
    {
        [$merchant] = $this->makeTenant();

        foreach (self::SCREENS as $name => $uri) {
            $response = $this->actingAs($merchant->fresh())->get($uri);

            $this->assertNotSame(500, $response->status(),
                "The {$name} report answered 500, so it is NOT suite-reachable after all and the "
                . "CLAUDE.md correction of 2026-11-21 is wrong. Re-measure its line range for "
                . "MONTH(/YEAR(/selectRaw before trusting this file.");
            $response->assertStatus(200);
        }
    }

    public function test_both_screens_show_the_float_precision_caveat_in_english(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->seedOneCategoryAndBrand($merchant, $store);

        $sentence = 'This figure is stored at limited precision and may differ from the ledger in the smallest units.';

        foreach (self::SCREENS as $name => $uri) {
            $body = $this->actingAs($merchant->fresh())->get($uri)->getContent();

            $this->assertStringContainsString($sentence, $body,
                "The {$name} report does not disclose the precision caveat, so ANA-007 is still "
                . "vacuous for it and its export has no caveat to carry.");
        }
    }

    /**
     * THE CAVEAT RESOLVES TO ARABIC — asserted at the translator, NOT end to end, and this says why.
     *
     * `MetricCaveat::message()` is `__(self::MESSAGES[$caveat])`, and 11 of the 13 messages were in
     * neither language file before this work, so an untranslated caveat would have shown an Arabic
     * merchant raw English. That risk is what this asserts, together with
     * `test_the_sentence_comes_from_the_caveat_vocabulary` above: the screen prints the vocabulary's
     * own output, and the vocabulary's own output is Arabic under an Arabic locale.
     *
     * AN UNEXPLAINED OBSERVATION, RECORDED RATHER THAN WORKED AROUND SILENTLY. I tried to assert
     * this over HTTP first, with `$merchant->language = 'ar'` — the pattern that works on
     * `/analytics`. Measured on `/category-order-reports`, same fixture, same request, twice in one
     * test:
     *
     *     [en] status=200 len=3416  English caveat present  no Arabic characters
     *     [ar] status=200 len=4508  English caveat ABSENT   no Arabic characters either
     *
     * `app()->getLocale()` really was `ar` after the request and the user's `language` column really
     * was `ar`, so `SetLocate` did its job (its whole body sits behind the git-ignored
     * `storage/installed` sentinel, which exists here and which CI creates in its own step). The
     * Arabic response is LARGER and yet contains neither sentence, so the tile did not render at
     * all rather than rendering in the wrong language. I did not establish why — a plausible lead is
     * that these handlers communicate through `Session::put` and the two requests in one test share
     * a session — and I am not asserting a behaviour I cannot explain. Recorded as a lead for the
     * ANA-007 follow-up that wires the caveat into the matching EXPORT, which has to touch that same
     * session handoff.
     */
    public function test_the_caveat_resolves_to_arabic(): void
    {
        $english = MetricCaveat::message(MetricCaveat::FLOAT_PRECISION);

        $this->app->setLocale('ar');
        $arabic = MetricCaveat::message(MetricCaveat::FLOAT_PRECISION);
        $this->app->setLocale('en');

        $this->assertNotSame($english, $arabic,
            'FLOAT_PRECISION resolves to the same text in both locales, so the Arabic entry is '
            . 'missing or identical to the English and an Arabic merchant sees English.');
        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $arabic,
            'The Arabic resolution of FLOAT_PRECISION contains no Arabic characters.');
    }

    /**
     * The sentence on the screen is the VOCABULARY'S sentence, not a copy. A second copy would drift
     * the moment the constant's wording changed, which is the whole reason `MetricCaveat` exists
     * rather than a literal in each view.
     */
    public function test_the_sentence_comes_from_the_caveat_vocabulary(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->seedOneCategoryAndBrand($merchant, $store);

        $fromVocabulary = MetricCaveat::message(MetricCaveat::FLOAT_PRECISION);

        foreach (self::SCREENS as $name => $uri) {
            $body = $this->actingAs($merchant->fresh())->get($uri)->getContent();

            $this->assertStringContainsString($fromVocabulary, $body,
                "The {$name} report's sentence is not MetricCaveat::FLOAT_PRECISION's own text.");
        }
    }
}
