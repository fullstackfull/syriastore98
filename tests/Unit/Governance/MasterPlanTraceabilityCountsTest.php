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
}
