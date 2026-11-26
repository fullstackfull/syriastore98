<?php
/**
 * GAP-060 / P02-I4 -- stage 1 of 2: produce the FOUR real configurations as static pages.
 *
 * USAGE   php docs/development/phase-2/tools/render-choice-guidance-surfaces.php [outDir]
 *         node docs/development/phase-2/tools/check-choice-guidance-surfaces.cjs [outDir]
 *
 * PRECONDITIONS: the repository's composer autoloader; no database, no running server and no
 * login -- every partial rendered here needs none, which is the whole reason this harness is a
 * cascade harness and not a live one. `check-choice-guidance-live.cjs` beside it drives the real
 * application against the product editor; this one covers the ELEVEN screens P02-I4's coverage
 * row adds, six of which are AJAX modal partials that a live harness can only reach by opening
 * each modal.
 *
 * WHY IT EXISTS AT ALL, and the figure is the argument: with the full admin cascade and the
 * `.modal` wrapper in place it measured `.ss-choicegroup__legend` at 1.21:1 and `.ss-field__hint`
 * at 2.30:1 on the host's WHITE dark-modal surface. Neither figure is visible from the markup,
 * from the design-system tokens, or from a harness that loads four stylesheets instead of
 * nineteen -- see the two notes below, each of which records a false reading this harness produced
 * before it was corrected.
 *
 * The four are not a guess. `layouts/app.blade.php` swaps `#main-style-link` between style.css,
 * style-dark.css, style-rtl.css and rtl-style-dark.css server-side and sets `dir` and
 * `data-ss-theme` on <html>; the design system is always loaded after it. This reproduces that
 * head exactly, because the merchant admin's dark mode is a STYLESHEET SWAP and not
 * `prefers-color-scheme`, so emulating a colour scheme would produce two identical light pages.
 */
/* tools -> phase-2 -> development -> docs -> repository root. */
$repo = dirname(__DIR__, 4);
require $repo . '/vendor/autoload.php';
$app = require_once $repo . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Blade;

/* The output directory is the first argument; it defaults to a temp folder so a run leaves
   nothing in the repository. The checker takes the same path as ITS first argument. */
$out = $argv[1] ?? (sys_get_temp_dir() . '/gap060-choice-guidance');
if (! is_dir($out) && ! mkdir($out, 0775, true)) {
    fwrite(STDERR, "cannot create $out\n");
    exit(1);
}
$pub = $repo . '/public';

$method = new App\Models\ShippingMethod([
    'method_name' => 'Flat Rate', 'cost' => 10, 'calculation_type' => '1',
    'shipping_requires' => '3', 'product_cost' => null,
]);
$method->id = 3;

$zone = new App\Models\ShippingZone(['zone_name' => 'Damascus', 'country_id' => 1, 'state_id' => 1]);
$zone->id = 4;

$coupon = new App\Models\Coupon([
    'coupon_name' => 'SPRING', 'coupon_type' => 'percentage', 'coupon_code' => 'SPRING10',
    'discount_amount' => 10, 'coupon_limit' => 5, 'status' => 1,
]);
$coupon->id = 7;

/* The six partials that render with no database. Each is the REAL view. */
$modals = [
    'coupon/create' => ['coupon.create', ['product' => [], 'category' => []]],
    'coupon/edit' => ['coupon.edit', [
        'coupon' => $coupon, 'product' => [], 'category' => [], 'applied_product' => [],
        'exclude_product' => [], 'applied_categories' => [], 'exclude_categories' => [],
    ]],
    'shipping_method/edit' => ['shipping_method.edit', [
        'shippingMethod' => $method, 'shippings' => [], 'shippings_count' => 0,
    ]],
    'shipping_method/free_shipping' => ['shipping_method.free_shipping', ['shippingMethod' => $method]],
    'shippingzone/create' => ['shippingzone.create', ['country_option' => [1 => 'Syria']]],
    'shippingzone/edit' => ['shippingzone.edit', [
        'shippingzone' => $zone, 'country_option' => [1 => 'Syria'],
        'state_option' => [1 => 'Damascus'], 'zone_selected' => ['Flat Rate'],
    ]],
];

