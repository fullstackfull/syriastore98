<?php

/**
 * RENDER THE DESIGN-SYSTEM COMPONENT GALLERY to a standalone HTML file, per combination.
 * =================================================================================================
 *
 * The coverage inventory carries each `x-ss.*` component as a surface of its own, and it is right
 * to: each one appears on many screens, so a defect in one is a defect everywhere at once. A
 * capture taken inside some host page is evidence for THAT PAGE -- one instance, one state, one
 * width.
 *
 * WHY A FILE AND NOT A ROUTE. A gallery route would be production code: a URL that ships, needs a
 * guard, and can be reached in an environment nobody meant it to be reachable in. This renders
 * through Laravel's own view factory -- the real Blade compiler, the real components, the real
 * `store-syria-ds.css` -- and writes an HTML file. Nothing is added to `routes/web.php`, nothing
 * is registered, and the application is unchanged.
 *
 * WHY EVERY COMPONENT IS GIVEN REAL CONTENT. An empty specimen renders a box, and a box proves
 * nothing about a component whose whole job is to present text, a number, a status or a control.
 * Where a component's point is that it has STATES, the states are rendered side by side in one
 * frame -- a status badge that looks the same in every tone is the defect, and a single-tone
 * capture cannot show it.
 *
 * usage:
 *   DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file \
 *     php tests/Support/DesignSystem/bin/render-ds-gallery.php <out-dir> <light|dark> <ltr|rtl> <en|ar>
 */

$root = dirname(__DIR__, 4);

require $root . '/vendor/autoload.php';

$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$script, $outDir, $theme, $dir, $lang] = array_pad($argv, 5, null);

if (! $outDir || ! in_array($theme, ['light', 'dark'], true)
    || ! in_array($dir, ['ltr', 'rtl'], true) || ! in_array($lang, ['en', 'ar'], true)) {
    fwrite(STDERR, "usage: render-ds-gallery.php <out-dir> <light|dark> <ltr|rtl> <en|ar>\n");
    exit(1);
}

app()->setLocale($lang);

/**
 * Every component, with content that makes its job visible.
 *
 * The key is the inventory's own component name, so a reader can map a specimen back to the row it
 * is evidence for without guessing.
 */
