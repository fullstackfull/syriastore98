<?php

namespace Tests\Feature\Merchant;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-03-8 -- the advanced-fields disclosure in the product editor.
 * =============================================================================================
 *
 * The owner's condition 2 of 2026-10-26: hiding advanced fields must not prevent submission or
 * lose values, and a validation error must surface and OPEN the relevant section.
 *
 * WHAT THIS FILE PROVES, and what it deliberately does not.
 *
 * It proves the SERVER-SIDE half of condition 2 -- that a failed field named by the section
 * renders the section `open`, with a textual badge, before a byte of JavaScript runs -- and the
 * STRUCTURAL invariants of the two product views: the six fields are inside the disclosure, and
 * `tag_id[]` and every other `required` control is outside it. That last one is not a style
 * point. A `required` control inside a CLOSED <details> is hidden from the browser's own
 * constraint UI, so Chrome refuses the submit and logs an unfocusable-control error: the Save
 * button stops working, with no message on the page and nothing in the server log. A static
 * assertion is the right shape for it because it has to hold for every future edit to the view,
 * not just for the state the fixture happens to be in.
 *
 * It CANNOT prove that a collapsed field is actually submitted. That is a browser fact about
 * multipart assembly, and it is driven by
 * `docs/development/phase-2/tools/check-advanced-fields-live.cjs` (78 cases), which reads the
 * real POST body and the persisted row, and which was bitten by marking a collapsed field
 * `disabled` -- it then failed on both the body and the persistence.
 */
class AdvancedFieldsDisclosureTest extends SecurityTestCase
{
    /** The error bag is SHARED, not passed as render data, because that is what the
     *  component depends on in production: ShareErrorsFromSession calls
     *  View::share('errors', ...), which every view and every component sees. Data handed
     *  to Blade::render is a local of the OUTER template only -- a component rendered
     *  inside it does not receive it, so passing the bag that way left `$errors` null
     *  inside the component and both error cases below failed against working code. */
    private function render(array $fields, array $errors = []): string
    {
        $bag = new ViewErrorBag;
        $bag->put('default', new MessageBag($errors));
        View::share('errors', $bag);

        try {
            return Blade::render(
                '<x-ss.advanced-fields :title="$title" :fields="$fields" :hint="$hint">SLOT</x-ss.advanced-fields>',
                [
                    'title'  => 'Advanced options',
                    'hint'   => 'Set once for the catalogue.',
                    'fields' => $fields,
                ]
            );
        } finally {
            // a shared binding outlives the view, so it is not left for the next test
            View::share('errors', new ViewErrorBag);
        }
    }

    /** The region between the component's own tags, with Blade comments removed so that no
     *  assertion below can be satisfied by the prose that explains it -- two assertions in an
     *  earlier package passed against a comment. */
    private function slotOf(string $view): string
    {
        $src = file_get_contents(base_path('resources/views/product/' . $view . '.blade.php'));
        $src = preg_replace('/\{\{--.*?--\}\}/s', '', $src);

        $open = strpos($src, '<x-ss.advanced-fields');
        $close = strpos($src, '</x-ss.advanced-fields>');
        $this->assertNotFalse($open, $view . ': the disclosure is not used at all');
        $this->assertNotFalse($close, $view . ': the disclosure is not closed');

        return substr($src, $open, $close - $open);
    }

    public function test_it_renders_collapsed_when_nothing_failed(): void
    {
        $html = $this->render(['brand_id', 'tax_id[]']);

        $this->assertStringContainsString('class="ss-advanced"', $html);
        $this->assertStringNotContainsString(' open', $html);
        $this->assertStringNotContainsString('ss-advanced__badge', $html);
        $this->assertStringNotContainsString('data-ss-advanced-error', $html);
    }

    public function test_a_failed_field_opens_the_section_server_side(): void
    {
        $html = $this->render(['brand_id', 'tax_id[]'], ['brand_id' => ['Pick a brand.']]);

        $this->assertMatchesRegularExpression('/<details[^>]*\sopen\b/', $html,
            'a section owning a failed field must render open, with no JavaScript');
        $this->assertStringContainsString('data-ss-advanced-error="true"', $html);
        $this->assertStringContainsString('ss-advanced__badge', $html);
    }

    public function test_a_bracketed_field_name_maps_to_its_error_bag_key(): void
    {
        // the markup says `tax_id[]`; the bag says `tax_id`
        $html = $this->render(['tax_id[]'], ['tax_id' => ['Bad tax.']]);

        $this->assertMatchesRegularExpression('/<details[^>]*\sopen\b/', $html,
            'tax_id[] must be matched against the tax_id error key');
    }

