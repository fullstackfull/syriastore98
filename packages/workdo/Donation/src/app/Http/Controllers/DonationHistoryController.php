<?php

namespace Workdo\Donation\app\Http\Controllers;

use App\Models\Store;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Workdo\Donation\app\Exports\DonationExport;
use Workdo\Donation\app\Models\CustomerDonation;
use Workdo\Donation\app\Models\DonationSetting;
use Workdo\Donation\DataTables\CustomerDonationData;

class DonationHistoryController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index(CustomerDonationData $dataTable)
    {
        $user = auth()->user();
        $store = Store::find($user->current_store);
        $donations_data = CustomerDonation::where('store_id', $store->id)->get();
        $donation_setting = DonationSetting::where('created_by', $user->id)->where('store_id', $store->id)->pluck('value', 'name')->toArray();
        if (isset($donation_setting['enable_donation']) && ($donation_setting['enable_donation'] == 'on')) {
            return $dataTable->render('donation::admin.donation-history.index', compact('donations_data'));
        } else {
            return redirect()->route('dashboard')->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        return view('donation::create');
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
        return view('donation::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        return view('donation::edit');
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
        $donation = CustomerDonation::find($id);
        if ($donation) {
            $donation->delete();
            return redirect()->back()->with('success', __('History delete successfully.'));
        } else {
            return redirect()->back()->with('error', __('The donation is not found.'));
        }
    }

    public function fileExport()
    {
        $fileName = 'Donation.xlsx';
        return Excel::download(new DonationExport, $fileName);
    }
}
