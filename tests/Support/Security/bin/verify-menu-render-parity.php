#!/usr/bin/env php
<?php
/**
 * Proves that moving the sidebar's rendering out of a PHP string and into a
 * Blade partial did not change the DOM.
 *
 * WHY THIS IS A SCRIPT AND NOT A TEST
 * `vendor/` cannot be installed in this container (see
 * docs/development/phase-2/PHASE-02-TEST-EVIDENCE.md §0), so there is no
 * PHPUnit and no Laravel to render a view with. This script needs neither: it
 * runs the OLD algorithm verbatim and renders the NEW partial through a
 * ~40-line compiler covering exactly the four directives the partial uses,
 * then compares the two as parsed DOM trees. A companion PHPUnit test
 * (tests/Unit/Navigation/MenuTreeParityTest.php) asserts the same thing
 * through the real Blade engine once vendor/ exists; this is the half that can
 * run today.
 *
 * WHAT IS ASSERTED, and why not byte-equality
 * The contract is that the emitted DOM stays SELECTOR-COMPATIBLE, because the
 * inline sidebar search (layouts/app.blade.php:421-461) selects
 * `.dash-navbar > .dash-item` with a direct-child combinator. So this compares
 * element names, attributes, nesting and text -- with insignificant whitespace
 * collapsed. Byte-equality is deliberately NOT the contract: the old string
 * carried a newline and sixteen spaces inside one branch, and reproducing that
 * in Blade would make the partial unreadable for no gain.
 *
 * It also records one real difference rather than hiding it: for a CHILD item
 * the old builder emitted an unbalanced `</span>` -- it closed a span it never
 * opened, because the `</span>` was appended unconditionally while the opening
 * tag was only emitted for top-level items. A stray close tag produces no node,
 * so the parsed trees still match; the new partial simply does not emit it.
 *
 * usage: php tests/Support/Security/bin/verify-menu-render-parity.php
 * exit:  0 = the trees match for every fixture, 1 = they diverge
 */

$repo = dirname(__DIR__, 4);

