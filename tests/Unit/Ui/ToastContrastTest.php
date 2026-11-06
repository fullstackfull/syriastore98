<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-WAVE-30 — the admin's feedback surface, made readable in both themes.
 * ================================================================================================
 *
 * THE DEFECT, MEASURED with full alpha compositing on `/pages` -- each ratio is against the panel
 * the text actually sits on, not against a parent's declared colour, which is the distinction that
 * once turned a real 16.15:1 into a reported 1.24:1 in this phase:
 *
 *                      light            dark
 *     panel            #ffffff          #ffffff   <- `.notifier{background:#fff}` has no dark
 *                                                    counterpart, so it does not move
 *     title "Error"    21.00:1  AA      1.00:1  WHITE ON WHITE -- not faint, ABSENT
 *     body             2.85:1   FAIL    2.85:1  FAIL   (`.notifier-container{color:#999}`)
 *     close "x"        2.32:1   FAIL    2.32:1  FAIL   (`#aaa`, with `opacity:.5` on top)
 *
 * A merchant whose save fails could not read why: in dark mode the word "Error" is not rendered at
 * all, and the sentence under it fails SC 1.4.3 in BOTH themes.
 *
 * AFTER, same method, same page: title 16.88:1 light / 14.23:1 dark, body and close 6.42:1 light /
 * 6.17:1 dark. Every one AA.
 *
 * WHAT THIS TEST PINS, and why each assertion exists.
 *
 *   the plugin is untouched   `notifier.css` and `notifier.js` are the host's. The repair is an
 *                             override from a sheet that loads later, not an edit, and the hashes
 *                             say so. ToastErrorPersistenceContractTest pins the JS for a
 *                             different reason; this pins the CSS, because the override's
 *                             correctness depends on exactly what it is overriding.
 *   the gate is structural    `public/js/custom.js` is also loaded by checkout, the payment
 *                             hand-off pages and the vendored LandingPage -- surfaces Phase 2.5
 *                             does not authorise. The repair reaches none of them because
 *                             `store-syria-ds.css` does not: MEASURED, of the six layouts loading
 *                             `notifier.css`, only `layouts/app.blade.php` also loads the design
 *                             system. That is a property of two files, so two files assert it.
 *   the marker exists         `[data-ss-admin]` is what confines the rules for
 *                             DesignSystemStylesheetScopeTest. `data-ss-theme` could not serve --
 *                             MEASURED, it is set only in dark mode and reads `null` in light --
 *                             and the only other always-present attribute is a TRANSLATED LABEL.
 *   tokens, not hex           A literal colour here would be a second source of truth beside the
 *                             palette P2.5-DESIGN-F and -G re-solved, and would not follow a theme.
 *   opacity is gone           `opacity: .5` halves whatever contrast the colour achieves, so a
 *                             colour tuned around it would have to be darker than the system has.
 */
class ToastContrastTest extends TestCase
{
    private const DS_SHEET = 'public/css/store-syria-ds.css';
    private const SHELL = 'resources/views/layouts/app.blade.php';
    private const PLUGIN_CSS = 'public/assets/css/plugins/notifier.css';

    /** The host plugin stylesheet as shipped. The override below is written against THIS content. */
    private const PLUGIN_CSS_SHA256 = '1e9581445b35e4742f8a615e0fd51b8121e98a5ebdd085e86352135cefbb4b6c';

    /** Layouts that load the notifier plugin and must NOT receive this repair. */
    private const OUT_OF_SCOPE_LOADERS = [
        'resources/views/front_end/jQueryRoute.blade.php',
        'resources/views/layouts/theme_layout.blade.php',
        'packages/workdo/LandingPage/src/Resources/views/layouts/landingpage.blade.php',
    ];

    public function test_the_host_plugin_stylesheet_is_not_edited(): void
    {
        $this->assertSame(
            self::PLUGIN_CSS_SHA256,
            hash_file('sha256', $this->path(self::PLUGIN_CSS)),
            self::PLUGIN_CSS . ' changed. It is the host plugin, loaded by six layouts including '
            . 'the storefront and the vendored LandingPage. This slice overrides it from '
            . 'store-syria-ds.css, which only the merchant shell loads; editing the plugin itself '
            . 'would change checkout and the public website.'
        );
    }

    public function test_the_merchant_shell_carries_the_scope_marker_and_the_others_do_not(): void
    {
        $this->assertMatchesRegularExpression(
            '/<body[^>]*\sdata-ss-admin\b/',
            $this->read(self::SHELL),
            'layouts/app.blade.php no longer carries `data-ss-admin` on <body>, so every rule '
            . 'confined by it stops applying and the toast silently returns to 1.00:1 in dark mode.'
        );

        foreach (self::OUT_OF_SCOPE_LOADERS as $view) {
            $source = @file_get_contents($this->path($view));

            if ($source === false) {
                continue; /* a vendored layout may legitimately not exist in a slimmed checkout */
            }

            $this->assertStringNotContainsString(
                'data-ss-admin',
                $source,
                $view . ' now carries the merchant-admin marker. It loads the notifier plugin and '
                . 'is NOT the merchant admin, so this would extend a Phase 2.5 repair onto a '
                . 'storefront or public surface.'
            );
        }
    }

