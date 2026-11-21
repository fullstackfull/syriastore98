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

    /**
     * THESE ROUTES RETURN A JSON ENVELOPE, NOT A PAGE — and reading the raw body instead of
     * decoding it is what made the Arabic case look like a defect.
     *
     * Both handlers answer `{"html":"<div class=\"col-xxl-12\">..."}`: they are the AJAX chart
     * fragments that the full report page loads. `json_encode` escapes non-ASCII by default (no
     * `JSON_UNESCAPED_UNICODE`), so Arabic arrives as `\u0642\u064a\u0645\u0629...` — six ASCII
     * bytes per character. MEASURED: the Arabic response was LARGER than the English one (4508 vs
     * 3416 bytes) for exactly that reason, and a `/[\x{0600}-\x{06FF}]/u` match over the raw body
     * found nothing while the Arabic was sitting right there, escaped.
     *
     * The English assertions passed anyway because ASCII is not escaped — which is the dangerous
     * half: the same test was accidentally right in one language and wrong in the other. Decoding
     * the envelope is the fix, and it makes the Arabic assertion STRONGER than the locale-scoped
     * one that briefly stood in for it.
     */
    private function fragment(string $uri, $as): string
    {
        $response = $this->actingAs($as)->get($uri);

        $response->assertStatus(200);

        $decoded = json_decode($response->getContent(), true);

        $this->assertIsArray($decoded,
            "{$uri} did not answer a JSON envelope: " . json_last_error_msg());
        $this->assertArrayHasKey('html', $decoded,
            "{$uri} answered JSON with no `html` key, so the fragment shape has changed.");

        return (string) $decoded['html'];
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
            $html = $this->fragment($uri, $merchant->fresh());

            $this->assertStringContainsString($sentence, $html,
                "The {$name} report does not disclose the precision caveat, so ANA-007 is still "
                . "vacuous for it and its export has no caveat to carry.");
        }
    }

    /**
     * THE ARABIC CAVEAT REALLY REACHES THE MERCHANT, end to end, through `__()` and the Blade.
     *
     * This replaces a weaker locale-scoped assertion that only proved the translator resolves the
     * sentence. It failed end to end for a measurement reason, not an application one — see
     * `fragment()` — and the honest resolution was to decode the envelope rather than to assert
     * something smaller. 11 of the 13 `MetricCaveat` messages were in NEITHER language file before
     * this work, so without the entries this renders the English key: that is the regression this
     * guards, and `MetricCaveatMessagesAreTranslatedTest` guards the same thing at the vocabulary.
     *
     * The locale comes from the user's own `language` column because `SetLocate` re-resolves it per
     * request (and its whole body sits behind the git-ignored `storage/installed` sentinel, which
     * exists here and which CI creates in its own step), so `app()->setLocale()` in a test does not
     * survive the request.
     */
    public function test_both_screens_show_the_caveat_in_arabic(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->seedOneCategoryAndBrand($merchant, $store);

        $merchant->language = 'ar';
        $merchant->save();

        $arabic = 'هذا الرقم مخزَّن بدقة محدودة وقد يختلف عن السجل المالي في أصغر الوحدات.';
        $english = 'This figure is stored at limited precision and may differ from the ledger in the smallest units.';

        foreach (self::SCREENS as $name => $uri) {
            $html = $this->fragment($uri, $merchant->fresh());

            $this->assertStringContainsString($arabic, $html,
                "The {$name} report did not show the precision caveat in Arabic.");
            $this->assertStringNotContainsString($english, $html,
                "The {$name} report showed the ENGLISH caveat to an Arabic merchant alongside or "
                . "instead of the Arabic one.");
        }
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
            $html = $this->fragment($uri, $merchant->fresh());

            $this->assertStringContainsString($fromVocabulary, $html,
                "The {$name} report's sentence is not MetricCaveat::FLOAT_PRECISION's own text.");
        }
    }
}
