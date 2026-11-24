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
        array $newValues = []
    ) {
        /*
         * THE ACTING USER IS PRESERVED EXACTLY AS IT WAS -- `auth()->id()`, unchanged. Only the
         * STORE attribution moves, and only because `getCurrentStore()` fabricates one for the
         * platform operator: it returns a hardcoded `1` for `type === 'super admin'`, so every
         * operator action was filed under store 1 whoever it was taken against.
         *
         * `App\Support\Audit\AuditAttribution` has the full reasoning and the precedence order.
         * The short version: the SUBJECT's own `store_id` wins over the actor's session, an
         * operator with no store-bearing subject is `null` rather than 1, and a merchant keeps the
         * attribution they already had.
         *
         * The explicit `auth('web')->user()` is CLAUDE.md's rule, not decoration: `auth()->user()`
         * reads the default guard, which `CustomAuth` can have set to `customers` or `deliveryboy`,
         * and a `Customer` is not a `User`.
         */
        return self::create([
            'user_id' => auth()->id(),
            'store_id' => \App\Support\Audit\AuditAttribution::storeFor($subject, auth('web')->user()),

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