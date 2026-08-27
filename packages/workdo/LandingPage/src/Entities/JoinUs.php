<?php

namespace Workdo\LandingPage\Entities;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class JoinUs extends Model
{
    use HasFactory, Cachable;

    protected $table = 'join_us';

    protected $fillable = ['email'];

    protected static function newFactory()
    {
        return \Workdo\LandingPage\Database\factories\JoinUsFactory::new();
    }
}
