<?php

namespace Workdo\ImportExport\app\Imports;

use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Validator;
use App\Models\ProductBrand;
use Illuminate\Support\Facades\Session;


class BrandImportData implements ToModel, WithHeadingRow
{
    /**
     * @param array $row
     *
     * @return ProductBrand|null
     */
    public function model(array $row)
    {
        $validator = Validator::make($row, [
            'name' => 'required',
        ]);

        if ($validator->fails()) {
            \Log::error('Validation failed for row: ' . json_encode($row));
            return null;
        }

        $existingBrand = ProductBrand::where('name', $row['name'])->where('store_id', getCurrentStore())->first();

        if ($existingBrand) {
            $existingBrand->import_status = 'Skipped: Duplicate brand';
            Session::push('brandimportdata', $existingBrand);
            return null;
        }

        $brand = new ProductBrand([
            'name' => $row['name'] ?? '',
            'slug' => ProductBrand::slugs($row['name'] ?? ''),
            'store_id' => getCurrentStore(),
            'status'=>'0',
            'created_by' => \Auth::user()->creatorId(),
        ]);
        $brand->save();

        return $brand;
    }
}
