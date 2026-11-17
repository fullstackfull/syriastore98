<?php

/**
 * MARKETING CONSENT ENFORCEMENT. D8 / GAP-200 / MKT-008.
 *
 * `enforcement` has three values and ONE default:
 *
 *   off      -- the shipped behaviour. The send gate answers "allow" WITHOUT TOUCHING THE DATABASE, so
 *               an environment that does not have the consent tables (production, today) is
 *               unaffected, byte for byte. This is the default and nothing in this repository changes it.
 *   report   -- the gate computes what it WOULD have done and logs it, then allows the send. This is
 *               how an owner finds out which sends would be held before holding any of them.
 *   enforce  -- a marketing message with no recorded, current permission is held.
 *
 * Moving off `off` is the "activation" the owner's decision D8 reserves: it needs the consent tables
 * migrated into that environment and the owner's SEPARATE authorisation. An unrecognised value is
 * treated as `report`, not `off` and not `enforce`: a typo must neither silently disable the control
 * nor silently stop a merchant's mail, and `report` is visible in the logs either way.
 */
return [
    'enforcement' => env('MARKETING_CONSENT_ENFORCEMENT', 'off'),
];