/*
 * The three ORDER screens extend `layouts/app` and need an authenticated principal plus an order
 * aggregate, so the fragment is rendered through Blade on its REAL surface instead. The markup is
 * the markup inserted into those views; `ChoiceGuidanceCoverageTest` is what pins that the views
 * really carry these strings and these `aria-describedby` values, so this stage only has to put
 * them on the right background.
 */
$fragments = [
    'order/createOrder' => ['card', <<<'BLADE'
<div class="col-md-6"><div class="form-group">
  {{ Form::label('payment_type', __('Payment Type'), ['class' => 'form-label']) }}<span class="ss-label__required" aria-hidden="true">*</span>
  <select name="payment_type" class="form-control select2" aria-describedby="payment_type-help" required>
    <option value="cod">{{ __('Cash On Delivery') }}</option>
  </select>
  <x-ss.field-help for="payment_type">{{ __('Record how the customer is paying. Set the payment status below once the money has reached you.') }}</x-ss.field-help>
</div></div>
<div class="col-md-6"><div class="form-group">
  {{ Form::label('payment_status', __('Payment Status'), ['class' => 'form-label']) }}<span class="ss-label__required" aria-hidden="true">*</span>
  <select name="payment_status" class="form-control" aria-describedby="payment_status-help" required>
    <option value="Unpaid">{{ __('Unpaid') }}</option><option value="Paid">{{ __('Paid') }}</option>
  </select>
  <x-ss.field-help for="payment_status">{{ __('Choose Paid only if the money has already reached you. You can change this later on the order page.') }}</x-ss.field-help>
</div></div>
BLADE],
    'order/view' => ['ss-card', <<<'BLADE'
<div class="form-group col-md-4">
  {!! Form::label('note_type', __('Note Type'), ['class' => 'form-label']) !!}
  {!! Form::select('note_type', ['private_note' => __('Private Note'), 'to_customer' => __('To Customer')], null, ['class' => 'form-control ss-select', 'id' => 'note_type', 'aria-describedby' => 'note_type-help']) !!}
  <x-ss.field-help for="note_type">{{ __('A private note is for your team only. To customer shows the note to the customer on their order page.') }}</x-ss.field-help>
</div>
BLADE],
    'order/refund_details' => ['card', <<<'BLADE'
<div class="col-md-4">
  <label class="form-check-label" for="customCheckdef1trending"><b>{{ __('Refund Shipping Amount')}} : </b></label>
  <input type="hidden" name="trending" value="0">
  {!! Form::checkbox("trending", 1, null, ["class" => "form-check-input input-primary check-shipp", "id"=>"customCheckdef1trending", "aria-describedby" => "refund_shipping-help"]) !!}
  <x-ss.field-help for="refund_shipping">{{ __('Tick this to add the delivery charge to the refund. The refund total is saved as soon as you tick it.') }}</x-ss.field-help>
</div>
BLADE],
    /*
     * The role-permission matrix needs `get_permission_by_module()` and the module registry, which
     * no test run loads. Its two added elements are the design system component's own output, so
     * the component is rendered here with the legend and hint the view passes it, on the same
     * `.modal-body` the roles modal uses. Same markup, same surface, same theme: the contrast
     * question is identical.
     */
    'roles/create+edit' => ['modal', <<<'BLADE'
<x-ss.choice-group name="permissions" :legend="__('Permissions')"
    :hint="__('Tick only what this role needs. Anyone you give this role can do everything ticked here.')">
  <div class="form-check"><input class="form-check-input" type="checkbox" id="perm_demo"><label class="form-check-label" for="perm_demo">{{ __('Manage Order') }}</label></div>
</x-ss.choice-group>
BLADE],
];

$surfaces = [
    /*
     * THE `.modal` WRAPPER IS LOAD-BEARING AND LEAVING IT OUT PRODUCED A FALSE READING.
     *
     * Bootstrap declares `--bs-modal-bg` on `.modal`, and `style-dark.css:6234` sets it to
     * #ffffff -- so the host's dark modal has a WHITE surface, which `css/custom.css`'s F126 note
     * documents at length. Without the wrapper the variable was undefined, `.modal-content`
     * computed `rgba(0,0,0,0)`, and the option labels measured 1.09:1 against the body's dark
     * background: a number produced entirely by the harness. `#commanModel` in
     * `layouts/app.blade.php` is `.modal.fade > .modal-dialog > .modal-content > .modal-body`, so
     * that is what this reproduces. `.show` and `display:block` stand in for what Bootstrap's JS
     * does when the modal opens, because a `display:none` subtree has no measurable boxes.
     */
    'modal' => ['<div class="modal fade show" style="display:block;position:static"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-body">', '</div></div></div></div>'],
    'card' => ['<div class="card"><div class="card-body"><div class="row">', '</div></div></div>'],
    'ss-card' => ['<div class="ss-card p-0"><div class="card-body ss-card__body"><div class="row">', '</div></div></div>'],
];

