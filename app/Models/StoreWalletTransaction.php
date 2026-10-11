<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreWalletTransaction extends Model
{
    protected $table = 'store_wallet_transactions';

    protected $fillable = [
        'store_id',
        'wallet_id',
        'type',
        'direction',
        'amount',
        'balance_before',
        'balance_after',
        'reference_type',
        'reference_id',
        'description',
        'status',
        'created_by',
        'currency_code',
    ];

    public function wallet()
    {
        return $this->belongsTo(StoreWallet::class, 'wallet_id');
    }
}