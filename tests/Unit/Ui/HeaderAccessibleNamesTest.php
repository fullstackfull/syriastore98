<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2-01-17 / WS B4 — every control in the shared merchant header has an accessible name.
 * =================================================================================================
 *
 * The header is rendered by `layouts/app.blade.php` on EVERY merchant screen, so a control without
 * a name is a defect on all of them at once. Measured across the partial's eight interactive
 * controls: **exactly one** had no accessible name of any kind -- the mobile sidebar toggle at
 * `partision/header.blade.php:23`, an `<a href="#!" id="mobile-collapse">` wrapping three nested
 * `div`s that draw a hamburger. A screen reader announced it as "link", with nothing else.
 *
 * THE OTHER SEVEN WERE FINE AND MY FIRST MEASUREMENT SAID OTHERWISE. A naive pass counted a
 * control as icon-only whenever its text began with `{{`, which flags `{{ __(' Profile') }}` --
 * a Blade echo that renders a perfectly good accessible name. It reported eight unnamed controls
 * where there was one. A Blade echo IS text; only genuinely empty content is icon-only, and that
 * is the rule below.
 *
 * WHY `aria-label` AND NOT A `<button>`. `<a href="#!">` acting as a button is also wrong, but
 * `public/assets/js/dash.js:99` and `side-dash.js:96` select `#mobile-collapse`, and
 * `public/assets/css/style.css:17131` styles `.dash-header .dash-head-link#mobile-collapse`.
 * Swapping the element risks the host theme's own layout on every screen for a benefit `aria-label`
 * already delivers, and the acceptance asks for accessible names. `aria-controls` was considered
 * and left out: the sidebar root is `<nav class="dash-sidebar">` with no id, in two variants, so it
 * would mean adding ids to a host partial for an attribute screen readers support weakly.
 *
 * F158 IS NOT IN THIS ROW'S SCOPE, AND THAT IS A MEASUREMENT RATHER THAN AN OPINION: the header
 * partial contains **zero** `<p>` elements. A first count said six -- it had matched `<path>` and
 * `<polyline>` inside the inline SVG icons. F158's rule is `p { color: #808191 !important }`, so it
 * cannot reach a partial with no paragraphs; the three failing paragraphs it describes are
 * dashboard CONTENT, not shared chrome.
 */
class HeaderAccessibleNamesTest extends TestCase
{
    private function header(): string
    {
        $path = dirname(__DIR__, 3) . '/resources/views/partision/header.blade.php';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * @return array<int, array{line:int, tag:string, attrs:string}>
     *         the interactive controls with no accessible name
     */
    private function unnamedControls(string $markup): array
    {
        $offenders = [];

        preg_match_all('/<(a|button)\b([^>]*)>(.*?)<\/\1>/s', $markup, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $m) {
            [$whole, $offset] = $m[0];
            $tag = $m[1][0];
            $attrs = $m[2][0];
            $inner = $m[3][0];

            // A Blade echo is text. Only genuinely empty content is icon-only -- the distinction
            // my first measurement got wrong, reporting eight offenders instead of one.
            $text = preg_replace('/\{\{--.*?--\}\}/s', '', $inner);
            $text = trim((string) preg_replace('/<[^>]+>/', '', (string) $text));

            $named = preg_match('/aria-label|aria-labelledby|\btitle\s*=/', $attrs) === 1;

            if ($text === '' && ! $named) {
                $offenders[] = [
                    'line' => substr_count(substr($markup, 0, $offset), "\n") + 1,
                    'tag' => $tag,
                    'attrs' => trim($attrs),
                ];
            }
        }

        return $offenders;
    }

    public function test_the_scan_finds_the_headers_controls_at_all(): void
    {
        // Without this the assertion below passes vacuously if the regex stops matching.
        preg_match_all('/<(a|button)\b[^>]*>.*?<\/\1>/s', $this->header(), $m);

        $this->assertGreaterThanOrEqual(6, count($m[0]),
            'the scan found almost no controls in the header, so the rule below is vacuous');
    }

    public function test_no_header_control_is_left_without_an_accessible_name(): void
    {
        $offenders = $this->unnamedControls($this->header());

        $this->assertSame([], $offenders,
            "these header controls have neither text nor an accessible-name attribute, and the "
            . "header renders on EVERY merchant screen:\n  "
            . implode("\n  ", array_map(
                fn ($o) => "line {$o['line']}: <{$o['tag']} {$o['attrs']}>", $offenders)));
    }

    public function test_the_sidebar_toggle_specifically_carries_a_translated_name(): void
    {
        $markup = $this->header();

        $this->assertMatchesRegularExpression(
            '/<a[^>]*id="mobile-collapse"[^>]*aria-label="\{\{ __\(/',
            $markup,
            'the mobile sidebar toggle has no translated aria-label, so a screen reader announces '
            . 'it as "link" on every merchant screen'
        );

        foreach (['resources/lang/en.json', 'resources/lang/ar.json'] as $file) {
            $strings = json_decode(
                (string) file_get_contents(dirname(__DIR__, 3) . '/' . $file), true
            );
            $this->assertArrayHasKey('Toggle navigation', $strings, "{$file} is missing the string");
            $this->assertNotSame('', trim((string) $strings['Toggle navigation']));
        }

        $ar = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/resources/lang/ar.json'), true
        );
        $this->assertNotSame('Toggle navigation', $ar['Toggle navigation'],
            'the toggle name is not translated into Arabic');
    }

    public function test_the_header_has_no_paragraphs_so_f158_cannot_reach_it(): void
    {
        /*
         * Stated as a test because the claim "F158 is out of scope here" is a claim about the
         * file, and because a first count said six -- having matched <path> and <polyline> inside
         * the inline SVGs. If a paragraph is ever added to the shared header it inherits
         * #808191 !important in dark mode and this row's scope decision has to be revisited.
         */
        $this->assertSame(0, preg_match_all('/<p[\s>]/', $this->header()),
            'the shared header now contains a paragraph, which takes the dark theme\'s forced '
            . '#808191 unless it carries an ss- class -- F158 is back in this row\'s scope');
    }
}
