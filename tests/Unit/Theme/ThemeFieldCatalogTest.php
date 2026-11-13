<?php

namespace Tests\Unit\Theme;

use App\Support\Theme\ThemeFieldCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M3-A — THE FIELD CATALOG IS THE INVENTORY, SO THE INVENTORY IS EXECUTABLE.
 *
 * M3-A's central claim is that the write allowlist the Phase 0 audit asked for already exists as
 * data and does not need to be re-declared. A document asserting that is worth little; this asserts
 * it against the themes and against the renderer.
 *
 * THE FAILURE THIS GUARDS IS SILENT. If the name-composition rule is wrong, the catalog returns an
 * allowlist that matches nothing a theme actually stores. A validator built on it would then reject
 * every legitimate save — or, worse, a caller written as `if ($allow && ! isset($allow[$name]))`
 * would treat the empty allowlist as "no opinion" and accept everything, which is the exact hole
 * the audit recorded. Neither shows up as a crash.
 */
class ThemeFieldCatalogTest extends TestCase
{
    /* ====================================================================== the composition rule */

    /**
     * The rule is not this test's invention and not the catalog's: it is what the merchant's form
     * emits. If the renderer ever changes how it builds the field name, the catalog is wrong the
     * same day, and this is what says so.
     */
    #[Test]
    public function test_the_name_rule_matches_what_the_renderer_actually_emits(): void
    {
        $renderer = base_path('resources/views/theme_customize/customize.blade.php');
        $this->assertFileExists($renderer, 'the theme-customize form is gone; the catalog has no '
            . 'renderer left to agree with and this whole file is vacuous.');

        $source = (string) file_get_contents($renderer);

        // Comments stripped: a rule quoted in a docblock is not the rule being applied. Prose
        // matching its own description has produced five false readings in this project.
        $code = preg_replace('{\{\{--.*?--\}\}}s', '', $source);

        $this->assertMatchesRegularExpression(
            "~\\\$json_setting\\['key'\\]\s*\.\s*'_'\s*\.\s*\\\$fields\\['key'\\]"
            . "|\\{\\{\s*\\\$json_setting\\['key'\\]\s*\\}\\}_\\{\\{\s*\\\$fields\\['key'\\]~",
            $code,
            'The form no longer composes a field name as {section key}_{setting key}. '
            . 'App\\Support\\Theme\\ThemeFieldCatalog derives the whole write allowlist from that '
            . 'rule, so it is now producing names that match nothing stored.');
    }

    /* ============================================================== the taxonomy, measured counts */

    public static function themeProvider(): array
    {
        return [['stylique'], ['techzonix'], ['greentic']];
    }

    /**
     * A floor, not an exact count: a theme may legitimately gain fields, and pinning the exact
     * number would turn every new field into a failing test. The floor catches the thing that
     * actually goes wrong — the catalog silently returning nothing or almost nothing.
     *
     * Measured 2026-11-13: stylique 307, techzonix 321, greentic 328.
     */
    #[Test]
    #[DataProvider('themeProvider')]
    public function test_each_theme_declares_a_substantial_field_set(string $theme): void
    {
        $fields = ThemeFieldCatalog::editableFields($theme);

        $this->assertGreaterThan(250, count($fields),
            "themes/{$theme} resolved to only " . count($fields) . ' editable fields. Measured at '
            . 'M3-A: stylique 307, techzonix 321, greentic 328. A collapse to near-zero means the '
            . 'theme_json shape changed or the name-composition rule stopped matching.');

        foreach ($fields as $name => $type) {
            $this->assertIsString($name);
            $this->assertNotSame('', $name, "themes/{$theme} declares a field with an empty name.");
            $this->assertContains($type, ThemeFieldCatalog::KNOWN_TYPES,
                "themes/{$theme} field '{$name}' declares type '{$type}', which is not in "
                . 'ThemeFieldCatalog::KNOWN_TYPES. A new type needs a deliberate decision about how '
                . 'its value is validated before it can be written — especially whether it reaches '
                . 'an unescaped output.');
        }
    }

