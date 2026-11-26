<?php

namespace Tests\Unit\Governance;

use Tests\TestCase;

/**
 * THE TRACEABILITY MATRIX'S HEADLINE MUST EQUAL ITS OWN BODY.
 *
 * CLAUDE.md §4.1 makes reconciling every requirement against this matrix mandatory before any
 * completion claim, and the figure a reader quotes is the summary table at the top — not 350
 * sections further down. On 2026-11-20 those two disagreed: the table read `PARTIALLY IMPLEMENTED
 * 76` / `NOT IMPLEMENTED 47` while the rows held 79 and 44, because three rows had been re-traced
 * from NOT to PARTIAL and the summary was never updated. The headline understated what had
 * actually been examined, and nothing anywhere checked.
 *
 * A stale count in this file is not a cosmetic defect. It is the number the owner is given for
 * "how much of the approved scope is done", and §4.1 forbids a percentage or a sample precisely so
 * that the exact figure carries the weight. So it is asserted, in both directions, like any other
 * census in this repository.
 *
 * This deliberately does NOT assert a particular total or a particular distribution — that would
 * turn every honest re-trace into a failing test. It asserts only that the document agrees with
 * itself.
 */
class MasterPlanTraceabilityCountsTest extends TestCase
{
    private const DOC = 'docs/development/MASTER-PLAN-REQUIREMENT-TRACEABILITY.md';

    /**
     * The controlled vocabulary. A new status must be added here AND to the summary table, which is
     * the point: `IMPLEMENTED (2026-11-20)` was written as a one-off label while this was being
     * built, and it would have been invisible to every count in the document.
     */
    private const VOCABULARY = [
        'VERIFIED COMPLETE',
        'PARTIALLY IMPLEMENTED',
        'NOT IMPLEMENTED',
        'IMPLEMENTED - VERIFICATION INCOMPLETE',
        'OWNER-APPROVED DEFERRED',
        'OWNER DECISION REQUIRED',
        'EXTERNALLY BLOCKED',
        'ACCEPTED OUT OF SCOPE',
    ];

    /**
     * Rows that are summaries rather than requirements, so they are not counted in the 350 and are
     * not expected in the summary table. Each must be justified here by name.
     */
    private const AGGREGATE_ROWS = [
        // The eighteen recovered MKT/ANA decision ids, held together as one row because their
        // individual texts were unavailable when the matrix was built (gap register category R).
        'MEASURED, NONE VERIFIED COMPLETE',
    ];

