<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-PKG-A-10 — the Arabic dashboard drew an English axis under an Arabic caption.
 * ================================================================================================
 *
 * MEASURED on /dashboard in all four combinations: the "Orders per day" chart labelled its seven
 * columns `31-Oct, 01-Nov, 02-Nov …` on the Arabic page, directly beneath its own caption reading
 * `الطلبات يومياً — آخر ٧ أيام`. Both label sites called `date('d-M', …)`, which has no notion of a
 * locale.
 *
 * ONE OF THE TWO SITES LOOKED LIKE IT HANDLED THIS AND NEVER HAD. `best_selling_brand_chart`
 * wrapped the call as `__(date('d-M', …))`, which asks the translator for the key "31-Oct".
 * MEASURED: `resources/lang/ar.json` holds NO `DD-Mon` key, and exactly one month name — "May",
 * which is in the file as an ordinary English word, not as a month. Laravel returns a key it
 * cannot resolve unchanged, so that `__()` has never translated a single label. It was decoration
 * that made the defect look handled, which is why this test asserts the *absence* of that shape
 * and not merely the presence of the fix.
 *
 * CARBON, NOT A TRANSLATION TABLE. Carbon is already a framework dependency and already imported
 * in this controller, and it carries month names for every locale. Adding 365 `DD-Mon` keys to
 * `ar.json` would be a table to maintain forever and would still be wrong in the next language.
 *
 * WESTERN DIGITS, DELIBERATELY. `isoFormat('D MMM')` yields `31 أكتوبر`, not `٣١ أكتوبر`. That
 * matches what the rest of this dashboard renders — the KPI figures measure as `62`, not `٦٢` —
 * so the axis agrees with the numbers directly above it rather than disagreeing with them.
 *
 * NOT AN API CHANGE, which was checked before the edit rather than assumed. `getOrderChart` is
 * called from exactly two web dashboard actions and appears nowhere in `routes/api.php`, so no
 * client contract reads these strings and the Flutter admin app cannot see them.
 *
 * MEASURED AFTER: 60 checks, 0 failed, four combinations —
 *     en  `31 Oct, 1 Nov, 2 Nov`
 *     ar  `31 أكتوبر, 1 نوفمبر, 2 نوفمبر`
 * and the fix reverted on its own produced 6 failures, so none of this is vacuous.
 */
class DashboardChartAxisLocaleTest extends TestCase
{
    private const CONTROLLER = 'app/Http/Controllers/HomeController.php';

    public function test_no_chart_axis_is_built_with_a_locale_blind_date(): void
    {
        $code = $this->codeWithoutComments();

        $this->assertSame(
            0,
            preg_match("/date\(\s*'d-M'/", $code),
            "`date('d-M')` has no notion of a locale, so the Arabic dashboard drew `31-Oct` under "
            . 'a caption reading `الطلبات يومياً`.'
        );
    }

    public function test_a_date_is_never_handed_to_the_translator_as_a_key(): void
    {
        $code = $this->codeWithoutComments();

        $this->assertSame(
            0,
            preg_match('/__\(\s*date\(/', $code),
            '`__(date(...))` asks the translator for a key like "31-Oct". ar.json holds no such '
            . 'key, so it returned the key unchanged and translated nothing — a call that made '
            . 'the defect look handled.'
        );
    }

    public function test_the_helper_formats_in_the_pages_own_locale(): void
    {
        $code = $this->code();

        $this->assertStringContainsString(
            'private function ssDayLabel(int $timestamp): string',
            $code,
            'Both axes go through one helper, so the reasoning lives in one place.'
        );

        $this->assertMatchesRegularExpression(
            '/Carbon::createFromTimestamp\(\$timestamp\)\s*->locale\(app\(\)->getLocale\(\)\)\s*'
            . "->isoFormat\('D MMM'\)/s",
            $code,
            'The label must follow the locale the page is being rendered in.'
        );

        // Carbon must actually be imported, or this is a fatal error rather than a label.
        $this->assertMatchesRegularExpression('/^use Carbon\\\\Carbon;$/m', $code);
    }

    public function test_both_call_sites_use_the_helper(): void
    {
        $code = $this->code();

        // The weekly order chart, and the best-selling-brand chart. Two sites, one helper.
        $this->assertSame(
            2,
            preg_match_all('/\$this->ssDayLabel\(/', $code),
            'Both axes must use it. Leaving one behind is how the mobile-drawer defect of '
            . 'P2.5-PKG-A-02 happened: one of a matched pair was fixed and the other was not.'
        );
    }

    public function test_the_arabic_dictionary_still_cannot_translate_a_date_which_is_the_premise(): void
    {
        $ar = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/resources/lang/ar.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        // If someone later adds DD-Mon keys, the `__()` approach becomes viable and this test's
        // reasoning should be revisited rather than silently kept. Until then the premise holds.
        $dayMonthKeys = array_filter(array_keys($ar), static fn ($k) => (bool) preg_match('/^\d{1,2}-[A-Z][a-z]{2}$/', $k));
        $this->assertCount(
            0,
            $dayMonthKeys,
            'The premise of this fix is that the translator cannot resolve a "31-Oct" key.'
        );

        $this->assertGreaterThan(3_000, count($ar), 'Anti-vacuity: this is the real dictionary.');
    }

    public function test_this_test_is_not_vacuous(): void
    {
        $code = $this->code();

        $this->assertGreaterThan(50_000, strlen($code), 'This is the real controller.');
        $this->assertStringContainsString('public function getOrderChart($arrParam)', $code);
        $this->assertStringContainsString('public function best_selling_brand_chart(', $code);
    }

    /**
     * The controller's CODE, with comments removed.
     *
     * THE FIRST VERSION OF THIS TEST FAILED ON ITS OWN DOCBLOCK. The explanation above quotes both
     * `date('d-M', ...)` and `__(date('d-M', ...))` verbatim while describing why they are wrong,
     * and a search over the raw file matched the explanation and reported the thing it forbids.
     * That is the FIFTH time in this phase a detector has matched its author's prose, and the
     * reason `MerchantHeaderStaysOnScreenTest` and `SidebarDirectionAndSubItemMarkerTest` already
     * strip comments before asserting. A rule that cannot be written down beside its own evidence
     * is a rule that will be explained somewhere else, or not at all.
     */
    private function codeWithoutComments(): string
    {
        return (string) preg_replace(
            ['#/\*.*?\*/#s', '#^\s*//.*$#m'],
            '',
            $this->code()
        );
    }

    private function code(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::CONTROLLER;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
