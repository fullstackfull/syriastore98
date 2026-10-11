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
        return self::create([
            'user_id' => auth()->id(),
            'store_id' => getCurrentStore(),

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