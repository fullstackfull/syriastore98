<?php

namespace Tests\Unit\Governance;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GAP-172 / P05-24 — PHASE 05 REPORTING HONESTY, AS A GATE RATHER THAN A PROMISE.
 * =================================================================================================
 *
 * THE REQUIREMENT, in the plan's own words (`PHASE-05-EXECUTION-PLAN.md` §5): "Work completed will
 * be reported as completed and work not started will be reported as not started — the final report
 * states, per slice, which of the two it is, with evidence." Plus §0's vocabulary rule: "An approved
 * design is never described as implemented until its behaviour has been measured, and engineering
 * completion is never described as production readiness."
 *
 * WHY THE REPORT IS GATED AND NOT MERELY WRITTEN. A per-slice statement maintained by hand is only
 * as current as the last person who remembered it, and this requirement exists because the matrix
 * was standing in for a report that did not exist. So the three ways the report could go quietly
 * wrong are each a failing assertion here:
 *
 *   1. A SLICE IS ADDED AND THE REPORT DOES NOT NOTICE. The slice set is asserted against
 *      `PHASE-05-BILINGUAL-APPEARANCE-GATE.md`'s own SLICES table, in BOTH directions — and that
 *      table is itself asserted against the execution plan's headings by
 *      `Phase05BilingualAppearanceGateTest`. So a slice added to the plan reddens this file too,
 *      through one hop, without either document naming the other's contents.
 *
 *   2. A REQUIREMENT IS DROPPED BECAUSE NO SLICE OWNS IT. That is the named failure of `P05-11`
 *      ("do not let the slice list stand as the definition of scope": loyalty was in the scope
 *      statement and in none of the original eight slices) and of this row's own next action, which
 *      asks for the conversion and marketing-performance requirements to be carried. So the
 *      requirement set is asserted against the traceability matrix's own `P05-*` headings, in both
 *      directions, and the subset with no owning slice is counted exactly.
 *
 *   3. A VERDICT IS GRANTED THAT ONLY A PERSON OR A RUN CAN GRANT. The verdict vocabulary is the
 *      closed list the plan declares in §0, and the two verdicts an engineering report cannot
 *      reach on its own — `verified by CI` and `released to production` — are refused outright.
 *      CLAUDE.md §4.1: "every deferral grounded in an actual owner decision; an engineering report
 *      deferring something is not one."
 *
 * WHAT THIS FILE DOES NOT DO. It does not read the figures and it cannot: whether a cited test
 * really passed is a question for a run, not for a parser. It asserts that each cited path EXISTS,
 * that each verdict is sayable, and that the sets match. The figures in the report name the command
 * that produced them, which is the house rule the Phase 04 report states in its own opening.
 */
final class Phase05FinalReportTest extends TestCase
{
    private const REPORT = 'docs/development/phase-5/PHASE-05-FINAL-REPORT.md';

    private const APPEARANCE = 'docs/development/phase-5/PHASE-05-BILINGUAL-APPEARANCE-GATE.md';

    private const PLAN = 'docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md';

    private const DECISIONS = 'docs/development/phase-5/PHASE-05-OWNER-DECISION-REGISTER.md';

    private const MATRIX = 'docs/development/MASTER-PLAN-REQUIREMENT-TRACEABILITY.md';

    /**
     * THE CLOSED VERDICT VOCABULARY, and it is the PLAN's rather than this test's.
     *
     * `PHASE-05-EXECUTION-PLAN.md` §0 declares seven terms "used consistently from here on". Five
     * of them are verdicts a report can reach by reading code and running tests. Two are not, and
     * they are listed separately below precisely so they can be refused rather than forgotten.
     *
     * `NOT STARTED` is added because §5 requires it in terms — "work not started will be reported
     * as not started" — and §0's list, which is about states a row can be IN, does not contain it.
     *
     * @var list<string>
     */
    private const SAYABLE = [
        'NOT STARTED',
        'APPROVED POLICY',
        'IMPLEMENTED IN DEVELOPMENT',
        'VERIFIED BY LOCAL TESTS',
        'EXTERNALLY BLOCKED',
        'AWAITING HUMAN APPROVAL',
    ];

