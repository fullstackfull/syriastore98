<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-WAVE-22 — the filter bar is collapsed at phone width, and collapsing it costs nothing.
 * ================================================================================================
 *
 * WHY IT EXISTS. MEASURED on an iPhone SE viewport (320x568) before this slice: the filter bar on
 * /product rendered four open fields plus a hint and an action row, 427px of it, and the first
 * product row landed at y=814 -- past the fold, on a 568px screen, with ZERO of ten rows visible.
 * On /order the first row was at y=1293, more than twice the screen height.
 *
 * WHAT IS ASSERTED HERE IS THE PART THAT CANNOT BE SEEN IN A SCREENSHOT.
 *
 * 1. THE CHECKBOX CARRIES NO `name`. The disclosure lives INSIDE the filter form, which is a plain
 *    GET form -- `product/partials/filters.blade.php` says so in its own docblock, because yajra's
 *    `minifiedAjax()` points DataTables at the current URL and so the query string is shared with
 *    `draw`, `columns`, `search`, `order`, `start` and `length`. An unnamed control is not
 *    submitted, so the toggle cannot reach the server. A `name` would put `on` in every filtered
 *    URL, in every saved view built from `request()->query()`, and in every shared filter link.
 *
 * 2. TWO LABEL SPANS, NOT ONE. The accessible name has to state what activating the control will
 *    DO. MEASURED through Chromium's accessibility tree, not inferred from the DOM: collapsed the
 *    checkbox computes `checkbox / name="Show filters"`, and after Space it computes
 *    `name="Hide filters" checked=true`. A single static label would announce the same string in
 *    both states. (A `textContent` read shows BOTH strings -- that is why this was checked against
 *    the AX tree instead.)
 *
 * 3. THE SUMMARY IS NOT INSIDE THE COLLAPSE. `x-ss.filter-summary` states which filters are
 *    currently applied. Hiding it behind the toggle would mean a merchant could not tell, from the
 *    first screen, that the list in front of them is filtered -- which is worse than the height it
 *    would save. The component is therefore placed AFTER the summary in both views.
 *
 * 4. BOTH ARABIC KEYS EXIST. FOUND BY MEASURING, not by reading: the first run of the AX-tree probe
 *    reported `name="Show filters"` on the ARABIC page, because `__('Show filters')` and
 *    `__('Hide filters')` had no `ar.json` entry and Laravel falls back to the key. The table-
 *    control toggle beside it already read خيارات الجدول, which is what made the gap visible.
 */
class FilterDisclosureTest extends TestCase
{
    private const COMPONENT = 'resources/views/components/ss/filter-disclosure.blade.php';

    /** Every list screen whose filter bar carries the disclosure. */
    private const HOSTS = [
        'resources/views/product/partials/filters.blade.php',
        'resources/views/order/partials/filters.blade.php',
    ];

    public function test_premise_the_component_exists_and_declares_a_checkbox(): void
    {
        $source = $this->read(self::COMPONENT);

        $this->assertStringContainsString('type="checkbox"', $source,
            self::COMPONENT . ' no longer declares a checkbox, so every assertion below is vacuous.');
        $this->assertStringContainsString('ss-filterbar__toggle', $source);
    }

    /**
     * The one that would ship silently. An unnamed checkbox is not submitted; a named one would
     * append itself to the query string the filter form and DataTables share.
     */
    public function test_the_toggle_checkbox_has_no_name_so_it_cannot_reach_the_query_string(): void
    {
        $this->assertTrue(
            (bool) preg_match('/<input\s[^>]*type="checkbox"[^>]*>/', $this->read(self::COMPONENT), $m),
            'The disclosure checkbox is gone.');

        $this->assertDoesNotMatchRegularExpression('/\sname\s*=/', $m[0],
            'The disclosure checkbox has gained a `name`. It sits inside a GET filter form whose '
            . 'query string is shared with DataTables (draw/columns/search/order/start/length), so '
            . 'a name would put the toggle state into every filtered URL, every saved view and '
            . 'every shared filter link. Tag: ' . $m[0]);
    }

    /** Both halves of the state-dependent accessible name. */
    public function test_the_label_states_the_action_in_both_states(): void
    {
        $source = $this->read(self::COMPONENT);

        $this->assertStringContainsString('ss-filterbar__disclosure-show', $source,
            'The "show" half of the label is gone, so the accessible name can no longer change '
            . 'with the state. MEASURED: collapsed it must compute name="Show filters".');
        $this->assertStringContainsString('ss-filterbar__disclosure-hide', $source,
            'The "hide" half of the label is gone. MEASURED: expanded it must compute '
            . 'name="Hide filters".');
        $this->assertStringContainsString("__('Show filters')", $source);
        $this->assertStringContainsString("__('Hide filters')", $source);
    }

    /** The checkbox is clipped, not display:none, or it would stop being focusable. */
    public function test_the_checkbox_is_hidden_by_clipping_so_it_stays_focusable(): void
    {
        $this->assertStringContainsString('ss-visually-hidden', $this->read(self::COMPONENT),
            'The checkbox must be hidden with `.ss-visually-hidden`, which clips. `display: none` '
            . 'or `visibility: hidden` would remove it from the tab order and the disclosure would '
            . 'become mouse-only. MEASURED: focusable=true in both directions.');
    }

    /** Placement, which is the part that decides whether anything is actually hidden. */
    public function test_the_disclosure_follows_the_filter_summary_on_every_host(): void
    {
        foreach (self::HOSTS as $host) {
            $source = $this->read($host);

            $this->assertStringContainsString('<x-ss.filter-disclosure', $source,
                $host . ' no longer includes the disclosure, so its filter bar is open at phone '
                . 'width again.');

            $summary = strpos($source, '<x-ss.filter-summary');
            $this->assertNotFalse($summary, $host . ' no longer renders the filter summary.');

            $this->assertGreaterThan(
                $summary, strpos($source, '<x-ss.filter-disclosure'),
                $host . ': the disclosure now comes BEFORE the summary. The summary states which '
                . 'filters are applied and must stay outside the collapse -- otherwise a merchant '
                . 'cannot tell from the first screen that the list is filtered.');
        }
    }

    /** The defect the AX-tree probe actually found on the Arabic page. */
    public function test_both_disclosure_labels_are_translated(): void
    {
        $path = dirname(__DIR__, 3) . '/resources/lang/ar.json';
        $this->assertFileExists($path);

        $ar = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($ar, 'ar.json is not valid JSON.');

        foreach (['Show filters' => 'إظهار المرشّحات', 'Hide filters' => 'إخفاء المرشّحات'] as $key => $value) {
            $this->assertArrayHasKey($key, $ar,
                'ar.json has no "' . $key . '", so the Arabic page falls back to the English key. '
                . 'MEASURED through the accessibility tree: the control announced "' . $key . '" '
                . 'on the Arabic page before these entries existed.');
            $this->assertSame($value, $ar[$key],
                'The Arabic for "' . $key . '" has changed. The register is المصدر (verbal noun), '
                . 'matching تطبيق المرشّحات and مسح المرشّحات already in the file.');
        }
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
