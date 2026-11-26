<?php

namespace App\Services;

use App\Models\PlatformIncident;
use App\Models\PlatformIncidentEvent;
use App\Support\Operations\IncidentSeverity;
use App\Support\Operations\IncidentState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * OPS-003 — THE ONLY THING THAT WRITES THE PLATFORM INCIDENT RECORD.
 * =================================================================================================
 *
 * THREE VERBS AND NO OTHERS: `open()`, `transition()`, `note()`. There is no `update()`, no
 * `delete()`, no `setStatus()` and no way to change an event once it is written.
 *
 * THE ABSENCE IS THE DESIGN AND IT IS ASSERTED. `PlatformIncidentLogTest` reads this file, with its
 * comments stripped, and fails if an update or delete call appears in it — because an operations log
 * that can be edited into a different history is not a log, and the history is the only reason the
 * incident row and its events are two tables rather than one. (That census is comment-stripped
 * because this very paragraph names the calls it looks for, which `CLAUDE.md` records as having
 * broken a census eight times.)
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 * EVERY WRITE IS ONE TRANSACTION, AND THE MOVE AND ITS EVENT ARE WRITTEN TOGETHER
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 *
 * A status that moved without an event row is an incident whose history has a hole in it; an event
 * row whose status did not move is a history of something that did not happen. Both are worse than
 * a failed write, so neither is reachable: `DB::transaction()` wraps each verb and the whole thing
 * either lands or does not.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 * THE STATE MACHINE AUTHORISES THE MOVE; THIS CLASS DOES NOT DECIDE ANYTHING
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 *
 * `IncidentState::canMove()` is the whole rule and it is declared there. `transition()` refuses a
 * move the machine does not allow — including a self-move, which would otherwise write a second
 * resolution event and drag `resolved_at` forward, making an incident look as though it had been
 * resolved later than it was.
 *
 * AND IT STILL JUDGES NOTHING. Nothing here reads a figure, compares it with a threshold, or opens
 * an incident by itself. An incident is opened by an operator, because what measured value
 * constitutes one is `PlatformIncidentPolicy::openDecisions()['thresholds']` — an owner decision
 * that has not been taken, and the easiest thing in the world to invent by accident.
 *
 * NOT AN AUTHORIZATION BOUNDARY. It records who acted from the id it is handed; deciding whether
 * they may is each caller's own gate. Its one caller is `SuperAdminIncidentsController`, behind
 * `PlatformOperatorOnly` on the route and the same test again inside every action.
 */
final class PlatformIncidentLog
{
    /**
     * RECORD AN INCIDENT, with the state it starts in and the first event of its history.
     *
     * `$detectedAt` IS SEPARATE FROM NOW ON PURPOSE. An incident is nearly always recorded after it
     * began, and a log that cannot say so answers "how long was it broken" with the time it took
     * somebody to write it down. Null means "as far as anybody knows, now".
     *
     * `$diagnostics` IS THE MEASUREMENT SNAPSHOT and is passed in rather than taken here, for two
     * reasons: this class has no business knowing which producers exist, and a caller recording a
     * past incident must be able to say honestly that no snapshot belongs to it.
     *
     * @param  array<string, mixed>|null $diagnostics
     */
    public function open(
        string $title,
        string $severity,
        ?string $summary = null,
        ?CarbonImmutable $detectedAt = null,
        ?int $actorId = null,
        ?array $diagnostics = null,
    ): PlatformIncident {
        $title = trim($title);

        if ($title === '') {
            throw new InvalidArgumentException('An incident needs a title.');
        }

        if (! IncidentSeverity::exists($severity)) {
            throw new InvalidArgumentException('Unknown incident severity: ' . $severity);
        }

        return DB::transaction(function () use ($title, $severity, $summary, $detectedAt, $actorId, $diagnostics) {
            $now = CarbonImmutable::now();

            $incident = new PlatformIncident();

            $incident->forceFill([
                'reference' => $this->mintReference($now),
                'title' => mb_substr($title, 0, 160),
                'summary' => $summary === null || trim($summary) === '' ? null : trim($summary),
                'severity' => $severity,
                'status' => IncidentState::OPEN,
                'detected_at' => $detectedAt ?? $now,
                'opened_by' => $actorId,
                'diagnostics' => $diagnostics,
            ])->save();

            $this->record($incident, null, IncidentState::OPEN, null, $actorId, $now);

            return $incident->refresh();
        });
    }

