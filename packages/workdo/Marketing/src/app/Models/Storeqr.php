<?php

namespace Workdo\Marketing\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Storeqr extends Model
{
    use HasFactory, Cachable;
    protected $table = 'store_qrs'; 
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'store_id',
        
        'foreground_color',
        'background_color',
        'radius',
        'qr_type',
        'qr_text',
        'qr_text_color',
        'image',
        'size',
    ];
   
}
