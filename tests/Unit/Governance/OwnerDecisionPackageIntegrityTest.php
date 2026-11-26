<?php

namespace Tests\Unit\Governance;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE OWNER DECISION PACKAGE MUST BE ADDRESSABLE BY ITS OWN NUMBERS.
 * =================================================================================================
 *
 * WHY THIS EXISTS, and it is a defect that nearly shipped rather than a hypothetical.
 *
 * Two workstreams drafted an owner-package entry in the same hour. One numbered it `J16` against a
 * branch that ended at `J15`; by the time it reported, the development branch had advanced and
 * `J16`, `J17`, `J18` and `J19` existed. **Git would have merged two different `J16` entries into
 * one file without a conflict** -- both are additions in different places -- and one of the two
 * would then have been un-findable by its own number: every reference to "J16" in a gap row, a test
 * failure message or an owner's reply would have resolved to whichever one the reader scrolled to
 * first. It was caught by the author noticing, not by any check. That is the gap this file closes.
 *
 * The package is the document a human signs. A duplicate id in it is worse than a duplicate
 * anywhere else in this repository, because the whole point of the id is that the owner can say
 * "do J17" and mean one thing.
 *
 * TWO ASSERTIONS, AND THE SECOND IS THE ONE THAT CAUGHT A REAL OMISSION.
 *
 *   1. Every heading id is unique. This is the merge hazard above.
 *
 *   2. Every id the GAP REGISTER says is "carried as OWNER-DECISION-PACKAGE.md <id>" must actually
 *      EXIST in the package. `GAP-257`'s disposition read "belongs with an owner decision" for
 *      weeks while the package contained no entry for it at all -- so a row that looked escalated
 *      was never put to anybody. A register row can promise an escalation; only this assertion
 *      makes the promise checkable.
 *
 * WHY NOT ALSO ASSERT CONTIGUITY (no gaps in J1..Jn). Deliberately not: an entry that is DECIDED
 * may be moved out of the open set, and a hole is then correct. Uniqueness and existence are the
 * properties that matter; density is not.
 */
class OwnerDecisionPackageIntegrityTest extends TestCase
{
    private const PACKAGE = 'docs/development/OWNER-DECISION-PACKAGE.md';
    private const REGISTER = 'docs/development/MASTER-PLAN-GAP-REGISTER.md';

    /**
     * Every decision id the package actually declares, in document order, duplicates kept.
     *
     * THE DOCUMENT CARRIES TWO SHAPES, AND THE FIRST VERSION OF THIS TEST KNEW ONLY ONE. Sections
     * A-I declare their decisions as TABLE ROWS (`| **I13** | ... |`); section J declares them as
     * HEADINGS (`### J14 - ...`). Matching headings alone reported EIGHT register citations as
     * missing entries, seven of which exist a few hundred lines above in table form -- a census
     * blind to half its subject, which is the exact failure this repository has recorded eight
     * times, and which READS AS A FINDING rather than as a bug in the instrument. Caught on this
     * test's first run, before the eight became a reported defect.
     *
     * Both shapes are matched, and a premise case asserts both are non-empty, so neither can go
     * silently to zero if the document is restructured again.
     *
     * @return list<string>
     */
    private function headingIds(): array
    {
        return array_merge($this->sectionHeadingIds(), $this->tableRowIds());
    }

    /** @return list<string> section J's shape: `### J14 - ...` */
    private function sectionHeadingIds(): array
    {
        $doc = (string) file_get_contents(base_path(self::PACKAGE));

        preg_match_all('/^#{3,4} +`?([A-Z][0-9]+)`?\b/m', $doc, $m);

        return $m[1];
    }

    /** @return list<string> sections A-I's shape: a table row whose first cell is the bolded id */
    private function tableRowIds(): array
    {
        $doc = (string) file_get_contents(base_path(self::PACKAGE));

        preg_match_all('/^\| +\*\*`?([A-Z][0-9]+)`?\*\* +\|/m', $doc, $m);

        return $m[1];
    }

    #[Test]
    public function test_premise_both_declaration_shapes_are_present(): void
    {
        $this->assertNotEmpty($this->tableRowIds(),
            'No `| **<id>** |` table rows found. Sections A-I declare their decisions that way, so '
            . 'either the document was restructured or this pattern has gone blind -- and a blind '
            . 'pattern makes every citation to those sections look like an un-escalated row.');

        $this->assertNotEmpty($this->sectionHeadingIds(),
            'No `### <id>` headings found. Section J declares its decisions that way.');
    }

