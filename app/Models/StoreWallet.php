<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreWallet extends Model
{
    protected $table = 'store_wallets';

    protected $fillable = [
        'store_id',
        'available_balance',
        'pending_balance',
        'total_income',
        'currency_code',
        'total_withdrawn',
        'withdrawal_pending_balance',
    ];

    public function transactions()
    {
        return $this->hasMany(StoreWalletTransaction::class, 'wallet_id');
    }
}