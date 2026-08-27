<?php

namespace Workdo\ImportExport\app\Imports;

use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Validator;
use App\Models\ProductLabel;
use Illuminate\Support\Facades\Session;


class LabelImportData implements ToModel, WithHeadingRow
{
    /**
     * @param array $row
     *
     * @return ProductLabel|null
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

        $existingLabel = ProductLabel::where('name', $row['name'])->where('store_id', getCurrentStore())->first();

        if ($existingLabel) {
            $existingLabel->import_status = 'Skipped: Duplicate label';
            Session::push('labelimportdata', $existingLabel);
            return null;
        }

        $label = new ProductLabel([
            'name' => $row['name'] ?? '',
            'slug' => ProductLabel::slugs($row['name'] ?? ''),
            'store_id' => getCurrentStore(),
            'status'=>'0',
            'created_by' => \Auth::user()->creatorId(),
        ]);
        $label->save();

        return $label;
    }
}
