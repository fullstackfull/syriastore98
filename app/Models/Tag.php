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

        /* F62b. A name made only of characters the regex above strips -- `!!!`, an emoji,
           a lone `+` -- reduced to the empty string, and `''` then went into `tags.slug`
           as the tag's slug. It is not NULL so it inserts, which is why nothing noticed,
           but it is not a slug either and two of them are indistinguishable. Since the
           tag field became free text (F16 -> F62) a merchant can type exactly that. */
        if ($slug === '') {
            $slug = 'tag';
        }

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

        /* F62b. Reaching here means `$slug`, `$slug-1` ... `$slug-100` are all taken, and
           this method USED TO FALL OFF THE END AND RETURN NULL -- which the callers assign
           straight into `tags.slug`, a NOT NULL column with no default. So the 101st tag
           sharing a base name was an explicit NULL insert: ER_BAD_NULL_ERROR on MySQL
           whatever the sql_mode, because a column default does not rescue an explicit NULL.
           That is the same 500 F62 was closing, moved a hundred rows down the road.

           The pool is also CROSS-TENANT: getRelatedSlugs() uses the query builder, so no
           store predicate and no global scope (`Tag` is not in config/tenancy.php's
           scoped_models). One store exhausting `sale-1..100` made every other tenant's
           first `sale` tag fail too. Nothing reads `tags.slug`, so the suffix leaks
           nothing; the shared failure is the part that matters.

           The `while` makes the fallback exact rather than merely improbable. */
        do {
            $newSlug = $slug . '-' . strtolower(\Illuminate\Support\Str::random(8));
        } while ($allSlugs->contains('slug', $newSlug));

        return $newSlug;
    }

    protected static function getRelatedSlugs($table, $slug, $id = 0)
    {
        return \DB::table($table)->select()->where('slug', 'like', $slug . '%')->where('id', '<>', $id)->get();
    }

}
