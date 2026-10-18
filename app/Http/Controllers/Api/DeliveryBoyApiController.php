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
    /**
     * AN OPERATOR WHO OWNS THE STORE SETS ONE OF THEIR OWN DRIVERS' PASSWORDS. Nothing else.
     *
     * THE DEFECT THIS REPLACES was found by the password-recovery verification package, which had
     * this route in hand for its logging and read the handler while it was there. The body began:
     *
     *     $store_id = $request->query('store_id');          // attacker-supplied
     *     ...
     *     $deliveryBoy = DeliveryBoy::where('id', $id)->where('store_id', $store_id)->first();
     *     ...
     *     $deliveryBoy->update(['password' => Hash::make($request->password)]);
     *
     * `store_id` arrives in the QUERY STRING, so the `where('store_id', ...)` predicate was not a
     * tenant check at all -- it only checked the attacker's two supplied values against each
     * other. And there was NO authorisation beyond `custom.auth`: any principal holding any valid
     * token -- a CUSTOMER, another store's DRIVER, any merchant -- could set ANY delivery driver's
     * password in ANY store, cross-tenant, by naming the matching store_id.
     *
     * WHAT IT DOES NOW. The caller must be a `User` (the `web` guard; a customer's or a driver's
     * token no longer reaches the write at all) and the named store must be one they own, or that
     * their own account was created under -- `creatorId()`, the same derivation the rest of the
     * admin surface uses for a staff member acting inside their merchant's tenant.
     *
     * COMPATIBILITY, and it is why this could be fixed here rather than deferred to the Flutter
     * authorization pass. The shipped admin app sends `store_id` = `Prefs.getStoreID()`, its own
     * selected store (repo B `lib/config/repository/delivery_boy_repository.dart:47`), with the
     * merchant's Bearer. Every request it makes still succeeds: same path, same method, same query
     * parameters, same body, same success and error shapes. The only requests newly refused are
     * ones where the caller does not own the store they named, which that client never sends.
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

    // The principal must be an administrative user. `custom.auth` logs a token's owner into
    // whichever guard matches its class, so a customer or a driver token passes the middleware
    // and must be refused here.
    $operator = auth()->user();

    if (! $operator instanceof \App\Models\User) {
        return response()->json([
            'status' => 'error',
            'message' => __('Unauthenticated.')
        ], 401);
    }

    // ...and the named store must be theirs. Without this the query-string store_id authorises
    // nothing it is checked against.
    $ownsStore = \App\Models\Store::where('id', $store_id)
        ->where('created_by', $operator->creatorId())
        ->exists();

    if (! $ownsStore) {
        return response()->json([
            'status' => 'error',
            'message' => __('Delivery Boy not found ')
        ], 404);
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