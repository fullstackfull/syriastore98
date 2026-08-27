<?php

namespace Workdo\ImportExport\app\Exports;

use App\Models\SubCategory;
use App\Models\Store;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Collection;

class SubcategoryExportData implements FromCollection, WithHeadings
{
    public function headings(): array
    {
        return [
            "Name",
            "Main Category",
        ];
    }

    public function collection(): Collection
    {
        $storeId = getCurrentStore() ?? \Auth::user()->current_store;
        $store = Store::findOrFail($storeId);

        $subcategories = SubCategory::where('store_id', $store->id)
                            ->with('Category')
                            ->get();

        $exportData = $subcategories->map(function ($subcategory) {
            return [
                'Name' => isset($subcategory->name) ? $subcategory->name : '',
                'Main Category' => optional($subcategory->Category)->name ?? '',
            ];
        });
        return $exportData;
    }
}
