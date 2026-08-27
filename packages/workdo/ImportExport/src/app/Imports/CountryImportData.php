<?php

namespace Workdo\ImportExport\app\Imports;

use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Validator;
use App\Models\Country;
use Illuminate\Support\Facades\Session;


class CountryImportData implements ToModel, WithHeadingRow
{
    /**
     * @param array $row
     *
     * @return Country|null
     */
    public function model(array $row)
    {
        $validator = Validator::make($row, [
            'country_name' => 'required',
        ]);

        if ($validator->fails()) {
            \Log::error('Validation failed for row: ' . json_encode($row));
            Session::push('countryimportdataerror', $validator->getMessageBag()->first());
            return null;
        }
        
        $existingCountry = Country::where('name', $row['country_name'])->first();

        if ($existingCountry) {
            $existingCountry->import_status = 'Skipped: Duplicate country';
            Session::push('countryimportdata', $existingCountry);
            return null;
        }

        $country = new Country([
            'name' => $row['country_name'] ?? '',
        ]);

        $country->save();

        return $country;
    }
}
