<?php

namespace Tests\Support\Security;

/**
 * The hostile inputs for App\Support\Html\RichText, in one place: the unit test and the real-browser differential (bin/verify-rich-text-in-chromium.php) draw on the same list.
 * HAND-WRITTEN: the classic evasion families (event handlers, script URLs spelled with entities, tabs and control bytes, data URLs, raw-text elements that swallow a closing tag, namespace
 * switches through svg and math, comments, CDATA, unclosed tags, null bytes, beacons and same-origin requests). SOUP: seeded random tag soup assembled from the same parts, so that a
 * combination nobody wrote down is tried too. Nothing here is an exploit for anything but the sanitizer under test: every payload is a string.
 */
final class RichTextCorpus
{
    /** @return list<string> */
    public static function hostile(): array
    {
        return [

 '<script>alert(1)</script>', '<img src=x onerror=alert(1)>', '<svg onload=alert(1)>', '<svg><script>alert(1)</script></svg>', '<math><mi xlink:href="data:x,<script>alert(1)</script>">',
 '<a href="javascript:alert(1)">x</a>', '<a href="  JaVaScRiPt:alert(1)">x</a>', '<a href="java&#x09;script:alert(1)">x</a>', '<a href="&#106;avascript:alert(1)">x</a>',
 '<a href="jav&#x0A;ascript:alert(1)">x</a>', '<a href="&#x6A&#x61&#x76&#x61&#x73&#x63&#x72&#x69&#x70&#x74&#x3A;alert(1)">x</a>', "<a href=\"\x01javascript:alert(1)\">x</a>",
 '<a href="vbscript:msgbox(1)">x</a>', '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>', '<a href="//evil.example/x">x</a>', '<a href="/relative">x</a>', '<a href="https:///x">x</a>', '<a href="https:\\\\evil.example">x</a>',
 '<iframe src="javascript:alert(1)"></iframe>', '<iframe srcdoc="<script>alert(1)</script>"></iframe>', '<object data="javascript:alert(1)"></object>', '<embed src="javascript:alert(1)">',
 '<link rel=stylesheet href="https://evil.example/x.css">', '<style>@import "https://evil.example/x.css";</style>', '<style>*{background:url(https://evil.example/x)}</style>',
 '<form action="javascript:alert(1)"><button formaction="javascript:alert(1)">x</button></form>', '<input autofocus onfocus=alert(1)>', '<details open ontoggle=alert(1)>', '<video><source onerror=alert(1)></video>',
 '<body onload=alert(1)>', '<div style="background:url(javascript:alert(1))">x</div>', '<div style="width:expression(alert(1))">x</div>', '<p style="color:red;background:url(//evil.example/x)">x</p>',
 '<div style="color:red\;background:url(x)">x</div>', '<div style=\'color:"red;x:y"\'>x</div>', '<img src="data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9ImFsZXJ0KDEpIi8+">', '<img src="https://evil.example/beacon.png">', '<img src="/admin/delete/1">',
 '<img srcset="https://evil.example/a.png 1x">', '<noscript><p title="</noscript><img src=x onerror=alert(1)>">', '<svg></p><style><a id="</style><img src=1 onerror=alert(1)>">', '<xmp><img src=x onerror=alert(1)></xmp>',
 '<textarea></textarea><img src=x onerror=alert(1)>', '<title></title><img src=x onerror=alert(1)>', '<!--><img src=x onerror=alert(1)>-->', '<!--[if IE]><img src=x onerror=alert(1)><![endif]-->', '<![CDATA[<img src=x onerror=alert(1)>]]>',
 '<a href=x onmouseover=alert(1)>x</a>', '<img src=x onerror=alert(1)//', "<img src=`x`onerror=alert(1)>", '<img """><script>alert(1)</script>">', "<scr\0ipt>alert(1)</scr\0ipt>", '<scr<script>ipt>alert(1)</scr</script>ipt>',
 '&lt;script&gt;alert(1)&lt;/script&gt;', '&#60;script&#62;alert(1)&#60;/script&#62;', '<p>&lt;img src=x onerror=alert(1)&gt;</p>', '<plaintext><img src=x onerror=alert(1)>', '<listing><img src=x onerror=alert(1)></listing>',
 '<template><img src=x onerror=alert(1)></template>', '<select><option><img src=x onerror=alert(1)></option></select>', '<table><tr><td><img src=x onerror=alert(1)></td></tr></table>', '<table><img src=x onerror=alert(1)></table>',
 '<a href="https://example.com" target="_self" rel="opener">x</a>', '<a href="mailto:a@b.c?subject=x">m</a>', '<a href="tel:+963123456">t</a>', '<img src="data:image/png;base64,iVBORw0KGgo=" onerror=alert(1)>',
 '<img src="data:image/png;base64,iVBOR w0KGgo=">', '<img src="data:image/png;base64,###">', '<p dir="rtl" style="text-align:right;color:#fff;font-size:12px">نص عربي</p>', '<ul><li>a</li><li><b>b</b></li></ul>', '<pre><code>&lt;b&gt;x&lt;/b&gt;</code></pre>',
 '<base href="https://evil.example/"><a href="x">x</a>', '<meta http-equiv="refresh" content="0;url=https://evil.example">', '<img src=x onerror="&#97;lert(1)">', '<div id=x><div id=x>dup</div></div>', '<a name=x id=y class=z>x</a>',
 '<audio src=x onerror=alert(1)>', '<marquee onstart=alert(1)>x</marquee>', '<isindex action="javascript:alert(1)">', '<keygen autofocus onfocus=alert(1)>', '<dialog open onclose=alert(1)>x</dialog>',
 '<svg><a xlink:href="javascript:alert(1)"><text>x</text></a></svg>', '<math><maction actiontype="statusline#" xlink:href="javascript:alert(1)">x</maction></math>', '<svg><animate onbegin=alert(1) attributeName=x dur=1s>',
        ];
    }

