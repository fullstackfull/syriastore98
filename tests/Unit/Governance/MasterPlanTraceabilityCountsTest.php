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
}
