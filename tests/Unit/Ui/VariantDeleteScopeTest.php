<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * F162 / F73 / F163 — the variant combination view's delete path and its DOM identifiers.
 * =================================================================================================
 *
 * F162 IS THE SERIOUS ONE AND IT WAS MEASURED, NOT INFERRED. `attribute_combinations.blade.php`
 * removed a deleted variant's form fields with
 *
 *     $('input[name*="' + originalId + '"], select[name*="..."], textarea[name*="..."]').remove();
 *
 * and `[name*=]` is a SUBSTRING match. Every per-variant field is named `<prefix>_<term>`, so
 * deleting the variant `M` matched every field of `M/L` as well. Driven on the running create page
 * through the view's own `products/attribute_combination` endpoint
 * (`docs/development/phase-2/tools/check-variant-delete-live.cjs`):
 *
 *   before : clicking delete on `M` removed 33 fields, SEVENTEEN of them M/L's --
 *            product_variation_price_M/L, product_sale_price_M/L, product_stock_M/L, product_sku_M/L
 *   after  : 17 fields removed, none of them M/L's
 *
 * A save after that click writes the product without the other variant's price, stock and SKU, with
 * no message. That is the same class of loss as F82/F83/F105 -- a variant's financial values
 * disappearing silently -- in the one path D-09's fix did not cover, because D-09 was about the
 * rebuild and this is the delete button.
 *
 * THE FIX IS THE SIBLING VIEW'S OWN IDIOM, not a third one: `closest('.attribute_options_datas')`,
 * which `attribute_combinations_edit.blade.php` already uses. The combination loop renders exactly
 * one such container per combination, so it reaches that variant and nothing else, and removing it
 * takes its fields with it. A name-based removal is what cannot be made safe here, because a term
 * is free text a merchant types.
 *
 * F73 is the collision that made `$safe_str` ambiguous: `preg_replace('/[^a-zA-Z0-9_-]/', '_', ...)`
 * maps every special character to `_`, so `M/L` and `M.L` both become `M_L`. Two elements then
 * shared one `id` and one `data-bs-target`, so opening one opened the wrong panel. The loop index
 * makes it injective BY CONSTRUCTION rather than probabilistically, which a hash would not.
 *
 * F163 was found by the same probe rather than by reading: FIVE ids were hardcoded and therefore
 * repeated once per variant, and one of them had a `<label for>` pointing at it -- so clicking the
 * third variant's "downloadable product" label opened the file picker for the FIRST variant and the
 * chosen file landed on the wrong variant's field. Measured: six duplicate ids before, zero after.
 * Nothing selected any of them (checked across the views, public/js and public/assets/js); the
 * JavaScript keys on the classes, which are untouched.
 */
class VariantDeleteScopeTest extends TestCase
{
    private const VIEW = __DIR__ . '/../../../resources/views/product/attribute_combinations.blade.php';

    private const SIBLINGS = [
        'resources/views/product/attribute_combinations_edit.blade.php',
        'resources/views/product/attribute_combinations_data.blade.php',
    ];

    /**
     * The view's CODE, with its comments removed — and that is not tidiness, it is the third time
     * this project has had a source check match its own explanation. The breadcrumb contract test
     * matched the `<ul class="breadcrumb">` quoted in its docblock; the ajax-popup ratchet counted
     * the attribute spelled out in a layout comment; and the first run of THIS file failed three
     * assertions because the comments written while fixing these defects quote the removed code
     * verbatim — `[name*=`, `originalId`, `'id' => 'Shipping'`. A comment describing a defect is
     * evidence that it was understood, not that it is still there.
     *
     * Blade comments, PHP block and line comments, and JavaScript line comments, in that order.
     */
    private function source(?string $relative = null): string
    {
        $path = $relative === null ? self::VIEW : __DIR__ . '/../../../' . $relative;
        $this->assertFileExists($path);

        $raw = (string) file_get_contents($path);

        $stripped = preg_replace('/\{\{--.*?--\}\}/s', '', $raw);
        $stripped = preg_replace('#/\*.*?\*/#s', '', (string) $stripped);
        $stripped = preg_replace('#^\s*//.*$#m', '', (string) $stripped);

        $this->assertNotSame('', trim((string) $stripped), 'stripping removed the whole file');

        return (string) $stripped;
    }

    // ------------------------------------------------------------------ F162

