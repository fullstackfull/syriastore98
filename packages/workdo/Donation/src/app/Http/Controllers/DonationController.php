<?php

namespace Workdo\Donation\app\Http\Controllers;

use App\Models\Store;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use DB;
use Workdo\Donation\app\Models\Donation;
use Workdo\Donation\app\Models\DonationSetting;
use Workdo\Donation\DataTables\DonationData;

class DonationController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index(DonationData $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Donation')) {
            $user = auth()->user();
            $store = Store::find($user->current_store);
            $donations = Donation::where('created_by', $user->id)->where('store_id', $store->id)->get();
            $donation_setting = DonationSetting::where('created_by', $user->id)->where('store_id', $store->id)->pluck('value', 'name')->toArray();
            if (isset($donation_setting['enable_donation']) && ($donation_setting['enable_donation'] == 'on')) {
                return $dataTable->render('donation::admin.donation.index', compact('donations', 'store'));
            } else {
                return redirect()->route('dashboard')->with('error', __('Permission denied.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        return view('donation::admin.donation.create');
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Create Donation')) {
            $validator = \Validator::make(
                $request->all(),
                [
                    'title' => 'required',
                    'description' => 'required'
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
            $user = auth()->user();
            if ($user) {
                $store = Store::find($user->current_store);

                $donation = new Donation();
                $donation->title = $request->title;
                $donation->description = $request->description;
                $donation->note = $request->note;
                $donation->amount = json_encode($request->amount_repeater_basic);
                $donation->created_by = auth()->user()->id;
                $donation->store_id = getCurrentStore();
                $donation->save();
                return redirect()->back()->with('success', __('Donation successfully created.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
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
        $donations = Donation::find($id);
        if ($donations) {
            return view('donation::admin.donation.edit', compact('donations'));
        } else {
            return redirect()->back()->with('error', __('The donation is not found.'));
        }

    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param int $id
     * @return Renderable
     */
    public function update(Request $request, $id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Edit Donation')) {
            $validator = \Validator::make(
                $request->all(),
                [
                    'title' => 'required',
                    'description' => 'required'
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
            $donation = Donation::find($id);
            if ($donation) {
                $donation->title = $request->title;
                $donation->description = $request->description;
                $donation->note = $request->note;
                $donation->amount = json_encode($request->amount_repeater_basic);
                $donation->created_by = auth()->user()->id;
                $donation->store_id = getCurrentStore();
                $donation->save();

                return redirect()->back()->with('success', __('Donation successfully updated.'));
            } else {
                return redirect()->back()->with('error', __('The donation is not found.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     * @param int $id
     * @return Renderable
     */
    public function destroy($id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Delete Donation')) {
            $donation = Donation::find($id);
            if ($donation) {
                $donation->delete();
                return redirect()->back()->with('success', __('Donation delete successfully.'));
            } else {
                return redirect()->back()->with('error', __('The donation is not found.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function updateDonationData(Request $request)
    {
        $validator = \Validator::make(
            $request->all(),
            [
                'payment_methods' => 'required'
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }
        $validatedData = $request->validate([
            'payment_methods' => 'required|array',
        ]);
        $theme_id = !empty(APP_THEME()) ? APP_THEME() : '';

        $paymentMethods = $validatedData['payment_methods'];
        $paymentNames = DB::table('settings')
            ->whereIn('id', $paymentMethods)
            ->pluck('name')
            ->map(function ($name) {
                return ucfirst(str_replace(['is_', '_enabled'], '', $name));
            })
            ->toArray();

        $paymentsname = array_combine($paymentMethods, $paymentNames);
        $paymentMethods = json_encode($paymentNames);
        session()->put('app_setting_tab', $request->app_setting_tab);
        $is_donation_enabled = $request->enable_donation ?? null;
        $post['enable_donation'] = $is_donation_enabled;
        $post['payment_methods'] = $paymentMethods;
        $settingQuery = DonationSetting::query();

        foreach ($post as $key => $data) {
            (clone $settingQuery)->updateOrCreate(
                [
                    'name' => $key,
                    'created_by' => auth()->user()->id,
                    'store_id' => getCurrentStore()
                ],
                [
                    'value' => $data,
                    'name' => $key,
                    'store_id' => getCurrentStore(),
                    'created_by' => auth()->user()->id,
                ]
            );
        }
        return redirect()->back()->with('success', 'Donation Setting successfully updated.');
    }
}