    private string $doc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->doc = file_get_contents(base_path(self::DOC));
    }

    /** @return array<string,int> status => number of rows carrying it */
    private function bodyCounts(): array
    {
        preg_match_all('/^\*\*Status: ([^*]+)\*\*/m', $this->doc, $m);

        $counts = [];
        foreach ($m[1] as $status) {
            $status = trim($status);
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }

    public function test_the_premise_that_the_statuses_can_be_read_at_all(): void
    {
        // Guards every assertion below against a silently empty scan.
        $this->assertGreaterThan(300, array_sum($this->bodyCounts()),
            'Read fewer than 300 status lines from ' . self::DOC . '; the scan is stale.');
    }

    public function test_every_row_uses_a_status_from_the_controlled_vocabulary(): void
    {
        $allowed = array_merge(self::VOCABULARY, self::AGGREGATE_ROWS);

        foreach (array_keys($this->bodyCounts()) as $status) {
            $this->assertContains($status, $allowed,
                "'{$status}' is not in the controlled vocabulary. A one-off status label is counted "
                . 'by nothing in this document. Use an existing status, or add it to VOCABULARY and '
                . 'to the summary table together.');
        }
    }

    public function test_the_summary_table_agrees_with_the_rows_it_summarises(): void
    {
        $body = $this->bodyCounts();

        foreach (self::VOCABULARY as $status) {
            $this->assertMatchesRegularExpression(
                '/^\| ' . preg_quote($status, '/') . ' \| \*\*\d+\*\* \|$/m',
                $this->doc,
                "The summary table has no row for '{$status}'."
            );

            preg_match('/^\| ' . preg_quote($status, '/') . ' \| \*\*(\d+)\*\* \|$/m', $this->doc, $m);

            $this->assertSame((int) $m[1], $body[$status] ?? 0,
                "The summary table says {$m[1]} rows are '{$status}'; " . ($body[$status] ?? 0)
                . ' rows actually carry it. Update the table in the same change as the status.');
        }
    }

    public function test_the_narrative_headline_agrees_with_the_table(): void
    {
        preg_match('/^\| VERIFIED COMPLETE \| \*\*(\d+)\*\* \|$/m', $this->doc, $table);
        $verified = (int) $table[1];
        $total = array_sum(array_map(
            fn (string $s) => (int) (preg_match('/^\| ' . preg_quote($s, '/') . ' \| \*\*(\d+)\*\* \|$/m', $this->doc, $m) ? $m[1] : 0),
            self::VOCABULARY
        ));

        $this->assertMatchesRegularExpression(
            '/\*\*' . $total . ' requirements recovered\. ' . $verified . ' VERIFIED COMPLETE\. '
            . ($total - $verified) . ' are not\.\*\*/',
            $this->doc,
            "The headline must read '{$total} requirements recovered. {$verified} VERIFIED COMPLETE. "
            . ($total - $verified) . " are not.' to match the summary table."
        );
    }

    /*
     * TWO MORE DERIVED FIGURES, ADDED 2026-11-22 BECAUSE BOTH WENT STALE IN ONE CHANGE AND EVERY
     * TEST ABOVE STILL PASSED.
     *
     * `P05-14` was re-traced from VERIFIED COMPLETE to PARTIALLY IMPLEMENTED. The status table and
     * the headline were repaired by the resync tool, and this class was green -- while the
     * per-phase table still credited Phase 05/06 with three verified rows, and the sentence under
     * the headline still said 172 of 368. The same failure this class exists for, one summary
     * further down: a number a reader quotes, derived from the rows, checked by nothing.
     */

    /**
     * @return list<array{title: string, total: int, verified: int}> one entry per `## Phase` section,
     *         counting REQUIREMENT rows only -- an aggregate row is a summary, not a requirement
     */
    private function phaseSections(): array
    {
        preg_match_all('/^## (.+)$/m', $this->doc, $headings, PREG_OFFSET_CAPTURE);

        $sections = [];

        foreach ($headings[1] as $index => [$title, $offset]) {
            if (! str_starts_with($title, 'Phase ')) {
                continue;
            }

            $end = $headings[1][$index + 1][1] ?? strlen($this->doc);
            preg_match_all('/^\*\*Status: ([^*]+)\*\*/m', substr($this->doc, $offset, $end - $offset), $m);

            $statuses = array_values(array_filter(
                array_map('trim', $m[1]),
                fn (string $status): bool => in_array($status, self::VOCABULARY, true)
            ));

            $sections[] = [
                'title' => substr($title, strlen('Phase ')),
                'total' => count($statuses),
                'verified' => count(array_keys($statuses, 'VERIFIED COMPLETE', true)),
            ];
        }

        return $sections;
    }

    public function test_the_per_phase_table_agrees_with_the_sections_it_summarises(): void
    {
        preg_match_all('/^\| (\d[^|]*?) \| (\d+) \| (\d+) \| \*\*(\d+)\*\* \|$/m', $this->doc, $rows, PREG_SET_ORDER);

        $sections = $this->phaseSections();

        $this->assertGreaterThan(3, count($sections), 'PREMISE: fewer than four `## Phase` sections were read; the scan is stale.');
        $this->assertCount(count($sections), $rows,
            'The per-phase table does not have one row per `## Phase` section.');

        foreach ($sections as $index => $section) {
            [, $label, $recovered, $verified, $notComplete] = $rows[$index];

            // The table truncates long phase names, so the label is a prefix of the section title.
            $this->assertStringStartsWith($label, $section['title'],
                "Row {$index} of the per-phase table is labelled '{$label}', which is not the section in that position.");

            $this->assertSame(
                [$section['total'], $section['verified'], $section['total'] - $section['verified']],
                [(int) $recovered, (int) $verified, (int) $notComplete],
                "The per-phase table's row for '{$label}' does not match the rows of that section. "
                . 'Run docs/development/tools/resync-traceability-summaries.py --write.'
            );
        }

        $this->assertSame(
            array_sum(array_column($sections, 'total')),
            array_sum(array_map(fn (string $s): int => $this->bodyCounts()[$s] ?? 0, self::VOCABULARY)),
            'The phase sections do not account for every requirement row: one sits outside every `## Phase` heading.'
        );
    }

    /**
     * THE FIGURE THAT INCLUDES THE EIGHTEEN. The recovered requirements are held in the gap
     * register, not here, so the sentence adds them to the matrix total -- and none of them is
     * verified complete, so the verified figure is the matrix's own.
     */
    public function test_the_project_wide_sentence_agrees_with_the_headline(): void
    {
        $this->assertSame(1, preg_match('/\*\*(\d+) requirements recovered\. (\d+) VERIFIED COMPLETE\./', $this->doc, $headline));
        $this->assertSame(1, preg_match('/\*\*Plus (\d+) recovered on /', $this->doc, $plus),
            'PREMISE: the sentence naming how many requirements were recovered later could not be found.');

        $known = (int) $headline[1] + (int) $plus[1];
        $verified = (int) $headline[2];

        $this->assertMatchesRegularExpression(
            '/\*\*' . $known . ' known requirements, ' . $verified . ' verified complete, ' . ($known - $verified) . ' not\.\*\*/',
            $this->doc,
            "The project-wide sentence must read '{$known} known requirements, {$verified} verified complete, "
            . ($known - $verified) . " not.' to match the headline above it."
        );
    }

    /**
     * AND THE COPY EVERY SESSION READS FIRST. `CLAUDE.md` quotes these figures in its "Where to
     * look" table. It said 171 for a day while this document said 172, and nothing noticed: the
     * instruction file is the one place a stale figure is read before the matrix is.
     */
    public function test_the_instruction_file_quotes_the_matrix_figures(): void
    {
        $this->assertSame(1, preg_match('/\*\*(\d+) requirements recovered\. (\d+) VERIFIED COMPLETE\. (\d+) are not\.\*\*/', $this->doc, $headline));
        $this->assertSame(1, preg_match('/\*\*Plus (\d+) recovered on /', $this->doc, $plus));

        $claude = (string) file_get_contents(base_path('CLAUDE.md'));

        $this->assertSame(
            1,
            preg_match('/(\d+) requirements, \*\*(\d+) verified complete, (\d+) not, \+(\d+) recovered and untracked = (\d+) open\*\*/', $claude, $quoted),
            'PREMISE: CLAUDE.md no longer quotes the traceability figures in the form this test reads.'
        );

        $this->assertSame(
            [(int) $headline[1], (int) $headline[2], (int) $headline[3], (int) $plus[1], (int) $headline[3] + (int) $plus[1]],
            array_map('intval', array_slice($quoted, 1)),
            'CLAUDE.md quotes traceability figures that are not the matrix\'s. It is read before the matrix is.'
        );
    }

    /**
     * AND THE HANDOFF DOCUMENT, WHICH IS READ BEFORE EITHER OF THEM.
     *
     * `PROJECT-STATE.md` is the first file CLAUDE.md tells a new or transferred session to open, and
     * its NEXT EXECUTABLE ACTION block quotes the per-status remainder. On 2026-11-26 an account
     * transfer handed over "NOT IMPLEMENTED 12, PARTIALLY IMPLEMENTED 99" while the matrix held 10
     * and 101 -- the figures had moved under it and nothing checked, because the test above covers
     * `CLAUDE.md` and this document was never in the set. That is the same failure the class
     * docblock describes, one file further out: a derived figure with no consumer asserting it.
     *
     * Asserted in BOTH directions, like the rest: the handoff may not quote a figure the matrix does
     * not hold, and the matrix may not move without the handoff following.
     */
    public function test_the_handoff_document_quotes_the_matrix_figures(): void
    {
        $state = (string) file_get_contents(base_path('docs/development/PROJECT-STATE.md'));

        $this->assertSame(
            1,
            preg_match('/\*\*Current figures \(gate-enforced\): (\d+) known requirements, (\d+) verified complete, (\d+) not\.\*\*/', $state, $headline),
            'PREMISE: PROJECT-STATE.md no longer quotes the project-wide figures in the form this test reads.'
        );

        $this->assertSame(
            1,
            preg_match('/Remaining: NOT IMPLEMENTED (\d+), PARTIALLY IMPLEMENTED (\d+)\./', $state, $remaining),
            'PREMISE: PROJECT-STATE.md no longer quotes the per-status remainder in the form this test reads.'
        );

        // The project-wide sentence, which the matrix states as 350 recovered plus the 20 held in the
        // gap register at COVERAGE NOT ASSESSED.
        $this->assertSame(1, preg_match('/\*\*(\d+) requirements recovered\. (\d+) VERIFIED COMPLETE\. (\d+) are not\.\*\*/', $this->doc, $matrixHeadline));
        $this->assertSame(1, preg_match('/\*\*Plus (\d+) recovered on /', $this->doc, $plus));

        $known = (int) $matrixHeadline[1] + (int) $plus[1];

        $this->assertSame(
            [$known, (int) $matrixHeadline[2], $known - (int) $matrixHeadline[2]],
            array_map('intval', array_slice($headline, 1)),
            'PROJECT-STATE.md quotes project-wide figures that are not the matrix\'s. It is the first file a transferred session reads.'
        );

        // The per-status remainder comes from the summary table, which the tests above have already
        // pinned to the rows themselves -- so this compares the handoff against measured counts, not
        // against another unchecked summary.
        foreach (['NOT IMPLEMENTED' => 1, 'PARTIALLY IMPLEMENTED' => 2] as $status => $group) {
            $this->assertSame(
                1,
                preg_match('/^\| ' . preg_quote($status, '/') . ' \| \*\*(\d+)\*\* \|$/m', $this->doc, $cell),
                "PREMISE: the matrix's summary table has no {$status} row in the form this test reads."
            );

            $this->assertSame(
                (int) $cell[1],
                (int) $remaining[$group],
                "PROJECT-STATE.md says {$status} " . $remaining[$group] . " and the matrix says {$cell[1]}. "
                . 'The handoff figure is the one an incoming session acts on.'
            );
        }
    }

    /**
     * THE HANDOFF'S GAP-REGISTER ROW COUNT MUST BE THE REGISTER'S OWN.
     *
     * It read **279** while the register's header said **332** -- a third copy of a derived figure,
     * read by nobody. That is the identical shape as the `NOT IMPLEMENTED 12 / PARTIALLY
     * IMPLEMENTED 99` pair the case above guards, and it went stale for the same reason: the
     * register's header IS asserted against its rows by `GapRegisterConsistencyTest`, and the
     * matrix's table IS asserted here, but the handoff's copy of the row count had no consumer.
     *
     * THE REMEDY FOR A STALE DERIVED FIGURE IS NOT TO RE-DERIVE IT MORE CAREFULLY. It is to give it
     * a consumer. This is that consumer.
     */
    public function test_the_handoff_document_quotes_the_gap_registers_own_row_count(): void
    {
        $register = (string) file_get_contents(base_path('docs/development/MASTER-PLAN-GAP-REGISTER.md'));
        $handoff = (string) file_get_contents(base_path('docs/development/PROJECT-STATE.md'));

        $this->assertSame(1, preg_match('/\*\*(\d+) rows in total\.\*\*/', $register, $m),
            'The gap register no longer declares "**<n> rows in total.**". Either it was restructured '
            . 'or this pattern has gone blind -- and a blind pattern passes this test for the wrong '
            . 'reason, which is the failure this repository has recorded eight times.');

        $declared = (int) $m[1];

        $this->assertSame(1, preg_match('/register holds \*\*?(\d+)\*\*? rows/', $handoff, $h),
            'PROJECT-STATE.md no longer states how many rows the gap register holds. If that sentence '
            . 'was removed on purpose, delete this test with it; if it was reworded, re-point the '
            . 'pattern. Do not leave the figure unasserted -- it was stale at 279 against a real 332 '
            . 'precisely because nothing read it.');

        $this->assertSame($declared, (int) $h[1], sprintf(
            'PROJECT-STATE.md says the gap register holds %d rows and the register itself declares %d. '
            . "The register's header is the measured one -- GapRegisterConsistencyTest asserts it "
            . 'against the rows in both directions -- so correct the handoff, not the register.',
            (int) $h[1], $declared
        ));
    }
}