    public function test_no_variant_view_removes_form_fields_by_substring_name_match(): void
    {
        $views = array_merge([null], self::SIBLINGS);

        foreach ($views as $view) {
            $this->assertDoesNotMatchRegularExpression(
                '/\[name\*=/',
                $this->source($view),
                ($view ?? 'attribute_combinations.blade.php')
                . ' removes fields by SUBSTRING name match, which deletes other variants\' prices: '
                . 'a term is free text, so `M` matches `M/L`'
            );
        }
    }

    public function test_the_delete_handler_is_scoped_to_the_clicked_variants_container(): void
    {
        $this->assertMatchesRegularExpression(
            '/\$\(this\)\.closest\(\s*[\'"]\.attribute_options_datas[\'"]\s*\)\.remove\(\)/',
            $this->source(),
            'the delete handler must remove the clicked variant\'s own container, which is the '
            . 'idiom attribute_combinations_edit.blade.php already uses'
        );
    }

    /**
     * THE REMOVED LINE, named exactly, so a revert cannot pass by rewriting it differently.
     */
    public function test_the_old_name_based_removal_is_gone(): void
    {
        $source = $this->source();

        foreach (['originalId', 'data-original-id'] as $token) {
            $this->assertStringNotContainsString(
                $token,
                $source,
                'the view still carries `' . $token . '`, which existed only to drive the '
                . 'substring removal F162 was'
            );
        }
    }

    // ------------------------------------------------------------------ F73

    public function test_the_css_safe_identifier_is_unique_per_rendered_variant(): void
    {
        /*
         * ALL THREE VIEWS, and that is a correction this file needed rather than a preference.
         * Four of its ratchets asked only the create view, so when F73 and F163 were fixed there
         * the two siblings kept both defects and the suite stayed green. They were then found by
         * driving the EDIT page in a browser -- and `attribute_combinations_data.blade.php` is the
         * one the edit screen injects from `$(document).ready`, so it is the panel a merchant sees
         * FIRST. A ratchet that covers one of three copies of a defect is not a ratchet.
         */
        foreach ($this->allViews() as $label => $view) {
            $this->assertMatchesRegularExpression(
                "/\\\$safe_str\s*=\s*preg_replace\([^)]*\)\s*\.\s*'_'\s*\.\s*\\\$loop->index/",
                $this->source($view),
                $label . ': $safe_str must carry the loop index: without it, two terms differing '
                . 'only in a special character collapse to the same DOM id (F73)'
            );
        }
    }

    /** @return array<string, string|null> label => path relative to the repo root; null = the create view */
    private function allViews(): array
    {
        return array_merge(
            ['attribute_combinations.blade.php' => null],
            array_combine(array_map('basename', self::SIBLINGS), self::SIBLINGS)
        );
    }

    /**
     * THE MISTAKE THIS ONE EXISTS FOR WAS MINE, and it was strictly worse than the defect it came
     * from. Giving each variant's preview `<img>` a per-variant id (F163) left the `onchange`
     * beside it still naming the old shared `down_product`, which no longer existed -- so
     * `getElementById(...).src` ran on `null` and THREW on every file choice, where before it had
     * merely written the wrong variant's preview. A browser probe that measures only the delete
     * and the duplicate ids cannot see that, so the rule is asserted instead: every id a view
     * looks up must be an id that view emits.
     */
    public function test_every_getelementbyid_names_an_id_the_same_view_emits(): void
    {
        $checked = 0;

        foreach ($this->allViews() as $label => $view) {
            $source = $this->source($view);

            preg_match_all("/getElementById\(\s*'([^']+)'\s*\)/", $source, $lookups);

            foreach ($lookups[1] as $wanted) {
                $checked++;

                /*
                 * THE LIMIT OF THIS CHECK, STATED SO IT IS NOT MISTAKEN FOR MORE. It compares two
                 * Blade expressions for identical spelling over the un-rendered template; it does
                 * not prove the element is ever EMITTED. Wrapping the `<img>` in `@if (false)`
                 * leaves this green — measured. What it does catch is the real defect it was
                 * written for: a rename on one side and not the other, which is how the F163 fix
                 * left `getElementById('down_product')` pointing at an id that no longer existed.
                 * The live probe `check-variant-edit-keys-live.cjs` is what proves the lookups
                 * actually resolve, on rendered pages, for all three views.
                 */
                $this->assertStringContainsString(
                    'id="' . $wanted . '"',
                    $source,
                    $label . ": getElementById('" . $wanted . "') names an id this view does not "
                    . 'emit, so the lookup returns null'
                );
            }
        }

        /*
         * ONE PER VIEW, DERIVED — not a hardcoded 3. The floor was `3` and the measured value is
         * exactly 3, so it had zero margin: delete the lookup from two views and add two to a
         * third and the floor still passes. Deriving it from the view list means a view that
         * loses its lookup entirely is caught.
         */
        $this->assertGreaterThanOrEqual(
            count($this->allViews()),
            $checked,
            'at least one of the variant views has no getElementById lookup left, so this test '
            . 'is no longer covering it'
        );
    }

