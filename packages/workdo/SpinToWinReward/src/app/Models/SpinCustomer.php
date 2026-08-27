<?php

namespace Workdo\SpinToWinReward\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SpinCustomer extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'name',
        'email',
        'winner_value',
        'number',
        'is_used',
        'store_id',
    ];
}
