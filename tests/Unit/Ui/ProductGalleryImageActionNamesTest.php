<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * The product gallery's per-image Download and Delete controls carry accessible names.
 * =================================================================================================
 *
 * WHAT WAS WRONG (P2.5 `gallery-image-actions-unnamed`). In `resources/views/product/edit.blade.php`
 * the gallery tile rendered two icon-only anchors inside a `@foreach ($product_image as $file)`.
 * The Download anchor carried exactly one naming-shaped attribute, `data-original-title`, which is
 * BOOTSTRAP 4's attribute name -- Bootstrap 5 writes `data-bs-original-title` -- and nothing in
 * this application reads it: the only two tooltip constructors, `public/assets/js/dash.js:561` and
 * `public/assets/js/side-dash.js:620`, both select `[data-bs-toggle="tooltip"]`, and neither anchor
 * carries that attribute, so no Tooltip object is ever built and the attribute is inert markup.
 * The Delete anchor carried no naming attribute at all. The `<img>` is `alt=""` and the tile holds
 * no text node, so there was no name from content either. The accessible name computation returned
 * EMPTY for both: the Download anchor fell back to its `href` and was announced as
 * "link, <upload path>.png"; the Delete anchor, `href="javascript::void(0)"`, as "link". Neither
 * ever said Download or Delete -- WCAG 2.1 SC 4.1.2.
 *
 * WHY NOTHING CAUGHT IT. `tests/Unit/Ui/ActionPartialContractTest.php:62`, the ratchet that fixed
 * this same feature's ROW actions, globs `resources/views/*​/action.blade.php` only, so
 * `product/edit.blade.php` sat outside every accessible-naming ratchet in the suite. This file is
 * that missing ratchet.
 *
 * SCOPED TO THE TILE, NOT THE FILE. Every assertion below runs against the text BETWEEN the
 * `@foreach ($product_image as $file)` and its `@endforeach` -- nothing else. A whole-file grep for
 * `aria-label` is satisfied for free by the breadcrumb at `edit.blade.php:18-19` and the action bar
 * at `:39`, so a file-wide assertion would have passed before the repair and proved nothing. The
 * extraction itself is asserted (`test_the_gallery_tile_is_locatable`) so that a rename of the
 * loop variable fails loudly here instead of making every other assertion vacuous.
 *
 * `$loop->iteration` IS LOAD-BEARING, not decoration. `$product->name` alone names all N tiles
 * identically, so a merchant with five gallery images would get five controls all announced
 * "Delete Blue T-Shirt": SC 4.1.2 satisfied, the control still unusable. The name must distinguish
 * the tiles, so the ordinal is asserted, not just the presence of an attribute.
 *
 * THE AJAX CONTRACT IS ASSERTED TOO, and that is the half of this test that a naming grep cannot
 * fake. The delete is bound at `edit.blade.php:987` as `$(document).on('click', '.deleteRecord')`,
 * reads only `$(this).data('id')`, and on success hides `$('.delete_img_' + id)`. A "better" repair
 * that rewrote the anchor as a `<button>`, dropped the `deleteRecord` class, renamed `data-id` or
 * touched the `delete_img_{id}` wrapper would leave a correctly NAMED control that no longer
 * deletes anything -- strictly worse than the silent version, and invisible to any test that only
 * looks for `aria-label`.
 *
 * KNOWN GAP, DELIBERATELY NOT ASSERTED HERE. `resources/views/product/show.blade.php:253-273` is a
 * near-identical copy of this tile with the same defect, and it is NOT repaired by this change --
 * that file belongs to another concurrent change and was not ours to edit. Asserting it here would
 * ship a permanently red test. It is reported separately. Note that show's delete anchor has no
 * `href` and `.deleteRecord` is bound only in `product/edit.blade.php:987` and
 * `product/create.blade.php:773`, so that control is not focusable and does nothing when clicked;
 * naming a dead destructive control would promise a merchant a delete that cannot fire.
 */
class ProductGalleryImageActionNamesTest extends TestCase
{
    private const VIEW = 'resources/views/product/edit.blade.php';

    private const DOWNLOAD_KEY = 'Download image :n of :name';

