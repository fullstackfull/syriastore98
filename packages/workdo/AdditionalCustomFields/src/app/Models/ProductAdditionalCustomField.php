<?php

namespace Workdo\AdditionalCustomFields\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProductAdditionalCustomField extends Model
{
    use HasFactory, Cachable;

    protected $table = 'product_additional_custom_fields'; // Define the table name

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'store_id',
        
        'product_id',
        'field_id',
        'label',
        'value',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
}
