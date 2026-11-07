<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5 header-icononly-name-lost-by-tooltip — `title=` plus `data-bs-toggle="tooltip"` is NOT an
 * accessible name, and on three of the shared header's controls it was the only one.
 * =================================================================================================
 *
 * THE MECHANISM, MEASURED IN THE SHIPPED BUNDLE rather than assumed from the Bootstrap docs.
 * `public/assets/js/plugins/bootstrap.min.js` carries the `Bootstrap v5.1.3` banner. Its Tooltip
 * constructor calls `_fixTitle()` whenever no `selector` is configured, and `_fixTitle()` reads:
 *
 *     _fixTitle(){const t=this._element.getAttribute("title"),
 *       e=typeof this._element.getAttribute("data-bs-original-title");
 *       (t||"string"!==e)&&(this._element.setAttribute("data-bs-original-title",t||""),
 *       !t||this._element.getAttribute("aria-label")||this._element.textContent
 *         ||this._element.setAttribute("aria-label",t),
 *       this._element.setAttribute("title",""))}
 *
 * So it moves `title` into `data-bs-original-title`, writes an `aria-label` ONLY when the element
 * has no `aria-label` AND no `textContent`, and then blanks `title` unconditionally. Each of the
 * three offending anchors has a newline plus ~27 spaces of indentation before its inline `<svg>`,
 * so `textContent` is a TRUTHY whitespace string, the `aria-label` fallback short-circuits, and
 * `title` is emptied anyway. Post-load each anchor had `title=""`, no `aria-label`, and an inline
 * SVG with no `role` and no `<title>` child — nothing in the accessible-name computation at all.
 * NVDA and VoiceOver announce bare "link"; the real name sits in `data-bs-original-title`, which
 * is not a name source. The constructor runs on every admin screen on BOTH script branches
 * (`partision/footerlink.blade.php` loads `bootstrap.min.js` then `dash.js:561,564` or
 * `side-dash.js:620,623` builds a Tooltip for every `[data-bs-toggle="tooltip"]`).
 *
 * WHY `aria-label` IN THE MARKUP AND NOT A ONE-LINE JS FIX at those two construction sites. A JS
 * fix is one edit for all 95 icon-only tooltip controls in the admin, which is genuinely tempting —
 * but it makes every accessible name in the application depend on a script having run, which is
 * exactly the fragility this row is about, and `dash.js` / `side-dash.js` are host theme files.
 * Collapsing the whitespace before each SVG so the vendor's own fallback fires is worse still:
 * invisible in the Blade file, dependent on minified vendor behaviour an upgrade can change, and
 * gone with JavaScript disabled. `test_the_vendor_fallback_still_cannot_rescue_these_controls()`
 * below pins that the whitespace is still there, so this repair is not quietly leaning on it.
 *
 * `title=` AND `data-bs-toggle="tooltip"` STAY. The visual tooltip is the name a sighted mouse user
 * gets, and `_fixTitle()` reads `getAttribute("aria-label")` BEFORE it would write one, so the
 * explicit label survives the plugin untouched. The same duplication (label in `title` and in an
 * accessible-name attribute) is this codebase's existing, test-enforced convention — see
 * `tests/Unit/Ui/ActionPartialContractTest.php`, which already codifies "title is not a name on a
 * tooltip control" across 37 action partials.
 */
