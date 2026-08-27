<?php

namespace Workdo\ImportExport\app\Imports;

use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Validator;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Support\Facades\Session;


class MaincategoryImportData implements ToModel, WithHeadingRow
{
    /**
     * @param array $row
     *
     * @return Category|null
     */

    public function model(array $row)
    {
        // Validate each row of data
        $validator = Validator::make($row, [
            'name' => 'required',
        ]);

        if ($validator->fails()) {
            \Log::error('Validation failed for row: ' . json_encode($row));
            return null;
        }

        $existingMaincategory = Category::where('name', $row['name'])->where('store_id', getCurrentStore())->first();

        if ($existingMaincategory) {
            $existingMaincategory->import_status = 'Skipped: Duplicate main category';
            Session::push('mainimportdata', $existingMaincategory);
            return null;
        }

        $maincategory = new Category([
            'name' => $row['name'] ?? '',
            'slug' => self::slugs($row['name'] ?? ''),
            'store_id' => getCurrentStore(),
        ]);
        $maincategory->save();

        return $maincategory;
    }
    public static function slugs($data)
    {
        $slug = '';
        $slug = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $data); // Remove special chars
        // $slug = transliterator_transliterate('Any-Latin; Latin-ASCII', $slug); // Transliterate to Latin
        $slug = strtolower(trim($slug)); // Convert to lowercase and trim
        $slug = preg_replace('/\s+/', '-', $slug); // Replace spaces with hyphens
        $slug = preg_replace('/-+/', '-', $slug); // Replace multiple hyphens with single hyphen

        $table = with(new Category)->getTable();
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
        return Category::select()->where('slug', 'like', $slug . '%')->where('id', '<>', $id)->get();
    }
}