    /**
     * MOVE AN INCIDENT, if the machine allows it, and write the event that says so.
     *
     * @throws RuntimeException when the move is not one the state machine allows.
     */
    public function transition(
        PlatformIncident $incident,
        string $to,
        ?string $note = null,
        ?int $actorId = null,
    ): PlatformIncident {
        $from = (string) $incident->status;

        if (! IncidentState::canMove($from, $to)) {
            throw new RuntimeException(sprintf(
                'An incident cannot move from %s to %s. The moves allowed from %s are: %s.',
                $from,
                $to,
                $from,
                implode(', ', IncidentState::exists($from) ? IncidentState::movesFrom($from) : []) ?: 'none'
            ));
        }

        return DB::transaction(function () use ($incident, $from, $to, $note, $actorId) {
            $now = CarbonImmutable::now();

            $changes = ['status' => $to];

            /*
             * THE STAMP IS ONLY EVER WRITTEN ONCE PER STATE. A reopened incident that is resolved
             * again keeps its FIRST `resolved_at` — the move is in the event trail, which is where
             * a second resolution belongs, and overwriting the column would erase the fact that the
             * incident had been resolved before. The state machine already refuses a self-move, so
             * this guard only bites on the reopen path, which is exactly where it is needed.
             */
            $column = IncidentState::stampedColumn($to);

            if ($column !== null && $incident->{$column} === null) {
                $changes[$column] = $now;
            }

            $incident->forceFill($changes)->save();

            $this->record($incident, $from, $to, $note, $actorId, $now);

            return $incident->refresh();
        });
    }

    /**
     * ADD TO AN INCIDENT WITHOUT MOVING IT.
     *
     * This is the verb that makes refusing a self-move affordable: an operator with something to
     * add about an incident that is already acknowledged has somewhere to put it, so nothing is
     * lost by the machine refusing `acknowledged -> acknowledged`. Notes are unlimited, are never
     * edited, and never change the state.
     */
    public function note(PlatformIncident $incident, string $note, ?int $actorId = null): PlatformIncidentEvent
    {
        $note = trim($note);

        if ($note === '') {
            throw new InvalidArgumentException('A note needs text.');
        }

        return DB::transaction(fn (): PlatformIncidentEvent => $this->record(
            $incident,
            null,
            null,
            $note,
            $actorId,
            CarbonImmutable::now()
        ));
    }

    /**
     * THE HUMAN HANDLE: `INC-20261126-7F3A`.
     *
     * DATED, SO IT SORTS AND SO IT MEANS SOMETHING READ ALOUD. A bare counter tells a reader
     * nothing and a UUID cannot be read down a telephone.
     *
     * RANDOM AND NOT SEQUENTIAL, which is a deliberate trade and worth stating. A per-day counter
     * would need a read-then-write ("how many today? then insert"), and that races: two operators
     * recording an incident in the same second both read N and both write N+1. This codebase has
     * measured that exact race on the gateway-reference path and its conclusion was to let the
     * UNIQUE constraint decide — which is what happens here. The suffix is four hex characters from
     * `random_bytes`, the column is unique, and a collision is retried rather than hoped against.
     *
     * THE RETRY IS BOUNDED AND THEN THROWS. 65,536 suffixes per day: a collision is a near
     * impossibility and ten in a row is a sign that something is wrong (a clock, a seeded random
     * source), at which point failing loudly beats looping.
     */
    private function mintReference(CarbonImmutable $now): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $reference = sprintf('INC-%s-%s', $now->format('Ymd'), strtoupper(bin2hex(random_bytes(2))));

            if (! PlatformIncident::where('reference', $reference)->exists()) {
                return $reference;
            }
        }

        throw new RuntimeException('Could not mint a unique incident reference after 10 attempts.');
    }

    /** The one place an event row is created. */
    private function record(
        PlatformIncident $incident,
        ?string $from,
        ?string $to,
        ?string $note,
        ?int $actorId,
        CarbonImmutable $at,
    ): PlatformIncidentEvent {
        $event = new PlatformIncidentEvent();

        $event->forceFill([
            'incident_id' => $incident->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note === null || trim($note) === '' ? null : trim($note),
            'actor_id' => $actorId,
            'recorded_at' => $at,
        ])->save();

        return $event;
    }
}
