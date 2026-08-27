<?php

namespace App\Http\Controllers;
use App\Models\Country;
use App\Models\State;
use App\Models\City;
use Illuminate\Support\Facades\Auth;
use App\DataTables\CountryDataTable;
use Yajra\DataTables\Facades\DataTables;

use Illuminate\Http\Request;

class CountryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        if (auth()->user() && auth()->user()->type == 'super admin') {
            $country_active_tab = session()->get('country_active_tab');
            
            if (empty($country_active_tab)) {
                $country_active_tab = 'pills-country-tab';
            }
            if ($request->ajax()) {
                $data = Country::select(['id', 'name']);
                return DataTables::of($data)
                    ->addColumn('action', function (Country $country) {
                        return view('country.action', compact('country'));
                    })
                    ->rawColumns(['action'])
                    ->make(true);
            }
            
            
            
            $get_country = Country::orderBy('name','ASC')->get()->pluck('name', 'id');

            $get_country->prepend('Select Country', 0);
            $get_state = State::orderBy('name','ASC')->get()->pluck('name', 'id');

            $get_state->prepend('Select State', 0);
           
            $country_active_tab = session()->get('country_active_tab');
            
            if (empty($country_active_tab)) {
                $country_active_tab = 'pills-country-tab';
            }
            return view('country.index',compact('get_country','get_state','country_active_tab'));



        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        if (auth()->user() && auth()->user()->type == 'super admin') {
            return view('country.create');
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if (auth()->user() && auth()->user()->type == 'super admin') {
            $countries = new Country();
            $countries->name = $request->name;
            $countries->save();
            session()->put('country_active_tab', $request->country_active_tab);
            return redirect()->back()->with('success', __('Country successfully created.'));
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Country $country)
    {
        if (auth()->user() && auth()->user()->type == 'super admin') {
            return view('country.edit',compact('country'));
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Country $country)
    {
        if (auth()->user() && auth()->user()->type == 'super admin') {
            $country->name = $request->name;
            $country->save();
            session()->put('country_active_tab', $request->country_active_tab);
            return redirect()->back()->with('success', __('Country successfully updated.'));
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Country $country)
    {
        if (auth()->user() && auth()->user()->type == 'super admin') {
            $country->delete();
            return redirect()->back()->with('success', __('Country successfully deleted.'));
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    public function getCountry(Request $request)
    {
        $query = State::where('country_id' , '=' ,$request->country);
        if (!empty($request->country)) {
            $query->where('country_id', '=', $request->country);
        }
        $filter_country = $query->orderBy('name','ASC')->get();

        $filter = view('country.filter', compact('filter_country'))->render();

        return response()->json($filter);
    }

    public function getAllCountry(CountryDataTable $dataTable)
    {
        try {
                $country_active_tab = session()->get('country_active_tab');
                    
                if (empty($country_active_tab)) {
                    $country_active_tab = 'pills-country-tab';
                }
                // Render the view and capture the HTML output
                $html = view('country.tab', ['dataTable' => $dataTable, 'country_active_tab' => $country_active_tab])->render();

                // Return JSON response
                return response()->json([
                    'is_success' => true,
                    "msg" => __("Country form get successfully"),
                    "data" => ['content' => $html]
                ]);
            } catch (\Exception $e) {
                // Return JSON response
                return response()->json([
                'is_success' => false,
                "msg" => __('Something went wrong!'),
                "data" => ['content' => null]
            ]);
        }
    }

}
