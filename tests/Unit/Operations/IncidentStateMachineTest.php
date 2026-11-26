<?php

namespace Tests\Unit\Operations;

use App\Support\Operations\IncidentSeverity;
use App\Support\Operations\IncidentState;
use App\Support\Operations\PlatformIncidentPolicy;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * OPS-003 — THE INCIDENT LIFECYCLE, ASSERTED IN BOTH DIRECTIONS.
 * =================================================================================================
 *
 * `IncidentState` exists because a string comparison is not a state machine, and this file exists
 * because a state machine nobody asserts the REFUSALS of is a list of suggestions. One direction
 * alone always fails the same way: "the forward moves work" lets any move work, and "this one move
 * is refused" lets everything else through. So the allowed set is asserted EXACTLY and every move
 * outside it is driven and refused.
 *
 * THE MOVES THAT MUST BE REFUSED ARE THE POINT. Three in particular:
 *
 *   mitigated -> acknowledged   going backwards. A mitigation that stops working is a new fact
 *                               about a live incident and belongs in a note.
 *   acknowledged -> open        the same, and the one a careless `status` assignment produces.
 *   resolved -> resolved        and every other self-move. A repeated "resolve" click would
 *                               otherwise write a second resolution event and drag `resolved_at`
 *                               forward, making the incident look as though it had been resolved
 *                               later than it was.
 *
 * AND THE ONE BACKWARD MOVE THAT IS ALLOWED is asserted as allowed, because it is a real
 * operational event: an incident declared resolved that turns out not to be is ONE incident that
 * was reopened, not a second incident with no link to the first.
 */
final class IncidentStateMachineTest extends TestCase
{
    use \Tests\Support\StripsSourceComments;

    /*
     * =============================================================================================
     * The vocabulary
     * =============================================================================================
     */

    /** The states, exactly, in lifecycle order — which is what a screen lists them in. */
    public function test_the_states_are_exactly_these_four_in_lifecycle_order(): void
    {
        $this->assertSame(['open', 'acknowledged', 'mitigated', 'resolved'], IncidentState::all());
    }

    public function test_only_a_declared_state_exists(): void
    {
        foreach (IncidentState::all() as $state) {
            $this->assertTrue(IncidentState::exists($state));
        }

        foreach (['', 'OPEN', 'closed', 'pending', 'done', 'in_progress'] as $invented) {
            $this->assertFalse(IncidentState::exists($invented),
                "'{$invented}' is not a declared state and was accepted as one");
        }
    }

    /** Live means being worked on. `resolved` is the only one that is not, and it is reopenable. */
    public function test_every_state_but_resolved_is_live(): void
    {
        $this->assertTrue(IncidentState::isLive(IncidentState::OPEN));
        $this->assertTrue(IncidentState::isLive(IncidentState::ACKNOWLEDGED));
        $this->assertTrue(IncidentState::isLive(IncidentState::MITIGATED));
        $this->assertFalse(IncidentState::isLive(IncidentState::RESOLVED));
        $this->assertFalse(IncidentState::isLive('invented'),
            'an undeclared state was reported as a live incident');
    }

    /*
     * =============================================================================================
     * The moves
     * =============================================================================================
     */

    /**
     * THE ALLOWED SET, EXACTLY. `assertSame` over the whole table and not a handful of
     * `assertTrue`s, so a move ADDED without anybody thinking about it fails here too.
     */
    public function test_the_allowed_moves_are_exactly_these(): void
    {
        $this->assertSame([
            'open' => ['acknowledged', 'mitigated', 'resolved'],
            'acknowledged' => ['mitigated', 'resolved'],
            'mitigated' => ['resolved'],
            'resolved' => ['open'],
        ], IncidentState::transitions());
    }

    /**
     * EVERY MOVE NOT IN THE TABLE IS REFUSED, driven over the whole cross product rather than
     * over the three a reader thinks of. 16 pairs, 7 allowed, 9 refused, and the refusals include
     * all four self-moves.
     */
    public function test_every_move_outside_the_declared_set_is_refused(): void
    {
        $allowed = IncidentState::transitions();
        $refused = 0;
        $permitted = 0;

        foreach (IncidentState::all() as $from) {
            foreach (IncidentState::all() as $to) {
                $shouldAllow = in_array($to, $allowed[$from], true);

                $this->assertSame($shouldAllow, IncidentState::canMove($from, $to),
                    $shouldAllow
                        ? "{$from} -> {$to} is declared allowed and was refused"
                        : "{$from} -> {$to} is NOT declared and was allowed");

                $shouldAllow ? $permitted++ : $refused++;
            }
        }

        $this->assertSame(7, $permitted, 'the number of allowed moves changed');
        $this->assertSame(9, $refused, 'the number of refused moves changed');
    }

