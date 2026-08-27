<?php

namespace Workdo\AdditionalCustomFields\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AdditionalCustomFields extends Model
{

        use HasFactory, Cachable;

        protected $fillable = ['store_id',  'label', 'type', 'options'];
    
        protected $casts = [
            'options' => 'array', // Automatically cast JSON options to an array
        ];
}
    
