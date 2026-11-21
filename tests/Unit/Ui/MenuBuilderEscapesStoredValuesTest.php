<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P1-SEC-80, round 3 (finding C-3, and the icon half of C-6) -- the menu builder writes a menu item's name, URL and icon as text.
 * =================================================================================================
 *
 * `resources/views/menu/script.blade.php` builds a menu item's markup in a template literal. The item's NAME, URL and ICON are text somebody typed (a member of staff who may create a category, a
 * page, a brand, a product, a blog or a custom link; a merchant for their own store), and they were interpolated as they were: a name with a tag ran when the item was added to the menu, a quote
 * broke out of `value="..."`, "Visit Link" pointed at a `javascript:` URL, and the icon preview wrote a class value that closed its attribute. Executed in Chromium at the commit before the fix:
 * the name and the icon fired, the link was the script URL.
 *
 * This is a SOURCE PIN, and says so: the suite runs no browser. It pins the spellings the fix uses and the absence of the raw ones; the ratchet `PanelViewsBuildOptionsFromNodesTest` covers the literals
 * that hold an `<option>`, and the title `<div>` is in one that does not.
 */
final class MenuBuilderEscapesStoredValuesTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/resources/views/menu/script.blade.php');
    }

    public function test_the_builder_has_its_helpers_and_uses_them(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('function ssEsc(', $source, 'premise: the escape helper is defined');
        $this->assertStringContainsString('function ssSafeHref(', $source, 'premise: the link helper is defined');
        $this->assertGreaterThanOrEqual(6, substr_count($source, 'ssEsc('), 'premise: the title, the two inputs, the icon input (twice) and the link go through ssEsc()');
    }

    public function test_no_stored_value_is_interpolated_raw(): void
    {
        $source = $this->source();

        $this->assertSame(0, preg_match_all('/\$\{\s*(?:itemTitle|url|icon)\s*\}/', $source), 'an item\'s name, URL or icon is interpolated into the markup the builder writes without ssEsc()');
        $this->assertMatchesRegularExpression('/href="\$\{ssEsc\(ssSafeHref\(url\)\)\}"/', $source, 'the Visit Link no longer limits its URL\'s scheme to http(s), mailto, tel, a path or a fragment');
    }

    public function test_the_icon_preview_does_not_write_the_icon_as_markup(): void
    {
        $source = $this->source();

        $this->assertMatchesRegularExpression('/glyph\.className\s*=\s*iconValue\s*;/', $source, 'premise: a class name is set as a property');
        $this->assertDoesNotMatchRegularExpression('/\$\{\s*iconValue\b/', $source, 'the icon is interpolated into the preview\'s markup');
        $this->assertDoesNotMatchRegularExpression('/iconPreview\.innerHTML/', $source, 'the preview\'s content is written as markup again');
    }
}
