<?php
/**
 * Renders the pilot Blade view outside Laravel, the same way Phase 4C's
 * render.php did: a minimal stand-in layout plus the real view file, the real
 * stylesheets from public/, and the real translation strings from
 * resources/lang/<locale>.json.
 *
 * It implements only the Blade subset this screen uses. It is a PREVIEW
 * harness, not the application: routing, auth, tenancy and the DataTables
 * stack are all absent. What it does prove is what the view's own markup and
 * the stylesheets do in a real browser.
 *
 * usage: php render-preview.php <view.blade.php> <locale: en|ar> <state: full|empty|noperm> <out.html> [light|dark]
 *
 * ---------------------------------------------------------------------------
 * PHASE 02 EXTENSION
 *
 * Two additions, both backwards compatible -- the Sprint 01 pilot commands in
 * tools/README.md still produce the same output:
 *
 *   1. FIXTURES. If docs/design-system/tools/fixtures/<view-basename>.php
 *      exists it is included and owns the view's variables and $GRANTS, so a
 *      screen's stub data is declarative and lives beside the screen instead
 *      of being hardcoded here. With no fixture, the pilot's own stubs below
 *      are used, exactly as before.
 *
 *   2. BLADE COMPONENTS. <x-ss.stat ... /> and <x-ss.card> ... </x-ss.card>
 *      are expanded, including :bound="expressions", named <x-slot:name>
 *      slots, @props defaults and $attributes->merge(). Phase 02 builds its
 *      screens out of resources/views/components/ss/*, so without this the
 *      harness could not render a single Phase 02 screen.
 *
 * It remains a PREVIEW harness, not the application. What it proves is what
 * the view's markup and the real stylesheets do in a real browser.
 * ---------------------------------------------------------------------------
 */

$repo = dirname(__DIR__, 3);   // <repo>/docs/design-system/tools -> <repo>

[$self, $viewPath, $locale, $state, $out, $theme] = array_pad($argv, 6, null);
$theme = $theme === 'dark' ? 'dark' : 'light';
if (!$viewPath || !$locale || !$state || !$out) {
    fwrite(STDERR, "usage: render-preview.php <view> <en|ar> <full|empty|noperm> <out.html> [light|dark]\n");
    exit(2);
}

// ---------------------------------------------------------------- translations
$langFile = "$repo/resources/lang/$locale.json";
$T = is_file($langFile) ? json_decode(file_get_contents($langFile), true) : [];
$missing = [];
function __t($key, array $replace = [])
{
    global $T, $missing;
    $line = (isset($T[$key]) && $T[$key] !== '') ? $T[$key] : null;
    if ($line === null) {
        $missing[$key] = true;
        $line = $key;
    }
    // Laravel's __() substitutes :placeholders; a harness that ignored them
    // would render ":sum of :total" and look like a translation bug.
    foreach ($replace as $k => $v) {
        $line = str_replace([':' . $k, ':' . ucfirst($k)], (string) $v, $line);
    }
    return $line;
}

/**
 * `__()` itself, for PHP that the harness runs directly rather than compiling.
 *
 * The Blade pass rewrites `__(` to `__t(` in TEMPLATES, but a fixture that
 * eval's a real helper out of app/ -- which is how the sidebar preview gets the
 * shipped menuTree() instead of a copy of it -- calls `__()` from PHP, where no
 * rewrite happens. Without this that call is a fatal.
 */
if (!function_exists('__')) {
    function __($key, array $replace = [], $locale = null) { return __t($key, $replace); }
}

/**
 * trans_choice() stand-in. Picks the plural branch the way Laravel's simple
 * "singular|plural" form does and substitutes :placeholders.
 */
function __tc($key, $count, array $replace = [])
{
    $line = __t($key);
    $parts = explode('|', $line);
    $pick = ($count == 1 || count($parts) < 2) ? $parts[0] : $parts[1];
    foreach ($replace as $k => $v) {
        $pick = str_replace([':' . $k, ':' . ucfirst($k)], (string) $v, $pick);
    }
    return $pick;
}

/**
 * Stand-in for a Laravel MessageBag, for the validation-summary component.
 * A fixture sets $errors = new ErrorBagStub([...]) to render the failed state.
 */
class ErrorBagStub
{
    private $bag;
    public function __construct(array $bag = []) { $this->bag = $bag; }
    public function any()   { return $this->bag !== []; }
    public function count() { return count($this->bag); }
    public function keys()  { return array_keys($this->bag); }
    public function has($k) { return isset($this->bag[$k]); }
    public function first($k = null)
    {
        if ($k === null) { return $this->bag ? reset($this->bag)[0] : ''; }
        return isset($this->bag[$k]) ? $this->bag[$k][0] : '';
    }
    public function get($k) { return $this->bag[$k] ?? []; }
}

