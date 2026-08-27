<?php

namespace Workdo\ImportExport\app\Exports;

use App\Models\ProductLabel;
use App\Models\Store;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Collection;

class LabelExportData implements FromCollection, WithHeadings
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

        $labels = ProductLabel::where('store_id', $store->id)
                            ->get();
        $exportData = $labels->map(function ($label) {
            return [
                'Name' => isset($label->name) ? $label->name : '',
            ];
        });
        return $exportData;
    }
}
