<?php

namespace App\Models;

use App\Support\Operations\IncidentSeverity;
use App\Support\Operations\IncidentState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * OPS-003 — ONE THING THAT WENT WRONG WITH THE PLATFORM.
 * =================================================================================================
 *
 * NOT `Cachable`, AND THAT IS THE DECISION IN THIS FILE. 79 of the 95 models in `app/Models/` are,
 * so the exception needs its reason: the 16 that are not include `AuditLog`, and this is the same
 * kind of record. `CLAUDE.md` records what the cache does — a raw write leaves Eloquent reads,
 * route-model binding included, serving the pre-write row, and under `CACHE_STORE=array` it lives
 * for the rest of the test; three measured false readings. An operations log read during an
 * incident, by two operators at once, is the worst possible place for a stale row: one of them
 * acknowledges and the other still sees it open and acknowledges it again.
 *
 * NOT TENANT-SCOPED, AND THE ABSENCE IS DELIBERATE. The subject is the shared infrastructure, which
 * has no tenant, so the table carries no `store_id` and the model is not in
 * `config/tenancy.php`'s `scoped_models`. `null_tenant => 'open'` means a global scope would add no
 * predicate for the operator anyway, so leaning on one would be no protection; every route that
 * reads this is `PlatformOperatorOnly` and the gate is the whole control.
 *
 * `$fillable` DELIBERATELY EXCLUDES `status` AND EVERY STAMP. The status moves only through
 * `PlatformIncidentLog::transition()`, which authorises the move against `IncidentState` and writes
 * an event row for it; a mass-assignable `status` is how an incident comes to read "resolved" with
 * no acknowledgement and no mitigation between, which is the exact history an incident log exists
 * to preserve. `FulfilmentStateMachine` was written for the same failure on orders.
 */
class PlatformIncident extends Model
{
    protected $table = 'platform_incidents';

    /**
     * What a caller may set. `status`, `acknowledged_at`, `mitigated_at`, `resolved_at` and
     * `reference` are absent on purpose — the first four are the state machine's and the last is
     * minted once, by `PlatformIncidentLog::open()`.
     */
    protected $fillable = [
        'title',
        'summary',
        'severity',
        'detected_at',
        'opened_by',
        'diagnostics',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'mitigated_at' => 'datetime',
        'resolved_at' => 'datetime',
        /*
         * `array` AND NOT `json`: the two are the same cast in Laravel, and `array` is the spelling
         * every other model in this codebase uses (`AuditLog::$casts`), so a reader does not have
         * to check whether they differ.
         */
        'diagnostics' => 'array',
    ];

    /** Oldest first, which is the order a history is read in. */
    public function events(): HasMany
    {
        return $this->hasMany(PlatformIncidentEvent::class, 'incident_id')->orderBy('id');
    }

    public function isLive(): bool
    {
        return IncidentState::isLive((string) $this->status);
    }

    /** @return list<string> */
    public function availableMoves(): array
    {
        return IncidentState::exists((string) $this->status)
            ? IncidentState::movesFrom((string) $this->status)
            : [];
    }

    public function statusLabel(): string
    {
        return IncidentState::exists((string) $this->status)
            ? IncidentState::label((string) $this->status)
            : (string) $this->status;
    }

    public function severityLabel(): string
    {
        return IncidentSeverity::exists((string) $this->severity)
            ? IncidentSeverity::label((string) $this->severity)
            : (string) $this->severity;
    }

    /**
     * The operator who recorded it. Null is a real answer and not an error: `opened_by` carries no
     * foreign key, deliberately, so that deleting an operator does not delete the record of what
     * they did — which means the row can and should outlive them.
     */
    public function openedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * The operator's name, or null.
     *
     * A RELATION AND NOT `DB::table('users')`, and the reason is this repository's own raw-query
     * ratchets rather than taste. `ResidualTenancyExposureTest` pins the exact set of raw
     * query-builder entry points in `app/Models/*.php`, because a raw query there carries no global
     * scope; a new one would be a new unexplained member of that set. Nothing is lost by the
     * relation: `users` is not in `config/tenancy.php`'s `scoped_models`, so the model read has no
     * predicate to add or to lose, and this screen's correct answer really is "whichever user it
     * was", including one from another tenant, because that is who did it.
     *
     * `User` IS `Cachable`, which is a real difference from this model and an acceptable one HERE.
     * The staleness this model refuses to have is about the INCIDENT — two operators must not see
     * different states. A name is not state: nobody renames themselves during an outage, and a
     * cached name would be wrong only in a way that cannot mislead anybody about the incident.
     */
    public function openedByName(): ?string
    {
        $name = $this->opened_by === null ? null : $this->openedBy?->name;

        return is_string($name) && $name !== '' ? $name : null;
    }
}
