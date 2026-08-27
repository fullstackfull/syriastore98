<?php

namespace Workdo\ProductAffiliate\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TransactionOrder extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'req_amount',
        'req_user_id',
        'status',
        'date',
        'store_id',
    ];

    public function getCompany()
    {
        return $this->hasOne('App\Models\Customer', 'id', 'req_user_id');
    }

    public static $status = [
        'Rejected',
        'In Progress',
        'Approved',
    ];
}
