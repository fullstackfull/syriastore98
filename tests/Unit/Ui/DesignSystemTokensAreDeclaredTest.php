<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * NOTHING CAUGHT A DANGLING `var(--ss-*)`, AND THE SAME DEFECT HAS NOW SHIPPED THREE TIMES.
 * =================================================================================================
 * A `var(--ss-name, fallback)` whose token is declared NOWHERE is silent: the fallback paints, every
 * light-mode screenshot looks right, and the theme simply never switches. The three instances in
 * this repository were all found by a human or by a browser, never by the suite:
 *
 *   1. `--ss-focus` on the storefront appearance toggle — GAP-095. Recorded in the comment at
 *      `resources/views/front_end/partials/appearance.blade.php:97-102`, which names the real token.
 *   2. `--ss-focus` AGAIN, on an admin control. The comment left at
 *      `public/css/store-syria-ds.css:4718-4726` measured it at 2.77:1 against the dark card, under
 *      WCAG 2.2 SC 1.4.11's 3:1, and ends with the sentence this file exists to answer:
 *      *"Found by review; nothing catches a dangling `var(--ss-*)`."*
 *   3. `--ss-bg-elevated` AND `--ss-focus` together, on `.ss-product-actions` — the storefront
 *      conversion actions. Measured in a real browser at **1.21:1** in dark mode, i.e. invisible,
 *      because `var(--ss-bg-elevated, #ffffff)` kept painting white while `--ss-fg` correctly
 *      flipped. The block's own comment ASSERTED that both tokens were re-declared under dark mode.
 *      One was.
 *
 * THE THIRD ONE ALSO SHOWS WHY RE-POINTING ONE TOKEN AT A TIME IS DANGEROUS, which is the argument
 * for a census over a fix: while the background was wrongly white, the hard-coded `#2563eb` ring
 * measured 5.17:1 and PASSED. Correcting the surface alone would have dropped it to 2.75:1 on
 * `#262b33` — a change that fixed one accessibility defect by introducing another. A census names
 * every dangling token at once, so they are fixed together or not at all.
 *
 * COMMENT STRIPPING IS LOAD-BEARING HERE, NOT HYGIENE. The ONLY `--ss-focus:` text anywhere in this
 * repository is inside a CSS comment — the GAP-095 note at (1), which explains the fix. A census
 * that reads raw source therefore scores `--ss-focus` as DECLARED and misses instances (2) and (3)
 * entirely. CLAUDE.md records four separate occasions when a census was satisfied or broken by prose
 * about the thing it measures; this would have been the fifth, and in the direction that hides a
 * live defect rather than the direction that annoys somebody.
 *
 * WHAT IT DOES NOT CLAIM. It checks that a token is DECLARED, not that its value is sensible, not
 * that it flips for dark mode, and not that the pair it forms with a foreground is legible. Those
 * are different questions with their own tests (`BrandTextOnDarkContrastTest`,
 * `StorefrontDarkModeLayerTest`, and the CI contrast step). This one answers only the question that
 * had no owner: does the name resolve to anything at all.
 */
final class DesignSystemTokensAreDeclaredTest extends TestCase
{
    /**
     * The shipped stylesheets, plus the Blade files that emit `<style>` blocks or `style="..."`.
     *
     * BOTH HALVES MATTER AND THAT IS MEASURED, not symmetry for its own sake: instance (1) above was
     * a USE inside a Blade `<style>` block, and `--ss-chip-share` is DECLARED in an inline `style`
     * attribute (`resources/views/components/ss/chip.blade.php`) while being used from
     * `store-syria-ds.css`. Scanning only `.css` would both miss uses and invent dangling tokens.
     */
    private const GLOBS = [
        'public/css/*.css',
        'themes/*/assets/css/*.css',
        'resources/views/front_end/partials/appearance.blade.php',
        'resources/views/components/ss/*.blade.php',
        'resources/views/marketing/partials/*.blade.php',
        // The evidence-gallery renderer emits its own CSS. A dangling token here does not
        // ship, but it makes the Phase 2.5 sign-off CAPTURES under-represent the design
        // system they are evidence for -- `--ss-text-muted` painted at 2.78:1 on the dark
        // gallery where the real token gives 7.18:1, in four committed artifacts.
        'tests/Support/DesignSystem/bin/render-ds-gallery.php',
    ];

