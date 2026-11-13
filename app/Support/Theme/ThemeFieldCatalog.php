<?php

namespace App\Support\Theme;

use Illuminate\Support\Facades\File;

/**
 * PHASE 03 M3 — THE THEME FIELD CATALOG: WHAT A STOREFRONT SETTING IS ALLOWED TO BE.
 * ================================================================================================
 *
 * WHY THIS IS DERIVED AND NOT DECLARED. Each theme already ships its own field taxonomy as data,
 * in `themes/<theme>/theme_json/`. Re-declaring it in PHP, or as columns, would create a second
 * source of truth that drifts the first time a theme adds a field. Measured on 2026-11-13: 956
 * addressable settings across the three shipped themes (307 stylique, 321 techzonix, 328 greentic),
 * 379 distinct names, in SEVEN value types. Adding a field to a theme must stay a JSON edit.
 *
 * THE NAME COMPOSITION RULE IS READ FROM THE RENDERER, NOT GUESSED. A stored setting's name is
 * `{section.key}_{setting.key}` — that is literally what the form emits,
 * `resources/views/theme_customize/customize.blade.php:119,130,187,204`:
 *
 *     name="{{ $json_setting['key'] . '_' . $fields['key'] }}"
 *
 * So `footer` + `description` is `footer_description`, which is the name the storefront reads and
 * the name stored in `theme_customizes`. Any other composition would silently produce an allowlist
 * that matches nothing, and a validator built on it would reject every legitimate save — which is
 * why `ClaudeConfigurationTest`-style structural pinning is not enough here and
 * `tests/Unit/Theme/ThemeFieldCatalogTest.php` asserts the rule against the renderer itself.
 *
 * THREE KEY SPACES, NOT ONE. `default_data/settings.php` and `theme_json/` do not describe the same
 * set, and conflating them was the trap this class exists to avoid (measured per theme):
 *
 *               defaults   theme_json   both   editable-only   defaulted-not-editable   of those DEAD
 *   stylique       511        307        295         12                 216                  150
 *   techzonix      523        321        318          3                 205                  150
 *   greentic       521        328        324          4                 197                  156
 *
 * The "dead" column is keys with a default that NO Blade view reads and NO theme_json exposes —
 * an older naming scheme. `default_data` carries `abouts_us_description`, while the current
 * `theme_json` declares section `abouts_us_full` with setting `description`, so the live name is
 * `abouts_us_full_description` and the bare one is neither read nor writable.
 *
 * WHAT USES THIS. `editableFields()` is the write allowlist: a save may only touch a name this
 * returns, which is the control the Phase 0 audit asked for (`docs/discovery/03-FEATURE-MATRIX.md`
 * line 223, *Theme settings write authorization*, HIGH/P0 — the permission check is commented out
 * AND there is no allowlist). `readableKeys()` is the wider space a published snapshot may carry,
 * because the storefront legitimately reads ~200 keys per theme that the editor does not offer.
 *
 * This class performs NO authorization and NO tenancy check. It answers "is this a real field of
 * this theme, and of what type" and nothing else. The caller still has to establish who is asking
 * and which store they may write to.
 */
class ThemeFieldCatalog
{
    /** Cached per theme for the life of the process; the JSON cannot change mid-request. */
    private static array $memo = [];

    /**
     * The value types the shipped themes actually use. Measured rather than taken from the
     * renderer, which handles more types than any theme declares.
     *
     * `slider` is the surprise: it is a REPEATER whose stored value is a JSON *string* with its own
     * declared `fields` list, not a numeric slider. It is the one type whose value must round-trip
     * byte-exactly, because the storefront `json_decode()`s it at render time and a re-encode that
     * reorders keys or changes escaping is a behaviour change even when the data is equivalent.
     */
    public const KNOWN_TYPES = ['text', 'switch', 'image', 'textarea', 'slider', 'menu', 'date'];

