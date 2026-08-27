<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Tax;
use App\Models\TaxOption;
use App\Models\Country;
use App\Models\TaxMethod;
use App\Models\Plan;
use App\DataTables\TaxDataTable;
use App\DataTables\TaxMethodDataTable;

class TaxController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(TaxDataTable $dataTable)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Manage Tax'))
        {
            $setting = getAdminAllSetting();
            if(isset($setting['taxes']) && $setting['taxes'] == 'on')
            {
                return  $dataTable->render('taxes.index');
            } else {
                return redirect()->back()->with('error', __('Tax setting is disabled please contact to your admin to enable tax.'));
            }
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('taxes.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Create Tax'))
        {
            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => 'required',
                ]
            );

            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            $tax                        = new Tax();
            $tax->name                 = $request->name;
            $tax->store_id              = getCurrentStore();
            $tax->save();
            return redirect()->back()->with('success', __('Tax successfully created.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id, TaxMethodDataTable $dataTable)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Show Tax'))
        {
            $tax_option = Tax::find($id);

            $dataTable->setTaxId($id);
            return $dataTable->render('taxes.show', compact('tax_option'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $taxes = Tax::find($id);
        return view('taxes.edit', compact('taxes'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {

        if(auth()->user() && auth()->user()->isAbleTo('Edit Tax'))
        {
            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => 'required',
                ]
            );

            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
            $tax = Tax::find($id);
            $tax->name                 = $request->name;
            $tax->store_id              = getCurrentStore();
            $tax->save();

            return redirect()->back()->with('success', __('Tax successfully updated.'));

        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {

        if(auth()->user() && auth()->user()->isAbleTo('Delete Tax'))
        {
            $tax = Tax::find($id);
            $tax->delete();
            return redirect()->back()->with('success', __('Tax delete successfully.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
}
