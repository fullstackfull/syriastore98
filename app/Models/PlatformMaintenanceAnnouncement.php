<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PHASE 06 `CTRL-003` — one thing the platform is telling its merchants.
 *
 * DELIBERATELY NOT `Cachable`, against the grain of 79 of the 95 models in this directory, and for
 * the reason its siblings give plus one of its own: **withdrawing an announcement has to take
 * effect at once.** The case that matters is an operator who published the wrong window, or
 * announced an outage that was then cancelled — a banner that keeps telling every merchant the
 * platform is going down in an hour, until a cache expires, is worse than no banner. And
 * `PlanController::update`'s docblock records that on production's non-taggable `database` store a
 * model-cache flush degrades to `Cache::flush()` over everything.
 *
 * NO GLOBAL SCOPE AND NO `store_id`: the announcement is the PLATFORM's and reaches every tenant.
 * `CTRL-003` names no audience, and `MaintenanceAnnouncements`' docblock says why none is invented.
 */
class PlatformMaintenanceAnnouncement extends Model
{
    /** The quieter of the two, and what an unrecognised stored value reads as. */
    public const SEVERITY_NOTICE = 'notice';

    /** The operator saying this one matters. */
    public const SEVERITY_URGENT = 'urgent';

    public const SEVERITIES = [self::SEVERITY_NOTICE, self::SEVERITY_URGENT];

    protected $fillable = [
        'title_en', 'title_ar', 'body_en', 'body_ar',
        'severity', 'starts_at', 'ends_at', 'is_published', 'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_published' => 'boolean',
        'created_by' => 'integer',
    ];

    /**
     * The severity, normalised, with anything unrecognised reported as `notice`.
     *
     * THE QUIET DIRECTION IS THE SAFE ONE HERE, and it is the opposite of the feature flag's.
     * `PlatformFeatureFlag::resolvedState()` reads an unrecognised value as `off` because an
     * availability control must fail closed. A corrupted severity has no security meaning at all —
     * so it fails towards the calmer badge, because a truncated column shouting "urgent" at every
     * merchant on the platform is the worse outcome of the two.
     */
    public function resolvedSeverity(): string
    {
        $severity = strtolower(trim((string) $this->getAttribute('severity')));

        return in_array($severity, self::SEVERITIES, true) ? $severity : self::SEVERITY_NOTICE;
    }
}
