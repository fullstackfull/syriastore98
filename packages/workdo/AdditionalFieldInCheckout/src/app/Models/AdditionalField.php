<?php

namespace Workdo\AdditionalFieldInCheckout\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AdditionalField extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'name',
        'type',
        'field_title',
        'label',
        'class',
        'placeholder',
        'default_value',
        'options',
        
        'store_id'

    ];

    public static $type = [
        'text' => 'Text',
        'password' => 'Password',
        'email' => 'Email',
        'number' => 'Number',
        'select' => 'Select Box',
        'textarea' => 'Textarea',
    ];
}