    #[Test]
    public function test_every_owner_decision_id_is_unique(): void
    {
        $ids = $this->headingIds();

        $this->assertNotEmpty($ids,
            'No `### <Letter><number>` headings were found in ' . self::PACKAGE . '. Either the '
            . 'document changed shape or this test is reading the wrong file -- a census that '
            . 'cannot see its subject reports a clean result, which is the failure mode this '
            . 'repository has recorded eight times.');

        $seen = [];
        $duplicates = [];

        foreach ($ids as $id) {
            if (isset($seen[$id])) {
                $duplicates[$id] = ($duplicates[$id] ?? 1) + 1;
            }
            $seen[$id] = true;
        }

        $this->assertSame([], $duplicates, sprintf(
            "The owner decision package carries the same id more than once: %s.\n\n"
            . "GIT WILL NOT CATCH THIS. Two entries added in different places are both additions, "
            . "so a merge produces one file with two headings carrying the same number and no "
            . "conflict to resolve. One of them is then un-findable by its own number, and every "
            . "reference to it -- in a gap row, in a test's failure message, in the owner's reply "
            . "-- resolves to whichever the reader reaches first.\n\n"
            . "Renumber the LATER one to the next free number, and say in the entry that it was "
            . "renumbered and what the commits behind it call it. Then fix every reference: the "
            . "register row, and any test failure message that names it, because those messages "
            . "are the instructions somebody follows when the decision is taken.",
            implode(', ', array_map(
                fn ($id, $n) => "{$id} (x{$n})",
                array_keys($duplicates),
                $duplicates
            ))
        ));
    }

    #[Test]
    public function test_every_decision_the_gap_register_escalates_exists_in_the_package(): void
    {
        $register = (string) file_get_contents(base_path(self::REGISTER));

        // The register's own spelling: "carried as `OWNER-DECISION-PACKAGE.md` I13", with or
        // without backticks and with or without a section marker.
        /*
         * ANCHORED AT THE LEFT EDGE ON PURPOSE. There are TWO owner-decision packages with
         * separate id spaces -- this one (`A1`..`J19`) and
         * `docs/development/phase-5/PHASE-05-OWNER-DECISION-PACKAGE.md` (`D1`..`D8`). An unanchored
         * `OWNER-DECISION-PACKAGE\.md` matches the phase-5 filename as a SUFFIX, so the first
         * version of this pattern read a phase-5 citation as a citation of this document and
         * reported its id as missing. That was a false positive on top of the false negative the
         * two declaration shapes produced -- the same census wrong in both directions at once,
         * which is why both corrections are recorded here rather than quietly applied.
         *
         * `(?<![A-Z0-9-])` keeps `PHASE-05-OWNER-DECISION-PACKAGE.md` out. A section reference
         * (`§2`) is deliberately NOT matched: a section number is not a decision id, and reading
         * one as an id is exactly the mis-transcription this test found in `GAP-191`.
         */
        preg_match_all('/(?<![A-Z0-9-])OWNER-DECISION-PACKAGE\.md`?\s+`?([A-Z][0-9]+)`?/', $register, $m);

        $cited = array_values(array_unique($m[1]));

        $this->assertNotEmpty($cited,
            'The gap register cites no owner-package ids at all. It cited 14 when this test was '
            . 'written, so either the citation spelling changed or this pattern no longer matches '
            . '-- and a pattern that matches nothing passes this test for the wrong reason.');

        $present = $this->headingIds();
        $missing = array_values(array_diff($cited, $present));

        sort($missing);

        $this->assertSame([], $missing, sprintf(
            "The gap register says these decisions are carried in the owner package, and the "
            . "package has no entry for them: %s.\n\n"
            . "A row that says \"owner decision\" and names an id that does not exist has NOT been "
            . "escalated -- it only looks escalated. GAP-257 sat in exactly that state: its "
            . "disposition read \"belongs with an owner decision\" while the package contained "
            . "nothing for it, so the question was never put to anybody.\n\n"
            . "Either write the entry (with the figures, so the owner can decide without "
            . "re-deriving them), or stop claiming the row is carried there.",
            implode(', ', $missing)
        ));
    }
}