class HeaderIconOnlyTooltipNamesTest extends TestCase
{
    private function header(): string
    {
        $path = dirname(__DIR__, 3) . '/resources/views/partision/header.blade.php';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * Every `<a>`/`<button>` in the header that carries `data-bs-toggle="tooltip"`.
     *
     * @return array<int, array{line:int, attrs:string, inner:string, text:string}>
     */
    private function tooltipControls(string $markup): array
    {
        $found = [];

        preg_match_all('/<(a|button)\b([^>]*)>(.*?)<\/\1>/s', $markup, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $m) {
            $attrs = $m[2][0];

            if (preg_match('/data-bs-toggle\s*=\s*"tooltip"/', $attrs) !== 1) {
                continue;
            }

            $inner = $m[3][0];

            // A Blade echo IS text, so only genuinely empty content is icon-only. Blade comments
            // are not rendered and do not count.
            $text = preg_replace('/\{\{--.*?--\}\}/s', '', $inner);
            $text = trim((string) preg_replace('/<[^>]+>/', '', (string) $text));

            $found[] = [
                'line' => substr_count(substr($markup, 0, $m[0][1]), "\n") + 1,
                'attrs' => trim($attrs),
                'inner' => $inner,
                'text' => $text,
            ];
        }

        return $found;
    }

    /** Vacuity guard: without this, every rule below passes if the scan stops matching. */
    public function test_the_scan_still_finds_the_headers_tooltip_controls(): void
    {
        $controls = $this->tooltipControls($this->header());

        $this->assertGreaterThanOrEqual(4, count($controls),
            'the scan found fewer than the four measured data-bs-toggle="tooltip" controls in the '
            . 'shared header, so the rules below are vacuous');
    }

    /**
     * THE RULE. An icon-only tooltip control must carry a real accessible-name attribute, because
     * Bootstrap takes its `title` away before a screen reader ever sees it.
     */
    public function test_every_icon_only_tooltip_control_carries_an_aria_label(): void
    {
        $offenders = [];

        foreach ($this->tooltipControls($this->header()) as $control) {
            if ($control['text'] !== '') {
                continue; // it has real text content, which Bootstrap cannot take away
            }

            if (preg_match('/aria-label|aria-labelledby/', $control['attrs']) === 1) {
                continue;
            }

            $offenders[] = "line {$control['line']}: " . preg_replace('/\s+/', ' ', $control['attrs']);
        }

        $this->assertSame([], $offenders,
            "these icon-only header controls rely on `title=` for their accessible name, and "
            . "Bootstrap 5.1.3's _fixTitle() blanks `title` and declines to write an `aria-label` "
            . "because their whitespace textContent is truthy — so a screen reader announces bare "
            . "\"link\" on EVERY merchant screen:\n  " . implode("\n  ", $offenders));
    }

    /**
     * POSITIVE CONTROL — the three named controls, each with the translation key that already
     * exists in both language files. Reusing the existing keys is not a nicety: it is what keeps
     * `ArabicUiCoverageTest` (which scans `resources/views/partision/*.blade.php` for `__()` keys
     * absent from `ar.json`) and `TranslationFileIntegrityTest`'s orphan ceiling green.
     */
    public function test_the_three_controls_carry_their_existing_translated_keys(): void
    {
        $markup = $this->header();

        $expected = [
            'Clear Cache' => '/<a[^>]*url\(\x27config-cache\x27\)[^>]*aria-label="\{\{ __\(\x27Clear Cache\x27\) \}\}"/s',
            'Store Link' => '/<a[^>]*target="_blank"[^>]*aria-label="\{\{ __\(\x27Store Link\x27\) \}\}"/s',
            'POS' => '/<a[^>]*route\(\x27pos\.index\x27\)[^>]*aria-label="\{\{ __\(\x27POS\x27\) \}\}"/s',
        ];

        foreach ($expected as $key => $pattern) {
            $this->assertMatchesRegularExpression($pattern, $markup,
                "the header control for \"{$key}\" has no translated aria-label");
        }

        $en = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/resources/lang/en.json'), true);
        $ar = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/resources/lang/ar.json'), true);

        foreach (array_keys($expected) as $key) {
            $this->assertArrayHasKey($key, $en, "resources/lang/en.json is missing \"{$key}\"");
            $this->assertArrayHasKey($key, $ar, "resources/lang/ar.json is missing \"{$key}\"");
            $this->assertNotSame('', trim((string) $ar[$key]));
        }

        // Two of the three are genuinely translated; "POS" is an acronym that ar.json deliberately
        // leaves as "POS". Asserting the translation for the two that have one, and asserting the
        // acronym is unchanged, keeps this honest in both directions.
        $this->assertNotSame('Clear Cache', $ar['Clear Cache']);
        $this->assertNotSame('Store Link', $ar['Store Link']);
        $this->assertSame('POS', $ar['POS']);
    }

    /**
     * NEGATIVE CONTROL — the repair must not take the visual tooltip away. `title=` is the name a
     * sighted mouse user gets, and `docs/development/phase-2/tools/capture-header-contract.cjs`
     * resolves a control's recorded name as `aria-label || textContent || (title ||
     * data-bs-original-title)`, so removing `title` would make the header capture record an empty
     * name for these two controls instead of the same string it records today.
     */
    public function test_the_visual_tooltip_is_still_there_on_all_three(): void
    {
        foreach ($this->tooltipControls($this->header()) as $control) {
            if ($control['text'] !== '') {
                continue;
            }

            $this->assertMatchesRegularExpression('/\btitle\s*=\s*"\{\{ __\(/', $control['attrs'],
                "line {$control['line']}: the icon-only tooltip control lost its title=, so a "
                . 'sighted mouse user now gets no tooltip at all');
            $this->assertStringContainsString('data-bs-toggle="tooltip"', $control['attrs']);
        }
    }

    /**
     * NEGATIVE CONTROL — the aria-label and the title must say the SAME thing. This is the one real
     * cost of the markup fix over a JS fix: the label is written twice on the same element, and the
     * two copies can drift. This assertion is what stops that.
     */
    public function test_the_aria_label_and_the_title_do_not_drift(): void
    {
        foreach ($this->tooltipControls($this->header()) as $control) {
            if ($control['text'] !== '') {
                continue;
            }

            preg_match('/\btitle\s*=\s*"([^"]*)"/', $control['attrs'], $t);
            preg_match('/\baria-label\s*=\s*"([^"]*)"/', $control['attrs'], $a);

            $this->assertSame($t[1] ?? null, $a[1] ?? null,
                "line {$control['line']}: title= and aria-label= have drifted apart on the same "
                . 'control, so the tooltip and the screen reader now say different things');
        }
    }

    /**
     * NEGATIVE CONTROL / the test can still see the defect's cause. If somebody ever "fixes" this
     * by collapsing the indentation before the inline SVG so that `textContent` becomes falsy and
     * Bootstrap's own fallback writes the `aria-label`, this fails. That route makes the name
     * depend on a script having run and on minified vendor behaviour, and leaves the Blade file
     * looking as though nothing names the control.
     */
    public function test_the_vendor_fallback_still_cannot_rescue_these_controls(): void
    {
        $bootstrap = (string) file_get_contents(
            dirname(__DIR__, 3) . '/public/assets/js/plugins/bootstrap.min.js');

        // The mechanism itself, pinned against a vendor upgrade that changes it.
        $this->assertStringContainsString('Bootstrap v5.1.3', $bootstrap,
            'the Bootstrap bundle was upgraded — re-read _fixTitle() before trusting this row');
        $this->assertStringContainsString(
            'this._element.setAttribute("data-bs-original-title",t||"")', $bootstrap,
            '_fixTitle() no longer moves title into data-bs-original-title');
        $this->assertStringContainsString(
            '!t||this._element.getAttribute("aria-label")||this._element.textContent'
            . '||this._element.setAttribute("aria-label",t)', $bootstrap,
            '_fixTitle() no longer short-circuits its aria-label fallback on truthy textContent');

        foreach ($this->tooltipControls($this->header()) as $control) {
            if ($control['text'] !== '') {
                continue;
            }

            // What the DOM's textContent would be: the inline SVG's own children (<path>,
            // <polyline>, <circle>, <rect>) carry no text, so textContent is exactly the
            // whitespace around them. It must still be a TRUTHY whitespace string — that is the
            // condition on which _fixTitle() declines to write an aria-label of its own.
            $textContent = (string) preg_replace('/<[^>]+>/', '', $control['inner']);

            $this->assertNotSame('', $textContent,
                "line {$control['line']}: the whitespace before the inline SVG was collapsed, so "
                . "Bootstrap's _fixTitle() now writes the accessible name itself at runtime. That "
                . 'makes the name depend on a script having run and on minified vendor behaviour '
                . 'an upgrade can change — strictly more fragile than the attribute in the markup.');
            $this->assertSame('', trim($textContent),
                "line {$control['line']}: expected whitespace-only content for an icon-only control");
        }
    }

    /**
     * NEGATIVE CONTROL — the repair took nothing away, and it moved no gate. The two storefront
     * controls sit inside `type == 'admin'` and Clear Cache inside `type == 'super admin'`; both
     * are string comparisons on `users.type`, and a staff member's `users.type` is their ROLE NAME,
     * so neither block is reachable by employees. "While we are here, let staff use POS" is a
     * different change. The bell is deliberately outside both blocks and keeps its own name.
     */
    public function test_the_gates_and_the_already_named_controls_are_untouched(): void
    {
        $markup = $this->header();

        $this->assertStringContainsString(
            "@if (auth()->user() && auth()->user()->type == 'super admin')", $markup,
            'the operator gate on Clear Cache moved');
        $this->assertSame(2, substr_count($markup, "@if (auth()->user() && auth()->user()->type == 'admin')"),
            "the two owner-only blocks around Store Link / POS / Quick Add and the store switcher "
            . 'are no longer exactly as they were');

        // The bell was already correctly named by a .visually-hidden span, and the sidebar toggle
        // by an aria-label. Neither may be collateral damage.
        $this->assertMatchesRegularExpression(
            '/<span class="visually-hidden" data-ss-notification-label>\{\{ __\(\x27Notifications\x27\) \}\}<\/span>/',
            $markup, 'the notification bell lost its visually-hidden name');
        $this->assertMatchesRegularExpression(
            '/<a[^>]*id="mobile-collapse"[^>]*aria-label="\{\{ __\(/s', $markup,
            'the mobile sidebar toggle lost its aria-label');

        // No aria-live anywhere in this partial: TransientFeedbackContractTest asserts the same
        // thing, and announcing the bell count via a live region is the over-reach that breaks it.
        $this->assertStringNotContainsString('aria-live', $markup);
    }
}
