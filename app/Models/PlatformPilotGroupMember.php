<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PHASE 06 `CTRL-001` — one store's membership of one pilot group.
 *
 * A PILOT GROUP IS ITS MEMBERSHIP. There is no parent `platform_pilot_groups` table, because such a
 * row's only content would be the name the slug already carries. If a group ever needs a
 * description, an owner or an end date, that is an additive table of its own rather than a reason to
 * add one now with nothing in it.
 *
 * `pilot_group` IS NORMALISED ON WRITE, by `FeatureControl::addToPilotGroup()`, to lower case with
 * surrounding whitespace removed. The unique key is `(pilot_group, store_id)`, and MySQL's default
 * collation is case-insensitive while SQLite's `=` is not — so without normalisation
 * `Early-Access` and `early-access` would be ONE group in production and TWO under the suite, which
 * is exactly the class of divergence CLAUDE.md records SQLite hiding (P01-SEC-13). Normalising at
 * the single write door makes both engines agree.
 *
 * NOT `Cachable`, for the reasons in `PlatformFeatureFlag`'s docblock.
 */
class PlatformPilotGroupMember extends Model
{
    protected $fillable = ['pilot_group', 'store_id'];

    protected $casts = [
        'store_id' => 'integer',
    ];
}
