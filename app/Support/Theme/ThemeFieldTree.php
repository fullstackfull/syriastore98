<?php

namespace App\Support\Theme;

use Illuminate\Support\Facades\File;

/**
 * PHASE 03 M4-3 — THE SHAPE A VISUAL EDITOR NEEDS, WHICH THE CATALOGUE DELIBERATELY THROWS AWAY.
 * =================================================================================================
 *
 * `ThemeFieldCatalog::editableFields()` answers the VALIDATOR's question -- "is this name editable,
 * and of what type" -- and returns a flat `name => type` map. To do that it composes the section
 * key into the name (`:160`) and discards everything else: the label the merchant reads, the page
 * the field lives on, the section it groups under, the placeholder, the declared default, the row
 * count of a textarea, and the sub-field list of a repeater. A grouped editor cannot be built from
 * that map, and `editableFields()`'s shape must not change, because `ThemeSettingValidator` depends
 * on it at `:122` and `:170`.
 *
 * So this is a second reader over the same files, for the other question, and the two are pinned to
 * each other: `ThemeFieldTreeTest` asserts the tree's name set is EXACTLY `editableFields()`'s. If
 * they ever drift, a field is editable and invisible, or visible and unsaveable.
 *
 * -------------------------------------------------------------------------------------------------
 * FOUR THINGS A NAIVE READER GETS WRONG, each measured on this tree 2026-11-15:
 *
 *   1. `pages.json` IS AN INCOMPLETE INDEX. All three themes ship `homepage.json` and
 *      `terms-condition.json` and list neither. An editor whose navigation comes from `pages.json`
 *      silently hides real pages, so the tree is built from the DIRECTORY and `pages.json` supplies
 *      titles and order where it has them.
 *
 *   2. `homepage.json` IS A SECOND, INCOMPATIBLE SCHEMA and contributes ZERO fields. It spells its
 *      nodes `section_name`/`field_name` and its types `photo upload` and `text area`, none of
 *      which the walker recognises. MEASURED: stylique 307 fields, techzonix 321, greentic 328 --
 *      and `homepage.json` contributes **0** to each. It is NOT dead: `Product.php:586` and
 *      `ApiController.php:5920` read it at runtime. It is reported here as an unreadable page
 *      rather than silently skipped, because "the editor shows nothing for this page" and "this
 *      page has no fields" are different facts.
 *
 *   3. DUPLICATE NAMES EXIST AND LAST-WINS SILENTLY. `editableFields()` assigns into
 *      `$fields[$name]`, so a repeated name collapses with no trace. MEASURED: exactly two per
 *      theme, of two different kinds --
 *
 *          stylique   blog_status            blog.json + home.json        (cross-file)
 *          techzonix  article_status         article.json + home.json     (cross-file)
 *          greentic   article_status         article.json + home.json     (cross-file)
 *          all three  track_cancel_title     track-order.json, twice      (within one file)
 *
 *      A grouped editor renders the duplicate in TWO places, and whichever the merchant edits last
 *      wins -- the same invisible-write defect M3-D measured on duplicated projection rows, arriving
 *      through the taxonomy instead of the table. `duplicates()` surfaces them; nothing collapses
 *      them, because which one is "right" is not this class's call.
 *
 *   4. A `slider` IS A REPEATER, NOT A SLIDER, and its stored value is a JSON *string* the
 *      storefront `json_decode()`s at render time. Its `fields` list is carried through so an
 *      editor can render sub-inputs rather than a text box.
 */
