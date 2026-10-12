<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToStore;

class StoreWithdrawal extends Model
{
    use BelongsToStore;
    protected $table = 'store_withdrawals';

    protected $fillable = [
        'store_id',
        'wallet_id',
        'amount',
        'status',
        'payment_method',
        'payment_details',
        'store_note',
        'admin_note',
        'requested_by',
        'reviewed_by',
        'requested_at',
        'reviewed_at',
        'paid_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function wallet()
    {
        return $this->belongsTo(StoreWallet::class, 'wallet_id');
    }
}