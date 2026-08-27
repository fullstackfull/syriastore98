<?php

namespace Workdo\ImportExport\app\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use Session;
use Workdo\ImportExport\app\Exports\CityExportData;
use Workdo\ImportExport\app\Imports\CityImportData;

class CityImportExportController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */

    public function fileImportExport()
    {
        if (auth()->user() && auth()->user()->type == 'super admin') {
            return view('import-export::importexport.import-city');
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function fileImport(Request $request)
    {
        $rules = [
            'csv_file' => 'required|mimes:xlsx,xls,csv,txt',
        ];

        $validator = \Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            $messages = $validator->getMessageBag()->first();
            return redirect()->back()->with('error', $messages);
        }
        Session::forget('cityimportdata');
        Session::forget('cityimportdataerror');
        try {
            Excel::import(new CityImportData, $request->file('csv_file'));
            $skippedCity = Session::get('cityimportdata', []);
            $error = Session::get('cityimportdataerror');
            session()->put('country_active_tab', 'pills-city-tab');
            if (!empty($error)) {
                return redirect()->back()->with('error', $error[0]);
            }
            if (!empty($skippedCity)) {
                foreach ($skippedCity as $city) {
                }
                return redirect()->back()->with('error', __('Some city were skipped due to duplication'));
            } else {
                return redirect()->back()->with('success', __('City imported successfully'));
            }
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Error importing city: :message', ['message' => $e->getMessage()]));
        }
    }


    public function exportCity()
    {
        return Excel::download(new CityExportData, 'city.xlsx');
    }
    
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index()
    {
        return view('import-export::index');
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        return view('import-export::create');
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        return view('import-export::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        return view('import-export::edit');
    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param int $id
     * @return Renderable
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     * @param int $id
     * @return Renderable
     */
    public function destroy($id)
    {
        //
    }
}