    /**
     * Types whose value is free text the merchant types, and therefore the only types that reach
     * the storefront's unescaped `{!! !!}` outputs.
     *
     * MEASURED 2026-11-13 over 150 Blade files: 55 unescaped output sites naming 43 distinct
     * settings, and every one of those 43 is declared `text` (26) or `textarea` (17). No `image`,
     * `switch`, `slider`, `menu` or `date` value is ever emitted unescaped. That is why a
     * type-aware write rule reaches the whole persistent-output surface at its source, instead of
     * editing 55 Blade sites.
     */
    public const FREE_TEXT_TYPES = ['text', 'textarea'];

    /**
     * Every editable setting of a theme, as `name => type`.
     *
     * Returns an empty array for an unknown theme or one with no `theme_json` — deliberately, so a
     * caller that validates against it FAILS CLOSED and accepts nothing, rather than falling back
     * to "allow everything" the way an `empty()` check would.
     */
    public static function editableFields(string $theme): array
    {
        if (array_key_exists($theme, self::$memo)) {
            return self::$memo[$theme];
        }

        $dir = base_path('themes/' . $theme . '/theme_json');

        if ($theme === '' || ! is_dir($dir)) {
            return self::$memo[$theme] = [];
        }

        $fields = [];

        foreach (File::glob($dir . '/*.json') as $path) {
            if (basename($path) === 'pages.json') {
                continue;       // the page index, not a field declaration
            }

            $decoded = json_decode((string) File::get($path), true);

            if (! is_array($decoded)) {
                continue;       // an unparsable theme file must not take the whole catalog down
            }

            foreach (self::sections($decoded) as $section) {
                $sectionKey = (string) ($section['key'] ?? $section['slug'] ?? '');

                foreach ($section['settings'] as $setting) {
                    if (! is_array($setting)) {
                        continue;
                    }

                    $key  = $setting['key'] ?? null;
                    $type = $setting['type'] ?? null;

                    if (! is_string($key) || $key === '' || ! is_string($type) || $type === '') {
                        continue;
                    }

                    // The renderer's own rule. See the class docblock.
                    $name = $sectionKey === '' ? $key : $sectionKey . '_' . $key;

                    $fields[$name] = $type;
                }
            }
        }

        ksort($fields);

        return self::$memo[$theme] = $fields;
    }

    /**
     * Walk any page JSON for section nodes.
     *
     * Three of the 93 page files are a top-level LIST rather than an object, so a walker that
     * assumed `['sections']` found nothing in them and silently undercounted. Measured: 90 dict,
     * 3 list.
     */
    private static function sections(array $node): \Generator
    {
        if (isset($node['settings']) && is_array($node['settings'])
            && (array_key_exists('key', $node) || array_key_exists('slug', $node))) {
            yield $node;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                yield from self::sections($value);
            }
        }
    }

    /** Is this a field the merchant's editor offers for this theme? */
    public static function isEditable(string $theme, string $name): bool
    {
        return array_key_exists($name, self::editableFields($theme));
    }

    /** The declared type of one field, or null when the theme does not declare it. */
    public static function typeOf(string $theme, string $name): ?string
    {
        return self::editableFields($theme)[$name] ?? null;
    }

    /**
     * The wider key space the storefront may legitimately READ for a theme: its editable fields
     * plus everything its `default_data/settings.php` declares.
     *
     * A published snapshot is allowed to carry any of these, because ~200 keys per theme have a
     * default and are read by views while not being offered by the editor. Only `editableFields()`
     * governs what a SAVE may touch.
     */
    public static function readableKeys(string $theme): array
    {
        $defaults = [];
        $path     = base_path('themes/' . $theme . '/default_data/settings.php');

        if ($theme !== '' && is_file($path)) {
            $loaded = include $path;

            if (is_array($loaded)) {
                $defaults = array_keys($loaded);
            }
        }

        $keys = array_values(array_unique(array_merge(
            array_keys(self::editableFields($theme)),
            $defaults
        )));

        sort($keys);

        return $keys;
    }

    /** The themes that ship a field taxonomy, in a stable order. */
    public static function themes(): array
    {
        $out = [];

        foreach ((array) File::directories(base_path('themes')) as $dir) {
            if (is_dir($dir . '/theme_json')) {
                $out[] = basename($dir);
            }
        }

        sort($out);

        return $out;
    }

    /** Only for tests that need a cold read after writing a fixture theme. */
    public static function forget(): void
    {
        self::$memo = [];
    }
}