// ---------------------------------------------------------------- stubs
// The two helpers the old builder calls out to, plus env() AND config(). Both
// implementations get the SAME stubs, so any difference is the renderers'.
//
// WHY config() IS HERE, AND WHY IT READS THE REAL FILE. menuTree() is not copied into
// this script -- it is extracted from app/Helper/helper.php and eval'd below, so that it
// cannot drift from the shipped function. That is the point of the design and it means
// this script runs whatever the shipped function calls. F48/R2 moved helper.php:98's
// `env('IS_MOBILE')` to `config('platform.is_mobile')`, and with no config() defined the
// eval'd body died with `Call to undefined function config()` -- exit 255, outside this
// script's own 0/1/2 contract, on the one fixture that exercises the predicate (`&&`
// short-circuits on the name for every other row, so the first four fixtures still
// passed and the failure looked selective). Nothing reported it: CI does not run
// tests/Support/Security/bin/ and these scripts are in no PHPUnit testsuite.
//
// The stub REQUIRES config/platform.php rather than hard-coding 'no', for the same
// reason menuTree() is eval'd: a changed default must reach this script. That file's own
// `env('IS_MOBILE', 'no')` resolves through the env() stub defined just below.
function route($name, $p = null) { return '/' . $name; }
// ONE translator for both implementations: the real menuTree() calls __(),
// the copied old builder calls __t(), and if they differed any mismatch would
// be the stubs' fault rather than the renderers'.
function __($s, $replace = [], $locale = null) { return $s; }
function __t($s) { return __($s); }
function env($k, $d = null) { return $k === 'IS_MOBILE' ? 'no' : $d; }
function config($key, $default = null)
{
    static $values = null;
    if ($values === null) {
        $values = ['platform' => require dirname(__DIR__, 4) . '/config/platform.php'];
    }
    $cursor = $values;
    foreach (explode('.', $key) as $segment) {
        if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
            return $default;
        }
        $cursor = $cursor[$segment];
    }
    return $cursor;
}
function e_($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

function hasChildren($menuItems, $name)
{
    foreach ($menuItems as $item) {
        if ($item['parent'] === $name) return true;
    }
    return false;
}

// ------------------------------------------------- the OLD implementation
// Copied verbatim from app/Helper/helper.php at 5c63bcea, with __() renamed to
// the stub above. Nothing else is altered -- if this is paraphrased the test
// proves nothing.
//
// THAT CLAIM IS LOAD-BEARING, so it was checked rather than asserted. An
// independent review flagged it as unverifiable from the working tree alone and
// named the exact way it could be wrong: this copy emits the icon bare, while
// the sibling generateSideMenu() (helper.php:2264) wraps it in
// <span class="dash-micon"> -- so if the real pre-change generateMenu() had
// also wrapped it, the new partial would have dropped a styling hook and this
// script would stay green because BOTH sides of the comparison were wrong.
//
// Settled by diffing this function against the real one:
//
//     git show 5c63bcea:app/Helper/helper.php \
//       | sed -n '/^    function generateMenu(/,/^    }$/p'
//
// The real builder emits `'<i class="ti ti-' . $item['icon'] . '"></i>'` with
// NO dash-micon wrapper -- the wrapper is generateSideMenu()'s alone. Body
// comparison after normalising indentation: three differences, all deliberate
// and all necessary -- __() -> __t() (the stub; __() cannot be redeclared),
// generateMenu() -> generateMenu_old() (so the copy recurses into itself, not
// into the new implementation), and a trailing newline. The HTML string
// literals are byte-identical, including the 16-space continuation line inside
// the icon/dash-mtext string.
function generateMenu_old($menuItems, $parent = null)
{
    $html = '';

    $filteredItems = array_filter($menuItems, function ($item) use ($parent) {
        return $item['parent'] == $parent;
    });
    usort($filteredItems, function ($a, $b) {
        return $a['order'] - $b['order'];
    });
    foreach ($filteredItems as $item) {
        if ($item['name'] == 'mobilescreensetting' && env('IS_MOBILE') != 'yes') {
            continue;
        }
        $hasChildren = hasChildren($menuItems, $item['name']);
        if ($item['parent'] == null) {
            $html .= '<li class="dash-item dash-hasmenu">';
        } else {
            $html .= '<li class="dash-item">';
        }
        $html .= '<a href="' . (!empty($item['route']) ? route($item['route']) : '#!') . '" class="dash-link">';

        if ($item['parent'] == null) {
            $html .= '<i class="ti ti-' . $item['icon'] . '"></i>
                <span class="dash-mtext">';
        }
        $html .= __t($item['title']) . '</span>';
        if ($hasChildren) {
            $html .= '<span class="dash-arrow"> <i data-feather="chevron-right"></i> </span> </a>';
            $html .= '<ul class="dash-submenu">';
            $html .= generateMenu_old($menuItems, $item['name']);
            $html .= '</ul>';
        } else {
            $html .= '</a>';
        }
        $html .= '</li>';
    }
    return $html;
}

// ------------------------------------------------- the NEW implementation
// menuTree() is read out of the real helper.php rather than copied, so this
// script cannot drift from the shipped function.
$helper = file_get_contents("$repo/app/Helper/helper.php");
if (!preg_match('/\n    function menuTree\(\$menuItems, \$parent = null\)\n    \{.*?\n    \}\n/s', $helper, $m)) {
    fwrite(STDERR, "FATAL: menuTree() not found in app/Helper/helper.php\n");
    exit(2);
}
eval('function menuTree($menuItems, $parent = null) ' . substr($m[0], strpos($m[0], '{')));

/**
 * Compiles the four directives partision/menu-tree.blade.php actually uses.
 * @include of the same file becomes a recursive call, which is how a submenu
 * gets identical markup instead of a second copy of it.
 */
function render_partial(array $nodes)
{
    static $php = null;

    if ($php === null) {
        global $repo;
        $src = file_get_contents("$repo/resources/views/partision/menu-tree.blade.php");
        $src = preg_replace('/\{\{--.*?--\}\}/s', '', $src);

        // the recursive @include, before anything else consumes its arguments
        $src = preg_replace(
            '/@include\(\s*[\'"]partision\.menu-tree[\'"]\s*,\s*\[\s*[\'"]nodes[\'"]\s*=>\s*(.+?)\s*\]\s*\)/',
            '<?php echo render_partial($1); ?>',
            $src
        );

        $src = preg_replace('/@foreach\s*\((.+?)\)/', '<?php foreach ($1): ?>', $src);
        $src = preg_replace('/@endforeach\b/', '<?php endforeach; ?>', $src);
        $src = preg_replace('/@if\s*\((.+?)\)\s*$/m', '<?php if ($1): ?>', $src);
        $src = preg_replace('/@else\b/', '<?php else: ?>', $src);
        $src = preg_replace('/@endif\b/', '<?php endif; ?>', $src);
        $src = preg_replace('/\{\{\s*(.+?)\s*\}\}/s', '<?php echo e_($1); ?>', $src);

        $php = $src;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'menutree') . '.php';
    file_put_contents($tmp, $php);
    ob_start();
    include $tmp;
    $out = ob_get_clean();
    unlink($tmp);
    return $out;
}

function generateMenu_new($menuItems, $parent = null)
{
    return render_partial(menuTree($menuItems, $parent));
}

// ---------------------------------------------------------------- compare
/** Normalised element tree: tag, attributes, and text with whitespace collapsed. */
function tree(DOMNode $n)
{
    $out = [];
    foreach ($n->childNodes as $c) {
        if ($c->nodeType === XML_TEXT_NODE) {
            $t = trim(preg_replace('/\s+/', ' ', $c->textContent));
            if ($t !== '') $out[] = ['#text' => $t];
            continue;
        }
        if ($c->nodeType !== XML_ELEMENT_NODE) continue;
        $attrs = [];
        foreach ($c->attributes as $a) $attrs[$a->name] = trim(preg_replace('/\s+/', ' ', $a->value));
        ksort($attrs);
        $out[] = [$c->nodeName => ['attrs' => $attrs, 'children' => tree($c)]];
    }
    return $out;
}

function parse_tree($html)
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);          // the old output is not valid HTML
    $doc->loadHTML('<ul class="dash-navbar">' . $html . '</ul>',
                   LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    return tree($doc);
}

