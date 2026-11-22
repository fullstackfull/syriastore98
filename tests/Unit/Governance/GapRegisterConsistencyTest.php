<?php

namespace Tests\Unit\Governance;

use Tests\TestCase;

/**
 * THE GAP REGISTER'S HEADLINE MUST EQUAL ITS OWN BODY — THE SIBLING DOCUMENT HAD NO GATE.
 *
 * `MasterPlanTraceabilityCountsTest` was built on 2026-11-20 because the traceability matrix's
 * summary table had drifted from its rows. **The gap register, which CLAUDE.md §4.1 names in the
 * same breath, had no equivalent check — and it had drifted on the same day, in the same way.**
 *
 * `GAP-160` was closed on 2026-11-20 and its status cell rewritten to the one-off label
 * `**CLOSED 2026-11-20**`, outside the controlled vocabulary. The summary table went on counting
 * it under `NOT IMPLEMENTED`: 47 where the rows held 46. **THE TOTAL STAYED 181, WHICH IS WHY
 * NOTHING NOTICED** — one bucket was wrong and the sum was right, and a reader quoting the
 * headline would have been told one more requirement was unimplemented than actually was.
 *
 * That is the matrix's exact failure, found in the register two days later by a count rather than
 * by a reader. So the register gets the same gate.
 *
 * TWO TABLE SHAPES, AND THIS GUARDS ONE OF THEM. The original 181 rows carry a status column. The
 * 48 added since — the 18 recovered on 2026-11-16 and the 30 raised by later work — are a
 * different shape: `| gap | decision id(s) | phase | measured | next action |`, recording what was
 * MEASURED, with no status term at all. Those cannot be counted mechanically and the document now
 * says so. **This file asserts that the split is exactly where the document claims**, so a new row
 * in the wrong shape, or a status row quietly losing its column, fails here rather than silently
 * leaving the counts.
 *
 * Like the matrix gate, it asserts no particular total and no particular distribution — that would
 * make every honest re-trace a failing test. It asserts only that the document agrees with itself.
 */
class GapRegisterConsistencyTest extends TestCase
{
    private const DOC = 'docs/development/MASTER-PLAN-GAP-REGISTER.md';

    /**
     * The controlled vocabulary, identical to the matrix's. A new status must be added here AND to
     * the summary table — which is the point: `**CLOSED 2026-11-20**` was written as a one-off and
     * was invisible to every count in the document.
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

    private string $doc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->doc = (string) file_get_contents(base_path(self::DOC));
    }

    /** @return array{0: list<string>, 1: list<string>} [status rows, measured rows] */
    private function rows(): array
    {
        $status = [];
        $measured = [];

        foreach (explode("\n", $this->doc) as $line) {
            if (preg_match('/^\| `GAP-\d+` \|/', $line) !== 1) {
                continue;
            }

            // Six cells is the status schema; anything else is the later "measured" schema.
            if (count(explode(' | ', $line)) === 6) {
                $status[] = $line;
            } else {
                $measured[] = $line;
            }
        }

        return [$status, $measured];
    }

    /** @return array<string,int> status => number of rows carrying it */
    private function bodyCounts(): array
    {
        [$status] = $this->rows();

        $counts = [];
        foreach ($status as $line) {
            $term = trim(explode(' | ', $line)[3]);
            $counts[$term] = ($counts[$term] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return array<string,int> the summary table at the top of the document */
    private function declaredCounts(): array
    {
        preg_match('/\| status \| count \|\n\|[-| ]+\|\n((?:\|.*\n)+)/', $this->doc, $m);

        $counts = [];
        foreach (explode("\n", trim($m[1] ?? '')) as $line) {
            $cells = array_map('trim', explode('|', trim($line, '|')));
            if (count($cells) >= 2 && $cells[0] !== '' && is_numeric($cells[1])) {
                $counts[$cells[0]] = (int) $cells[1];
            }
        }

        return $counts;
    }

    /** Guards every assertion below against a silently empty scan. */
    public function test_the_premise_that_the_rows_can_be_read_at_all(): void
    {
        [$status, $measured] = $this->rows();

        $this->assertGreaterThan(150, count($status),
            'The status-schema rows could not be parsed, so every count below is measuring nothing.');

        $this->assertGreaterThan(20, count($measured),
            'The measured-schema rows could not be parsed.');

        $this->assertNotEmpty($this->declaredCounts(),
            'The summary table could not be parsed, so nothing is being compared to the rows.');
    }

    public function test_every_status_row_uses_a_term_from_the_controlled_vocabulary(): void
    {
        foreach ($this->bodyCounts() as $term => $count) {
            $this->assertContains($term, self::VOCABULARY, sprintf(
                '%d gap row(s) carry the status "%s", which is not in the controlled vocabulary. '
                . 'A one-off label is invisible to every count in this document -- which is exactly '
                . 'how "**CLOSED 2026-11-20**" left GAP-160 counted as NOT IMPLEMENTED for two days.',
                $count,
                $term
            ));
        }
    }

    public function test_the_summary_table_agrees_with_the_rows_it_summarises(): void
    {
        $body = $this->bodyCounts();
        $declared = $this->declaredCounts();

        ksort($body);
        ksort($declared);

        $this->assertSame($declared, $body,
            'The gap register\'s summary table disagrees with its own rows. That table is the '
            . 'figure a reader quotes for how much of the approved scope is still open, and '
            . 'CLAUDE.md §4.1 forbids a percentage or a sample precisely so the exact number '
            . 'carries the weight.');
    }

    /**
     * THE DOCUMENT STATES ITS OWN SPLIT, AND THE SPLIT MUST BE REAL. Without this, a new row in
     * the measured schema would quietly leave the counted population and the register would
     * understate what is open while every other assertion here still passed.
     */
    public function test_the_two_table_shapes_are_where_the_document_says_they_are(): void
    {
        [$status, $measured] = $this->rows();

        foreach ([count($status) => 'status', count($measured) => 'measured'] as $count => $which) {
            $this->assertStringContainsString("**{$count}**", $this->doc, sprintf(
                'The document does not state that there are %d %s-schema rows, but there are. '
                . 'The split between the counted rows and the uncounted ones is the one thing a '
                . 'reader must not have to work out for themselves.',
                $count,
                $which
            ));
        }

        $this->assertStringContainsString('**' . (count($status) + count($measured)) . ' rows in total.**', $this->doc,
            'The stated row total does not match the rows.');
    }
}
