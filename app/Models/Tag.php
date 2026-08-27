<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    use HasFactory, Cachable;
    protected $fillable =[
        'name',
        'slug',
        
        'store_id',
        'created_by'
    ];


    public static function slugs($data)
    {
        $slug = '';
        $slug = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $data); // Remove special chars
        // $slug = transliterator_transliterate('Any-Latin; Latin-ASCII', $slug); // Transliterate to Latin
        $slug = strtolower(trim($slug)); // Convert to lowercase and trim
        $slug = preg_replace('/\s+/', '-', $slug); // Replace spaces with hyphens
        $slug = preg_replace('/-+/', '-', $slug); // Replace multiple hyphens with single hyphen

        $table = with(new Tag)->getTable();
        $allSlugs = self::getRelatedSlugs($table, $slug ,$id = 0);

        if (!$allSlugs->contains('slug', $slug)) {
            return $slug;
        }
        for ($i = 1; $i <= 100; $i++) {
            $newSlug = $slug . '-' . $i;
            if (!$allSlugs->contains('slug', $newSlug)) {
                return $newSlug;

            }
        }
    }

    protected static function getRelatedSlugs($table, $slug, $id = 0)
    {
        return \DB::table($table)->select()->where('slug', 'like', $slug . '%')->where('id', '<>', $id)->get();
    }

}
