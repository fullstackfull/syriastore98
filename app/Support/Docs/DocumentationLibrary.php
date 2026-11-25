<?php

namespace App\Support\Docs;

use Illuminate\Support\Facades\Cache;

/**
 * PHASE 03 M6 — THE DOCUMENTATION CENTRE'S CONTENT, AND WHY IT LIVES IN THE REPOSITORY.
 * =================================================================================================
 *
 * The owner asked for a bilingual documentation centre — explicitly NOT the vendored `/api-docs`,
 * which is `packages/workdo/Api/src/routes/web.php:18` behind the merchant auth stack and documents
 * the API rather than the product.
 *
 * THE CONTENT IS MARKDOWN IN `resources/docs/<locale>/<category>/<article>.md`, for the same
 * measured reasons that put the M5 marketing pages in git rather than in the LandingPage builder:
 *
 *   - A database-backed centre only has content after somebody runs a seeder ON PRODUCTION, and
 *     Phase 03 is not authorised to apply a production migration or seed.
 *   - Prose in a database row cannot be reviewed in a diff, cannot be searched in the editor, and
 *     cannot be asserted by a test. Prose in the repository is all three.
 *   - A bilingual pair is visible as two files side by side; a bilingual pair in a row is a column
 *     somebody forgets to fill.
 *
 * The owner's brief allows a database-backed search. It is not needed: the corpus is tens of
 * articles, the index is built from the files and cached, and `DocumentationSearch` ranks in PHP.
 * No migration, no seeder, no second CMS, and nothing to keep in step between environments.
 *
 * -------------------------------------------------------------------------------------------------
 * FRONT MATTER IS A DELIBERATELY TINY SUBSET, NOT YAML. A `---` block of `key: value` lines, where
 * `related` takes a comma-separated list. There is no YAML parser in this application's
 * dependencies and adding one for four keys would be a dependency decision the owner has not been
 * asked for. The parser is strict: an unknown key is ignored, a missing `title` makes the file
 * invisible rather than half-rendered, and `DocumentationLibraryTest` asserts every shipped file
 * parses.
 *
 * LOCALE FALLBACK IS EXPLICIT AND VISIBLE. An article that exists in English and not yet in Arabic
 * is served in English WITH A NOTICE, never as a 404 and never silently. A reader looking for help
 * is better served by text they can struggle through than by a dead link, and the notice is what
 * stops the gap being invisible to us.
 */
final class DocumentationLibrary
{
    /** Relative to the application root. */
    public const ROOT = 'resources/docs';

    /** The locales the centre ships. The first is the fallback and must always be complete. */
    public const LOCALES = ['en', 'ar'];

    private const CACHE_KEY = 'platform.docs.index.v1';

    /** @var array<string, array>|null  In-process memo; the request may ask several times. */
    private static ?array $memo = null;

    /**
     * The whole catalogue, keyed by locale.
     *
     * Shape per locale:
     *   categories => [ slug => ['slug','title','summary','order','articles' => [ ...article ]] ]
     *   articles   => [ 'category/slug' => article ]
     *
     * An article is:
     *   ['id','slug','category','title','summary','order','related' => [...ids],
     *    'updated' => ISO date or '', 'body' => raw markdown,
     *    'locale' => the locale it was actually READ from]
     */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        /* Cached because the build reads every file on disk. `Cache::flexible` is not used: the
           index must be correct immediately after a deploy, and a stale read here is a wrong
           answer rather than a slow one. In tests `CACHE_STORE=array`, so each test gets a fresh
           store; `forget()` exists for the cases that write files mid-test. */
        $index = Cache::remember(self::CACHE_KEY, 3600, static fn () => self::build());

        self::$memo = $index;