$specimens = [
    'x-ss.page-header' => <<<'BLADE'
        <x-ss.page-header :title="__('Orders')" :subtitle="__('Everything customers have bought')" />
    BLADE,

    'x-ss.breadcrumb' => <<<'BLADE'
        <x-ss.breadcrumb>
            <li class="ss-breadcrumb__item"><a href="#">{{ __('Dashboard') }}</a></li>
            <li aria-current="page" class="ss-breadcrumb__item">{{ __('Orders') }}</li>
        </x-ss.breadcrumb>
    BLADE,

    'x-ss.card' => <<<'BLADE'
        <x-ss.card :title="__('Recent activity')" :note="__('Last 7 days')">
            <p>{{ __('A card wraps a section of a page and gives it a title and a border.') }}</p>
        </x-ss.card>
    BLADE,

    // THE THREE TONES TOGETHER. A badge whose tones are indistinguishable is the defect, and no
    // single-tone capture can show it.
    'x-ss.status-badge' => <<<'BLADE'
        <div class="d-flex gap-2 flex-wrap">
            <x-ss.status-badge tone="success" :label="__('Delivered')" />
            <x-ss.status-badge tone="warning" :label="__('Pending')" />
            <x-ss.status-badge tone="danger" :label="__('Cancel')" />
            <x-ss.status-badge tone="neutral" :label="__('Draft')" />
        </div>
    BLADE,

    // `stat` and `stats` ARE SEPARATE INVENTORY ROWS, so they get separate specimens rather than
    // one picture doing duty for two surfaces. The tile alone shows the tile; the container with
    // three tiles shows what the container contributes -- the grid and its dividers.
    'x-ss.stat' => <<<'BLADE'
        <x-ss.stat :label="__('Total Orders')" value="126" :period="__('all time')"
                   :hint="__('Includes cancelled orders.')" />
    BLADE,

    'x-ss.stats' => <<<'BLADE'
        <x-ss.stats :label="__('This month')">
            <x-ss.stat :label="__('Total Orders')" value="126" :period="__('all time')" />
            <x-ss.stat :label="__('Total Products')" value="48" :period="__('in this store')" />
            <x-ss.stat :label="__('Total Delivered')" value="37" :period="__('all time')" />
        </x-ss.stats>
    BLADE,

    'x-ss.empty' => <<<'BLADE'
        <x-ss.empty :title="__('No orders yet')" :body="__('Orders will appear here once customers start buying.')" />
    BLADE,

    // `label` is required and `count`/`href` turn it into the interactive form, so both shapes
    // are shown: a chip that only ever renders inert is half the component.
    'x-ss.chip' => <<<'BLADE'
        <div class="d-flex gap-2 flex-wrap">
            <x-ss.chip :label="__('Pending')" />
            <x-ss.chip :label="__('COD')" :count="12" href="#" />
        </div>
    BLADE,

    // CHECKED AND UNCHECKED AND DISABLED. A switch that looks identical in two states says nothing.
    'x-ss.switch' => <<<'BLADE'
        <div class="d-flex flex-column gap-3">
            <x-ss.switch name="demo_on" :label="__('Enabled')" :checked="true" />
            <x-ss.switch name="demo_off" :label="__('Disabled')" :checked="false" />
            <x-ss.switch name="demo_locked" :label="__('Locked')" :checked="true" :disabled="true" />
        </div>
    BLADE,

    'x-ss.progress' => <<<'BLADE'
        <div class="d-flex flex-column gap-3">
            <x-ss.progress :value="25" :label="__('Setup')" />
            <x-ss.progress :value="80" :label="__('Almost done')" />
        </div>
    BLADE,

    'x-ss.help' => <<<'BLADE'
        <x-ss.help :title="__('About tax classes')">
            {{ __('A tax class groups the rates that apply to a product.') }}
        </x-ss.help>
    BLADE,

    'x-ss.field-help' => <<<'BLADE'
        <label class="ss-label" for="ss-gallery-field">{{ __('Store Name') }}</label>
        <input class="ss-input" id="ss-gallery-field" aria-describedby="ss-gallery-field-help">
        <x-ss.field-help for="ss-gallery-field">{{ __('Shown to customers at checkout.') }}</x-ss.field-help>
    BLADE,

    // It takes a real MessageBag, because that is what a failed submit hands it.
    'x-ss.error-summary' => <<<'BLADE'
        @php($ssErrors = new \Illuminate\Support\MessageBag([
            'name'  => [__('This field is required.')],
            'email' => [__('Enter a valid email address.')],
        ]))
        <x-ss.error-summary :errors="$ssErrors" :field-ids="['name' => 'f1', 'email' => 'f2']" />
    BLADE,

    // Same reasoning as stat/stats: `attention` and `attention-item` are separate rows.
    'x-ss.attention' => <<<'BLADE'
        <x-ss.attention :title="__('Needs attention')">
            <x-ss.attention-item :count="3" :text="__('orders awaiting confirmation')" href="#" />
            <x-ss.attention-item :count="1" :text="__('product out of stock')" href="#" />
        </x-ss.attention>
    BLADE,

    'x-ss.attention-item' => <<<'BLADE'
        <x-ss.attention-item :count="3" :text="__('orders awaiting confirmation')" href="#" />
    BLADE,

    'x-ss.actionbar' => <<<'BLADE'
        <x-ss.actionbar :label="__('Bulk actions')">
            <button type="button" class="ss-btn ss-btn--sm">{{ __('Export') }}</button>
            <button type="button" class="ss-btn ss-btn--sm ss-btn--danger">{{ __('Delete') }}</button>
        </x-ss.actionbar>
    BLADE,

    'x-ss.secret-field' => <<<'BLADE'
        <x-ss.secret-field name="api_key" :label="__('API key')" stored="sk_live_************"
                           :hint="__('Stored securely. Leave blank to keep the current key.')" />
    BLADE,

    'x-ss.filter-summary' => <<<'BLADE'
        <x-ss.filter-summary :filters="[__('Status') => __('Pending'), __('Payment') => __('COD')]"
                             clear-url="#" :clear-label="__('Clear filters')" />
    BLADE,

    'x-ss.setup-checklist' => <<<'BLADE'
        <x-ss.setup-checklist :steps="[
            ['label' => __('Add your first product'), 'done' => true,  'href' => '#'],
            ['label' => __('Set up a payment method'), 'done' => false, 'href' => '#'],
            ['label' => __('Choose a shipping method'), 'done' => false, 'href' => '#'],
        ]" />
    BLADE,

    // Copied from the REAL call site (`reports/order_chart_data.blade.php:27`) rather than
    // guessed: labels and values are parallel lists, not a map, and `title` is required.
    'x-ss.bar-chart' => <<<'BLADE'
        <x-ss.bar-chart
            :labels="[__('Mon'), __('Tue'), __('Wed'), __('Thu')]"
            :values="[8, 14, 11, 19]"
            :title="__('Orders per day')"
            :value-label="__('Orders')"
            :column-label="__('Day')"
            :period="__('last 7 days')" />
    BLADE,

    'x-ss.choice-group' => <<<'BLADE'
        <x-ss.choice-group name="charges_type" :legend="__('Charges type')"
                           :hint="__('How this amount is applied.')">
            <label class="ss-choice"><input type="radio" name="charges_type" checked> {{ __('Percentage') }}</label>
            <label class="ss-choice"><input type="radio" name="charges_type"> {{ __('Flat') }}</label>
        </x-ss.choice-group>
    BLADE,

    'x-ss.advanced-fields' => <<<'BLADE'
        <x-ss.advanced-fields
            :title="__('Advanced options')"
            :hint="__('Usually set once for the whole catalogue.')"
            :fields="['brand_id', 'label_id', 'product_weight']">
            <p>{{ __('Options most stores never need to change.') }}</p>
        </x-ss.advanced-fields>
    BLADE,

    'x-ss.panel (side sheet / drawer)' => <<<'BLADE'
        <x-ss.panel id="ss-gallery-panel"
                    :title="__('Order SS-1042')"
                    :loading-text="__('Loading…')"
                    :error-text="__('This could not be loaded. Please try again.')" />
    BLADE,

    'x-ss.confirm-dialog' => <<<'BLADE'
        <x-ss.confirm-dialog id="ss-gallery-confirm" />
        <p class="ss-help-text">{{ __('Rendered inert: the dialog opens on a destructive action.') }}</p>
    BLADE,

    'x-ss.report-filter' => <<<'BLADE'
        <x-ss.report-filter id="gallery" :label="__('Period')" selected="year" />
    BLADE,

    'x-ss.filter-disclosure' => <<<'BLADE'
        <x-ss.filter-disclosure id="ss-gallery-filters">
            <p>{{ __('Filters live behind a disclosure so the table stays the subject.') }}</p>
        </x-ss.filter-disclosure>
    BLADE,
];

