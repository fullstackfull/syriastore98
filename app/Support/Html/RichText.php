<?php

namespace App\Support\Html;

/**
 * An ALLOWLIST sanitizer for the rich text a person types into an editor (a ticket's description, a reply) and somebody else's browser prints.  P1-SEC-75.
 *
 * WHY IT EXISTS. The vendored Helpdesk views print a ticket's description and every reply with `{!! !!}`, because the editor produces markup. The text is a merchant's; the
 * page is the platform operator's. The project's input filter (`XSS` middleware) is a substring blocklist on an opt-in alias. HTMLPurifier (and masterminds/html5) ARE in vendor/,
 * but only as transitive dependencies of phpoffice/phpspreadsheet and dompdf: nothing here depends on them directly, nothing configures them for this, and a transitive dependency
 * goes when its parent does. So the output is cleaned where it is printed -- by a host-side override of the views -- and this is what cleans it; HTMLPurifier with an allowlist is the
 * alternative if keeping this one proves costlier than adopting that.
 *
 * HOW. The input is parsed (libxml's HTML parser, no network), and a NEW string is built from the parse tree: an element only if it is on the list below, an attribute only if the
 * element lists it and its value passes that attribute's own check, every text node and every attribute value entity-escaped. Nothing of the input is copied through: not a
 * tag, not an attribute, not a comment, not a style declaration. So a parser that disagrees with a browser cannot make the output RUN anything: it contains no raw-text element, no
 * namespace switch (`svg`, `math`), no `<` or `>` or `&` or quote that was not escaped by this class. What it CAN change is the STRUCTURE, which is the next paragraph.
 *
 * STRUCTURE. libxml's tree is an HTML4 tree, and writing it back out hands a browser's HTML5 tree builder a string it repairs differently for some nestings: in `<li><div><li>x</li></div></li>`
 * the inner `li` pops the open `div`, so the output's own `</div>` closes an element of the HOST page -- one per repetition (found by an independent review of P1-SEC-75: a merchant could pull
 * the operator's reply form out of its card). So the class writes only the nestings the two tree builders agree on (belongsIn()): a list item only in a list and a list only of list items,
 * table parts only in their table, no block inside a paragraph, a heading or `pre`, no link inside a link; anything else is UNWRAPPED (its text stays). tests/Support/Security/bin/
 * verify-rich-text-in-chromium.php is the gate: it prints the output into the real page's shape and fails if the element AFTER it is no longer where it was.
 *
 * WHAT IS KEPT: paragraphs, line breaks, rules, emphasis, lists, quotes, code, headings, tables, `a` (http, https, mailto, tel only; opened with rel=noopener noreferrer nofollow),
 * `img` (a base64 png, jpeg, gif or webp DATA url only: an editor pastes pictures that way, and any other source is a request the operator's browser would make with the
 * operator's cookies), and a short list of presentation: `dir`, and `style` reduced to alignment, direction, colour, weight, style, decoration and size, each value matched
 * against a pattern and re-written, never copied. WHAT IS DROPPED: every other element (its text is kept; `script`, `style`, `iframe` and the like lose their content too),
 * every other attribute (so every `on*` handler, `class`, `id`, `name`, `srcset`, `xlink:href`, `formaction`), comments, processing instructions, CDATA.
 *
 * LIMITS. The input is cut at MAX_BYTES (2 MiB: an editor writes a pasted picture as a base64 `data:` URL inside the text, and a screenshot is hundreds of kilobytes), and an element
 * deeper than MAX_DEPTH is not written (its text is). Two kinds of input are not parsed at all and are shown as TEXT -- escaped, never raw: input with a start tag that crowds
 * (see crowded(): libxml's cost grows with the SQUARE of the attributes of one tag) and input libxml cannot read -- a belt that is unreachable on libxml 2.9.14: 43,000 inputs, among
 * them random bytes, floods, NULs and 60,000-deep nests, never made loadHTML fail, so no test reaches it. What libxml itself drops silently is dropped here too: everything after a NUL
 * byte, after a literal `</body>`, and below 256 levels of nesting. Measured on 2 MiB of hostile input: at most about 1 s and 75 MB per call.
 *
 * libxml's error buffer: clean() turns the parser's own error collection on, restores the caller's setting, and clears the buffer -- so errors a CALLER had collected before calling it are
 * lost. Nothing in this application parses markup around a Blade print; a caller that did would have to read its errors first.
 */
final class RichText
{
    public const MAX_BYTES = 2097152;
    public const MAX_DEPTH = 32;

    /** the longest start tag, in units, that is parsed: one per byte outside a quoted value, one per quoted value whatever its length. An editor writes tens; see crowded() */
    public const MAX_TAG = 400;

