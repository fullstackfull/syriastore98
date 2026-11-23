<?php

namespace Tests\Unit\Reports;

use App\Support\Metrics\MetricCaveat;
use App\Support\Reports\ReportFigureNote;
use Tests\TestCase;

/**
 * THE REPORT FIGURE SENTENCES EXIST IN EXACTLY ONE PLACE, and this test exists because a
 * mutation proved the other tests could not see a copy.
 * =================================================================================================
 *
 * `ReportExportsCarryTheScreensCaveatTest` asserts the exported sentence is identical to
 * `ReportFigureNote`'s own return value. That catches a DRIFTED copy. Measured 2026-11-21: it does
 * NOT catch a VERBATIM one -- pasting
 *
 *     __('The total value of items ordered. ...') . ' ' . MetricCaveat::message(FLOAT_PRECISION)
 *
 * back into `ReportController` in place of the call left all seven of those tests green, because
 * an identical string is identical. An earlier draft of that file's docblock claimed otherwise and
 * was wrong.
 *
 * A verbatim copy is not harmless -- it is precisely the state this work started from. The
 * definition sentence was duplicated as an identical literal in FOUR blades, and because nothing
 * read it from one place, two of those four carried the precision caveat beside it and two did
 * not. The duplication is what allowed the divergence; the divergence is what hid the defect.
 *
 * So the invariant is structural and that is the only way to assert it: in first-party application
 * code, these sentences appear in `ReportFigureNote` and nowhere else.
 *
 * WHAT IS DELIBERATELY NOT IN SCOPE:
 *
 *  - `resources/lang/*.json` -- the translation catalogue must contain the key; that is its job.
 *  - `tests/` -- a test SHOULD hardcode the text it expects, so that it still fails if the
 *    constant and the catalogue are changed together. Scoping the census to `app/` and
 *    `resources/views/` is what keeps this test from forbidding the very assertions that give it
 *    its meaning.
 *  - `packages/workdo/` and `storage/framework/views/` -- vendored code and the compiled Blade
 *    cache, neither of which is a source file anyone edits.
 */
final class ReportFigureNoteIsTheOnlySourceTest extends TestCase
{
    /**
     * The hints that carry `MONEY_FLOAT_PRECISION` ("these figures") rather than `FLOAT_PRECISION`
     * ("this figure"), because they serve rows with six money columns.
     *
     * DEFINED ONCE AND USED BY BOTH CASES. A mutation removing `moneyRowHint` from the plural
     * case's own hint list SURVIVED while this was two hand-kept lists: the singular census
     * excluded it and the plural case simply stopped checking it, so a hint could fall between
     * them and be asserted nowhere -- which is how `moneyRowHint` came to be unchecked in the
     * first place. The plural case now asserts it covers this list exactly.
     */
    private const PLURAL_FAMILY = ['moneyRowHint', 'salesRowHint'];

    /** The sentences that must have exactly one home, and the file that is allowed to be it. */
    private const HOME = 'app/Support/Reports/ReportFigureNote.php';

    private function sentences(): array
    {
        return [
            'the Order value definition' =>
                'The total value of items ordered. This is not payments received, and not profit.',
            'the gross Order value definition' =>
                'The full amount the orders came to: after coupons, and including shipping and tax. '
                . 'This is what customers were asked to pay, not what they paid, and not profit.',
            'the Shipping charged definition' =>
                'What customers were charged for delivery on these orders, whatever their payment '
                . 'status. Where the plan charges shipping, it is already part of order value '
                . 'including shipping and tax.',
            'the Coupon value applied definition' =>
                'The amount discounted by coupons, already deducted from order value.',

            /*
             * P5-R7-B, added on the independent review's note that the new sentence was outside
             * this census -- a verbatim copy of it into a controller or blade would have passed.
             *
             * THE FRAGMENT STOPS SHORT OF THE APOSTROPHE DELIBERATELY. The source literal is
             * `report\'s` (an escaped apostrophe inside a single-quoted PHP string), so a census
             * string containing a plain `report's` does not match the file's bytes and the census
             * would fail for the wrong reason -- the review flagged exactly this trap. Matching
             * from `from the payment ledger` onward is unambiguous and apostrophe-free.
             */
            'the collected-payments export sentence' =>
                'from the payment ledger: :value. This is one total for the whole period, not a '
                . 'figure for each row.',
        ];
    }

    /** Every first-party source file that could hold one, as real paths on disk. */
    private function searchedFiles(): array
    {
        $files = [];

        foreach (['app', 'resources/views'] as $root) {
            $rii = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($root))
            );

