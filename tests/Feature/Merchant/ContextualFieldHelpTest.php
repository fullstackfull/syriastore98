<?php

namespace Tests\Feature\Merchant;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-06-10 / WS D9 + I2 — contextual field help, ASSOCIATED, not merely rendered.
 * =================================================================================================
 *
 * THE ACCEPTANCE: "help attached to the field it is about via `aria-describedby`, in both
 * languages, NOT A TOOLTIP ONLY."
 *
 * WHAT WAS THERE BEFORE, and why the last clause is the requirement rather than a preference. The
 * slug field carried:
 *
 *     'data-bs-toggle' => 'tooltip', 'title' => __('Sku or Slug')
 *
 * which needs a hover or a long-press to reach, restates the field's own label, and is bound to
 * the input for nothing — Bootstrap's tooltip plugin moves `title` into
 * `data-bs-original-title` and blanks the attribute (`order/action.blade.php:8` records the same
 * behaviour), so even the browser's own fallback is gone. No other product field had help at all.
 *
 * WHY THIS FILE IS A RATCHET AND NOT A COUNT. "Five hints exist" is satisfied by five paragraphs
 * nothing points at, and "five controls have aria-describedby" is satisfied by five references to
 * ids that are not on the page. Both are worse than silence: a screen reader announces neither,
 * and a dangling `aria-describedby` makes the control's accessible description empty while the
 * markup looks complete. So the assertions below are BOTH directions of the association, measured
 * against the rendered page rather than the template.
 */
class ContextualFieldHelpTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The fields that carry help, and a phrase from each that proves it is the right text. */
    private const HELPED = [
        'slug' => 'web address',
        'sale_price' => 'lower than the price',
        'cost_price' => 'never shown to customers',
        'product_weight' => 'shipping',
        'low_stock_threshold' => 'Low stock',
    ];

    /**
     * `stock_management` MUST BE ON, and that is a premise rather than a convenience.
     * `low_stock_threshold` lives inside `@if ($stock_management == 'on')`
     * (`create.blade.php:247`, `edit.blade.php` likewise), so with the setting unset -- which is
     * how it ships -- the field is not on the page at all and neither is its help. The first
     * version of this file did not set it and reported that field as unhelped, which was true of
     * the page and false of the template. Enabling it is what makes the assertion about the help
     * rather than about the feature flag.
     */
    private function enableStockManagement(int $storeId): void
    {
        \App\Models\Setting::updateOrCreate(
            ['name' => 'stock_management', 'created_by' => 1, 'store_id' => $storeId],
            ['value' => 'on']
        );
        \Illuminate\Support\Facades\Cache::flush();
    }

    private function editorHtml(string $path = '/product/create'): string
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Create Product');
        $this->grantPermission($merchant, 'Manage Products');
        $this->enableStockManagement($store->id);

        $response = $this->actingAs($merchant)->get($path);

        $this->assertSame(200, $response->getStatusCode(),
            $path . ' must render, or every assertion below is vacuous');

        $html = $response->getContent();

        // The premise the comment above describes, asserted rather than assumed.
        $this->assertStringContainsString('name="low_stock_threshold"', $html,
            'premise: stock management is on, so the low-stock field is on the page. Without it '
            . 'the help assertions for that field would be measuring a feature flag.');

        return $html;
    }

    public function test_every_helped_field_points_at_its_help(): void
    {
        $html = $this->editorHtml();

        foreach (self::HELPED as $field => $phrase) {
            $this->assertMatchesRegularExpression(
                '/aria-describedby="' . preg_quote($field, '/') . '-help"/',
                $html,
                $field . ' does not reference its help through aria-describedby, so a screen '
                . 'reader announces the control with no description'
            );
        }
    }

    public function test_every_help_element_is_really_on_the_page(): void
    {
        $html = $this->editorHtml();

        foreach (self::HELPED as $field => $phrase) {
            $this->assertMatchesRegularExpression(
                '/id="' . preg_quote($field, '/') . '-help"/',
                $html,
                'a control references ' . $field . '-help but no element has that id — a dangling '
                . 'aria-describedby leaves the accessible description EMPTY while the markup looks '
                . 'complete'
            );
        }
    }

    public function test_no_help_element_is_an_orphan(): void
    {
        /* THE OTHER DIRECTION. A hint nothing points at is decoration: it is on screen for a
         * sighted mouse user and absent from the control's accessible description. */
        $html = $this->editorHtml();

        preg_match_all('/data-ss-field-help="([^"]+)"/', $html, $found);
        $this->assertNotEmpty($found[1], 'premise: the page renders field help at all');

        $orphans = [];

        foreach (array_unique($found[1]) as $field) {
            if (! preg_match('/aria-describedby="[^"]*' . preg_quote($field, '/') . '-help/', $html)) {
                $orphans[] = $field;
            }
        }

        $this->assertSame([], $orphans,
            "These help elements are rendered but nothing references them:\n  "
            . implode("\n  ", $orphans)
            . "\n\nAdd aria-describedby=\"<field>-help\" to the control, or remove the help.");
    }

    public function test_the_help_says_something_the_label_does_not(): void
    {
        $html = $this->editorHtml();

        foreach (self::HELPED as $field => $phrase) {
            $this->assertStringContainsString($phrase, $html,
                $field . "'s help should contain " . json_encode($phrase) . '; help that restates '
                . 'the label is what the old `title="Sku or Slug"` tooltip already did');
        }
    }

    public function test_the_slug_tooltip_that_said_nothing_is_gone(): void
    {
        /* Named explicitly, because "we added help" and "we removed the thing that pretended to be
         * help" are different claims and only the second one is checkable. */
        $html = $this->editorHtml();

        $this->assertStringNotContainsString("title=\"Sku or Slug\"", $html,
            'the hover-only tooltip that restated the label is still there');
        $this->assertStringNotContainsString("'title'=> __('Sku or Slug')", $html);
    }

    public function test_the_help_is_translated(): void
    {
        /* BOTH LANGUAGES is part of the acceptance, and the lang files are where it is decidable
         * without driving a browser. The live harness checks the rendered Arabic. */
        $root = dirname(__DIR__, 3);

        $en = json_decode((string) file_get_contents($root . '/resources/lang/en.json'), true);
        $ar = json_decode((string) file_get_contents($root . '/resources/lang/ar.json'), true);

        $this->assertIsArray($en);
        $this->assertIsArray($ar);

        $keys = [
            "Part of the product's web address. Leave it blank and it is generated from the name.",
            'The discounted price customers pay. It must be lower than the price.',
            'What the item costs you. Required, cannot exceed the price, and never shown to customers.',
            'Used to work out shipping. Leave blank if your shipping does not depend on weight.',
            'The product list shows Low stock at or below this number. Leave 0 to use the store default.',
        ];

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $en, 'en.json is missing the help string');
            $this->assertArrayHasKey($key, $ar, 'ar.json is missing the help string');
            $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', (string) $ar[$key],
                'the Arabic entry for ' . json_encode(substr($key, 0, 40)) . ' is not in Arabic — '
                . 'an English fallback copied into ar.json reads as translated and is not');
        }
    }

    public function test_the_edit_screen_carries_the_same_help(): void
    {
        /* The two screens are separate 1000-line templates and the phase has already produced
         * three defects that existed on one and not the other (F46, F72, F75). */
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Products');
        $this->grantPermission($merchant, 'Manage Products');

        $product = \App\Models\Product::forceCreate([
            'name' => 'Help Scarf',
            'slug' => 'help-scarf-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'cost_price' => 4,
            'track_stock' => 1,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 5,
            'low_stock_threshold' => 2,
            'status' => 1,
            'variant_product' => 0,
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);

        $this->enableStockManagement($store->id);

        $response = $this->actingAs($merchant)->get('/product/' . $product->id . '/edit');
        $this->assertSame(200, $response->getStatusCode(), 'the edit screen must render');
        $html = $response->getContent();

        $this->assertStringContainsString('name="low_stock_threshold"', $html,
            'premise: the low-stock field is on the edit page too');

        foreach (self::HELPED as $field => $phrase) {
            $this->assertMatchesRegularExpression(
                '/aria-describedby="' . preg_quote($field, '/') . '-help"/', $html,
                $field . ' has help on create and not on edit');
            $this->assertMatchesRegularExpression('/id="' . preg_quote($field, '/') . '-help"/', $html);
        }
    }
}
