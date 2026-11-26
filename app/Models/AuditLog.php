<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    protected $fillable = [
        'user_id',
        'store_id',
        'action',
        'subject_type',
        'subject_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public static function record(
        string $action,
        $subject = null,
        array $oldValues = [],
        array $newValues = [],
        mixed $actor = null
    ) {
        /*
         * BOTH HALVES NOW READ THE SAME ACTOR, AND IT CAN BE PASSED IN. Two changes, for two
         * different reasons.
         *
         * (a) `$actor`, OPTIONAL AND ADDITIVE -- every one of the 18 existing call sites passes at
         *     most four arguments and none of them changes. It exists because
         *     `App\Support\Audit\RecordImpersonation` CANNOT read the guard: lab404's
         *     `ImpersonateManager::take()` dispatches `TakeImpersonation` AFTER `quietLogin($to)`,
         *     so at listener time the session holds the IMPERSONATED MERCHANT and a row built from
         *     `auth()` would say the merchant impersonated themselves. The event carries the real
         *     impersonator; this parameter is how it gets here.
         *
         * (b) `user_id` NOW READS THE `web` GUARD EXPLICITLY. It was `auth()->id()` -- the DEFAULT
         *     guard -- while the store attribution beside it read `auth('web')` explicitly. CLAUDE.md
         *     records that `CustomAuth` can leave the default guard set to `customers` or
         *     `deliveryboy`, so on such a request the two disagreed and a CUSTOMER id could be
         *     written into a column that means a `users` row. LATENT rather than live, because every
         *     current call site is panel-only -- and now impossible rather than unlikely. A
         *     non-`User` principal writes `null`, which is the honest answer and fails safe.
         *
         * Only the STORE attribution moved in the previous change, and only because
         * `getCurrentStore()` fabricates one for the platform operator: it returns a hardcoded `1`
         * for `type === 'super admin'`, so every operator action was filed under store 1 whoever it
         * was taken against.
         *
         * `App\Support\Audit\AuditAttribution` has the full reasoning and the precedence order.
         * The short version: the SUBJECT's own `store_id` wins over the actor's session, an
         * operator with no store-bearing subject is `null` rather than 1, and a merchant keeps the
         * attribution they already had.
         *
         * The explicit `auth('web')` is CLAUDE.md's rule, not decoration: `auth()->user()` reads the
         * default guard, which `CustomAuth` can have set to `customers` or `deliveryboy`, and a
         * `Customer` is not a `User`.
         */
        $acting = $actor instanceof User ? $actor : auth('web')->user();

        return self::create([
            'user_id' => $acting?->id,
            'store_id' => \App\Support\Audit\AuditAttribution::storeFor($subject, $acting),

            'action' => $action,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject?->id,

            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,

            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}