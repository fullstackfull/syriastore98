<?php

namespace Tests\Unit\Ui;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `<x-ss.bar-chart>` must not take a page down because a caller handed it a scalar.
 * =================================================================================================
 *
 * HOW THIS WAS FOUND, because it explains why a test that only drives arrays would have missed it.
 *
 * `GET order-reports-chart-data` was recorded in the Phase 2.5 coverage matrix as BLOCKED with the
 * reason "HTTP 500 on the SQLite dev fixture ... uses MONTH() and YEAR(). Production is MySQL."
 * That reason was INCOMPLETE. Run against a real MariaDB with the dev fixture seeded into it, the
 * SQL executes perfectly and the route still answered **500** -- on this component, with:
 *
 *     count(): Argument #1 ($value) must be of type Countable|array, int given
 *     (View: resources/views/components/ss/bar-chart.blade.php)
 *
 * `ReportController::order_reports_chart_data()` initialises `$PurchasedItemTotal = 0` -- a scalar
 * -- at :1004, :1076, :1136, :1221 and :1316, and passes it as `itemSeries`. Sibling methods later
 * append to it (`$PurchasedItemTotal[] = ...` at :561, :630, :684, :772), which is why it is an
 * array on those paths; on these paths nothing ever appends, so an int arrives here. In PHP 8
 * `count()` on an int is a FATAL, not a warning, so one surface's bad input 500'd the whole page.
 *
 * The component already guarded `$values2` with `is_array($values2) && count($values2) > 0`. It did
 * not guard `$labels` or `$values`. That inconsistency was the defect, and the fix makes all three
 * uniform.
 *
 * SQLITE HID IT COMPLETELY. On the SQLite fixture the route 500s on `MONTH()` before it ever
 * reaches a view, so the blocker's recorded cause masked a second, unrelated, first-party defect.
 * That is the whole argument for the owner's instruction to "prefer an isolated MySQL test
 * environment instead of assuming that passing SQLite tests proves production behavior".
 *
 * WHAT THIS TEST DOES NOT CLAIM. It does not assert the chart is CORRECT for a scalar -- a scalar
 * is not a series and there is nothing to draw. It asserts only that the component renders its
 * honest empty state instead of fatally ending the request. Fixing the controller to pass an array
 * is a separate, larger change to reporting arithmetic and is recorded, not taken here.
 */
class BarChartScalarSeriesTest extends TestCase
{
    /** Every prop shape that reached this component and should not be able to fatal. */
    public static function hostileSeries(): array
    {
        return [
            'an int, the real case'   => ['0'],
            'a non-zero int'          => ['7'],
            'a float'                 => ['1.5'],
            'a string'                => ["'not a series'"],
            'null'                    => ['null'],
            'a bool'                  => ['false'],
        ];
    }

    private function render(string $labels, string $values, string $values2 = '[]'): string
    {
        return Blade::render(
            '<x-ss.bar-chart :labels="' . $labels . '" :values="' . $values . '"'
            . ' :values2="' . $values2 . '" title="Items" period="month" />'
        );
    }

    #[Test]
    #[DataProvider('hostileSeries')]
    public function test_a_scalar_values_prop_does_not_fatal(string $scalar): void
    {
        $html = $this->render("['Jan','Feb']", $scalar);

        $this->assertIsString(
            $html,
            'the component did not return markup for a scalar $values'
        );

        $this->assertStringNotContainsString(
            'Countable',
            $html,
            'the count() TypeError is back. $values must be coerced to an array before count(), '
            . 'exactly as $values2 already was -- a shared component that fatals on one caller\'s '
            . 'bad input takes down every page that renders it.'
        );
    }

    #[Test]
    #[DataProvider('hostileSeries')]
    public function test_a_scalar_labels_prop_does_not_fatal(string $scalar): void
    {
        $html = $this->render($scalar, '[1,2]');

        $this->assertIsString($html, 'the component did not return markup for a scalar $labels');
        $this->assertStringNotContainsString('Countable', $html, 'count() on a scalar $labels');
    }

    #[Test]
    #[DataProvider('hostileSeries')]
    public function test_a_scalar_values2_prop_does_not_fatal(string $scalar): void
    {
        // $values2 was already guarded. This case exists so that a future edit which "tidies" the
        // three guards into one cannot quietly drop the one that was always there.
        $html = $this->render("['Jan','Feb']", '[1,2]', $scalar);

        $this->assertIsString($html, 'the component did not return markup for a scalar $values2');
        $this->assertStringNotContainsString('Countable', $html, 'count() on a scalar $values2');
    }

    #[Test]
    public function test_the_legitimate_path_still_draws_its_columns(): void
    {
        // A guard that swallows real data is worse than the fatal it replaced, so pin that the
        // ordinary case is unchanged: three labels, three values, all three rendered.
        $html = $this->render("['Jan','Feb','Mar']", '[10,20,30]');

        foreach (['Jan', 'Feb', 'Mar'] as $label) {
            $this->assertStringContainsString(
                $label,
                $html,
                "the chart stopped rendering the label {$label}. The scalar coercion must not "
                . 'affect a caller that passes real arrays.'
            );
        }
    }

    #[Test]
    public function test_a_scalar_renders_the_empty_state_rather_than_silently_drawing_nothing(): void
    {
        $scalarHtml = $this->render("['Jan','Feb']", '0');
        $emptyHtml = $this->render('[]', '[]');

        // Both are "no series". The component's documented behaviour for an empty $values is an
        // honest empty state, and a scalar must land in the same place rather than in a chart
        // frame with no columns in it.
        $this->assertSame(
            $this->hasEmptyState($emptyHtml),
            $this->hasEmptyState($scalarHtml),
            'a scalar series and an empty series render differently. They are the same condition '
            . '-- there is nothing to draw -- and should produce the same honest empty state.'
        );
    }

    private function hasEmptyState(string $html): bool
    {
        return (bool) preg_match('/empty|no data|لا توجد/iu', $html);
    }
}
