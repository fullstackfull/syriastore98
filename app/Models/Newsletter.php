<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Newsletter extends Model
{
    use HasFactory, Cachable;

    /**
     * `$slug` lost a default `$section` never had, so PHP already required both. Arity-identical;
     * `NoOptionalBeforeRequiredParameterTest` holds the rule. The method has no caller.
     */
    public static function Subscribe($slug, $section)
    {
        return view('front_end.sections.pages.subscribe_form', compact('slug', 'section'))->render();
    }
}
