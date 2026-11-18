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
 * nor silently stop a merchant's mail, and `report` is visible in the logs either way. The spellings that can only mean "not
 * on" -- an empty value, `false`, `0`, `no`, `disabled`, `none`, `null` -- are `off`: Laravel turns the literal `false` into an
 * empty string, which used to select `report` and read tables an environment may not have.
 *
 * THE GATE FAILS SAFE, PER MODE. If the consent store cannot be read (a table that was never migrated, a lock timeout, an outage) a
 * marketing message is ALLOWED in `report` (the point of report is to say what would happen without ever stopping a send) and HELD
 * in `enforce` (consent cannot be verified, and a marketing message needs it). Transactional and operational messages never reach
 * the store at all.
 */
return [
    'enforcement' => env('MARKETING_CONSENT_ENFORCEMENT', 'off'),

    /*
     * PHONE IDENTITY IS NOT CANONICAL, so `enforce` is refused for the SMS and WhatsApp channels and treated as `report`.
     *
     * `0912345678` and `963912345678` are one handset and two keys, so a withdrawal or suppression recorded under one spelling does
     * not bind a send addressed under the other, and two people whose country-less numbers share national digits share a key. Both are
     * unsafe for marketing, and neither is visible in report mode. Making phone numbers canonical needs a country for each number and a
     * phone-number library, which is the owner's decision (docs/development/phase-5/PHASE-05-MKT-008-ENFORCEMENT-DESIGN.md, section 10).
     *
     * This is a literal `false`, NOT an environment variable, on purpose: it is a statement about what the CODE can do, and it is
     * flipped by the change that makes it true, together with the migration that re-keys the stored rows, never by a configuration typo.
     */
    'phone_identity_canonical' => false,
];