    /**
     * THE PREMISE OF THAT FIX, asserted so it cannot rot: the sanitiser really does collapse two
     * distinct terms, so the suffix is load-bearing rather than decorative.
     */
    public function test_the_sanitiser_really_does_collapse_two_distinct_terms(): void
    {
        $sanitise = static fn (string $term): string => preg_replace('/[^a-zA-Z0-9_-]/', '_', $term);

        $this->assertSame($sanitise('M/L'), $sanitise('M.L'),
            'premise: these two terms collapse to one id without the index suffix');
        $this->assertNotSame($sanitise('M/L') . '_0', $sanitise('M.L') . '_1',
            'and the index separates them');
    }

    // ------------------------------------------------------------------ F163

    /**
     * @dataProvider formerlyHardcodedIds
     */
    public function test_no_identifier_is_emitted_without_the_variant_suffix(string $id): void
    {
        foreach ($this->allViews() as $label => $view) {
            $this->assertDoesNotMatchRegularExpression(
                '/id="' . preg_quote($id, '/') . '"/',
                $this->source($view),
                $label . ': id="' . $id . '" is emitted once per variant, so N variants share one '
                . 'id. A label activates the FIRST -- which is how a file chosen for the third '
                . 'variant landed on the first (F163).'
            );
        }
    }

    /** @return array<string, array{0: string}> */
    public static function formerlyHardcodedIds(): array
    {
        return [
            'COD' => ['COD'],
            'enable_manage_stock' => ['enable_manage_stock'],
            'download-product' => ['download-product'],
            'downloadable_product' => ['downloadable_product'],
            'down_product' => ['down_product'],
            'down_products' => ['down_products'],
            'payment-gateways' => ['payment-gateways'],
        ];
    }

    public function test_the_shipping_select_is_associated_with_its_label(): void
    {
        foreach ($this->allViews() as $label => $view) {
            $source = $this->source($view);

            $this->assertStringNotContainsString(
                "'id' => 'Shipping'",
                $source,
                $label . ': the shipping select still carries a hardcoded id repeated once per variant'
            );

            $this->assertStringContainsString(
                "Form::label('shipping_id_' . \$safe_str",
                $source,
                $label . ': the shipping label must name the select it describes: its first '
                . 'argument was an empty string, so it was associated with nothing at all'
            );
        }
    }

    /**
     * AND THE LABEL THAT MISDIRECTED THE FILE PICKER NOW NAMES ITS OWN INPUT. Both halves, because
     * a unique id with a label still pointing at the old one would be worse than either alone.
     */
    public function test_the_downloadable_file_label_names_its_own_variants_input(): void
    {
        foreach ($this->allViews() as $label => $view) {
            $source = $this->source($view);

            $this->assertStringContainsString(
                'for="downloadable_product_id_{{ $safe_str }}"',
                $source,
                $label . ': the label must point at this variant\'s own file input'
            );
            $this->assertStringContainsString(
                'id="downloadable_product_id_{{ $safe_str }}"',
                $source,
                $label . ': and that input must carry the matching unique id'
            );
        }
    }

    /**
     * AND THE PANEL'S ACCESSIBLE NAME IS ITS OWN. `aria-labelledby="COD"` was duplicated once per
     * variant, so every panel but the first resolved to the FIRST variant's header -- measured in
     * a browser: the panel whose own term was `M/L` was announced as `M`.
     */
    public function test_each_variant_panel_is_labelled_by_its_own_header(): void
    {
        foreach ($this->allViews() as $label => $view) {
            $source = $this->source($view);

            $this->assertStringNotContainsString(
                'aria-labelledby="COD"',
                $source,
                $label . ': every panel is labelled by the first variant\'s header'
            );
            $this->assertStringContainsString(
                'aria-labelledby="variant_head_{{ $safe_str }}"',
                $source,
                $label . ': the panel must be labelled by its own header'
            );
        }
    }
}
