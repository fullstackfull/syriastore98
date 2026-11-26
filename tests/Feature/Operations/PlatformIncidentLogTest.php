<?php

namespace Tests\Feature\Operations;

use App\Models\PlatformIncident;
use App\Services\PlatformIncidentLog;
use App\Support\Operations\IncidentSeverity;
use App\Support\Operations\IncidentState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * OPS-003 — THE INCIDENT RECORD, ITS AUDIT TRAIL, AND WHAT CANNOT BE DONE TO EITHER.
 * =================================================================================================
 *
 * The record's whole value is the SEQUENCE. An incident that reads "resolved" with no
 * acknowledgement and no mitigation between is either a mis-click or a cover-up, and a log that
 * cannot tell those apart from the real thing is not worth keeping. So the cases below are mostly
 * about what the log REFUSES and about the trail it writes whether anybody asks for one or not.
 *
 * ASSERTED THROUGH THE RAW TABLES, not through the model. `CLAUDE.md`: eleven models really do
 * filter by `store_id`, so a plain `find()` in a test answers against whichever tenant happens to
 * be resolved rather than against the table, and an "it is there" assertion passes whether or not
 * the write worked — which cost this project a real false reading on a cart. These two models are
 * deliberately NOT scoped and NOT `Cachable`, so a raw read is exact here; it is used anyway,
 * because the premise "these models are unscoped" is itself something a later change could break,
 * and a raw read cannot be wrong about it either way.
 *
 * WHY A Feature TEST. It writes to two tables inside a transaction, so it needs the container and a
 * migrated schema. Nothing here touches HTTP — the door is `PlatformIncidentsScreenTest`.
 */