    /**
     * DANGLING ON PURPOSE, each with the reason, because not every unresolved `var(--ss-*)` is the
     * same defect and treating them alike would either hide one or break something working.
     *
     * The defects this file was written for ASSERTED that their token existed -- `.ss-product-actions`
     * carried a comment claiming `--ss-bg-elevated` was re-declared for dark mode -- so the fallback
     * painted while the prose said otherwise. These two are the opposite shape: a deliberate
     * override HOOK whose fallback is the real, live value, documented in the comments around every
     * use site. Collapsing them to the fallback would remove a hook somebody put there on purpose;
     * "fixing" them by inventing a token would change a painted colour.
     *
     * BOTH DIRECTIONS ARE RATCHETED BELOW: a new dangling token fails until it is either fixed or
     * named here, and an entry here fails once its token is declared or its last use goes -- so a
     * stale exception cannot sit in this file asserting something about CSS that no longer exists.
     *
     * @var array<string, string>
     */
    private const DANGLING_BY_DESIGN = [
        '--ss-brand-ontext' => 'An override hook, not a broken reference. All three uses are '
            . '`color: var(--ss-brand-ontext, var(--theme-color)) !important` -- breadcrumb links, '
            . 'the light-mode header accent, and dark-mode links on a white card. `--theme-color` '
            . 'is REAL and LIVE: it is the merchant\'s own brand colour, read at runtime by '
            . '`resources/views/layouts/app.blade.php:493` via '
            . '`getComputedStyle(document.body).getPropertyValue(\'--theme-color\')`, and the '
            . 'comments at those use sites discuss its contrast lifts. So the fallback is the '
            . 'intended value and the token is a hook for overriding it later. Introducing the '
            . 'token would REPLACE a merchant\'s chosen colour with a design-system one, which is '
            . 'a product decision rather than a repair.',
        '--ss-brand-ontext-dark' => 'The dark-mode half of the same hook, same shape, same live '
            . 'fallback: dark-mode content links and inactive pagination links. The comment above '
            . 'the second use records that the lift it applies repairs a pre-existing failure to '
            . '5.15:1, so the fallback is not merely tolerated there -- it is the measured fix.',
    ];

