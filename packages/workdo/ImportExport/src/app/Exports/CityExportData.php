<?php

namespace Workdo\ImportExport\app\Exports;

use App\Models\City;
use App\Models\Store;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Collection;

class CityExportData implements FromCollection, WithHeadings
{
    public function headings(): array
    {
        return [
            "Country Name",
            "State Name",
            "City Name",
        ];
    }

    public function collection(): Collection
    {
        $cities = City::get();

        $exportData = $cities->map(function ($city) {
            return [
                'Country Name' => optional($city->country)->name ?? '',
                'State Name' => optional($city->state)->name ?? '',
                'City Name' => isset($city->name) ? $city->name : '',
            ];
        });
        return $exportData;
    }
}
