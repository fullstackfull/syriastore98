<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class userActiveModule extends Model
{
    use HasFactory, Cachable;

    protected $fillable = ['user_id','module'];

}
