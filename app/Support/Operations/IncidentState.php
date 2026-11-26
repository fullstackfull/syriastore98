<?php

namespace App\Support\Operations;

use InvalidArgumentException;

/**
 * OPS-003 — THE LIFE OF A PLATFORM INCIDENT, AND THE MOVES THAT ARE REALLY ALLOWED.
 * =================================================================================================
 *
 * The recovered requirement is "إدارة الحوادث والتنبيهات والتشخيص" — incident, alert and
 * diagnostics management — and it splits in two along a line this class is on one side of:
 *
 *   THE RECORD is buildable and is built. A row, a vocabulary, the moves between its states, who
 *   made each move and when. None of that needs a decision from anybody: it is bookkeeping about
 *   something that has already happened.
 *
 *   THE POLICY is not, and nothing here invents it. What threshold opens an incident, who is
 *   notified, how fast each severity must be answered — those are the platform's operational
 *   commitments and they are the owner's to make. `PlatformIncidentPolicy` records the questions
 *   and the fact that they are unanswered; this class attaches no obligation to any state or any
 *   severity.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 * A STRING COMPARISON IS NOT A STATE MACHINE, AND THAT IS WHY THIS FILE EXISTS
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 *
 * This codebase already has the failure this prevents. `FulfilmentStateMachine` was written because
 * order fulfilment was moved by whatever string a handler happened to assign, so an order could go
 * from delivered back to pending and the record of how it got there did not exist. An incident log
 * whose status is assignable is worse, because the whole value of the log is the SEQUENCE: an
 * incident that reads "resolved" with no acknowledgement and no mitigation between is either a
 * mis-click or a cover-up, and a free-text status cannot tell those apart from the real thing.
 *
 * So every move is declared below, `transitions()` is the only thing that authorises one, and
 * `PlatformIncidentLog` writes an event row for each.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 * REOPENING IS ALLOWED, AND DELETION IS NOT. Both are deliberate.
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 *
 * An incident declared resolved that turns out not to be is an ordinary operational event, and the
 * honest record of it is one incident that was reopened — not a second incident with no link to the
 * first, which is what a terminal `resolved` would force somebody to create. So `resolved` is not
 * terminal.
 *
 * Nothing here removes a state or a row. An incident log that can be edited into a different
 * history is not a log, and the `platform_incident_events` table has no update or delete path at
 * all.
 *
 * `RESOLVED -> RESOLVED` AND EVERY OTHER SELF-MOVE IS REFUSED, which is not pedantry: a repeated
 * "resolve" click would otherwise write a second resolution event and move `resolved_at` forward,
 * making the incident look as though it had been resolved later than it was. A note is how an
 * operator adds to an incident without moving it, and notes are unlimited.
 */
final class IncidentState
{
    /** Recorded, and nobody has yet said they are looking at it. */
    public const OPEN = 'open';

    /** Somebody has taken it: the move that distinguishes "we know" from "it is in the list". */
    public const ACKNOWLEDGED = 'acknowledged';

    /**
     * The impact has been stopped; the cause may not have been found.
     *
     * KEPT APART FROM `resolved` BECAUSE THE TWO ARE DIFFERENT FACTS and conflating them is how a
     * platform loses the record of what it has not actually fixed. A queue drained by restarting a
     * worker is mitigated; it is resolved when the worker stops dying.
     */
    public const MITIGATED = 'mitigated';

    /** Done, and reopenable. See the class docblock. */
    public const RESOLVED = 'resolved';