// ---------------------------------------------------------------- stub model
class Row
{
    public $id, $terms, $name;
    public function __construct($id, $terms) { $this->id = $id; $this->terms = $terms; $this->name = $terms; }
}

$isRtl = in_array($locale, ['ar', 'he'], true);

$attribute = new Row(7, $isRtl ? 'المقاس' : 'Size');

$optionTerms = $isRtl
    ? ['صغير جداً', 'صغير', 'متوسط', 'كبير', 'كبير جداً']
    : ['Extra small', 'Small', 'Medium', 'Large', 'Extra large'];

$attribute_option = [];
if ($state !== 'empty') {
    foreach ($optionTerms as $i => $t) $attribute_option[] = new Row(100 + $i, $t);
}

$GRANTS = $state === 'noperm'
    ? ['Manage Attributes Option' => true]      // view-only merchant
    : ['Manage Attributes Option' => true, 'Create Attributes Option' => true,
       'Edit Attributes Option' => true, 'Delete Attributes Option' => true];

function permission_check($p) { global $GRANTS; return !empty($GRANTS[$p]); }

/**
 * auth() stand-in.
 *
 * A view may ask the question either way round -- `@permission('X')` for a
 * whole block, or `auth()->user()->isAbleTo('X')` for one attribute -- and the
 * two MUST answer from the same place, or a preview can show a gated control
 * and a gated link disagreeing with each other. Both read $GRANTS.
 *
 * This models a merchant, never the platform operator: `super admin` is routed
 * to superadmin.dashboard by HomeController::index() and never reaches the
 * views previewed here. A preview harness that silently granted operator
 * status would make every gate look passable.
 */
class PreviewUser
{
    public $type = 'admin';
    public function isAbleTo($permission) { return permission_check($permission); }
    public function can($permission) { return permission_check($permission); }
}

class PreviewAuth
{
    public function check() { return true; }
    public function user() { return new PreviewUser(); }
    public function guest() { return false; }
    public function id() { return 1; }
}

if (!function_exists('auth')) {
    function auth($guard = null) { return new PreviewAuth(); }
}

// ---------------------------------------------------------------- fixtures
// A screen's stub data belongs beside the screen, not in this file. If
// fixtures/<view-basename>.php exists it runs here, in global scope, and may
// define or overwrite anything above -- including $GRANTS. $errors always
// exists so a view can reference it unconditionally.
$errors = new ErrorBagStub();
$ssFixtureName = basename(dirname($viewPath)) . '-' . basename($viewPath, '.blade.php');
foreach ([__DIR__ . "/fixtures/{$ssFixtureName}.php",
          __DIR__ . '/fixtures/' . basename($viewPath, '.blade.php') . '.php'] as $ssFixture) {
    if (is_file($ssFixture)) {
        require $ssFixture;
        fwrite(STDERR, "fixture: " . basename($ssFixture) . "\n");
        break;
    }
}

// ---------------------------------------------------------------- stub helpers
function route($name, $p = null) { return '#' . $name . ($p !== null ? '/' . (is_object($p) ? $p->id : $p) : ''); }
function asset($p) { return '/' . ltrim($p, '/'); }
/**
 * Marks a string as already-HTML, so `{{ $x }}` prints it rather than escaping
 * it. This is Laravel's Htmlable contract in miniature, and it is not optional:
 * a component's slot and its $attributes bag are BOTH echoed through `{{ }}`,
 * so without it every nested component renders as visible escaped markup --
 * which is exactly what the first screenshot run showed.
 */
class SafeHtml
{
    private $html;
    public function __construct($html) { $this->html = (string) $html; }
    public function __toString() { return $this->html; }
}

