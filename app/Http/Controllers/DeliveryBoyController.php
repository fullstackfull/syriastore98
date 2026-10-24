<?php

namespace App\Http\Controllers;

use App\Models\DeliveryBoy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\DataTables\DeliveryBoyDataTable;

class DeliveryBoyController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Deliveryboy')) {
            $deliveryboys = DeliveryBoy::where('store_id', getCurrentStore())->get();

            return view('deliveryboy.index', compact('deliveryboys'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        if (auth()->user() && auth()->user()->isAbleTo('Create DeliveryBoy')) {
            return view('deliveryboy.create');
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Create DeliveryBoy')) {
            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => 'required|string|max:255',
                    'email' => 'required|email|unique:delivery_boys,email',
                    'password' => 'required|string|min:6',
                    'contact' => 'nullable|string|max:20',
                    'commission_percentage' => 'nullable|numeric|min:0|max:100',
                    'is_commission_enabled' => 'nullable|boolean', // ✅ التحقق من الحقل الجديد
                ]
            );

            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first())->withInput($request->except(['password', 'password_confirmation']));
            }

            $user = \Auth::user();

            $deliveryboy = new DeliveryBoy();
            $deliveryboy->name = $request->name;
            $deliveryboy->email = $request->email;
            $deliveryboy->profile_image = 'uploads/profile/avatar.png';
            $deliveryboy->type = $request->type;
            $deliveryboy->password = Hash::make($request->password);
            $deliveryboy->contact = $request->contact;
            $deliveryboy->created_by = $user->id;
            $deliveryboy->store_id = getCurrentStore();
            
            $deliveryboy->commission_percentage = $request->commission_percentage ?? 0;
            $deliveryboy->is_commission_enabled = $request->has('is_commission_enabled') ? 1 : 0;

            $deliveryboy->save();

            return redirect()->back()->with('success', __('Delivery Boy successfully created.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(DeliveryBoy $deliveryBoy)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Edit Deliveryboy')) {
            $deliveryBoy = DeliveryBoy::find($id);

            /*
             * P1-PLT-08. `DeliveryBoy` is allowlisted in config/tenancy.php, so this bare-id read now
             * returns null for a driver outside the caller's stores instead of that driver's row. The
             * refusal has to be explicit: without it the view dereferences null and the screen 500s, which is a crash
             * where a refusal belongs -- and a 500 is also the shape that makes a regression test pass
             * for the wrong reason. `update()` already had this guard; the other four did not.
             */
            if (! $deliveryBoy) {
                return redirect()->back()->with('error', __('Delivery Boy not found.'));
            }

            return view('deliveryboy.edit', compact('deliveryBoy'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Edit Deliveryboy')) {
            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => 'required|string|max:255',
                    'email' => 'required|email|unique:delivery_boys,email,' . $id,
                    'contact' => 'nullable|string|max:20',
                    'commission_percentage' => 'nullable|numeric|min:0|max:100',
                    'is_commission_enabled' => 'nullable|boolean', // ✅ التحقق من الحقل الجديد
                ]
            );

            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first())->withInput($request->except(['password', 'password_confirmation']));
            }

            $deliveryBoy = DeliveryBoy::find($id);
            
            if (!$deliveryBoy) {
                return redirect()->back()->with('error', __('Delivery Boy not found.'));
            }

            $deliveryBoy->name = $request->name;
            $deliveryBoy->email = $request->email;
            $deliveryBoy->contact = $request->contact;
            $deliveryBoy->commission_percentage = $request->commission_percentage ?? 0;
            
            $deliveryBoy->is_commission_enabled = $request->has('is_commission_enabled') ? 1 : 0;
            
            $deliveryBoy->save();

            return redirect()->back()->with('success', __('Delivery Boy successfully updated.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Delete Deliveryboy')) {
            $deliveryBoy = DeliveryBoy::find($id);

            /*
             * P1-PLT-08. `DeliveryBoy` is allowlisted in config/tenancy.php, so this bare-id read now
             * returns null for a driver outside the caller's stores instead of that driver's row. The
             * refusal has to be explicit: without it the delete() call 500s, which is a crash
             * where a refusal belongs -- and a 500 is also the shape that makes a regression test pass
             * for the wrong reason. `update()` already had this guard; the other four did not.
             */
            if (! $deliveryBoy) {
                return redirect()->back()->with('error', __('Delivery Boy not found.'));
            }

            $deliveryBoy->delete();

            return redirect()->back()->with('success', __('Delivery Boy successfully Deleted!'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function resetPassword($id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Reset password Deliveryboy')) {
            $Id = \Crypt::decrypt($id);
            $deliveryBoy = DeliveryBoy::find($Id);

            /*
             * P1-PLT-08. `DeliveryBoy` is allowlisted in config/tenancy.php, so this bare-id read now
             * returns null for a driver outside the caller's stores instead of that driver's row. The
             * refusal has to be explicit: without it the view dereferences null and the screen 500s, which is a crash
             * where a refusal belongs -- and a 500 is also the shape that makes a regression test pass
             * for the wrong reason. `update()` already had this guard; the other four did not.
             */
            if (! $deliveryBoy) {
                return redirect()->back()->with('error', __('Delivery Boy not found.'));
            }

            return view('deliveryboy.reset', compact('deliveryBoy'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function updatePassword(Request $request, $id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Reset password Deliveryboy')) {
            $validator = \Validator::make(
                $request->all(),
                [
                    'password' => 'required|confirmed|same:password_confirmation',
                ]
            );

            if ($validator->fails()) {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }

            $deliveryBoy = DeliveryBoy::find($id);

            /*
             * P1-PLT-08. `DeliveryBoy` is allowlisted in config/tenancy.php, so this bare-id read now
             * returns null for a driver outside the caller's stores instead of that driver's row. The
             * refusal has to be explicit: without it the forceFill() call 500s, which is a crash
             * where a refusal belongs -- and a 500 is also the shape that makes a regression test pass
             * for the wrong reason. `update()` already had this guard; the other four did not.
             */
            if (! $deliveryBoy) {
                return redirect()->back()->with('error', __('Delivery Boy not found.'));
            }

            $deliveryBoy->forceFill([
                'password' => Hash::make($request->password),
            ])->save();

            /*
             * P1-PLT-08 FOLLOW-UP (tenant-authz review, N1). The API sibling
             * (Api\DeliveryBoyApiController::resetPassword) revokes the driver's Sanctum tokens after the
             * write and this one did not -- so a merchant forcing a reset because a driver's account was
             * compromised left the attacker's bearer working. This project configures no Sanctum expiry
             * (there is no config/sanctum.php), so that token never lapses on its own. Same write, same
             * revocation.
             */
            $deliveryBoy->tokens()->delete();

            return redirect()->back()->with('success', __('Delivery Boy successfully updated!'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function list(Request $request, DeliveryBoyDataTable $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Deliveryboy')) {
            return $dataTable->render('deliveryboy.list');
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
}