    /**
     * EVERY STATE, in the order an incident normally passes through them.
     *
     * The ORDER is part of the declaration and not incidental: it is what the screen lists them in,
     * so a reader sees the lifecycle rather than an alphabetical set in which `acknowledged`
     * precedes `open`.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::OPEN, self::ACKNOWLEDGED, self::MITIGATED, self::RESOLVED];
    }

    /**
     * WHICH MOVES ARE ALLOWED, declared rather than computed.
     *
     * Forward through the lifecycle, and `resolved` from anywhere live — because an incident that
     * turns out to be nothing is resolved from `open` without anybody pretending to have mitigated
     * it, and inventing an intermediate state to satisfy a diagram would put a false step in the
     * record.
     *
     * BACKWARD MOVES ARE REFUSED EXCEPT THE REOPEN. `mitigated -> acknowledged` is not a thing that
     * happens; a mitigation that stops working is a new fact about a live incident and belongs in a
     * note, or is a reopen if it had been resolved.
     *
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        return [
            self::OPEN => [self::ACKNOWLEDGED, self::MITIGATED, self::RESOLVED],
            self::ACKNOWLEDGED => [self::MITIGATED, self::RESOLVED],
            self::MITIGATED => [self::RESOLVED],
            // The reopen, and the only backward move in the machine.
            self::RESOLVED => [self::OPEN],
        ];
    }

    public static function exists(string $state): bool
    {
        return in_array($state, self::all(), true);
    }

    /** States an incident is still being worked on in. Not a verdict — a membership test. */
    public static function isLive(string $state): bool
    {
        return self::exists($state) && $state !== self::RESOLVED;
    }

    public static function canMove(string $from, string $to): bool
    {
        if (! self::exists($from) || ! self::exists($to)) {
            return false;
        }

        return in_array($to, self::transitions()[$from] ?? [], true);
    }

    /** @return list<string> */
    public static function movesFrom(string $from): array
    {
        if (! self::exists($from)) {
            throw new InvalidArgumentException('Unknown incident state: ' . $from);
        }

        return self::transitions()[$from] ?? [];
    }

    /**
     * THE COLUMN A MOVE STAMPS, or null for the reopen.
     *
     * `open` has no column of its own because `detected_at` is not the same fact — an incident can
     * be recorded hours after it began, and `created_at` already says when the row was written.
     * The reopen deliberately stamps nothing: it does not un-resolve the time it was resolved,
     * which is a real moment that happened, and the event row carries the reopening.
     */
    public static function stampedColumn(string $state): ?string
    {
        return match ($state) {
            self::ACKNOWLEDGED => 'acknowledged_at',
            self::MITIGATED => 'mitigated_at',
            self::RESOLVED => 'resolved_at',
            default => null,
        };
    }

    /**
     * The words an operator reads, resolved in the operator's own language.
     *
     * A MACHINE KEY AND A SENTENCE ARE TWO DIFFERENT THINGS, which is the split `MetricCaveat` and
     * `FulfilmentStateMachine` both draw in this codebase: the key is what a row stores, a
     * transition is authorised against and a test asserts; the label is what is printed. Storing
     * the label would make the state machine break when somebody signs in in Arabic.
     */
    public static function label(string $state): string
    {
        return match ($state) {
            self::OPEN => __('Open'),
            self::ACKNOWLEDGED => __('Acknowledged'),
            self::MITIGATED => __('Mitigated'),
            self::RESOLVED => __('Resolved'),
            default => throw new InvalidArgumentException('Unknown incident state: ' . $state),
        };
    }

    /** What the state MEANS, which is the part that keeps `mitigated` and `resolved` apart. */
    public static function description(string $state): string
    {
        return match ($state) {
            self::OPEN => __('Recorded. Nobody has yet said they are working on it.'),
            self::ACKNOWLEDGED => __('Somebody has taken it on.'),
            self::MITIGATED => __('The impact has been stopped. The cause may not have been found, and this is deliberately not the same as resolved.'),
            self::RESOLVED => __('Finished. An incident that turns out not to be finished is reopened rather than recorded twice.'),
            default => throw new InvalidArgumentException('Unknown incident state: ' . $state),
        };
    }

    /** The verb on the button that makes the move, which is not the name of the state it lands in. */
    public static function moveLabel(string $from, string $to): string
    {
        if ($from === self::RESOLVED && $to === self::OPEN) {
            return __('Reopen');
        }

        return match ($to) {
            self::ACKNOWLEDGED => __('Acknowledge'),
            self::MITIGATED => __('Record as mitigated'),
            self::RESOLVED => __('Resolve'),
            self::OPEN => __('Reopen'),
            default => throw new InvalidArgumentException('Unknown incident state: ' . $to),
        };
    }
}