    /**
     * Random TREES of the elements the sanitizer keeps (and a few it does not), nested WITHOUT REGARD to any content model: a list item in a div in a list item, a table in a div in a table, a
     * block in a paragraph, a link in a link, tags left open. This is the family where libxml's HTML4 tree and a browser's HTML5 tree builder disagree, and where an end tag of the output can
     * close an element of the page around it. Seeded.
     *
     * @return list<string>
     */
    public static function structure(int $count, int $seed): array
    {
        mt_srand($seed);
        $containers = ['p', 'div', 'span', 'b', 'i', 'u', 'em', 'strong', 'a', 'ul', 'ol', 'li', 'li', 'blockquote', 'pre', 'code', 'h1', 'h2', 'h3', 'table', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'font', 'center', 'section', 'small', 'sub', 'sup', 'del', 'mark', 'div', 'p'];
        $leaves = ['hello', 'a', 'b c', '&amp;', 'x<y', 'نص', '1', '', '<br>', '<hr>', '<img src="data:image/png;base64,iVBORw0KGgo=">'];
        $open = fn (string $tag): string => '<' . $tag . ($tag === 'a' ? ' href="https://example.com/"' : '') . ($tag === 'ol' && mt_rand(0, 4) === 0 ? ' start="2"' : '') . '>';
        $tree = function (int $depth) use (&$tree, $containers, $leaves, $open): string {
            $out = '';

            for ($n = mt_rand(1, 3); $n > 0; $n--) {
                if ($depth <= 0 || mt_rand(0, 3) === 0) {
                    $out .= $leaves[array_rand($leaves)];

                    continue;
                }

                $tag = $containers[array_rand($containers)];
                $out .= $open($tag) . $tree($depth - 1) . (mt_rand(0, 9) === 0 ? '' : '</' . $tag . '>');   // now and then left open
            }

            return $out;
        };

        $out = [];

        while (count($out) < $count) {
            $out[] = $tree(mt_rand(2, 7));
        }

        return $out;
    }

    /** @return list<string> */
    public static function soup(int $count, int $seed): array
    {
        mt_srand($seed);
        $tags = ['p','div','span','a','img','b','i','u','ul','ol','li','table','tr','td','th','blockquote','pre','code','h1','h2','script','style','iframe','svg','math','form','input','button','textarea','title','noscript','xmp','template','select','option','details','video','audio','object','embed','link','meta','base','body','html','head','font','center','marquee','plaintext','listing'];
        $attrs = ['onerror','onload','onclick','onmouseover','onfocus','autofocus','style','href','src','srcset','data','action','formaction','xlink:href','class','id','dir','title','alt','width','height','colspan','target','rel','name','content','http-equiv','background','poster','ping','srcdoc','is'];
        $vals = ['alert(1)','javascript:alert(1)','  javascript:alert(1)','JaVaScRiPt:alert(1)','java&#x09;script:alert(1)','&#106;avascript:alert(1)','data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==','data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=','data:image/png;base64,iVBORw0KGgo=','https://evil.example/x','//evil.example/x','/relative','x','1','2','red','color:red;background:url(javascript:alert(1))','background:url(//evil.example/a)','width:expression(alert(1))','rtl','"><script>alert(1)</script>',"'><img src=x onerror=alert(1)>",'</style><img src=x onerror=alert(1)>','</title><img src=x onerror=alert(1)>','</noscript><img src=x onerror=alert(1)>','</textarea><img src=x onerror=alert(1)>','`x`onerror=alert(1)'];
        $texts = ['hello','a < b','x &amp; y','&lt;b&gt;','&#60;','&nbsp;','لا','<','>','"',"'",'</','<!','<?','&','alert(1)','--','-->','<!--',']]>','<![CDATA[','\\','%3Cscript%3E'];
        $pick = fn (array $a) => $a[mt_rand(0, count($a) - 1)];
        $tag = function () use ($tags, $attrs, $vals, $pick): string {
            $t = $pick($tags);
            $s = '<' . (mt_rand(0, 9) === 0 ? '/' : '') . $t;
            for ($n = mt_rand(0, 3); $n > 0; $n--) {
                $q = $pick(['"', "'", '', '`']);
                $s .= (mt_rand(0, 5) === 0 ? '/' : ' ') . $pick($attrs) . (mt_rand(0, 8) === 0 ? '' : '=' . $q . $pick($vals) . $q);
            }

            return $s . (mt_rand(0, 12) === 0 ? '' : (mt_rand(0, 8) === 0 ? '/>' : '>'));
        };

        $out = [];
        while (count($out) < $count) {
            $s = '';
            for ($n = mt_rand(1, 9); $n > 0; $n--) {
                $s .= mt_rand(0, 2) === 0 ? $pick($texts) : $tag();
            }
            $out[] = $s;
        }

        return $out;
    }
}
