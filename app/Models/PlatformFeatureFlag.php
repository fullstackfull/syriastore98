<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PHASE 06 `CTRL-001` / `CTRL-002` — one controlled feature: its state and how wide it has reached.
 *
 * DELIBERATELY NOT `Cachable`, against the grain of 79 of the 95 models in this directory. The
 * migration's docblock carries the two measured reasons; the short form is that a kill switch must
 * answer current truth, and that on production's non-taggable `database` cache store
 * laravel-model-caching's flush degrades to a `Cache::flush()` over everything, so every flag edit
 * would erase the whole application cache. `App\Support\Control\FeatureControl` memoises per
 * request in the container instead, and clears that memo on every write.
 *
 * NO GLOBAL SCOPE AND NO `store_id`. A flag is the PLATFORM's, not a tenant's — `CTRL-001` is in the
 * §06-H *platform control* section. Which stores it reaches is `platform_feature_flag_targets`'
 * question, and the resolver asks it explicitly: CLAUDE.md records `null_tenant => 'open'`, so a
 * scope would add no predicate for the operator anyway and leaning on one would be the mistake this
 * table is designed to avoid.
 */
class PlatformFeatureFlag extends Model
{
    /** Nobody. The feature does not exist for any store. The kill switch. */
    public const STATE_OFF = 'off';

    /** Only the stores an allow-target names, or the ones inside the rollout percentage. */
    public const STATE_LIMITED = 'limited';

    /** Everybody, except the stores a deny-target names. */
    public const STATE_ON = 'on';

    /**
     * THE ONLY THREE RECOGNISED VALUES. `FeatureControl` treats anything else as `off`, which is
     * fail-closed inside a declared flag — production runs non-strict `sql_mode`, so a value too
     * long for `varchar(16)` is TRUNCATED rather than refused, and a truncated state must not read
     * as "on".
     */
    public const STATES = [self::STATE_OFF, self::STATE_LIMITED, self::STATE_ON];

    protected $fillable = ['key', 'label', 'description', 'state', 'rollout_percentage'];

    protected $casts = [
        'rollout_percentage' => 'integer',
    ];

    public function targets(): HasMany
    {
        return $this->hasMany(PlatformFeatureFlagTarget::class, 'feature_flag_id');
    }

    /**
     * The state, normalised, with anything unrecognised reported as `off`.
     *
     * A method rather than an accessor on `state`, so a screen can still show the operator the raw
     * stored value when it disagrees with this. A control that silently rewrites what it reads is
     * harder to debug than one that answers safely and lets you see why.
     */
    public function resolvedState(): string
    {
        $state = strtolower(trim((string) $this->getAttribute('state')));

        return in_array($state, self::STATES, true) ? $state : self::STATE_OFF;
    }

    /** 0-100, clamped. A stored value outside the range cannot widen a rollout past everybody. */
    public function resolvedPercentage(): int
    {
        return max(0, min(100, (int) $this->getAttribute('rollout_percentage')));
    }
}
