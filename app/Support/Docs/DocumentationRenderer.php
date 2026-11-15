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

    /** Markdown in, HTML out, with `id` attributes on every h2 and h3. */
    public static function html(string $markdown): string
    {
        $html = Str::markdown($markdown, self::OPTIONS);

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
     * Any Unicode letter or digit survives, which is what makes Arabic headings addressable.
     */
    public static function slug(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text) ?? $text;

        return trim($text, '-');
    }
}