final class PlatformIncidentLogTest extends TestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private PlatformIncidentLog $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = new PlatformIncidentLog();
    }

    /** @return list<array<string, mixed>> the raw event rows, oldest first */
    private function events(int $incidentId): array
    {
        return array_map(
            static fn ($row): array => (array) $row,
            DB::table('platform_incident_events')
                ->where('incident_id', $incidentId)
                ->orderBy('id')
                ->get()
                ->all()
        );
    }

    /** @return array<string, mixed> the raw incident row */
    private function row(int $id): array
    {
        return (array) DB::table('platform_incidents')->where('id', $id)->first();
    }

    /*
     * =============================================================================================
     * Opening
     * =============================================================================================
     */

    /** An incident starts open, with a reference, and with the first event of its history. */
    public function test_opening_an_incident_writes_the_row_and_the_first_event(): void
    {
        $incident = $this->log->open(
            'Checkout failing at the gateway',
            IncidentSeverity::CRITICAL,
            'Every card payment returned a provider error.',
            actorId: 7,
        );

        $row = $this->row($incident->id);

        $this->assertSame(IncidentState::OPEN, $row['status']);
        $this->assertSame(IncidentSeverity::CRITICAL, $row['severity']);
        $this->assertSame('Checkout failing at the gateway', $row['title']);
        $this->assertSame('Every card payment returned a provider error.', $row['summary']);
        $this->assertSame(7, (int) $row['opened_by']);
        $this->assertNull($row['acknowledged_at'], 'a new incident was already acknowledged');
        $this->assertNull($row['mitigated_at']);
        $this->assertNull($row['resolved_at']);

        $events = $this->events($incident->id);

        $this->assertCount(1, $events, 'opening an incident did not write exactly one event');
        $this->assertNull($events[0]['from_status'], 'the first event came from a state');
        $this->assertSame(IncidentState::OPEN, $events[0]['to_status']);
        $this->assertSame(7, (int) $events[0]['actor_id']);
        $this->assertNotNull($events[0]['recorded_at']);
    }

    /**
     * `detected_at` IS WHEN IT BEGAN, NOT WHEN IT WAS WRITTEN DOWN, and keeping them apart is most
     * of the value of the table: an incident is nearly always recorded after it began, and a log
     * that cannot express that answers "how long was it broken" with the time it took somebody to
     * notice.
     */
    public function test_an_incident_can_be_recorded_as_having_begun_earlier(): void
    {
        $began = CarbonImmutable::now()->subHours(6)->startOfSecond();

        $incident = $this->log->open('Queue stalled overnight', IncidentSeverity::MAJOR, detectedAt: $began);

        $row = $this->row($incident->id);

        $this->assertSame($began->toDateTimeString(), CarbonImmutable::parse($row['detected_at'])->toDateTimeString(),
            'the incident was dated by when it was written down rather than by when it began');
        $this->assertNotSame(
            CarbonImmutable::parse($row['detected_at'])->toDateTimeString(),
            CarbonImmutable::parse($row['created_at'])->toDateTimeString(),
            'premise: the two timestamps are the same in this case, so it proves nothing'
        );
    }

    /** With nothing said about when it began, it began now. A null `detected_at` is not an answer. */
    public function test_an_incident_with_no_stated_start_is_dated_now(): void
    {
        $incident = $this->log->open('Something', IncidentSeverity::MINOR);

        $this->assertNotNull($this->row($incident->id)['detected_at'],
            'an incident with no stated start has no start at all, so no duration can ever be '
            . 'computed for it');
    }

    /** The reference is quotable, dated, and unique across a batch opened in the same second. */
    public function test_every_incident_gets_its_own_dated_reference(): void
    {
        $references = [];

        for ($i = 0; $i < 25; $i++) {
            $references[] = $this->log->open('Incident ' . $i, IncidentSeverity::MINOR)->reference;
        }

        $this->assertCount(25, array_unique($references),
            'two incidents opened in the same run share a reference, so quoting one is ambiguous');

        foreach ($references as $reference) {
            $this->assertMatchesRegularExpression('/^INC-\d{8}-[0-9A-F]{4}$/', $reference,
                "'{$reference}' is not a dated, quotable reference");
            $this->assertStringContainsString(CarbonImmutable::now()->format('Ymd'), $reference,
                'the reference does not carry the day the incident was recorded');
        }
    }

    /** An undeclared severity is refused rather than stored, which is what makes the set a set. */
    public function test_an_undeclared_severity_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->log->open('Something', 'catastrophic');
    }

    public function test_an_incident_needs_a_title(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->log->open('   ', IncidentSeverity::MINOR);
    }

    /*
     * =============================================================================================
     * Moving
     * =============================================================================================
     */

    /** A move changes the state, stamps its own column, and writes an event naming both ends. */
    public function test_a_move_stamps_its_own_column_and_writes_an_event_naming_both_ends(): void
    {
        $incident = $this->log->open('Queue stalled', IncidentSeverity::MAJOR, actorId: 3);

        $this->log->transition($incident, IncidentState::ACKNOWLEDGED, 'Looking at the worker.', 9);

        $row = $this->row($incident->id);

        $this->assertSame(IncidentState::ACKNOWLEDGED, $row['status']);
        $this->assertNotNull($row['acknowledged_at'], 'the move did not stamp its own column');
        $this->assertNull($row['mitigated_at'], 'a move to acknowledged stamped the mitigation column');
        $this->assertNull($row['resolved_at']);

        $events = $this->events($incident->id);

        $this->assertCount(2, $events);
        $this->assertSame(IncidentState::OPEN, $events[1]['from_status'],
            'the event does not say which state the incident came from, so the sequence is lost');
        $this->assertSame(IncidentState::ACKNOWLEDGED, $events[1]['to_status']);
        $this->assertSame('Looking at the worker.', $events[1]['note']);
        $this->assertSame(9, (int) $events[1]['actor_id'],
            'the event was attributed to whoever opened the incident rather than to whoever moved it');
    }

    /** The whole forward lifecycle, with each stamp landing once and in order. */
    public function test_the_forward_lifecycle_stamps_each_column_in_turn(): void
    {
        $incident = $this->log->open('Gateway errors', IncidentSeverity::CRITICAL);

        foreach ([IncidentState::ACKNOWLEDGED, IncidentState::MITIGATED, IncidentState::RESOLVED] as $to) {
            $incident = $this->log->transition($incident, $to);
        }

        $row = $this->row($incident->id);

        $this->assertSame(IncidentState::RESOLVED, $row['status']);

        foreach (['acknowledged_at', 'mitigated_at', 'resolved_at'] as $column) {
            $this->assertNotNull($row[$column], $column . ' was never stamped');
        }

        $this->assertLessThanOrEqual(
            CarbonImmutable::parse($row['resolved_at'])->timestamp,
            CarbonImmutable::parse($row['acknowledged_at'])->timestamp,
            'the incident was acknowledged after it was resolved'
        );

        $this->assertCount(4, $this->events($incident->id),
            'the four things that happened to this incident are not four events');
    }

    /**
     * A MOVE THE MACHINE REFUSES CHANGES NOTHING AT ALL — not the status, and not the trail.
     *
     * The second half is the one worth asserting: a refusal that had already written an event would
     * leave a history of something that did not happen, which is worse than the move succeeding.
     */
    public function test_a_refused_move_changes_neither_the_status_nor_the_trail(): void
    {
        $incident = $this->log->open('Slow search', IncidentSeverity::MINOR);
        $this->log->transition($incident, IncidentState::MITIGATED);

        $before = $this->row($incident->id);
        $eventsBefore = $this->events($incident->id);

        try {
            $this->log->transition($incident->refresh(), IncidentState::ACKNOWLEDGED);

            $this->fail('a mitigated incident was walked backwards to acknowledged');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('mitigated', $e->getMessage());
            $this->assertStringContainsString('acknowledged', $e->getMessage());
        }

        $this->assertSame($before, $this->row($incident->id),
            'a refused move changed the incident row');
        $this->assertSame($eventsBefore, $this->events($incident->id),
            'a refused move wrote an event, so the trail records something that did not happen');
    }

    /**
     * A REPEATED MOVE IS REFUSED, which is what stops a double click dragging a stamp forward.
     *
     * The discriminating half is the timestamp: if the self-move were allowed, `resolved_at` would
     * move to the second click and the incident would read as having been resolved later than it
     * was.
     */
    public function test_a_repeated_move_is_refused_and_the_stamp_does_not_move(): void
    {
        $incident = $this->log->open('Disk filling', IncidentSeverity::MAJOR);
        $incident = $this->log->transition($incident, IncidentState::RESOLVED);

        $stamp = $this->row($incident->id)['resolved_at'];

        $this->expectException(RuntimeException::class);

        try {
            $this->log->transition($incident, IncidentState::RESOLVED);
        } finally {
            $this->assertSame($stamp, $this->row($incident->id)['resolved_at'],
                'a second resolve moved the resolution time, so the incident now reads as having '
                . 'been resolved later than it was');
            $this->assertCount(2, $this->events($incident->id),
                'a refused repeat wrote a second resolution event');
        }
    }

    /**
     * REOPENING KEEPS THE FIRST RESOLUTION TIME, and that is the subtlest rule in the log.
     *
     * The incident really was resolved at that moment; a reopen is a new fact, not a correction of
     * history. So `resolved_at` is written once, the reopen stamps nothing, and the SECOND
     * resolution is in the trail where a second resolution belongs.
     */
    public function test_reopening_and_resolving_again_keeps_the_first_resolution_time(): void
    {
        $incident = $this->log->open('Worker dying', IncidentSeverity::MAJOR);
        $incident = $this->log->transition($incident, IncidentState::RESOLVED);

        $first = $this->row($incident->id)['resolved_at'];

        $incident = $this->log->transition($incident, IncidentState::OPEN, 'It came back.');

        $this->assertSame(IncidentState::OPEN, $this->row($incident->id)['status']);
        $this->assertSame($first, $this->row($incident->id)['resolved_at'],
            'the reopen erased the time the incident really had been resolved');

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(30));

        $incident = $this->log->transition($incident, IncidentState::RESOLVED);

        CarbonImmutable::setTestNow();

        $this->assertSame($first, $this->row($incident->id)['resolved_at'],
            'the second resolution overwrote the first, so the record no longer shows that this '
            . 'incident had been resolved once before');

        $events = $this->events($incident->id);

        $this->assertCount(4, $events);
        $this->assertSame([
            [null, IncidentState::OPEN],
            [IncidentState::OPEN, IncidentState::RESOLVED],
            [IncidentState::RESOLVED, IncidentState::OPEN],
            [IncidentState::OPEN, IncidentState::RESOLVED],
        ], array_map(
            static fn (array $e): array => [$e['from_status'], $e['to_status']],
            $events
        ), 'the trail does not record the reopen and the second resolution as separate events');
    }

    /*
     * =============================================================================================
     * Notes
     * =============================================================================================
     */

    /**
     * A NOTE ADDS TO AN INCIDENT WITHOUT MOVING IT, which is what makes refusing a self-move
     * affordable: an operator with something to add about an already-acknowledged incident has
     * somewhere to put it.
     */
    public function test_a_note_is_recorded_without_changing_the_state(): void
    {
        $incident = $this->log->open('Intermittent 500s', IncidentSeverity::MINOR);
        $incident = $this->log->transition($incident, IncidentState::ACKNOWLEDGED);

        $before = $this->row($incident->id);

        $this->log->note($incident, 'Only on the search route.', 4);
        $this->log->note($incident, 'And only for Arabic.', 4);

        $this->assertSame($before, $this->row($incident->id),
            'adding a note changed the incident row');

        $events = $this->events($incident->id);

        $this->assertCount(4, $events);
        $this->assertNull($events[2]['from_status'], 'a note came from a state');
        $this->assertNull($events[2]['to_status'], 'a note moved the incident');
        $this->assertSame('Only on the search route.', $events[2]['note']);
        $this->assertSame('And only for Arabic.', $events[3]['note']);
    }

    public function test_an_empty_note_is_refused(): void
    {
        $incident = $this->log->open('Something', IncidentSeverity::MINOR);

        $this->expectException(InvalidArgumentException::class);

        $this->log->note($incident, "  \n ");
    }

    /*
     * =============================================================================================
     * The diagnostics snapshot
     * =============================================================================================
     */

    /**
     * THE SNAPSHOT IS WHAT WAS MEASURED WHEN THE INCIDENT WAS OPENED, and it is the half of
     * OPS-003's "التشخيص" the measurement screens cannot supply on their own: those screens show
     * NOW, and an incident is about then.
     *
     * ASSERTED BY CHANGING THE SYSTEM AFTERWARDS. Three jobs are queued AFTER the incident is
     * opened, and the stored snapshot must still say zero. A snapshot re-measured on read — which
     * is what an accessor computing it lazily would do — says three and fails.
     */
    public function test_the_snapshot_records_what_was_measured_then_and_not_what_is_true_now(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.table' => 'jobs',
        ]);

        $before = \App\Services\SaasPlatformOperations::measure();

        $incident = $this->log->open('Queue stalled', IncidentSeverity::MAJOR, diagnostics: $before);

        for ($i = 0; $i < 3; $i++) {
            DB::table('jobs')->insert([
                'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
                'reserved_at' => null, 'available_at' => time(), 'created_at' => time(),
            ]);
        }

        $stored = PlatformIncident::find($incident->id)->diagnostics;

        $this->assertIsArray($stored, 'the snapshot did not survive the round trip');

        $pending = null;

        foreach ($stored['queue'] as $row) {
            if ($row['key'] === 'pending') {
                $pending = $row['value'];
            }
        }

        $this->assertSame('0', $pending,
            'the stored snapshot reports the queue as it is NOW rather than as it was when the '
            . 'incident was opened, so it is not a snapshot');

        // And the live measurement really has moved, so the assertion above is discriminating.
        $live = \App\Services\SaasPlatformOperations::measure();
        $livePending = null;

        foreach ($live['queue'] as $row) {
            if ($row['key'] === 'pending') {
                $livePending = $row['value'];
            }
        }

        $this->assertSame('3', $livePending,
            'premise: the live figure did not change, so the case above proved nothing');
    }

    /** A host that refuses every measurement must still be able to have an incident recorded. */
    public function test_an_incident_can_be_recorded_with_no_snapshot_at_all(): void
    {
        $incident = $this->log->open('Recorded after the fact', IncidentSeverity::MINOR);

        $this->assertNull($this->row($incident->id)['diagnostics']);
        $this->assertSame(IncidentState::OPEN, $this->row($incident->id)['status']);
    }

    /*
     * =============================================================================================
     * What cannot be done
     * =============================================================================================
     */

    /**
     * THE AUDIT TRAIL HAS NO UPDATE AND NO DELETE PATH, asserted by reading the only writer.
     *
     * A log that can be edited into a different history is not a log, and the history is the whole
     * reason the incident row and its events are two tables rather than a `status` column beside a
     * `notes` field.
     *
     * COMMENT-STRIPPED, AND LOAD-BEARING: `PlatformIncidentLog`'s own docblock names the calls this
     * case looks for, while explaining that they are absent. `CLAUDE.md` records eight occasions on
     * which a census was satisfied — or broken — by prose about the thing it measures.
     */
    public function test_the_audit_trail_has_no_update_or_delete_path(): void
    {
        $code = $this->sourceWithoutComments('app/Services/PlatformIncidentLog.php');

        foreach (['->delete(', '::delete(', 'forceDelete', '->truncate(', '->update('] as $call) {
            $this->assertStringNotContainsString($call, $code, sprintf(
                'PlatformIncidentLog contains `%s`. It is the only writer of the incident record, '
                . 'and an operations log that can be edited into a different history is not a log.',
                $call
            ));
        }

        // The three verbs it does have, asserted present, so this case fails if the writer is
        // replaced by something it cannot inspect rather than merely renamed.
        foreach (['function open(', 'function transition(', 'function note('] as $verb) {
            $this->assertStringContainsString($verb, $code,
                'the writer no longer has ' . $verb . ', so this census is reading the wrong file');
        }
    }

    /**
     * THE STATUS AND THE STAMPS ARE NOT MASS-ASSIGNABLE, so no handler can move an incident by
     * assigning to it and skip the trail entirely.
     *
     * This is the defect `FulfilmentStateMachine` was written for, on orders: a status moved by
     * whatever string a handler happened to assign, with no record of how it got there. Asserted
     * by DRIVING it rather than by reading `$fillable`, because the question is what a `fill()`
     * really does.
     */
    public function test_the_status_and_its_stamps_cannot_be_mass_assigned(): void
    {
        $incident = $this->log->open('Something', IncidentSeverity::MINOR);

        $incident->fill([
            'status' => IncidentState::RESOLVED,
            'resolved_at' => CarbonImmutable::now(),
            'reference' => 'INC-00000000-FFFF',
        ]);
        $incident->save();

        $row = $this->row($incident->id);

        $this->assertSame(IncidentState::OPEN, $row['status'],
            'an incident was resolved by mass assignment, with no event in its trail');
        $this->assertNull($row['resolved_at']);
        $this->assertNotSame('INC-00000000-FFFF', $row['reference'],
            'an incident\'s reference was rewritten by mass assignment');
        $this->assertCount(1, $this->events($incident->id));
    }

    /**
     * THE MODELS ARE NOT CACHED, which matters because an operations log is read by two people at
     * once while something is going wrong: one acknowledges and the other must not still see it
     * open.
     *
     * `CLAUDE.md` records what the cache does — a raw write leaves Eloquent reads serving the
     * pre-write row, and under `CACHE_STORE=array` it lives for the rest of the test; three
     * measured false readings. Asserted by the class not having the trait at all, because that is
     * the property, and then DRIVEN: a raw write must be visible to the next Eloquent read.
     */
    public function test_the_incident_models_are_not_cached(): void
    {
        foreach ([PlatformIncident::class, \App\Models\PlatformIncidentEvent::class] as $model) {
            $this->assertNotContains(
                'GeneaLabs\LaravelModelCaching\Traits\Cachable',
                class_uses_recursive($model),
                $model . ' is Cachable. Two operators reading an incident at once would be served '
                . 'the pre-write row, and one would acknowledge an incident the other already had.'
            );
        }

        $incident = $this->log->open('Something', IncidentSeverity::MINOR);

        PlatformIncident::find($incident->id);     // warm whatever there is to warm

        DB::table('platform_incidents')->where('id', $incident->id)->update(['title' => 'Renamed by hand']);

        $this->assertSame('Renamed by hand', PlatformIncident::find($incident->id)->title,
            'an Eloquent read served the row as it was before a raw write, so the model is cached '
            . 'somewhere');
    }

    /**
     * NEITHER TABLE IS TENANT-SCOPED, and the absence is the design: the subject is the shared
     * infrastructure, which has no tenant.
     *
     * ASSERTED IN BOTH DIRECTIONS AND NOT JUST ONE. `CLAUDE.md` is explicit that
     * `getGlobalScopes()` is not the same question as "is it scoped" — `Product` answers
     * `[StoreScope]` and adds no predicate — so the scope registration AND the allowlist are both
     * checked, and then the behaviour is driven.
     */
    public function test_the_incident_record_carries_no_tenant_scope(): void
    {
        $this->assertNotContains(PlatformIncident::class, config('tenancy.scoped_models', []),
            'the platform incident record is on the tenant allowlist. It has no store_id column, '
            . 'so the predicate would match nothing and the operator would see an empty log.');

        $this->assertSame([], array_keys((new PlatformIncident())->getGlobalScopes()),
            'the platform incident model has acquired a global scope');

        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('platform_incidents', 'store_id'),
            'the incident table has a store_id. Whether a merchant ever sees a platform incident is '
            . 'an owner decision (PlatformIncidentPolicy: merchant_visibility), not a column.');
    }
}
