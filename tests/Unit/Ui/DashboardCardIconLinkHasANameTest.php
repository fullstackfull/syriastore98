<?php

namespace Tests\Unit\Ui;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * THE OPERATOR DASHBOARD'S SIX STAT-CARD LINKS HAD NO ACCESSIBLE NAME. P06-05 / P06-06, area D.
 * =================================================================================================
 *
 * MEASURED, not read. `audit-merchant-screens.cjs` over `/dashboard` as `operator@example.test`
 * reported `6 nameless` in **all four** AR/EN x light/dark combinations, and every one of the six is
 * `<a class="card-icon fs-4"> 45x45`. They come from one shared component,
 * `resources/views/components/dashboard-card.blade.php`, whose anchor contains `{!! $icon !!}` --
 * an `<i class="ti ti-...">` glyph that contributes no text -- so a screen reader announces six
 * links in a row with nothing to distinguish them, on the first screen the platform operator sees.
 *
 * THE NAME WAS ALREADY IN THE COMPONENT. The card renders `$label` as its own `<h2>` immediately
 * beside the link, so nothing had to be invented or translated: the anchor now carries that same
 * label. An `aria-label` adds no pixels, which matters because this surface has never been through
 * owner visual acceptance and a layout change would need one.
 *
 * THE COMPONENT IS THE OPERATOR'S ALONE, which is why this is area D and not a merchant change.
 * `grep -rn 'x-dashboard-card' resources/views/` finds six uses, all in
 * `resources/views/superadmin/dashboard.blade.php`; the merchant dashboard's own comment records
 * that P2-02 replaced its `<x-dashboard-card>` tiles.
 *
 * WHY THE SOURCE ASSERTION BELOW IS NOT PADDING. `$label` is read as `$label ?? ''`, so a caller
 * that passes no label gets an empty `aria-label` and the link is nameless again. Rather than
 * invent a fallback word, this test pins the thing that is actually true of the live surface: all
 * six operator cards pass a label. A seventh card added without one turns this red.
 */
class DashboardCardIconLinkHasANameTest extends TestCase
{
    private function render(string $label): string
    {
        return Blade::render(
            '<x-dashboard-card :count="$c" :label="$l" :icon="$i" :url="$u" />',
            ['c' => 7, 'l' => $label, 'i' => '<i class="ti ti-building-store"></i>', 'u' => '/stores']
        );
    }

    public function test_the_icon_link_carries_the_cards_own_label_as_its_accessible_name(): void
    {
        $html = $this->render('Total Store');

        $this->assertStringContainsString('class="card-icon fs-4"', $html,
            'PREMISE: the component no longer renders the icon anchor this test names. Re-point it '
            . 'rather than deleting it.');

        $this->assertMatchesRegularExpression(
            '/<a\b[^>]*aria-label="Total Store"[^>]*class="card-icon fs-4"|<a\b[^>]*class="card-icon fs-4"[^>]*aria-label="Total Store"/',
            $html,
            'The dashboard card\'s icon link has no accessible name. It contains only an icon glyph, '
            . 'so a screen reader announces a link with nothing in it -- six times over on the '
            . 'operator dashboard, which is what the four-combination audit measured.'
        );
    }

    public function test_a_label_with_markup_in_it_is_escaped_into_the_attribute(): void
    {
        $html = $this->render('Plans & "Requests" <b>');

        $this->assertStringNotContainsString('aria-label="Plans & "Requests"', $html,
            'The label is interpolated into the attribute without escaping, so a label containing a '
            . 'quote breaks out of it. Blade\'s {{ }} must do the escaping.');

        $this->assertStringContainsString('aria-label="Plans &amp; &quot;Requests&quot; &lt;b&gt;"', $html,
            'The label is not escaped the way Blade escapes it, so either the directive changed or '
            . 'the attribute is being built by hand.');
    }

    public function test_every_operator_dashboard_card_passes_a_label(): void
    {
        $view = (string) preg_replace(
            '/\{\{--.*?--\}\}/s',
            '',
            (string) file_get_contents(base_path('resources/views/superadmin/dashboard.blade.php'))
        );

        preg_match_all('/<x-dashboard-card\b[^>]*>/s', $view, $cards);

        $this->assertCount(6, $cards[0], sprintf(
            'PREMISE: the operator dashboard renders %d dashboard-cards, not the six this test was '
            . 'written against. Re-count rather than relaxing the assertion.',
            count($cards[0])
        ));

        foreach ($cards[0] as $card) {
            $this->assertMatchesRegularExpression('/:?label=/', $card,
                "An operator dashboard card passes no label, so its icon link's aria-label is empty "
                . "and the link is nameless again: {$card}");
        }
    }
}