// ---------------------------------------------------------------- fixtures
$fixtures = [
    'flat, three top-level items' => [
        ['title' => 'Dashboard', 'icon' => 'home',     'name' => 'dash',  'parent' => null, 'order' => 1, 'route' => 'dashboard'],
        ['title' => 'Orders',    'icon' => 'shopping-cart', 'name' => 'ord', 'parent' => null, 'order' => 3, 'route' => 'order.index'],
        ['title' => 'Products',  'icon' => 'package',  'name' => 'prod',  'parent' => null, 'order' => 2, 'route' => 'product.index'],
    ],
    'two levels, with ordering that is NOT array order' => [
        ['title' => 'Catalogue', 'icon' => 'package', 'name' => 'cat',   'parent' => null,  'order' => 2, 'route' => ''],
        ['title' => 'Products',  'icon' => 'x',       'name' => 'p',     'parent' => 'cat', 'order' => 2, 'route' => 'product.index'],
        ['title' => 'Brands',    'icon' => 'x',       'name' => 'b',     'parent' => 'cat', 'order' => 1, 'route' => 'brand.index'],
        ['title' => 'Sell',      'icon' => 'cart',    'name' => 'sell',  'parent' => null,  'order' => 1, 'route' => ''],
        ['title' => 'Orders',    'icon' => 'x',       'name' => 'o',     'parent' => 'sell','order' => 1, 'route' => 'order.index'],
    ],
    'three levels deep' => [
        ['title' => 'Settings', 'icon' => 'settings', 'name' => 's',   'parent' => null, 'order' => 1, 'route' => ''],
        ['title' => 'Store',    'icon' => 'x',        'name' => 'st',  'parent' => 's',  'order' => 1, 'route' => ''],
        ['title' => 'Taxes',    'icon' => 'x',        'name' => 'tx',  'parent' => 'st', 'order' => 1, 'route' => 'tax.index'],
    ],
    'an item with no route falls back to #!' => [
        ['title' => 'Nowhere', 'icon' => 'help', 'name' => 'n', 'parent' => null, 'order' => 1, 'route' => ''],
    ],
    'the mobilescreensetting exclusion' => [
        ['title' => 'Dashboard',  'icon' => 'home', 'name' => 'dash',               'parent' => null, 'order' => 1, 'route' => 'dashboard'],
        ['title' => 'Mobile',     'icon' => 'x',    'name' => 'mobilescreensetting','parent' => null, 'order' => 2, 'route' => 'mobile'],
    ],
    'empty registry' => [],

];

