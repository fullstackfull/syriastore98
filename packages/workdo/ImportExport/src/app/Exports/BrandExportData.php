<?php

namespace Workdo\ImportExport\app\Exports;

use App\Models\ProductBrand;
use App\Models\Store;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Collection;

class BrandExportData implements FromCollection, WithHeadings
{
    public function headings(): array
    {
        return [
            "Name",
        ];
    }

    public function collection(): Collection
    {
        $storeId = getCurrentStore() ?? \Auth::user()->current_store;
        $store = Store::findOrFail($storeId);

        $brands = ProductBrand::where('store_id', $store->id)
                            ->get();
        $exportData = $brands->map(function ($brand) {
            return [
                'Name' => isset($brand->name) ? $brand->name : '',
            ];
        });
        return $exportData;
    }
}
