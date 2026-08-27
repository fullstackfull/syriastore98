<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasFactory, Cachable;
    protected $fillable = [
        'first_name', 'last_name', 'email', 'contact', 'subject', 'description', 'store_id'
    ];
}