    /**
     * The two an engineering report cannot grant itself.
     *
     * `VERIFIED BY CI` needs GitHub's check-runs API for that head, which this report does not
     * have. `RELEASED TO PRODUCTION` is refused for the reason the plan gives in the same
     * paragraph — "which **nothing in this branch is**" — and CLAUDE.md's: no phase after 02 is
     * authorised to merge or release.
     *
     * @var list<string>
     */
    private const UNSAYABLE = [
        'VERIFIED BY CI',
        'RELEASED TO PRODUCTION',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function path(string $relative): string
    {
        return self::root() . '/' . $relative;
    }

    private function contents(string $relative): string
    {
        $path = $this->path($relative);

        $this->assertFileExists($path, sprintf(
            '%s does not exist. P05-24 is the report itself; without the file there is no per-slice '
            . 'completed/not-started statement and the traceability matrix goes on standing in for '
            . 'one.', $relative
        ));

        return (string) file_get_contents($path);
    }

    /**
     * The rows of one marked table, as lists of trimmed cells.
     *
     * SCOPED BETWEEN HTML COMMENT MARKERS, not matched across the whole document, for the reason
     * `Phase05BilingualAppearanceGateTest` gives at its own parser: an assertion over a whole file
     * answers "does this string exist anywhere", and CLAUDE.md §3 records eight occasions a census
     * was satisfied or broken by prose about the thing it measures. A marker pair is also invisible
     * in rendered Markdown, so the document reads normally.
     *
     * @return list<list<string>>
     */
    private function table(string $relative, string $marker, string $name): array
    {
        $doc = $this->contents($relative);

        $open = "<!-- {$marker}: {$name} -->";
        $close = '<!-- /' . explode(':', $marker)[0] . ' -->';

        $from = strpos($doc, $open);

        $this->assertNotFalse($from, sprintf(
            '%s carries no `%s` marker, so the %s table cannot be read. Tables are read between '
            . '`%s` and `%s`; prose outside them is not data.',
            $relative, $open, $name, $open, $close
        ));

        $to = strpos($doc, $close, $from);
        $this->assertNotFalse($to, "The {$name} table in {$relative} is not closed with `{$close}`.");

        $rows = [];
        $body = substr($doc, $from + strlen($open), $to - $from - strlen($open));

        foreach (explode("\n", $body) as $line) {
            $line = trim($line);

            if (! str_starts_with($line, '|') || ! str_ends_with($line, '|')) {
                continue;
            }

            $cells = array_map('trim', explode('|', trim($line, '|')));

            /* The header and its `|---|` separator are not data. */
            if ($cells === [] || str_starts_with($cells[0], '---') || $cells[0] === 'slice' || $cells[0] === 'requirement') {
                continue;
            }

            $rows[] = $cells;
        }

        return $rows;
    }

    /** @return list<string> the slice ids the appearance register declares, which the plan pins */
    private function declaredSlices(): array
    {
        $ids = [];

        foreach ($this->table(self::APPEARANCE, 'GATE-TABLE', 'SLICES') as $cells) {
            $ids[] = trim($cells[0], '`');
        }

        sort($ids);

        return $ids;
    }

    /** @return list<string> every `P05-*` requirement the traceability matrix carries */
    private function declaredRequirements(): array
    {
        $ids = [];

        preg_match_all('/^### `(P05-\d+)`/m', $this->contents(self::MATRIX), $found);

        foreach ($found[1] as $id) {
            $ids[] = $id;
        }

        sort($ids);

        return array_values(array_unique($ids));
    }

    #[Test]
    public function test_the_premise_that_every_document_this_gate_reads_exists(): void
    {
        foreach ([self::REPORT, self::APPEARANCE, self::PLAN, self::DECISIONS, self::MATRIX] as $relative) {
            $this->assertFileExists($this->path($relative));
        }

        /* REACHABILITY, so a rename cannot make this file pass vacuously -- the tenancy skill's §7
           fifth rule. If either source table stops yielding rows, every set comparison below would
           compare two empty lists and agree. */
        $this->assertNotSame([], $this->declaredSlices(), 'the appearance register yielded no slices');
        $this->assertNotSame([], $this->declaredRequirements(), 'the matrix yielded no requirements');
    }

    /**
     * EVERY SLICE HAS A VERDICT, AND NO VERDICT NAMES A SLICE THAT IS GONE.
     *
     * The second direction is the one that keeps the report honest as the plan moves: a verdict
     * outliving its slice is a claim about work that no longer exists, which is how
     * `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` came to disagree with the gap register for a day.
     */
    #[Test]
    public function test_every_plan_slice_has_an_explicit_verdict_and_no_verdict_is_orphaned(): void
    {
        $reported = [];

        foreach ($this->table(self::REPORT, 'REPORT-TABLE', 'VERDICTS') as $cells) {
            $reported[] = trim($cells[0], '`');
        }

        sort($reported);

        $this->assertSame($this->declaredSlices(), array_values(array_unique($reported)), sprintf(
            "The report's per-slice verdicts and the plan's slice list disagree.\n"
            . "  in the plan, no verdict: %s\n"
            . "  verdict, not in the plan: %s\n"
            . 'P05-24 asks for an explicit verdict per slice. A slice with none is the gap this row '
            . 'exists to close; a verdict with no slice is a claim about work that is gone.',
            implode(', ', array_diff($this->declaredSlices(), $reported)) ?: '(none)',
            implode(', ', array_diff($reported, $this->declaredSlices())) ?: '(none)'
        ));
    }

    /**
     * EVERY VERDICT IS SAYABLE, AND THE TWO THAT ARE NOT ARE REFUSED.
     */
    #[Test]
    public function test_no_verdict_claims_what_only_a_person_or_a_ci_run_can_grant(): void
    {
        foreach ($this->table(self::REPORT, 'REPORT-TABLE', 'VERDICTS') as $cells) {
            $slice = trim($cells[0], '`');
            $verdict = strtoupper($cells[2] ?? '');

            foreach (self::UNSAYABLE as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $verdict, sprintf(
                    '%s is reported as "%s". An engineering report cannot grant that: `VERIFIED BY '
                    . 'CI` needs GitHub\'s check-runs API for the head, and `RELEASED TO PRODUCTION` '
                    . 'is what the plan\'s own §0 says nothing in this branch is.',
                    $slice, $cells[2] ?? ''
                ));
            }

            $known = false;

            foreach (self::SAYABLE as $allowed) {
                if (str_contains($verdict, $allowed)) {
                    $known = true;

                    break;
                }
            }

            $this->assertTrue($known, sprintf(
                '%s\'s verdict "%s" is outside the closed vocabulary the plan declares in §0: %s. '
                . 'A new word is a new claim; add it to the plan first.',
                $slice, $cells[2] ?? '', implode(' / ', self::SAYABLE)
            ));
        }
    }