final class ThemeFieldTree
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private static array $memo = [];

    /** @var array<string, array<string, array<int, string>>> */
    private static array $dupMemo = [];

    /**
     * Page → section → field, in the order the theme declares, with `pages.json`'s pages first and
     * in its order.
     *
     * @return array<int, array{
     *     page: string, title: string, listed: bool, readable: bool,
     *     sections: array<int, array{key: string, title: string, fields: array<int, array<string, mixed>>}>
     * }>
     */
    public static function forTheme(string $theme): array
    {
        if (array_key_exists($theme, self::$memo)) {
            return self::$memo[$theme];
        }

        /* Same containment as the catalogue: `$theme` reaches here from the `{theme}` route
         * parameter and from `stores.theme_id`, both merchant-writable. */
        if ($theme === '' || basename($theme) !== $theme) {
            return self::$memo[$theme] = [];
        }

        $dir = base_path('themes/' . $theme . '/theme_json');

        if (! is_dir($dir)) {
            return self::$memo[$theme] = [];
        }

        $index = self::pageIndex($dir);
        $pages = [];
        $seen  = [];

        foreach (File::glob($dir . '/*.json') as $path) {
            $slug = basename($path, '.json');

            if ($slug === 'pages') {
                continue;
            }

            $decoded = json_decode((string) File::get($path), true);
            $sections = [];

            if (is_array($decoded)) {
                foreach (self::sections($decoded) as $section) {
                    $built = self::buildSection($section);

                    if ($built['fields'] !== []) {
                        $sections[] = $built;
                    }
                }
            }

            $pages[$slug] = [
                'page'     => $slug,
                'title'    => $index[$slug]['title'] ?? self::titleFrom($decoded, $slug),
                'listed'   => array_key_exists($slug, $index),
                /* FALSE means "this file exists and yielded no field the walker understands" --
                 * `homepage.json`'s second schema, not an empty page. See note 2. */
                'readable' => $sections !== [],
                'sections' => $sections,
            ];
            $seen[] = $slug;
        }

        /* `pages.json`'s order first, for the pages it lists; then everything else alphabetically,
         * so an unlisted page is at the end rather than absent. */
        $ordered = [];

        foreach (array_keys($index) as $slug) {
            if (isset($pages[$slug])) {
                $ordered[] = $pages[$slug];
                unset($pages[$slug]);
            }
        }

        ksort($pages);

        foreach ($pages as $page) {
            $ordered[] = $page;
        }

        return self::$memo[$theme] = $ordered;
    }

    /**
     * Field names this theme declares more than once, each mapped to the `page/section` origins
     * that declare it. Empty for a theme with no collision.
     *
     * @return array<string, array<int, string>>
     */
    public static function duplicates(string $theme): array
    {
        if (array_key_exists($theme, self::$dupMemo)) {
            return self::$dupMemo[$theme];
        }

        $origins = [];

        foreach (self::forTheme($theme) as $page) {
            foreach ($page['sections'] as $section) {
                foreach ($section['fields'] as $field) {
                    $origins[$field['name']][] = $page['page'] . '/' . $section['key'];
                }
            }
        }

        $dups = [];

        foreach ($origins as $name => $where) {
            if (count($where) > 1) {
                $dups[$name] = $where;
            }
        }

        ksort($dups);

        return self::$dupMemo[$theme] = $dups;
    }

    /** Every composed name in the tree, for pinning against `ThemeFieldCatalog::editableFields()`. */
    public static function names(string $theme): array
    {
        $names = [];

        foreach (self::forTheme($theme) as $page) {
            foreach ($page['sections'] as $section) {
                foreach ($section['fields'] as $field) {
                    $names[$field['name']] = true;
                }
            }
        }

        $out = array_keys($names);
        sort($out);

        return $out;
    }

    public static function forget(): void
    {
        self::$memo = [];
        self::$dupMemo = [];
    }

    /* ======================================================================= internals */

    /** @return array<string, array{title: string}> slug => meta, in `pages.json` order */
    private static function pageIndex(string $dir): array
    {
        $path = $dir . '/pages.json';

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) File::get($path), true);

        if (! is_array($decoded)) {
            return [];
        }

        $index = [];

        foreach ($decoded as $entry) {
            if (is_array($entry) && isset($entry['slug']) && is_string($entry['slug'])) {
                $index[$entry['slug']] = ['title' => (string) ($entry['title'] ?? $entry['slug'])];
            }
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array{key: string, title: string, fields: array<int, array<string, mixed>>}
     */
    private static function buildSection(array $section): array
    {
        $sectionKey = (string) ($section['key'] ?? $section['slug'] ?? '');
        $fields = [];

        foreach ($section['settings'] as $setting) {
            if (! is_array($setting)) {
                continue;
            }

            $key  = $setting['key'] ?? null;
            $type = $setting['type'] ?? null;

            /* EXACTLY the catalogue's acceptance rule, so the two name sets cannot diverge. */
            if (! is_string($key) || $key === '' || ! is_string($type) || $type === '') {
                continue;
            }

            $field = [
                'name'        => $sectionKey === '' ? $key : $sectionKey . '_' . $key,
                'key'         => $key,
                'type'        => $type,
                'label'       => (string) ($setting['label'] ?? $key),
                'placeholder' => isset($setting['placeholder']) ? (string) $setting['placeholder'] : null,
                'default'     => $setting['value'] ?? null,
            ];

            if (isset($setting['rows'])) {
                $field['rows'] = (int) $setting['rows'];
            }

            /* A `slider` is a repeater; its sub-field list is what an editor renders inputs for. */
            if (isset($setting['fields']) && is_array($setting['fields'])) {
                $field['repeater_fields'] = array_values(array_filter(
                    $setting['fields'],
                    static fn ($f) => is_string($f) && $f !== ''
                ));
            }

            $fields[] = $field;
        }

        return [
            'key'    => $sectionKey,
            'title'  => (string) ($section['title'] ?? $sectionKey),
            'fields' => $fields,
        ];
    }

    /** Mirrors `ThemeFieldCatalog::sections()`; three page files are a top-level LIST. */
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

    private static function titleFrom(mixed $decoded, string $slug): string
    {
        if (is_array($decoded) && isset($decoded['title']) && is_string($decoded['title'])) {
            return $decoded['title'];
        }

        return ucwords(str_replace('-', ' ', $slug));
    }
}
