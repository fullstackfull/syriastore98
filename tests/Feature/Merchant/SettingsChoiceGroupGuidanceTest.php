<?php

namespace Tests\Feature\Merchant;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-060 / GAP-061 (P02-I4 + I5) — TASK-ORIENTED INSTRUCTIONS ON THE SETTINGS CHOICE GROUPS.
 * =================================================================================================
 *
 * What the gap register measured: "Choice groups on orders, settings, shipping, staff and the rest
 * carry no task-oriented instruction." `ChoiceGroupGuidanceTest` covers the two groups on the
 * product editor, which is where WS I4/I5 landed; this file covers the SETTINGS panels, which the
 * register names and which that row never reached. It is a separate file because that one is
 * another slice's evidence and its `GROUPS` constant is scoped to the product's two radio names.
 *
 * THE HALF THAT WAS MISSING HERE IS THE INSTRUCTION, NOT THE GROUPING. These panels already use
 * `<fieldset>` + `<legend class="ss-label">`, so each group's NAME already reaches the
 * accessibility tree — P2-06-11 did that. What none of them had is the sentence that says what the
 * choice DOES, which is I5 ("accessible guidance"), and on these three groups the missing sentence
 * is not a nicety:
 *
 *   - `price_type` decides whether the prices a merchant types already contain tax, and the
 *     inclusive behaviour is ALSO conditional on a second control on the same screen. Four call
 *     sites test both (`Cart::...718`, `Cart::...1010`, `Product::...420`, `Tax::...73`), so
 *     choosing inclusive alone silently leaves tax added on top of a price that already has it.
 *   - `site_currency_symbol_name` is labelled "With Currency Name" and prints the currency CODE.
 *   - `color` is ten swatches whose only accessible names are "Theme colour 1" to "Theme colour
 *     10", and what it paints is the merchant PANEL, not the storefront.
 *
 * TWO GROUPS ARE DELIBERATELY LEFT WITHOUT AN INSTRUCTION and that is asserted too, because an
 * instruction describing a control that does not work is worse than none:
 * `test_the_two_groups_whose_behaviour_is_broken_carry_no_instruction` names both defects and the
 * lines they live on. If either is fixed, that test is the place that says so.
 *
 * DRIVEN THROUGH THE REAL TAB ENDPOINT (`POST /setting-form`), which is how the panels actually
 * reach a browser, rather than by rendering the Blade directly: the tab is fetched by AJAX and its
 * content comes back inside JSON, so a view-source assertion would not prove a merchant can ever
 * see it.
 */
final class SettingsChoiceGroupGuidanceTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * The groups that must carry an instruction: radio `name` => the tab that renders it.
     *
     * @var array<string, string>
     */
    private const INSTRUCTED = [
        'price_type' => 'tax_opt_setting',
        'site_currency_symbol_position' => 'currency_setting',
        'site_currency_symbol_name' => 'currency_setting',
        'color' => 'brand_setting',
    ];

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        [$merchant] = $this->makeTenant();
        $this->merchant = $merchant;

        /*
         * `Cache::forget('admin_details')` is not enough on its own here: several settings
         * helpers memoise both the operator row and the settings bag, and the fixture creates
         * its tenant after the application booted.
         */
        Cache::flush();
    }

    /** The rendered panel, taken out of the AJAX envelope the browser actually receives. */
    private function panel(string $tab, ?string $language = null): string
    {
        $merchant = User::find($this->merchant->id);

        if ($language !== null) {
            $merchant->language = $language;
            $merchant->save();
            $merchant = User::find($this->merchant->id);
        }

        $response = $this->actingAs($merchant)->post('/setting-form', ['tab_type' => $tab]);

        $this->assertSame(200, $response->getStatusCode(),
            "the {$tab} panel did not render, so every assertion about it would be vacuous");

        $json = json_decode($response->getContent(), true);

        $this->assertTrue((bool) ($json['is_success'] ?? false),
            "the {$tab} panel came back as a refusal: " . ($json['msg'] ?? ''));

        $content = (string) ($json['data']['content'] ?? '');

        $this->assertNotSame('', $content, "the {$tab} panel rendered empty");

        return $content;
    }

    /** The `<fieldset>` that owns a radio group, found by the group's own radio name. */
    private function fieldsetFor(string $html, string $name): string
    {
        $needle = 'name="' . $name . '"';
        $at = strpos($html, $needle);

        $this->assertNotFalse($at,
            "no control named '{$name}' is on this panel, so the group this test is about is not "
            . 'the group being measured');

        $open = strrpos(substr($html, 0, $at), '<fieldset');

        $this->assertNotFalse($open,
            "the '{$name}' group is not inside a <fieldset>, so its name reaches no reader when "
            . 'focus enters the group (WS I4)');

        $close = strpos($html, '</fieldset>', $open);

        return substr($html, $open, ($close === false ? strlen($html) : $close) - $open);
    }

    /** The text of the element carrying `id`, with tags and entities removed. */
    private function textOfId(string $html, string $id): ?string
    {
        if (preg_match('/id="' . preg_quote($id, '/') . '"[^>]*>(.*?)</s', $html, $m) !== 1) {
            return null;
        }

        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The instruction exists, and is bound to the GROUP
     * ---------------------------------------------------------------------------------------------
     */

    public function test_every_declared_choice_group_carries_an_instruction_bound_to_the_group(): void
    {
        foreach (self::INSTRUCTED as $name => $tab) {
            $html = $this->panel($tab);
            $fieldset = $this->fieldsetFor($html, $name);

            $this->assertMatchesRegularExpression('/<fieldset[^>]*aria-describedby="([^"]+)"/', $fieldset,
                "the '{$name}' group carries no aria-describedby, so its instruction — if any — is "
                . 'announced only when a reader happens to land on the one option it hangs off. A '
                . 'group is described by describing the SET.');

            preg_match('/<fieldset[^>]*aria-describedby="([^"]+)"/', $fieldset, $m);

            $text = $this->textOfId($html, $m[1]);

            $this->assertNotNull($text,
                "the '{$name}' group's aria-describedby points at id '{$m[1]}', which is on no "
                . 'element in this panel. A dangling reference is worse than silence: a reader '
                . 'announces neither.');

            $this->assertGreaterThan(30, mb_strlen((string) $text), sprintf(
                "the '%s' group's instruction is %d characters (\"%s\"), which is a label rather "
                . 'than an instruction. I5 asks what the choice DOES.',
                $name, mb_strlen((string) $text), $text
            ));
        }
    }

    /** The instruction says the thing that makes the choice safe to make. */
    public function test_the_tax_instruction_names_the_second_setting_the_behaviour_depends_on(): void
    {
        $html = $this->panel('tax_opt_setting');
        $fieldset = $this->fieldsetFor($html, 'price_type');

        preg_match('/<fieldset[^>]*aria-describedby="([^"]+)"/', $fieldset, $m);
        $text = (string) $this->textOfId($html, $m[1] ?? '');

        $this->assertStringContainsString('Display prices in the shop', $text,
            'the inclusive-tax behaviour is conditional on BOTH price_type and shop_price — four '
            . 'call sites test the pair — so an instruction that does not name the second control '
            . 'leaves the merchant one setting short of the behaviour they chose');
    }

    public function test_the_brand_colour_instruction_says_it_does_not_change_the_storefront(): void
    {
        $html = $this->panel('brand_setting');
        $fieldset = $this->fieldsetFor($html, 'color');

        preg_match('/<fieldset[^>]*aria-describedby="([^"]+)"/', $fieldset, $m);
        $text = (string) $this->textOfId($html, $m[1] ?? '');

        $this->assertStringContainsString('storefront', $text,
            'the stored colour is printed only by the admin layout '
            . '(resources/views/layouts/app.blade.php:338-340, via StoreColour::forCss) and by '
            . 'nothing under resources/views/front_end/, so a merchant choosing it for their shop '
            . 'is choosing the wrong control and nothing tells them');
    }

    public function test_the_currency_name_instruction_says_a_code_is_printed(): void
    {
        $html = $this->panel('currency_setting');
        $fieldset = $this->fieldsetFor($html, 'site_currency_symbol_name');

        preg_match('/<fieldset[^>]*aria-describedby="([^"]+)"/', $fieldset, $m);
        $text = (string) $this->textOfId($html, $m[1] ?? '');

        $this->assertStringContainsString('code', $text,
            'the option is labelled "With Currency Name" and helper.php:1737 prints '
            . '`defult_currancy`, which is the three-letter CODE. An instruction that repeats the '
            . 'label tells the merchant nothing they did not already misread.');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Nothing dangles, nothing is orphaned
     * ---------------------------------------------------------------------------------------------
     */

    public function test_no_group_description_points_at_an_element_that_is_not_there(): void
    {
        foreach (array_unique(array_values(self::INSTRUCTED)) as $tab) {
            $html = $this->panel($tab);

            preg_match_all('/aria-describedby="([^"]+)"/', $html, $matches);

            foreach ($matches[1] as $reference) {
                foreach (preg_split('/\s+/', trim($reference)) ?: [] as $id) {
                    if ($id === '') {
                        continue;
                    }

                    $this->assertStringContainsString('id="' . $id . '"', $html,
                        "{$tab}: aria-describedby names '{$id}', which is on no element in this "
                        . 'panel');
                }
            }
        }
    }

    public function test_no_group_instruction_is_rendered_without_being_referenced(): void
    {
        foreach (array_unique(array_values(self::INSTRUCTED)) as $tab) {
            $html = $this->panel($tab);

            preg_match_all('/id="([^"]*-group-help)"/', $html, $matches);

            $this->assertNotEmpty($matches[1], "{$tab}: no group instruction was rendered at all");

            foreach ($matches[1] as $id) {
                $this->assertStringContainsString('aria-describedby="' . $id . '"', $html,
                    "{$tab}: the instruction '{$id}' is rendered and referenced by nothing, so it "
                    . 'is decoration. An orphan hint is the half-fix this convention exists to '
                    . 'make visible.');
            }
        }
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The two groups that must NOT be given an instruction yet
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * AN INSTRUCTION FOR A CONTROL THAT DOES NOT WORK IS A FALSE STATEMENT, so these two groups
     * are left silent on purpose and the reason is pinned here rather than only in a comment.
     *
     *   `currency_space` — `app/Helper/helper.php:1740`, `:1741`, `:2435` and `:2436` read
     *   `((isset($currency_space) && $currency_space) == 'withspace' ? ' ' : '')`. The
     *   parenthesisation makes the left operand a BOOLEAN, so the comparison is `true ==
     *   'withspace'`, which PHP 8 settles by casting the string to bool — true. Measured: the
     *   stored value `withoutspace` yields TRUE and a space is printed. Only an UNSET value
     *   yields no space. So "Without space" does nothing once anything has been saved.
     *
     *   `storage_setting` — `app/Models/Utility.php:393` selects the driver with
     *   `Setting::where('created_by', 1)`, the platform operator's own rows, with no store
     *   predicate. A merchant's choice is stored under their own id and never read by the
     *   uploader, so the control has no effect for them.
     *
     * Neither file belongs to this workstream, and neither defect is a labelling problem, so both
     * are reported rather than patched here. IF ONE IS FIXED: write the instruction, move the
     * group into INSTRUCTED, and delete its entry below.
     */
    public function test_the_two_groups_whose_behaviour_is_broken_carry_no_instruction(): void
    {
        foreach (['currency_space' => 'currency_setting', 'storage_setting' => 'storage_setting'] as $name => $tab) {
            $html = $this->panel($tab);
            $fieldset = $this->fieldsetFor($html, $name);

            $this->assertDoesNotMatchRegularExpression('/<fieldset[^>]*aria-describedby=/', $fieldset, sprintf(
                "the '%s' group has been given an instruction. That is only correct if the defect "
                . 'recorded in this test\'s docblock has been fixed — if it has, update this test '
                . 'and move the group into INSTRUCTED; if it has not, the instruction describes '
                . 'behaviour that does not happen.',
                $name
            ));
        }
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Both language directions
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * THE INSTRUCTION IS DECLARED IN ARABIC, asserted through the principal's own `language`
     * column because `SetLocate` re-resolves the locale inside the request and
     * `app()->setLocale()` in a test does not survive it. An undeclared key renders as its English
     * self on the Arabic page, which is the failure this assertion exists for.
     */
    public function test_the_instructions_render_in_arabic(): void
    {
        foreach (self::INSTRUCTED as $name => $tab) {
            $html = $this->panel($tab, 'ar');
            $fieldset = $this->fieldsetFor($html, $name);

            preg_match('/<fieldset[^>]*aria-describedby="([^"]+)"/', $fieldset, $m);
            $text = (string) $this->textOfId($html, $m[1] ?? '');

            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $text, sprintf(
                "the '%s' group's instruction rendered with no Arabic in it for an Arabic "
                . 'merchant: "%s"',
                $name, mb_strimwidth($text, 0, 80, '...')
            ));
        }
    }

    /** The English control for the test above: the same instructions, in English. */
    public function test_the_instructions_render_in_english(): void
    {
        foreach (self::INSTRUCTED as $name => $tab) {
            $html = $this->panel($tab, 'en');
            $fieldset = $this->fieldsetFor($html, $name);

            preg_match('/<fieldset[^>]*aria-describedby="([^"]+)"/', $fieldset, $m);
            $text = (string) $this->textOfId($html, $m[1] ?? '');

            $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $text,
                "the '{$name}' group's instruction rendered Arabic for an English merchant");
            $this->assertGreaterThan(30, mb_strlen($text));
        }
    }
}