$fail = 0;
foreach ($fixtures as $label => $items) {
    $old = generateMenu_old($items, null);
    $new = generateMenu_new($items, null);

    $oldTree = parse_tree($old);
    $newTree = parse_tree($new);

    $same = $oldTree == $newTree;
    printf("%-6s %s\n", $same ? 'PASS' : 'FAIL', $label);

    if (!$same) {
        $fail++;
        echo "  --- old ---\n  " . trim(preg_replace('/\s+/', ' ', $old)) . "\n";
        echo "  --- new ---\n  " . trim(preg_replace('/\s+/', ' ', $new)) . "\n";
    }
}

// ------------------------------------------------- empty-submenu divergence
// The SECOND deliberate difference, found by review rather than by me.
//
// The old builder asked hasChildren() -- "does any item name this as parent?"
// -- and then rendered a list that FILTERS. So when a parent's only child was
// mobilescreensetting on a non-mobile build, it emitted a .dash-arrow and an
// EMPTY <ul class="dash-submenu"></ul>: a disclosure control promising a
// submenu, opening onto nothing.
//
// The new renderer derives both from the same value -- the arrow and the <ul>
// appear only when children actually rendered -- so it cannot disagree with
// itself. That is a fix, not a regression, and it is asserted here rather than
// reproduced. Latent today: nothing in the registry declares
// mobilescreensetting as a parent.
{
    $onlyChildExcluded = [
        ['title' => 'Settings', 'icon' => 'settings', 'name' => 'cfg', 'parent' => null, 'order' => 1, 'route' => ''],
        ['title' => 'Mobile', 'icon' => 'x', 'name' => 'mobilescreensetting', 'parent' => 'cfg', 'order' => 1, 'route' => 'dashboard'],
    ];

    $oldOut = generateMenu_old($onlyChildExcluded, null);
    $newOut = generateMenu_new($onlyChildExcluded, null);

    $oldEmptyUl = (bool) preg_match('#<ul class="dash-submenu">\s*</ul>#', $oldOut);
    $oldArrow   = strpos($oldOut, 'dash-arrow') !== false;
    $newEmptyUl = (bool) preg_match('#<ul class="dash-submenu">\s*</ul>#', $newOut);
    $newArrow   = strpos($newOut, 'dash-arrow') !== false;

    printf("%-6s empty submenu: the OLD builder emitted an empty <ul> and an arrow\n",
        ($oldEmptyUl && $oldArrow) ? 'PASS' : 'FAIL');
    printf("%-6s empty submenu: the NEW renderer emits neither\n",
        (!$newEmptyUl && !$newArrow) ? 'PASS' : 'FAIL');
    printf("%-6s empty submenu: the parent itself still renders\n",
        strpos($newOut, 'dash-mtext') !== false ? 'PASS' : 'FAIL');

    if (!($oldEmptyUl && $oldArrow) || $newEmptyUl || $newArrow || strpos($newOut, 'dash-mtext') === false) {
        $fail++;
    }
}

