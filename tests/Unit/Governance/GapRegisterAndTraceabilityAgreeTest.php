<?php

namespace Tests\Unit\Governance;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE TWO CANONICAL DOCUMENTS MUST NOT CONTRADICT EACH OTHER, AND UNTIL 2026-11-26 NOTHING
 * CHECKED THAT THEY DIDN'T.
 *
 * `MASTER-PLAN-GAP-REGISTER.md` and `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` each have a gate
 * asserting that the document agrees with ITSELF -- `GapRegisterConsistencyTest` and
 * `MasterPlanTraceabilityCountsTest`. Neither asks whether the two agree with EACH OTHER, and
 * they are written by different workstreams at different times: the register is updated as each
 * slice lands, the traceability matrix was largely written once.
 *
 * MEASURED 2026-11-26: SEVEN requirement ids disagreed, every one of them in the same direction.
 *
 *   P03-ACC-02         register VERIFIED COMPLETE      matrix NOT IMPLEMENTED
 *   P04-P1-03          register PARTIALLY IMPLEMENTED  matrix NOT IMPLEMENTED
 *   P04-P2-02          register PARTIALLY IMPLEMENTED  matrix NOT IMPLEMENTED
 *   P04-P5-01          register VERIFIED COMPLETE      matrix NOT IMPLEMENTED
 *   P06-03             register PARTIALLY IMPLEMENTED  matrix NOT IMPLEMENTED
 *   P01-DEFECT-PROFILE register VERIFIED COMPLETE      matrix NOT IMPLEMENTED
 *   P01-DEFECT-REG     register VERIFIED COMPLETE      matrix NOT IMPLEMENTED
 *
 * The matrix understated delivery in all seven. That is the SAFE direction -- CLAUDE.md §4.1 is
 * about not overclaiming -- and it is still wrong, because the rule that section states is that
 * the figure must be the EXACT one, never a percentage and never a sample. The correction moved
 * VERIFIED COMPLETE from 172 to 176 and NOT IMPLEMENTED from 33 to 26, and those are the numbers
 * the owner directive's own baseline quotes.
 *
 * THE RULE THIS ASSERTS, AND WHY IT IS ONE-DIRECTIONAL
 * ====================================================
 *
 * A register row is a GAP; a matrix section is a REQUIREMENT. A gap is strictly narrower, so most
 * differences between the two are legitimate and must not fail: a requirement can sit at
 * PARTIALLY IMPLEMENTED while a particular gap against it is VERIFIED COMPLETE, because closing
 * one gap does not deliver the whole requirement.
 *
 * Exactly one combination cannot be true at the same time:
 *
 *   if ANY gap row for a requirement records progress (VERIFIED COMPLETE or PARTIALLY
 *   IMPLEMENTED), that requirement is NOT "NOT IMPLEMENTED".
 *
 * Work recorded against it has happened. Anything looser would pass the seven above; anything
 * stricter would fail honest differences and get itself deleted.
 *
 * A DELIBERATELY NARROW SCAN. Only the register's six-cell STATUS schema carries a status term;
 * the later "measured" rows do not, and are skipped -- `GapRegisterConsistencyTest` is what holds
 * that split. Ids are read from structure (a table cell, a `###` heading) and never from prose,
 * because CLAUDE.md records six separate occasions when a census was satisfied or broken by
 * sentences ABOUT the thing it measured.
 */
class GapRegisterAndTraceabilityAgreeTest extends TestCase
{
    private const REGISTER = 'docs/development/MASTER-PLAN-GAP-REGISTER.md';
    private const MATRIX = 'docs/development/MASTER-PLAN-REQUIREMENT-TRACEABILITY.md';

    /** Register statuses that mean work against the gap has actually happened. */
    private const PROGRESS = ['VERIFIED COMPLETE', 'PARTIALLY IMPLEMENTED'];

    /**
     * requirement id => list of register statuses recorded against it.
     *
     * @return array<string, list<string>>
     */
    private function registerStatuses(): array
    {
        $out = [];

        foreach (explode("\n", (string) file_get_contents(base_path(self::REGISTER))) as $line) {
            if (preg_match('/^\| `GAP-\d+` \|/', $line) !== 1) {
                continue;
            }

            $cells = explode(' | ', $line);

            // Six cells is the status schema. The later "measured" rows carry no status term.
            if (count($cells) !== 6) {
                continue;
            }

            $status = trim($cells[3]);

            // Ids come from the requirement cell's backticks, never from the prose cells.
            preg_match_all('/`([A-Z][A-Z0-9]*(?:-[A-Z0-9]+)+)`/', $cells[1], $ids);

            foreach ($ids[1] as $id) {
                $out[$id][] = $status;
            }
        }

        return $out;
    }

