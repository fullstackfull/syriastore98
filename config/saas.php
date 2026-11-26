<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Grace period after a subscription expires — SAAS-002, SAAS-004
    |--------------------------------------------------------------------------
    |
    | AN OWNER DECISION, DELIBERATELY LEFT UNSET.
    |
    | `SAAS-002` lists "فترات السماح" (grace periods) among the approved subscription decisions and
    | `SAAS-004` makes the restriction of new selling conditional on one: "تقييد البيع الجديد بعد
    | انتهاء فترة السماح" — restrict new selling AFTER the grace period. Neither text states a
    | length, and no grace period exists anywhere in this codebase: measured 2026-11-26, the string
    | `grace` appears in `app/`, `database/`, `routes/`, `resources/views/` and `packages/workdo/`
    | exactly zero times, and the only hit in the whole repository is `config/jwt.php`'s unrelated
    | `blacklist_grace_period`.
    |
    | So this ships as NULL and `App\Support\Saas\SubscriptionLifecycle::graceDays()` answers null.
    | With it null, a lapsed subscription is reported as `EXPIRED_GRACE_UNDEFINED` and
    | `restrictsNewSelling()` is FALSE — nothing is restricted. That is the conservative direction:
    | the alternative is halting a paying-until-recently merchant's sales on a number this
    | repository invented, which CLAUDE.md §2 reserves to the owner ("material changes to
    | prices/payments/stock/business rules").
    |
    | THE QUESTION FOR THE OWNER, in one line: how many days after `plan_expire_date` may a
    | merchant keep taking new orders before new selling is restricted?
    |
    | Set `SAAS_GRACE_PERIOD_DAYS` to a whole number of days (0 means "from the day after the
    | expiry date") and the restriction takes effect. Anything that is not a whole number of days
    | >= 0 is treated as unset rather than as zero, so a typo cannot start stopping sales.
    |
    */

    'grace_period_days' => env('SAAS_GRACE_PERIOD_DAYS'),

];
