<?php

namespace Tests\Feature\Merchant;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-06-12 / WS I4 + I5 — task-oriented instructions where a merchant must CHOOSE.
 * =================================================================================================
 *
 * THIS IS NOT P2-06-10 AGAIN. That row attached help to a single FIELD via `aria-describedby`, and
 * for one input that is the whole association. A GROUP of choices is a different ARIA problem: the
 * thing being described is the set, not any one radio, and a description hung off one option is
 * announced only when the reader reaches that option.
 *
 * THREE DEFECTS WERE MEASURED ON THE PRODUCT EDITOR, and this row fixes them together:
 *
 *   1. NEITHER CHOICE GROUP IS A GROUP. "Stock Status:" and "Allow BackOrders:" are rendered as
 *      `Form::label('', ...)` -- a label with an EMPTY `for` -- above a bare `<div>` of radios. So
 *      a screen reader announces "In Stock, radio button 1 of 3" with nothing to say what is being
 *      chosen. The group name exists visually and not programmatically.
 *
 *   2. `create.blade.php:224` CARRIES `for="   "` -- three spaces. The "In Stock" label is
 *      therefore associated with no control at all: clicking it selects nothing, and no reader
 *      ties the two. It is the kind of defect that survives review because it looks like an
 *      attribute that is present.
 *
 *   3. THERE IS NO INSTRUCTION ON EITHER GROUP, and "Allow BackOrders" is the most consequential
 *      choice on the screen: it decides whether the store accepts money for stock it does not
 *      have. Three options whose difference is not explained anywhere.
 *
 * SO THE ACCEPTANCE'S TWO HALVES MAP ONTO: a `<fieldset>`/`<legend>` so the group's name reaches
 * the accessibility tree, and an instruction bound to the GROUP with `aria-describedby`. The
 * browser check (`check-choice-guidance-live.cjs`) reads the computed accessibility tree rather
 * than the markup, because markup that looks associated and is not is precisely defect 1.
 */
class ChoiceGroupGuidanceTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The two groups, by the radio `name` they own. */
    private const GROUPS = ['stock_status', 'stock_order_status'];

    private function enableStockManagement(int $storeId): void
    {
        \App\Models\Setting::updateOrCreate(
            ['name' => 'stock_management', 'created_by' => 1, 'store_id' => $storeId],
            ['value' => 'on']
        );
        Cache::flush();
    }

    private function editorHtml(string $path = '/product/create'): string
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Create Product');
        $this->grantPermission($merchant, 'Manage Products');
        $this->grantPermission($merchant, 'Edit Products');
        $this->enableStockManagement($store->id);

        if ($path === 'edit') {
            $id = DB::table('products')->insertGetId([
                'name' => 'Guidance Fixture', 'slug' => 'guidance-fixture-' . uniqid(),
                'description' => 'x', 'price' => 10, 'product_stock' => 5,
                'stock_status' => 'in_stock', 'stock_order_status' => 'not_allow',
                'track_stock' => 1, 'trending' => 0, 'status' => 1, 'variant_product' => 0,
                'store_id' => $store->id, 'created_by' => $merchant->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $path = '/product/' . $id . '/edit';
        }

        $response = $this->actingAs($merchant)->get($path);

        $this->assertSame(200, $response->getStatusCode(),
            $path . ' must render, or every assertion below is vacuous');

        $html = (string) $response->getContent();

        /* PREMISE. Both groups live inside `@if ($stock_management == 'on')` or its sibling
           block, so without the setting the page simply has no groups and every assertion below
           would be measuring a feature flag -- the lesson P2-06-10's own test file records. */
        foreach (self::GROUPS as $name) {
            $this->assertStringContainsString('name="' . $name . '"', $html,
                'premise: the ' . $name . ' choice group is on the page');
        }

        return $html;
    }

    /** @return array<string, string[]> */
    public static function editorPaths(): array
    {
        return ['create' => ['/product/create'], 'edit' => ['edit']];
    }

    /**
     * EACH GROUP IS A REAL GROUP, with a legend that names it.
     *
     * @dataProvider editorPaths
     */
    public function test_each_choice_group_is_a_fieldset_with_a_legend(string $path): void
    {
        $html = $this->editorHtml($path);

        foreach (self::GROUPS as $name) {
            $this->assertMatchesRegularExpression(
                '~<fieldset[^>]*data-ss-choicegroup="' . preg_quote($name, '~') . '"~',
                $html,
                $name . ' is not a fieldset, so a screen reader announces its radios with no '
                . 'indication of what is being chosen'
            );
        }

        $this->assertStringContainsString('<legend', $html,
            'no legend is rendered, so the group names exist visually and not programmatically');
    }

    /**
     * AND THE INSTRUCTION IS BOUND TO THE GROUP, with no dangling reference either way: every
     * `aria-describedby` must point at an element that exists, and every group help element must
     * be referenced. An orphan hint is decoration; a dangling reference is worse than silence,
     * because a reader announces neither.
     *
     * @dataProvider editorPaths
     */
    public function test_every_group_instruction_is_associated_both_ways(string $path): void
    {
        $html = $this->editorHtml($path);

        foreach (self::GROUPS as $name) {
            $id = $name . '-group-help';

            $this->assertStringContainsString('id="' . $id . '"', $html,
                $name . ' has no instruction element');
            $this->assertStringContainsString('aria-describedby="' . $id . '"', $html,
                $name . '\'s instruction is on the page but nothing references it, so it is '
                . 'decoration rather than guidance');
        }

        // No dangling reference anywhere on the page.
        preg_match_all('~aria-describedby="([^"]+)"~', $html, $refs);
        foreach (array_unique($refs[1] ?? []) as $ref) {
            foreach (preg_split('~\s+~', trim($ref)) as $one) {
                if ($one === '') { continue; }
                $this->assertStringContainsString('id="' . $one . '"', $html,
                    'aria-describedby points at "' . $one . '", which is on no element: a '
                    . 'screen reader announces nothing for it');
            }
        }
    }

    /**
     * EVERY LABEL INSIDE A CHOICE GROUP NAMES A REAL CONTROL — scoped to the groups this row
     * owns, and the scoping is a deliberate decision with a measurement behind it.
     *
     * The first version asserted this over the WHOLE page and failed, which is how a much larger
     * defect surfaced: `Form::label('', ...)` renders `<label for="">`, and the product editor
     * uses that form **17 times on create and 21 on edit**. Every one of those labels is
     * associated with no control, so a screen reader announces those inputs unlabelled. Measured
     * by counting `for=""` in the rendered HTML, not by reading the template.
     *
     * That is NOT this row's scope — it is 38 labels across two large views, each needing an
     * `id` on its input, in an editor with JavaScript keyed on those ids — and fixing it here
     * would be an unrequested widening with real regression risk. It is recorded as its own
     * tracked ledger row instead of being dropped, which is the only honest way to narrow an
     * assertion.
     *
     * What IS asserted strictly: the labels inside the two choice groups, where this row owns the
     * markup, plus the specific `for="   "` bug (three spaces, `create.blade.php:224`) that made
     * the "In Stock" option unclickable by its label.
     *
     * @dataProvider editorPaths
     */
    public function test_no_label_in_a_choice_group_points_at_nothing(string $path): void
    {
        $html = $this->editorHtml($path);

        // The specific bug this row fixes: whitespace, which reads as a present attribute.
        $this->assertDoesNotMatchRegularExpression('~<label[^>]*\sfor="\s+"~', $html,
            'a label carries a whitespace `for`, so clicking it selects no control and no '
            . 'screen reader associates it with one');

        foreach (self::GROUPS as $name) {
            $start = strpos($html, 'data-ss-choicegroup="' . $name . '"');
            $this->assertNotFalse($start, 'premise: the ' . $name . ' group is missing');

            $end = strpos($html, '</fieldset>', $start);
            $this->assertNotFalse($end, 'premise: the ' . $name . ' fieldset is unclosed');

            $group = substr($html, $start, $end - $start);

            preg_match_all('~<label[^>]*\sfor="([^"]*)"~', $group, $labels);
            $this->assertNotEmpty($labels[1] ?? [], $name . ' has no labelled options');

            foreach ($labels[1] as $for) {
                $this->assertNotSame('', trim($for),
                    'an option label inside ' . $name . ' has an empty `for`');
                $this->assertMatchesRegularExpression('~id="' . preg_quote($for, '~') . '"~', $html,
                    'an option label inside ' . $name . ' points at "' . $for . '", which is on '
                    . 'no element');
            }
        }
    }

    /**
     * THE CONSEQUENTIAL CHOICE IS EXPLAINED. "Allow BackOrders" decides whether the store takes
     * money for stock it does not have, and the three options' difference appeared nowhere.
     *
     * @dataProvider editorPaths
     */
    public function test_the_backorder_choice_explains_its_consequence(string $path): void
    {
        $html = $this->editorHtml($path);

        $start = strpos($html, 'id="stock_order_status-group-help"');
        $this->assertNotFalse($start, 'premise: the backorder instruction is missing');

        $text = strip_tags(substr($html, $start, 400));

        $this->assertGreaterThan(40, mb_strlen(trim($text)),
            'the backorder instruction is too short to explain what the three options do');
        $this->assertMatchesRegularExpression('~order|stock|sell|buy~i', $text,
            'the backorder instruction does not describe what happens when stock runs out');
    }

    /**
     * TRANSLATED, asserted by comparing locales rather than by looking for `__()`: a missing
     * Arabic entry makes `__()` return the English key from a perfectly-written call.
     */
    public function test_the_instructions_are_translated(): void
    {
        $en = trans('Decide what happens when this product runs out of stock.', [], 'en');
        $ar = trans('Decide what happens when this product runs out of stock.', [], 'ar');

        $this->assertNotSame($en, $ar,
            'the Arabic instruction is identical to the English one, so the translation is '
            . 'missing and __() fell back to the key');
        $this->assertMatchesRegularExpression('~\p{Arabic}~u', $ar,
            'the Arabic instruction contains no Arabic script');
    }
}