$sections = '';

foreach ($specimens as $name => $blade) {
    // Each specimen is compiled by the REAL Blade compiler, so a component that would throw on a
    // page throws here too rather than being quietly skipped.
    try {
        $html = Illuminate\Support\Facades\Blade::render($blade);
    } catch (\Throwable $e) {
        fwrite(STDERR, "FAILED {$name}: " . $e->getMessage() . "\n");
        exit(1);
    }

    $sections .= '<section class="ss-gallery__item">'
        . '<p class="ss-gallery__name">' . e($name) . '</p>'
        . '<div class="ss-gallery__stage">' . $html . '</div>'
        . '</section>';
}

$css = file_get_contents($root . '/public/css/store-syria-ds.css');
// `data-ss-theme`, NOT `data-theme`. The design system's dark block is keyed on
// `[data-ss-theme="dark"]` (store-syria-ds.css:560) and the host's own `data-theme` means
// something else. The first version of this script used the wrong attribute and every one of the
// 24 components rendered IDENTICALLY in light and dark -- 96 captures, 48 distinct. That looked
// like twenty-four components ignoring the theme; it was one harness bug, and the only reason it
// was caught is that the distinct-capture count was checked before anything was claimed.
$themeAttr = $theme === 'dark' ? ' data-ss-theme="dark"' : ' data-ss-theme="light"';

$doc = <<<HTML
<!doctype html>
<html lang="{$lang}" dir="{$dir}"{$themeAttr}>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Store Syria design system — component gallery</title>
<style>{$css}</style>
<style>
  body { margin: 0; padding: 24px; background: var(--ss-bg, #f4f6fa); color: var(--ss-text, #1e222b);
         font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }
  .ss-gallery__item { margin-bottom: 28px; }
  .ss-gallery__name { margin: 0 0 8px; font-size: 11px; letter-spacing: .02em;
                      color: var(--ss-text-muted, #5b6665); font-family: ui-monospace, monospace; }
  .ss-gallery__stage { padding: 16px; border: 1px dashed var(--ss-border, #cfd9e3); border-radius: 10px; }
</style>
</head>
<body>
{$sections}
</body>
</html>
HTML;

if (! is_dir($outDir)) {
    mkdir($outDir, 0o755, true);
}

$file = rtrim($outDir, '/') . "/ds-gallery_{$lang}_{$dir}_{$theme}.html";
file_put_contents($file, $doc);

echo "wrote {$file} (" . count($specimens) . " specimens)\n";
