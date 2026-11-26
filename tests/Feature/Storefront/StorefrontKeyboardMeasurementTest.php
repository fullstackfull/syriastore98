<?php

namespace Tests\Feature\Storefront;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GAP-095 (P03-A-04) — THE MEASUREMENT HALF: tab order, focus visibility, operable controls.
 * =================================================================================================
 *
 * THE ROW HAS TWO HALVES AND ONLY ONE WAS DONE. `StorefrontKeyboardOperabilityTest` closed three
 * measured WCAG failures across all four layout copies — no skip link, no `<main>`, and themes
 * deleting the focus indicator. That is the ENGINEERING half. The row's first clause asks for
 * something else: *"Measure keyboard operability of the storefront (tab order, focus visibility,
 * operable controls) and record it"*. A skip link that exists is not a measured tab order, and a
 * focus ring that is declared is not a focus ring that is VISIBLE against the thing it is drawn on.
 *
 * This file is the capture stage of that measurement, and it is a TEST THAT ALWAYS RUNS rather than
 * a conditionally skipped one, because a skip would break the declared outcome set (CLAUDE.md §3).
 * What it asserts on every run is the PREMISE every browser reading downstream depends on; what it
 * additionally does, when `SS_STOREFRONT_HTML_DIR` is set, is write those documents out for
 * `tests/Support/bin/check-storefront-keyboard-live.cjs` to drive.
 *
 * ------------------------------------------------------------------------------------------------
 *  WHY A CAPTURE NEEDS THREE PREMISES HERE AND NOT ONE
 * ------------------------------------------------------------------------------------------------
 *
 *  1. THE LANDED PATH. A redirect that has been followed is also a page, and another workstream
 *     found four committed evidence images that are photographs of the DASHBOARD rather than the
 *     screen they were captioned with. So each case asserts HTTP 200 (never a 3xx) and a marker
 *     that only that surface carries.
 *
 *  2. THE THEME. `Theme::set()` → `prependLocation()` means the active theme's own
 *     `front_end/layouts/app.blade.php` wins over the `resources/views` copy, and there are FOUR
 *     copies. A case that believes it is driving techzonix while stylique rendered is measuring
 *     the wrong file, so the theme's own stylesheet path must be in the document.
 *
 *  3. THE DIRECTION. Tab order in RTL is a real question and not a mirror: a sequence that reads
 *     correctly left-to-right can tab in an order that makes no sense right-to-left. An Arabic case
 *     that silently rendered LTR would report the LTR answer twice, so `dir` is asserted per case.
 *
 * ------------------------------------------------------------------------------------------------
 *  AND THE SCRIPTS ARE KEPT, WHICH IS THE OPPOSITE OF THE SETTINGS CAPTURE
 * ------------------------------------------------------------------------------------------------
 *
 * The merchant-panel harness strips `<script>` because the settings page's own AJAX deletes the
 * panel on failure. Doing that here would invalidate the measurement instead of enabling it: every
 * storefront layout loads `themes/<theme>/assets/js/tailwind.js`, the runtime JIT CDN, and
 * CLAUDE.md §6 records that 270–294 rules are injected INLINE AT RUNTIME on a rendered page. A
 * utility beats the theme stylesheet on source order at equal specificity, so a focus ring measured
 * without Tailwind is measured against a cascade no shopper ever sees. The browser harness
 * therefore keeps every script, serves `themes/` and `public/` locally, and asserts that Tailwind
 * really did inject before it reads a single colour.
 */
final class StorefrontKeyboardMeasurementTest extends TestCase
{
    use RefreshDatabase;

    /** The three themes that ship. `config('theme.active')` is stylique, so a themeless store gets that one. */
    private const THEMES = ['stylique', 'greentic', 'techzonix'];

