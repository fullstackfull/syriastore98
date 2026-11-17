<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Where one (store, channel, purpose, address) stands NOW. D8 / MKT-008.
 *
 * A PROJECTION of `marketing_consent_events`, kept so the send gate answers with one indexed read
 * instead of folding a log on every message. It is written only by `ConsentLedger`, inside the same
 * transaction as the event it reflects, and `ConsentLedger::rebuildState()` recomputes it from the
 * events so the two can be compared. Never write it from anywhere else.
 *
 * Not `Cachable` and not tenant-scoped, for the reasons on {@see MarketingConsentEvent}.
 */
class MarketingContactState extends Model
{
    protected $table = 'marketing_contact_states';

    protected $guarded = [];

    protected $casts = [
        'last_event_id' => 'integer',
    ];
}
