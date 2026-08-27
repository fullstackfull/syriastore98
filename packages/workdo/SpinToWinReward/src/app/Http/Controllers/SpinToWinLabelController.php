<?php

namespace Workdo\SpinToWinReward\app\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\SpinToWinReward\app\Models\SpinToWinLabels;
use Yajra\DataTables\Facades\DataTables;

class SpinToWinLabelController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index(Request $request)
    {
        if ($request->ajax()) {
            // Fetch product pricing rules for the current store
            $spinToWinLabels = SpinToWinLabels::where('store_id', getCurrentStore())
                ->orderBy('id', 'desc');
            return DataTables::of($spinToWinLabels)
                ->addColumn('action', function (SpinToWinLabels $label) {
                    return view('spin-to-win-reward::admin.label.action', compact('label'));
                })
                ->rawColumns(['name', 'action'])
                ->make(true);
        }
        return view('spin-to-win-reward::pages.tab_form');
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        return view('spin-to-win-reward::admin.label.create');
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        session()->put('app_setting_tab', 'pills-spin_to_win-tab');
        if (auth()->user() && auth()->user()->isAbleTo('Create Spin to Win Label')) {
            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => 'required'
                ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
            
            $spinToWinLabel                      = new SpinToWinLabels();
            $spinToWinLabel->name                = $request->name;
            
            $spinToWinLabel->store_id            = getCurrentStore();
            $spinToWinLabel->save();
            return redirect()->back()->with('success', __('Label successfully created.'));
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
        return view('spin-to-win-reward::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        $spinToWinLabel = SpinToWinLabels::where('id',$id)->first();
        return view('spin-to-win-reward::admin.label.edit', compact('spinToWinLabel'));
    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param int $id
     * @return Renderable
     */
    public function update(Request $request, $id)
    {
        session()->put('app_setting_tab', 'pills-spin_to_win-tab');
        if (auth()->user() && auth()->user()->isAbleTo('Edit Spin to Win Label')) {

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

            $spinToWinLabel = SpinToWinLabels::where('id',$id)->first();

            if ($spinToWinLabel) {
                $spinToWinLabel->name                = $request->name;  
                $spinToWinLabel->store_id            = getCurrentStore();
                $spinToWinLabel->save();
            }

            return redirect()->back()->with('success', __('Label successfully updated.'));
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
        session()->put('app_setting_tab', 'pills-spin_to_win-tab');
        if (auth()->user() && auth()->user()->isAbleTo('Delete Spin to Win Label')) {
            
            SpinToWinLabels::where('id',$id)->delete();
            return redirect()->back()->with('success', __('Label delete successfully.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
}