    /**
     * The shopper's core path, and a marker only that surface carries.
     *
     * CHOSEN BY MEASUREMENT, NOT BY PREFERENCE. Fourteen storefront GETs were probed across the
     * three themes and both directions; `search-product` answers 62 bytes (a fragment endpoint, not
     * a screen) and is excluded for that reason. The other thirteen all render 200. These five are
     * the ones a shopper cannot avoid — arrive, browse, filter, basket, sign in — so a keyboard
     * failure on any of them blocks the whole funnel.
     *
     * @var array<string, string>
     */
    private const SURFACES = [
        'home' => 'ss-skip-link',
        'product-list' => 'ss-skip-link',
        'collections' => 'ss-skip-link',
        'cart' => 'ss-skip-link',
        'login' => 'ss-skip-link',
    ];

    /** @var array<string, Store> */
    private array $stores = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $merchant = User::forceCreate([
            'name' => 'Keyboard Measurement Merchant',
            'email' => 'keyboard-measurement@example.test',
            'password' => bcrypt('x'), 'type' => 'admin',
            'created_by' => 1, 'email_verified_at' => now(),
        ]);

        foreach (self::THEMES as $theme) {
            foreach (['en', 'ar'] as $lang) {
                $slug = $theme . '-' . $lang;

                $this->stores[$slug] = Store::create([
                    'name' => ucfirst($theme) . ' ' . strtoupper($lang),
                    'email' => $slug . '@example.test',
                    'slug' => $slug,
                    'theme_id' => $theme,
                    'created_by' => $merchant->id,
                    'is_active' => 1,
                    'default_language' => $lang,
                ]);
            }
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function storefronts(): array
    {
        $out = [];

        foreach (self::THEMES as $theme) {
            foreach (['en', 'ar'] as $lang) {
                $out["{$theme} {$lang}"] = [$theme, $lang];
            }
        }

        return $out;
    }

    /**
     * EVERY SURFACE THIS MEASUREMENT CLAIMS TO COVER REALLY IS THAT SURFACE, in both directions and
     * on the theme named — and the documents are written out for the browser stage.
     *
     * @dataProvider storefronts
     */
    #[Test]
    #[DataProvider('storefronts')]
    public function test_every_measured_surface_is_the_surface_the_theme_and_the_direction_it_claims(
        string $theme,
        string $lang
    ): void {
        $dir = getenv('SS_STOREFRONT_HTML_DIR') ?: null;

        if ($dir !== null && ! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        $slug = $theme . '-' . $lang;

        foreach (self::SURFACES as $surface => $marker) {
            $response = $this->get("/{$slug}/{$surface}");

            /* 1. THE LANDED PATH. Asserted as "exactly 200", not "not an error": a 302 to the
                  dashboard is a successful HTTP exchange and renders a real page somewhere else. */
            $this->assertSame(200, $response->getStatusCode(), sprintf(
                '/%s/%s answered %d, so whatever a browser would photograph here is not this '
                . 'surface. A redirect that has been followed is also a page.',
                $slug, $surface, $response->getStatusCode()
            ));

            $html = (string) $response->getContent();

            $this->assertStringContainsString($marker, $html, sprintf(
                '/%s/%s rendered without the storefront layout marker "%s", so it is not a '
                . 'storefront page at all.',
                $slug, $surface, $marker
            ));

            /* 2. THE THEME. Four copies of the layout exist and the theme's own wins. */
            $this->assertStringContainsString("themes/{$theme}/assets/css/main-style.css", $html,
                "/{$slug}/{$surface} did not load {$theme}'s own stylesheet, so this capture is of "
                . 'some other theme and a layout-level finding from it would be misattributed.');

            /* 3. THE DIRECTION. */
            $this->assertMatchesRegularExpression(
                '/<html[^>]*\bdir="' . ($lang === 'ar' ? 'rtl' : 'ltr') . '"/',
                $html,
                "/{$slug}/{$surface} did not render dir=\"" . ($lang === 'ar' ? 'rtl' : 'ltr')
                . '", so an RTL reading from it would be an LTR reading in disguise.'
            );

            /* The runtime JIT is what paints colour on this surface, so its absence would make
               every contrast reading downstream a reading of the theme sheet alone. */
            $this->assertStringContainsString("themes/{$theme}/assets/js/tailwind.js", $html,
                "/{$slug}/{$surface} does not load the theme's Tailwind runtime bundle. CLAUDE.md "
                . '§6 records that 270-294 rules are injected at runtime on a rendered page, so a '
                . 'colour measured without it is measured against a cascade no shopper sees.');

            if ($dir !== null) {
                file_put_contents(
                    rtrim($dir, '/') . "/storefront-{$surface}-{$theme}-{$lang}.html",
                    $html
                );
            }
        }
    }

    /**
     * THE CLOSED OFF-CANVAS MENU IS OUT OF THE TAB ORDER — the one defect this slice fixed.
     *
     * MEASURED BEFORE: the last three tab stops of every one of the 30 pages were `#mobile-menu`'s
     * logo link, Close button and language button, at x=1456..1744 in a 1440px viewport. 90 stops,
     * 3 per page, every surface, every theme, both directions. `translate-x-full` moves the panel
     * off-screen and leaves it RENDERED, and a rendered element stays tabbable — so a sighted
     * keyboard user tabbed past the footer and focus left the visible page. WCAG 2.4.3 and 2.4.7.
     *
     * MEASURED AFTER: offscreen-focus 90 -> 0, and the tabbable count per page fell by EXACTLY 3
     * (19->16, 20->17, 31->28), which is the differential that says the fix removed those three
     * controls and nothing else. Every other category was unchanged.
     *
     * THE FIX IS THE THEMES' OWN CONVENTION. All three `custom.js` open the panel with
     * `classList.remove('hidden')` and close it with `classList.add('hidden')`, so `hidden` is the
     * closed state and the server-rendered markup simply never declared it. Both halves are
     * asserted here: the class in the rendered document, and the removal in each theme's script —
     * because adding a class the open handler does not remove would make the menu unopenable, which
     * is a worse bug than the one being fixed.
     *
     * @dataProvider storefronts
     */
    #[Test]
    #[DataProvider('storefronts')]
    public function test_the_closed_off_canvas_menu_is_out_of_the_tab_order(
        string $theme,
        string $lang
    ): void {
        $html = (string) $this->get("/{$theme}-{$lang}/home")->getContent();

        $this->assertMatchesRegularExpression('/<div id="mobile-menu" class="[^"]*"/', $html,
            'the off-canvas panel is not on the page, so this assertion would be vacuous');

        preg_match('/<div id="mobile-menu" class="([^"]*)"/', $html, $m);
        $classes = preg_split('/\s+/', trim($m[1] ?? '')) ?: [];

        $this->assertContains('hidden', $classes, sprintf(
            'the closed off-canvas menu does not carry `hidden`, so it is RENDERED off-screen and '
            . 'its three controls are back in the tab order of every page — focus leaves the '
            . 'visible viewport after the footer. Classes: %s',
            implode(' ', $classes)
        ));

        /* It must still be translated off-screen as well: `hidden` alone would make the open
           handler's `remove('hidden')` reveal a panel that was never positioned. */
        $this->assertContains('translate-x-full', $classes,
            'the panel lost its off-screen transform, so removing `hidden` on open would no longer '
            . 'slide it in from the edge');
    }

    /**
     * AND THE OPEN HANDLER REALLY REMOVES THAT CLASS, in every theme that ships.
     *
     * This is the half that makes the fix above safe rather than merely correct: a class the open
     * handler does not remove would leave the menu permanently unopenable.
     */
    #[Test]
    public function test_every_theme_opens_the_off_canvas_menu_by_removing_that_class(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (self::THEMES as $theme) {
            $path = $root . "/themes/{$theme}/assets/js/custom.js";

            $this->assertFileExists($path, "{$theme} has no custom.js");

            $js = (string) file_get_contents($path);

            $this->assertStringContainsString("mobileMenu.classList.remove('hidden')", $js, sprintf(
                "%s's menu toggle does not open the panel by removing `hidden`, so adding that "
                . 'class to the markup would make the menu unopenable in this theme. If the script '
                . 'changed, the markup has to change with it.',
                $theme
            ));

            $this->assertStringContainsString("mobileMenu.classList.add('hidden')", $js,
                "{$theme}'s close handler does not restore `hidden`, so `hidden` is not this "
                . "theme's closed state and the markup should not assume it is.");
        }
    }

    /**
     * THE REMAINING FINDINGS, DECLARED WITH THEIR MEASURED COUNTS AND A DISPOSITION EACH.
     *
     * The measurement found seven categories. Two were clean, one was fixed, three were INSTRUMENT
     * ARTEFACTS that were corrected rather than reported, and three remain. Reporting the artefacts
     * as defects would have been the easy and wrong outcome, so they are recorded here too:
     *
     *   CLEAN      no-indicator  0/884   every tab stop's appearance changes on focus. The engineering
     *                                    half of GAP-095 works.
     *              click-only    0/30 pages  no element responds to a click without being focusable.
     *                                    Measured, not assumed: the storefront views contain ZERO
     *                                    inline `onclick` on a div/span/i/img, and the delegated
     *                                    jQuery click selectors all resolve to <button> or <input>.
     *
     *   FIXED      offscreen-focus 90 -> 0   the closed off-canvas menu, above.
     *
     *   ARTEFACT   unreached 180 -> 2    the enumeration included elements that are not RENDERED,
     *                                    so the closed language dropdown's six links were reported
     *                                    as "tabbable but never reached" on every page. Tab was
     *                                    right; the instrument was wrong.
     *              nameless 18 -> 0      the name extraction ignored `<label for>`, the PRIMARY
     *                                    naming mechanism for a form control. login.blade.php:35,
     *                                    :42 and :53 all carry one.
     *              weak-indicator 66 -> 32  half the readings were against the element's own fill
     *                                    when the ring is drawn on its PARENT: `outline-offset` is
     *                                    +2px for the general rule, so the ring sits outside the
     *                                    border box. Same class of error as measuring a modal's
     *                                    label against the page.
     *
     * WHAT REMAINS IS BELOW. None is a labelling problem and none is fixed on this slice's
     * authority: one needs a visual-design decision, one is a layout decision, and one is in
     * vendored library behaviour.
     */
    #[Test]
    public function test_the_remaining_keyboard_findings_are_declared_with_their_measured_counts(): void
    {
        $findings = [
            /*
             * 1. THE FOCUS RING IS ONE FIXED COLOUR AND THE STOREFRONT HAS BRAND-COLOURED BARS.
             *
             * `public/css/storefront-keyboard.css` draws `--ss-focus-ring` (#4f77c0) at
             * `outline-offset: 2px`, so the ring lands on the PARENT's background. Measured:
             *   techzonix's purple utility bar  rgb(153,82,224)  ->  1.02:1   (Shop now, EN/AR)
             *   greentic's green search bar     rgb(57,132,60)   ->  1.04:1   (search input)
             *   stylique's coral swiper arrow   on white         ->  2.64:1
             * WCAG 1.4.11 asks 3:1. The file's own comment claims 4.44:1 light and 8.27:1 dark,
             * which is TRUE against the page background and is the only surface that was measured
             * when it was written — it is not true of a ring drawn on a brand-coloured bar, and a
             * single ring colour cannot clear 3:1 against every brand colour at once.
             *
             * NOT FIXED HERE because the remedy is a visual-design decision: the standard answer is
             * a two-tone ring (the token colour plus a contrasting outer halo), which changes how
             * focus looks on every storefront surface in three themes and wants its own
             * re-measurement in all four layout copies.
             */
            'weak-indicator' => [
                'count' => 32,
                'disposition' => 'design decision: a two-tone ring, re-measured per theme',
            ],

            /*
             * 2. THE FOOTER'S DOM ORDER AND ITS VISUAL ORDER DISAGREE, in greentic and techzonix.
             *
             * One stop per page, 26 in all: the social-media links (y=701) are followed by the
             * phone link (y=648), so tab moves UP the page by ~53px inside the footer. stylique has
             * ZERO of these, which is what says it is a per-theme layout fact rather than a shared
             * defect. Both directions report it, so it is not an RTL-specific bug.
             *
             * NOT FIXED HERE, by the standing rule: a tab order that is wrong because the visual
             * layout puts a later column above an earlier one is a design decision, and re-ordering
             * the footer DOM on a measurement slice's authority would change the page for everyone
             * to satisfy a checker.
             */
            'order-inversions' => [
                'count' => 26,
                'disposition' => 'layout decision: the footer column order, greentic and techzonix',
            ],

            /*
             * 3. TWO SWIPER ARROWS ARE ENUMERATED AND NEVER REACHED, on greentic's home page only.
             *
             * `<div class="swiper-button-next …"></div>` is OUR markup (offer.blade.php:79 and
             * eight siblings) but it is bare — no role, no tabindex, no name. Swiper's a11y module
             * upgrades it at runtime, which is where `aria-label="Next slide"` comes from
             * (`swiper.esm.bundle.js:6019`, `nextSlideMessage`). So its keyboard behaviour is the
             * vendored library's, and whether a given arrow is in the tab order depends on the
             * library's own state at the moment the DOM is read — loop mode replaces slide nodes.
             *
             * NOT A CONFIRMED DEFECT AND NOT FIXED: it is as likely to be this harness reading a
             * dynamic DOM one tick too early as a real unreachable control, and `packages/workdo/`
             * is vendored. Stated so the next pass starts from the measurement rather than from
             * zero.
             */
            'unreached' => [
                'count' => 2,
                'disposition' => 'vendored Swiper dynamic DOM; may be an instrument timing artefact',
            ],
        ];

        $this->assertSame(60, array_sum(array_column($findings, 'count')), sprintf(
            'the declared storefront keyboard remainder changed size. It was 32 + 26 + 2 = 60 when '
            . 'measured over 5 surfaces x 3 themes x 2 languages at 1440px. If a finding was '
            . 'closed, say so here and re-run tests/Support/bin/check-storefront-keyboard-live.cjs; '
            . 'if one was added, say why it is open. Counts: %s',
            json_encode(array_map(static fn ($f) => $f['count'], $findings))
        ));

        /* THE CONDITION THAT WOULD CLOSE FINDING 1, made executable rather than left as prose: the
           ring is a SINGLE colour today. When somebody gives it a contrasting second edge, this
           fails and sends them back to the harness to re-measure the three brand-coloured bars. */
        $layer = (string) file_get_contents(
            dirname(__DIR__, 3) . '/public/css/storefront-keyboard.css'
        );
        $rules = preg_replace('#/\*.*?\*/#s', '', $layer) ?? $layer;

        $this->assertStringNotContainsString('box-shadow', $rules, sprintf(
            'the storefront focus layer now declares a box-shadow, which is how a two-tone focus '
            . 'ring is built. If that is what happened, finding 1 may be closed — re-run '
            . 'tests/Support/bin/check-storefront-keyboard-live.cjs and replace its count (32) with '
            . 'the new measurement rather than deleting the row.'
        ));

        $this->assertStringContainsString('outline-offset: var(--ss-focus-offset, 2px)', $rules,
            'the general focus rule no longer uses a POSITIVE outline-offset. The offset is what '
            . 'decides whether the ring is drawn on the parent background or the element own fill, '
            . 'and the 32 readings above are against the parent. Re-measure before trusting them.');
    }

    /**
     * THE BROWSER HARNESS'S IN-PAGE INSTRUMENT MUST CONTAIN NO BACKTICK — asserted here because it
     * has broken the file THREE TIMES, twice in this harness and once in the settings one.
     *
     * The instrument is a JavaScript template literal, so a single backtick inside it terminates
     * the string early and the rest of the file becomes syntax. Every time it has happened the
     * cause was a COMMENT: prose quoting a CSS property or a filename in backticks, which is the
     * house style everywhere else in this repository and is exactly wrong here. The failure is a
     * parse error naming a random identifier from the middle of a sentence
     * (`Unexpected identifier 'cursor'`, `Unexpected identifier 'login'`), which says nothing
     * about backticks or comments — the same shape as the Blade-comment trap CLAUDE.md §3 records,
     * where none of the three 500s mentioned a comment either.
     *
     * PURE PHP, DELIBERATELY. Shelling out to `node --check` would be a stronger check and would
     * make this test depend on node being installed, which would break the declared outcome set on
     * a machine without it. The structural assertion needs nothing and catches the whole class.
     */
    #[Test]
    public function test_the_live_harnesses_in_page_instrument_has_no_backtick_in_it(): void
    {
        $path = dirname(__DIR__, 2) . '/Support/bin/check-storefront-keyboard-live.cjs';

        $this->assertFileExists($path, 'the storefront keyboard harness is missing');

        $source = (string) file_get_contents($path);
        $open = 'const INSTRUMENT = String.raw`';
        $at = strpos($source, $open);

        $this->assertNotFalse($at,
            'the harness no longer declares INSTRUMENT as a String.raw template literal, so this '
            . 'guard is looking for something that is not there and proves nothing');

        $body = substr($source, $at + strlen($open));
        $close = strpos($body, "\n`;");

        $this->assertNotFalse($close, 'the INSTRUMENT literal is not terminated as expected');

        $instrument = substr($body, 0, $close);

        $this->assertStringNotContainsString('`', $instrument, sprintf(
            'The in-page instrument contains a backtick, which terminates its template literal '
            . 'early and makes the harness a syntax error. Quote identifiers in that block without '
            . 'backticks. (%d characters scanned, so the extraction really found the block.)',
            strlen($instrument)
        ));

        $this->assertGreaterThan(2000, strlen($instrument),
            'the extracted instrument is too short to be the real one, so the assertion above '
            . 'passed against the wrong text');
    }

    /**
     * AND THE SIX CAPTURES OF A SURFACE ARE SIX DIFFERENT DOCUMENTS.
     *
     * The same premise the settings capture needed: a harness that measured one document six times
     * would report a clean sweep across three themes and two directions and prove nothing. Byte
     * equality is the cheapest way to say so and it is enough — two themes whose layouts differ at
     * all cannot produce identical bytes.
     *
     * MEASURED WHILE WRITING THIS, AND IT CHANGED THE CLAIM THIS FILE MAKES: `product-list`,
     * `collections` and `cart` are byte-IDENTICAL between stylique and greentic (68120, 57142 and
     * 56224 bytes), because those CONTENT views are not theme-specific — only the layout and a few
     * screens are. So the six captures of a surface differ by LANGUAGE always, and by theme only
     * where the theme really ships that view. The assertion is written to that measurement rather
     * than to the assumption that three themes means three documents.
     */
    #[Test]
    public function test_the_captures_of_a_surface_are_not_all_the_same_document(): void
    {
        foreach (array_keys(self::SURFACES) as $surface) {
            $byLanguage = [];
            $hashes = [];

            foreach (self::THEMES as $theme) {
                foreach (['en', 'ar'] as $lang) {
                    $html = (string) $this->get("/{$theme}-{$lang}/{$surface}")->getContent();
                    $hashes["{$theme}-{$lang}"] = md5($html);
                    $byLanguage[$lang][] = md5($html);
                }
            }

            /* The LANGUAGE split must be real for every theme: no Arabic capture may equal any
               English one. This is the half that cannot be explained by a shared content view. */
            $this->assertSame(
                [],
                array_values(array_intersect($byLanguage['en'] ?? [], $byLanguage['ar'] ?? [])),
                "a {$surface} capture is byte-identical across the two languages, so the RTL "
                . 'reading would be the LTR reading under another name'
            );

            $this->assertGreaterThanOrEqual(2, count(array_unique($hashes)), sprintf(
                'all six %s captures are one document (%s), so this surface cannot support a '
                . 'per-theme or per-direction finding at all.',
                $surface, implode(', ', array_keys($hashes))
            ));
        }
    }
}
