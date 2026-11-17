<?php

namespace Tests\Unit\Metrics;

use App\Support\Metrics\MetricCaveat;
use App\Support\Metrics\MetricResult;
use App\Support\Metrics\MetricScope;
use App\Support\Metrics\MetricWindow;
use App\Support\Metrics\MixedCurrencyException;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * P5-2 -- the rules the metric VALUE OBJECTS enforce, independent of any database.
 *
 * These are the guarantees that make a figure readable: that it names a tenant, that it names the
 * record it came from, that it never adds two currencies together, and that a caveat is a key the
 * code can branch on rather than a sentence somebody pasted.
 */
class MetricValueObjectTest extends TestCase
{
    /*
     * `Tests\TestCase` rather than the bare PHPUnit one, and only for `MetricCaveat::message()`:
     * it resolves the merchant's sentence through `__()`, which needs the container. Everything
     * else here is pure. 59 of this repository's unit tests already boot the application for the
     * same reason.
     */

    private function scope(): MetricScope
    {
        return MetricScope::store(1);
    }

    // ------------------------------------------------------------------ scope

    public function test_a_scope_with_no_store_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least one store/');

        MetricScope::stores([]);
    }

    public function test_a_store_id_that_is_not_a_positive_integer_is_refused(): void
    {
        // Zero is the shape a missing `current_store` takes when it is cast, and it would
        // otherwise compile to `store_id in (0)` -- a predicate that quietly matches nothing and
        // reports every figure as zero for a merchant whose session lost its store.
        foreach ([0, -1] as $invalid) {
            try {
                MetricScope::stores([$invalid]);
                $this->fail('a store id of ' . $invalid . ' was accepted');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('positive store ids', $e->getMessage());
            }
        }
    }

    public function test_a_store_id_that_is_not_numeric_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MetricScope::stores(['not-a-store']);
    }

    public function test_duplicate_store_ids_do_not_make_a_scope_cross_tenant(): void
    {
        $scope = MetricScope::stores([3, 3, 3]);

        $this->assertSame([3], $scope->storeIds());
        $this->assertFalse($scope->isCrossTenant());
    }

    public function test_a_scope_naming_several_stores_says_so(): void
    {
        $scope = MetricScope::stores([5, 2]);

        $this->assertSame([2, 5], $scope->storeIds());
        $this->assertTrue($scope->isCrossTenant());
    }

    public function test_there_is_no_way_to_build_a_scope_meaning_every_tenant(): void
    {
        /*
         * The operator case must be enumerated. If a constructor existed that meant "all stores",
         * forgetting a tenant predicate and asking for everything would look identical in a code
         * review, and `Order` has no global scope to catch the difference.
         *
         * THIS ASSERTS THE WHOLE SET, NOT THREE NAMES. The first version listed `all`, `platform`
         * and `everything` and asserted none was present -- which `forAllStores()`, `global()`,
         * `unscoped()` and `platformWide()` would all have passed. Review caught it. Asserting the
         * exact set means ANY new way to build a scope fails this test, which is precisely the
         * event a reviewer wants to be told about.
         */
        $constructors = array_values(array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            array_filter(
                (new \ReflectionClass(MetricScope::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn (\ReflectionMethod $m): bool => $m->isStatic()
            )
        ));

        sort($constructors);

        $this->assertSame(
            ['store', 'stores'],
            $constructors,
            'A new way to build a MetricScope appeared. Every money query in Phase 05 is bounded '
            . 'by what a scope can mean, so adding one is a decision about how wide a report may '
            . 'read -- not a refactor.'
        );
    }

    public function test_the_four_table_accessors_cannot_be_overridden(): void
    {
        /*
         * THE `final` KEYWORD IS THE WHOLE TENANCY ARGUMENT, so it gets a ratchet.
         *
         * `Metric`'s four accessors apply `whereIn('<table>.store_id', ...)` in the same
         * expression that names the table, and the claim built on that is "a metric cannot obtain
         * an unscoped builder". Without `final` an eleventh metric could simply override
         * `orders()` and return `DB::table('orders')`, and the claim would be a convention. Review
         * found that gap between the docblock and the code.
         *
         * A mutation test cannot catch this -- removing `final` breaks no existing behaviour,
         * because nothing subclasses these today. Only reflection can.
         */
        $class = new \ReflectionClass(\App\Support\Metrics\Metric::class);

        foreach (['orders', 'financials', 'transactions', 'wallets'] as $accessor) {
            $method = $class->getMethod($accessor);

            $this->assertTrue(
                $method->isFinal(),
                'Metric::' . $accessor . '() is not final, so a subclass can return an unscoped '
                . 'builder for a money table that no global scope protects.'
            );
            $this->assertTrue(
                $method->isProtected(),
                'Metric::' . $accessor . '() is reachable from outside the metric layer.'
            );
        }
    }

    // ------------------------------------------------------------------ window

    public function test_a_window_that_ends_before_it_starts_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MetricWindow::between(now()->addDay(), now()->subDay());
    }

    public function test_all_time_knows_it_is_all_time(): void
    {
        $this->assertTrue(MetricWindow::allTime()->isAllTime());
        $this->assertFalse(MetricWindow::since(now())->isAllTime());
        $this->assertSame('all time', MetricWindow::allTime()->describe());
    }

    // ------------------------------------------------------------------ result

    public function test_a_result_must_name_the_record_it_measures(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must name the record/');

        MetricResult::make(
            'invented', 'guesswork', null, ['USD' => 1.0], 1,
            $this->scope(), MetricWindow::allTime()
        );
    }

    public function test_an_unknown_caveat_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown metric caveat/');

        MetricResult::make(
            'gmv', MetricResult::RECORD_SETTLEMENT, null, ['USD' => 1.0], 1,
            $this->scope(), MetricWindow::allTime(), ['sounds_about_right']
        );
    }

    public function test_two_currencies_are_flagged_and_never_added(): void
    {
        $result = MetricResult::make(
            'gmv', MetricResult::RECORD_SETTLEMENT, null,
            ['USD' => 10.0, 'SYP' => 50000.0], 2,
            $this->scope(), MetricWindow::allTime()
        );

        $this->assertTrue($result->hasCaveat(MetricCaveat::MIXED_CURRENCY));
        $this->assertFalse($result->isSingleCurrency());
        $this->assertNull($result->currency());

        $this->expectException(MixedCurrencyException::class);
        $result->total();
    }

    public function test_an_empty_result_totals_zero_rather_than_throwing(): void
    {
        $result = MetricResult::make(
            'gmv', MetricResult::RECORD_SETTLEMENT, null, [], 0,
            $this->scope(), MetricWindow::allTime()
        );

        $this->assertSame(0.0, $result->total());
        $this->assertSame(0, $result->rowCount());
        $this->assertNull($result->currency());
    }

    public function test_a_null_currency_becomes_its_own_bucket_rather_than_a_guess(): void
    {
        // `currency_code` is nullable on all three money tables. Folding a null into the store's
        // configured currency would give the figure a unit it was never measured in.
        $result = MetricResult::make(
            'gmv', MetricResult::RECORD_SETTLEMENT, null, ['' => 5.0, 'USD' => 7.0], 2,
            $this->scope(), MetricWindow::allTime()
        );

        $this->assertSame(['unknown', 'USD'], $result->currencies());
        $this->assertSame(5.0, $result->totalIn('unknown'));
    }

    // ------------------------------------------------------- the unrecorded-currency assumption

    private function withUnknownBucket(array $byCurrency): MetricResult
    {
        return MetricResult::make(
            'gmv', MetricResult::RECORD_SETTLEMENT, 'orders.order_date', $byCurrency, 7,
            $this->scope(), MetricWindow::allTime(), [], ['cancellations' => 'included']
        );
    }

    public function test_folding_the_unrecorded_bucket_gives_a_tile_one_number_and_says_what_it_assumed(): void
    {
        /*
         * `2026_10_12_000102` added orders.currency_code and deliberately did not backfill, so
         * every store with history has an `unknown` bucket and `total()` throws. A tile renders
         * one number beside one currency symbol, so without this the obvious wiring is an HTTP
         * 500 on a merchant admin route.
         */
        $raw = $this->withUnknownBucket(['USD' => 826.50, 'unknown' => 331.00]);

        $this->assertTrue($raw->hasCaveat(MetricCaveat::MIXED_CURRENCY));
        $this->assertEqualsWithDelta(331.00, $raw->unrecordedCurrencyAmount(), 0.001);

        $folded = $raw->assumingUnknownCurrencyIs('USD');

        $this->assertEqualsWithDelta(1157.50, $folded->total(), 0.001);
        $this->assertSame('USD', $folded->currency());
        $this->assertTrue($folded->hasCaveat(MetricCaveat::CURRENCY_UNRECORDED));
        $this->assertEqualsWithDelta(331.00, $folded->notes()['currency_assumed_amount'], 0.001);
        $this->assertSame('USD', $folded->notes()['currency_assumed_into']);
    }

    public function test_the_mixed_currency_caveat_is_withdrawn_when_folding_leaves_one_currency(): void
    {
        // A caveat that is FALSE is worse than one that is missing: a merchant told "these amounts
        // are in more than one currency" beside a single-currency figure learns to ignore caveats.
        $folded = $this->withUnknownBucket(['USD' => 10.0, 'unknown' => 5.0])
            ->assumingUnknownCurrencyIs('USD');

        $this->assertFalse($folded->hasCaveat(MetricCaveat::MIXED_CURRENCY));
        $this->assertTrue($folded->isSingleCurrency());
    }

    public function test_folding_does_not_make_a_genuinely_multi_currency_figure_single(): void
    {
        /*
         * The problem this solves is UNRECORDED currency, not two real ones. A store trading in
         * both USD and SYP still has two buckets after folding, so MIXED_CURRENCY stays and
         * total() still refuses -- which is the whole point of §11 rule 3.
         */
        $folded = $this->withUnknownBucket(['USD' => 10.0, 'SYP' => 500000.0, 'unknown' => 5.0])
            ->assumingUnknownCurrencyIs('USD');

        $this->assertTrue($folded->hasCaveat(MetricCaveat::MIXED_CURRENCY));
        $this->assertTrue($folded->hasCaveat(MetricCaveat::CURRENCY_UNRECORDED));
        $this->assertEqualsWithDelta(15.0, $folded->totalIn('USD'), 0.001);

        $this->expectException(MixedCurrencyException::class);
        $folded->total();
    }

    public function test_a_figure_with_nothing_to_assume_claims_no_assumption(): void
    {
        // A store with no pre-migration history must not be told its figures rest on an
        // assumption they do not rest on.
        $clean = $this->withUnknownBucket(['USD' => 10.0]);
        $folded = $clean->assumingUnknownCurrencyIs('USD');

        $this->assertSame($clean, $folded, 'a copy was returned where nothing was folded');
        $this->assertFalse($folded->hasCaveat(MetricCaveat::CURRENCY_UNRECORDED));
        $this->assertArrayNotHasKey('currency_assumed_amount', $folded->notes());
        $this->assertEqualsWithDelta(0.0, $folded->unrecordedCurrencyAmount(), 0.001);
    }

    public function test_folding_into_a_currency_the_figure_does_not_yet_have_still_works(): void
    {
        // Every order predates the column: one bucket, named `unknown`. total() does NOT throw
        // (one bucket), but the number has no unit until it is named.
        $only = $this->withUnknownBucket(['unknown' => 42.0]);

        $this->assertEqualsWithDelta(42.0, $only->total(), 0.001);
        $this->assertSame('unknown', $only->currency());

        $folded = $only->assumingUnknownCurrencyIs('SYP');

        $this->assertSame('SYP', $folded->currency());
        $this->assertEqualsWithDelta(42.0, $folded->total(), 0.001);
        $this->assertTrue($folded->hasCaveat(MetricCaveat::CURRENCY_UNRECORDED));
    }

    public function test_folding_preserves_the_figures_identity_and_its_other_notes(): void
    {
        $folded = $this->withUnknownBucket(['USD' => 10.0, 'unknown' => 5.0])
            ->assumingUnknownCurrencyIs('USD');

        $this->assertSame('gmv', $folded->metric());
        $this->assertSame(MetricResult::RECORD_SETTLEMENT, $folded->record());
        $this->assertSame('orders.order_date', $folded->datedBy());
        $this->assertSame(7, $folded->rowCount());
        $this->assertSame('included', $folded->notes()['cancellations']);
    }

    public function test_the_unknown_bucket_key_is_the_same_constant_both_sides_use(): void
    {
        /*
         * `Metric::sumByCurrency()` chooses the bucket key and `MetricResult::make()` chooses it
         * again. If the two ever disagreed, the unrecorded rows would be invisible to
         * assumingUnknownCurrencyIs() and would silently vanish from every tile that folds.
         */
        $source = file_get_contents(__DIR__ . '/../../../app/Support/Metrics/Metric.php');

        $this->assertStringContainsString('MetricResult::UNKNOWN_CURRENCY', $source);
        $this->assertStringNotContainsString("? 'unknown'", $source);
        $this->assertSame('unknown', MetricResult::UNKNOWN_CURRENCY);
    }

    public function test_a_blocking_caveat_makes_a_figure_non_authoritative(): void
    {
        $blocked = MetricResult::make(
            'coupon_redemption', MetricResult::RECORD_SETTLEMENT, null, ['USD' => 10.0], 1,
            $this->scope(), MetricWindow::allTime(),
            [MetricCaveat::COUPON_REDEMPTION_UNRELIABLE]
        );

        $qualified = MetricResult::make(
            'margin', MetricResult::RECORD_ENTITLEMENT, null, ['USD' => 10.0], 1,
            $this->scope(), MetricWindow::allTime(),
            [MetricCaveat::COST_UNKNOWN, MetricCaveat::EXCLUDES_SHIPPING_AND_TAX]
        );

        $this->assertFalse($blocked->isAuthoritative());
        $this->assertTrue(
            $qualified->isAuthoritative(),
            'an ordinary caveat QUALIFIES a figure; only a blocking one disqualifies it'
        );
    }

    public function test_every_caveat_key_resolves_to_a_message(): void
    {
        foreach (MetricCaveat::all() as $caveat) {
            $this->assertNotSame(
                '',
                trim(MetricCaveat::message($caveat)),
                'the caveat "' . $caveat . '" has no sentence a merchant could read'
            );
        }

        $this->assertGreaterThanOrEqual(9, count(MetricCaveat::all()));
    }

    public function test_an_unknown_caveat_has_no_message_rather_than_an_empty_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MetricCaveat::message('made_up');
    }

    public function test_only_the_coupon_caveat_blocks(): void
    {
        /*
         * Section 9 is blocked BY OWNER INSTRUCTION, not by engineering judgement. Anything else
         * added to that list is a decision about what the product may report, so it should be
         * visible here rather than buried in a constant.
         */
        $blocking = array_values(array_filter(
            MetricCaveat::all(),
            static fn (string $c): bool => MetricCaveat::isBlocking($c)
        ));

        $this->assertSame([MetricCaveat::COUPON_REDEMPTION_UNRELIABLE], $blocking);
    }

    public function test_a_result_carries_its_scope_and_window_for_the_report_to_print(): void
    {
        $result = MetricResult::make(
            'gmv', MetricResult::RECORD_SETTLEMENT, 'orders.order_date', ['USD' => 1.0], 1,
            MetricScope::stores([4, 9]), MetricWindow::allTime(), [], ['cancellations' => 'excluded']
        );

        $array = $result->toArray();

        $this->assertSame('store_id in (4, 9)', $array['scope']);
        $this->assertSame('all time', $array['window']);
        $this->assertSame('orders.order_date', $array['dated_by']);
        $this->assertSame('excluded', $array['notes']['cancellations']);
        $this->assertTrue($array['authoritative']);
    }
}
