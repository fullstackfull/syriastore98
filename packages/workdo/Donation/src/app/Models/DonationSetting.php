<?php

namespace Workdo\Donation\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DonationSetting extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'name', 'value',  'created_by', 'store_id'
    ];
}