    private const DELETE_KEY = 'Delete image :n of :name';

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Blade comments stripped, and ONLY Blade comments -- the same limitation every other
     * source-reading ratchet in this suite has. `ValidationFeedbackVisibilityTest.php:95` records
     * what happens otherwise: an explanatory comment that quoted the literal it was counting
     * inflated its own figure the first time it ran.
     */
    private function source(): string
    {
        $raw = (string) file_get_contents($this->root() . '/' . self::VIEW);

        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $raw);
    }

    /** The `@foreach ($product_image as $file)` body, and nothing outside it. */
    private function tile(): string
    {
        $matched = preg_match(
            '/@foreach\s*\(\s*\$product_image\s+as\s+\$file\s*\)(.*?)@endforeach/s',
            $this->source(),
            $m
        );

        $this->assertSame(1, $matched, self::VIEW . ' no longer contains a `@foreach ($product_image as $file)` loop; every assertion in this file reads that loop body, so re-locate the gallery tile before changing this test.');

        return $m[1];
    }

    public function test_the_gallery_tile_is_locatable(): void
    {
        $tile = $this->tile();

        // Reachability: without these the extraction could silently return the wrong region and
        // every naming assertion below would pass against an empty or unrelated string.
        $this->assertStringContainsString('ti ti-download', $tile);
        $this->assertStringContainsString('ti ti-trash', $tile);
        $this->assertSame(2, substr_count($tile, '<a '), 'The gallery tile is expected to hold exactly two anchors (Download, Delete).');
    }

    public function test_the_download_control_has_an_accessible_name(): void
    {
        $tile = $this->tile();

        $this->assertStringContainsString(
            'aria-label="{{ __(\'' . self::DOWNLOAD_KEY . '\'',
            $tile,
            'The gallery Download anchor has no accessible name: `data-original-title` is Bootstrap 4\'s attribute name and is read by nothing in this application, the sibling `<img>` is alt="", and the tile holds no text node -- so a screen reader announces the href.'
        );

        $this->assertMatchesRegularExpression(
            '/aria-label="\{\{ __\(\'' . preg_quote(self::DOWNLOAD_KEY, '/') . '\',\s*\[\'n\' => \$loop->iteration,\s*\'name\' => \$product->name\]\) \}\}"/',
            $tile,
            'The Download name must carry `$loop->iteration`: `$product->name` alone names all N tiles identically.'
        );
    }

    public function test_the_delete_control_has_an_accessible_name(): void
    {
        $tile = $this->tile();

        $this->assertStringContainsString(
            'aria-label="{{ __(\'' . self::DELETE_KEY . '\'',
            $tile,
            'The gallery Delete anchor has no naming attribute of any kind, so a screen reader announces only "link" for a destructive control.'
        );

        $this->assertMatchesRegularExpression(
            '/aria-label="\{\{ __\(\'' . preg_quote(self::DELETE_KEY, '/') . '\',\s*\[\'n\' => \$loop->iteration,\s*\'name\' => \$product->name\]\) \}\}"/',
            $tile,
            'The Delete name must carry `$loop->iteration`, otherwise five gallery images give five controls all announced "Delete <product>".'
        );
    }

    public function test_every_icon_in_the_gallery_tile_is_hidden_from_assistive_technology(): void
    {
        $tile = $this->tile();

        $icons = preg_match_all('/<i\b[^>]*>/s', $tile, $m) ? $m[0] : [];

        $this->assertCount(2, $icons, 'The gallery tile is expected to hold exactly two `<i>` glyphs.');

        foreach ($icons as $icon) {
            $this->assertStringContainsString(
                'aria-hidden="true"',
                $icon,
                'A named control whose glyph is still exposed is announced twice. ' . $icon
            );
        }
    }

    /**
     * ICON CLASS ADJACENCY -- a control that hides rather than announces itself.
     * `IconNamesExistTest.php:83` matches `/\bti\s+(ti-[a-z0-9-]+)/`, so `ti` and `ti-download`
     * must stay adjacent INSIDE one class attribute. Adding `aria-hidden` inside the class value,
     * or splitting the pair, would make that ratchet stop seeing the icon -- it would pass
     * VACUOUSLY, not fail, which is why the adjacency is pinned here where it fails loudly.
     */
    public function test_the_icon_class_pair_stays_adjacent_for_the_icon_ratchet(): void
    {
        $tile = $this->tile();

        $this->assertMatchesRegularExpression('/class="ti ti-download"/', $tile);
        $this->assertMatchesRegularExpression('/class="ti ti-trash"/', $tile);
    }

    /**
     * NEGATIVE CONTROL: the repair took nothing away. Every hook the live delete and download
     * depend on is still exactly where the JavaScript at `edit.blade.php:987` expects it.
     */
    public function test_the_delete_ajax_contract_is_unchanged(): void
    {
        $tile = $this->tile();
        $whole = $this->source();

        $this->assertStringContainsString('class="btn btn-sm btn-danger deleteRecord"', $tile, 'The click handler is bound to `.deleteRecord`.');
        $this->assertStringContainsString('name="deleteRecord"', $tile);
        $this->assertStringContainsString('data-id="{{ $file->id }}"', $tile, 'The handler reads only `$(this).data(\'id\')`.');
        $this->assertStringContainsString("'delete_img_' . \$file->id", $tile, 'On success the handler hides `.delete_img_<id>`.');
        $this->assertStringContainsString('<a ', $tile, 'The control must stay an anchor; a `<button>` inside this form would submit it.');

        // The download must still actually download the file it names.
        $this->assertStringContainsString('href="{{ get_file($file->image_path) }}"', $tile);
        $this->assertStringContainsString('download=""', $tile);

        // And the binding itself must still be there to be honoured.
        $this->assertStringContainsString("\$(document).on('click', '.deleteRecord'", $whole);
    }

    /**
     * BOTH LANGUAGE FILES OR NEITHER. `TranslationFileIntegrityTest.php:154` pins ar-only orphans
     * at 31, so an Arabic key without its English sibling fails there; and
     * `ArabicUiCoverageTest.php:117` collects `__('key', [...])` from
     * `resources/views/product/*.blade.php`, so an English-only addition fails there. Asserting
     * both here means the failure names the missing key instead of a count.
     */
    public function test_both_names_are_translated_in_both_language_files(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $path = $this->root() . '/resources/lang/' . $locale . '.json';
            $decoded = json_decode((string) file_get_contents($path), true);

            $this->assertIsArray($decoded, $locale . '.json is not valid JSON.');

            foreach ([self::DOWNLOAD_KEY, self::DELETE_KEY] as $key) {
                $this->assertArrayHasKey($key, $decoded, sprintf('%s.json is missing "%s".', $locale, $key));
                $this->assertNotSame('', trim((string) $decoded[$key]));
            }
        }
    }

    /**
     * BIDI ORDER, MEASURED IN THE STRING ITSELF. The announced name is read, not painted, so RTL
     * cannot break layout here -- but `Download image :n of :name` interpolating an Arabic product
     * name beside a Latin digit is a bidi string, and this project has already shipped one defect
     * of exactly that shape (A-03, the Arabic greeting). The Arabic value must keep the ordinal
     * BEFORE the product name, so the announcement is "تنزيل الصورة 3 من قميص أزرق" and not the
     * digit stranded after the name.
     */
    public function test_the_arabic_names_keep_the_ordinal_before_the_product_name(): void
    {
        $ar = json_decode((string) file_get_contents($this->root() . '/resources/lang/ar.json'), true);

        foreach ([self::DOWNLOAD_KEY, self::DELETE_KEY] as $key) {
            $value = (string) ($ar[$key] ?? '');

            $this->assertNotSame('', $value, 'ar.json is missing ' . $key);
            $this->assertStringContainsString(':n', $value);
            $this->assertStringContainsString(':name', $value);
            $this->assertLessThan(
                strpos($value, ':name'),
                strpos($value, ':n '),
                'The Arabic value must read "<verb> الصورة :n من :name" -- ordinal first.'
            );

            // Laravel's Translator::makeReplacements ends in a single strtr() with the longest key
            // first, so `:name` is never eaten by `:n`. Proved on the real values.
            $rendered = strtr($value, [':name' => 'قميص أزرق', ':n' => '3']);
            $this->assertStringContainsString('3', $rendered);
            $this->assertStringContainsString('قميص أزرق', $rendered);
            $this->assertStringNotContainsString(':n', $rendered);
        }
    }
}
