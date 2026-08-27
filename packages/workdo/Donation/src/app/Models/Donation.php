<?php

namespace Workdo\Donation\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Donation extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'title',
        'description',
        'note',
        'amount',
        'created_by',
        'store_id',
    ];
}
