<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlanRequest extends Model
{
    use HasFactory, Cachable;
    protected $fillable = [
        'user_id',
        'plan_id',
        'duration',
    ];
    public function user()
    {
        return $this->hasOne('App\Models\User', 'id', 'user_id');
    }
    public function plan()
    {
        return $this->hasOne('App\Models\Plan', 'id', 'plan_id');
    }
}
