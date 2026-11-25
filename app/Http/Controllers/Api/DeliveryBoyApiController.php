<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryBoy;
use App\Models\Store;
use App\Services\MerchantPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class DeliveryBoyApiController extends Controller
{

    /**
     * EVERY CREDENTIAL WRITE ON THIS CONTROLLER IS GATED HERE: the caller must be an
     * administrative user who owns the store named in the query string.
     *
     * WHY A SHARED HELPER. Three methods on this controller write a delivery driver's credentials
     * -- store() creates one, update() rewrites its login e-mail, resetPassword() sets its
     * password -- and all three took `store_id` from the QUERY STRING. A predicate built from a
     * value the caller supplies is not a tenant check; it only compares the caller's own two
     * values against each other. None of the three checked who was asking.
     *
     * `custom.auth` admits a Customer, a User or a DeliveryBoy token, logging each into whichever
     * guard matches its class (App\Http\Middleware\CustomAuth:78-86). So a SHOPPER's token, or
     * another store's DRIVER's token, satisfied the middleware on all three. Measured before the
     * fix: a shopper's token created a working driver credential in a store they had nothing to do
     * with, and reset an existing driver's password in another store.
     *
     * THE PLATFORM OPERATOR IS ADMITTED EXPLICITLY. isAbleTo() has no super-admin bypass in this
     * codebase and creatorId() returns a 'super admin' their own id, so an ownership test alone
     * would refuse the operator -- the divergence from the sibling ownership helpers that an
     * independent review flagged on the first version of this check. The operator-first ordering
     * matches CustomerController and OrderController.
     *
     * COMPATIBILITY. The shipped admin app always sends its own store_id with the merchant's
     * Bearer (repo B lib/config/repository/delivery_boy_repository.dart, base URL
     * https://storesyria.com/api/admin), so every request it makes still succeeds. The refusal
     * shape matches each handler's existing 401/404 envelopes.
     *
     * @return \Illuminate\Http\JsonResponse|null  a refusal, or null when the caller may proceed
     */
    private function refuseUnlessOperatorOwns($storeId)
    {
        $operator = auth()->user();

        if (! $operator instanceof \App\Models\User) {
            return response()->json([
                'status' => 'error',
                'message' => __('Unauthenticated.')
            ], 401);
        }

        if (strtolower(trim((string) $operator->type)) === 'super admin') {
            return null;
        }

        // P1-SEC-66: the tenant root must be a MERCHANT -- `creatorId()` alone is the operator's id for a user rooted at the platform (a demoted
        // merchant, or staff the operator created), which made the operator's store "theirs". A null root owns no store: the same 404 as any other.
        $root = \App\Policies\UserPolicy::merchantTenantRoot($operator);

        $ownsStore = $root !== null
            && \App\Models\Store::where('id', $storeId)
                ->where('created_by', $root)
                ->exists();

        if (! $ownsStore) {
            return response()->json([
                'status' => 'error',
                'message' => __('Store not found.')
            ], 404);
        }

        return null;
    }

    /**
     * GAP-142 / P4-F109 — MAY THIS PRINCIPAL, INSIDE A TENANT IT BELONGS TO, DO THIS?
     * =============================================================================================
     *
     * `refuseUnlessOperatorOwns()` above answers a DIFFERENT question: WHOSE store is it. It is a
     * tenant check and nothing more, and it was the only gate on this controller -- so every staff
     * member of the merchant's own tenant, holding a role with NO permissions at all, could read the
     * whole driver roster with its login addresses, create a driver credential, REPOINT A DRIVER'S
     * LOGIN E-MAIL, destroy a driver, and SET A DRIVER'S PASSWORD.
     *
     * `DeliveryBoy` is an authentication model with its own guard and its own Sanctum tokens, so an
     * e-mail or password write on one is an ACCOUNT-TAKEOVER PRIMITIVE rather than a privacy row:
     * whoever holds the address holds recovery, and whoever sets the password holds the account.
     * That is why this ranked first of GAP-142's remaining rows.
     *
     * THE NAMES ARE THE WEB SCREENS' OWN, ONE PER ROUTE, AND NOTHING IS INVENTED HERE.
     * `Utility::addNewData()` seeds exactly five delivery-driver names, and it seeds the SAME five
     * both into the row-creating array and into the grant list of the shared global `admin` role
     * every merchant holds. There is NO plural delivery family at all -- unlike the order family,
     * where the platform block spells `Manage Orders` and the merchant block the singular, a
     * divergence that cost P4-F106 a correction. `DeliveryBoyController` -- the merchant WEB screens
     * for these same five operations -- maps them method for method, and this copies that mapping
     * rather than reasoning by analogy:
     *
     *     Manage Deliveryboy          web index / datatable   ->  index
     *     Create DeliveryBoy          web create / store      ->  store
     *     Edit Deliveryboy            web edit / update       ->  update
     *     Delete Deliveryboy          web destroy             ->  destroy
     *     Reset password Deliveryboy  web reset password      ->  resetPassword
     *
     * The casing is load-bearing: `Create DeliveryBoy` carries a capital B and the other four do
     * not, that is the seeded spelling, and `MerchantPermissions::held()` compares with
     * `in_array(..., true)`. One name per route, exactly as the web, so a staff member's grants mean
     * the same thing in both products; widening a credential write to also admit the roster-reading
     * name would be a product decision, not a port.
     *
     * THROUGH `MerchantPermissions`, NEVER `isAbleTo()`. Laratrust runs with
     * `permissions_as_gates => false` and this codebase has NO super-admin bypass -- operator status
     * is a string comparison on `users.type` -- so `isAbleTo('Manage Deliveryboy')` REFUSES the
     * platform operator, who holds no roles. The five names are not even in the super-admin grant
     * list, so a fully seeded platform install grants the operator none of them. `allowsAny()` tests
     * operator status first and answers `WILDCARD`. It also scopes a staff member's names to THIS
     * store, which `allPermissions()` does not -- and `$storeId` here is the store
     * `refuseUnlessOperatorOwns()` has already admitted the caller to, not `current_store`.
     *
     * THE OWNER LIMB IS NOT REDUNDANT, and this family has already broken on it. A merchant does not
     * implicitly hold these names, and the register records an order gate in the same family
     * refusing the store's own owner; `DeliveryAndOrderApiAuthorizationTest` still carries the
     * message written for it -- "The predicate is too tight -- that is a defect, not a fix." It is
     * not an escalation either: the owner is the principal who creates the roles and grants the
     * permissions in their own tenant, so refusing them is a live breakage with no security gain.
     *
     * CALLED BELOW `refuseUnlessOperatorOwns()` IN EVERY HANDLER, deliberately. Tenancy answers
     * first, so a foreign tenant still gets the same 404 it got before -- three shipped suites
     * assert that exact status (`CredentialBoundaryTest`, `DeliveryBoyScopeTest`,
     * `TenantRootSinksTest`) and a permission check placed above would have turned them into 403s.
     * The permission question is only ever asked about a store the caller is already admitted to.
     *
     * 403, NEVER 401, AND NEVER `ApiResponser::error()`, which answers HTTP 200 with `{status: 0}`
     * -- a refusal no client distinguishes from success. The shipped Flutter admin app force-clears
     * its session on any 401, so a 401 here would log merchants out of the whole product.
     *
     * @return \Illuminate\Http\JsonResponse|null  a refusal, or null when the caller may proceed
     */
    private function refuseWithoutDeliveryPermission(string $permission, $storeId)
    {
        if ($this->callerIsStoreOwner($storeId)) {
            return null;
        }

        if (MerchantPermissions::allowsAny(auth()->user(), [$permission], $storeId)) {
            return null;
        }

        return response()->json([
            'status' => 'error',
            'message' => __('You do not have permission to perform this action.'),
        ], 403);
    }

    /**
     * The tenant ROOT, not the tenant.
     *
     * `$store->created_by === $user->id` AND NOT `creatorId()`, which returns the OWNER's id for a
     * staff member too -- a `creatorId()` comparison here would admit every employee and dissolve
     * the permission gate above entirely. The owner's own id is the only spelling that separates the
     * two. Same shape as `Api\DashboardController::callerIsStoreOwner()`.
     *
     * The platform operator is not handled here: `MerchantPermissions::allowsAny()` already answers
     * the wildcard for them, which is where that decision belongs.
     *
     * `Store` is NOT in `config/tenancy.php`'s `scoped_models`, so this read adds no predicate and
     * answers about the real row for every principal.
     */
    private function callerIsStoreOwner($storeId): bool
    {
        $user = auth()->user();

        if (! $user instanceof \App\Models\User || empty($storeId)) {
            return false;
        }

        $store = Store::where('id', $storeId)->first();

        return $store !== null && (int) $store->created_by === (int) $user->id;
    }


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

    /*
     * P1-PLT-08 FOLLOW-UP (tenant-authz review, B1). THIS WAS THE EXISTENCE CHECK AND NOTHING ELSE.
     * `Store::where('id', $store_id)->exists()` proves the store is real, not that the caller owns it --
     * and three sibling methods on this controller already had refuseUnlessOperatorOwns() while this one
     * and destroy() did not.
     *
     * AND THE TENANT SCOPE DOES NOT COVER IT, which is why allowlisting DeliveryBoy was not the fix.
     * `custom.auth` logs a token's owner into the guard matching its TOKENABLE CLASS
     * (App\Http\Middleware\CustomAuth:78-86), so a Customer's token lands on `customers` and a
     * DeliveryBoy's on `deliveryboy`, leaving the DEFAULT guard empty. `StoreScope::resolveTenant()`
     * reads the default guard, so for those principals it returns MODE_UNRESOLVED and
     * `null_tenant => 'open'` adds NO predicate. The scope narrows this read for a merchant's `User`
     * token and for nobody else -- the mirror of the trap CLAUDE.md records, and the reason a
     * customer-token probe is the one that finds it.
     *
     * What went out: `DeliveryBoy::...->get()` serialises the whole model and the model declares no
     * `$hidden`, so every driver's login e-mail and bcrypt `password` hash were in the JSON.
     */
    if ($refusal = $this->refuseUnlessOperatorOwns($store_id)) {
        return $refusal;
    }

    // AND THEN WHO INSIDE THAT TENANT MAY READ THE ROSTER. The line above settles whose store it
    // is; `Manage Deliveryboy` is the name the merchant's own web roster screen gates on, and
    // without it any staff member read every driver's login address.
    if ($refusal = $this->refuseWithoutDeliveryPermission('Manage Deliveryboy', $store_id)) {
        return $refusal;
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

    // CREATING A DRIVER IS CREATING A CREDENTIAL. The existence check above only established that
    // the store the caller named is real -- it said nothing about whether the caller owns it, and
    // nothing about whether the caller is an administrator at all.
    if ($refusal = $this->refuseUnlessOperatorOwns($store_id)) {
        return $refusal;
    }

    // AND THEN WHETHER THIS PRINCIPAL MAY MINT A CREDENTIAL IN IT. Checked BEFORE the validator so
    // an unpermitted caller gets the refusal rather than a field-level 422 that reveals the rules.
    if ($refusal = $this->refuseWithoutDeliveryPermission('Create DeliveryBoy', $store_id)) {
        return $refusal;
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

    // THIS HANDLER REWRITES A DRIVER'S LOGIN E-MAIL, which is a credential: whoever holds the
    // address holds account recovery. Gated for the same reason as store() and resetPassword() --
    // `store_id` arrives in the query string, so the predicate below compared the caller's own two
    // values and authorised nothing.
    if ($refusal = $this->refuseUnlessOperatorOwns($store_id)) {
        return $refusal;
    }

    // AND THEN WHETHER THIS PRINCIPAL MAY REWRITE A DRIVER'S LOGIN ADDRESS. `Edit Deliveryboy` is
    // the name the merchant's own web edit screen gates on. Without it, a staff member holding a
    // role with no permissions could repoint any of the tenant's driver accounts at an address they
    // control, which is account recovery and therefore the account.
    if ($refusal = $this->refuseWithoutDeliveryPermission('Edit Deliveryboy', $store_id)) {
        return $refusal;
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


    /*
     * P1-PLT-08 FOLLOW-UP (tenant-authz review, B1). Same gap as index(), and worse because the write is
     * IRREVERSIBLE: the predicate was `store_id = <the caller's own query parameter>`, which compares the
     * caller's two values and authorises nothing. See index() for why the tenant scope does not cover it
     * -- and note additionally that `Model::delete()` goes through `newModelQuery()`, which carries NO
     * global scopes, so even a narrowed read would not have constrained the DELETE.
     */
    if ($refusal = $this->refuseUnlessOperatorOwns($store_id)) {
        return $refusal;
    }

    // AND THEN WHETHER THIS PRINCIPAL MAY DESTROY ONE. `Delete Deliveryboy` is the name the
    // merchant's own web screen gates on. There is no soft delete on DeliveryBoy and no audit row
    // on this path, so the driver and their earnings history are simply gone.
    if ($refusal = $this->refuseWithoutDeliveryPermission('Delete Deliveryboy', $store_id)) {
        return $refusal;
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

    if ($refusal = $this->refuseUnlessOperatorOwns($store_id)) {
        return $refusal;
    }

    // AND THEN WHETHER THIS PRINCIPAL MAY SET A DRIVER'S PASSWORD. `Reset password Deliveryboy` is
    // the name the merchant's own web reset screen gates on, and it is a separate grant there for
    // the obvious reason: setting the password IS taking the account. Checked before the validator
    // so an unpermitted caller never learns the password rules.
    if ($refusal = $this->refuseWithoutDeliveryPermission('Reset password Deliveryboy', $store_id)) {
        return $refusal;
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

        // AN ADMIN-FORCED RESET MUST END THE DRIVER'S SESSIONS. Without this the driver -- or
        // whoever had taken their account, which is usually why an operator forces a reset --
        // keeps a working token, and this project configures no Sanctum expiry, so that token
        // never lapses. The same omission was found and closed on the customer recovery path
        // (Api\AuthController::forgot_password_save).
        $deliveryBoy->tokens()->delete();

        return response()->json([
            'status' => 'success',
            'message' => __('Delivery Boy password successfully updated!')
        ], 200);
    }
}