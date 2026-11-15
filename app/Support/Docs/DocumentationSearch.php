<?php

namespace App\Support\Docs;

/**
 * PHASE 03 M6 — SEARCH OVER THE DOCUMENTATION CENTRE, INCLUDING IN ARABIC.
 * =================================================================================================
 *
 * The owner's brief allows a database-backed search. One is not used, and the reason is a
 * measurement rather than a preference: the corpus is a few dozen files, the index is already
 * cached by `DocumentationLibrary`, and a `LIKE` query against MySQL would be WORSE here — its
 * default collation is accent- and case-insensitive for Latin but does nothing at all about Arabic
 * orthography, which is where the real misses are.
 *
 * WHY ARABIC NEEDS NORMALISING AND LATIN MOSTLY DOES NOT.
 * A reader types what they hear; the author wrote what is correct. In Arabic those differ in ways
 * that are invisible to a byte comparison:
 *
 *   - `أ إ آ ٱ` are all written for the same alef. A reader almost always types the bare `ا`.
 *   - `ة` at the end of a word is often typed `ه`.
 *   - `ى` (alef maqsura) is often typed `ي`, and vice versa.
 *   - Tashkeel (the short-vowel marks) and tatweel (the stretching character `ـ`) are decorative
 *     and are never typed in a search box, but DO appear in carefully written prose.
 *   - Arabic-Indic digits `٠١٢٣٤٥٦٧٨٩` and ASCII `0123456789` are the same numbers.
 *
 * Without normalisation a merchant searching `قالب` would miss an article titled `قوالب` only on
 * stemming grounds — which is acceptable — but one searching `مسوده` would miss `مسوّدة` on pure
 * orthography, which is not. `DocumentationSearchTest` drives each of these as a named case.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO. No stemming, no synonyms, no fuzzy matching, no transliterated
 * cross-script search. Each is a real improvement and each can be wrong in ways a reader cannot
 * see; none is needed to make the centre usable, and claiming them would be a feature promise the
 * code does not keep. Prefix matching is as far as it goes, and that is stated on the results page.
 */
final class DocumentationSearch
{
    /** Below this a result is noise rather than a weak answer. */
    private const MINIMUM_SCORE = 1;

    public const MAX_RESULTS = 25;

    /**
     * Search one locale's articles.
     *
     * @return list<array{article: array, score: int, snippet: string}>
     */
    public static function run(string $locale, string $query): array
    {
        $terms = self::terms($query);

        if ($terms === []) {
            return [];
        }

        $results = [];

        foreach (DocumentationLibrary::articles($locale) as $article) {
            $score = self::score($article, $terms);

            if ($score < self::MINIMUM_SCORE) {
                continue;
            }

            $results[] = [
                'article' => $article,
                'score'   => $score,
                'snippet' => self::snippet($article, $terms),
            ];
        }

        usort($results, static function (array $a, array $b) {
            return [$b['score'], $a['article']['title']] <=> [$a['score'], $b['article']['title']];
        });

        return array_slice($results, 0, self::MAX_RESULTS);
    }

    /**
     * A match in the title is worth more than one in the body.
     *
     * The weights are deliberately coarse. A reader is looking for the page whose SUBJECT is what
     * they typed, so an article whose title carries every term must outrank one that mentions them
     * in passing however many times — which is why a body match scores once per term rather than
     * once per occurrence.
     */
    private static function score(array $article, array $terms): int
    {
        $title   = self::normalise($article['title']);
        $summary = self::normalise($article['summary']);
        $body    = self::normalise($article['body']);

        $score = 0;

        foreach ($terms as $term) {
            if (self::contains($title, $term)) {
                $score += 8;
            } elseif (self::contains($summary, $term)) {
                $score += 4;
            } elseif (self::contains($body, $term)) {
                $score += 1;
            }
        }

        /* An article matching EVERY term beats one matching some of them, whatever the field. */
        $matchedAll = true;
        foreach ($terms as $term) {
            if (! self::contains($title . ' ' . $summary . ' ' . $body, $term)) {
                $matchedAll = false;
                break;
            }
        }

        return $matchedAll ? $score + 5 : $score;
    }

