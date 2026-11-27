<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PHASE 06 `CTRL-001` — a named exception to a feature flag's state: by plan, store or pilot group.
 *
 * `CTRL-001` names exactly three axes — *"حسب الباقات والمتاجر والمجموعات التجريبية"* — and
 * `TARGET_TYPES` is those three and nothing else. An unrecognised type never matches, so a typo
 * removes a target's effect rather than widening it.
 *
 * NOT `Cachable`, for the reasons in `PlatformFeatureFlag`'s docblock.
 */
class PlatformFeatureFlagTarget extends Model
{
    /** The subject's plan, resolved through the store's owner. */
    public const TYPE_PLAN = 'plan';

    /** The subject store itself. */
    public const TYPE_STORE = 'store';

    /** A named set of stores — `platform_pilot_group_members`. */
    public const TYPE_PILOT_GROUP = 'pilot_group';

    public const TARGET_TYPES = [self::TYPE_PLAN, self::TYPE_STORE, self::TYPE_PILOT_GROUP];

    /**
     * `allow` reaches a subject a `limited` state would otherwise refuse.
     *
     * `deny` refuses a subject whatever the state says — including `on`. That direction exists
     * because "everyone except this one store" is a real operator need, and the alternative is
     * taking the feature away from everybody.
     */
    public const EFFECT_ALLOW = 'allow';
    public const EFFECT_DENY = 'deny';

    public const EFFECTS = [self::EFFECT_ALLOW, self::EFFECT_DENY];

    protected $fillable = ['feature_flag_id', 'target_type', 'target_key', 'effect'];

    protected $casts = [
        'feature_flag_id' => 'integer',
    ];
}
