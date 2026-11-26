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
    | length, and no subscription grace period exists anywhere in this codebase.
    |
    | MEASURED PRECISELY, because the first draft of this comment overstated it. Searched as an
    | IDENTIFIER (`grace_period`, `grace_days`, `graceDays`, `gracePeriod`) across `app/`,
    | `database/`, `routes/`, `resources/`, `config/` and `packages/workdo/`: ONE hit, and it is
    | `config/jwt.php`'s unrelated `blacklist_grace_period`. The bare word `grace` is the WRONG
    | search and the draft's "zero times" was false -- the vendored WorkDo demo data contains
    | people called Grace.
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