    /**
     * EVERY CITED PIECE OF EVIDENCE IS A PATH THAT EXISTS.
     *
     * Not "the test passed" -- that is a question for a run, and this file says so in its own
     * docblock. What it refuses is a citation that cannot be opened, which is the commonest way a
     * report's evidence column becomes decoration: a test file renamed, and the claim left behind.
     */
    #[Test]
    public function test_every_cited_evidence_path_exists(): void
    {
        $cited = 0;

        foreach ($this->table(self::REPORT, 'REPORT-TABLE', 'VERDICTS') as $cells) {
            $slice = trim($cells[0], '`');
            $evidence = $cells[4] ?? '';

            preg_match_all('#`((?:tests|docs|app|resources)/[^`\s]+?\.(?:php|md|json))`#', $evidence, $paths);

            $this->assertNotSame([], $paths[1], sprintf(
                '%s cites no evidence path at all. P05-24 asks for the verdict "with evidence"; a '
                . 'verdict whose evidence column names no file is the per-slice claim this row '
                . 'already rejects. If a slice genuinely has none, say so in the `not established` '
                . 'column and cite the document that records why.',
                $slice
            ));

            foreach ($paths[1] as $relative) {
                $cited++;

                $this->assertFileExists($this->path($relative), sprintf(
                    '%s cites `%s`, which does not exist. A renamed test leaves the claim behind.',
                    $slice, $relative
                ));
            }
        }

        $this->assertGreaterThan(20, $cited,
            'the evidence columns cite almost nothing, which is what a report of intentions looks like');
    }

