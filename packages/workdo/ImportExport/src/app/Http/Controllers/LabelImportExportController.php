<?php

namespace Workdo\ImportExport\app\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use Session;
use Workdo\ImportExport\app\Exports\LabelExportData;
use Workdo\ImportExport\app\Imports\LabelImportData;

class LabelImportExportController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */

    public function fileImportExport()
    {
        if (Auth::user()->isAbleTo('label import')) {
            return view('import-export::importexport.import-label');
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
        Session::forget('labelimportdata');
        try {
            Excel::import(new LabelImportData, $request->file('csv_file'));
            $skippedLabels = Session::get('labelimportdata', []);

            if (!empty($skippedLabels)) {
                foreach ($skippedLabels as $label) {
                }
                return redirect()->back()->with('error', __('Some label were skipped due to duplication'));
            } else {
                return redirect()->back()->with('success', __('Label imported successfully'));
            }
            $import = Excel::import(new LabelImportData, $request->file('csv_file'));
            return redirect()->back()->with('success', __('Label imported successfully'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Error importing Label: :message', ['message' => $e->getMessage()]));
        }
    }
    public function exportLabel()
    {
        return Excel::download(new LabelExportData, 'product-label.xlsx');
    }

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