    /**
     * requirement id => the status its matrix section declares.
     *
     * @return array<string, string>
     */
    private function matrixStatuses(): array
    {
        $doc = (string) file_get_contents(base_path(self::MATRIX));

        preg_match_all(
            '/^### `([^`]+)`.*?\n+\*\*Status: ([^*]+)\*\*/ms',
            $doc,
            $m,
            PREG_SET_ORDER
        );

        $out = [];
        foreach ($m as $hit) {
            $out[$hit[1]] = trim($hit[2]);
        }

        return $out;
    }

    /** Guards every assertion below against a silently empty scan. */
    #[Test]
    public function test_the_premise_both_documents_were_actually_read(): void
    {
        $this->assertGreaterThan(
            100,
            count($this->registerStatuses()),
            'PREMISE: fewer than 100 requirement ids were read out of the gap register\'s status '
            . 'rows. The scan is stale and every assertion below would pass vacuously.'
        );

        $this->assertGreaterThan(
            300,
            count($this->matrixStatuses()),
            'PREMISE: fewer than 300 `### `ID`` sections were read out of the traceability matrix. '
            . 'The scan is stale.'
        );

        $this->assertNotEmpty(
            array_intersect_key($this->registerStatuses(), $this->matrixStatuses()),
            'PREMISE: no requirement id appears in BOTH documents, so this test compares nothing. '
            . 'Either an id format changed or one of the two scans is broken.'
        );
    }

    /**
     * THE DEFECT ITSELF. A requirement cannot be "NOT IMPLEMENTED" while a gap against it records
     * work delivered.
     */
    #[Test]
    public function test_no_requirement_is_not_implemented_while_a_gap_records_progress(): void
    {
        $register = $this->registerStatuses();
        $matrix = $this->matrixStatuses();

        $contradictions = [];

        foreach ($matrix as $id => $declared) {
            if ($declared !== 'NOT IMPLEMENTED' || ! isset($register[$id])) {
                continue;
            }

            $progress = array_values(array_intersect($register[$id], self::PROGRESS));

            if ($progress !== []) {
                $contradictions[] = sprintf(
                    '%s: the matrix says NOT IMPLEMENTED, the register records %s',
                    $id,
                    implode(' and ', array_unique($progress))
                );
            }
        }

        $this->assertSame([], $contradictions, sprintf(
            "The two canonical documents contradict each other for %d requirement id(s):\n  %s\n\n"
            . "Resolve it by MEASURING the code, not by copying one document into the other -- all "
            . "seven of the originals were resolved in the register's favour only after each was "
            . "checked against its commit. If the matrix is the right one, the register's status "
            . "is what changes. Then run docs/development/tools/resync-traceability-summaries.py "
            . "--write, because the headline counts and CLAUDE.md's copy of them move with it.",
            count($contradictions),
            implode("\n  ", $contradictions)
        ));
    }

    /**
     * THE SAME CHECK FROM THE OTHER END, so a future id cannot evade it by being spelled only in
     * the register. This asserts a much weaker thing -- that a VERIFIED COMPLETE gap's requirement
     * is present in the matrix at all -- because a gap can legitimately precede its section.
     */
    #[Test]
    public function test_a_closed_gaps_requirement_is_tracked_in_the_matrix(): void
    {
        $matrix = $this->matrixStatuses();
        $missing = [];

        foreach ($this->registerStatuses() as $id => $statuses) {
            if (! in_array('VERIFIED COMPLETE', $statuses, true) && ! isset($matrix[$id])) {
                continue;
            }

            if (! isset($matrix[$id])) {
                $missing[] = $id;
            }
        }

        $this->assertSame([], $missing, sprintf(
            "These requirement ids carry a VERIFIED COMPLETE gap row but have no `### `ID`` section "
            . "in the traceability matrix, so nothing counts them:\n  %s\n\n"
            . 'CLAUDE.md §4.1 records twenty requirements that were never tracked at all; this is '
            . 'how the twenty-first would start.',
            implode("\n  ", $missing)
        ));
    }
}
