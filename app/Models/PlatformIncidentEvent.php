<?php

namespace App\Models;

use App\Support\Operations\IncidentState;
use Illuminate\Database\Eloquent\Model;

/**
 * OPS-003 — ONE THING THAT HAPPENED TO AN INCIDENT: a move between states, or a note.
 * =================================================================================================
 *
 * APPEND-ONLY, AND THERE IS NO WRITER THAT UPDATES OR DELETES ONE. `PlatformIncidentLog` is the
 * only thing that creates these rows and it has no other verb;
 * `PlatformIncidentLogTest::test_the_audit_trail_has_no_update_or_delete_path` holds that by reading
 * the writer. A log that can be edited into a different history is not a log, and the history is
 * the whole reason the incident row and its events are two tables rather than a `status` column
 * beside a `notes` field.
 *
 * NOT `Cachable`, for its parent's reason: this is audit data read while something is going wrong,
 * and `AuditLog` is the precedent among the 16 models in `app/Models/` that are not.
 *
 * A ROW WITH NEITHER STATUS IS A NOTE. Both status columns are nullable — the first event of an
 * incident comes from nowhere, and a note comes from no state and goes to none — so the shape
 * carries the distinction without a `type` column whose values would have to be kept in step with
 * the two status columns anyway. `isNote()` is that reading, in one place.
 */
class PlatformIncidentEvent extends Model
{
    protected $table = 'platform_incident_events';

    protected $fillable = [
        'incident_id',
        'from_status',
        'to_status',
        'note',
        'actor_id',
        'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    public function isNote(): bool
    {
        return $this->to_status === null;
    }

    /**
     * The sentence this event reads as, assembled rather than stored.
     *
     * STORED WOULD BE WRONG for the reason `IncidentState::label()` gives: a sentence written at
     * write time is written in whatever language the operator who made the move was using, and
     * would then be read in that language by everybody else for ever. The keys are stored; the
     * words are resolved when they are read.
     */
    public function describe(): string
    {
        if ($this->isNote()) {
            return __('Note added.');
        }

        $to = IncidentState::exists((string) $this->to_status)
            ? IncidentState::label((string) $this->to_status)
            : (string) $this->to_status;

        if ($this->from_status === null) {
            return __('Recorded as :to.', ['to' => $to]);
        }

        $from = IncidentState::exists((string) $this->from_status)
            ? IncidentState::label((string) $this->from_status)
            : (string) $this->from_status;

        return __('Moved from :from to :to.', ['from' => $from, 'to' => $to]);
    }

    /** Whoever made the move. Null is a real answer — see `actorName()`. */
    public function actor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * The actor's name, or null. `PlatformIncident::openedByName()`'s twin, and a relation rather
     * than a raw query for the reason given there.
     *
     * NULL IS NOT AN ERROR HERE EITHER, and it covers two different cases on purpose: an operator
     * whose row has since been deleted, and a move made by something other than a signed-in
     * operator — which is what the automatic opening OPS-003's threshold decision would enable.
     * Attributing either to somebody would be worse than saying nothing.
     */
    public function actorName(): ?string
    {
        $name = $this->actor_id === null ? null : $this->actor?->name;

        return is_string($name) && $name !== '' ? $name : null;
    }
}
