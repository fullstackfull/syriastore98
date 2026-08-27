<?php

namespace Workdo\LotteryPrizes\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LotteryCustomer extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        "ticket_id","lottery_id","lottery_price","lottery_code","customer_id","status","email_sent","store_id"
    ];

    public function Lottery()
    {
        return $this->belongsTo(PurchaseTicket::class, 'lottery_id');
    }

    public function tickets()
    {
        return $this->hasOne(PurchaseTicket::class, 'lottery_code', 'lottery_code');
    }
}
