<?php

namespace Tests\Unit\Ui;

use App\Support\Metrics\CaveatedFigure;
use App\Support\Metrics\MetricCaveat;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\ViewException;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * GAP-152 / P05-04 — `<x-ss.figure>`: THE RENDERER WITH NO `hint` PROP.
 * =================================================================================================
 *
 * `<x-ss.stat>` takes `value`, `period` and `hint` as three independent strings. That is what let
 * five surfaces render a margin figure and none of them render
 * `MetricCaveat::EXCLUDES_PAYMENT_FEES` — attached unconditionally by `StoreRevenueMargin` (ANA-006:
 * nothing in this product records a gateway fee, so every card-taking store's margin is overstated
 * by the gateway's cut), translated into Arabic, ratcheted by
 * `MetricCaveatMessagesAreTranslatedTest`, and present in no view. Measured 2026-11-25: one hit in
 * `resources/views/` and `app/`, and it was a docblock.
 *
 * This component has no `hint` prop at all. The value, the period and the sentences come off one
 * `CaveatedFigure` whose `hint()` is computed from its caveat list, so "render the figure and leave
 * the disclosure out" is not expressible. The tests below drive the real Blade compiler, because
 * the only thing worth asserting is the bytes a merchant's browser receives.
 */
final class CaveatedFigureRendersItsCaveatsTest extends TestCase
{
    private function render(CaveatedFigure $figure, string $label = 'Estimated gross profit'): string
    {
        return Blade::render(
            '<x-ss.figure :label="$label" :figure="$figure" />',
            ['label' => $label, 'figure' => $figure]
        );
    }

    private function margin(array $statedAs = []): CaveatedFigure
    {
        return CaveatedFigure::of(
            '60.00 USD',
            'from 2 November 2026',
            [MetricCaveat::EXCLUDES_PAYMENT_FEES, MetricCaveat::COST_UNKNOWN],
            ['Sales value minus product cost for orders that have been paid.'],
            $statedAs,
        );
    }

    public function test_the_premise_that_the_component_renders_the_figure_at_all(): void
    {
        $html = $this->render($this->margin());

        // Without this every "contains the caveat" assertion below could pass on a blank string.
        $this->assertStringContainsString('ss-stat__value', $html);
        $this->assertStringContainsString('60.00 USD', $html);
        $this->assertStringContainsString('from 2 November 2026', $html);
    }

    public function test_every_caveat_the_figure_carries_is_rendered_in_the_visible_hint(): void
    {
        $html = $this->render($this->margin());

        $this->assertStringContainsString('class="ss-stat__hint"', $html,
            'the sentences must land in the VISIBLE hint slot, not a clipped one (P5-11)');

        foreach ([MetricCaveat::EXCLUDES_PAYMENT_FEES, MetricCaveat::COST_UNKNOWN] as $caveat) {
            $this->assertStringContainsString(
                htmlspecialchars(MetricCaveat::message($caveat), ENT_QUOTES),
                $html,
                $caveat . ' is carried by the figure and absent from the rendered tile'
            );
        }
    }

    public function test_the_hint_is_associated_with_the_value_for_a_screen_reader(): void
    {
        // Delegated to `<x-ss.stat>` rather than re-implemented, so the audited `aria-describedby`
        // wiring (one id per instance) is the one in use.
        $html = $this->render($this->margin());

        $this->assertMatchesRegularExpression('/aria-describedby="(ss-stat-hint-[^"]+)"/', $html);

        preg_match('/aria-describedby="(ss-stat-hint-[^"]+)"/', $html, $m);

        $this->assertStringContainsString('id="' . $m[1] . '"', $html,
            'the hint the value points at does not exist on the page');
    }

    public function test_a_surface_cannot_render_the_figure_without_its_caveats(): void
    {
        /*
         * THE STRUCTURAL ASSERTION. There is no prop to pass a hint through and none to suppress
         * one, so this reads the component's own `@props` list: a `hint` there would mean a screen
         * could override the derived sentences, which is the per-view mechanism again.
         */
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/resources/views/components/ss/figure.blade.php');

        // The Blade comment block explains the absence and names `hint` several times, so a census
        // over the raw file would pass on prose about the thing it measures.
        $declaration = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);

        $this->assertMatchesRegularExpression('/@props\(\s*\[(.*?)\]\s*\)/s', $declaration);

        preg_match('/@props\(\s*\[(.*?)\]\s*\)/s', $declaration, $props);

        $this->assertStringContainsString("'figure'", $props[1]);
        $this->assertStringNotContainsString("'hint'", $props[1],
            'a hint prop would let a screen replace the sentences the metric attached');
    }

    public function test_an_array_shaped_figure_is_refused_rather_than_rendered(): void
    {
        /*
         * The array form is what the figures classes used to return, and it is the shape whose
         * `hint` somebody composes by hand. Rendering it would be a figure whose caveats nobody
         * can check, which is the defect; refusing it is loud and happens in development.
         */
        try {
            Blade::render(
                '<x-ss.figure :label="$label" :figure="$figure" />',
                ['label' => 'Payments received', 'figure' => ['value' => '1.00', 'period' => 'all time', 'hint' => 'anything']]
            );

            $this->fail('an array-shaped figure rendered, so a hand-composed hint can still reach a tile');
        } catch (ViewException $e) {
            // Blade wraps anything a view throws, so the refusal is asserted through the wrapper
            // and its cause rather than by type alone -- a `ViewException` on its own would also
            // match a typo in the template.
            // Blade wraps per nested view, so the chain can be more than one deep: the component
            // renders `<x-ss.stat>` inside itself, and the refusal is thrown before that.
            $root = $e;

            while ($root->getPrevious() !== null) {
                $root = $root->getPrevious();
            }

            $this->assertInstanceOf(InvalidArgumentException::class, $root);
            $this->assertStringContainsString('CaveatedFigure', $e->getMessage());
            $this->assertStringContainsString('Received array', $e->getMessage());
        }
    }

    public function test_a_disclosure_for_several_figures_is_refused_rather_than_shown_as_a_tile(): void
    {
        /*
         * `CaveatedFigure::forFigures()` carries the caveats of a whole figure SET and has no value
         * of its own -- `StoreInventoryValuation`'s seven. Rendering it as a tile would print
         * "Not available" beside four figures the same screen is showing.
         */
        $set = CaveatedFigure::forFigures('right now', [MetricCaveat::MONEY_FLOAT_PRECISION]);

        try {
            $this->render($set, 'Inventory');

            $this->fail('a figure-set disclosure rendered as a tile, where its null value reads as "we cannot say"');
        } catch (ViewException $e) {
            $root = $e;

            while ($root->getPrevious() !== null) {
                $root = $root->getPrevious();
            }

            $this->assertInstanceOf(InvalidArgumentException::class, $root);
            $this->assertStringContainsString('several', $e->getMessage());
        }
    }

    public function test_a_caveat_the_surface_answers_itself_renders_those_words_and_not_the_generic_ones(): void
    {
        $own = 'At least 2 paid orders have no product cost on record.';

        $html = $this->render($this->margin([MetricCaveat::COST_UNKNOWN => $own]));

        $this->assertStringContainsString(htmlspecialchars($own, ENT_QUOTES), $html);
        $this->assertStringNotContainsString(
            htmlspecialchars(MetricCaveat::message(MetricCaveat::COST_UNKNOWN), ENT_QUOTES),
            $html,
            'the §8 wording names orders and rows; a surface that says it better must not say both'
        );

        // ... and the caveat it did NOT answer is still there in the vocabulary's own words.
        $this->assertStringContainsString(
            htmlspecialchars(MetricCaveat::message(MetricCaveat::EXCLUDES_PAYMENT_FEES), ENT_QUOTES),
            $html
        );
    }

    public function test_the_caveats_render_in_arabic_on_an_arabic_page(): void
    {
        $english = MetricCaveat::message(MetricCaveat::EXCLUDES_PAYMENT_FEES);

        $this->app->setLocale('ar');

        $arabic = MetricCaveat::message(MetricCaveat::EXCLUDES_PAYMENT_FEES);

        // Resolved through the translator: a key in en.json and missing from ar.json falls back to
        // English, and no source census can see that.
        $this->assertNotSame($english, $arabic, 'this caveat has no Arabic, so the assertion below proves nothing');

        $html = $this->render($this->margin());

        $this->assertStringContainsString(htmlspecialchars($arabic, ENT_QUOTES), $html);
        $this->assertStringNotContainsString($english, $html);
    }

    public function test_a_figure_with_no_value_renders_the_honest_unavailable_state_with_its_reason(): void
    {
        $figure = CaveatedFigure::of(null, 'all time', [MetricCaveat::MIXED_CURRENCY]);

        $html = $this->render($figure, 'Payments received');

        $this->assertStringContainsString('ss-stat--unavailable', $html, 'a null figure must not look like a zero');
        $this->assertStringContainsString(
            htmlspecialchars(MetricCaveat::message(MetricCaveat::MIXED_CURRENCY), ENT_QUOTES),
            $html
        );
    }
}
