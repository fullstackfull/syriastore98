<?php

namespace Tests\Unit\Ui;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * ANA-002 — the period-over-period line on `<x-ss.stat>`, and the two ways it could mislead.
 * =================================================================================================
 *
 * The arithmetic is `MerchantAnalyticsFigures::comparison()`'s and is tested against real rows in
 * `AnalyticsComparisonTest`. This file is about the RENDERING, where a comparison can go wrong in
 * two specific ways that a passing arithmetic test would not notice:
 *
 *  1. IT COULD PRINT A PERCENTAGE THE METRIC REFUSED TO STATE. The figures layer returns
 *     `percent => null` when the earlier period was empty, because there is nothing to divide by.
 *     A view that printed "0%", or an empty pair of brackets, or reconstructed a figure from the
 *     magnitude, would put back exactly the false claim the refusal exists to prevent.
 *  2. IT COULD CARRY THE DIRECTION IN COLOUR ALONE. A green arrow up and a red arrow down is the
 *     obvious design and it fails WCAG 1.4.1: colour cannot be the only carrier of meaning. So the
 *     direction is a WORD, the arrow is `aria-hidden`, and the stylesheet is asserted not to have
 *     grown a hue for this class.
 *
 * Both are asserted against the REAL Blade compiler and the REAL stylesheet rather than against a
 * description of them.
 */
final class StatComparisonIsVisibleTest extends TestCase
{
    private function render(array $comparison): string
    {
        return Blade::render(
            '<x-ss.stat :label="$l" :value="$v" :period="$p" :comparison="$c" />',
            [
                'l' => 'Orders',
                'v' => '3',
                'p' => 'last 30 days',
                'c' => $comparison,
            ]
        );
    }

    private function upByOne(array $overrides = []): array
    {
        return array_merge([
            'direction' => 'up',
            'change' => '1',
            'baseline' => '2',
            'period' => 'the 30 days before that',
            'percent' => '50.0%',
            'note' => null,
        ], $overrides);
    }

    public function test_premise_a_tile_with_no_comparison_renders_no_comparison_element(): void
    {
        $html = Blade::render(
            '<x-ss.stat :label="$l" :value="$v" :period="$p" />',
            ['l' => 'Orders', 'v' => '3', 'p' => 'last 30 days']
        );

        $this->assertStringNotContainsString('ss-stat__comparison', $html,
            'A tile given no comparison must not emit an empty comparison element: most tiles on '
            . 'this product pass none, and an empty line would read as a missing figure.');
        $this->assertStringNotContainsString('ss-stat__trend', $html);
    }

    public function test_the_comparison_renders_as_its_own_element_with_the_design_system_class(): void
    {
        $html = $this->render($this->upByOne());

        $this->assertMatchesRegularExpression(
            '/<p[^>]*class="ss-stat__comparison"[^>]*>/',
            $html,
            'The comparison should render as its own <p class="ss-stat__comparison">, beside the '
            . 'period and the hint rather than inside either.'
        );
    }

    public function test_each_direction_is_stated_in_words(): void
    {
        $cases = [
            'up' => 'Up 1 from 2 in the 30 days before that',
            'down' => 'Down 1 from 2 in the 30 days before that',
            'same' => 'No change from 2 in the 30 days before that',
        ];

        foreach ($cases as $direction => $sentence) {
            $html = $this->render($this->upByOne(['direction' => $direction]));

            $this->assertStringContainsString($sentence, $html, sprintf(
                'The %s direction does not read as words. WCAG 1.4.1 forbids colour (or an arrow '
                . 'glyph) being the only carrier of the direction, so the sentence IS the '
                . 'statement and the arrow is decoration.',
                $direction
            ));
        }
    }

    public function test_the_arrow_is_decorative_and_hidden_from_assistive_technology(): void
    {
        $html = $this->render($this->upByOne());

        $this->assertMatchesRegularExpression(
            '/<span[^>]*aria-hidden="true"[^>]*class="ss-stat__trend"'
            . '|<span[^>]*class="ss-stat__trend"[^>]*aria-hidden="true"/',
            $html,
            'The trend arrow must be aria-hidden. Announced, it adds a character a screen reader '
            . 'reads as punctuation or skips, beside a sentence that already says the direction.'
        );
    }

    public function test_a_computable_percentage_is_shown_in_brackets(): void
    {
        $html = $this->render($this->upByOne());

        $this->assertStringContainsString('(50.0%)', $html);
    }