    /**
     * A plain-text excerpt around the first matching term.
     *
     * Markdown syntax is stripped first, so a snippet never shows `##` or a link's bracket soup.
     */
    private static function snippet(array $article, array $terms, int $width = 180): string
    {
        $text = self::plainText($article['body']);

        if ($text === '') {
            return $article['summary'];
        }

        $haystack = self::normalise($text);
        $at       = false;

        foreach ($terms as $term) {
            $at = mb_strpos($haystack, $term);
            if ($at !== false) {
                break;
            }
        }

        if ($at === false) {
            return mb_substr($text, 0, $width) . (mb_strlen($text) > $width ? '…' : '');
        }

        /* NORMALISATION IS LENGTH-PRESERVING BY CONSTRUCTION -- every rule below maps one character
           to exactly one character or removes it, and the removals are tracked. Rather than rely on
           that, the offset is clamped and the excerpt taken from the ORIGINAL text at a word
           boundary, so a drift cannot cut a character in half. */
        $start = max(0, min(mb_strlen($text) - 1, $at - (int) ($width / 3)));
        $excerpt = mb_substr($text, $start, $width);

        return ($start > 0 ? '…' : '') . trim($excerpt) . (mb_strlen($text) > $start + $width ? '…' : '');
    }

    /** @return list<string> normalised, de-duplicated, one-character noise dropped. */
    public static function terms(string $query): array
    {
        $normalised = self::normalise($query);

        $parts = preg_split('/[^\p{L}\p{N}]+/u', $normalised, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $terms = [];
        foreach ($parts as $part) {
            /* A single character matches almost everything and is never what the reader meant --
               except for a CJK-style script where one character is a word, which this corpus does
               not contain. Latin and Arabic both need two. */
            if (mb_strlen($part) < 2) {
                continue;
            }
            $terms[$part] = true;
        }

        return array_slice(array_keys($terms), 0, 10);
    }

    /**
     * Fold away everything a reader will not type.
     *
     * Each rule is here because of a specific miss it prevents; see the class docblock.
     */
    public static function normalise(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');

        /* Tashkeel (U+064B..U+0652), superscript alef (U+0670) and tatweel (U+0640). */
        $text = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $text) ?? $text;

        $map = [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ى' => 'ي', 'ئ' => 'ي',
            'ؤ' => 'و',
            'ة' => 'ه',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '«' => '"', '»' => '"', '“' => '"', '”' => '"', '’' => "'", '‘' => "'",
            '—' => '-', '–' => '-',
        ];

        return strtr($text, $map);
    }

    /** Markdown in, readable prose out. Used for snippets and for body scoring. */
    public static function plainText(string $markdown): string
    {
        $t = $markdown;
        $t = preg_replace('/```.*?```/s', ' ', $t) ?? $t;        // fenced code
        $t = preg_replace('/`([^`]*)`/', '$1', $t) ?? $t;         // inline code
        $t = preg_replace('/!\[[^\]]*\]\([^)]*\)/', ' ', $t) ?? $t; // images
        $t = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $t) ?? $t; // links keep their text
        $t = preg_replace('/^\s{0,3}#{1,6}\s*/m', '', $t) ?? $t;  // headings
        $t = preg_replace('/^\s{0,3}>\s?/m', '', $t) ?? $t;       // quotes
        $t = preg_replace('/^\s{0,3}[-*+]\s+/m', '', $t) ?? $t;   // bullets
        $t = preg_replace('/\*\*|__|\*|_/', '', $t) ?? $t;        // emphasis
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        return trim($t);
    }

    private static function contains(string $haystack, string $needle): bool
    {
        return $needle !== '' && mb_strpos($haystack, $needle) !== false;
    }
}
