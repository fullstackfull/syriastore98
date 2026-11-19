<?php

namespace Tests\Unit\Support\Html;

use App\Support\Html\RichText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Security\RichTextCorpus;

/**
 * P1-SEC-75 -- the allowlist sanitizer behind the two Helpdesk views that print a ticket's rich text.
 * ====================================================================================================
 *
 * The vendored views print a ticket's description and every reply with `{!! !!}`. The text is a merchant's, the page is the platform operator's. This class builds a NEW string
 * from the parse of the input -- an element only if it is on its list, an attribute only if the element lists it and the value passes that attribute's own check, every text node
 * and attribute value entity-escaped -- so what a test has to show is the CONTRACT of that output, not the cleverness of a filter.
 *
 * THE CONTRACT IS WRITTEN HERE A SECOND TIME, on purpose (ALLOWED below): the class's own lists are not the oracle, because a test that asked the class what it allows would pass for any
 * list. Widening the class's lists fails this file until somebody widens this one and says why.
 *
 * ORACLE for the corpus cases: the output is parsed again and every node of it is checked against ALLOWED and against the value rules. `Tests\Support\Security\RichTextCorpus` is the
 * input (hand-written evasions plus seeded random tag soup); tests/Support/Security/bin/verify-rich-text-in-chromium.php runs the same corpus through a real browser, which is the evidence
 * that a parser disagreement does not matter. A string search for `<script` in the output is NOT used as an oracle: an `alt` attribute may legitimately CONTAIN the characters it escaped.
 */
final class RichTextTest extends TestCase
{
    private const ALLOWED = [
        'p' => ['dir', 'style'], 'div' => ['dir', 'style'], 'span' => ['dir', 'style'], 'br' => [], 'hr' => [],
        'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 's' => [], 'strike' => [], 'del' => [], 'ins' => [], 'sub' => [], 'sup' => [], 'small' => [], 'mark' => [],
        'ul' => ['dir'], 'ol' => ['dir', 'start'], 'li' => ['dir'], 'blockquote' => ['dir'], 'pre' => [], 'code' => [],
        'h1' => ['dir', 'style'], 'h2' => ['dir', 'style'], 'h3' => ['dir', 'style'], 'h4' => ['dir', 'style'], 'h5' => ['dir', 'style'], 'h6' => ['dir', 'style'],
        'table' => ['dir'], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [], 'th' => ['colspan', 'rowspan', 'style'], 'td' => ['colspan', 'rowspan', 'style'],
        'a' => ['href', 'title', 'rel', 'target'], 'img' => ['src', 'alt', 'title', 'width', 'height'],
    ];

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAHnOcQAAAAABJRU5ErkJggg==';

