<?php

namespace Tests\Unit\Reports;

use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * WHICH FIVE SURVIVE A TIE ON THE "TOP 5" CHARTS. AUD-4, found by the PR #140 deletion audit.
 * =================================================================================================
 *
 * `ReportController::top_product()` cannot run under this suite -- its category and brand queries
 * use `SUBSTRING_INDEX`, and the file uses `MONTH()` and `YEAR()`, none of which exist in SQLite.
 * The real evidence is `tests/Support/Security/bin/verify-top-five-reports-on-mysql.php`, section E,
 * which drives the handler on a real server. THIS file is the half that CAN live in the suite: the
 * Collection arithmetic of the two PHP-ranked charts, run on this repository's own vendored
 * `Illuminate\Support\Collection`, plus a census that the controller still carries the exact
 * expressions measured here.
 *
 * THE DEFECT. The payment-method chart was ranked with
 *
 *     ->sortByDesc(fn ($group) => [$group['orders'], $group['label']])->take(5)
 *
 * and `sortByDesc` compares an ARRAY key element by element, BOTH descending. So on a tie in the
 * order count the label was compared descending too: seven methods of one order each came back as
 * g, f, e, d, c, and `a` and `b` -- the two a merchant would expect first -- were dropped without
 * any signal. The two SQL charts beside it break the same tie by name ASCENDING, and the comment
 * above the line said "the same deterministic tie-break". It was deterministic and it was the
 * opposite one.
 *
 * The product chart (`countBy()->sortDesc()`) kept FIRST-APPEARANCE order on a tie, which follows
 * the database's row order for a query with no `ORDER BY`. Same failure, different mechanism, and
 * this file pins both because "the fifth place appears and disappears between loads" is a defect
 * a merchant reads as the chart being unreliable.
 *
 * THE LABEL COMPARISON IS CASE-FOLDED. The first replacement compared the label bytewise, which puts
 * every capital letter before every lower-case one -- "Stripe" ahead of "cod" -- where the SQL charts'
 * collation is case-insensitive. Found by independent review of that replacement and pinned by
 * `test_the_label_tie_break_ignores_case_as_the_sql_charts_do`. What remains is a NON-LATIN name, which
 * the database orders by collation weight and PHP by code point; this file does not claim to close it.
 *
 * THE CENSUS HALF IS A TEXT-PINNED COPY, not a second implementation. The comparator below is
 * written out once here, and `test_the_controller_carries_exactly_the_expressions_measured_here`
 * asserts that the controller's code (comments stripped, whitespace removed) contains the same
 * text. Edit one and not the other and that test fails.
 */
class TopFiveTieBreakTest extends TestCase
{
    /** The shipped comparator. Orders descending, then the label ascending AND CASE-FOLDED. */
    private static function shippedRanking(Collection $groups): Collection
    {
        return $groups
            ->sort(fn ($a, $b) => [$b['orders'], mb_strtolower($a['label'])] <=> [$a['orders'], mb_strtolower($b['label'])])
            ->take(5);
    }

    /** @return Collection<string, array{label: string, orders: int}> */
    private static function makeGroups(array $ordersByLabel): Collection
    {
        $groups = collect();
        foreach ($ordersByLabel as $label => $orders) {
            $groups->put((string) $label, ['label' => (string) $label, 'orders' => $orders]);
        }

        return $groups;
    }

