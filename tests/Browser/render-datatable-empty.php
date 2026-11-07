<?php

/**
 * Render an EMPTY merchant DataTable to standalone HTML, for P2.5-WAVE-6a's visual evidence.
 *
 *   php tests/Browser/render-datatable-empty.php <output-directory>
 *
 * WHAT IS REAL HERE, because that is the only thing that makes the screenshots worth anything:
 *
 *   * THE TABLE MARKUP is extracted verbatim from `resources/views/store/custom_domain.blade.php`
 *     (the `<div class="table-responsive">` region) and compiled by the REAL Blade compiler with an
 *     empty `$stores`. Nothing is retyped: if that view's markup changes, this changes with it.
 *   * THE FOUR LABEL STRINGS come from the REAL translator, through the same `__()` calls the
 *     layout makes, in the locale being rendered. So the Arabic page shows what an Arabic merchant
 *     would actually read, not a transliteration this script invented.
 *   * THE SCRIPTS AND STYLESHEETS are the shipped files, served from symlinked `public/js` and
 *     `public/assets`, plus `public/css/store-syria-ds.css`. `simple-datatables.js` and
 *     `custom.js` are the same bytes the merchant gets, so the empty row in the screenshot is the
 *     one the library really writes.
 *
 * WHAT IS A STAND-IN, stated plainly: the dashboard CHROME -- sidebar, header, the signed-in
 * merchant -- is absent, because it needs a database and an authenticated principal. The page
 * carries `<html data-ss-theme>` and `dir`, which is what the real layout publishes and what every
 * `--ss-*` token and every RTL rule reads, so the table and its empty state resolve exactly as they
 * do in the product. It is a table on a page, not a screenshot of the whole admin.
 */
require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$root = dirname(__DIR__, 2);
$target = $argv[1] ?? sys_get_temp_dir() . '/ss-datatable-empty';
if (! is_dir($target)) { mkdir($target, 0777, true); }

// --- the real table markup, taken from the real view -------------------------------------------
$view = file_get_contents($root . '/resources/views/store/custom_domain.blade.php');
$start = strpos($view, '<div class="table-responsive">');
$end = strpos($view, '</table>', $start);
if ($start === false || $end === false) {
    fwrite(STDERR, "FAILED: the table region is no longer where this script looks for it in\n"
        . "resources/views/store/custom_domain.blade.php. Re-read the view rather than\n"
        . "loosening this check -- a silently-wrong extraction is a screenshot of nothing.\n");
    exit(1);
}
$region = substr($view, $start, ($end + strlen('</table>')) - $start) . "\n</div>";

// Compiled by the real Blade compiler, so the @foreach really is the view's @foreach.
$renderRegion = static function (array $stores) use ($region) {
    return Illuminate\Support\Facades\Blade::render($region, ['stores' => $stores]);
};

foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $dir) {
    $app->setLocale($locale);

    // The four strings, through the SAME __() calls layouts/app.blade.php makes.
    $labels = [
        'dt-no-rows' => __('Nothing here yet'),
        'dt-no-rows-body' => __('Records will appear in this table as they are created.'),
        'dt-no-results' => __('No results match your search'),
        'dt-no-results-body' => __('Try a shorter term, or clear the search to see everything.'),
        'dt-search' => __('Search...'),
        'dt-per-page' => __('{select} entries per page'),
        'dt-info' => __('Showing {start} to {end} of {rows} entries'),
    ];
    $metas = '';
    foreach ($labels as $name => $content) {
        $metas .= '    <meta name="' . $name . '" content="' . e($content) . '">' . "\n";
    }

    $heading = $locale === 'ar' ? 'النطاق المخصص' : 'Custom Domain';
    $table = $renderRegion([]);

    foreach (['light' => '', 'dark' => ' data-ss-theme="dark" data-ss-theme-source="host"'] as $theme => $themeAttr) {
        /*
         * THE SAME STYLESHEET PAIR THE REAL LAYOUT PICKS, and this is the second correction the
         * live pass forced. The first version loaded only the plugin sheet and the design system,
         * so the search box rendered WHITE on the dark card -- and that looked exactly like a
         * dark-mode defect until it was traced: `custom.css:4823` carries a dark arm for
         * `.dataTable-input`, and the harness simply was not loading custom.css. An evidence
         * harness that omits a stylesheet does not show a defect, it MANUFACTURES one.
         *
         * layouts/app.blade.php:201-215 branches four ways on (dark, RTL); this mirrors it.
         */
        $layoutSheet = $theme === 'dark'
            ? ($dir === 'rtl' ? 'assets/css/rtl-style-dark.css' : 'assets/css/style-dark.css')
            : ($dir === 'rtl' ? 'assets/css/style-rtl.css' : 'assets/css/style.css');
        $customSheet = $dir === 'rtl' ? 'css/rtl-custom.css' : 'css/custom.css';
        $html = <<<HTML
<!doctype html>
<html lang="{$locale}" dir="{$dir}"{$themeAttr}>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$heading}</title>
    <meta name="base-url" content="http://127.0.0.1">
    <meta name="csrf-token" content="harness">
{$metas}
    <link rel="stylesheet" href="/assets/css/plugins/style.css">
    <link rel="stylesheet" href="/css/custom-color.css">
    <link rel="stylesheet" href="/assets/fonts/tabler-icons.min.css">
    <link rel="stylesheet" href="/{$layoutSheet}" id="main-style-link">
    <link rel="stylesheet" href="/{$customSheet}" id="main-style-custom-link">
    <link rel="stylesheet" href="/css/store-syria-ds.css">
    <style>
        body { margin: 0; padding: 32px; background: var(--ss-bg, #f9fafb); color: var(--ss-fg, #111); }
        .harness-card { max-width: 860px; margin: 0 auto; background: var(--ss-surface, #fff);
                        border: 1px solid var(--ss-border, #e5e7eb); border-radius: 12px; padding: 24px; }
        h1 { font-size: 20px; margin: 0 0 20px; }
    </style>
</head>
<body>
    <div class="harness-card">
        <h1>{$heading}</h1>
        {$table}
    </div>
    <script src="/js/jquery-3.6.0.min.js"></script>
    <script src="/assets/js/plugins/simple-datatables.js"></script>
    <script src="/js/custom.js"></script>
</body>
</html>
HTML;
        $out = "{$target}/custom-domain-{$locale}-{$theme}.html";
        file_put_contents($out, $html);
        echo "wrote {$out} (" . strlen($html) . " bytes)\n";

        /*
         * THE SECOND EMPTY STATE NEEDS ROWS TO FILTER AWAY. `noResults` is half this change and
         * nothing exercises it on an already-empty table -- the library only reaches that label
         * when a search removes every row. So the same page is written again WITH fixture rows,
         * and the browser check types a term that matches none of them.
         */
        $populated = str_replace($table, $renderRegion([
            (object) ['domains' => 'shop.example.com', 'name' => 'Damascus Store', 'email' => 'damascus@example.test'],
            (object) ['domains' => 'store.example.net', 'name' => 'Aleppo Store', 'email' => 'aleppo@example.test'],
            (object) ['domains' => 'market.example.org', 'name' => 'Homs Store', 'email' => 'homs@example.test'],
        ]), $html);
        $outPopulated = "{$target}/populated-{$locale}-{$theme}.html";
        file_put_contents($outPopulated, $populated);
        echo "wrote {$outPopulated} (" . strlen($populated) . " bytes)\n";
    }
}