    /**
     * The catalog must agree with the themes it is derived from on the ONE thing M3 depends on:
     * that the names it produces are the names the storefront actually reads.
     */
    #[Test]
    public function test_the_catalog_covers_the_keys_the_storefront_reads(): void
    {
        $read = $this->keysTheViewsRead();

        $this->assertGreaterThan(300, count($read),
            'Only ' . count($read) . ' setting keys were found in the storefront views, so this '
            . 'assertion covers almost nothing. Measured at M3-A: 368.');

        $allowed = [];
        foreach (ThemeFieldCatalog::themes() as $theme) {
            $allowed += ThemeFieldCatalog::editableFields($theme);
        }

        $covered = count(array_intersect($read, array_keys($allowed)));
        $ratio   = $covered / count($read);

        $this->assertGreaterThan(0.9, $ratio,
            sprintf('Only %d of %d (%.0f%%) keys the storefront reads appear in the derived '
                . "allowlist. Measured at M3-A: 360 of 368. A drop means the composition rule no "
                . 'longer matches the stored names, which would make the allowlist useless.',
                $covered, count($read), $ratio * 100));
    }

    /* ====================================================== fail closed, not open, on the unknown */

    /**
     * THE MOST IMPORTANT ASSERTION HERE. An unknown or themeless store must resolve to an EMPTY
     * allowlist, and a caller must therefore accept nothing. The danger is a caller written as
     * `if ($allow && ! isset($allow[$name]))`, where an empty allowlist means "no opinion" and
     * every key is accepted — reinstating exactly the hole the Phase 0 audit recorded.
     *
     * A store with `theme_id = ''` is not hypothetical: `stores.theme_id` is NOT NULL, so a store
     * that has chosen no theme carries the empty string, and M0 measured eleven routes answering
     * 500 because of it.
     */
    #[Test]
    #[DataProvider('unknownThemeProvider')]
    public function test_an_unknown_theme_yields_an_empty_allowlist(string $theme, string $why): void
    {
        $this->assertSame([], ThemeFieldCatalog::editableFields($theme), $why);
        $this->assertFalse(ThemeFieldCatalog::isEditable($theme, 'site_title'),
            "isEditable() said a field of an unknown theme ({$theme}) is editable.");
        $this->assertNull(ThemeFieldCatalog::typeOf($theme, 'site_title'));
    }

    public static function unknownThemeProvider(): array
    {
        return [
            'empty string (a store that chose no theme)' => ['',
                'A store with theme_id = "" must allow NOTHING. stores.theme_id is NOT NULL, so '
                . 'this is the real state of a themeless store, not a hypothetical.'],
            'a theme that does not exist' => ['no-such-theme-' . __LINE__,
                'An unknown theme must allow nothing rather than fall through to a permissive '
                . 'default.'],
            'a traversal attempt' => ['../../etc',
                'A theme name is used to build a path. It must not resolve outside themes/.'],
        ];
    }

    /** The traversal case deserves its own assertion about the path, not just the result. */
    #[Test]
    public function test_a_theme_name_cannot_escape_the_themes_directory(): void
    {
        foreach (['../../app', '..', '../resources', 'stylique/../../app'] as $evil) {
            $this->assertSame([], ThemeFieldCatalog::editableFields($evil),
                "editableFields('{$evil}') returned fields, so a theme name reached outside "
                . 'themes/. The name comes from stores.theme_id, which merchant-facing code '
                . 'writes — makeActiveTheme() stores $request->theme_id with no validation.');
        }
    }

    /* ============================================================ the free-text / unescaped pairing */

