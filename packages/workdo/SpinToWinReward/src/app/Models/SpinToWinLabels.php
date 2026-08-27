<?php

namespace Workdo\SpinToWinReward\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SpinToWinLabels extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'name',
        
        'store_id'
    ];
}
