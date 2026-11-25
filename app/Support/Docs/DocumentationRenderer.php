<?php

namespace App\Support\Docs;

use Illuminate\Support\Str;

/**
 * PHASE 03 M6 — MARKDOWN TO HTML, PLUS THE TABLE OF CONTENTS THAT GOES WITH IT.
 * =================================================================================================
 *
 * RAW HTML IN THE SOURCE IS STRIPPED, NOT RENDERED, and that is a deliberate choice rather than a
 * default. The articles are written by us and reviewed in git, so the usual argument for allowing
 * HTML — "the author is trusted" — does hold today. It is refused anyway, because the value of the
 * rule is that it survives the day somebody pastes a block of markup from a vendor's site into a
 * help article, and because nothing in this corpus needs it. `allow_unsafe_links` is off for the
 * same reason: a `javascript:` URL in a documentation link has no legitimate use here.
 *
 * HEADING IDs ARE ADDED AFTERWARDS RATHER THAN BY AN EXTENSION. CommonMark emits `<h2>` with no
 * `id`, so a table of contents has nothing to link to. The two obvious fixes are the
 * HeadingPermalink extension (which injects visible anchor glyphs into the prose and would have to
 * be styled and hidden from screen readers) and post-processing. Post-processing is chosen because
 * the SAME `slug()` builds the TOC entry and the heading id, so the two cannot drift — which is the
 * failure mode that matters: a table of contents whose links go nowhere is worse than none.
 *
 * ARABIC HEADINGS GET ARABIC IDs. The slug keeps any Unicode letter or digit, so `## البداية`
 * becomes `id="البداية"`. That is a valid HTML id and a valid URL fragment once percent-encoded,
 * and the alternative — positional ids like `h-3` — breaks every saved link the moment somebody
 * inserts a section.
 */
final class DocumentationRenderer
{
    /** CommonMark options. Both are security decisions; see the class docblock. */
    private const OPTIONS = [
        'html_input'         => 'strip',
        'allow_unsafe_links' => false,
    ];

    /** Markdown in, HTML out, with `id` attributes on every h2 and h3 and `dir` on every code run. */
    public static function html(string $markdown): string
    {
        $html = self::isolateCode(Str::markdown($markdown, self::OPTIONS));

        return preg_replace_callback(
            '~<(h[23])>(.*?)</\1>~su',
            static function (array $m): string {
                $id = self::slug(strip_tags($m[2]));

                return $id === ''
                    ? $m[0]
                    : '<' . $m[1] . ' id="' . e($id) . '">' . $m[2] . '</' . $m[1] . '>';
            },
            $html
        ) ?? $html;
    }

    /**
     * GAP-106 (P03-D-03) — EVERY CODE RUN IS DIRECTION-ISOLATED.
     *
     * A bilingual documentation centre's one genuinely hard rendering case is a left-to-right code
     * sample inside a right-to-left page, and until this change the corpus contained no code at all
     * to find it with: 32 articles, zero fenced blocks, while `public/css/store-syria-platform.css`
     * had carried `.ss-ps-prose pre` and `.ss-ps-prose pre code` rules since M6 for content that
     * did not exist. That is the gap, verbatim: "Code-sample rendering is untested because no
     * article contains one."
     *
     * WHY `dir`, AND WHY NOT CSS. A line such as `Sale Price,Final Price,Product Stock` is almost
     * entirely NEUTRAL characters — Latin letters and commas, no strong right-to-left run — so
     * inside an Arabic paragraph the Unicode bidirectional algorithm resolves its segments at the
     * paragraph's own level and is free to lay them out right-to-left. `text-align: left` does not
     * help: it moves the block, not the character order, and a reader who copies the reordered line
     * gets something that does not parse. `dir="ltr"` resets the embedding level, and it has to be
     * an attribute because this markup is generated.
     *
     * INLINE CODE TOO, and that is the case worth being explicit about: a fenced block is a long
     * run that often contains enough structure to resolve correctly by luck, whereas an inline
     * `Final Price` is a short neutral run surrounded by Arabic and is reordered reliably.
     *
     * THE ORDER MATTERS. `<pre><code` is matched FIRST, so a fenced block's own `<code>` already
     * carries the attribute by the time the inline pass runs and is skipped rather than given a
     * second `dir` — a duplicate attribute is invalid and the browser keeps whichever it saw first.
     *
     * It does not touch the content: CommonMark has already escaped it, and this adds an attribute
     * to tags it emitted rather than parsing the article again.
     */
    private static function isolateCode(string $html): string
    {
        $html = str_replace('<pre><code', '<pre dir="ltr"><code dir="ltr"', $html);

        /* A `<pre>` CommonMark emitted without a `<code>` child (an indented block in some
           configurations) still needs the attribute. */
        $html = (string) preg_replace('~<pre>(?!<code)~', '<pre dir="ltr">', $html);

        /* Everything else: inline code. `(?! dir=)` is what skips the block children the first
           pass has already handled. */
        return (string) preg_replace('~<code(?! dir=)~', '<code dir="ltr"', $html);
    }

    /**
     * The headings of an article, for the contents panel.
     *
     * Read from the MARKDOWN rather than from the rendered HTML, so a heading inside a fenced code
     * block cannot become a contents entry.
     *
     * @return list<array{level: int, text: string, id: string}>
     */
    public static function tableOfContents(string $markdown): array
    {
        $markdown = preg_replace('/```.*?```/s', '', $markdown) ?? $markdown;

        $out = [];

        foreach (explode("\n", $markdown) as $line) {
            if (preg_match('/^(#{2,3})\s+(.+?)\s*$/u', $line, $m) !== 1) {
                continue;
            }

            $text = trim($m[2]);
            $id   = self::slug($text);

            if ($id === '') {
                continue;
            }

            $out[] = ['level' => strlen($m[1]), 'text' => $text, 'id' => $id];
        }

        return $out;
    }

    /**
     * The one slug function. Used for both the heading id and the contents link, so they cannot
     * disagree.
     *
     * Any Unicode letter, digit or COMBINING MARK survives, which is what makes Arabic headings
     * addressable.
     *
     * `\p{M}` IS NOT DECORATION, and leaving it out was a measured defect. An Arabic diacritic —
     * shadda, fatha, damma, kasra, tanween — is Unicode general category **Mn (Mark, non-spacing)**
     * and therefore matched NEITHER `\p{L}` nor `\p{N}`, so each one became a hyphen in the middle
     * of a word:
     *
     *       سجّل                   ->  سج-ل
     *       أكّد بريدك الإلكتروني  ->  أك-د-بريدك-الإلكتروني
     *
     * 24 of the 58 Arabic headings in `resources/docs/ar` — 41% — produced an id naming a word that
     * does not exist. It stayed invisible because the heading id and the contents link come from
     * THIS function, both of them, so every anchor resolved and the only symptom was a mangled
     * fragment in the address bar. The docblock's own example, `## البداية`, carries no diacritic,
     * which is why the promise above read as kept.
     *
     * `DocumentationArabicHeadingIdTest` holds the measurement and asserts it per word over the
     * whole Arabic corpus: a single source word must slug to a single id segment.
     */
    public static function slug(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\p{M}]+/u', '-', $text) ?? $text;

        return trim($text, '-');
    }
}
