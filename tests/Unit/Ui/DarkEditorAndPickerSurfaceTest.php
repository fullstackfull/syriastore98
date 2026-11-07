<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-WAVE-33 — THE RICH-TEXT EDITOR AND THE TAG PICKER IN DARK MODE.
 * ================================================================================================
 *
 * A merchant could not read what they were typing. Measured on /product/create at 1440x1100 with
 * full alpha compositing, which is the only instrument that could see it: the editor's own
 * surfaces are TRANSPARENT, so a search for light backgrounds finds nothing and the near-black
 * ink simply lands on the dark page.
 *
 *     part                          before                       after
 *     .note-editable   427x200      #293240 on #22242c  1.20:1   #e8eaed on #262b33  11.81:1
 *     .note-editor.note-frame       #293240 on #22242c  1.20:1   #e8eaed on #262b33  11.81:1
 *     .note-toolbar    427x80       #333333 on #22242c  1.23:1   #e8eaed on #1f232a  13.08:1
 *     .note-statusbar               #293240 on #2c2e35  1.05:1   #e8eaed on #30343b  10.33:1
 *     .note-btn        54x32        #ffffff fill, floating       #e8eaed on #262b33  11.81:1
 *     .select2-selection--multiple  #ffffff fill on a dark form  #e8eaed on #262b33  11.81:1
 *     .select2-dropdown (opened)    #293240 on #262b33  1.10:1   #e8eaed on #262b33  11.81:1
 *     .select2-...__placeholder     #999999 on #ffffff  2.85:1   #5b5f66 on #ffffff   6.42:1
 *
 * LIGHT MODE IS BYTE-IDENTICAL AFTERWARDS except the placeholder, which was an AA failure in the
 * DEFAULT theme: 12.37, 12.09, 12.37, 12.63, 10.99, 12.93 and 21.00 before and after.
 *
 * WHAT THIS TEST PINS, AND WHY EACH ASSERTION IS HERE.
 *
 *   the scope      every selector carries `[data-ss-theme="dark"]` or `[data-ss-admin]`. The first
 *                  keeps light mode out; the second is emitted only by `layouts/app.blade.php`.
 *                  Without this the rules would reach the storefront's summernote and select2 --
 *                  Phase 03 surfaces -- if this sheet were ever linked there.
 *   tokens only    no literal colour in the block. The ratios below are properties of the token
 *                  values, so a hex here would silently escape them.
 *   the ratios     computed from the token values PARSED OUT OF THE SHEET, not from this comment.
 *   no statusbar   `.note-statusbar` must NOT appear. It did, and the rule was DEAD: summernote's
 *                  own `.note-editor.note-frame .note-statusbar` is also (0,3,0) and its sheet is
 *                  index 17 against this file's 15, so it wins on source order. What it paints is
 *                  `rgba(128,128,128,0.11)`, which composites correctly over either theme. A rule
 *                  that cannot win is worse than no rule, because the next reader believes it.
 */
class DarkEditorAndPickerSurfaceTest extends TestCase
{
    private const OPEN = '/* ---- P2.5-WAVE-33: THE RICH-TEXT EDITOR AND THE TAG PICKER IN DARK MODE';
    private const CLOSE = '/* end P2.5-WAVE-33 */';

    private static function sheet(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/public/css/store-syria-ds.css');
    }

    /** The block's source, with its comments removed so only live declarations remain. */
    private static function block(): string
    {
        $css = self::sheet();
        $from = strpos($css, self::OPEN);
        $to = strpos($css, self::CLOSE);
        if ($from === false || $to === false) {
            return '';
        }
        $slice = substr($css, $from, $to - $from);

        // Strip comments. A slice that starts INSIDE one leaves a dangling `*/`, which has broken
        // two tests in this phase, so the leading tail is dropped first.
        $slice = preg_replace('#^.*?\*/#s', '', $slice) ?? $slice;

        return preg_replace('#/\*.*?\*/#s', '', $slice) ?? $slice;
    }

    /** @return array<string> every selector in the block */
    private static function selectors(): array
    {
        $out = [];
        foreach (explode('}', self::block()) as $rule) {
            $head = explode('{', $rule)[0] ?? '';
            foreach (explode(',', $head) as $sel) {
                $sel = trim($sel);
                if ($sel !== '') {
                    $out[] = $sel;
                }
            }
        }

        return $out;
    }