    /** A self-move is refused for every state, named, because it is the one a double click makes. */
    public function test_a_state_cannot_move_to_itself(): void
    {
        foreach (IncidentState::all() as $state) {
            $this->assertFalse(IncidentState::canMove($state, $state),
                "{$state} -> {$state} was allowed. A repeated click would write a second event and "
                . 'drag the stamp forward, making the incident look as though it reached that state '
                . 'later than it did.');
        }
    }

    /** The reopen is allowed, and is the ONLY backward move. */
    public function test_the_reopen_is_the_only_backward_move(): void
    {
        $this->assertTrue(IncidentState::canMove(IncidentState::RESOLVED, IncidentState::OPEN),
            'a resolved incident cannot be reopened, so an incident that turns out not to be '
            . 'finished has to be recorded twice with no link between the two');

        foreach ([
            [IncidentState::ACKNOWLEDGED, IncidentState::OPEN],
            [IncidentState::MITIGATED, IncidentState::OPEN],
            [IncidentState::MITIGATED, IncidentState::ACKNOWLEDGED],
        ] as [$from, $to]) {
            $this->assertFalse(IncidentState::canMove($from, $to),
                "{$from} -> {$to} was allowed, so the history can be walked backwards");
        }
    }

    /** An undeclared state on either side is refused rather than throwing at a call site. */
    public function test_an_undeclared_state_moves_nowhere(): void
    {
        $this->assertFalse(IncidentState::canMove('invented', IncidentState::OPEN));
        $this->assertFalse(IncidentState::canMove(IncidentState::OPEN, 'invented'));
        $this->assertFalse(IncidentState::canMove('invented', 'invented'));
    }

    public function test_asking_for_the_moves_from_an_undeclared_state_is_an_error(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IncidentState::movesFrom('invented');
    }

    /*
     * =============================================================================================
     * The stamps
     * =============================================================================================
     */

    /**
     * EACH STATE STAMPS ITS OWN COLUMN, AND `open` AND THE REOPEN STAMP NOTHING.
     *
     * `open` has no column because `detected_at` is a different fact — an incident can be recorded
     * hours after it began — and `created_at` already says when the row was written. The reopen
     * stamps nothing because it must not un-resolve the time the incident WAS resolved, which is a
     * real moment that happened.
     */
    public function test_each_state_stamps_its_own_column_and_open_stamps_none(): void
    {
        $this->assertNull(IncidentState::stampedColumn(IncidentState::OPEN),
            'reaching `open` stamped a column. The reopen would then overwrite a real moment.');
        $this->assertSame('acknowledged_at', IncidentState::stampedColumn(IncidentState::ACKNOWLEDGED));
        $this->assertSame('mitigated_at', IncidentState::stampedColumn(IncidentState::MITIGATED));
        $this->assertSame('resolved_at', IncidentState::stampedColumn(IncidentState::RESOLVED));
    }

    /** No two states share a stamp, which a hand-written match could easily do. */
    public function test_no_two_states_stamp_the_same_column(): void
    {
        $columns = array_filter(array_map(
            static fn (string $s): ?string => IncidentState::stampedColumn($s),
            IncidentState::all()
        ));

        $this->assertSame(array_values($columns), array_values(array_unique($columns)),
            'two states stamp the same column, so one of them records the wrong moment');
    }

    /*
     * =============================================================================================
     * The words
     * =============================================================================================
     */

    /** Every state has a label and a description, and both are sentences rather than the key. */
    public function test_every_state_reads_as_words_and_not_as_its_key(): void
    {
        foreach (IncidentState::all() as $state) {
            $this->assertNotSame($state, IncidentState::label($state),
                "the label for '{$state}' is its own machine key, so the screen prints the key");
            $this->assertNotSame('', trim(IncidentState::description($state)));
        }
    }

    /**
     * `mitigated` AND `resolved` SAY DIFFERENT THINGS, which is the distinction the whole
     * vocabulary turns on: a queue drained by restarting a worker is mitigated; it is resolved when
     * the worker stops dying. A product that collapsed the two would lose the record of what it has
     * not actually fixed.
     */
    public function test_mitigated_and_resolved_do_not_describe_the_same_thing(): void
    {
        $this->assertNotSame(
            IncidentState::description(IncidentState::MITIGATED),
            IncidentState::description(IncidentState::RESOLVED),
            'mitigated and resolved describe the same thing, so the platform cannot record what it '
            . 'has stopped the impact of but not fixed'
        );
        $this->assertStringContainsStringIgnoringCase('cause', IncidentState::description(IncidentState::MITIGATED),
            'the mitigated description does not say that the cause may be unfound, which is the '
            . 'only thing that tells it from resolved');
    }

