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
function __t($key)
{
    global $T, $missing;
    if (isset($T[$key]) && $T[$key] !== '') return $T[$key];
    $missing[$key] = true;
    return $key;
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

// ---------------------------------------------------------------- stub helpers
function route($name, $p = null) { return '#' . $name . ($p !== null ? '/' . (is_object($p) ? $p->id : $p) : ''); }
function asset($p) { return '/' . ltrim($p, '/'); }
function e_($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

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

// ---------------------------------------------------------------- Blade subset
function compile_blade($src)
{
    global $repo;

    // Comments first, so directives inside them never compile.
    $s = preg_replace('/\{\{--.*?--\}\}/s', '', $src);

    // @include('a.b.c') -> resources/views/a/b/c.blade.php, inlined before the
    // rest of the compile so the partial's own directives are handled too.
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

    // control flow
    $s = preg_replace('/@if\s*(\(.*?\))\s*$/m', '<?php if $1: ?>', $s);
    $s = preg_replace('/@if\s*(\(.*?\))/', '<?php if $1: ?>', $s);
    $s = preg_replace('/@elseif\s*(\(.*?\))/', '<?php elseif $1: ?>', $s);
    $s = preg_replace('/@else\b/', '<?php else: ?>', $s);
    $s = preg_replace('/@endif\b/', '<?php endif; ?>', $s);
    $s = preg_replace('/@foreach\s*(\(.*?\))/', '<?php foreach $1: ?>', $s);
    $s = preg_replace('/@endforeach\b/', '<?php endforeach; ?>', $s);
    $s = preg_replace('/@php\s*\((.+?)\)/', '<?php $1; ?>', $s);

    // echoes
    $s = preg_replace('/\{!!\s*(.+?)\s*!!\}/s', '<?php echo $1; ?>', $s);
    $s = preg_replace('/\{\{\s*(.+?)\s*\}\}/s', '<?php echo e_($1); ?>', $s);

    // translation + Form facade
    $s = str_replace('Form::', 'FormStub::', $s);
    $s = preg_replace('/\b__\(/', '__t(', $s);

    return $s;
}

$src = file_get_contents($viewPath);
$php = compile_blade($src);

$tmp = tempnam(sys_get_temp_dir(), 'blade') . '.php';
file_put_contents($tmp, $php);

$__sections = [];
$__stacks = [];
$__cur = null;

ob_start();
include $tmp;
ob_end_clean();            // the view's own stray output is discarded; sections are what matter
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
</body>
</html>
HTML;

file_put_contents($out, $html);

$n = count($missing);
fwrite(STDERR, sprintf("rendered %s [%s/%s/%s] -> %s (%d bytes)%s\n",
    basename($viewPath), $locale, $state, $theme, $out, strlen($html),
    $n ? "  MISSING-TRANSLATIONS={$n}: " . implode(' | ', array_slice(array_keys($missing), 0, 6)) : "  all strings translated"));