$combos = [
    'en-light' => ['en', 'ltr', 'assets/css/style.css', false],
    'en-dark' => ['en', 'ltr', 'assets/css/style-dark.css', true],
    'ar-light' => ['ar', 'rtl', 'assets/css/style-rtl.css', false],
    'ar-dark' => ['ar', 'rtl', 'assets/css/rtl-style-dark.css', true],
];

foreach ($combos as $combo => [$locale, $dir, $hostSheet, $dark]) {
    app()->setLocale($locale);

    $body = '';

    foreach ($modals as $case => [$view, $data]) {
        [$open, $close] = $surfaces['modal'];
        $body .= '<section data-case="' . $case . '">' . $open
            . view($view, $data)->render() . $close . "</section>\n";
    }

    foreach ($fragments as $case => [$surface, $blade]) {
        [$open, $close] = $surfaces[$surface];
        $body .= '<section data-case="' . $case . '">' . $open
            . Blade::render($blade) . $close . "</section>\n";
    }

    /*
     * THE WHOLE CASCADE, IN THE LAYOUT'S OWN ORDER. The first version of this harness loaded only
     * `#main-style-link` and the design system, and it read the dark modal's option labels at
     * 4.03:1 -- a figure produced by the MISSING SHEETS, not by the page. `css/custom.css` and its
     * RTL twin carry `html[data-ss-theme="dark"] body .modal-content label`, which is exactly the
     * declaration that decides those labels, and `SwitchAccessibleNameTest` pins it. A contrast
     * number measured off an incomplete cascade is not a measurement of the product.
     */
    $rtl = $dir === 'rtl';
    $sheets = array_merge([
        'assets/css/plugins/select2.min.css',
        'assets/css/plugins/notifier.css',
        'assets/css/plugins/bootstrap-switch-button.min.css',
        'assets/css/plugins/style.css',
        'css/custom-color.css',
        'assets/css/customizer.css',
        'assets/css/plugins/dropzone.css',
        $hostSheet,
    ], $rtl
        ? ['css/rtl-custom.css', 'css/rtl-loader.css']
        : ['css/custom.css', 'css/loader.css'], [
        'css/store-syria-a11y.css',
        'css/store-syria-ds.css',
        /*
         * THE SHEETS THAT LAND AFTER THE DESIGN SYSTEM. `css/custom.css`'s F126 note records that
         * a harness loading four sheets read a modal title at 3.84:1 where the running application
         * read 1.00:1, because ten sheets land after `store-syria-ds.css` and it had none of them.
         * These are the ones `layouts/app.blade.php` names after that line and that exist on disk;
         * the Leaflet sheet is cross-origin and is the one this harness cannot reproduce.
         */
        'assets/css/plugins/flatpickr.min.css',
        'assets/css/summernote/summernote-bs4.css',
        'css/emojionearea.min.css',
        'css/calendar.css',
    ]);

    $head = '';
    foreach ($sheets as $sheet) {
        if (! is_file($pub . '/' . $sheet)) {
            fwrite(STDERR, "MISSING SHEET: $sheet\n");
            exit(1);
        }
        $id = $sheet === $hostSheet ? ' id="main-style-link"' : '';
        $head .= '<link rel="stylesheet" href="file://' . $pub . '/' . $sheet . '"' . $id . '>';
    }

    $html = '<!doctype html><html lang="' . $locale . '" dir="' . $dir . '" id="html-dir-tag"'
        . ($dark ? ' data-ss-theme="dark" data-ss-theme-source="host"' : '') . '>'
        . '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . $head . '</head><body><div class="container-fluid py-4">' . $body . '</div></body></html>';

    file_put_contents("$out/page-$combo.html", $html);
    echo "wrote page-$combo.html (" . strlen($html) . " bytes)\n";
}