    /**
     * EVERY BLOCKER NAMES A DECISION THE OWNER'S REGISTER REALLY CARRIES.
     *
     * CLAUDE.md §4.1: "every deferral grounded in an actual owner decision; an engineering report
     * deferring something is not one." So a blocker is an id in the register, not a sentence here.
     */
    #[Test]
    public function test_every_blocker_names_a_decision_the_register_carries(): void
    {
        /*
         * THREE REGISTERS, NOT ONE, AND THE FIRST VERSION OF THIS GATE KNEW ABOUT ONE.
         *
         * Phase 05 blockers legitimately come from three places: `D-N*` and `D1`/`D2`/`D6` from the
         * phase's own owner decision register, and the RECOVERED `MKT-*` / `ANA-*` ids, which are
         * traced in `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` and were never copied into the phase
         * register. Checking only the first refused `MKT-005`, which is a real recovered decision.
         *
         * IT STILL REFUSES AN ID NO REGISTER CARRIES, which is the point: it caught `D3` and `D5`
         * in this report's own `P5-14` row. Those are PR #140's internal labels as the execution
         * plan uses them -- `D3` in `OWNER-DECISION-PACKAGE.md` is a different question (the Arabic
         * catalogue) and `D5` is in no register at all -- so the row was wrong and was corrected
         * rather than the gate widened to admit it. CLAUDE.md §4.1: an engineering report deferring
         * something is not an owner decision, and a label it invented is not one either.
         */
        $register = $this->contents(self::DECISIONS)
            . $this->contents(self::MATRIX)
            . $this->contents('docs/development/OWNER-DECISION-PACKAGE.md');
        $named = 0;

        foreach ($this->table(self::REPORT, 'REPORT-TABLE', 'VERDICTS') as $cells) {
            $slice = trim($cells[0], '`');

            preg_match_all('/\b((?:D-N|GAP-|P4-F|MKT-|ANA-)\d+|D\d+)\b/', $cells[3] ?? '', $ids);

            foreach ($ids[1] as $id) {
                /* GAP and P4-F ids live in their own registers, which this gate does not read; the
                   decision ids are the ones it can verify, and they are the ones §4.1 is about. */
                if (! preg_match('/^(D-N\d+|D\d+|MKT-\d+|ANA-\d+)$/', $id)) {
                    continue;
                }

                $named++;

                $this->assertStringContainsString($id, $register, sprintf(
                    '%s is reported as blocked by `%s`, which the owner decision register does not '
                    . 'carry. An engineering report cannot create a decision id to defer behind.',
                    $slice, $id
                ));
            }
        }

        $this->assertGreaterThan(0, $named,
            'no slice names a decision id, although the register carries open Phase 05 decisions. '
            . 'Either every blocker has cleared -- in which case say so -- or the blockers are '
            . 'being described in prose instead of named.');
    }

