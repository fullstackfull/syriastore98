<?php

namespace Workdo\ImportExport\app\Exports;

use App\Models\Country;
use App\Models\Store;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Collection;

class CountryExportData implements FromCollection, WithHeadings
{
    public function headings(): array
    {
        return [
            "Country Name",
        ];
    }

    public function collection(): Collection
    {
        $countries = Country::get();

        $exportData = $countries->map(function ($country) {
            return [
                'Country Name' => isset($country->name) ? $country->name : '',
            ];
        });
        return $exportData;
    }
}
