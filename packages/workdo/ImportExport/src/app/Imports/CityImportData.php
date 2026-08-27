<?php

namespace Workdo\ImportExport\app\Imports;

use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Validator;
use App\Models\Country;
use App\Models\City;
use App\Models\State;
use Illuminate\Support\Facades\Session;


class CityImportData implements ToModel, WithHeadingRow
{
    /**
     * @param array $row
     *
     * @return City|null
     */
    public function model(array $row)
    {
        $validator = Validator::make($row, [
            'country_name' => 'required',
            'state_name' => 'required',
            'city_name' => 'required',
        ]);

        if ($validator->fails()) {
            \Log::error('Validation failed for row: ' . json_encode($row));
            Session::push('cityimportdataerror', $validator->getMessageBag()->first());
            return null;
        }
        
        $country = Country::firstOrCreate(
            ['name' => $row['country_name']]
        );

        $state = State::firstOrCreate(
            ['name' => $row['state_name'], 'country_id' => $country->id]
        );

        $existingCity = City::where('name', $row['city_name'])->where('country_id', $country->id)->where('state_id', $state->id)->first();

        if ($existingCity) {
            $existingCity->import_status = 'Skipped: Duplicate city';
            Session::push('cityimportdata', $existingCity);
            return null;
        }

        $city = new City([
            'name' => $row['city_name'] ?? '',
            'country_id' => $country->id,
            'state_id' => $state->id,
        ]);

        $city->save();

        return $city;
    }
}
