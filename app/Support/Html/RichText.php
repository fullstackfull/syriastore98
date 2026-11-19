<?php

namespace App\Support\Html;

/**
 * An ALLOWLIST sanitizer for the rich text a person types into an editor (a ticket's description, a reply) and somebody else's browser prints.  P1-SEC-75.
 *
 * WHY IT EXISTS. The vendored Helpdesk views print a ticket's description and every reply with `{!! !!}`, because the editor produces markup. The text is a merchant's; the
 * page is the platform operator's. There is no HTML purifier in vendor/ and the project's input filter (`XSS` middleware) is a substring blocklist on an opt-in alias. So the
 * output is cleaned where it is printed -- by a host-side override of the two views -- and this is what cleans it.
 *
 * HOW. The input is parsed (libxml's HTML parser, no network), and a NEW string is built from the parse tree: an element only if it is on the list below, an attribute only if the
 * element lists it and its value passes that attribute's own check, every text node and every attribute value entity-escaped. Nothing of the input is copied through: not a
 * tag, not an attribute, not a comment, not a style declaration. So a parser that disagrees with a browser cannot matter in the way that makes sanitisers fail (the cleaned
 * output re-parsed as something else): the output contains no raw-text element, no namespace switch (`svg`, `math`), no `<` or `>` or `&` or quote that was not escaped by
 * this class, and so no way to be read as anything but the markup this class wrote.
 *
 * WHAT IS KEPT: paragraphs, line breaks, rules, emphasis, lists, quotes, code, headings, tables, `a` (http, https, mailto, tel only; opened with rel=noopener noreferrer nofollow),
 * `img` (a base64 png, jpeg, gif or webp DATA url only: an editor pastes pictures that way, and any other source is a request the operator's browser would make with the
 * operator's cookies), and a short list of presentation: `dir`, and `style` reduced to alignment, direction, colour, weight, style, decoration and size, each value matched
 * against a pattern and re-written, never copied. WHAT IS DROPPED: every other element (its text is kept; `script`, `style`, `iframe` and the like lose their content too),
 * every other attribute (so every `on*` handler, `class`, `id`, `name`, `srcset`, `xlink:href`, `formaction`), comments, processing instructions, CDATA.
 *
 * LIMITS. The input is cut at MAX_BYTES and the tree is followed to MAX_DEPTH levels: an editor's output is a few kilobytes, and a hostile one is not owed a stack.
 * If the parser cannot read the input at all the result is the input ESCAPED as text, never the input.
 */
final class RichText
{
    public const MAX_BYTES = 262144;
    public const MAX_DEPTH = 32;

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

        return $body === null ? '' : self::children($body, 0);
    }

    private static function children(\DOMNode $parent, int $depth): string
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

            $inner = self::children($node, $depth + 1);

            // an element that is not on the list, and every element past the depth limit, is unwrapped: its (escaped) text stays, its tag does not
            if (! isset(self::ELEMENTS[$tag]) || $depth >= self::MAX_DEPTH) {
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
                    $out .= ' ' . $name . '="' . htmlspecialchars(mb_substr($value, 0, 300), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';

                    break;
            }
        }

        if ($tag === 'img' && ! str_contains($out, ' src="')) {
            return null;
        }

        // a link that goes anywhere is opened without the opener, the referrer and the search engines' credit -- written LAST, so that the order of the output does not depend on the input's
        return $linked ? $out . ' rel="noopener noreferrer nofollow" target="_blank"' : $out;
    }

    /** @param list<string> $schemes */
    public static function url(string $value, array $schemes): ?string
    {
        $url = preg_replace(self::URL_NOISE, '', $value);

        if ($url === null || $url === '' || preg_match('/^([a-z][a-z0-9+.\-]*):/i', $url, $scheme) !== 1) {
            return null;
        }

        $scheme = strtolower($scheme[1]);

        if (! in_array($scheme, $schemes, true)) {
            return null;
        }

        // a web link names a host; `https:` alone, `https:///x` and `https:\\x` are not links
        if (($scheme === 'http' || $scheme === 'https') && preg_match('#^https?://[^/?\#\\\\@\s]+#i', $url) !== 1) {
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

        return preg_match(self::COLOR, $value) === 1 ? strtolower($value) : null;
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
                $kept[$property] = $property . ': ' . strtolower($setting);
            }
        }

        return htmlspecialchars(implode('; ', $kept), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