    /** The verb on a button is not the name of the state, and the reopen says reopen. */
    public function test_the_move_verb_names_the_action_and_the_reopen_says_reopen(): void
    {
        $this->assertSame(__('Reopen'),
            IncidentState::moveLabel(IncidentState::RESOLVED, IncidentState::OPEN));
        $this->assertSame(__('Acknowledge'),
            IncidentState::moveLabel(IncidentState::OPEN, IncidentState::ACKNOWLEDGED));
        $this->assertNotSame(IncidentState::label(IncidentState::MITIGATED),
            IncidentState::moveLabel(IncidentState::OPEN, IncidentState::MITIGATED),
            'the button reads as the name of a state rather than as the action it performs');
    }

    public function test_an_undeclared_state_has_no_label(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IncidentState::label('invented');
    }

    /*
     * =============================================================================================
     * Severity
     * =============================================================================================
     */

    public function test_the_severities_are_exactly_these_four_worst_first(): void
    {
        $this->assertSame(['critical', 'major', 'minor', 'informational'], IncidentSeverity::all());
    }

    /** The rank is derived from the declared order, so no two levels can share one. */
    public function test_the_rank_is_one_for_the_worst_and_is_unique_per_level(): void
    {
        $this->assertSame(1, IncidentSeverity::rank(IncidentSeverity::CRITICAL));

        $ranks = array_map(
            static fn (string $s): int => IncidentSeverity::rank($s),
            IncidentSeverity::all()
        );

        $this->assertSame([1, 2, 3, 4], $ranks);
        $this->assertSame($ranks, array_values(array_unique($ranks)));
    }

    public function test_only_a_declared_severity_exists(): void
    {
        foreach (['', 'CRITICAL', 'high', 'low', 'sev1', 'urgent'] as $invented) {
            $this->assertFalse(IncidentSeverity::exists($invented),
                "'{$invented}' is not a declared severity and was accepted as one");
        }
    }

    /**
     * NO SEVERITY CARRIES A RESPONSE COMMITMENT, and this is the assertion that keeps it that way.
     *
     * A response time is a promise Store Syria would be making to its merchants, which `CLAUDE.md`
     * section 2 reserves to the owner. The vocabulary describes IMPACT; what follows from it is
     * `PlatformIncidentPolicy::openDecisions()['response_targets']` and is unanswered.
     *
     * A WORD CENSUS AND NOT AN EYEBALL, because the way this gets crossed is somebody adding
     * "respond within 15 minutes" to a description as a helpful hint.
     */
    public function test_no_severity_states_a_response_time_or_an_escalation(): void
    {
        foreach (IncidentSeverity::all() as $severity) {
            $description = IncidentSeverity::description($severity) . ' ' . IncidentSeverity::label($severity);

            foreach ([
                'minute', 'hour', 'immediately', 'within', 'escalat', 'on-call', 'on call',
                'paged', 'notify', 'sla', 'respond',
            ] as $commitment) {
                $this->assertStringNotContainsStringIgnoringCase($commitment, $description, sprintf(
                    'the %s severity says "%s", which states an operational commitment. What a '
                    . 'severity obliges is an owner decision (PlatformIncidentPolicy: '
                    . 'response_targets) and this vocabulary describes impact only.',
                    $severity,
                    $commitment
                ));
            }
        }
    }

    /** Each level describes a different impact; four levels that said the same thing would be one. */
    public function test_each_severity_describes_a_different_impact(): void
    {
        $descriptions = array_map(
            static fn (string $s): string => IncidentSeverity::description($s),
            IncidentSeverity::all()
        );

        $this->assertSame($descriptions, array_values(array_unique($descriptions)));
    }

    /*
     * =============================================================================================
     * The policy that is deliberately absent
     * =============================================================================================
     */

    /**
     * NOTHING ON THIS PLATFORM ALERTS ANYBODY, AND TWO SCREENS SAY SO IN WORDS.
     *
     * This is the assertion that keeps those words honest: the day somebody wires an alert, this
     * goes red and the sentences get changed with the code. It is not a statement that alerting
     * should never be built — it is a statement that building it is a change to what the product
     * TELLS AN OPERATOR, and must not happen silently.
     */
    public function test_the_product_still_alerts_nobody_and_says_so(): void
    {
        $this->assertFalse(PlatformIncidentPolicy::isAlertingConfigured(),
            'something now alerts somebody. Two operator screens state in words that nothing does. '
            . 'Change those sentences, and this assertion, in the same commit as the alert.');

        $this->assertStringContainsString('sends an alert', PlatformIncidentPolicy::noAlertingNotice());
    }