    /**
     * THE ONE THAT MATTERS. A withheld percentage must not come back as brackets, as a zero, or as
     * anything else the view invented.
     */
    public function test_a_withheld_percentage_is_not_printed_and_its_reason_is(): void
    {
        $html = $this->render($this->upByOne([
            'baseline' => '0',
            'change' => '2',
            'percent' => null,
            'note' => 'There is no figure for the earlier period to compare against, so no percentage is shown.',
        ]));

        $this->assertStringNotContainsString('()', $html,
            'An empty pair of brackets was rendered where the percentage would have been.');
        $this->assertStringNotContainsString('%', $html,
            'A percent sign reached the screen for a comparison whose percentage the metric layer '
            . 'refused to compute. There was nothing to divide by, and any figure here is false.');

        $this->assertStringContainsString('no figure for the earlier period', $html,
            'The percentage was withheld with no reason shown, so the tile has a silence where an '
            . 'explanation belongs.');
        $this->assertStringContainsString('Up 2 from 0', $html,
            'The ABSOLUTE change is still true and must still be stated.');
    }

    /**
     * THE STYLESHEET MUST NOT HAVE GROWN A HUE FOR THIS, because the moment it does, the direction
     * has a second carrier that the words no longer have to agree with -- and a green/red pair
     * needs its own 4.5:1 proof in light AND dark, AR and EN.
     */
    public function test_the_comparison_reuses_the_proven_muted_token_and_adds_no_colour(): void
    {
        $css = (string) file_get_contents(base_path('public/css/store-syria-ds.css'));

        $start = strpos($css, '.ss-stat__comparison {');
        $this->assertNotFalse($start, 'the comparison rule is gone from the design system.');

        $rule = substr($css, $start, (int) strpos($css, '}', $start) - $start);

        $this->assertStringContainsString('color: var(--ss-fg-muted)', $rule,
            'The comparison line must reuse --ss-fg-muted, the same token .ss-stat__period and '
            . '.ss-stat__hint use and which the design-system contrast gate already holds to '
            . '4.5:1 in both themes.');

        foreach (['green', 'red', '#0', '#1', '#2', '#3', '#4', '#5', '#6', '#7', '#8', '#9',
                  '#a', '#b', '#c', '#d', '#e', '#f', 'rgb', 'hsl'] as $literal) {
            $this->assertStringNotContainsString($literal, strtolower($rule), sprintf(
                'The comparison rule contains the colour literal "%s". A hue here needs its own '
                . 'contrast proof in four combinations, and it would make colour a second carrier '
                . 'of the direction -- which WCAG 1.4.1 forbids as the only one and which this '
                . 'component deliberately expresses in words instead.',
                $literal
            ));
        }
    }

    /** And the trend glyph carries no colour of its own either. */
    public function test_the_trend_glyph_has_no_colour_of_its_own(): void
    {
        $css = (string) file_get_contents(base_path('public/css/store-syria-ds.css'));

        $start = strpos($css, '.ss-stat__trend {');
        $this->assertNotFalse($start, 'the trend rule is gone from the design system.');

        $rule = substr($css, $start, (int) strpos($css, '}', $start) - $start);

        $this->assertStringNotContainsString('color:', $rule,
            'The arrow has been given a colour. It is decorative and aria-hidden; colouring it '
            . 'makes it look like the carrier of the direction it is not allowed to be.');
    }

    /**
     * BILINGUAL. All three direction sentences and the baseline period are translated, and an
     * Arabic merchant must not be shown the English template.
     */
    public function test_all_three_direction_sentences_are_translated_into_arabic(): void
    {
        $en = json_decode((string) file_get_contents(base_path('resources/lang/en.json')), true);
        $ar = json_decode((string) file_get_contents(base_path('resources/lang/ar.json')), true);

        $keys = [
            'Up :change from :baseline in :period',
            'Down :change from :baseline in :period',
            'No change from :baseline in :period',
            'the :count days before that',
            'There is no figure for the earlier period to compare against, so no percentage is shown.',
        ];

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $en, "{$key} is missing from en.json.");
            $this->assertArrayHasKey($key, $ar, "{$key} is missing from ar.json, so an Arabic "
                . 'merchant is shown the English key.');

            $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $ar[$key],
                "ar.json's value for {$key} contains no Arabic.");

            foreach ([':change', ':baseline', ':period', ':count'] as $placeholder) {
                if (str_contains($key, $placeholder)) {
                    $this->assertStringContainsString($placeholder, $ar[$key], sprintf(
                        'The Arabic for "%s" dropped the %s placeholder, so the number it stands '
                        . 'for never reaches the sentence.',
                        $key,
                        $placeholder
                    ));
                }
            }
        }
    }
}