// ---------------------------------------------------------------- escaping
// The ONE deliberate difference, asserted rather than tolerated.
//
// The string builder interpolated __($item['title']) straight into HTML with no
// escaping, so a title carrying markup rendered as LIVE MARKUP.
//
// SCOPE, corrected after review -- the first version of this comment claimed
// more than the change delivers. Every title that reaches generateMenu() is a
// hardcoded literal inside __() in a module listener, and no lang file value
// contains markup, so this is HARDENING rather than the closure of a live XSS.
// All three generateMenu() returns in getSideMenu() (app/Helper/helper.php:2138,
// 2142, 2167) come BEFORE the push of database-sourced SideMenuOption rows at
// :2170, and that path returns generateSideMenu() at :2189 instead. The
// editable titles therefore still render unescaped at helper.php:2267, in a
// function this change does not touch.
{
    $evil = [[
        'title'  => 'Tom & Jerry <b>bold</b><script>x</script>',
        'icon'   => 'home', 'name' => 'amp', 'parent' => null, 'order' => 1,
        'route'  => 'dashboard',
    ]];

    $oldOut = generateMenu_old($evil, null);
    $newOut = generateMenu_new($evil, null);

    $oldRaw = strpos($oldOut, '<b>bold</b>') !== false;
    $newRaw = strpos($newOut, '<b>bold</b>') !== false;
    $newEsc = strpos($newOut, '&lt;b&gt;bold&lt;/b&gt;') !== false
           && strpos($newOut, '&amp;') !== false;

    printf("%-6s escaping: the OLD builder emitted a markup title RAW\n", $oldRaw ? 'PASS' : 'FAIL');
    printf("%-6s escaping: the NEW renderer does NOT emit it raw\n",      $newRaw ? 'FAIL' : 'PASS');
    printf("%-6s escaping: the NEW renderer escapes < > and &\n",         $newEsc ? 'PASS' : 'FAIL');
    if (!$oldRaw || $newRaw || !$newEsc) { $fail++; }
}

// The selectors the inline sidebar search depends on must be present in the
// NEW output, asserted directly rather than inferred from the tree comparison.
//
// CAVEAT, measured: this section is a weaker guard than it looks. Wrapping an
// item in a <div> -- the exact regression the architecture doc warns about --
// still passes here, because libxml's HTML parser hoists a stray <li> out of a
// <div> inside a <ul> per the HTML parsing rules, so the XPath still finds it.
// The TREE COMPARISON above is what actually catches that (verified: wrapping
// an item produced 6 fixture failures). Keep both, and do not read a green
// selector section alone as proof.
$html = generateMenu_new($fixtures['two levels, with ordering that is NOT array order'], null);
$doc = new DOMDocument();
libxml_use_internal_errors(true);
$doc->loadHTML('<ul class="dash-navbar">' . $html . '</ul>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
libxml_clear_errors();
$x = new DOMXPath($doc);

$selectors = [
    'direct children of .dash-navbar are .dash-item' =>
        '//ul[contains(@class,"dash-navbar")]/li[contains(@class,"dash-item")]',
    '.dash-link inside each item'   => '//li[contains(@class,"dash-item")]/a[contains(@class,"dash-link")]',
    '.dash-mtext on top-level items' => '//li[contains(@class,"dash-hasmenu")]/a/span[contains(@class,"dash-mtext")]',
    '.dash-submenu as a direct child of .dash-item' =>
        '//li[contains(@class,"dash-item")]/ul[contains(@class,"dash-submenu")]',
    '.dash-arrow on an item that has children' => '//a[contains(@class,"dash-link")]/span[contains(@class,"dash-arrow")]',
];
foreach ($selectors as $label => $q) {
    $n = $x->query($q)->length;
    printf("%-6s selector: %s (%d)\n", $n > 0 ? 'PASS' : 'FAIL', $label, $n);
    if ($n === 0) $fail++;
}

echo "\n" . ($fail === 0
    ? "ALL FIXTURES AND SELECTORS MATCH -- the DOM contract is preserved.\n"
    : "$fail FAILURE(S) -- the rendering changed the DOM.\n");

exit($fail === 0 ? 0 : 1);