    /**
     * The claim M3's write validation rests on: every setting the storefront emits UNESCAPED is a
     * declared free-text field. If a future theme emits an `image` or a repeater unescaped, the
     * type-aware rule stops covering the whole surface and the gap is invisible.
     */
    #[Test]
    public function test_every_unescaped_setting_is_a_declared_free_text_field(): void
    {
        $unescaped = $this->keysEmittedUnescaped();

        $this->assertGreaterThan(20, count($unescaped),
            'Only ' . count($unescaped) . ' unescaped setting outputs were found, so this '
            . 'assertion covers almost nothing. Measured at M3-A: 43 distinct keys over 55 sites.');

        $offenders = [];

        foreach ($unescaped as $name) {
            $type = null;
            foreach (ThemeFieldCatalog::themes() as $theme) {
                $type = ThemeFieldCatalog::typeOf($theme, $name) ?? $type;
            }

            if ($type === null) {
                $offenders[] = $name . ' (declared by no theme)';
            } elseif (! in_array($type, ThemeFieldCatalog::FREE_TEXT_TYPES, true)) {
                $offenders[] = $name . ' (type ' . $type . ')';
            }
        }

        sort($offenders);

        $this->assertSame([], $offenders,
            "A storefront view emits a setting unescaped that is not a declared free-text field.\n  "
            . implode("\n  ", $offenders)
            . "\nM3's write validation is type-aware: it constrains `text` and `textarea` because "
            . 'those were the only types ever emitted unescaped. A value of another type reaching '
            . 'an unescaped output is outside that cover.');
    }

    /* ===================================================================== the wider read key space */

    #[Test]
    #[DataProvider('themeProvider')]
    public function test_the_readable_space_is_wider_than_the_editable_one(string $theme): void
    {
        $editable = array_keys(ThemeFieldCatalog::editableFields($theme));
        $readable = ThemeFieldCatalog::readableKeys($theme);

        $this->assertGreaterThan(count($editable), count($readable),
            "themes/{$theme}: readableKeys() is not wider than editableFields(). Measured at M3-A, "
            . 'each theme has ~200 keys with a default that the editor does not offer, and the '
            . 'storefront reads 41-66 of them. A published snapshot may carry those; a SAVE may not '
            . 'touch them.');

        $this->assertSame([], array_diff($editable, $readable),
            "themes/{$theme}: an editable field is missing from the readable space, so a legitimate "
            . 'save could produce a key a snapshot is not allowed to carry.');
    }

    /* ================================================================================== helpers */

    /** Every `$themeSettings['x']` key any storefront view reads. */
    private function keysTheViewsRead(): array
    {
        $keys = [];

        foreach ($this->storefrontViews() as $source) {
            if (preg_match_all('~\$themeSettings\[\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*\]~', $source, $m)) {
                foreach ($m[1] as $k) {
                    $keys[$k] = true;
                }
            }
        }

        return array_keys($keys);
    }

    /** Every setting key that appears inside a `{!! !!}` block. */
    private function keysEmittedUnescaped(): array
    {
        $keys = [];

        foreach ($this->storefrontViews() as $source) {
            if (! preg_match_all('~\{!!(.*?)!!\}~s', $source, $blocks)) {
                continue;
            }

            foreach ($blocks[1] as $block) {
                if (preg_match_all('~\$themeSettings\[\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*\]~', $block, $m)) {
                    foreach ($m[1] as $k) {
                        $keys[$k] = true;
                    }
                }
            }
        }

        $out = array_keys($keys);
        sort($out);

        return $out;
    }

    /** The three themes' views plus the shared storefront fallback. */
    private function storefrontViews(): array
    {
        $out  = [];
        $root = dirname(__DIR__, 3);

        $dirs = ['resources/views/front_end', 'resources/views/components'];
        foreach (ThemeFieldCatalog::themes() as $theme) {
            $dirs[] = 'themes/' . $theme . '/views';
        }

        foreach ($dirs as $dir) {
            $base = $root . '/' . $dir;

            if (! is_dir($base)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));

            foreach ($it as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                    $out[] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        $this->assertGreaterThan(100, count($out),
            'Only ' . count($out) . ' storefront Blade files were found, so the scans above cover '
            . 'almost nothing. Measured at M3-A: 150.');

        return $out;
    }
}