    /** @return array<string, array{string, string}> what an editor produces comes through, re-written */
    public static function whatIsKept(): array
    {
        return [
            'paragraph, emphasis and an entity'  => ['<p>Hello <b>world</b> &amp; <i>you</i></p>', '<p>Hello <b>world</b> &amp; <i>you</i></p>'],
            'line break and rule'                => ['a<br>b<hr>c', 'a<br>b<hr>c'],
            'a list'                             => ['<ul><li>a</li><li><b>b</b></li></ul>', '<ul><li>a</li><li><b>b</b></li></ul>'],
            'an ordered list that starts at 3'   => ['<ol start="3"><li>x</li></ol>', '<ol start="3"><li>x</li></ol>'],
            'a table'                            => ['<table><thead><tr><th colspan="2">h</th></tr></thead><tbody><tr><td>1</td><td rowspan="2">2</td></tr></tbody></table>', '<table><thead><tr><th colspan="2">h</th></tr></thead><tbody><tr><td>1</td><td rowspan="2">2</td></tr></tbody></table>'],
            'preformatted code'                  => ['<pre><code>&lt;b&gt;x&lt;/b&gt;</code></pre>', '<pre><code>&lt;b&gt;x&lt;/b&gt;</code></pre>'],
            'a heading and a quote'              => ['<h2>T</h2><blockquote>q</blockquote>', '<h2>T</h2><blockquote>q</blockquote>'],
            'a web link'                         => ['<a href="https://example.com/a?b=1&amp;c=2" title="t">x</a>', '<a href="https://example.com/a?b=1&amp;c=2" title="t" rel="noopener noreferrer nofollow" target="_blank">x</a>'],
            'a mail link and a phone link'       => ['<a href="mailto:a@b.co">m</a><a href="tel:+963123456">t</a>', '<a href="mailto:a@b.co" rel="noopener noreferrer nofollow" target="_blank">m</a><a href="tel:+963123456" rel="noopener noreferrer nofollow" target="_blank">t</a>'],
            'Arabic text, right to left'         => ['<p dir="rtl" style="text-align:right;color:#FFF">مرحبا بالعالم</p>', '<p dir="rtl" style="text-align: right; color: #fff">مرحبا بالعالم</p>'],
            'a pasted picture'                   => ['<img src="data:image/png;base64,' . self::PNG . '" alt="a &quot;b&quot;" width="10" height="5">', '<img src="data:image/png;base64,' . self::PNG . '" alt="a &quot;b&quot;" width="10" height="5">'],
            'a bare less-than and ampersand'     => ['a < b && c > d', 'a &lt; b &amp;&amp; c &gt; d'],
            'markup written as text stays text'  => ['&lt;script&gt;alert(1)&lt;/script&gt;', '&lt;script&gt;alert(1)&lt;/script&gt;'],
            'an unknown wrapper keeps its text'  => ['<font color="red"><center>hi</center></font>', 'hi'],
            'declarations that are on the list'  => ['<span style="font-weight: BOLD; font-size: 14px; text-decoration: underline; direction: rtl; background-color: rgb(1, 2, 3)">s</span>', '<span style="font-weight: bold; font-size: 14px; text-decoration: underline; direction: rtl; background-color: rgb(1, 2, 3)">s</span>'],
            'nothing'                            => ['', ''],
        ];
    }

    #[DataProvider('whatIsKept')]
    public function test_what_an_editor_produces_comes_through(string $in, string $expected): void
    {
        $this->assertSame($expected, RichText::clean($in));
    }

    public function test_a_null_is_nothing_and_a_number_is_text(): void
    {
        $this->assertSame('', RichText::clean(null));
        $this->assertSame('123', RichText::clean('123'));
    }

