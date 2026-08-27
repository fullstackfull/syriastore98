<?php

namespace Workdo\LandingPage\Entities;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Utility;
use Illuminate\Support\Facades\Storage;

class OwnerMenu extends Model
{
    use HasFactory, Cachable;


    protected $fillable = [
        'name',
        'content',
        'created_by'
    ];

}