        return $index;
    }

    public static function forget(): void
    {
        self::$memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** @return list<array> the categories of a locale, in order. */
    public static function categories(string $locale): array
    {
        $all = self::all();

        return array_values($all[self::resolveLocale($locale)]['categories'] ?? []);
    }

    public static function category(string $locale, string $slug): ?array
    {
        $all = self::all();

        return $all[self::resolveLocale($locale)]['categories'][$slug] ?? null;
    }

    /**
     * One article, with the locale fallback applied.
     *
     * Returns null only when the article does not exist in ANY locale, which is a genuine 404.
     */
    public static function article(string $locale, string $category, string $slug): ?array
    {
        $locale = self::resolveLocale($locale);
        $id     = $category . '/' . $slug;
        $all    = self::all();

        if (isset($all[$locale]['articles'][$id])) {
            return $all[$locale]['articles'][$id];
        }

        foreach (self::LOCALES as $other) {
            if (isset($all[$other]['articles'][$id])) {
                /* The caller can see this is not the requested locale and must say so. */
                return $all[$other]['articles'][$id];
            }
        }

        return null;
    }

    /** @return list<array> every article of a locale, with fallbacks filled in. */
    public static function articles(string $locale): array
    {
        $locale = self::resolveLocale($locale);
        $all    = self::all();

        $ids = [];
        foreach (self::LOCALES as $l) {
            foreach (array_keys($all[$l]['articles'] ?? []) as $id) {
                $ids[$id] = true;
            }
        }

        $out = [];
        foreach (array_keys($ids) as $id) {
            [$category, $slug] = explode('/', $id, 2);
            $article = self::article($locale, $category, $slug);
            if ($article !== null) {
                $out[] = $article;
            }
        }

        usort($out, static fn ($a, $b) => [$a['category'], $a['order'], $a['slug']] <=> [$b['category'], $b['order'], $b['slug']]);

        return $out;
    }

    /**
     * The articles an article points at, resolved and filtered.
     *
     * A `related` id that names nothing is DROPPED rather than rendered as a dead link — a typo in
     * front matter should not become a 404 for a reader. `DocumentationLibraryTest` asserts that no
     * shipped article has one, so the drop never silently hides a real mistake.
     *
     * @return list<array>
     */
    public static function related(string $locale, array $article): array
    {
        $out = [];

        foreach ($article['related'] as $id) {
            if ($id === $article['id'] || ! str_contains($id, '/')) {
                continue;
            }
            [$category, $slug] = explode('/', $id, 2);
            $found = self::article($locale, $category, $slug);
            if ($found !== null) {
                $out[] = $found;
            }
        }

        return $out;
    }

    /**
     * The article before and after this one inside its category.
     *
     * @return array{prev: ?array, next: ?array}
     */
    public static function neighbours(string $locale, array $article): array
    {
        $category = self::category($locale, $article['category']);
        $list     = $category['articles'] ?? [];

        $i = null;
        foreach ($list as $n => $candidate) {
            if ($candidate['id'] === $article['id']) {
                $i = $n;
                break;
            }
        }

        if ($i === null) {
            return ['prev' => null, 'next' => null];
        }

        return [
            'prev' => $list[$i - 1] ?? null,
            'next' => $list[$i + 1] ?? null,
        ];
    }

    /** A locale this library actually ships; anything else falls back to the first. */
    public static function resolveLocale(string $locale): string
    {
        return in_array($locale, self::LOCALES, true) ? $locale : self::LOCALES[0];
    }

    // ---------------------------------------------------------------------------------------------

    private static function build(): array
    {
        $index = [];

        foreach (self::LOCALES as $locale) {
            $index[$locale] = ['categories' => [], 'articles' => []];

            $root = base_path(self::ROOT . '/' . $locale);
            if (! is_dir($root)) {
                continue;
            }

            foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $categorySlug = self::slugOf(basename($dir));
                $meta         = self::readFrontMatter($dir . '/_category.md');

                if ($meta === null || ($meta['title'] ?? '') === '') {
                    /* A category with no `_category.md` has no title to show, so it is skipped
                       rather than rendered as its directory name. The test asserts none is. */
                    continue;
                }

                $articles = [];

                foreach (glob($dir . '/*.md') ?: [] as $file) {
                    if (basename($file) === '_category.md') {
                        continue;
                    }

                    $parsed = self::readArticle($file, $categorySlug, $locale);
                    if ($parsed === null) {
                        continue;
                    }

                    $articles[] = $parsed;
                    $index[$locale]['articles'][$parsed['id']] = $parsed;
                }

                usort($articles, static fn ($a, $b) => [$a['order'], $a['slug']] <=> [$b['order'], $b['slug']]);

                $index[$locale]['categories'][$categorySlug] = [
                    'slug'     => $categorySlug,
                    'title'    => $meta['title'],
                    'summary'  => $meta['summary'] ?? '',
                    'order'    => (int) ($meta['order'] ?? self::orderOf(basename($dir))),
                    'articles' => $articles,
                ];
            }

            uasort(
                $index[$locale]['categories'],
                static fn ($a, $b) => [$a['order'], $a['slug']] <=> [$b['order'], $b['slug']]
            );
        }

        return $index;
    }

    private static function readArticle(string $file, string $categorySlug, string $locale): ?array
    {
        $meta = self::readFrontMatter($file);

        if ($meta === null || ($meta['title'] ?? '') === '') {
            return null;
        }

        $slug = self::slugOf(pathinfo($file, PATHINFO_FILENAME));

        return [
            'id'       => $categorySlug . '/' . $slug,
            'slug'     => $slug,
            'category' => $categorySlug,
            'locale'   => $locale,
            'title'    => $meta['title'],
            'summary'  => $meta['summary'] ?? '',
            'order'    => (int) ($meta['order'] ?? self::orderOf(pathinfo($file, PATHINFO_FILENAME))),
            'related'  => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) ($meta['related'] ?? ''))
            ))),
            /* GAP-108 (P03-D-05). WHEN THIS ARTICLE WAS LAST LOOKED AT, as an ISO date.
               A reader had no way to tell whether an article described the product as it is or as
               it was a year ago, and nothing in the centre carried a date of any kind.

               A MALFORMED OR MISSING VALUE EMPTIES THE FIELD; IT DOES NOT HIDE THE ARTICLE. A
               missing `title` makes a file invisible on purpose (half a heading is worse than no
               article), but a reader came for the BODY: refusing to serve working help because its
               date is mistyped would be the cure being worse than the disease. The empty string is
               what the view guards on, and `DocumentationArticleFreshnessTest` is what stops the
               fallback being silent — it asserts a valid date on every shipped file, in both
               locales, so an article can never reach production without one.

               NOT A VERSION, and that is measured rather than preferred: this application carries
               no release identifier anywhere (no `version` in `composer.json`, none in
               `config/app.php`), so the gap's other option would have meant inventing a release
               number, which on a help page looks authoritative and means nothing. */
            'updated'  => self::isoDate((string) ($meta['updated'] ?? '')),
            'body'     => $meta['__body'],
        ];
    }

    /** An ISO `YYYY-MM-DD` that is also a real calendar date, or the empty string. */
    private static function isoDate(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return '';
        }

        /* `2026-02-31` matches the pattern and is not a date. `checkdate` is what stops the view
           emitting a `datetime` attribute no parser will accept. */
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : '';
    }

    /**
     * The tiny front-matter parser.
     *
     * ```
     * ---
     * title: Create your account
     * summary: One sentence.
     * order: 1
     * related: getting-started/choose-a-plan, themes/edit-your-theme
     * updated: 2026-11-25
     * ---
     * # Body markdown…
     * ```
     *
     * Returns null when the file is missing or has no front-matter block, so a stray `.md` in the
     * tree is invisible rather than half-rendered. `__body` carries everything after the block.
     *
     * @return array<string, string>|null
     */
    public static function readFrontMatter(string $file): ?array
    {
        if (! is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $raw = str_replace("\r\n", "\n", $raw);

        if (! str_starts_with($raw, "---\n")) {
            return null;
        }

        $end = strpos($raw, "\n---", 3);
        if ($end === false) {
            return null;
        }

        $block = substr($raw, 4, $end - 3);
        $body  = ltrim(substr($raw, $end + 4), "\n");

        $meta = ['__body' => $body];

        foreach (explode("\n", $block) as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = explode(':', $line, 2);
            $k = strtolower(trim($k));
            if ($k === '' || $k === '__body') {
                continue;
            }
            $meta[$k] = trim($v);
        }

        return $meta;
    }

    /** `01-create-your-account` -> `create-your-account`; also the guard against a path escape. */
    private static function slugOf(string $name): string
    {
        $name = preg_replace('/^\d+[-_]/', '', $name) ?? $name;

        return strtolower(preg_replace('/[^A-Za-z0-9-]/', '', $name) ?? '');
    }

    /** `01-create-your-account` -> 1; files with no prefix sort last. */
    private static function orderOf(string $name): int
    {
        return preg_match('/^(\d+)[-_]/', $name, $m) === 1 ? (int) $m[1] : 9999;
    }
}
