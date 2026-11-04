<?php

namespace Tests\Feature\Merchant;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * EVERY FIELD IN A VARIANT PANEL HAS A PROGRAMMATIC NAME, AND EVERY LABEL NAMES A REAL CONTROL.
 * =============================================================================================
 *
 * WHAT WAS WRONG. All three variant partials wrote `Form::label('', __('SKU'), ...)`. konekt's
 * builder interpolates argument 1 straight into `for=` (vendor/konekt/html/src/FormBuilder.php:259)
 * and `getIdAttribute()` (:1268-1277) emits an `id` only when the FIELD's own name is in
 * `$this->labels` -- the field's name is `product_sku_<term>` while the pushed label name was the
 * empty string, so the label rendered `for=""` and the input carried NO `id` at all. The two
 * identifiers that have to match were `""` and absent. Measured on the rendered fragment: 9 such
 * pairs per variant in each of the three views, 25 unnamed controls plus 3 dangling group captions.
 * A screen reader announced the SKU box as "edit text, Scarf-M-L" -- role and value, no name -- and
 * clicking the visible word "SKU" focused nothing.
 *
 * WHY THIS IS ASSERTED ON THE RENDERED DOCUMENT AND NOT ON THE TEMPLATE SOURCE. A dangling `for`
 * and a duplicate `id` are properties of the DOCUMENT, not of a line of Blade: the same template
 * line is correct or broken depending on what `$safe_str` expands to and on how many variants are
 * on the page. This repository has been given a false reading by a source-literal scanner five
 * times (see `StripsSourceComments`), and the one earlier row in this family (F138) chose the
 * rendered document for exactly this reason. So every assertion below drives the three producing
 * endpoints and reads their HTML.
 *
 * THE FIXTURE IS THE F73 PAIR ON PURPOSE. Two variants whose terms are `M/L` and `M.L`. The
 * sanitiser maps every character outside `[A-Za-z0-9_-]` to `_`, so both collapse to `M_L`; it is
 * `$loop->index` that keeps the ids injective. A fixture with one variant, or with two terms that
 * differ in an ordinary letter, cannot see that.
 *
 * WHAT MUST NOT MOVE, and each of these is a separate test below:
 *   - the SUBMITTED `name` stays the RAW `$str`. `ProductController::update()` reads
 *     `$request['product_sku_' . str_replace('.', '_', $str)]`; `$safe_str` carries a `_<index>`
 *     suffix, so moving a name onto it makes every one of those reads miss and write 0 or '' over
 *     a live variant's price, stock and SKU.
 *   - the backorder radios already carry correct raw-`$str` `for`/`id` pairs. They are working
 *     markup and are left alone.
 *   - the three fields inside `.d-none .enable_manage_stock` are in the HTTP body before
 *     "Manage stock?" is clicked. An accessibility-tree probe must reveal first; a body-level
 *     assertion must NOT, and this file asserts the body, so the premise is pinned rather than
 *     assumed.
 */
class VariantFieldLabelAssociationTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * The eight field families that were unnamed. The ninth caption, `Allow BackOrders:`, names a
     * RADIO GROUP and becomes a `<legend>`, so it is asserted separately.
     */
    private const REPAIRED_PREFIXES = [
        'product_sku_',
        'product_weight_',
        'product_variation_price_',
        'product_sale_price_',
        'stock_status_',
        'product_description_',
        'product_stock_',
        'low_stock_threshold_',
    ];

    /** The two variant keys, and the CSS-safe id suffix each must get. */
    private const KEYS = ['M/L' => 'M_L_0', 'M.L' => 'M_L_1'];

    // =========================================================================================
    // Fixture
    // =========================================================================================

    private function makeCategory(int $storeId): int
    {
        return (int) \DB::table('categories')->insertGetId([
            'name' => 'Textiles',
            'slug' => 'textiles-' . uniqid(),
            'trending' => 0,
            'status' => 1,
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{merchant:\App\Models\User,store:\App\Models\Store,product:Product,optionIds:array<int>}
     */
    private function arrange(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Products');
        $this->grantPermission($merchant, 'Manage Products');

        $product = Product::forceCreate([
            'name' => 'Scarf',
            'slug' => 'scarf-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'track_stock' => 1,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 7,
            'category_id' => $this->makeCategory($store->id),
            'status' => 1,
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);

        $attributeId = (int) \DB::table('product_attributes')->insertGetId([
            'name' => 'Size', 'slug' => 'size-' . uniqid(), 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $optionIds = [];
        foreach (array_keys(self::KEYS) as $i => $term) {
            $optionIds[] = (int) \DB::table('product_attribute_options')->insertGetId([
                'attribute_id' => $attributeId, 'terms' => $term, 'order' => $i,
                'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        /*
         * STORED VARIANTS for the two renderers that read them. `stock_status` is non-null on
         * purpose: `attribute_combinations_data.blade.php`'s Stock Status control sits behind
         * `@if ($combination->stock_status != null)` with NO `@else`, so a null there renders no
         * control and this file would be asserting a property of an absent element.
         */
        foreach (array_keys(self::KEYS) as $term) {
            ProductVariant::forceCreate([
                'product_id' => $product->id,
                'variant' => $term,
                'sku' => 'Scarf-' . $term,
                'price' => 43,
                'variation_price' => 41,
                'stock' => 6,
                'low_stock_threshold' => 2,
                'weight' => 1,
                'stock_status' => 'in_stock',
                'stock_order_status' => 'not_allow',
                'store_id' => $store->id,
            ]);
        }

        return compact('merchant', 'store', 'product', 'optionIds');
    }

    /**
     * The three fragments, keyed by the view each endpoint renders.
     *
     * @return array<string, string>
     */
    private function fragments(array $a): array
    {
        $terms = array_keys(self::KEYS);

        $create = $this->actingAs($a['merchant'])->post(route('products.attribute_combination'), [
            'sku' => 'Scarf',
            'price' => 100,
            'product_stock' => 7,
            'attribute_no' => [1],
            'for_variation_1' => 1,
            'attribute_options_1' => $a['optionIds'],
            'options_1' => $a['optionIds'],
        ])->assertOk()->getContent();

        $data = $this->actingAs($a['merchant'])->post(route('products.attribute_combination_data'), [
            'id' => $a['product']->id,
        ])->assertOk()->getContent();

        $edit = $this->actingAs($a['merchant'])->put(route('products.attribute_combination_edit'), [
            'id' => $a['product']->id,
            'sku' => 'Scarf',
            'price' => 100,
            'attribute_no' => [1],
            'for_variation_1' => 1,
            'attribute_options_1' => $terms,
            'options_1' => $terms,
        ])->assertOk()->getContent();

        return [
            'attribute_combinations' => $create,
            'attribute_combinations_data' => $data,
            'attribute_combinations_edit' => $edit,
        ];
    }

    /** Every `for` value in document order, including empty ones. @return array<int,string> */
    private function labelTargets(string $html): array
    {
        preg_match_all('~<label\b[^>]*\sfor="([^"]*)"~', $html, $m);

        return $m[1];
    }

    /** How many elements carry this exact id. `\s` before `id=` keeps `data-id=` out. */
    private function idCount(string $html, string $id): int
    {
        return preg_match_all('~\sid="' . preg_quote($id, '~') . '"~', $html);
    }

    // =========================================================================================
    // THE DETECTOR'S OWN NEGATIVE CONTROL -- it must still be able to see the defect
    // =========================================================================================

    /**
     * If this ever fails, every assertion in this file is vacuous: it would mean the collector
     * cannot see an empty `for` even when one is rendered. Driven against the real vendored
     * builder in exactly the shape the three views used, so it keeps measuring the thing the
     * repair removed rather than a memory of it.
     */
    public function test_the_collector_still_sees_an_unnamed_label(): void
    {
        $broken = app('form')->label('', __('SKU'), ['class' => 'form-label'])->toHtml();

        $this->assertStringContainsString('for=""', $broken,
            'premise: konekt no longer interpolates argument 1 into for=, so this file is '
            . 'measuring a builder that behaves differently from the one that produced the defect');

        $this->assertSame([''], $this->labelTargets($broken),
            'the collector cannot see an empty for= any more, so every assertion below passes '
            . 'for the wrong reason');

        /* And it sees a REAL target too, or it would "pass" by matching nothing. */
        $named = app('form')->label('product_sku_M_L_0', __('SKU'), ['class' => 'form-label'])->toHtml();
        $this->assertSame(['product_sku_M_L_0'], $this->labelTargets($named));
    }

    // =========================================================================================
    // THE DEFECT
    // =========================================================================================

    public function test_no_label_in_a_variant_panel_points_at_nothing(): void
    {
        $a = $this->arrange();

        foreach ($this->fragments($a) as $view => $html) {
            $targets = $this->labelTargets($html);

            $this->assertNotEmpty($targets, $view . ': premise -- no labels rendered at all, so '
                . 'this assertion is measuring an empty document');

            $empty = array_values(array_filter($targets, fn ($for) => trim($for) === ''));

            $this->assertSame([], $empty, $view . ': ' . count($empty) . ' label(s) render for="" '
                . '-- each one names no control, so a screen reader announces that field with a '
                . 'role and a value but no name, and clicking the visible caption focuses nothing');
        }
    }

    public function test_every_variant_label_resolves_to_exactly_one_control(): void
    {
        $a = $this->arrange();

        foreach ($this->fragments($a) as $view => $html) {
            $targets = array_values(array_unique($this->labelTargets($html)));
            $this->assertNotEmpty($targets, $view . ': premise -- no labels rendered');

            foreach ($targets as $for) {
                $this->assertSame(1, $this->idCount($html, $for), $view . ': for="' . $for . '" '
                    . 'resolves to ' . $this->idCount($html, $for) . ' element(s). Zero is a '
                    . 'dangling reference and two is the F163/F73 duplicate-id class, where a '
                    . 'label activates the FIRST match and so drives the wrong variant.');
            }
        }
    }

    public function test_each_repaired_field_carries_a_css_safe_id_unique_to_its_variant(): void
    {
        $a = $this->arrange();

        foreach ($this->fragments($a) as $view => $html) {
            foreach (self::REPAIRED_PREFIXES as $prefix) {
                $seen = [];

                foreach (self::KEYS as $term => $safe) {
                    $id = $prefix . $safe;

                    /*
                     * `assertLessThanOrEqual`, not `assertSame(1, ...)`, and the asymmetry is
                     * measured rather than defensive: `attribute_combinations_data.blade.php`'s
                     * Stock Status sits behind `@if ($combination->stock_status != null)` with no
                     * `@else`, so a null legitimately renders zero of them. The property is
                     * "never two", which is what the duplicate-id defect would produce.
                     */
                    $this->assertLessThanOrEqual(1, $this->idCount($html, $id), $view . ': id="'
                        . $id . '" appears more than once');

                    if ($this->idCount($html, $id) === 1) {
                        $seen[$term] = $id;

                        $this->assertMatchesRegularExpression('~^[A-Za-z0-9_-]+$~', $id,
                            $view . ': id="' . $id . '" is not a CSS-safe identifier, so any '
                            . 'querySelector built from it throws (F72)');
                    }
                }

                $this->assertNotEmpty($seen, $view . ': no variant renders ' . $prefix
                    . '<id> at all, so this field is still unnamed');

                if (count($seen) === 2) {
                    $this->assertNotSame(
                        $seen['M/L'],
                        $seen['M.L'],
                        $view . ': ' . $prefix . ' gives `M/L` and `M.L` the SAME id. The '
                        . 'sanitiser collapses both to M_L; only the loop index separates them '
                        . '(F73).'
                    );
                }
            }
        }
    }

    /**
     * EXACTLY ONE Stock Status control where both of its branches can render. In
     * `attribute_combinations_edit.blade.php` the control is a true `@if/@else` pair, so a
     * copy-paste that gave the two branches different ids -- or that repaired only the branch the
     * fixture happens to take -- looks correct until the other branch renders.
     */
    public function test_the_edit_views_two_stock_status_branches_share_one_id(): void
    {
        $a = $this->arrange();
        $html = $this->fragments($a)['attribute_combinations_edit'];

        foreach (self::KEYS as $term => $safe) {
            $this->assertSame(1, $this->idCount($html, 'stock_status_' . $safe),
                'attribute_combinations_edit: stock_status_' . $safe . ' must be emitted exactly '
                . 'once -- the @if and the @else are twins and only one of them renders');
        }

        /* And the other branch carries the same id, which only the source can show. */
        $source = file_get_contents(base_path('resources/views/product/attribute_combinations_edit.blade.php'));
        $this->assertSame(2, substr_count($source, "Form::label('stock_status_' . \$safe_str"),
            'attribute_combinations_edit: both Stock Status branches must name the same control; '
            . 'one of them was missed');
    }

    // =========================================================================================
    // THE GROUP CAPTION -- a name for a SET of radios, which a <label> cannot carry
    // =========================================================================================

    public function test_the_backorder_radios_are_a_named_group(): void
    {
        $a = $this->arrange();

        foreach ($this->fragments($a) as $view => $html) {
            foreach (self::KEYS as $term => $safe) {
                $this->assertMatchesRegularExpression(
                    '~<fieldset[^>]*data-ss-choicegroup="stock_order_status_' . preg_quote($safe, '~') . '"~',
                    $html,
                    $view . ': the Allow BackOrders radios for ' . $term . ' are not a group, so '
                    . 'their caption reaches no accessibility tree and a reader announces "Do Not '
                    . 'Allow, radio button 1 of 3" with no indication of what is being chosen'
                );
            }

            $this->assertStringContainsString('<legend class="ss-choicegroup__legend">', $html,
                $view . ': the group has no legend');

            /*
             * AND IT MUST NOT SHADOW THE EDITOR'S OWN GROUPS. `ChoiceGroupGuidanceTest` finds its
             * fieldsets with `data-ss-choicegroup="stock_order_status"` -- the closing quote
             * included -- and `strpos` takes the FIRST hit, so a variant group named plainly
             * `stock_order_status` would point those assertions at the wrong element.
             */
            $this->assertStringNotContainsString('data-ss-choicegroup="stock_order_status"', $html,
                $view . ': a variant group is named without its variant suffix, which shadows the '
                . 'product editor\'s own group for ChoiceGroupGuidanceTest');

            /* No hint is passed, so nothing may dangle. */
            preg_match_all('~aria-describedby="([^"]+)"~', $html, $refs);
            foreach (array_unique($refs[1]) as $ref) {
                foreach (preg_split('~\s+~', trim($ref)) as $one) {
                    if ($one === '') {
                        continue;
                    }
                    $this->assertSame(1, $this->idCount($html, $one),
                        $view . ': aria-describedby points at "' . $one . '", which is on no '
                        . 'element -- a reader then announces neither');
                }
            }
        }
    }

    // =========================================================================================
    // NEGATIVE CONTROLS -- what the repair must NOT have taken away
    // =========================================================================================

    /**
     * THE SUBMITTED NAME STAYS THE RAW VARIANT KEY. `ProductController::update()` reads
     * `$request['product_sku_' . str_replace('.', '_', $str)]`, and so do
     * `AuctionProduct`'s controller and `CartQuantityControl`'s model. `$safe_str` appends
     * `_<loop index>`, so a name moved onto it makes every one of those reads miss and write 0 or
     * '' over a live variant's price, sale price, stock, low-stock threshold, weight and SKU.
     */
    public function test_the_submitted_field_names_stay_the_raw_variant_key(): void
    {
        $a = $this->arrange();

        foreach ($this->fragments($a) as $view => $html) {
            foreach (array_keys(self::KEYS) as $term) {
                foreach (['product_sku_', 'product_sale_price_', 'product_stock_', 'low_stock_threshold_'] as $prefix) {
                    $this->assertStringContainsString('name="' . $prefix . $term . '"', $html,
                        $view . ': the submitted name for ' . $prefix . ' is no longer the raw '
                        . 'variant key "' . $term . '", so ProductController::update() reads a '
                        . 'key that is not posted and writes an empty value over a live variant');
                }

                foreach (self::KEYS as $safe) {
                    $this->assertStringNotContainsString('name="product_sku_' . $safe . '"', $html,
                        $view . ': a field name has moved onto the sanitised key');
                }
            }
        }

        /*
         * AND THE STORED VALUES STILL COME BACK through those names -- the assertion
         * `VariantCombinationVocabularyTest` already makes, repeated here against the variant key
         * that contains a CSS-special character, because an inserted `id` attribute sits between
         * `name=` and `value=` in konekt's output.
         */
        $html = $this->fragments($a)['attribute_combinations_data'];
        $this->assertMatchesRegularExpression('~name="product_sale_price_M/L"[^>]*value="43(\.0+)?"~', $html);
        $this->assertMatchesRegularExpression('~name="product_stock_M\.L"[^>]*value="6"~', $html);
    }

    /**
     * THE ASSOCIATIONS THAT ALREADY WORKED DO NOT MOVE. The three backorder radios carry correct
     * raw-`$str` `for`/`id` pairs and the Shipping select was repaired under F163; rewriting
     * either would be churn on working markup, and the F163/F72 ratchets pin the Shipping shape.
     */
    public function test_the_existing_associations_are_untouched(): void
    {
        $a = $this->arrange();

        foreach ($this->fragments($a) as $view => $html) {
            foreach (array_keys(self::KEYS) as $term) {
                foreach (['not_allow_', 'notify_customer_', 'allow_'] as $prefix) {
                    $this->assertStringContainsString('for="' . $prefix . $term . '"', $html,
                        $view . ': the ' . $prefix . ' radio lost its label association');
                    $this->assertSame(1, $this->idCount($html, $prefix . $term),
                        $view . ': the ' . $prefix . ' radio\'s id moved or duplicated');
                }

                $this->assertStringContainsString('for="manage_stock_' . $term . '"', $html,
                    $view . ': the Manage stock? checkbox lost its label association');
            }

            foreach (self::KEYS as $safe) {
                $this->assertStringContainsString('for="shipping_id_' . $safe . '"', $html,
                    $view . ': the F163 Shipping association was rewritten');
                $this->assertSame(1, $this->idCount($html, 'shipping_id_' . $safe), $view
                    . ': the Shipping select\'s unique id moved');
            }
        }
    }

    /**
     * THE PROGRESSIVE-DISCLOSURE PREMISE, pinned rather than assumed. `product_stock_`, the
     * backorder group and `low_stock_threshold_` sit inside
     * `<div class="row col-md-12 d-none enable_manage_stock ...">`: present in the HTTP body,
     * absent from the computed accessibility tree until "Manage stock?" is checked. This file
     * asserts the BODY, so the hidden three are visible to it -- but any axe or
     * accessibility-tree probe must click the toggle FIRST and assert the reveal, or it reports
     * three of nine still unnamed on a correct repair. That is the pair of false readings
     * P2-06-12 already paid for, and it is written down here because this test's own correctness
     * depends on which of the two questions it is asking.
     */
    public function test_the_fields_behind_manage_stock_are_in_the_body_before_any_reveal(): void
    {
        $a = $this->arrange();

        foreach ($this->fragments($a) as $view => $html) {
            $this->assertStringContainsString('d-none enable_manage_stock', $html,
                $view . ': premise -- the manage-stock block is no longer hidden, so this test is '
                . 'no longer measuring a body-level question');

            foreach (self::KEYS as $safe) {
                $this->assertSame(1, $this->idCount($html, 'product_stock_' . $safe), $view
                    . ': product_stock_' . $safe . ' is not in the body, so the repair reached '
                    . 'only the fields that happen to be visible');
                $this->assertSame(1, $this->idCount($html, 'low_stock_threshold_' . $safe), $view
                    . ': low_stock_threshold_' . $safe . ' is not in the body');
            }
        }
    }

    // =========================================================================================
    // THE ONE VISUAL CONSEQUENCE, MEASURED
    // =========================================================================================

    /**
     * THE CAPTION CHANGES COLOUR TOKEN, so the ratio is computed rather than assumed.
     *
     * It moved from `.form-label`, which inherits `--bs-body-color` (#293240), onto
     * `.ss-choicegroup__legend`, which sets `color: var(--ss-fg)`. Computed here with the WCAG
     * 2.x relative-luminance formula from the token values actually in the stylesheet:
     *
     *   LIGHT  #1a1d23 on the card's #ffffff  -> 16.88:1  (the caption it replaced: 12.93:1)
     *   DARK   #e8eaed on --ss-surface #262b33 -> 11.81:1
     *
     * Both pass AA for normal text with a wide margin, and light mode improves. The numbers are
     * recomputed from the stylesheet on every run so a token edit cannot quietly undercut them.
     *
     * THE TYPOGRAPHIC DELTA IS REAL AND IS NOT A CONTRAST PROBLEM, and it is recorded rather than
     * hidden: the legend is 0.8125rem/600 while the two `.form-label` captions beside it in the
     * same three-column row are 0.875rem/400. That is a 1px smaller, heavier caption sitting
     * between two lighter ones. It is the same delta the product editor's two existing groups
     * already carry; what is new here is that the converted caption has unconverted siblings on
     * its own row. Not screenshotted -- stated as the measured CSS difference it is.
     *
     * A BARE `<fieldset>` WOULD HAVE BEEN MUCH WORSE, which is why the component is used: the
     * theme's own `legend` rule is `float: left; width: 100%; font-size: calc(1.275rem + 0.3vw)`
     * (public/assets/css/style.css:519-526) -- a ~20px floated heading. `.ss-choicegroup__legend`
     * resets `float` to `none` and `width` to `auto`, which is also the only direction-sensitive
     * declaration involved, so one rule in `store-syria-ds.css` covers LTR and Arabic RTL alike
     * and no `custom.css` / `rtl-custom.css` pair is needed.
     */
    public function test_the_group_legend_keeps_a_readable_contrast_in_both_themes(): void
    {
        $ds = file_get_contents(base_path('public/css/store-syria-ds.css'));

        /* Premises: the tokens the ratios below are computed from are still these values. */
        $this->assertStringContainsString('--ss-grey-900: #1a1d23;', $ds,
            'the light-mode foreground token changed, so the measured 16.88:1 is stale');
        $this->assertStringContainsString('--ss-fg:        #e8eaed;', $ds,
            'the dark-mode foreground token changed, so the measured 11.81:1 is stale');
        $this->assertStringContainsString('--ss-surface:       #262b33;', $ds,
            'the dark-mode card surface changed, so the measured 11.81:1 is stale');
        $this->assertMatchesRegularExpression('~\.ss-choicegroup__legend\s*\{[^}]*color:\s*var\(--ss-fg\)~s', $ds,
            'the legend no longer takes its colour from --ss-fg, so these ratios measure the '
            . 'wrong declaration');
        $this->assertMatchesRegularExpression('~\.ss-choicegroup__legend\s*\{[^}]*float:\s*none~s', $ds,
            'the legend no longer resets Bootstrap\'s float, which breaks the following flow and '
            . 'does so differently in LTR and RTL');

        $this->assertSame(16.88, $this->contrast('#1a1d23', '#ffffff'),
            'light mode: the group legend on a card');
        $this->assertSame(11.81, $this->contrast('#e8eaed', '#262b33'),
            'dark mode: the group legend on --ss-surface');

        foreach ([['#1a1d23', '#ffffff'], ['#e8eaed', '#262b33']] as [$fg, $bg]) {
            $this->assertGreaterThanOrEqual(4.5, $this->contrast($fg, $bg),
                $fg . ' on ' . $bg . ' fails WCAG AA for normal text');
        }
    }

    /** WCAG 2.x relative luminance and contrast ratio, rounded to two places. */
    private function contrast(string $a, string $b): float
    {
        $lum = function (string $hex): float {
            $hex = ltrim($hex, '#');
            $chan = function (int $v): float {
                $c = $v / 255;

                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            };

            return 0.2126 * $chan((int) hexdec(substr($hex, 0, 2)))
                 + 0.7152 * $chan((int) hexdec(substr($hex, 2, 2)))
                 + 0.0722 * $chan((int) hexdec(substr($hex, 4, 2)));
        };

        $la = $lum($a);
        $lb = $lum($b);

        return round((max($la, $lb) + 0.05) / (min($la, $lb) + 0.05), 2);
    }

    /**
     * THE AJAX FRAGMENT'S TOGGLES STILL REACH WHAT THEY TOGGLE.
     *
     * The parent injects these fragments with `.html()`, which re-evaluates their scripts on every
     * injection, and every handler reaches into a panel with `closest('.accordion-item')` plus
     * `.find('<class>')` -- a DESCENDANT match, so a new `<fieldset>` between them is transparent.
     * The failure mode of getting that wrong is silent: "Manage stock?", "Virtual" and
     * "Downloadable" simply stop revealing their rows. Asserted on the rendered document rather
     * than reasoned about, because the nesting is what the handlers depend on:
     *
     *   - every toggle TARGET class is still emitted once per variant, and
     *   - the new fieldset sits INSIDE `.enable_manage_stock` rather than around it, so the
     *     container the handler shows is still the container that holds the three fields.
     */
    public function test_the_toggle_targets_are_still_reachable_from_their_panels(): void
    {
        $a = $this->arrange();

        foreach ($this->fragments($a) as $view => $html) {
            foreach (['enable_manage_stock', 'weight-div', 'shipping-div', 'download-product'] as $target) {
                $this->assertGreaterThanOrEqual(2, substr_count($html, $target), $view
                    . ': the .' . $target . ' toggle target is no longer emitted once per variant, '
                    . 'so container.find() reaches nothing and that row never reveals');
            }

            /* The fieldset is a DESCENDANT of the block the manage-stock toggle reveals. */
            $block = strpos($html, 'd-none enable_manage_stock');
            $this->assertNotFalse($block, $view . ': premise -- the manage-stock block is gone');

            $fieldset = strpos($html, '<fieldset class="ss-choicegroup"', $block);
            $this->assertNotFalse($fieldset, $view . ': the backorder group is not inside the '
                . 'block the manage-stock toggle reveals, so revealing that block no longer '
                . 'reveals the group');

            $this->assertLessThan(
                strpos($html, 'low_stock_threshold_', $fieldset),
                strpos($html, '</fieldset>', $fieldset),
                $view . ': the fieldset swallowed the Low stock threshold column, which moves a '
                . 'field out of its own Bootstrap grid cell'
            );
        }
    }
}
