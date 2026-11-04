<?php

namespace Tests\Feature\Merchant;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F-show-readonly — the read-only product screen locked the wrong things and broke the chrome.
 * =================================================================================================
 *
 * `resources/views/product/show.blade.php` opened its ready callback with two DOCUMENT-WIDE lines:
 *
 *     $('input').attr('readonly', true);
 *     $('select').attr('readonly', true);
 *
 * Both are wrong in opposite directions, and each half is measured below rather than reasoned
 * about.
 *
 * TOO WIDE — THE REAL, USER-VISIBLE HARM. `$('input')` is not scoped to the screen's content, and
 * the shared admin chrome is already in the DOM when the pushed `custom-script` stack runs
 * (`resources/views/layouts/app.blade.php`: sidebar :467, content :531, command palette :624,
 * footer links :626, stack :628 — and `$(document).ready` waits for the full parse regardless). So
 * the sweep made `#sideBarsearch` (`resources/views/partision/sidebar.blade.php:60`) and
 * `#ss-command-input` (`resources/views/partision/command-palette.blade.php:25`) read-only on this
 * one route: the sidebar's keyup nav filter (`layouts/app.blade.php:718`) could never see a value
 * change, and the click-to-palette hop at `public/js/store-syria-ds.js:1171` focused an input that
 * also refused typing. Two pieces of shared navigation, dead on /product/{id} only.
 *
 * TOO NARROW — `readonly` IS NOT A CONTENT ATTRIBUTE OF `<select>`, a checkbox or a radio, and no
 * CSS compensates: the only `[readonly]` rule in the design system is
 * `public/css/store-syria-ds.css:784`, prefixed `.ss-input`, and these are un-prefixed Bootstrap
 * controls. So every select and every switch on the screen stayed fully interactive.
 *
 * AND THE PART THE FIRST DRAFT MISSED, which is why this test pins two specific lines by name.
 * `public/js/custom.js` is loaded at `resources/views/partision/footerlink.blade.php:64` (layout
 * :626) and only REGISTERS a ready handler, so its `comman_function()` runs BEFORE this view's own
 * ready callback and rebuilds five of the controls:
 *
 *   - `custom.js:217` turns the three `summernote-simple-product` textareas (show.blade.php :350,
 *     :354, :358) into summernote editors — the textarea is hidden and a `contenteditable`
 *     sibling takes over, so `readonly` on the textarea is a NO-OP. The toolbar includes
 *     `codeview`. Nothing in the repository ever called `summernote('disable')`.
 *   - `custom.js:168` builds a Choices widget for every `[data-role="tagsinput"]` select — here
 *     `tax_id` (:68) and `attribute_options_*` (:522). Choices reads the disabled state ONCE, at
 *     construction: `public/assets/js/plugins/choices.min.js` ends its init with
 *     `(!this.config.addItems || this.passedElement.element.hasAttribute("disabled")) &&
 *     this.disable()`, and that file contains ZERO `MutationObserver` (measured). So disabling
 *     such a select AFTER init changes nothing, and the attribute has to be present BEFORE —
 *     which is why the lock for those two runs at script top level, not inside ready.
 *
 * WHY THIS IS A SOURCE TEST. The harm is JavaScript runtime behaviour on a page PHPUnit does not
 * execute, so there is nothing in the rendered HTML to assert: the defect and the repair look
 * identical server-side. What can be pinned is the script the view ships. The one half that IS
 * server-side — that the injected variant panel renders anything at all — is driven for real
 * below, because asserting the locker reaches injected controls on a route whose panel is empty
 * would pass for free.
 */
class ProductShowReadOnlyLockTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const VIEW = 'resources/views/product/show.blade.php';

    /** The raw view, exactly as Blade receives it. */
    private function raw(): string
    {
        return (string) file_get_contents(base_path(self::VIEW));
    }

    /**
     * The view with Blade comments removed — the same stripping
     * `ValidationFeedbackVisibilityTest::source()` uses, so a census assertion here measures the
     * same text that one does.
     */
    private function stripped(): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $this->raw());
    }

    // =============================================================================================
    // 1. The chrome fix — the only limb of this finding with live user-visible harm
    // =============================================================================================

    public function test_the_view_does_not_sweep_the_whole_document_read_only(): void
    {
        $source = $this->raw();

        $this->assertStringContainsString(
            'readonly',
            $source,
            'premise: this view really is the read-only screen and really does lock controls. '
            . 'If this fails the test is pointed at the wrong file and every absence below is free.'
        );

        /*
         * Both quoting styles and both tag names, so the defect cannot come back wearing double
         * quotes. The Blade comments that DESCRIBE the old sweep are stripped first — one at
         * show.blade.php:634 used to quote it verbatim.
         */
        foreach (["'input'", '"input"', "'select'", '"select"', "'textarea'", '"textarea"'] as $needle) {
            $this->assertStringNotContainsString(
                '$(' . $needle . ')',
                $this->stripped(),
                "an UNSCOPED jQuery sweep \$({$needle}) is back in " . self::VIEW . ". The shared "
                . 'chrome is in the DOM when the custom-script stack runs, so a document-wide '
                . 'selector reaches #sideBarsearch (partision/sidebar.blade.php:60) and '
                . '#ss-command-input (partision/command-palette.blade.php:25) and kills the '
                . 'sidebar nav filter plus its command-palette fallback on this route only.'
            );
        }
    }

    public function test_the_lock_is_scoped_to_a_wrapper_this_page_actually_has(): void
    {
        $source = $this->raw();

        $this->assertSame(
            1,
            substr_count($source, 'id="ss-product-show"'),
            'the content wrapper needs exactly one #ss-product-show for the lock to scope to. '
            . 'The auditor\'s suggested #choice_form cannot be used: id="choice_form" has zero '
            . 'occurrences anywhere in resources/views, and this view has no <form> at all.'
        );

        $this->assertStringContainsString(
            '<div class="row pt-4" id="ss-product-show">',
            $source,
            'the id belongs on the single `row pt-4` content wrapper, which closes at the end of '
            . 'the section and therefore encloses every static control and `.attribute_combination` '
            . 'but NOT the appended _variant-delete-dialog include.'
        );

        /* Every readonly/disabled mutation must hang off that scope, not off the document. */
        $this->assertMatchesRegularExpression(
            '/\$scope\s*=\s*\$\(\s*[\'"]#ss-product-show[\'"]\s*\)/',
            $source,
            'the locker must resolve #ss-product-show and call .find() from it'
        );
    }

    /**
     * NEGATIVE CONTROL for the scoping: the chrome inputs are not in this view, they come from the
     * layout's own includes — so scoping the sweep to this view's wrapper is what removes them from
     * its reach. If this ever fails, the scope argument above is void and the test must be redone.
     */
    public function test_the_chrome_inputs_live_outside_this_view_so_scoping_really_excludes_them(): void
    {
        $this->assertStringNotContainsString('sideBarsearch', $this->raw(),
            'premise: the sidebar search is not declared in this view');
        $this->assertStringNotContainsString('ss-command-input', $this->raw(),
            'premise: the command palette input is not declared in this view');

        $this->assertStringContainsString(
            'id="sideBarsearch"',
            (string) file_get_contents(base_path('resources/views/partision/sidebar.blade.php')),
            'premise: #sideBarsearch is declared by the shared sidebar partial'
        );
        $this->assertStringContainsString(
            'id="ss-command-input"',
            (string) file_get_contents(base_path('resources/views/partision/command-palette.blade.php')),
            'premise: #ss-command-input is declared by the shared command-palette partial'
        );

        $layout = (string) file_get_contents(base_path('resources/views/layouts/app.blade.php'));
        $this->assertLessThan(
            strpos($layout, "@stack('custom-script')"),
            strpos($layout, "@include('partision.sidebar')"),
            'premise: the sidebar is parsed before the pushed script, so an unscoped sweep reaches it'
        );
        $this->assertLessThan(
            strpos($layout, "@stack('custom-script')"),
            strpos($layout, "@include('partision.command-palette')"),
            'premise: the palette is parsed before the pushed script'
        );
    }

    // =============================================================================================
    // 2. The two controls that need library-specific handling — measured, and easy to delete
    // =============================================================================================

    public function test_choices_backed_selects_are_disabled_before_custom_js_builds_them(): void
    {
        $source = $this->raw();

        $this->assertMatchesRegularExpression(
            '/select\[data-role="tagsinput"\][\'"]\s*\)\s*\n?\s*\.?attr\(\s*[\'"]disabled[\'"]\s*,\s*[\'"]disabled[\'"]\s*\)/',
            $source,
            'The two [data-role="tagsinput"] selects (tax_id :68, attribute_options_* :522) are '
            . 'turned into Choices widgets by public/js/custom.js:168. Choices reads the state '
            . 'once at construction — choices.min.js ends init with '
            . '(!config.addItems || element.hasAttribute("disabled")) && this.disable() — and that '
            . 'file has ZERO MutationObserver, so prop(\'disabled\') applied after init does '
            . 'NOTHING. It must be a content ATTRIBUTE, set before custom.js\'s ready callback runs.'
        );

        /*
         * ORDER IS THE WHOLE POINT: the statement has to be at script TOP LEVEL, before this
         * view's own `$(document).ready`, because custom.js (footerlink :64, layout :626) has
         * already REGISTERED its ready handler and therefore runs first among ready callbacks.
         */
        $tagsinputLock = strpos($source, 'select[data-role="tagsinput"]');
        $readyStart = strpos($source, '$(document).ready(function() {');

        $this->assertNotFalse($tagsinputLock);
        $this->assertNotFalse($readyStart);
        $this->assertLessThan(
            $readyStart,
            $tagsinputLock,
            'the tagsinput lock is inside a ready callback, which is too late: custom.js '
            . 'registered its ready handler first and will have already constructed Choices.'
        );
    }

    public function test_the_summernote_editors_are_disabled_through_their_own_api(): void
    {
        $source = $this->raw();

        $this->assertStringContainsString(
            "summernote('disable')",
            $source,
            'public/js/custom.js:217 converts the three summernote-simple-product textareas '
            . '(:350 description, :354 specification, :358 detail) into WYSIWYG editors with a '
            . 'codeview button. The textarea is hidden and a contenteditable sibling takes over, '
            . 'so readonly on the textarea is a measured no-op. summernote(\'disable\') exists in '
            . 'the vendored build (public/assets/css/summernote/summernote-bs4.js, key:"disable") '
            . 'and is the only thing that actually locks them.'
        );

        $this->assertStringContainsString(
            "data('summernote')",
            $source,
            'guard the disable call on the instance existing, so the view does not throw if '
            . 'custom.js has not built the editors'
        );
    }

    /**
     * NEGATIVE CONTROL: the repair must not have achieved its assertions by DELETING the controls.
     * Every field the screen exists to display is still rendered, and the three `checked` readers
     * the script depends on are intact — `disabled` does not change `checked`.
     */
    public function test_the_repair_did_not_remove_the_controls_or_break_the_checked_readers(): void
    {
        $source = $this->raw();

        /*
         * The MARKUP occurrences only — the repair's own selector and its explanatory comment
         * also contain the class name, so a bare substring count measures the wrong thing. That
         * is the same trap `ValidationFeedbackVisibilityTest` records for `invalid-feedback`.
         */
        $this->assertSame(
            3,
            preg_match_all('/Form::textarea\([^)]*summernote-simple-product/', $source),
            'the three rich-text fields must still be RENDERED, only locked'
        );
        $this->assertSame(
            2,
            preg_match_all('/data-role="tagsinput"(?!\])/', $source),
            'both Choices-backed selects must still be rendered, only disabled. The negative '
            . 'lookahead excludes the repair\'s own `select[data-role="tagsinput"]` selector.'
        );

        $this->assertStringContainsString("'id' => 'enable_product_stock'", $source,
            'type() reads $(\'#enable_product_stock\').is(":checked")');
        $this->assertStringContainsString('name="track_stock" value="0"', $source,
            'the hidden companion of the stock switch must survive — removing it changes the '
            . 'initial show/hide state');
        $this->assertStringContainsString("is(\":checked\")", $source,
            'the checked reader in type() must be untouched');
        $this->assertStringContainsString("\$('.enable_product_variant').prop('checked')", $source,
            'the variant toggles read checked, which `disabled` does not affect');
    }

    // =============================================================================================
    // 3. The injected variant panel — driven for real, because the static assertion is free
    // =============================================================================================

    public function test_the_injected_variant_panel_is_relocked_after_each_ajax_replacement(): void
    {
        $source = $this->raw();

        /*
         * Both success callbacks, not one. `attribute_option_data()` runs on load and
         * `update_attribute()` on every attribute change, and the ready-time lock has long since
         * finished when either replaces the panel's HTML.
         */
        $this->assertSame(
            2,
            preg_match_all(
                '/\$\(\'\.attribute_combination\'\)\.html\(data\);\s*\n\s*ssLockProductShow\(\);/',
                $source
            ),
            'both $(\'.attribute_combination\').html(data) call sites must re-run the locker '
            . 'immediately. The injected partial (product/attribute_combinations_data.blade.php) '
            . 'brings its own selects, checkboxes, radios and file inputs with no read-only '
            . 'awareness at all, and the ready-time lock ran before the injection.'
        );

        /*
         * The locker must be a TOP-LEVEL declaration, or the callbacks in the later <script> block
         * cannot see it. A function declared inside the ready callback is not in global scope.
         */
        $this->assertMatchesRegularExpression(
            '/^\s{0,12}function ssLockProductShow\(\) \{/m',
            $source,
            'ssLockProductShow must be declared at script top level: the AJAX success callbacks '
            . 'live in a different <script> block and only share global scope.'
        );
        $this->assertLessThan(
            strpos($source, '$(document).ready(function() {'),
            strpos($source, 'function ssLockProductShow()'),
            'the declaration must precede the ready callback that calls it'
        );
    }

    /**
     * PREMISE for the test above: the panel this locker re-locks can really contain controls.
     * On the SHOW route it renders EMPTY today, because `$('#choice_form').serialize()` is `''`
     * (there is no such form) so the controller reaches `Product::find(null)` and refuses with an
     * empty collection. Asserting against that route would pass for free. So the endpoint is
     * driven with a REAL product id, which is the state the path will be in the moment anyone
     * repairs the dead serialize.
     */
    public function test_the_injected_panel_really_renders_lockable_controls_for_a_real_product(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Products');

        $categoryId = (int) \DB::table('categories')->insertGetId([
            'name' => 'Textiles',
            'slug' => 'textiles-' . uniqid(),
            'trending' => 0,
            'status' => 1,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = Product::forceCreate([
            'name' => 'Scarf',
            'slug' => 'scarf-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'track_stock' => 1,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 7,
            'category_id' => $categoryId,
            'status' => 1,
            'variant_product' => 1,
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);

        ProductVariant::forceCreate([
            'product_id' => $product->id,
            'variant' => 'M-Natural',
            'sku' => 'SKU-LOCK-PREMISE',
            'price' => 12,
            'variation_price' => 12,
            'stock' => 3,
            'store_id' => $store->id,
        ]);

        $html = (string) $this->actingAs($merchant)
            ->post(route('products.attribute_combination_data'), ['id' => $product->id])
            ->getContent();

        $this->assertStringContainsString('M-Natural', $html,
            'premise: the panel rendered this product\'s variant');
        $this->assertStringContainsString('<select', $html,
            'premise: the injected panel really contains <select> controls for the locker to reach');
        $this->assertStringContainsString('form-check-input', $html,
            'premise: the injected panel really contains switches/radios for the locker to reach');

        /* And the empty case the SHOW route hits today, so the difference is on the record. */
        $empty = (string) $this->actingAs($merchant)
            ->post(route('products.attribute_combination_data'), ['id' => null])
            ->getContent();

        $this->assertStringNotContainsString('M-Natural', $empty,
            'the no-id request the show screen actually sends refuses with an empty panel, which '
            . 'is why the injected limb of this repair is latent rather than live');
    }

    // =============================================================================================
    // 4. NEGATIVE CONTROLS the brief requires: nothing else about the view changed
    // =============================================================================================

    public function test_the_ajax_contract_is_unchanged(): void
    {
        $source = $this->raw();

        $this->assertSame(2, substr_count($source, 'type: "PUT"'),
            'both variant requests must still be PUTs');
        $this->assertStringContainsString("route('products.attribute_combination_data')", $source);
        $this->assertStringContainsString("route('products.attribute_combination_edit')", $source);
        $this->assertSame(2, substr_count($source, "\$('#choice_form').serialize()"),
            'the payload expression is left exactly as it was: `disabled` excludes controls from '
            . 'jQuery .serialize(), which is harmless here only because this view has no <form> '
            . 'at all — and that is precisely why this sweep must never be copied into '
            . 'product/create.blade.php or product/edit.blade.php, where #choice_form does exist.');
    }

    public function test_the_view_still_has_no_form_so_disabling_cannot_drop_a_payload(): void
    {
        $source = $this->raw();

        foreach (['<form', '</form>', 'Form::open'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $source,
                "{$needle} appeared in a read-only view. `disabled` controls are excluded from "
                . 'jQuery .serialize() and FormData, so a form here would silently submit a '
                . 'partial payload.'
            );
        }
    }

    public function test_the_dead_validation_placeholder_census_is_unchanged(): void
    {
        /*
         * `ValidationFeedbackVisibilityTest` freezes this view at exactly 2 static
         * `invalid-feedback` placeholders and 0 `@error(` blocks, and its source() helper strips
         * ONLY Blade comments — so a JavaScript comment in this repair mentioning either string
         * would inflate the count and fail that test rather than this one. Measured here too so
         * the cause is obvious.
         */
        $stripped = $this->stripped();

        $this->assertSame(2, substr_count($stripped, 'invalid-feedback'));
        $this->assertSame(0, substr_count($stripped, '@error('));
    }

    public function test_the_repair_introduces_no_css_so_the_two_direction_stylesheets_stay_in_step(): void
    {
        /*
         * `disabled` is presentation-independent, so this repair adds no selector anywhere. The
         * control is therefore that neither direction stylesheet mentions the new scope: if a
         * later version adds a [disabled] or .form-control-plaintext rule it must land in BOTH,
         * because public/css/rtl-custom.css is served instead of custom.css for Arabic.
         */
        foreach (['public/css/custom.css', 'public/css/rtl-custom.css'] as $sheet) {
            $this->assertStringNotContainsString(
                'ss-product-show',
                (string) file_get_contents(base_path($sheet)),
                "{$sheet} gained a rule for the new scope. If one direction needs it, both do."
            );
        }
    }

    public function test_the_variant_delete_affordances_are_deliberately_left_live(): void
    {
        /*
         * F184 / D-28c: both trash affordances on this screen are anchors, outside every selector
         * the locker uses, so the lock deliberately leaves them alone. Pinned so the next reader
         * does not "finish" the repair by locking them.
         *
         * CORRECTED after independent review -- the two are NOT in the same state, and an earlier
         * version of this comment claimed both were live and both governed by the dialog:
         *
         *   - the per-variant trash button IS governed by the dialog. Its delegated handler ships
         *     inside `attribute_combinations_data.blade.php` itself, so it is live wherever that
         *     partial is injected. On THIS route the panel renders EMPTY -- the view posts
         *     `$('#choice_form').serialize()` and no `id="choice_form"` exists anywhere in
         *     resources/views, so no id reaches the endpoint and `Product::find(null)` is empty.
         *     Measured by this file's own test at the attribute_combination_data endpoint. So it
         *     is LATENT here, and the dialog include is defensive.
         *
         *   - the product-image trash button (`.deleteRecord`, show.blade.php:267) is INERT on
         *     this screen. Its only two bindings are `product/create.blade.php:773` (direct) and
         *     `product/edit.blade.php:987` (delegated but inside edit's own pushed script), and
         *     neither script loads on `show`. There is no global binding -- grepped across
         *     public/ and resources/views/. Clicking it here does nothing, and the `<a>` has no
         *     href. It is therefore NOT governed by the confirmation dialog, because there is
         *     nothing to confirm.
         *
         * The assertions below do not depend on either state, which is exactly why the wrong
         * prose survived: nothing failed.
         */
        $source = $this->raw();

        $this->assertStringContainsString("@include('product._variant-delete-dialog')", $source,
            'the confirmation dialog that governs the live delete must stay included');

        $this->assertStringNotContainsString("find('a", $source,
            'the locker must not reach anchors: the delete affordances are intentionally live');
    }
}
