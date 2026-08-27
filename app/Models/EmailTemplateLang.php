<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailTemplateLang extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'parent_id', 'language', 'subject', 'content', 'is_default'
    ];
}