    private function controllerCode(): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/app/Http/Controllers/ReportController.php');
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return preg_replace('/\s+/', '', $code);
    }

    public function test_premise_the_replaced_expression_really_dropped_the_two_alphabetically_first(): void
    {
        // MEASURED, not assumed: the OLD expression on this repository's Collection. If a framework
        // upgrade ever changed `sortByDesc` on array keys, this fails and the claim in the
        // controller's comment is stale in the other direction.
        $old = self::makeGroups(['a' => 1, 'b' => 1, 'c' => 1, 'd' => 1, 'e' => 1, 'f' => 1, 'g' => 1])
            ->sortByDesc(fn ($group) => [$group['orders'], $group['label']])
            ->take(5);

        $this->assertSame(['g', 'f', 'e', 'd', 'c'], $old->keys()->all());
    }

    public function test_seven_methods_tied_at_one_order_keep_the_alphabetically_first_five(): void
    {
        $ranked = self::shippedRanking(
            self::makeGroups(['g' => 1, 'f' => 1, 'e' => 1, 'd' => 1, 'c' => 1, 'b' => 1, 'a' => 1])
        );

        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $ranked->keys()->all());
    }

    public function test_order_count_still_ranks_before_the_label(): void
    {
        $ranked = self::shippedRanking(self::makeGroups(['z' => 3, 'a' => 1, 'm' => 3, 'b' => 2, 'q' => 3]));

        // 3,3,3 by label ascending, then the 2, then the 1. A tie-break that outranked the count
        // would put 'a' first and fail here.
        $this->assertSame(['m', 'q', 'z', 'b', 'a'], $ranked->keys()->all());
    }

    public function test_the_label_tie_break_ignores_case_as_the_sql_charts_do(): void
    {
        /*
         * THE CASE THAT DISCRIMINATES. Found by independent review of this change: the first version
         * compared the label bytewise, and PHP's comparison puts every capital letter before every
         * lower-case one, while the two SQL charts beside it break the same tie under a
         * case-insensitive collation. Seven methods of one order each:
         *
         *     bytewise   B, D, F, a, c      <- keeps F, drops e and g
         *     folded     a, B, c, D, e      <- what the SQL charts would show
         *
         * With every label the same case the two readings AGREE, so the seven-letter test above could
         * not have told them apart.
         */
        $groups = self::makeGroups(['a' => 1, 'B' => 1, 'c' => 1, 'D' => 1, 'e' => 1, 'F' => 1, 'g' => 1]);

        $bytewise = $groups
            ->sort(fn ($a, $b) => [$b['orders'], $a['label']] <=> [$a['orders'], $b['label']])
            ->take(5);
        $this->assertSame(['B', 'D', 'F', 'a', 'c'], $bytewise->keys()->all(),
            'premise: a bytewise label comparison puts capitals first');

        $this->assertSame(['a', 'B', 'c', 'D', 'e'], self::shippedRanking($groups)->keys()->all());
    }

    public function test_the_product_chart_breaks_a_tie_by_id_not_by_arrival_order(): void
    {
        // Ids as they appear across `orders.product_id` rows, in the order the rows came back.
        // 9, 5 and 7 each appear twice; 8 and 3 once.
        $arrivals = collect([9, 5, 7, 8, 5, 7, 9, 3]);

        // THE OLD CHAIN keeps arrival order among equals: 9, 5, 7 then 8, 3.
        $this->assertSame(
            [9, 5, 7, 8, 3],
            $arrivals->countBy()->sortDesc()->take(5)->keys()->all(),
            'premise: without sortKeys() the survivors follow arrival order'
        );

        // THE SHIPPED CHAIN breaks ties by id ascending: 5, 7, 9 then 3, 8.
        $this->assertSame(
            [5, 7, 9, 3, 8],
            $arrivals->countBy()->sortKeys()->sortDesc()->take(5)->keys()->all()
        );
    }

    public function test_the_controller_carries_exactly_the_expressions_measured_here(): void
    {
        $code = $this->controllerCode();

        $comparator = preg_replace('/\s+/', '',
            "->sort(fn (\$a, \$b) => [\$b['orders'], mb_strtolower(\$a['label'])] <=> [\$a['orders'], mb_strtolower(\$b['label'])])->take(5)"
        );
        $this->assertSame(1, substr_count($code, $comparator),
            'the payment-method chart no longer carries the comparator this file measures');

        $this->assertStringNotContainsString(
            preg_replace('/\s+/', '', "->sortByDesc(fn (\$group) => [\$group['orders'], \$group['label']])"),
            $code,
            'the reverse-alphabet tie-break is back on the payment-method chart'
        );

        $this->assertSame(1, substr_count($code, '->countBy()->sortKeys()->sortDesc()->take(5);'),
            'the product chart no longer breaks a tie by id');
    }
}