    /**
     * The structural gate, stated as the property it really is: the design system's stylesheet
     * reaches the merchant shell and nothing else that loads the plugin.
     */
    public function test_only_the_merchant_shell_loads_both_the_plugin_and_the_design_system(): void
    {
        $both = [];

        foreach ($this->pluginLoaders() as $view) {
            if (str_contains((string) file_get_contents($view), 'store-syria-ds.css')) {
                $both[] = str_replace($this->path('') . '/', '', $view);
            }
        }

        $this->assertSame(
            ['resources/views/layouts/app.blade.php'],
            $both,
            'A layout other than the merchant shell now loads BOTH the notifier plugin and '
            . 'store-syria-ds.css, so this repair would reach a surface Phase 2.5 does not '
            . 'authorise. Found: ' . implode(', ', $both)
        );
    }

    public function test_the_override_uses_tokens_and_drops_the_opacity(): void
    {
        $block = $this->block();

        $this->assertNotSame('', $block, 'store-syria-ds.css has no P2.5-WAVE-30 block.');

        foreach ([
            'var(--ss-surface)' => 'the panel background, so it follows the theme',
            'var(--ss-fg)' => 'the title colour',
            'var(--ss-fg-muted)' => 'the body and close colour, which inherits the token '
                . 'P2.5-DESIGN-F re-solved for AA',
        ] as $token => $why) {
            $this->assertStringContainsString($token, $block, 'The block no longer uses ' . $token . ' for ' . $why . '.');
        }

        $this->assertMatchesRegularExpression(
            '/notifier-close\s*\{[^}]*opacity:\s*1/s',
            $block,
            '`opacity: .5` on the dismiss control halves whatever contrast its colour achieves. '
            . 'Measured at 2.32:1 before the colour was even considered.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/:\s*#[0-9a-f]{3,8}\b/i',
            $block,
            'A literal colour appeared in the toast block. The palette is a token system that two '
            . 'earlier slices re-solved for contrast; a hex here is a second source of truth that '
            . 'will not follow a theme.'
        );
    }

    /**
     * The border lesson, kept because the first attempt shipped it wrong. The plugin draws its
     * type bar as PHYSICAL `border-left: 4px` with `.notifier.danger{border-left-color}`, so in
     * RTL a logical `border-inline-end` resolves onto that same edge and would paint a 1px grey
     * over a 4px status colour. The edge is a shadow ring instead, which is outside the border box.
     */
    public function test_the_panel_edge_does_not_touch_a_border_property(): void
    {
        $block = $this->block();

        $this->assertMatchesRegularExpression(
            '/\.notifier\s*\{[^}]*box-shadow:[^;]*var\(--ss-border\)/s',
            $block,
            'The panel edge is no longer a shadow ring. On the dark page the plugin\'s own '
            . '`rgba(0,0,0,.1)` shadow is black on near-black, i.e. no boundary at all.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\bborder(-(left|right|top|bottom|inline|block))?\s*:/',
            $block,
            'The toast block sets a border property. Either form is wrong here: a physical one '
            . 'paints the wrong side in Arabic, and a logical one lands on the plugin\'s own 4px '
            . 'type bar in RTL and overwrites the status colour with a grey hairline.'
        );
    }

    /** @return list<string> absolute paths of every view loading the notifier plugin */
    private function pluginLoaders(): array
    {
        $root = $this->path('');
        $out = [];

        foreach ([$root . '/resources/views', $root . '/packages'] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

            foreach ($files as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                if (str_contains((string) file_get_contents($file->getPathname()), 'plugins/notifier.css')) {
                    $out[] = $file->getPathname();
                }
            }
        }

        sort($out);

        return $out;
    }

    private function block(): string
    {
        $css = $this->read(self::DS_SHEET);
        $open = strpos($css, 'P2.5-WAVE-30: the toast a merchant cannot read');
        $end = strpos($css, 'P2.5-WAVE-29: and the summary tables', (int) $open);

        if ($open === false || $end === false || $end < $open) {
            return '';
        }

        $block = substr($css, $open, $end - $open);

        /* The slice starts INSIDE the opening comment -- it anchors on the wave marker -- so it
           begins with a dangling tail that has no `/*` to pair with. Left in, the measurement
           table above, which quotes #ffffff and #999 as the colours being REPLACED, reads as
           declarations and fails the no-hex assertion against itself. The same trap reddened
           StackedTableRowTest's generated-block comparison an hour earlier. */
        if (($close = strpos($block, '*/')) !== false) {
            $first = strpos($block, '/*');

            if ($first === false || $close < $first) {
                $block = substr($block, $close + 2);
            }
        }

        /* declarations only: four tests in this phase have passed or failed on their own prose */
        return (string) preg_replace('#/\*.*?\*/#s', '', $block);
    }

    private function path(string $relative): string
    {
        return rtrim(dirname(__DIR__, 3) . '/' . $relative, '/');
    }

    private function read(string $relative): string
    {
        $path = $this->path($relative);
        $this->assertFileExists($path, $relative . ' is missing.');

        return (string) file_get_contents($path);
    }
}
