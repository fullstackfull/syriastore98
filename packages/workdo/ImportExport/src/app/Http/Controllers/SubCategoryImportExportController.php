<?php

namespace Workdo\ImportExport\app\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use Session;
use Workdo\ImportExport\app\Exports\SubcategoryExportData;
use Workdo\ImportExport\app\Imports\SubcategoryImportData;

class SubCategoryImportExportController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */

    public function fileImportExport()
    {
        if (Auth::user()->isAbleTo('sub-category import')) {
            return view('import-export::importexport.import-subcategory');
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
        Session::forget('subimportdata');
        try {
            Excel::import(new SubcategoryImportData, $request->file('csv_file'));
            $skippedSubcategory = Session::get('subimportdata', []);
            if (!empty($skippedSubcategory)) {
                foreach ($skippedSubcategory as $subcategory) {
                }
                return redirect()->back()->with('error', __('Some sub category were skipped due to duplication'));
            } else {
                return redirect()->back()->with('success', __('Sub category imported successfully'));
            }
            $import = Excel::import(new SubcategoryImportData, $request->file('csv_file'));
            return redirect()->back()->with('success', __('Sub category imported successfully'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Error importing sub category: :message', ['message' => $e->getMessage()]));
        }
    }


    public function exportSubcategory()
    {
        return Excel::download(new SubcategoryExportData, 'sub-category.xlsx');
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