function e_($v)
{
    if ($v instanceof SafeHtml || $v instanceof AttrBag) { return (string) $v; }
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

class FormStub
{
    public static function open($a = [])
    {
        $r = $a['route'] ?? null;
        $url = is_array($r) ? route($r[0], $r[1]) : '#';
        return '<form method="POST" action="' . e_($url) . '" class="' . e_($a['class'] ?? '') . '">'
             . '<input type="hidden" name="_method" value="' . e_($a['method'] ?? 'POST') . '">';
    }
    public static function close() { return '</form>'; }
}

// ---------------------------------------------------------------- components
/**
 * The global symbol table, MINUS the entries a template must never receive.
 *
 * `GLOBALS` is the one that matters: $GLOBALS contains a `GLOBALS` entry
 * pointing at itself, so extracting it into a scope and then calling
 * get_defined_vars() in that scope copies a self-referential array. Nest that
 * two components deep and the process is killed by the OOM killer -- which is
 * exactly what happened here before this filter existed, with no error message
 * to explain it.
 */
function ss_clean_globals()
{
    static $keys = ['GLOBALS', 'T', 'missing', 'argv', 'argc',
                    '_SERVER', '_ENV', '_GET', '_POST', '_COOKIE', '_FILES', '_REQUEST',
                    '__sections', '__stacks', '__cur'];
    $out = [];
    foreach ($GLOBALS as $k => $v) {
        if (!in_array($k, $keys, true)) { $out[$k] = $v; }
    }
    return $out;
}

/**
 * Strips the entries a scope must never carry into a nested render.
 *
 * `__ss*` is the important half. A scope handed up from a call site is that
 * caller's get_defined_vars(), and if the caller is itself a render closure
 * those vars include the closure's OWN include path. Extracting it with
 * EXTR_OVERWRITE then replaced the nested render's include target with the
 * outer template's, so `include` re-ran the whole view -- an infinite
 * recursion that looked like a hang with no error at all. Every harness
 * internal is therefore named __ss* and filtered here, and no Blade view
 * defines a variable with that prefix.
 */
function ss_clean_scope(array $scope)
{
    unset($scope['GLOBALS'], $scope['T'], $scope['missing'],
          $scope['__sections'], $scope['__stacks'], $scope['__cur']);
    foreach (array_keys($scope) as $k) {
        if (strncmp((string) $k, '__ss', 4) === 0) { unset($scope[$k]); }
    }
    return $scope;
}

/**
 * Stand-in for Laravel's ComponentAttributeBag. Only the three things the
 * ss-* components actually call: merge(), get() and string interpolation.
 */
class AttrBag
{
    public $items = [];
    public function __construct(array $items = []) { $this->items = $items; }

    public function merge(array $defaults = [])
    {
        $out = $this->items;
        foreach ($defaults as $k => $v) {
            if ($k === 'class') {
                // Laravel appends; the component's own class comes first.
                $out['class'] = trim($v . ' ' . ($out['class'] ?? ''));
            } elseif (!array_key_exists($k, $out)) {
                $out[$k] = $v;
            }
        }
        return new AttrBag($out);
    }

    public function get($k, $default = null) { return $this->items[$k] ?? $default; }

    public function __toString()
    {
        $parts = [];
        foreach ($this->items as $k => $v) {
            if ($v === true) { $parts[] = e_($k); continue; }
            if ($v === false || $v === null) { continue; }
            $parts[] = e_($k) . '="' . e_($v) . '"';
        }
        return $parts ? ' ' . implode(' ', $parts) : '';
    }
}

/**
 * Renders resources/views/components/<path>.blade.php with $props bound.
 *
 * Props come from the component's own @props([...]) declaration: a string key
 * is a prop with a default, a numeric key is a required prop with none. Any
 * attribute that is NOT a declared prop lands in $attributes, which is how
 * Laravel does it and which is what lets a caller pass id/aria-* straight
 * through to the root element.
 */
function ss_component($path, array $props, $slot = '', array $namedSlots = [])
{
    global $repo;

    $file = $repo . '/resources/views/components/' . str_replace('.', '/', $path) . '.blade.php';
    if (!is_file($file)) {
        fwrite(STDERR, "missing component: x-{$path}\n");
        return '';
    }

    $src = file_get_contents($file);

    // Declared props, and their defaults.
    $declared = [];
    if (preg_match('/@props\s*\(\s*(\[.*?\])\s*\)/s', $src, $m)) {
        $arr = eval('return ' . $m[1] . ';');
        foreach ($arr as $k => $v) {
            if (is_int($k)) { $declared[$v] = null; } else { $declared[$k] = $v; }
        }
        $src = preg_replace('/@props\s*\(\s*\[.*?\]\s*\)/s', '', $src, 1);
    }

    $scope = [];
    foreach ($declared as $name => $default) {
        $scope[$name] = array_key_exists($name, $props) ? $props[$name] : $default;
    }
    // Everything the component did not declare becomes an attribute.
    $attrs = [];
    foreach ($props as $k => $v) {
        if (!array_key_exists($k, $declared)) { $attrs[$k] = $v; }
    }

    $scope['attributes'] = new AttrBag($attrs);
    $scope['slot'] = new SafeHtml($slot);
    foreach ($namedSlots as $n => $html) { $scope[$n] = new SafeHtml($html); }

    $__ssPhp = compile_blade($src);

    $__ssTmp = tempnam(sys_get_temp_dir(), 'sscomp') . '.php';
    file_put_contents($__ssTmp, $__ssPhp);

    // The component body still reads the globals a Blade view would see
    // (the fixture's data, __t(), route(), permission_check()), plus its own
    // props and slot on top. Note the __ss* names: see ss_clean_scope().
    $scope = ss_clean_scope($scope);
    $__ssRender = function () use ($__ssTmp, $scope) {
        extract(ss_clean_globals(), EXTR_SKIP);
        extract($scope, EXTR_OVERWRITE);
        ob_start();
        include $__ssTmp;
        return ob_get_clean();
    };
    $__ssOut = $__ssRender();
    unlink($__ssTmp);
    return $__ssOut;
}

/**
 * Renders resources/views/<path>.blade.php with $data bound, for the
 * `@include('x', [...])` form.
 *
 * Separate from ss_component() because a partial is not a component: it has no
 * @props, no $attributes and no $slot. It sees the caller's globals plus the
 * data it was handed, which is Laravel's behaviour.
 *
 * This is what lets partision/menu-tree render a submenu by including itself.
 */
function ss_include($path, array $data = [])
{
    global $repo;

    $file = $repo . '/resources/views/' . str_replace('.', '/', $path) . '.blade.php';
    if (!is_file($file)) {
        fwrite(STDERR, "missing include: {$path}\n");
        return '';
    }

    $__ssPhp = compile_blade(file_get_contents($file));
    $__ssTmp = tempnam(sys_get_temp_dir(), 'ssincl') . '.php';
    file_put_contents($__ssTmp, $__ssPhp);

    $data = ss_clean_scope($data);
    $__ssRender = function () use ($__ssTmp, $data) {
        extract(ss_clean_globals(), EXTR_SKIP);
        extract($data, EXTR_OVERWRITE);
        ob_start();
        include $__ssTmp;
        return ob_get_clean();
    };
    $__ssOut = $__ssRender();
    unlink($__ssTmp);
    return $__ssOut;
}

/**
 * Finds the index just past the matching close tag for a component that opened
 * at $from, counting nested <x-same> opens so a card inside a card closes the
 * right one.
 */
function ss_match_close($s, $name, $from)
{
    $open = '<x-' . $name;
    $close = '</x-' . $name . '>';

    /* The character after the name must END it. Without this check
       <x-ss.attention-item> counts as a nested <x-ss.attention>, because one
       name is a prefix of the other, and the depth counter then never returns
       to zero -- which is exactly how this was first wrong. */
    $endsName = function ($at) use ($s, $open) {
        $c = $s[$at + strlen($open)] ?? '';
        return $c === '' || $c === '>' || $c === '/' || ctype_space($c);
    };

    $depth = 1;
    $i = $from;
    while ($i < strlen($s)) {
        $nc = strpos($s, $close, $i);
        if ($nc === false) { return false; }

        // The next genuine open tag for this same name before that close.
        $no = $i;
        while (($no = strpos($s, $open, $no)) !== false) {
            if ($endsName($no)) { break; }
            $no += strlen($open);
        }

        if ($no !== false && $no < $nc) { $depth++; $i = $no + strlen($open); continue; }
        $depth--;
        if ($depth === 0) { return [$nc, $nc + strlen($close)]; }
        $i = $nc + strlen($close);
    }
    return false;
}

/** Parses `a="b" :c="$expr" d` into a PHP array literal source string. */
function ss_parse_attrs($raw)
{
    $parts = [];
    $re = '/(?:^|\s)(:?)([A-Za-z_@\[\]][A-Za-z0-9_\-:.\[\]]*)(?:\s*=\s*"([^"]*)")?/';
    preg_match_all($re, $raw, $ms, PREG_SET_ORDER);
    foreach ($ms as $m) {
        $bound = $m[1] === ':';
        $name = $m[2];
        $hasVal = isset($m[3]);
        $key = "'" . str_replace("'", "\'", $name) . "'";
        if (!$hasVal) { $parts[] = "$key => true"; continue; }
        if ($bound) {
            // :value="$x" -- the quoted text is a PHP expression.
            $parts[] = "$key => (" . $m[3] . ")";
        } else {
            // value="literal {{ $x }}" -- a plain string, but it may itself
            // contain echoes, so run it back through the compiler.
            $lit = $m[3];
            // Either raw Blade ({{ }}) or, now that expansion runs last,
            // already-compiled PHP. Both need executing rather than quoting.
            if (strpos($lit, '{{') !== false || strpos($lit, '<?php') !== false) {
                $parts[] = "$key => ss_inline(" . var_export($lit, true) . ", get_defined_vars())";
            } else {
                $parts[] = "$key => " . var_export($lit, true);
            }
        }
    }
    return '[' . implode(', ', $parts) . ']';
}

/** Compiles and runs a tiny inline Blade fragment, for attribute values. */
function ss_inline($lit, array $scope = [])
{
    $__ssPhp = compile_blade($lit);
    $__ssTmp = tempnam(sys_get_temp_dir(), 'ssinl') . '.php';
    file_put_contents($__ssTmp, $__ssPhp);
    // $scope is the caller's get_defined_vars(), so a component nested inside
    // an @foreach can read the loop variable from its own slot body.
    $scope = ss_clean_scope($scope);
    $__ssRender = function () use ($__ssTmp, $scope) {
        extract(ss_clean_globals(), EXTR_SKIP);
        extract($scope, EXTR_OVERWRITE);
        ob_start();
        include $__ssTmp;
        return ob_get_clean();
    };
    $__ssOut = $__ssRender();
    unlink($__ssTmp);
    return $__ssOut;
}

/**
 * Rewrites every <x-...> in $s into a ss_component() call. Runs innermost-last
 * (the slot body is compiled by the recursive compile_blade call on it), so
 * nesting works to any depth.
 */
function ss_expand_components($s)
{
    $out = '';
    $offset = 0;
    $re = '/<x-([A-Za-z0-9_.\-]+)((?:[^>"\x27]|"[^"]*"|\x27[^\x27]*\x27)*?)(\/?)>/';

    /* A SINGLE FORWARD PASS, and the offset is the whole point.
     *
     * The first version of this re-scanned the whole string each iteration.
     * That made it walk back into text it had just var_export()ed into a PHP
     * string literal, expand a <x-...> tag sitting INSIDE that literal, and
     * splice unescaped quotes into the middle of it -- a parse error whose
     * cause is invisible in the Blade. Advancing past each replacement means
     * a slot body stays raw here and is expanded by the recursive
     * compile_blade() call that ss_inline() makes on it, which is both
     * correct and how nesting ends up working to any depth.
     */
    while (preg_match($re, $s, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $whole = $m[0][0];
        $at = $m[0][1];
        $name = $m[1][0];
        $rawAttrs = $m[2][0];
        $selfClose = $m[3][0] === '/';

        $propsSrc = ss_parse_attrs($rawAttrs);
        $afterOpen = $at + strlen($whole);

        if ($selfClose) {
            $inner = '';
            $end = $afterOpen;
        } else {
            $found = ss_match_close($s, $name, $afterOpen);
            if ($found === false) {
                fwrite(STDERR, "unclosed component: x-{$name}\n");
                $out .= substr($s, $offset, $at - $offset);
                $offset = $afterOpen;
                continue;
            }
            $inner = substr($s, $afterOpen, $found[0] - $afterOpen);
            $end = $found[1];
        }

        /* Named slots.
         *
         * Blade closes these BOTH ways -- `</x-slot>` and `</x-slot:footer>`
         * -- and accepting only the short form left the whole block sitting
         * un-extracted inside the slot text, where the later directive passes
         * then rewrote its @if/@foreach *inside* the generated string literal
         * and corrupted it. The failure surfaced as a PHP parse error pointing
         * at a line of perfectly good Blade, so: accept both. */
        $named = [];
        $inner = preg_replace_callback(
            '/<x-slot:([A-Za-z0-9_\-]+)\s*>(.*?)<\/x-slot(?::\1)?>/s',
            function ($sm) use (&$named) { $named[$sm[1]] = $sm[2]; return ''; },
            $inner
        );

        $namedSrc = [];
        foreach ($named as $n => $html) {
            // Only slots the caller actually wrote are passed, so a component's
            // @isset($actions) stays false when none was given.
            $namedSrc[] = "'" . $n . "' => ss_inline(" . var_export($html, true) . ", get_defined_vars())";
        }

        $call = '<?php echo ss_component(' . var_export($name, true) . ', ' . $propsSrc . ', '
              . ($inner === '' ? "''" : 'ss_inline(' . var_export($inner, true) . ', get_defined_vars())') . ', '
              . '[' . implode(', ', $namedSrc) . ']); ?>';

        $out .= substr($s, $offset, $at - $offset) . $call;
        $offset = $end;
    }

    return $out . substr($s, $offset);
}

/**
 * Rewrites @name(...) directives with a PAREN COUNTER rather than a regex.
 *
 * The Sprint 01 harness used /@if\s*(\(.*?\))/ and that is only correct when
 * the directive is the last thing on its line: for `@if (! is_null($c))<span>`
 * the lazy group stops at the first ")" and emits `if (! is_null($c)` --
 * a PHP syntax error. Phase 02's components write exactly that shape, so the
 * matching is done properly here.
 *
 * $fmt receives the inside of the parens and returns the PHP to emit.
 */
function ss_directive($s, $name, callable $fmt)
{
    $i = 0;
    $out = '';
    $needle = '@' . $name;
    while (($at = strpos($s, $needle, $i)) !== false) {
        // Must not match @iffy when looking for @if, and must not match an
        // e-mail-ish @if inside a word.
        $after = $at + strlen($needle);
        $nextCh = $s[$after] ?? '';
        if (ctype_alnum($nextCh) || $nextCh === '_') { $out .= substr($s, $i, $after - $i); $i = $after; continue; }

        // Skip whitespace, then require an opening paren.
        $j = $after;
        while ($j < strlen($s) && ctype_space($s[$j])) { $j++; }
        if (($s[$j] ?? '') !== '(') { $out .= substr($s, $i, $after - $i); $i = $after; continue; }

        $depth = 0;
        $k = $j;
        $inStr = false;
        $q = '';
        for (; $k < strlen($s); $k++) {
            $c = $s[$k];
            if ($inStr) {
                if ($c === '\\') { $k++; continue; }
                if ($c === $q) { $inStr = false; }
                continue;
            }
            if ($c === '"' || $c === "'") { $inStr = true; $q = $c; continue; }
            if ($c === '(') { $depth++; continue; }
            if ($c === ')') { $depth--; if ($depth === 0) { break; } }
        }
        if ($depth !== 0) { $out .= substr($s, $i, $after - $i); $i = $after; continue; }

        $inside = substr($s, $j + 1, $k - $j - 1);
        $out .= substr($s, $i, $at - $i) . $fmt($inside);
        $i = $k + 1;
    }
    return $out . substr($s, $i);
}

// ---------------------------------------------------------------- Blade subset
function compile_blade($src)
{
    global $repo;

    // Comments first, so directives inside them never compile.
    $s = preg_replace('/\{\{--.*?--\}\}/s', '', $src);

    /* @include('a.b.c') -> resources/views/a/b/c.blade.php, inlined before the
       rest of the compile so the partial's own directives are handled too.

       @include('a.b.c', ['x' => $y]) is a DIFFERENT shape and is compiled to a
       call instead of inlined: inlining it would lose the data binding, and
       inlining a partial that includes ITSELF (which is how
       partision/menu-tree renders a submenu) would recurse forever at compile
       time. ss_include() below renders it in its own scope. */
    $s = preg_replace_callback(
        '/@include\s*\(\s*([\'"])([A-Za-z0-9_.\-]+)\1\s*,\s*(\[.*?\])\s*\)/s',
        function ($m) {
            return '<?php echo ss_include(' . var_export($m[2], true) . ', ' . $m[3] . '); ?>';
        },
        $s
    );

    $s = preg_replace_callback(
        '/@include\s*\(\s*([\'"])([A-Za-z0-9_.\-]+)\1\s*\)/',
        function ($m) use ($repo) {
            $f = $repo . '/resources/views/' . str_replace('.', '/', $m[2]) . '.blade.php';
            if (!is_file($f)) { fwrite(STDERR, "missing include: {$m[2]}\n"); return ''; }
            return preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($f));
        },
        $s
    );

    // @extends is handled by the stand-in layout, not here.
    $s = preg_replace('/@extends\s*\(.*?\)/s', '', $s);

    // Inline @section('x', 'expr')  -> captured as a named block
    $s = preg_replace_callback(
        '/@section\s*\(\s*([\'"])(.+?)\1\s*,\s*(.+?)\)\s*$/m',
        fn($m) => "<?php \$__sections['{$m[2]}'] = " . $m[3] . "; ?>",
        $s
    );

    // Block @section('x') ... @endsection / @stop
    $s = preg_replace('/@section\s*\(\s*([\'"])(.+?)\1\s*\)/', "<?php ob_start(); \$__cur='$2'; ?>", $s);
    $s = preg_replace('/@(endsection|stop)\b/', "<?php \$__sections[\$__cur] = ob_get_clean(); ?>", $s);

    // @push('x') ... @endpush
    $s = preg_replace('/@push\s*\(\s*([\'"])(.+?)\1\s*\)/', "<?php ob_start(); \$__cur='$2'; ?>", $s);
    $s = preg_replace('/@endpush\b/', "<?php \$__stacks[\$__cur] = (\$__stacks[\$__cur] ?? '') . ob_get_clean(); ?>", $s);

    // @permission('X') / @can('X')
    $s = preg_replace('/@(?:permission|can)\s*\(\s*([\'"])(.+?)\1\s*\)/', "<?php if (permission_check('$2')): ?>", $s);
    $s = preg_replace('/@(endpermission|endcan)\b/', '<?php endif; ?>', $s);

    // control flow -- paren-counted, see ss_directive().
    $s = ss_directive($s, 'if',      fn($e) => '<?php if (' . $e . '): ?>');
    $s = ss_directive($s, 'elseif',  fn($e) => '<?php elseif (' . $e . '): ?>');
    $s = ss_directive($s, 'unless',  fn($e) => '<?php if (! (' . $e . ')): ?>');
    $s = ss_directive($s, 'isset',   fn($e) => '<?php if (isset(' . $e . ')): ?>');
    $s = ss_directive($s, 'empty',   fn($e) => '<?php if (empty(' . $e . ')): ?>');
    $s = ss_directive($s, 'foreach', fn($e) => '<?php foreach (' . $e . '): ?>');
    $s = ss_directive($s, 'for',     fn($e) => '<?php for (' . $e . '): ?>');
    // @checked / @disabled / @selected / @required emit the bare attribute.
    $s = ss_directive($s, 'checked',  fn($e) => '<?php echo (' . $e . ') ? \'checked\' : \'\'; ?>');
    $s = ss_directive($s, 'disabled', fn($e) => '<?php echo (' . $e . ') ? \'disabled\' : \'\'; ?>');
    $s = ss_directive($s, 'selected', fn($e) => '<?php echo (' . $e . ') ? \'selected\' : \'\'; ?>');
    $s = ss_directive($s, 'required', fn($e) => '<?php echo (' . $e . ') ? \'required\' : \'\'; ?>');
    $s = ss_directive($s, 'php',      fn($e) => '<?php ' . $e . '; ?>');

    $s = preg_replace('/@else\b/', '<?php else: ?>', $s);
    $s = preg_replace('/@end(if|unless|isset|empty)\b/', '<?php endif; ?>', $s);
    $s = preg_replace('/@endforeach\b/', '<?php endforeach; ?>', $s);
    $s = preg_replace('/@endfor\b/', '<?php endfor; ?>', $s);

    // @php ... @endphp (block form)
    $s = preg_replace('/@php\b(?!\s*\()(.*?)@endphp/s', '<?php $1 ?>', $s);

    // @json(expr) -> a JavaScript literal, as Laravel's @json directive emits.
    // Scanned with a paren counter, because expr is usually a nested call.
    while (($at = strpos($s, '@json(')) !== false) {
        $depth = 0;
        for ($i = $at + 5; $i < strlen($s); $i++) {
            if ($s[$i] === '(') $depth++;
            elseif ($s[$i] === ')' && --$depth === 0) break;
        }
        $expr = substr($s, $at + 6, $i - $at - 6);
        $s = substr($s, 0, $at) . '<?php echo json_encode(' . $expr . ', JSON_UNESCAPED_UNICODE); ?>' . substr($s, $i + 1);
    }

    // echoes
    $s = preg_replace('/\{!!\s*(.+?)\s*!!\}/s', '<?php echo $1; ?>', $s);
    $s = preg_replace('/\{\{\s*(.+?)\s*\}\}/s', '<?php echo e_($1); ?>', $s);

    // translation + Form facade
    $s = str_replace('Form::', 'FormStub::', $s);
    $s = preg_replace('/\btrans_choice\(/', '__tc(', $s);
    $s = preg_replace('/\b__\(/', '__t(', $s);

    /* Components LAST, and the order is the whole point.
     *
     * The expansion var_export()s each slot body into a PHP string literal. If
     * any pass runs AFTER that, it rewrites the Blade inside that literal --
     * and a half-rewritten @if whose paren scan then runs off the end of the
     * literal corrupts the generated file, surfacing as a parse error pointing
     * at a line of perfectly good Blade. (It did. Twice: once via a
     * re-scanning loop, once via pass ordering.)
     *
     * Running last means a slot body is already-compiled PHP by the time it is
     * quoted, and nothing touches it again. The recursive compile_blade() call
     * that ss_inline() makes on it is then a no-op for directives and still
     * expands any nested <x-...> the slot contains.
     */
    $s = ss_expand_components($s);

    return $s;
}

$src = file_get_contents($viewPath);
$php = compile_blade($src);

$tmp = tempnam(sys_get_temp_dir(), 'blade') . '.php';
file_put_contents($tmp, $php);

$__sections = [];
$__stacks = [];
$__cur = null;

/* The view runs in a FUNCTION scope, not the global one, for the same reason
   ss_clean_globals() exists: at the top level get_defined_vars() -- which the
   component expansion emits to pass a slot its surrounding scope -- would
   return the global symbol table including its own `GLOBALS` self-reference.
   The sections and stacks are passed by reference so the view still fills
   the ones the stand-in layout below reads. */
$__ssViewTmp = $tmp;
$__ssRenderView = function () use ($__ssViewTmp, &$__sections, &$__stacks, &$__cur) {
    extract(ss_clean_globals(), EXTR_SKIP);
    ob_start();
    include $__ssViewTmp;
    ob_end_clean();        // stray output is discarded; the sections are what matter
};
$__ssRenderView();
unlink($tmp);

// ---------------------------------------------------------------- stand-in layout
// Mirrors what layouts/app.blade.php actually emits for the chrome this screen
// sits in: the same <html dir>, the same body theme class, the same stylesheet
// branch, and the same four yields in the same order.
$dir      = $isRtl ? 'rtl' : '';
// The four stylesheet branches of layouts/app.blade.php:102-118.
if ($theme === 'dark') {
    $mainCss = $isRtl ? 'assets/css/rtl-style-dark.css' : 'assets/css/style-dark.css';
} else {
    $mainCss = $isRtl ? 'assets/css/style-rtl.css' : 'assets/css/style.css';
}
$customCss = $isRtl ? 'css/rtl-custom.css' : 'css/custom.css';

$title      = $__sections['page-title']    ?? '';
$breadcrumb = $__sections['breadcrumb']    ?? '';
$actions    = $__sections['action-button'] ?? '';
$content    = $__sections['content']       ?? '';
$pushedCss  = $__stacks['css']             ?? '';
// The page's own pushed scripts, kept inert inside a <template>: the host's
// jQuery, Bootstrap and jQuery UI are not loaded here, so running them would
// prove nothing, but the checks can still read what they would send.
$pushedJs   = ($__stacks['scripts'] ?? '') . ($__stacks['custom-script'] ?? '') . ($__stacks['custom-script1'] ?? '');

$html = <<<HTML
<!DOCTYPE html>
<html lang="{$locale}" dir="{$dir}" id="html-dir-tag">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Store Syria - {$title}</title>
<link rel="stylesheet" href="/assets/fonts/tabler-icons.min.css">
<link rel="stylesheet" href="/{$mainCss}" id="main-style-link">
<link rel="stylesheet" href="/{$customCss}" id="main-style-custom-link">
{$pushedCss}
<style>
  /* Harness-only adjustments, kept to the minimum:
     1. .dash-container carries margin-left:255px for the fixed sidebar
        (style.css:17230). The sidebar is not rendered here, so that offset is
        neutralised; .dash-content's own 20px gutter is left exactly as-is.
     2. Arabic is forced onto DejaVu Sans, the only Arabic-capable family
        installed in this container. Production serves Open Sans + the
        merchant's own webfont. */
  .dash-container { top: 0 !important; margin-left: 0 !important; margin-right: 0 !important; }
  [dir="rtl"] .ss-scope, [dir="rtl"] body { font-family: "DejaVu Sans", sans-serif; }
</style>
</head>
<body class="theme-3">
<!-- Real chrome structure from layouts/app.blade.php:188-214 -->
<div class="dash-container">
  <div class="dash-content">
    <div class="page-header mb-md-4 mb-3">
      <div class="page-block d-flex align-items-center justify-content-between gap-3 flex-wrap">
        <div class="page-header-wrp">
          <div class="page-header-title"><h2 class="h3 mb-0">{$title}</h2></div>
          <ul class="breadcrumb mt-2">
            <li class="breadcrumb-item"><a href="#dashboard">Home</a></li>
            {$breadcrumb}
          </ul>
        </div>
        <div class="page-header-icon d-flex flex-wrap gap-2">{$actions}</div>
      </div>
    </div>
    {$content}
  </div>
</div>
<template id="ss-pushed-scripts">{$pushedJs}</template>
</body>
</html>
HTML;

file_put_contents($out, $html);

$n = count($missing);
fwrite(STDERR, sprintf("rendered %s [%s/%s/%s] -> %s (%d bytes)%s\n",
    basename($viewPath), $locale, $state, $theme, $out, strlen($html),
    $n ? "  MISSING-TRANSLATIONS={$n}: " . implode(' | ', array_slice(array_keys($missing), 0, 6)) : "  all strings translated"));
