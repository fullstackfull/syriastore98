<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MKT-004 — one customer's membership of one static segment.
 *
 * `UPDATED_AT` IS NULL BECAUSE THERE IS NOTHING TO UPDATE. A membership is added or removed; a row
 * that could be edited in place would invite a future reader to believe a membership can change
 * identity, and the unique index on (`segment_id`, `customer_id`) says it cannot.
 *
 * NOT `Cachable` and not in `scoped_models`, for the reasons `CustomerSegment` records.
 */
class CustomerSegmentMember extends Model
{
    protected $table = 'customer_segment_members';

    public const UPDATED_AT = null;

    /**
     * NOTHING IS FILLABLE. Every column on this table identifies a tenant, a segment or a person,
     * so each is set as a property by the one service that writes it. There is no attribute here a
     * request may name.
     */
    protected $guarded = ['*'];
}
