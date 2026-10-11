<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryBoy;
use App\Models\Store; 
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class DeliveryBoyApiController extends Controller
{

public function index(Request $request)
{
    $store_id = $request->input('store_id'); 


    if (!$store_id || !is_numeric($store_id)) {
        return response()->json([
            'status' => 'error',
            'message' => __('store_id is required and must be a valid number.')
        ], 400);
    }

    if (!Store::where('id', $store_id)->exists()) {
        return response()->json([
            'status' => 'error',
            'message' => __('Store not found.')
        ], 404);
    }

    $deliveryboys = DeliveryBoy::where('store_id', $store_id)->get();

    return response()->json([
        'status' => 'success',
        'message' => __('Delivery boys retrieved successfully.'),
        'data' => $deliveryboys
    ], 200);
}



    /**
     * إنشاء سائق توصيل جديد لمتجر محدد
     */
    public function store(Request $request)
    {
        
        
    $store_id = $request->input('store_id'); 


    if (!$store_id || !is_numeric($store_id)) {
        return response()->json([
            'status' => 'error',
            'message' => __('store_id is required and must be a valid number.')
        ], 400);
    }

    if (!Store::where('id', $store_id)->exists()) {
        return response()->json([
            'status' => 'error',
            'message' => __('Store not found.')
        ], 404);
    }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:delivery_boys,email',
            'password' => 'required|string|min:6',
            'contact' => 'nullable|string|max:20',
            'commission_percentage' => 'nullable|numeric|min:0|max:100',
            'is_commission_enabled' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        $deliveryBoy = DeliveryBoy::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'contact' => $request->contact,
            'profile_image' => 'uploads/profile/avatar.png', 
            'commission_percentage' => $request->commission_percentage ?? 0,
            'is_commission_enabled' => $request->boolean('is_commission_enabled'),
            'store_id' => $store_id,
            'created_by' => auth()->id() ?? 0,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => __('Delivery Boy successfully created.'),
            'data' => $deliveryBoy
        ], 201);
    }


    /**
     * تحديث بيانات سائق توصيل في متجر محدد
     */
public function update(Request $request, $id)
{
    $store_id = $request->query('store_id');
    if (!$store_id) {
        return response()->json([
            'status' => 'error',
            'message' => __('store_id is required in the URL parameters.')
        ], 400);
    }


    $deliveryBoy = \App\Models\DeliveryBoy::where('id', $id)
                                          ->where('store_id', $store_id)
                                          ->first();

    if (!$deliveryBoy) {
        return response()->json([
            'status' => 'error',
            'message' => __('Delivery Boy not found ')
        ], 404);
    }

    $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
        'name' => 'nullable|string|max:255',
        'email' => 'nullable|email|unique:delivery_boys,email,' . $id,
        'contact' => 'nullable|string|max:20',
        'commission_percentage' => 'nullable|numeric|min:0|max:100',
        'is_commission_enabled' => 'nullable|boolean',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => 'error',
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors()
        ], 422);
    }

    $deliveryBoy->name = $request->name ?? $deliveryBoy->name;
    $deliveryBoy->email = $request->email ?? $deliveryBoy->email;
    $deliveryBoy->contact = $request->contact ?? $deliveryBoy->contact;
    $deliveryBoy->commission_percentage = $request->commission_percentage ?? $deliveryBoy->commission_percentage;
    
    $deliveryBoy->is_commission_enabled = $request->has('is_commission_enabled') 
        ? ($request->input('is_commission_enabled') ? 1 : 0) 
        : $deliveryBoy->is_commission_enabled;

    $deliveryBoy->save();
    return response()->json([
        'status' => 'success',
        'message' => __('Delivery Boy successfully updated.'),
        'data' => $deliveryBoy
        
    ], 200);
}   

    public function destroy(Request $request, $id)
    {
    $store_id = $request->query('store_id');
    if (!$store_id) {
        return response()->json([
            'status' => 'error',
            'message' => __('store_id is required in the URL parameters.')
        ], 400);
    }


    $deliveryBoy = \App\Models\DeliveryBoy::where('id', $id)
                                          ->where('store_id', $store_id)
                                          ->first();

    if (!$deliveryBoy) {
        return response()->json([
            'status' => 'error',
            'message' => __('Delivery Boy not found ')
        ], 404);
    }

        $deliveryBoy->delete();

        return response()->json([
            'status' => 'success',
            'message' => __('Delivery Boy successfully Deleted!')
        ], 200);
    }

    /**
     * إعادة تعيين كلمة المرور لسائق في متجر محدد
     */
    public function resetPassword(Request $request,  $id)
    {
    $store_id = $request->query('store_id');
    if (!$store_id) {
        return response()->json([
            'status' => 'error',
            'message' => __('store_id is required in the URL parameters.')
        ], 400);
    }


    $deliveryBoy = \App\Models\DeliveryBoy::where('id', $id)
                                          ->where('store_id', $store_id)
                                          ->first();

    if (!$deliveryBoy) {
        return response()->json([
            'status' => 'error',
            'message' => __('Delivery Boy not found ')
        ], 404);
    }

        $validator = Validator::make($request->all(), [
            'password' => 'required|string|min:6|confirmed', 
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        $deliveryBoy->update([
            'password' => Hash::make($request->password),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => __('Delivery Boy password successfully updated!')
        ], 200);
    }
}