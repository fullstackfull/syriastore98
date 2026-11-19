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
 * THE CONTRACT IS WRITTEN HERE A SECOND TIME, on purpose (ALLOWED and KEPT below): the class's own lists are not the oracle, because a test that asked the class what it allows would pass for any
 * list. Widening the class's lists fails this file until somebody widens this one and says why -- and that is TRUE only because of two tests that make it so, which the first version of this
 * sentence lacked (a reviewer ran 94 widenings and loosenings against it and more than half passed): the class's own constants are compared with this file's by reflection, and every
 * element is offered every attribute any element keeps (and some that none does) in a generated cross-product.
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

    /** what the class KEEPS of each element: its ELEMENTS constant, written out a second time (ALLOWED is what the OUTPUT may hold: `a` also carries the rel and target the class writes) */
    private const KEPT = [
        'p' => ['dir', 'style'], 'div' => ['dir', 'style'], 'span' => ['dir', 'style'], 'br' => [], 'hr' => [],
        'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 's' => [], 'strike' => [], 'del' => [], 'ins' => [], 'sub' => [], 'sup' => [], 'small' => [], 'mark' => [],
        'ul' => ['dir'], 'ol' => ['dir', 'start'], 'li' => ['dir'], 'blockquote' => ['dir'], 'pre' => [], 'code' => [],
        'h1' => ['dir', 'style'], 'h2' => ['dir', 'style'], 'h3' => ['dir', 'style'], 'h4' => ['dir', 'style'], 'h5' => ['dir', 'style'], 'h6' => ['dir', 'style'],
        'table' => ['dir'], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [], 'th' => ['colspan', 'rowspan', 'style'], 'td' => ['colspan', 'rowspan', 'style'],
        'a' => ['href', 'title'], 'img' => ['src', 'alt', 'title', 'width', 'height'],
    ];

    /** the elements whose CONTENT is dropped with them */
    private const DROPPED = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'noscript', 'noframes', 'noembed', 'template', 'title', 'head', 'xmp', 'plaintext', 'listing',
        'svg', 'math', 'textarea', 'select', 'option', 'optgroup', 'button', 'input', 'form', 'link', 'meta', 'base', 'audio', 'video', 'source', 'track', 'canvas', 'map', 'area', 'dialog', 'portal',
    ];

    /** the declarations a `style` may carry: property => [values that are kept, values that are not] */
    private const STYLES = [
        'text-align'       => [['left', 'RIGHT', 'center', 'justify', 'start', 'end'], ['left !important', 'url(x)', 'inherit', 'middle', '']],
        'direction'        => [['ltr', 'RTL'], ['ltr !important', 'inherit', 'auto']],
        'color'            => [['#fff', '#FF8800', '#ff880080', 'red', 'Tomato', 'rgb(1, 2, 3)', 'rgba(1,2,3,0.5)'], ['url(x)', 'expression(x)', 'red !important', '#ggg', 'ab', 'a', 'rgb(1,2)', 'rgb(1,2,3,4)', 'var(--x)']],
        'background-color' => [['#fff', 'red', 'rgb(1, 2, 3)'], ['url(x)', 'expression(x)', 'red !important', '#12', 'rgb(1)', 'attr(x)']],
        'font-weight'      => [['bold', 'BOLD', 'normal', '700', '900', 'bolder', 'lighter'], ['1000', '650', 'bold !important', 'boldd', '', 'inherit']],
        'font-style'       => [['italic', 'normal', 'oblique'], ['slanted', 'italic !important', 'inherit']],
        'text-decoration'  => [['none', 'underline', 'line-through', 'underline line-through', 'line-through underline'], ['overline', 'underline dotted', 'blink', 'underline !important', 'underline underline']],
        'font-size'        => [['12px', '14pt', '1.5em', '2rem', '100%', '999px'], ['12', '12vw', '1000px', '12px !important', 'calc(1px)', 'large', '1.555px', '-1px']],
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

    /** @return array<string, array{?string, ?string}> a stored colour that is one, and what is written for it; and a value that is not, which is NOTHING */
    public static function colours(): array
    {
        return [
            'a hex colour, written in lower case' => ['#FF8800', '#ff8800'],
            'a short hex colour'                  => ['#fff', '#fff'],
            'a hex colour with an alpha'          => ['#ff880080', '#ff880080'],
            'a colour name'                       => ['Red', 'red'],
            'rgb'                                 => ['rgb(1, 2, 3)', 'rgb(1, 2, 3)'],
            'rgba'                                => ['rgba(1,2,3,0.5)', 'rgba(1,2,3,0.5)'],
            'surrounding space'                   => ['  #fff ', '#fff'],
            'a null'                              => [null, null],
            'an empty string'                     => ['', null],
            'a quote that would close the attribute'      => ['red;" onmouseover="alert(1)', null],
            'a second declaration'                        => ['red; background: url(x)', null],
            'a url'                                       => ['url(javascript:alert(1))', null],
            'a script URL'                                => ['javascript:alert(1)', null],
            'a function that is not a colour'             => ['expression(alert(1))', null],
            'a hex of two digits'                         => ['#ff', null],
            'a run of letters longer than any colour name' => [str_repeat('a', 21), null],
            'an rgb with a fourth channel'                => ['rgb(1,2,3,4)', null],
        ];
    }

    #[DataProvider('colours')]
    public function test_a_colour_is_written_when_it_is_one_and_dropped_when_it_is_not(?string $in, ?string $expected): void
    {
        $this->assertSame($expected, RichText::cssColor($in));
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

    /** the URL rule is the class's own business (it is private); what it decides is seen through the link the class writes, or does not */
    #[DataProvider('urls')]
    public function test_which_urls_are_links(string $url, bool $accepted): void
    {
        $out = RichText::clean('<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">x</a>');

        $this->assertSame($accepted, str_contains($out, 'href="'), json_encode($url, JSON_INVALID_UTF8_SUBSTITUTE) . ' -> ' . $out);
    }

    public function test_a_link_is_written_without_the_characters_a_browser_would_ignore(): void
    {
        $this->assertStringContainsString('href="https://example.com/a"', RichText::clean("<a href=\"ht\ttps://exam\nple.com/a\">x</a>"));
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

    // ---- the lists are the lists, and everything off them is refused ------------------------------------

    /** @return array<string, mixed> the class's own constants, private ones included */
    private static function constantsOfTheClass(): array
    {
        return (new \ReflectionClass(RichText::class))->getConstants();
    }

    public function test_the_class_keeps_exactly_what_this_file_says_it_keeps(): void
    {
        $class = self::constantsOfTheClass();

        $elements = $class['ELEMENTS'];
        ksort($elements);
        foreach ($elements as &$attributes) {
            sort($attributes);
        }
        unset($attributes);

        $expected = self::KEPT;
        ksort($expected);
        foreach ($expected as &$attributes) {
            sort($attributes);
        }
        unset($attributes);

        $this->assertSame($expected, $elements, 'The class keeps a different set of elements or attributes than this file says. Widening it is a decision: write it down here, with the reason, in KEPT and ALLOWED.');
        $this->assertEqualsCanonicalizing(self::DROPPED, $class['DROP_WITH_CONTENT'], 'The elements dropped WITH their content are not the ones this file lists.');
        $this->assertSame(['br' => true, 'hr' => true, 'img' => true], $class['VOID']);
        $this->assertEqualsCanonicalizing(array_keys(self::STYLES), array_keys($class['STYLE']), 'The class allows a different set of style properties than this file lists (a `position` here is what a page-covering overlay needs).');
        $this->assertSame(32, $class['MAX_DEPTH']);
        $this->assertSame(2097152, $class['MAX_BYTES']);
        $this->assertSame(400, $class['MAX_TAG']);

        // ALLOWED is the output's side of KEPT: the same, plus what the class itself writes on a link
        foreach (self::ALLOWED as $element => $attributes) {
            $this->assertEqualsCanonicalizing($element === 'a' ? array_merge(self::KEPT['a'], ['rel', 'target']) : self::KEPT[$element], $attributes, $element);
        }
    }

    /** @return array<string, array{string, string, string, bool}> an element, an attribute, a value of the SHAPE that attribute takes, and whether the element may keep it */
    public static function everyElementWithEveryAttribute(): array
    {
        $values = [
            // what some element keeps
            'dir' => 'rtl', 'style' => 'color: red', 'start' => '3', 'colspan' => '2', 'rowspan' => '2', 'href' => 'https://example.com/', 'title' => 'a title', 'src' => 'data:image/png;base64,' . self::PNG,
            'alt' => 'an alt', 'width' => '10', 'height' => '5',
            // what no element keeps, with a value that is harmless in itself: it must never come out
            'class' => 'x', 'id' => 'x', 'name' => 'x', 'lang' => 'ar', 'onclick' => 'x()', 'onerror' => 'x()', 'onload' => 'x()', 'srcset' => 'a.png 1x', 'target' => '_self', 'rel' => 'opener', 'data-x' => '1',
            'tabindex' => '0', 'contenteditable' => 'true', 'draggable' => 'true', 'accesskey' => 'x', 'formaction' => 'https://example.com/', 'xlink:href' => 'https://example.com/', 'background' => 'x',
            'poster' => 'x', 'ping' => 'x', 'action' => 'https://example.com/', 'content' => 'x', 'http-equiv' => 'refresh', 'is' => 'x', 'slot' => 'x', 'hidden' => 'hidden', 'align' => 'center', 'bgcolor' => 'red',
        ];
        $rows = [];

        foreach (self::KEPT as $element => $kept) {
            foreach ($values as $attribute => $value) {
                $rows["{$element}@{$attribute}"] = [$element, $attribute, $value, in_array($attribute, $kept, true)];
            }
        }

        return $rows;
    }

    #[DataProvider('everyElementWithEveryAttribute')]
    public function test_an_element_keeps_the_attributes_on_its_list_and_no_other(string $element, string $attribute, string $value, bool $kept): void
    {
        $picture = $element === 'img' ? ' src="data:image/png;base64,' . self::PNG . '"' : '';   // a picture with no acceptable source is not written at all
        $out = RichText::clean("<{$element}{$picture} {$attribute}=\"" . htmlspecialchars($value, ENT_QUOTES) . "\">t</{$element}>");

        if ($kept) {
            $this->assertStringContainsString(" {$attribute}=\"" . htmlspecialchars($value, ENT_QUOTES) . '"', $out, "{$element} keeps {$attribute}");
        } else {
            $this->assertStringNotContainsString(" {$attribute}=", $out, "{$element} must not keep {$attribute}: " . $out);
        }
    }

    /** @return array<string, array{string, string, bool}> */
    public static function declarations(): array
    {
        $rows = [];

        foreach (self::STYLES as $property => [$kept, $refused]) {
            foreach ($kept as $value) {
                $rows["{$property}: {$value}"] = [$property, $value, true];
            }

            foreach ($refused as $value) {
                $rows["{$property}: {$value} (refused)"] = [$property, $value, false];
            }
        }

        return $rows;
    }

    #[DataProvider('declarations')]
    public function test_a_style_declaration_is_kept_when_it_matches_its_pattern_and_refused_when_it_does_not(string $property, string $value, bool $kept): void
    {
        $out = RichText::clean('<p style="' . htmlspecialchars($property . ': ' . $value, ENT_QUOTES) . '">x</p>');

        if ($kept) {
            $this->assertSame('<p style="' . $property . ': ' . strtolower(preg_replace('/\s+/', ' ', $value)) . '">x</p>', $out);
        } else {
            $this->assertSame('<p>x</p>', $out);
        }
    }

    /** @return array<string, array{string, string}> an attribute with a value that is not of its shape: the picture or the cell is written, the attribute is not */
    public static function valuesOfTheWrongShape(): array
    {
        $rows = [];

        foreach (['1" onload="alert(1)', '12345', '-1', '1e3', '0x10', '10px', '', 'ten', '1.5'] as $value) {
            foreach (['width', 'height'] as $attribute) {
                $rows["img {$attribute}={$value}"] = ['<img src="data:image/png;base64,' . self::PNG . '" ' . $attribute . '="' . htmlspecialchars($value, ENT_QUOTES) . '">', '<img src="data:image/png;base64,' . self::PNG . '">'];
            }
        }

        foreach (['1000', '-1', '2" x="', 'two', '1.5', ''] as $value) {
            foreach (['colspan', 'rowspan'] as $attribute) {
                $rows["td {$attribute}={$value}"] = ['<td ' . $attribute . '="' . htmlspecialchars($value, ENT_QUOTES) . '">x</td>', '<td>x</td>'];
            }

            $rows["ol start={$value}"] = ['<ol start="' . htmlspecialchars($value, ENT_QUOTES) . '"><li>x</li></ol>', '<ol><li>x</li></ol>'];
        }

        foreach (['sideways', 'ltr" onclick="x', '', 'RTL ltr'] as $value) {
            $rows["p dir={$value}"] = ['<p dir="' . htmlspecialchars($value, ENT_QUOTES) . '">x</p>', '<p>x</p>'];
        }

        return $rows;
    }

    #[DataProvider('valuesOfTheWrongShape')]
    public function test_a_value_that_is_not_of_its_attributes_shape_is_not_written(string $in, string $expected): void
    {
        $this->assertSame($expected, RichText::clean($in));
    }

    public function test_a_direction_is_written_in_lower_case_and_trimmed(): void
    {
        $this->assertSame('<p dir="ltr">x</p><p dir="rtl">y</p><p dir="auto">z</p>', RichText::clean('<p dir=" LTR ">x</p><p dir="rtl">y</p><p dir="Auto">z</p>'));
    }

    public function test_a_title_and_an_alt_lose_control_characters_and_are_cut_at_three_hundred(): void
    {
        $out = RichText::clean("<img src=\"data:image/png;base64," . self::PNG . "\" alt=\"a\x01b\x7Fc\x1Fd\te\nf\" title=\"" . str_repeat('é', 400) . '">');

        $this->assertStringContainsString("alt=\"abcd\te\nf\"", $out, 'Control characters go; tab and line feed stay.');
        $this->assertStringContainsString('title="' . str_repeat('é', 300) . '"', $out);
        $this->assertStringNotContainsString(str_repeat('é', 301), $out);
    }

    public function test_whitespace_inside_a_colour_is_one_space(): void
    {
        $this->assertSame('<p style="color: rgb(1, 2, 3)">x</p>', RichText::clean("<p style=\"color: rgb(1,\f2,\v3)\">x</p>"));
        $this->assertSame('rgb(1, 2, 3)', RichText::cssColor("rgb(1,\n2,\t3)"));
    }

    // ---- what the parser is not asked to read -----------------------------------------------------------

    /** @return array<string, array{string}> start tags that crowd, in the shapes libxml would count attributes in -- and some it would not, which are counted all the same */
    public static function crowdedTags(): array
    {
        $n = 5000;
        $names = fn (string $separator) => implode($separator, array_map(fn ($i) => "a{$i}", range(1, $n)));

        return [
            'spaces'                        => ['<p ' . $names(' ') . '>x</p>'],
            'tabs'                          => ['<p ' . $names("\t") . '>x</p>'],
            'line feeds'                    => ['<p ' . $names("\n") . '>x</p>'],
            'carriage returns'              => ['<p ' . $names("\r") . '>x</p>'],
            'form feeds'                    => ['<p ' . $names("\f") . '>x</p>'],
            'slashes'                       => ['<p ' . $names('/') . '>x</p>'],
            'values in double quotes'       => ['<p ' . implode(' ', array_map(fn ($i) => "a{$i}=\"1\"", range(1, $n))) . '>x</p>'],
            'values in single quotes'       => ['<p ' . implode(' ', array_map(fn ($i) => "a{$i}='1'", range(1, $n))) . '>x</p>'],
            'values without quotes'         => ['<p ' . implode(' ', array_map(fn ($i) => "a{$i}=1", range(1, $n))) . '>x</p>'],
            'blanks around the equals sign' => ['<p ' . implode(' ', array_map(fn ($i) => "a{$i} = \"1\"", range(1, $n))) . '>x</p>'],
            'a form feed before a quote'    => ["<p x=\f\"" . $names(' ') . ' ">x</p>'],
            'a quoted > first'              => ['<p x=">" ' . $names(' ') . '>x</p>'],
            'an unterminated quote'         => ['<p x="' . $names(' ') . '>x</p>'],
            'a tag name that starts with _' => ['<_p ' . $names(' ') . '>x</_p>'],
            'upper case'                    => ['<P ' . strtoupper($names(' ')) . '>x</P>'],
            'an end tag with attributes'    => ['<p>x</p ' . $names(' ') . '>'],
            'an unknown element'            => ['<x:y ' . $names(' ') . '>x</x:y>'],
            'a picture'                     => ['<img src="data:image/png;base64,' . self::PNG . '" ' . $names(' ') . '>'],
            'duplicates of a few names'     => ['<p ' . implode(' ', array_map(fn ($i) => 'a' . ($i % 40), range(1, $n))) . '>x</p>'],
        ];
    }

    #[DataProvider('crowdedTags')]
    public function test_a_tag_that_crowds_is_shown_as_text_and_never_parsed(string $in): void
    {
        $this->assertSame(htmlspecialchars($in, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), RichText::clean($in), 'libxml compares each attribute with all the ones before it: a tag of 39,000 costs 7-9 s on every view of the page.');
    }

    public function test_what_an_editor_writes_is_not_a_crowd(): void
    {
        $cell = '<td style="width: 16.6667%; border: 1px solid rgb(0, 0, 0); padding: 5pt; vertical-align: top;" colspan="1" rowspan="1"><span style="font-size: 11pt; font-family: Arial, sans-serif; color: rgb(0, 0, 0); background-color: transparent;">cell</span></td>';
        $table = '<table style="width: 100%;" class="table table-bordered" dir="rtl"><tbody>' . str_repeat('<tr>' . str_repeat($cell, 6) . '</tr>', 200) . '</tbody></table>';
        $paste = str_repeat('<p class="MsoNormal" dir="rtl" style="margin: 0cm 0cm 8pt; text-align: right; line-height: 107%; font-size: 11pt;"><span lang="AR-SA" style="font-size: 14pt;">نص عربي</span></p>', 3000);
        $eighty = '<p ' . implode(' ', array_map(fn ($i) => "a{$i}", range(1, 80))) . ' dir="rtl">x</p>';

        $this->assertStringStartsWith('<table dir="rtl"><tbody><tr><td colspan="1" rowspan="1">', RichText::clean($table), 'A table of 1,200 cells is parsed.');
        $this->assertStringContainsString('<p dir="rtl" style="text-align: right; font-size: 11pt">', RichText::clean($paste));
        $this->assertSame('<p dir="rtl">x</p>', RichText::clean($eighty), 'A tag with eighty attributes is still parsed (the limit is 400 units).');
    }

    /**
     * The guard against what libxml does with it, as a property: for random tag shapes, whenever libxml BUILDS an element with 250 attributes or more, clean() has not parsed the input. The
     * generator re-uses a few uniform shapes (separator, name, value) so that a good share of the inputs really do reach libxml's attribute list; the count of those is asserted.
     */
    public function test_whenever_libxml_would_build_a_crowded_element_clean_does_not_parse(): void
    {
        mt_srand(20261119);
        $names = ['a', 'b1', 'a-b', 'a:b', 'a.b', '_a', 'é', '@a', '[x]', '#a', '"x', "'x", 'on', 'href', 'style', '0', '-', '$', '*', '<', '\\', '/x', '=x'];
        $separators = [' ', "\t", "\n", "\r", "\f", "\v", '/', '', "\0", "\xc2\xa0", '  ', ' / '];
        $values = ['', '', '', '=1', '=a', '="1"', "='1'", '= "1"', "=\f\"1\"", '="a>b"', "='a>b'", '=`x`', '=""', '=', '==', '="', "='"];
        $tags = ['p', 'P', '_p', ':p', '.p', 'x:p', 'a', 'img', 'div', ''];
        $beforeTag = ['', '', 'x', '<!-- ', '<p x=">" ', "<p x='>' ", '<a href=', '</'];
        $afterTag = ['>', '>', '/>', '', ' >', '">'];

        $crowded = 0;

        for ($i = 0; $i < 600; $i++) {
            $mode = mt_rand(0, 3);
            [$separator, $name, $value] = [$separators[array_rand($separators)], $names[array_rand($names)], $values[array_rand($values)]];
            $html = $beforeTag[array_rand($beforeTag)] . '<' . $tags[array_rand($tags)];

            for ($k = 0, $count = mt_rand(250, 700); $k < $count; $k++) {
                $html .= ($mode === 3 ? $separators[array_rand($separators)] : $separator) . ($mode === 3 ? $names[array_rand($names)] : $name) . $k . ($mode === 3 ? $values[array_rand($values)] : $value);
            }

            $html .= $afterTag[array_rand($afterTag)] . '<p>tail</p>';

            $previous = libxml_use_internal_errors(true);
            $document = new \DOMDocument();
            @$document->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            $widest = 0;
            foreach ($document->getElementsByTagName('*') as $element) {
                $widest = max($widest, $element->attributes->length);
            }

            if ($widest >= 250) {
                $crowded++;
                $this->assertSame(htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), RichText::clean($html), "libxml built {$widest} attributes on one element and clean() parsed it: " . json_encode(substr($html, 0, 120)));
            }
        }

        $this->assertGreaterThan(15, $crowded, 'Premise: the generator reaches libxml\'s attribute list often enough for the property to mean something.');
    }

    public function test_a_pasted_picture_of_a_realistic_size_survives_with_the_text_around_it(): void
    {
        $picture = 'data:image/png;base64,' . str_repeat('A', 1400000);

        $out = RichText::clean('<p>before</p><img src="' . $picture . '" alt="screenshot"><p>after</p>');

        $this->assertSame('<p>before</p><img src="' . $picture . '" alt="screenshot"><p>after</p>', $out, 'A screenshot of 1.4 MB of base64, and the text on both sides of it, come through whole.');
    }

    public function test_a_picture_over_the_cap_is_dropped_and_only_the_picture(): void
    {
        $out = RichText::clean('<p>before</p><img src="data:image/png;base64,' . str_repeat('A', 1600000) . '"><p>after</p>');

        $this->assertSame('<p>before</p><p>after</p>', $out);
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