    /** @return list<string> absolute paths */
    private function files(): array
    {
        $root = dirname(__DIR__, 3);
        $files = [];

        foreach (self::GLOBS as $glob) {
            foreach (glob($root . '/' . $glob) ?: [] as $path) {
                if (is_file($path)) {
                    $files[] = $path;
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Comments removed, newlines preserved so reported line numbers stay the ORIGINAL ones.
     *
     * `{{-- --}}` first, because a Blade comment may legally contain `/* * /` and would otherwise be
     * half-eaten. The `/* * /` pass uses a negative lookbehind on `*` so a CSS value such as
     * `accept="image/*"` does not read as a comment opener — the same guard
     * `Tests\Support\StripsSourceComments` applies, for the reason its docblock records.
     */
    private function withoutComments(string $source): string
    {
        $blank = static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n"));

        $source = preg_replace_callback('/\{\{--.*?--\}\}/s', $blank, $source) ?? $source;
        $source = preg_replace_callback('/<!--.*?-->/s', $blank, $source) ?? $source;
        $source = preg_replace_callback('~(?<!\*)/\*.*?\*/~s', $blank, $source) ?? $source;

        return $source;
    }

    /**
     * Every `--ss-name:` DECLARATION, from any of the scanned files.
     *
     * `var(...)` interiors are blanked FIRST, so a token that is only REFERENCED inside another
     * token's fallback — `var(--ss-a, var(--ss-b, #fff))` — can never be miscounted as declared.
     * That is the one way this census could produce a false green, so it is closed before anything
     * is matched rather than reasoned about afterwards.
     *
     * @return array<string, true>
     */
    private function declared(): array
    {
        $declared = [];

        foreach ($this->files() as $path) {
            $code = $this->withoutComments((string) file_get_contents($path));
            $code = preg_replace('/var\(\s*--[^)]*\)/', 'var()', $code) ?? $code;

            preg_match_all('/(--ss-[A-Za-z0-9_-]+)\s*:/', $code, $m);

            foreach ($m[1] as $token) {
                $declared[$token] = true;
            }
        }

        return $declared;
    }

    /** @return array<string, list<string>> token => "repo/relative/path:line" */
    private function used(): array
    {
        $root = dirname(__DIR__, 3);
        $used = [];

        foreach ($this->files() as $path) {
            $relative = substr($path, strlen($root) + 1);
            $lines = explode("\n", $this->withoutComments((string) file_get_contents($path)));

            foreach ($lines as $i => $line) {
                preg_match_all('/var\(\s*(--ss-[A-Za-z0-9_-]+)/', $line, $m);

                foreach ($m[1] as $token) {
                    $used[$token][] = $relative . ':' . ($i + 1);
                }
            }
        }

        ksort($used);

        return $used;
    }

    /** Guards every assertion below against a silently empty scan. */
    public function test_the_premise_that_the_scan_reads_the_design_system_at_all(): void
    {
        $this->assertGreaterThan(5, count($this->files()),
            'the globs match almost nothing, so the census below would be vacuous');

        $used = $this->used();
        $declared = $this->declared();

        $this->assertGreaterThan(100, count($used),
            'only ' . count($used) . ' distinct tokens are USED, so the parse has gone stale');
        $this->assertGreaterThan(100, count($declared),
            'only ' . count($declared) . ' distinct tokens are DECLARED, so the parse has gone stale');

        $this->assertArrayHasKey('--ss-surface', $declared,
            'the surface token is not being read as declared, so the scan is wrong about the one '
            . 'token the live defect was fixed with');
    }

    /**
     * THE PREMISE THAT STRIPPING CHANGES THE ANSWER, so nobody removes it as tidying.
     *
     * Without stripping, `--ss-focus` reads as DECLARED — from the GAP-095 comment that explains
     * why it must not be used. This asserts the comment really is the only occurrence, so if the
     * stripping pass were dropped this file would start passing for the wrong reason.
     */
    public function test_the_only_declaration_shaped_focus_text_is_a_comment(): void
    {
        $root = dirname(__DIR__, 3);
        $raw = 0;
        $stripped = 0;

        foreach ($this->files() as $path) {
            $source = (string) file_get_contents($path);
            $raw += preg_match_all('/--ss-focus\s*:/', $source);
            $stripped += preg_match_all('/--ss-focus\s*:/', $this->withoutComments($source));
        }

        $this->assertGreaterThan(0, $raw,
            'the `--ss-focus:` comment text has gone; if it was deleted deliberately this premise '
            . 'can go with it, but then say so rather than leaving the assertion inverted');
        $this->assertSame(0, $stripped, sprintf(
            '`--ss-focus` now appears as a real declaration in %d place(s). If it has genuinely been '
            . 'adopted as a token, this premise is obsolete; until then the only occurrence must be '
            . 'the comment recording GAP-095, and a census that reads it as a declaration misses '
            . 'every live use of the token.',
            $stripped
        ));
    }

    /** THE RATCHET. Every `var(--ss-*)` resolves to a token something actually declares. */
    public function test_every_referenced_design_system_token_is_declared_somewhere(): void
    {
        $declared = $this->declared();
        $dangling = [];

        $stillDangling = [];

        foreach ($this->used() as $token => $sites) {
            if (isset($declared[$token])) {
                continue;
            }

            if (array_key_exists($token, self::DANGLING_BY_DESIGN)) {
                $stillDangling[] = $token;

                continue;
            }

            $dangling[] = sprintf('%s  (used at %s)', $token, implode(', ', array_unique($sites)));
        }

        $stale = array_values(array_diff(array_keys(self::DANGLING_BY_DESIGN), $stillDangling));

        $this->assertSame([], $stale, sprintf(
            "%d exception(s) in DANGLING_BY_DESIGN no longer describe anything: the token is now "
            . "declared, or its last use is gone. Delete the entry -- an exception that outlives "
            . "its subject is how a census starts asserting history:\n  %s",
            count($stale),
            implode("\n  ", $stale)
        ));

        sort($dangling);

        $this->assertSame([], $dangling, sprintf(
            "%d design-system token(s) are referenced through `var()` and declared NOWHERE, so the "
            . "hard-coded fallback paints in every theme and the token never switches. This has "
            . "shipped three times, twice as a focus ring under WCAG 1.4.11's 3:1 minimum and once "
            . "as a storefront surface at 1.21:1 in dark mode:\n  %s\n\n"
            . "Re-point each one at the token that exists -- the surface family is `--ss-surface` / "
            . "`--ss-surface-hover` / `--ss-surface-sunken`, the focus trio is `--ss-focus-ring` / "
            . "`--ss-focus-width` / `--ss-focus-offset`, type sizes are `--ss-text-*` -- and check "
            . "the PAIR afterwards, because a dangling token can be masking another one: correcting "
            . "`.ss-product-actions`'s background alone would have dropped its ring from 5.17:1 to "
            . "2.75:1.",
            count($dangling),
            implode("\n  ", $dangling)
        ));
    }
}
