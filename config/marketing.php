<?php

return [

    /*
    |---------------------------------------------------------------------------
    | GAP-219 — the minimum interval between nudges to one shopper, in seconds
    |---------------------------------------------------------------------------
    |
    | NULL BY DESIGN, AND THAT IS NOT AN OVERSIGHT. How often a shopper may be
    | messaged is a MARKETING POLICY and therefore the owner's to set; the
    | standing instruction is not to invent an approved sending frequency, and a
    | quiet default would be inventing one by another route.
    |
    | While this is null, `NudgeThrottle` returns "allow" without touching the
    | database, and no shopper is ever refused a nudge for recency. The
    | DUPLICATE guard is separate and is always on: it keys on the logical action
    | (store, channel, template, contact, subject) with no clock involved, so it
    | stops a merchant's double-click without implying any cadence.
    |
    | Setting this is one value and no code change. Examples, none of them
    | recommended here: 86400 (one day), 604800 (one week). A zero or negative
    | value is read as "unset" rather than as "no interval", because
    | MARKETING_NUDGE_MIN_INTERVAL_SECONDS=0 in an .env almost certainly means
    | "off" and treating it as "allow everything" would reach that answer by
    | accident.
    |
    | See docs/development/phase-5/PHASE-05-OWNER-DECISION-REGISTER.md.
    */
    'nudge_min_interval_seconds' => env('MARKETING_NUDGE_MIN_INTERVAL_SECONDS', null),

];