    /** @return array<string, array{string, string}> what runs, requests or navigates does not come through; the text around it does */
    public static function whatIsRemoved(): array
    {
        return [
            'a script and its code'              => ['a<script>alert(1)</script>b', 'ab'],
            'an image handler'                   => ['<img src=x onerror=alert(1)>', ''],
            'an image with a handler and a data source' => ['<img src="data:image/png;base64,' . self::PNG . '" onerror=alert(1)>', '<img src="data:image/png;base64,' . self::PNG . '">'],
            'an svg that loads'                  => ['<svg onload=alert(1)><circle/></svg>t', 't'],
            'a script URL'                       => ['<a href="javascript:alert(1)">x</a>', '<a>x</a>'],
            'a script URL in mixed case'         => ['<a href="  JaVaScRiPt:alert(1)">x</a>', '<a>x</a>'],
            'a script URL with a tab spelled as an entity' => ['<a href="java&#x09;script:alert(1)">x</a>', '<a>x</a>'],
            'a script URL spelled with entities' => ['<a href="&#106;avascript:alert(1)">x</a>', '<a>x</a>'],
            'a script URL after a control byte'  => ["<a href=\"\x01javascript:alert(1)\">x</a>", '<a>x</a>'],
            'a vbscript URL'                     => ['<a href="vbscript:msgbox(1)">x</a>', '<a>x</a>'],
            'a data page'                        => ['<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>', '<a>x</a>'],
            'a protocol-relative link'           => ['<a href="//evil.example/x">x</a>', '<a>x</a>'],
            'a relative link'                    => ['<a href="/admin/users/1/delete">x</a>', '<a>x</a>'],
            'a web link with no host'            => ['<a href="https:///x">x</a>', '<a>x</a>'],
            'a link with a handler'              => ['<a href="https://example.com" onclick="x()" onmouseover=alert(1)>ok</a>', '<a href="https://example.com" rel="noopener noreferrer nofollow" target="_blank">ok</a>'],
            'a frame'                            => ['<iframe src="javascript:alert(1)"></iframe>after', 'after'],
            'a frame with a document'            => ['<iframe srcdoc="<script>alert(1)</script>"></iframe>', ''],
            'an object and an embed'             => ['<object data="javascript:alert(1)"></object><embed src="x">', ''],
            'a stylesheet link and an import'    => ['<link rel=stylesheet href="https://evil.example/x.css"><style>@import "https://evil.example/x.css";</style>', ''],
            'a form and its buttons'             => ['<form action="javascript:alert(1)"><button formaction="javascript:alert(1)">x</button><input autofocus onfocus=alert(1)></form>', ''],
            'a toggle handler'                   => ['<details open ontoggle=alert(1)>x</details>', 'x'],
            'a body handler'                     => ['<body onload=alert(1)>t', 't'],
            'a style with a script URL'          => ['<div style="background:url(javascript:alert(1))">x</div>', '<div>x</div>'],
            'a style with an expression'         => ['<div style="width:expression(alert(1))">x</div>', '<div>x</div>'],
            'a style with a request'             => ['<p style="color:red;background:url(//evil.example/x)">x</p>', '<p style="color: red">x</p>'],
            'a style hiding a second declaration' => ['<div style=\'color:"red;x:y"\'>x</div>', '<div>x</div>'],
            'a picture from another host'        => ['<img src="https://evil.example/beacon.png">', ''],
            'a picture from this one'            => ['<img src="/admin/delete/1">', ''],
            'a scalable picture'                 => ['<img src="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=">', ''],
            'a picture set'                      => ['<img srcset="https://evil.example/a.png 1x">', ''],
            'a picture whose data is not base64' => ['<img src="data:image/png;base64,###">', ''],
            'mutation: noscript'                 => ['<noscript><p title="</noscript><img src=x onerror=alert(1)>">', ''],
            'mutation: svg and style'            => ['<svg></p><style><a id="</style><img src=1 onerror=alert(1)>">', ''],
            'mutation: xmp'                      => ['<xmp><img src=x onerror=alert(1)></xmp>', ''],
            'mutation: textarea and title'       => ['<textarea></textarea><img src=x onerror=alert(1)><title></title><img src=x onerror=alert(1)>', ''],
            'a comment that swallows a tag'      => ['<!--><img src=x onerror=alert(1)>-->t', 't'],
            'a conditional comment'              => ['<!--[if IE]><img src=x onerror=alert(1)><![endif]-->t', 't'],
            'a null byte inside a tag name'      => ["<scr\0ipt>alert(1)</scr\0ipt>", ''],
            'a tag that is never closed'         => ['<img src=x onerror=alert(1)//', ''],
            'a meta refresh and a base'          => ['<meta http-equiv="refresh" content="0;url=https://evil.example"><base href="https://evil.example/">t', 't'],
            'attributes that are not on the list' => ['<a name=x id=y class=z data-x=1 href="https://example.com/">x</a>', '<a href="https://example.com/" rel="noopener noreferrer nofollow" target="_blank">x</a>'],
        ];
    }

    #[DataProvider('whatIsRemoved')]
    public function test_what_runs_or_requests_does_not_come_through(string $in, string $expected): void
    {
        $this->assertSame($expected, RichText::clean($in));
    }