    public function test_a_nested_field_name_maps_to_its_dotted_error_bag_key(): void
    {
        // Laravel keys a nested field as `options.0.price`. `rtrim($name, '[]')` -- the
        // obvious one-liner, and what this component shipped first -- yields
        // `options[0][price` instead and matches nothing, so a future section that owns a
        // repeater field would never open on its own error.
        $html = $this->render(['options[0][price]'], ['options.0.price' => ['Bad price.']]);

        $this->assertMatchesRegularExpression('/<details[^>]*\sopen\b/', $html,
            'options[0][price] must be matched against the options.0.price error key');
    }

    public function test_an_error_on_a_field_the_section_does_not_own_leaves_it_closed(): void
    {
        $html = $this->render(['brand_id'], ['name' => ['Name is required.']]);

        $this->assertDoesNotMatchRegularExpression('/<details[^>]*\sopen\b/', $html,
            'a section must not open for a failure that belongs to another section');
        $this->assertStringNotContainsString('ss-advanced__badge', $html);
    }

    public function test_it_does_not_report_its_state_twice(): void
    {
        // <summary> already exposes expanded/collapsed. A hand-written aria-expanded
        // beside it gives assistive technology two answers that can disagree.
        $html = $this->render(['brand_id'], ['brand_id' => ['x']]);

        $this->assertStringNotContainsString('aria-expanded', $html);
    }

    public static function productViews(): array
    {
        return [['create'], ['edit']];
    }

    /** @dataProvider productViews */
    public function test_the_six_catalogue_fields_are_inside_the_disclosure(string $view): void
    {
        $slot = $this->slotOf($view);

        foreach (['tax_id[]', 'tax_status', 'brand_id', 'label_id', 'shipping_id', 'product_weight'] as $field) {
            $this->assertStringContainsString($field, $slot,
                $view . ": {$field} is no longer inside the disclosure");
        }
    }

    /** @dataProvider productViews */
    public function test_no_required_control_is_inside_the_disclosure(string $view): void
    {
        $slot = $this->slotOf($view);

        $this->assertStringNotContainsString('required', $slot,
            $view . ': a required control inside a closed <details> makes Chrome refuse the '
            . 'submit with an unfocusable-control error -- the Save button silently stops working');
        $this->assertStringNotContainsString('tag_id', $slot,
            $view . ': tag_id[] carries `required` in the markup and must stay outside');
    }

    /** @dataProvider productViews */
    public function test_the_required_fields_stay_outside_the_disclosure(string $view): void
    {
        $slot = $this->slotOf($view);

        // These are `required` in ProductController's own rules. If one were collapsed, the
        // merchant could be refused a save over a field they cannot see.
        foreach (['name', 'category_id', 'cost_price'] as $field) {
            $this->assertStringNotContainsString("'" . $field . "'", $slot,
                $view . ": {$field} is validated as required and must not be collapsed");
        }
    }

    public function test_both_language_files_carry_the_components_strings(): void
    {
        $strings = [
            'Advanced options',
            'Taxes, brand, label, shipping and weight. These are usually set once for the whole catalogue.',
            'Needs attention',
        ];

        foreach (['en', 'ar'] as $locale) {
            $json = json_decode(file_get_contents(base_path('resources/lang/' . $locale . '.json')), true);
            $this->assertIsArray($json, $locale . '.json did not parse');

            foreach ($strings as $string) {
                $this->assertArrayHasKey($string, $json,
                    $locale . '.json is missing "' . $string . '" -- an Arabic merchant would see English');
                $this->assertNotSame('', trim((string) $json[$string]), $locale . ': "' . $string . '" is empty');
            }
        }

        // and the Arabic really is Arabic, not the English copied across
        $ar = json_decode(file_get_contents(base_path('resources/lang/ar.json')), true);
        foreach ($strings as $string) {
            $this->assertNotSame($string, $ar[$string],
                'ar.json still holds the English text for "' . $string . '"');
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $ar[$string],
                'ar.json value for "' . $string . '" contains no Arabic script');
        }
    }

    public function test_the_stylesheet_carries_the_components_rules(): void
    {
        $css = file_get_contents(base_path('public/css/store-syria-ds.css'));

        $this->assertStringContainsString('.ss-advanced {', $css);
        $this->assertStringContainsString('.ss-advanced__summary', $css);
        // the measured choice: --ss-border is 1.30:1 against the card and would vanish
        $this->assertStringContainsString('border: 1px solid var(--ss-border-strong);', $css);
        // the UA triangle is replaced by a chevron that mirrors under RTL
        $this->assertStringContainsString('[dir="rtl"] .ss-advanced__chevron', $css);
        $this->assertStringContainsString('forced-colors: active', $css);
    }
}
