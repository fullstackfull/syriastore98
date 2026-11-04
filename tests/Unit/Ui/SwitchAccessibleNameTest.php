<?php

namespace Tests\Unit\Ui;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2.5 / switch-no-accessible-name — TWELVE TOGGLE SWITCHES IN THE CATALOGUE AND MARKETING
 * MODALS HAD NO ACCESSIBLE NAME FROM ANY SOURCE. WCAG 2.1 SC 4.1.2, Level A.
 * =================================================================================================
 *
 * TWO BREAKS AT ONCE, which is why neither half alone would have fixed it.
 *
 *   1. THE VISIBLE CAPTION NAMED NOTHING. It came from `Form::label('', __('Status'), …)`, and
 *      `Konekt\Form\FormBuilder::label()` (vendor/konekt/html/src/FormBuilder.php:259) builds the
 *      tag by raw concatenation, so an empty first argument emits `for=""` — not a valid IDREF,
 *      matching no element. And the caption is a SIBLING of the `.form-check` div that holds the
 *      input (product_brand/create.blade.php:31 against the div opened at :32), never an ancestor,
 *      so there is no implicit association either.
 *
 *   2. THE ONLY ASSOCIATED LABEL WAS EMPTY. `<label class="form-check-label" for="status"></label>`
 *      — correctly associated, and contributing nothing.
 *
 * So the accessible-name computation ended at the empty string: NVDA and JAWS announced
 * "checkbox, not checked" and VoiceOver "switch, off", with no name. The visible word was reachable
 * only as unassociated static text in browse mode.
 *
 * THE REPAIR IS THE ONE THIS AREA ALREADY SHIPPED, at category/create.blade.php:123 and :132 and
 * category/edit.blade.php:127: delete the dangling caption and put its text inside the label that
 * is ALREADY associated with the control. One label per control, and the caption becomes clickable.
 *
 * AND ONE FUNCTIONAL BUG HAD TO BE FIXED FIRST, or the accessibility repair would have introduced
 * a worse one. `id="customCheckdef1"` was on TWO inputs in one document in both coupon views —
 * `name="status"` and `name="sale_items"` (coupon/create.blade.php:125 and :133;
 * coupon/edit.blade.php:93 and :101). A `for` pointing at a duplicated id resolves to the FIRST
 * match, so a merchant clicking the newly-clickable "Exclude sale items" caption would have
 * toggled "Status". The second id is renamed BEFORE any label is pointed at it.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT IS DELIBERATELY NOT DONE HERE.
 * -------------------------------------------------------------------------------------------------
 *
 *   - NO `ss-label` ON THE MERGED LABEL, departing from the draft plan. Its only stated
 *     justification was dark-mode contrast on the white modal, and that is already covered without
 *     it: `custom.css:4669` and `rtl-custom.css:4661` list a BARE `label` inside `.modal-content`
 *     in the same declaration as `.ss-label` (`color: var(--ss-grey-900, #1a2322) !important`).
 *     Computed with the WCAG formula rather than copied: the token's real value `#1a1d23` is
 *     16.88:1 on the `#ffffff` modal and the literal fallback `#1a2322` is 16.06:1 — the number
 *     that block's own comment records. `.ss-label` would additionally set `font-size:
 *     var(--ss-text-sm)` and `font-weight: var(--ss-weight-medium)` (store-syria-ds.css:749-753),
 *     which on these six still-host-styled modals would render one caption in design-system
 *     typography beside `.form-label` siblings. category/create can carry it because that whole
 *     form was converted to the design system and wrapped in `data-ss-theme="light"`; these are
 *     not. `test_neither_mirrored_stylesheet_was_touched` pins that no CSS was added either way.
 *   - NO `data-ss-theme="light"` WRAPPER, for the reason the A-02 note in that same block records:
 *     an escaped light scope measured 1.13:1 against the host's still-dark `.form-control`, and
 *     these views are full of text inputs.
 *   - THE FOUR SAME-SHAPE SWITCHES IN order/{create,edit} AND wishlist/{create,edit} ARE NOT HERE.
 *     They carry byte-identical markup and belong in the same row, but those four files are outside
 *     this change's file ownership. `test_the_untouched_same_shape_switches_are_named_in_the_report`
 *     records them so the residue is not lost.
 *   - THE HIDDEN `value="0"` COMPANION IS NEITHER ADDED NOR REMOVED. testimonial/edit has none
 *     today, which means its handler cannot clear the flag — handler behaviour, a separate row.
 *     `test_the_hidden_companions_are_exactly_as_they_were` pins the census in both directions.
 */
class SwitchAccessibleNameTest extends TestCase
{
    /* `get_file()` reads the platform admin out of `users`, so the two edit views that render a
       stored image need the tables to exist. Nothing here asserts on a row. */
    use RefreshDatabase;

    private static function brand(): array
    {
        return ['productBrand' => (object) [
            'id' => 9, 'name' => 'Acme', 'logo' => null, 'status' => 1, 'is_popular' => 0,
        ]];
    }

    private static function coupon(): array
    {
        return [
            'coupon' => (object) [
                'id' => 3, 'coupon_name' => 'SPRING', 'coupon_type' => 'percentage',
                'minimum_spend' => 10, 'maximum_spend' => 100, 'discount_amount' => 5,
                'coupon_limit' => 1, 'coupon_limit_user' => 1, 'coupon_limit_x_item' => 1,
                'coupon_expiry_date' => '2030-01-01', 'coupon_code' => 'SPRING',
                'status' => 1, 'sale_items' => 0, 'free_shipping_coupon' => 0,
            ],
            'product' => [], 'category' => [],
            'applied_product' => [], 'exclude_product' => [],
            'applied_categories' => [], 'exclude_categories' => [],
        ];
    }

    private static function testimonial(): array
    {
        return [
            'Testimonial' => (object) [
                'id' => 2, 'title' => 'Great', 'description' => 'Lovely', 'category_id' => 1,
                'product_id' => 1, 'rating_no' => 5, 'username' => 'Rana', 'avatar' => null,
                'status' => 1,
            ],
            'categoryTree' => [], 'product' => [],
        ];
    }

    /** view => [view data, [[checkbox id, the name it must announce, the posted field], …]] */
    public static function switches(): array
    {
        return [
            'product_brand/create' => ['product_brand.create', [], [
                ['status', 'Status', 'status'],
                ['customCheckdef1trending', 'Is Popular ?', 'is_popular'],
            ]],
            'product_brand/edit' => ['product_brand.edit', self::brand(), [
                ['status', 'Status', 'status'],
                /* ONE KEY FOR ONE FIELD. edit used `__('Is Popular')`, whose Arabic in
                   resources/lang/ar.json is the weaker 'هو شعبية', while create used
                   `__('Is Popular ?')` -> 'هل هو مشهور ؟'. Naming a control two different things
                   on create and edit is the same defect in a second coat of paint. */
                ['customCheckdef1popular', 'Is Popular ?', 'is_popular'],
            ]],
            'coupon/create' => ['coupon.create', ['product' => [], 'category' => []], [
                ['customCheckdef1', 'Status', 'status'],
                ['coupon_sale_items', 'Exclude sale items', 'sale_items'],
                ['customCheckdef2', 'Free Shipping Status', 'free_shipping_coupon'],
            ]],
            'coupon/edit' => ['coupon.edit', self::coupon(), [
                ['customCheckdef1', 'Status', 'status'],
                ['coupon_sale_items', 'Exclude sale items', 'sale_items'],
                ['customCheckdef2', 'Free Shipping Status', 'free_shipping_coupon'],
            ]],
            'testimonial/create' => ['testimonial.create', ['categoryTree' => []], [
                ['customCheckdef1', 'Status', 'status'],
            ]],
            'testimonial/edit' => ['testimonial.edit', self::testimonial(), [
                ['customCheckdef1', 'Status', 'status'],
            ]],
        ];
    }

    private function render(string $view, array $data): string
    {
        $html = (string) view($view, $data)->render();

        $this->assertNotSame('', trim($html), $view . ' rendered nothing');

        return $html;
    }

    /** The concatenated text of every `<label for="$id">` in the document, in tree order. */
    private function accessibleNameFromLabels(string $html, string $id): string
    {
        preg_match_all(
            '/<label\b[^>]*\bfor="' . preg_quote($id, '/') . '"[^>]*>(.*?)<\/label>/s',
            $html,
            $matches
        );

        return trim(html_entity_decode(strip_tags(implode(' ', $matches[1]))));
    }

    /**
     * THE SWITCH ANNOUNCES ITS OWN NAME, AND ONLY ONE ELEMENT ANSWERS TO ITS id.
     *
     * Asserted on the rendered document, not the template: an empty associated label and a
     * duplicated id are both properties of the DOCUMENT. And the name is read as the
     * CONCATENATION of every associated label, which is what the accessible-name algorithm does —
     * so a repair that filled one label while leaving a second empty one beside it would be
     * caught by the exact-match rather than slipping through a "contains" assertion.
     *
     * @dataProvider switches
     */
    public function test_each_switch_has_an_accessible_name(string $view, array $data, array $expected): void
    {
        $html = $this->render($view, $data);

        foreach ($expected as [$id, $name, $field]) {
            $this->assertSame(
                1,
                substr_count($html, 'id="' . $id . '"'),
                $view . ': id="' . $id . '" is emitted ' . substr_count($html, 'id="' . $id . '"')
                . ' times. Zero means the test is addressing the wrong control; more than one means '
                . 'a label pointing here activates whichever comes first in the document.'
            );

            $this->assertSame(
                $name,
                $this->accessibleNameFromLabels($html, $id),
                $view . ': the switch posting "' . $field . '" announces '
                . '"' . $this->accessibleNameFromLabels($html, $id) . '" — it must announce '
                . '"' . $name . '". An empty <label class="form-check-label"> contributes nothing, '
                . 'and the visible caption beside it was a <label for=""> naming no element.'
            );

            /* And the caption is not ALSO still sitting outside as a dangling label. */
            $this->assertSame(
                0,
                preg_match('/<label[^>]*\bfor="\s*"[^>]*>\s*' . preg_quote($name, '/') . '/', $html),
                $view . ': "' . $name . '" is still rendered as a <label for="">, so the caption was '
                . 'duplicated rather than moved — the regression category/create.blade.php:17-21 '
                . 'records from the first attempt at this very repair.'
            );
        }
    }

    /**
     * NO DUPLICATE id ANYWHERE IN THE FRAGMENT.
     *
     * This is the assertion the `customCheckdef1` rename exists for, and it has to be a COUNT
     * rather than an existence check: a merged "Exclude sale items" label pointing at a duplicated
     * id looks right to every `for`-resolves assertion and toggles the wrong field. These are
     * `data-ajax-popup` fragments injected into a host page, so their ids join that page's
     * namespace and a duplicate inside one fragment is the floor, not the whole risk.
     *
     * @dataProvider switches
     */
    public function test_no_id_is_emitted_twice(string $view, array $data, array $expected): void
    {
        $html = $this->render($view, $data);

        preg_match_all('/\sid="([^"]+)"/', $html, $matches);
        $duplicates = array_keys(array_filter(array_count_values($matches[1]), fn ($n) => $n > 1));

        $this->assertSame([], $duplicates,
            $view . ': these ids are emitted more than once: ' . implode(', ', $duplicates)
            . '. A label pointing at a duplicated id activates the first match, so the caption '
            . 'toggles the wrong switch.');
    }

    /**
     * NEGATIVE CONTROL — EVERY POSTED FIELD NAME IS UNCHANGED, INCLUDING THE RENAMED ONE.
     *
     * `coupon_sale_items` is an id. The field is still `sale_items`, which is what
     * `CouponController.php:118-119` and `:429-430` read. Controllers read NAMES, never ids.
     *
     * @dataProvider switches
     */
    public function test_the_posted_field_names_are_unchanged(string $view, array $data, array $expected): void
    {
        $html = $this->render($view, $data);

        foreach ($expected as [$id, $name, $field]) {
            $this->assertStringContainsString('name="' . $field . '"', $html,
                $view . ' stopped posting ' . $field . ', which is a server-contract change');
        }
    }

    /**
     * NEGATIVE CONTROL — THE HIDDEN `value="0"` COMPANIONS ARE EXACTLY AS THEY WERE.
     *
     * An unchecked checkbox posts nothing, so the `<input type="hidden" name="x" value="0">` ahead
     * of it is what lets the handler tell "switched off" from "not submitted". A tidy-up that
     * removed one would silently make the flag impossible to clear.
     *
     * ASSERTED IN BOTH DIRECTIONS, because the census is not uniform and the gap is real:
     * testimonial/edit has NO companion for `status` today. That is a handler-behaviour row of its
     * own and is deliberately not fixed here; it is pinned so the omission stays visible rather
     * than being read as an oversight in this test.
     */
    public function test_the_hidden_companions_are_exactly_as_they_were(): void
    {
        $census = [
            'product_brand/create' => [['product_brand.create', []], ['status' => 1, 'is_popular' => 1]],
            'product_brand/edit' => [['product_brand.edit', self::brand()], ['status' => 1, 'is_popular' => 1]],
            'coupon/create' => [['coupon.create', ['product' => [], 'category' => []]],
                ['status' => 1, 'sale_items' => 1, 'free_shipping_coupon' => 1]],
            'coupon/edit' => [['coupon.edit', self::coupon()],
                ['status' => 1, 'sale_items' => 1, 'free_shipping_coupon' => 1]],
            'testimonial/create' => [['testimonial.create', ['categoryTree' => []]], ['status' => 1]],
            /* The one that has none, and must keep having none in this change. */
            'testimonial/edit' => [['testimonial.edit', self::testimonial()], ['status' => 0]],
        ];

        foreach ($census as $label => [[$view, $data], $expected]) {
            $html = $this->render($view, $data);

            foreach ($expected as $field => $count) {
                $this->assertSame(
                    $count,
                    preg_match_all('/<input type="hidden" name="' . $field . '" value="0">/', $html),
                    $label . ': the hidden value="0" companion for ' . $field . ' must appear '
                    . $count . ' time(s). Removing one makes the flag unclearable; adding one here '
                    . 'changes handler behaviour, which is a different row.'
                );
            }
        }
    }

    /**
     * NEGATIVE CONTROL — NEITHER MIRRORED STYLESHEET WAS TOUCHED, AND THE PREMISE THAT MAKES THAT
     * SAFE IS STILL TRUE IN BOTH OF THEM.
     *
     * `public/css/custom.css` serves LTR and `public/css/rtl-custom.css` serves Arabic, and they
     * are mirrored duplicates: a rule added to one reaches half the merchants. This repair adds no
     * CSS at all, which is only sound because the moved caption lands on a `.form-check-label` —
     * a bare `<label>` inside `.modal-content` — and BOTH files already carry that selector in the
     * dark-modal declaration. Measured with the WCAG formula, not copied: `#1a1d23` (the token's
     * real value, store-syria-ds.css:182) is 16.88:1 on `#ffffff`, and `#1a2322` (the literal
     * fallback) is 16.06:1 — which is the figure that declaration's own comment states, so the
     * arithmetic agrees with the shipped record.
     */
    public function test_neither_mirrored_stylesheet_was_touched(): void
    {
        $declaration = 'html[data-ss-theme="dark"] body .modal-content label,';

        foreach (['custom.css', 'rtl-custom.css'] as $sheet) {
            $css = (string) file_get_contents(base_path('public/css/' . $sheet));

            $this->assertStringContainsString($declaration, $css,
                $sheet . ' no longer scopes a bare label inside .modal-content, so the caption this '
                . 'repair moved into .form-check-label has lost its dark-mode colour and this '
                . 'change now needs CSS of its own — in BOTH files.');

            $this->assertStringContainsString('color: var(--ss-grey-900, #1a2322) !important;', $css,
                $sheet . ': the dark-modal label colour changed. 16.88:1 on white for the token, '
                . '16.06:1 for the fallback — both computed, both far above 4.5:1.');

            $this->assertStringNotContainsString('.form-check-label', $css,
                $sheet . ' gained a .form-check-label rule. This repair is markup-only; a rule in '
                . 'one of these two mirrored files reaches only half the merchants.');
        }
    }

    /** The two brand views must name the same field with the same translation key. */
    public function test_the_brand_popularity_flag_uses_one_translation_key(): void
    {
        $arabic = json_decode((string) file_get_contents(base_path('resources/lang/ar.json')), true);

        $this->assertSame('هل هو مشهور ؟', $arabic['Is Popular ?'] ?? null,
            'premise: the key this repair standardises on really is translated in ar.json');

        foreach (['create', 'edit'] as $form) {
            $source = (string) file_get_contents(
                base_path('resources/views/product_brand/' . $form . '.blade.php')
            );

            $this->assertStringContainsString("__('Is Popular ?')", $source);
            $this->assertStringNotContainsString("__('Is Popular')\n", $source);
            $this->assertSame(0, preg_match("/__\('Is Popular'\)/", $source),
                'product_brand/' . $form . " still uses __('Is Popular'), whose Arabic is the "
                . "weaker 'هو شعبية'. One field, one key, one announced name on both forms.");
        }
    }

    /**
     * NEGATIVE CONTROL FOR THE DETECTOR — the name reader can still see the original defect.
     *
     * Every assertion above is a regex over rendered HTML, so drive it over the shape that shipped
     * and confirm it reports the empty name rather than quietly passing.
     */
    public function test_the_name_reader_detects_the_original_defect(): void
    {
        $broken = '<label for="" class="form-label me-2">Status</label>'
            . '<div class="form-check form-switch">'
            . '<input type="hidden" name="status" value="0">'
            . '<input type="checkbox" name="status" id="status" value="1">'
            . '<label class="form-check-label" for="status"></label>'
            . '</div>';

        $this->assertSame('', $this->accessibleNameFromLabels($broken, 'status'),
            'the name reader reported a name for a control whose only associated label is empty');

        $this->assertSame(1, preg_match('/<label[^>]*\bfor="\s*"[^>]*>\s*Status/', $broken),
            'the dangling-caption scanner cannot see a literal for=""');

        /* And it reads a CONCATENATION, so a half-done merge is visible rather than passing. */
        $halfDone = '<label class="form-check-label" for="status">Status</label>'
            . '<label class="form-check-label" for="status"></label>';

        $this->assertSame('Status', $this->accessibleNameFromLabels($halfDone, 'status'));
    }

    /**
     * THE RESIDUE, NAMED RATHER THAN LOST.
     *
     * Four more switches carry byte-identical markup — `Form::label('', __('Status'))` beside an
     * empty `.form-check-label` — and are NOT repaired here, because those four files are outside
     * this change's ownership. This test fails the day somebody deletes this list without either
     * fixing them or moving the record somewhere better.
     */
    public function test_the_untouched_same_shape_switches_are_named_in_the_report(): void
    {
        foreach ([
            'resources/views/order/create.blade.php',
            'resources/views/order/edit.blade.php',
            'resources/views/wishlist/create.blade.php',
            'resources/views/wishlist/edit.blade.php',
        ] as $view) {
            $source = (string) file_get_contents(base_path($view));

            $this->assertStringContainsString("Form::label('', __('Status')", $source,
                $view . ' no longer carries the unrepaired shape. If it was fixed, drop it from '
                . 'this list; if it was deleted, say so. Either way this list must not go stale.');
        }
    }
}