    /** @return array<string, array{string, bool}> */
    public static function urls(): array
    {
        return [
            'https' => ['https://example.com/a?b=c#d', true], 'http' => ['http://example.com', true], 'mixed case scheme' => ['HtTpS://example.com', true], 'mail' => ['mailto:a@b.co', true], 'phone' => ['tel:+1234', true],
            'script' => ['javascript:alert(1)', false], 'script, upper' => ['JAVASCRIPT:alert(1)', false], 'script with a tab inside' => ["java\tscript:alert(1)", false], 'script with a newline inside' => ["java\nscript:alert(1)", false],
            'script after control bytes' => ["\x00\x01javascript:alert(1)", false], 'script after a zero-width space' => ["\u{200B}javascript:alert(1)", false], 'script after a no-break space' => ["\u{00A0}javascript:alert(1)", false],
            'script after a byte-order mark' => ["\u{FEFF}javascript:alert(1)", false], 'script with a soft hyphen inside' => ["java\u{00AD}script:alert(1)", false], 'vbscript' => ['vbscript:x', false],
            'data' => ['data:text/html,x', false], 'file' => ['file:///etc/passwd', false], 'ftp' => ['ftp://example.com', false], 'protocol-relative' => ['//example.com', false], 'relative' => ['/x', false], 'bare word' => ['example.com', false],
            'web link without a host' => ['https://', false], 'three slashes' => ['https:///x', false], 'backslashes' => ['https:\\\\example.com', false], 'userinfo only' => ['https://@', false], 'empty' => ['', false], 'spaces only' => ['   ', false],
        ];
    }

