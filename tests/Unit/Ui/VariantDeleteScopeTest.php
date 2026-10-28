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
        $this->assertMatchesRegularExpression(
            "/\\\$safe_str\s*=\s*preg_replace\([^)]*\)\s*\.\s*'_'\s*\.\s*\\\$loop->index/",
            $this->source(),
            '$safe_str must carry the loop index: without it, two terms differing only in a '
            . 'special character collapse to the same DOM id (F73)'
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
        $source = $this->source();

        $this->assertDoesNotMatchRegularExpression(
            '/id="' . preg_quote($id, '/') . '"/',
            $source,
            'id="' . $id . '" is emitted once per variant, so N variants share one id. A label '
            . 'activates the FIRST -- which is how a file chosen for the third variant landed on '
            . 'the first (F163).'
        );
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
        ];
    }

    public function test_the_shipping_select_is_associated_with_its_label(): void
    {
        $source = $this->source();

        $this->assertStringNotContainsString(
            "'id' => 'Shipping'",
            $source,
            "the shipping select still carries a hardcoded id repeated once per variant"
        );

        $this->assertStringContainsString(
            "Form::label('shipping_id_' . \$safe_str",
            $source,
            'the shipping label must name the select it describes: its first argument was an empty '
            . 'string, so it was associated with nothing at all'
        );
    }

    /**
     * AND THE LABEL THAT MISDIRECTED THE FILE PICKER NOW NAMES ITS OWN INPUT. Both halves, because
     * a unique id with a label still pointing at the old one would be worse than either alone.
     */
    public function test_the_downloadable_file_label_names_its_own_variants_input(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            'for="downloadable_product_id_{{ $safe_str }}"',
            $source,
            'the label must point at this variant\'s own file input'
        );
        $this->assertStringContainsString(
            'id="downloadable_product_id_{{ $safe_str }}"',
            $source,
            'and that input must carry the matching unique id'
        );
    }
}
