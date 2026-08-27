<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    use HasFactory, Cachable;
    protected $fillable = [
        'id','name', 'state_id', 'state_code', 'country_id', 'country_code', 'latitude', 'longitude', 'flag', 'wikiDataId'
    ];

    public function country()
    {
        return $this->hasOne(Country::class, 'id', 'country_id');
    }

    public function state()
    {
        return $this->belongsTo(State::class);
    }
}