    /** element => attributes it may keep ('style' and 'dir' are checked by their own rules below) */
    private const ELEMENTS = [
        'p' => ['dir', 'style'], 'div' => ['dir', 'style'], 'span' => ['dir', 'style'], 'br' => [], 'hr' => [],
        'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 's' => [], 'strike' => [], 'del' => [], 'ins' => [], 'sub' => [], 'sup' => [], 'small' => [], 'mark' => [],
        'ul' => ['dir'], 'ol' => ['dir', 'start'], 'li' => ['dir'], 'blockquote' => ['dir'], 'pre' => [], 'code' => [],
        'h1' => ['dir', 'style'], 'h2' => ['dir', 'style'], 'h3' => ['dir', 'style'], 'h4' => ['dir', 'style'], 'h5' => ['dir', 'style'], 'h6' => ['dir', 'style'],
        'table' => ['dir'], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [], 'th' => ['colspan', 'rowspan', 'style'], 'td' => ['colspan', 'rowspan', 'style'],
        'a' => ['href', 'title'], 'img' => ['src', 'alt', 'title', 'width', 'height'],
    ];

    private const VOID = ['br' => true, 'hr' => true, 'img' => true];

    /** the elements whose CONTENT is not text a person wrote (code, markup of another language, form controls): dropped with it. Anything else not listed above is unwrapped. */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'noscript', 'noframes', 'noembed', 'template', 'title', 'head', 'xmp', 'plaintext', 'listing',
        'svg', 'math', 'textarea', 'select', 'option', 'optgroup', 'button', 'input', 'form', 'link', 'meta', 'base', 'audio', 'video', 'source', 'track', 'canvas', 'map', 'area', 'dialog', 'portal',
    ];

    /** the only parents these elements may have among the elements this class writes (the HTML4 and the HTML5 tree builders agree on these nestings, and on little else) */
    private const PARENT_OF = [
        'li' => ['ul', 'ol'], 'tr' => ['table', 'thead', 'tbody', 'tfoot'], 'td' => ['tr'], 'th' => ['tr'], 'thead' => ['table'], 'tbody' => ['table'], 'tfoot' => ['table'],
    ];

    /** a paragraph, a heading and `pre` hold phrasing content: a block start tag closes them in HTML5, however many inline elements lie between */
    private const PHRASING_ONLY = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre'];

    private const BLOCKS = ['p', 'div', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'blockquote', 'hr'];

    /** @var array<string, string> style property => the pattern its value has to match, whole */
    private const STYLE = [
        'text-align'       => '/^(left|right|center|justify|start|end)$/i',
        'direction'        => '/^(ltr|rtl)$/i',
        'color'            => self::COLOR,
        'background-color' => self::COLOR,
        'font-weight'      => '/^(normal|bold|bolder|lighter|[1-9]00)$/i',
        'font-style'       => '/^(normal|italic|oblique)$/i',
        'text-decoration'  => '/^(none|underline|line-through|underline line-through|line-through underline)$/i',
        'font-size'        => '/^\d{1,3}(\.\d{1,2})?(px|pt|em|rem|%)$/i',
    ];

    private const COLOR = '/^(#[0-9a-f]{3,8}|[a-z]{3,20}|rgba?\(\s*\d{1,3}%?\s*,\s*\d{1,3}%?\s*,\s*\d{1,3}%?\s*(,\s*(0|1|0?\.\d{1,3})\s*)?\))$/i';

    /** the characters a browser's URL parser drops or reads as space before it looks for a scheme (`java\tscript:`, `\x01javascript:`, a zero-width joiner) */
    private const URL_NOISE = '/[\x00-\x20\x7F-\x{A0}\x{AD}\x{1680}\x{180E}\x{2000}-\x{200F}\x{2028}-\x{202F}\x{205F}-\x{2064}\x{3000}\x{FEFF}]+/u';

    public static function clean(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $html = substr($html, 0, self::MAX_BYTES);

        // libxml wants valid UTF-8; an invalid byte becomes `?` rather than ending the parse
        $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');

        // decided BEFORE the parser sees it: a tag of 39,000 attributes costs libxml 7-9 s, and it costs it on every view of the page
        if (self::crowded($html)) {
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new \DOMDocument();
            $loaded = $document->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        } catch (\Throwable $e) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded === false) {
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $body = $document->getElementsByTagName('body')->item(0);

        return $body === null ? '' : self::children($body, 0, 'body', false, false);
    }

    /**
     * libxml's HTML parser compares every attribute of a start tag with the ones before it, so the cost of ONE tag grows with the square of its attributes: 5,645 distinct attributes
     * cost 0.07 s, 20,310 cost 1.1 s, 39,035 (256 KB) cost 7-9 s. An editor writes a handful. This reads the tags once, in linear time, and says whether any is longer than MAX_TAG
     * units -- one for every byte of the tag outside a quoted value, and one for a quoted value whatever its length (a pasted picture is a single very long value).
     *
     * It is conservative on purpose: a quote only starts a value after `=` and blanks that libxml itself skips (space, tab, LF, CR -- no more: a byte libxml does not skip is not a
     * quote's start, and what follows is counted byte by byte), an unterminated quote is counted byte by byte, and `<` is taken for a tag's start wherever it is, except before `!` and
     * `?`. It over-counts rather than under-counts: an unquoted attribute value of more than MAX_TAG bytes, which an editor does not write, is shown as text.
     */
    private static function crowded(string $html): bool
    {
        $length = strlen($html);
        $at = 0;

        while (($at = strpos($html, '<', $at)) !== false) {
            $at++;

            if ($at >= $length || $html[$at] === '!' || $html[$at] === '?') {
                continue;   // a comment, a doctype, a processing instruction: no attribute list
            }

            for ($units = 0; $at < $length && $html[$at] !== '>'; $units++) {
                if ($units >= self::MAX_TAG) {
                    return true;
                }

                if ($html[$at] === '=') {
                    $next = $at + 1 + strspn($html, " \t\n\r", $at + 1);

                    if ($next < $length && ($html[$next] === '"' || $html[$next] === "'")) {
                        $close = strpos($html, $html[$next], $next + 1);

                        if ($close !== false) {
                            $at = $close + 1;

                            continue;
                        }
                    }
                }

                $at++;
            }
        }

        return false;
    }

    /**
     * Whether an element of this name may be written with this parent -- the nearest ancestor that WAS written --, with a link open around it, or with a paragraph, a heading or `pre` open
     * around it. See STRUCTURE in the class docblock.
     */
    private static function belongsIn(string $tag, string $parent, bool $insideLink, bool $insidePhrasing): bool
    {
        if (isset(self::PARENT_OF[$tag]) && ! in_array($parent, self::PARENT_OF[$tag], true)) {
            return false;   // a list item outside a list, a cell outside a row, a row outside a table, a table part outside its table
        }

        if (($parent === 'ul' || $parent === 'ol') && $tag !== 'li') {
            return false;   // a list holds list items
        }

        if (in_array($parent, ['table', 'thead', 'tbody', 'tfoot'], true) && ! isset(self::PARENT_OF[$tag])) {
            return false;   // a table holds its sections and rows, a section its rows
        }

        if ($parent === 'tr' && $tag !== 'td' && $tag !== 'th') {
            return false;   // a row holds cells
        }

        if ($insidePhrasing && in_array($tag, self::BLOCKS, true)) {
            return false;   // a block inside a paragraph, a heading or `pre`, directly or through inline elements
        }

        return ! ($tag === 'a' && $insideLink);
    }

    /** @param string $parentTag the nearest ancestor that was WRITTEN ('body' at the top: what an element that was unwrapped hands down to its children) */
    private static function children(\DOMNode $parent, int $depth, string $parentTag, bool $insideLink, bool $insidePhrasing): string
    {
        $out = '';

        foreach ($parent->childNodes as $node) {
            if ($node instanceof \DOMText) {   // CDATA is a DOMText too
                $out .= htmlspecialchars(str_replace("\0", '', $node->data), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                continue;
            }

            if (! $node instanceof \DOMElement) {
                continue;   // a comment, a processing instruction, an entity reference
            }

            $tag = strtolower($node->nodeName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                continue;
            }

            $keep = isset(self::ELEMENTS[$tag]) && $depth < self::MAX_DEPTH && self::belongsIn($tag, $parentTag, $insideLink, $insidePhrasing);

            $inner = self::children($node, $depth + 1, $keep ? $tag : $parentTag, $insideLink || ($keep && $tag === 'a'), $insidePhrasing || ($keep && in_array($tag, self::PHRASING_ONLY, true)));

            // an element that is not on the list, that is past the depth limit, or that does not belong where it is, is unwrapped: its (escaped) text stays, its tag does not
            if (! $keep) {
                $out .= $inner;

                continue;
            }

            $attributes = self::attributes($node, $tag);

            if ($attributes === null) {
                continue;   // an element that is nothing without an attribute (an image with no acceptable source)
            }

            $out .= isset(self::VOID[$tag]) ? '<' . $tag . $attributes . '>' : '<' . $tag . $attributes . '>' . $inner . '</' . $tag . '>';
        }

        return $out;
    }

    /** @return string|null the attribute text (leading space included), or null when the element must not be written at all */
    private static function attributes(\DOMElement $element, string $tag): ?string
    {
        $out = '';
        $linked = false;
        $sourced = false;
        $allowed = self::ELEMENTS[$tag];

        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->nodeName);

            if (! in_array($name, $allowed, true)) {
                continue;
            }

            $value = $attribute->value;

            switch ($name) {
                case 'href':
                    $url = self::url($value, ['http', 'https', 'mailto', 'tel']);

                    if ($url !== null) {
                        $out .= ' href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
                        $linked = true;
                    }

                    break;

                case 'src':
                    $src = self::dataImage($value);

                    if ($src !== null) {
                        $out .= ' src="' . $src . '"';
                        $sourced = true;
                    }

                    break;

                case 'dir':
                    if (preg_match('/^(ltr|rtl|auto)$/i', trim($value)) === 1) {
                        $out .= ' dir="' . strtolower(trim($value)) . '"';
                    }

                    break;

                case 'style':
                    $style = self::style($value);

                    if ($style !== '') {
                        $out .= ' style="' . $style . '"';
                    }

                    break;

                case 'width':
                case 'height':
                    if (preg_match('/^\d{1,4}$/', trim($value)) === 1) {
                        $out .= ' ' . $name . '="' . trim($value) . '"';
                    }

                    break;

                case 'colspan':
                case 'rowspan':
                case 'start':
                    if (preg_match('/^\d{1,3}$/', trim($value)) === 1) {
                        $out .= ' ' . $name . '="' . trim($value) . '"';
                    }

                    break;

                case 'alt':
                case 'title':
                    $out .= ' ' . $name . '="' . htmlspecialchars(mb_substr(self::printable($value), 0, 300), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';

                    break;
            }
        }

        if ($tag === 'img' && ! $sourced) {   // (the first form searched the output for ` src="`, which an alt ending in ` src=` supplies)
            return null;
        }

        // a link that goes anywhere is opened without the opener, the referrer and the search engines' credit -- written LAST, so that the order of the output does not depend on the input's
        return $linked ? $out . ' rel="noopener noreferrer nofollow" target="_blank"' : $out;
    }

    /** @param list<string> $schemes */
    private static function url(string $value, array $schemes): ?string
    {
        $url = preg_replace(self::URL_NOISE, '', $value);

        if ($url === null || $url === '' || preg_match('/^([a-z][a-z0-9+.\-]*):/i', $url, $scheme) !== 1) {
            return null;
        }

        $scheme = strtolower($scheme[1]);

        if (! in_array($scheme, $schemes, true)) {
            return null;
        }

        // a web link names a host and NOTHING BUT a host before the path: `https:` alone, `https:///x`, `https:\\x` and a userinfo (`https://good.example@evil.example/`, which a person reads as
        // the first name and a browser as the second) are not links
        if (($scheme === 'http' || $scheme === 'https') && preg_match('#^https?://[^/?\#\\\\@\s]+(?:[/?\#]|$)#i', $url) !== 1) {
            return null;
        }

        return mb_substr($url, 0, 2048);
    }

    /**
     * A CSS colour -- a hex, a name or rgb()/rgba() -- or null. For the places that put a STORED colour into a `style` attribute (a badge's background): a colour that is not one is
     * dropped, so a value like `red;" onmouseover="alert(1)` cannot close the attribute.
     */
    public static function cssColor(?string $value): ?string
    {
        $value = trim((string) $value);

        return preg_match(self::COLOR, $value) === 1 ? strtolower(preg_replace('/\s+/', ' ', $value)) : null;
    }

    /** a text without the control characters libxml leaves in an attribute value (it drops them from text itself); tab, line feed and carriage return stay */
    private static function printable(string $text): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? '';
    }

    private static function dataImage(string $value): ?string
    {
        $value = preg_replace('/\s+/', '', $value);

        if (preg_match('#^data:image/(png|jpe?g|gif|webp);base64,([A-Za-z0-9+/]+={0,2})$#', (string) $value, $m) !== 1 || strlen($m[2]) > 1500000) {
            return null;
        }

        return 'data:image/' . $m[1] . ';base64,' . $m[2];
    }

    /** the declarations of a style attribute that are on the list and match their pattern, re-written; nothing else */
    private static function style(string $value): string
    {
        $kept = [];

        foreach (explode(';', $value) as $declaration) {
            $pair = explode(':', $declaration, 2);

            if (count($pair) !== 2) {
                continue;
            }

            $property = strtolower(trim($pair[0]));
            $setting = trim($pair[1]);

            if (isset(self::STYLE[$property]) && preg_match(self::STYLE[$property], $setting) === 1) {
                $kept[$property] = $property . ': ' . strtolower(preg_replace('/\s+/', ' ', $setting));
            }
        }

        return htmlspecialchars(implode('; ', $kept), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