            foreach ($rii as $file) {
                if ($file->isDir()) {
                    continue;
                }

                if (! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                $files[] = str_replace(base_path() . '/', '', $file->getPathname());
            }
        }

        sort($files);

        return $files;
    }

    public function test_premise_the_census_really_reads_the_tree(): void
    {
        $files = $this->searchedFiles();

        $this->assertGreaterThan(500, count($files),
            'The census read only ' . count($files) . ' files. It is meant to walk app/ and '
            . 'resources/views/, so a number this small means the walk is broken and every '
            . 'assertion below would pass vacuously.');

        $this->assertContains(self::HOME, $files,
            'The census did not even reach ' . self::HOME . ', so it cannot be measuring anything.');
    }

    public function test_each_sentence_lives_in_exactly_one_first_party_file(): void
    {
        foreach ($this->sentences() as $what => $sentence) {
            $holders = [];

            foreach ($this->searchedFiles() as $relative) {
                if (str_contains((string) file_get_contents(base_path($relative)), $sentence)) {
                    $holders[] = $relative;
                }
            }

            $this->assertSame([self::HOME], $holders, sprintf(
                '%s is written out in %d first-party file(s): %s. It must appear only in %s, '
                . 'because duplication is what let two of the four report screens carry the '
                . 'precision caveat while the other two silently did not. Call '
                . 'ReportFigureNote instead of restating the sentence.',
                $what,
                count($holders),
                implode(', ', $holders) ?: '(none)',
                self::HOME
            ));
        }
    }

    /**
     * And the composition itself is not re-assembled anywhere: a caller that glued the definition
     * to `MetricCaveat::FLOAT_PRECISION` by hand would be a copy of `orderValueHint()`, which is
     * the same defect one level up.
     */
    public function test_no_other_file_re_assembles_the_definition_and_its_caveat(): void
    {
        foreach ($this->searchedFiles() as $relative) {
            if ($relative === self::HOME) {
                continue;
            }

            $source = (string) file_get_contents(base_path($relative));

            if (! str_contains($source, 'FLOAT_PRECISION')) {
                continue;
            }

            $this->assertStringNotContainsString('items ordered', $source, sprintf(
                '%s mentions FLOAT_PRECISION next to the Order value wording, so it is composing '
                . 'the hint by hand instead of calling ReportFigureNote::orderValueHint().',
                $relative
            ));
        }
    }

    /**
     * EVERY MONEY COMPOSITION CARRIES THE PRECISION CAVEAT, which nothing else asserts.
     *
     * The `<x-ss.stat>` census counts hints; it cannot see a caveat silently dropped from one. So
     * each `*Hint()` on this class is checked for the sentence, by name, from the vocabulary --
     * which also means adding a new money figure here without its caveat fails rather than ships.
     *
     * `moneyRowHint()` is deliberately excluded: it IS the plural caveat and carries no definition,
     * because no single definition covers a nine-column export row.
     */
    public function test_every_money_hint_carries_the_precision_caveat(): void
    {
        $caveat = MetricCaveat::message(MetricCaveat::FLOAT_PRECISION);

        $hints = [
            'orderValueHint' => ReportFigureNote::orderValueHint(),
            'grossOrderValueHint' => ReportFigureNote::grossOrderValueHint(),
            'shippingChargedHint' => ReportFigureNote::shippingChargedHint(),
            'couponValueAppliedHint' => ReportFigureNote::couponValueAppliedHint(),
        ];

        /*
         * THE PLURAL FAMILY IS EXCLUDED HERE AND ASSERTED SEPARATELY BELOW.
         *
         * `moneyRowHint` and `salesRowHint` serve the two sales reports, whose rows carry NINE
         * columns, six of them money -- so they carry `MONEY_FLOAT_PRECISION` ("these figures")
         * rather than `FLOAT_PRECISION` ("this figure"), and the singular sentence is not in them
         * by design. `moneyRowHint` was already excluded; P5-R7-B added `salesRowHint` and this
         * census caught it, which is the census working.
         *
         * EXCLUDING A HINT FROM THIS CASE NO LONGER MEANS IT GOES UNCHECKED, which it did before:
         * `moneyRowHint` was named here and asserted nowhere. Both are now held to the plural
         * caveat by `test_every_plural_money_hint_carries_the_plural_precision_caveat()`, so the
         * exclusion classifies a hint rather than exempting it.
         */
        $plural = self::PLURAL_FAMILY;

        $declared = array_filter(
            get_class_methods(ReportFigureNote::class),
            static fn (string $m): bool => str_ends_with($m, 'Hint') && ! in_array($m, $plural, true)
        );

        sort($declared);
        $checked = array_keys($hints);
        sort($checked);

        $this->assertSame($declared, $checked, sprintf(
            'ReportFigureNote declares the money hint(s) %s but this test checks %s. A new money '
            . 'figure was added without being held to the caveat rule.',
            implode(', ', $declared),
            implode(', ', $checked)
        ));

        foreach ($hints as $name => $sentence) {
            $this->assertStringContainsString($caveat, $sentence, sprintf(
                'ReportFigureNote::%s() no longer carries the precision caveat. Every one of these '
                . 'figures is accumulated in PHP as += (float) off a FLOAT column, so the caveat is '
                . 'true of all of them, and the hint census counts hints rather than their content.',
                $name
            ));
        }
    }

    public function test_every_plural_money_hint_carries_the_plural_precision_caveat(): void
    {
        /*
         * P5-R7-B. The two sales exports write nine columns per period, six of them money, so
         * "this figure" cannot say which one it means -- which is the whole reason
         * `MONEY_FLOAT_PRECISION` exists beside `FLOAT_PRECISION`. These hints are excluded from
         * the singular census above; they are NOT excluded from having to carry a caveat, and
         * before this case they were: `moneyRowHint` was named in that exclusion and asserted
         * nowhere at all.
         *
         * `salesRowHint(null)` is the unavailable branch -- no ledger figure to state -- and it
         * must still carry the caveat, because the money columns are still floats whatever the
         * ledger could answer.
         */
        $caveat = MetricCaveat::message(MetricCaveat::MONEY_FLOAT_PRECISION);

        $hints = [
            'moneyRowHint' => ReportFigureNote::moneyRowHint(),
            'salesRowHint (no ledger figure)' => ReportFigureNote::salesRowHint(null),
            'salesRowHint (valueless tile)' => ReportFigureNote::salesRowHint(['value' => null]),
            'salesRowHint (with a figure)' => ReportFigureNote::salesRowHint(['value' => '40.00 EUR']),
        ];

        /*
         * THIS CASE CENSUSES ITSELF, the way the singular one does. A mutation that removed
         * `moneyRowHint` from the array above survived without this: the loop only checks what the
         * array holds, so dropping a hint from the array silently dropped it from the gate. Every
         * member of PLURAL_FAMILY must appear in a key here.
         */
        foreach (self::PLURAL_FAMILY as $method) {
            $covered = array_filter(
                array_keys($hints),
                static fn (string $k): bool => str_starts_with($k, $method)
            );

            $this->assertNotEmpty($covered, sprintf(
                'ReportFigureNote::%s() is excluded from the singular caveat census and is not '
                . 'exercised here either, so it is held to no caveat rule at all.',
                $method
            ));
        }

        foreach ($hints as $name => $sentence) {
            $this->assertStringContainsString($caveat, $sentence, sprintf(
                'ReportFigureNote::%s no longer carries the plural precision caveat. These rows are '
                . 'six money columns accumulated as += (float) off FLOAT columns, so it is true of '
                . 'every one of them and dropping it is the export defect in reverse.',
                $name
            ));
        }

        /* And the figure only appears when there IS one. */
        $this->assertStringNotContainsString('Payments received over', $hints['salesRowHint (no ledger figure)'],
            'the unavailable branch promises a collected figure it does not have');
        $this->assertStringNotContainsString('Payments received over', $hints['salesRowHint (valueless tile)'],
            'a tile whose value is null promised a collected figure it does not have');
        $this->assertStringContainsString('40.00 EUR', $hints['salesRowHint (with a figure)'],
            'the available branch dropped the figure it exists to carry');
    }

    /**
     * The two compositions are what the exports and the screens actually send, so their SHAPE is
     * pinned here too: definition first, then one space, then the caveat. A change of order or a
     * missing separator would run two sentences together in a CSV cell.
     */
    public function test_the_compositions_are_definition_then_caveat(): void
    {
        $this->assertSame(
            ReportFigureNote::orderValue() . ' ' . MetricCaveat::message(MetricCaveat::FLOAT_PRECISION),
            ReportFigureNote::orderValueHint()
        );

        $this->assertSame(
            ReportFigureNote::grossOrderValue() . ' ' . MetricCaveat::message(MetricCaveat::FLOAT_PRECISION),
            ReportFigureNote::grossOrderValueHint()
        );

        $this->assertSame(
            MetricCaveat::message(MetricCaveat::MONEY_FLOAT_PRECISION),
            ReportFigureNote::moneyRowHint(),
            'moneyRowHint() must be the plural statement alone: no single definition covers a '
            . 'nine-column row, and inventing one would be a monetary definition this code does '
            . 'not get to make.'
        );
    }
}