    /**
     * EVERY PHASE 05 REQUIREMENT IS CARRIED, SLICE OR NO SLICE.
     *
     * This is the assertion that stops the slice list standing as the definition of scope, which is
     * `P05-11`'s named failure in its own words and this row's second instruction.
     */
    #[Test]
    public function test_every_phase_05_requirement_is_carried_whether_or_not_a_slice_owns_it(): void
    {
        $reported = [];

        foreach ($this->table(self::REPORT, 'REPORT-TABLE', 'REQUIREMENTS') as $cells) {
            $reported[] = trim($cells[0], '`');
        }

        sort($reported);
        $reported = array_values(array_unique($reported));

        $this->assertSame($this->declaredRequirements(), $reported, sprintf(
            "The report's requirement rows and the traceability matrix disagree.\n"
            . "  in the matrix, not in the report: %s\n"
            . "  in the report, not in the matrix: %s\n"
            . 'A requirement the report does not carry is one the slice list has silently defined '
            . 'away -- P05-11 records exactly that happening to loyalty.',
            implode(', ', array_diff($this->declaredRequirements(), $reported)) ?: '(none)',
            implode(', ', array_diff($reported, $this->declaredRequirements())) ?: '(none)'
        ));
    }

    /**
     * AND THE ONES WITH NO OWNING SLICE ARE COUNTED EXACTLY, in both directions.
     *
     * The row asks for "the conversion and marketing-performance requirements that still have
     * none". Measured against the matrix's own headings there are THIRTEEN with no owning slice,
     * not two, so the count is asserted rather than the two names: a figure that only bounds from
     * above lets the set grow quietly.
     */
    #[Test]
    public function test_the_requirements_with_no_owning_slice_are_counted_exactly(): void
    {
        $matrix = $this->contents(self::MATRIX);
        $withoutSlice = [];

        preg_match_all('/^### `(P05-\d+)`\s*—\s*(.*)$/m', $matrix, $found, PREG_SET_ORDER);

        foreach ($found as $row) {
            if (! preg_match('/\bP5-\d+\b/', $row[2])) {
                $withoutSlice[] = $row[1];
            }
        }

        sort($withoutSlice);

        $reported = [];

        foreach ($this->table(self::REPORT, 'REPORT-TABLE', 'REQUIREMENTS') as $cells) {
            if (trim($cells[1] ?? '', '`') === '—') {
                $reported[] = trim($cells[0], '`');
            }
        }

        sort($reported);

        $this->assertSame($withoutSlice, array_values(array_unique($reported)), sprintf(
            "The report and the matrix disagree about which requirements have NO owning slice.\n"
            . "  matrix says no slice, report gives one: %s\n"
            . "  report says no slice, matrix gives one: %s\n"
            . 'This is the set the row asks to be carried. Both directions matter: a requirement '
            . 'that gains a slice must stop being listed here, or the report keeps asking for work '
            . 'that is now owned.',
            implode(', ', array_diff($withoutSlice, $reported)) ?: '(none)',
            implode(', ', array_diff($reported, $withoutSlice)) ?: '(none)'
        ));
    }

    /**
     * THE REPORT STATES THE FOUR STATES AND SAYS WHICH IT DOES NOT ESTABLISH.
     *
     * CLAUDE.md §4.1: "Four states, never one label: engineering complete · original requirement
     * coverage · human acceptance · production readiness." A report that gives one verdict per
     * slice and nothing else has collapsed them again.
     */
    #[Test]
    public function test_the_report_separates_the_four_states_and_disclaims_the_two_it_cannot_reach(): void
    {
        $report = $this->contents(self::REPORT);

        foreach ([
            'engineering complete',
            'original requirement coverage',
            'human acceptance',
            'production readiness',
        ] as $state) {
            $this->assertStringContainsString($state, strtolower($report), sprintf(
                'The report does not name the state "%s". CLAUDE.md §4.1 requires all four, never '
                . 'one label.', $state
            ));
        }

        /* AND IT SAYS SO ABOUT ITSELF. A disclaimer in prose is weak evidence, but its ABSENCE is
           strong evidence of the collapse this rule exists to prevent. */
        $this->assertMatchesRegularExpression(
            '/not (?:released|release-ready|accepted|authorised)/i',
            $report,
            'The report nowhere says that nothing here is accepted or released. The plan\'s §0 says '
            . 'it of the whole branch and CLAUDE.md says no phase after 02 is authorised to merge '
            . 'or release; a report that omits it is the exact conflation §0 forbids.'
        );
    }
}