    /**
     * EVERY OPEN DECISION IS NAMED PRECISELY, which is what makes it answerable.
     *
     * "Needs a decision" is worth nothing to an owner. Each entry has to say what the question is,
     * what answering it enables and what answering it by accident costs — and the last of those is
     * the one that gets left out, so it is asserted.
     */
    public function test_every_open_decision_states_the_question_and_the_cost_of_guessing(): void
    {
        $decisions = PlatformIncidentPolicy::openDecisions();

        $this->assertGreaterThanOrEqual(5, count($decisions),
            'the register of unanswered alerting decisions has shrunk; a question that has been '
            . 'answered is recorded as answered, not deleted');

        foreach ($decisions as $id => $decision) {
            $this->assertSame(['question', 'enables', 'cost_of_guessing'], array_keys($decision),
                "the '{$id}' decision does not carry all three parts");

            foreach ($decision as $part => $text) {
                $this->assertGreaterThan(40, strlen($text),
                    "the '{$id}' decision's {$part} is a phrase rather than a statement an owner "
                    . 'could answer');
            }

            $this->assertStringContainsString('?', $decision['question'],
                "the '{$id}' entry's question is not a question");
        }

        foreach (['thresholds', 'recipients', 'response_targets', 'merchant_visibility', 'retention'] as $required) {
            $this->assertArrayHasKey($required, $decisions,
                "the '{$required}' decision is no longer in the register");
        }
    }

    /**
     * NO THRESHOLD IS DECLARED ANYWHERE IN THE OPERATIONS CODE.
     *
     * The thing that would make all of the above untrue is a number: 85, 1000, 5. A threshold is
     * the easiest thing in the world to invent and the moment one is in the code it is quoted back
     * as the platform's rule — including to merchants.
     *
     * COMMENT-STRIPPED, AND LOAD-BEARING: every one of these files discusses thresholds at length
     * precisely because it has none, and `CLAUDE.md` records eight occasions on which a census was
     * satisfied — or broken — by prose about the thing it measures.
     *
     * AND THE FIRST VERSION OF THIS CASE FAILED ON `PlatformIncidentPolicy` ITSELF, which is the
     * same trap one level in: that file's REGISTER OF UNANSWERED QUESTIONS contains the word
     * `thresholds` as a key and the question "at what measured value…" as a value, and those are
     * code rather than comments, so stripping the comments did not help. The register naming the
     * decision is the opposite of the decision having been taken.
     *
     * SO THE POLICY FILE IS SCANNED FOR A DIFFERENT AND STRICTER PROPERTY: it may contain no
     * numeric literal at all. A threshold IS a number, so "no numbers in this file" admits the
     * question and refuses every possible answer to it — and it cannot be evaded by naming a
     * constant something other than the eight spellings below, which is `CLAUDE.md`'s rule about
     * enumerating the safe set rather than the dangerous one.
     */
    public function test_no_threshold_is_declared_in_the_operations_code(): void
    {
        $policy = 'app/Support/Operations/PlatformIncidentPolicy.php';

        $measurementFiles = [
            'app/Services/SaasPlatformOperations.php',
            'app/Services/SaasOperationsProbe.php',
            'app/Services/SaasPlatformResources.php',
            'app/Services/PlatformIncidentLog.php',
            'app/Support/Operations/IncidentSeverity.php',
            'app/Support/Operations/IncidentState.php',
        ];

        foreach ($measurementFiles as $file) {
            $code = $this->sourceWithoutComments($file);

            foreach ([
                'THRESHOLD', 'WARNING_AT', 'CRITICAL_AT', 'ALERT_AT', 'MAX_QUEUE', 'MAX_DEPTH',
                'DISK_LIMIT', 'WARN_PERCENT',
            ] as $constant) {
                $this->assertStringNotContainsString($constant, strtoupper($code), sprintf(
                    '%s declares something named like a threshold (%s). What measured value '
                    . 'constitutes an incident is an owner decision that has not been taken '
                    . '(PlatformIncidentPolicy: thresholds), and a number in the code is read as '
                    . 'the platform\'s rule.',
                    $file,
                    $constant
                ));
            }
        }

        /*
         * THE POLICY FILE: THE QUESTIONS ARE WELCOME, A NUMBER IS NOT. Every digit in its code is
         * reported, with the line it is on, because the only reason a number would appear in a file
         * whose entire subject is an undecided threshold is that somebody decided it.
         */
        $policyCode = $this->sourceWithoutComments($policy);

        $this->assertStringContainsString('thresholds', $policyCode,
            'the policy register no longer names the threshold decision at all, so the question is '
            . 'no longer recorded anywhere in the code');

        $digits = [];

        foreach (explode("\n", $policyCode) as $number => $line) {
            if (preg_match('/\d/', $line) === 1) {
                $digits[] = ($number + 1) . ': ' . trim($line);
            }
        }

        $this->assertSame([], $digits, sprintf(
            "%s contains a numeric literal:\n  %s\nA threshold is a number, and this file's whole "
            . 'subject is that the thresholds have not been chosen. If one has now been chosen, it '
            . 'was chosen by the owner and this assertion is the place that records it.',
            $policy,
            implode("\n  ", $digits)
        ));
    }
}
