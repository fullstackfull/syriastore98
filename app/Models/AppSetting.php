<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
    	
    	'page_name',
    	'store_id',
    	'theme_json',
    	'theme_json_api'
    ];
}
