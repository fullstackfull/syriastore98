<?php

namespace Workdo\LotteryPrizes\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LotterySetting extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'min_ticket',
        'max_ticket_per_user',
        'total_ticket',
        'number_of_winner',
        'lottery_start_date',
        'lottery_end_date',
        'lottery_start_time',
        'lottery_end_time',
        'lottery_products',
        'refund_ticket',
        'ticket_price',
        'created_by',
        
        'store_id'
    ];

    public function purchaseTickets()
    {
        return $this->hasMany(PurchaseTicket::class, 'lottery_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'lottery_products');
    }
}