    #[DataProvider('urls')]
    public function test_which_urls_are_links(string $url, bool $accepted): void
    {
        $this->assertSame($accepted, RichText::url($url, ['http', 'https', 'mailto', 'tel']) !== null, json_encode($url, JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function test_a_link_is_written_without_the_characters_a_browser_would_ignore(): void
    {
        $this->assertSame('https://example.com/a', RichText::url("ht\ttps://exam\nple.com/a", ['https']));
    }

    /** every input of the corpus, written out and read back */
    public static function corpus(): array
    {
        $rows = [];

        foreach (RichTextCorpus::hostile() as $i => $html) {
            $rows['hand-written ' . $i] = [$html];
        }

        foreach (RichTextCorpus::soup(900, 11) as $i => $html) {
            $rows['soup ' . $i] = [$html];
        }

        return $rows;
    }

    #[DataProvider('corpus')]
    public function test_the_output_holds_nothing_that_is_not_on_the_lists(string $in): void
    {
        $this->assertSame([], self::offences(RichText::clean($in)), json_encode($in, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE));
    }

    #[DataProvider('corpus')]
    public function test_cleaning_twice_changes_nothing(string $in): void
    {
        $once = RichText::clean($in);

        $this->assertSame($once, RichText::clean($once));
    }

    public function test_the_checker_can_see_an_offence(): void
    {
        // the oracle above is only worth anything if it fails on what it is there to find
        foreach (['<script>x</script>', '<img src=x>', '<a href="javascript:x">y</a>', '<p onclick="x()">y</p>', '<div style="background:url(x)">y</div>', '<svg></svg>', '<a href="//x">y</a>', '<p style="color:red;color:blue;x:y">y</p>'] as $bad) {
            $this->assertNotSame([], self::offences($bad), $bad);
        }

        $this->assertSame([], self::offences('<p dir="rtl" style="color: red">ok <a href="https://example.com/" rel="noopener noreferrer nofollow" target="_blank">x</a></p>'));
    }

    public function test_input_beyond_the_limit_is_cut_not_trusted(): void
    {
        $long = str_repeat('a', RichText::MAX_BYTES) . '<script>alert(1)</script><b>after</b>';

        $out = RichText::clean($long);

        $this->assertSame(RichText::MAX_BYTES, strlen($out), 'the text is cut at the limit and nothing after it is read');
        $this->assertStringNotContainsString('after', $out);
    }

    public function test_nesting_beyond_the_limit_keeps_the_text_and_nothing_else(): void
    {
        // 100 pairs are 200 levels: inside what libxml itself follows (255), beyond what this class writes (32)
        $deep = str_repeat('<div><b>', 100) . 'core<script>alert(1)</script>' . str_repeat('</b></div>', 100);

        $out = RichText::clean($deep);

        $this->assertStringContainsString('core', $out);
        $this->assertStringNotContainsString('alert', $out);
        $this->assertSame([], self::offences($out));
        $this->assertSame(RichText::MAX_DEPTH / 2, substr_count($out, '<div>'), 'past the depth limit an element is unwrapped, not written');
    }

    public function test_nesting_beyond_what_the_parser_follows_is_not_fatal(): void
    {
        $out = RichText::clean(str_repeat('<div><b>', 400) . 'core' . str_repeat('</b></div>', 400));

        $this->assertSame([], self::offences($out));
    }

    public function test_invalid_utf8_and_null_bytes_are_neither_fatal_nor_passed_through(): void
    {
        $out = RichText::clean("<p>caf\xC3 \xFF\xFE ok\0</p>");

        $this->assertSame([], self::offences($out));
        $this->assertStringNotContainsString("\0", $out);
        $this->assertStringContainsString('ok', $out);
        $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
    }

    public function test_a_quote_in_an_attribute_cannot_close_it(): void
    {
        $out = RichText::clean('<a href="https://example.com/&quot; onmouseover=&quot;alert(1)" title="x&quot; onfocus=&quot;y">t</a><img src="data:image/png;base64,' . self::PNG . '" alt=\'a" onerror="b\'>');

        $this->assertSame([], self::offences($out));
        $this->assertSame(0, preg_match('/\sonmouseover=|\sonfocus=|\sonerror=/', preg_replace('/"[^"]*"/', '""', $out)), 'no handler exists outside a quoted value');
    }

    /** @return list<string> what in $html is not allowed: an element, an attribute or a value */
    private static function offences(string $html): array
    {
        $found = [];
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach ($document->getElementsByTagName('body')->item(0)->getElementsByTagName('*') as $element) {
            $tag = $element->nodeName;

            if (! isset(self::ALLOWED[$tag])) {
                $found[] = "element <{$tag}>";

                continue;
            }

            foreach ($element->attributes as $attribute) {
                $name = $attribute->nodeName;
                $value = $attribute->value;

                if (! in_array($name, self::ALLOWED[$tag], true)) {
                    $found[] = "attribute {$tag}@{$name}";
                } elseif ($name === 'href' && preg_match('#^(https?://[^/?\#\\\\@\s]+|mailto:|tel:)#i', $value) !== 1) {
                    $found[] = "href {$value}";
                } elseif ($name === 'src' && preg_match('#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/]+={0,2}$#', $value) !== 1) {
                    $found[] = "src {$value}";
                } elseif ($name === 'style' && preg_match('/^((text-align|direction|color|background-color|font-weight|font-style|text-decoration|font-size): [a-z0-9#%.(), ]+(; |$))+$/', $value) !== 1) {
                    $found[] = "style {$value}";
                } elseif ($name === 'dir' && ! in_array($value, ['ltr', 'rtl', 'auto'], true)) {
                    $found[] = "dir {$value}";
                } elseif (in_array($name, ['width', 'height', 'colspan', 'rowspan', 'start'], true) && preg_match('/^\d{1,4}$/', $value) !== 1) {
                    $found[] = "number {$name}={$value}";
                }
            }

            if ($tag === 'a' && $element->hasAttribute('href') && ($element->getAttribute('rel') !== 'noopener noreferrer nofollow' || $element->getAttribute('target') !== '_blank')) {
                $found[] = 'a link that is not marked rel/target';
            }

            if ($tag === 'img' && ! $element->hasAttribute('src')) {
                $found[] = 'img without a source';
            }
        }

        return $found;
    }
}