    private static function token(string $name, bool $dark): string
    {
        $css = self::sheet();
        // The dark values live in the second declaration of each token; the light ones alias
        // primitives. Both are read from the sheet so this test cannot drift from it.
        preg_match_all('/--' . preg_quote($name, '/') . ':\s*([^;]+);/', $css, $m);
        $vals = array_map('trim', $m[1] ?? []);
        $hexes = array_values(array_filter($vals, fn ($v) => (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $v)));
        $aliases = array_values(array_filter($vals, fn ($v) => str_starts_with($v, 'var(')));

        if ($dark) {
            return $hexes[0] ?? '';
        }
        if (! $aliases) {
            return $hexes[0] ?? '';
        }
        preg_match('/var\(\s*(--[a-z0-9-]+)\s*\)/', $aliases[0], $a);
        preg_match('/' . preg_quote($a[1] ?? '--nope', '/') . ':\s*(#[0-9a-fA-F]{6})\s*;/', $css, $p);

        return $p[1] ?? '';
    }

    private static function ratio(string $fg, string $bg): float
    {
        $lum = static function (string $hex): float {
            $hex = ltrim($hex, '#');
            $c = [];
            foreach ([0, 2, 4] as $i) {
                $v = hexdec(substr($hex, $i, 2)) / 255;
                $c[] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            }

            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        $a = $lum($fg);
        $b = $lum($bg);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    public function test_the_block_is_present_and_was_really_parsed(): void
    {
        $this->assertNotSame('', self::block(), 'the WAVE-33 block is missing or its markers moved');
        $this->assertGreaterThanOrEqual(20, count(self::selectors()),
            'the selector scan found almost nothing, so it is broken rather than the block being small');
    }

    public function test_every_selector_is_scoped_to_the_admin_or_to_the_dark_theme(): void
    {
        $loose = array_values(array_filter(
            self::selectors(),
            fn ($s) => ! str_contains($s, '[data-ss-theme="dark"]') && ! str_contains($s, '[data-ss-admin]')
        ));

        $this->assertSame([], $loose,
            "An unscoped selector here would repaint summernote and select2 in light mode and on any\n"
            . "other layout that links this sheet.\n" . implode("\n", $loose));
    }

    public function test_the_block_names_no_literal_colour(): void
    {
        $block = self::block();
        preg_match_all('/#[0-9a-fA-F]{3,8}\b|\brgba?\(/', $block, $m);

        $this->assertSame([], $m[0],
            'Every colour here must be a token, because the ratios this test computes are properties'
            . " of the token values.\n" . implode(' ', $m[0]));
    }

    /**
     * ANTI-VACUITY, and the assertion that actually protects the merchant: the token PAIRS this
     * block creates have to clear AA. Values are parsed out of the sheet, so retuning a token
     * below AA reddens here rather than silently landing on the editor.
     */
    public function test_every_pair_the_block_creates_clears_aa(): void
    {
        $darkFg = self::token('ss-fg', true);
        $darkMuted = self::token('ss-fg-muted', true);
        $darkSurface = self::token('ss-surface', true);
        $darkSunken = self::token('ss-surface-sunken', true);
        $darkHover = self::token('ss-surface-hover', true);
        $lightMuted = self::token('ss-fg-muted', false);

        foreach ([$darkFg, $darkMuted, $darkSurface, $darkSunken, $darkHover, $lightMuted,
            self::token('ss-grey-900', false), self::token('ss-grey-0', false), self::token('ss-grey-100', false)] as $v) {
            $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $v, 'a token value failed to parse out of the sheet');
        }

        $pairs = [
            'editor frame / editable / button: --ss-fg on --ss-surface' => [$darkFg, $darkSurface],
            'toolbar: --ss-fg on --ss-surface-sunken' => [$darkFg, $darkSunken],
            'button hover, highlighted option: --ss-fg on --ss-surface-hover' => [$darkFg, $darkHover],
            'placeholder, dark: --ss-fg-muted on --ss-surface' => [$darkMuted, $darkSurface],
            'placeholder, light: --ss-fg-muted on white' => [$lightMuted, '#ffffff'],
            // The modal exemption's own pairs. It restores LAYER-1 PRIMITIVES on the white sheet
            // `.modal-content` keeps in dark mode under P2.5-A-01, so they need covering here too
            // or the exemption is the one part of this block with no ratio behind it.
            'modal exemption: --ss-grey-900 on --ss-grey-0' => [self::token('ss-grey-900', false), self::token('ss-grey-0', false)],
            'modal exemption toolbar: --ss-grey-900 on --ss-grey-100' => [self::token('ss-grey-900', false), self::token('ss-grey-100', false)],
        ];

        foreach ($pairs as $what => [$fg, $bg]) {
            $this->assertGreaterThanOrEqual(4.5, round(self::ratio($fg, $bg), 2),
                "$what measures " . round(self::ratio($fg, $bg), 2) . ':1');
        }
    }

    public function test_the_dead_statusbar_rule_is_not_reintroduced(): void
    {
        $this->assertStringNotContainsString('.note-statusbar', self::block(),
            'summernote wins that selector on source order at equal specificity; the rule cannot apply. '
            . 'Its own rgba(128,128,128,0.11) composites correctly over the repaired frame (10.33:1 measured).');
    }
}
