<?php

namespace Workdo\ImportExport\app\Exports;

use App\Models\State;
use App\Models\Store;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Collection;

class StateExportData implements FromCollection, WithHeadings
{
    public function headings(): array
    {
        return [
            "Country Name",
            "State Name",
        ];
    }

    public function collection(): Collection
    {
        $storeId = getCurrentStore() ?? \Auth::user()->current_store;
        $store = Store::findOrFail($storeId);

        $states = State::get();

        $exportData = $states->map(function ($state) {
            return [
                'Country Name' => optional($state->country)->name ?? '',
                'State Name' => isset($state->name) ? $state->name : '',
            ];
        });
        return $exportData;
    }
}
