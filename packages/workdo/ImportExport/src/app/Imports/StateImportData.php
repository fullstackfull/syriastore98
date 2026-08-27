<?php

namespace Workdo\ImportExport\app\Imports;

use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Validator;
use App\Models\Country;
use App\Models\State;
use Illuminate\Support\Facades\Session;


class StateImportData implements ToModel, WithHeadingRow
{
    /**
     * @param array $row
     *
     * @return State|null
     */
    public function model(array $row)
    {
        $validator = Validator::make($row, [
            'country_name' => 'required',
            'state_name' => 'required',
        ]);

        if ($validator->fails()) {
            \Log::error('Validation failed for row: ' . json_encode($row));
            Session::push('stateimportdataerror', $validator->getMessageBag()->first());
            return null;
        }
        
        $country = Country::firstOrCreate(
            ['name' => $row['country_name']]
        );

        $existingState = State::where('name', $row['state_name'])->where('country_id', $country->id)->first();

        if ($existingState) {
            $existingState->import_status = 'Skipped: Duplicate state';
            Session::push('stateimportdata', $existingState);
            return null;
        }

        $state = new State([
            'name' => $row['state_name'] ?? '',
            'country_id' => $country->id,
        ]);

        $state->save();

        return $state;
    }
}
