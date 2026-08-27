<?php

namespace Workdo\ImportExport\app\Imports;

use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Validator;
use App\Models\Product;
use App\Models\Category;
use App\Models\SubCategory;
use Illuminate\Support\Facades\Session;


class SubcategoryImportData implements ToModel, WithHeadingRow
{
    /**
     * @param array $row
     *
     * @return SubCategory|null
     */
    public function model(array $row)
    {
        $validator = Validator::make($row, [
            'main_category' => 'required',
            'name' => 'required',
        ]);

        if ($validator->fails()) {
            \Log::error('Validation failed for row: ' . json_encode($row));
            return null;
        }

        $maincategory = Category::firstOrCreate(
            ['name' => $row['main_category']],
            [
                'slug' => 'collections/' . strtolower(preg_replace("/[^\w]+/", "-", $row['main_category'])),
                'image_url' => 'packages/workdo/ImportExport/src/resources/assets/img/img.png',
                'image_path' => 'packages/workdo/ImportExport/src/resources/assets/img/img.png',
                'icon_path' => 'packages/workdo/ImportExport/src/resources/assets/img/img.png',
                'trending' => 0,
                'status' => 0,
                'store_id' => getCurrentStore(),
            ]
        );
        $existingSubcategory = Category::where('name', $row['name'])
            ->where('category_id', $maincategory->id)
            ->where('store_id', getCurrentStore())
            ->first();

        if ($existingSubcategory) {
            $existingSubcategory->import_status = 'Skipped: Duplicate sub category';
            Session::push('subimportdata', $existingSubcategory);
            return null;
        }

        $subcategory = new Category([
            'name' => $row['name'] ?? '',
            'category_id' => $maincategory->id,
            'store_id' => getCurrentStore(),
        ]);
        $subcategory->save();

        return $subcategory;
    }
}
