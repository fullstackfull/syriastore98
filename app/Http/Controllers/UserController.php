<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Plan;
use App\Models\Store;
use App\Models\Utility;
use App\Models\Role;
use App\Models\Permission;
use App\Models\Setting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use App\DataTables\UserDataTable;
use App\Models\AuditLog;
use App\Http\Requests\UpdateUserRequest;
use App\Policies\RolePolicy;
use Workdo\LynomiaChat\app\Services\MerchantContactVerification;



class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if(auth()->user() && auth()->user()->isAbleTo('Manage User'))
        {
            $users = User::where('created_by','=',\Auth::user()->creatorId())->where('current_store', getCurrentStore())->paginate(11);

            return view('users.index',compact('users'));

        }
        else{
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        if (auth()->user() && auth()->user()->isAbleTo('Create User'))
        {
            $user  = \Auth::user();
            $roles = Role::where('created_by', '=', $user->creatorId())->where('store_id', getCurrentStore())->get()->pluck('name', 'id');
            return view('users.create',compact('roles'));
        }
        else
        {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Create User'))
        {
            $exitUser = User::where('email', $request->email)->first();
            if ($exitUser && $exitUser->created_by != auth()->user()->id) {
                return redirect()->back()->with('error', __('The email is already being used in another account. please choose another email'));
            }
            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => 'required',
                    'email' => [
                        'required',
                        Rule::unique('users')->where(function ($query) {
                        return $query->where('created_by', \Auth::user()->id);
                        })
                    ],
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }

            $user = \Auth::user();
            $creator = User::find($user->creatorId());
            $total_users = User::where('type', '!=', 'super admin')->where('type', '!=', 'admin')->where('created_by', '=', $user->id)->count();
            $plan = Plan::find($user->plan_id);
            // $plan = '5';

            if ($total_users < $plan->max_users || $plan->max_users == -1)
            {

                $objUser    = \Auth::user();

                // S13 -- PRIVILEGE ESCALATION. This was `Role::find($request->role)`: unscoped,
                // on request input, and its result was written to BOTH `$user->type` (below) and
                // `$user->addRole()`. The seeded global `super admin` role
                // (database/seeders/PermissionTableSeeder.php) carries store_id NULL, so it was
                // findable by id from any tenant, and `Create User` is granted to the merchant
                // `admin` role (app/Models/Utility.php) -- so EVERY self-registered merchant
                // could mint a platform operator.
                //
                // WHY THAT WAS THE WORST DEFECT IN THIS PROJECT, not a local one. Nothing here
                // trusts Laratrust for operator identity: permissions_as_gates is disabled and
                // Laratrust has no super-admin bypass, so authorization code compares
                // `users.type === 'super admin'` directly -- App\Policies\OrderPolicy,
                // App\Http\Middleware\OrderDocumentAccessGuard,
                // Api\DashboardController::resolveOwnedStore() and the rest. Writing that string
                // defeated every store_id predicate in the codebase at once.
                //
                // THE FIX IS NOT NEW CODE. The sibling update():240-266 was already hardened and
                // carries exactly these two controls; store() was missed, and nothing noticed
                // because the pair was never asserted to agree. Both halves are mirrored here
                // verbatim -- a role lookup scoped to the actor's own tenant, then a
                // RolePolicy::RESERVED_NAMES refusal as defence in depth -- and
                // tests/Feature/Security/PrivilegeEscalationTest now pins the two methods
                // together so they cannot drift apart again.
                //
                // NO OPERATOR SPECIAL CASE, deliberately, because update() has none either:
                // consistency between the pair is the whole point of the fix. A platform operator
                // keeps every capability they had except minting another operator through the
                // merchant user form, which was never this form's job.
                $role_r = Role::where('id', $request->role)
                    ->where('created_by', '=', $objUser->creatorId())
                    ->where('store_id', getCurrentStore())
                    ->first();

                if (!$role_r) {
                    return redirect()->back()->with('error', __('The selected role is not available in this store.'));
                }

                if (in_array(strtolower(trim((string) $role_r->name)), RolePolicy::RESERVED_NAMES, true)) {
                    return redirect()->back()->with('error', __('That role cannot be assigned.'));
                }

                $user =  new User();
                $user->name =  $request['name'];
                $user->email =  $request['email'];
                $user->type = $role_r->name;
                $user->password = Hash::make($request['password']);
                $user->is_assign_store = $objUser->current_store;
                $user->language = $objUser->default_language ?? 'en';
                $user->default_language = $objUser->default_language ?? 'en';
                $user->created_by = \Auth::user()->id;
                $user->email_verified_at = date("Y-m-d H:i:s");
                $user->current_store = $objUser->current_store;
                $user->plan_id = $objUser->plan;
                $user->is_active = 1;

                if (module_is_active('GoogleAuthentication')) {
                    $user->google2fa_enable = $objUser->google2fa_enable ;
                    $user->google2fa_secret = $objUser->google2fa_secret;
                }

                $user->save();

                $user->addRole($role_r);


AuditLog::record('user_created', $user, [], [
    'name' => $user->name,
    'email' => $user->email,
    'type' => $user->type,
]);


                // webhook
                if(!empty($user))
                {
                    $module = 'New User';
                    $store = getStoreById(getCurrentStore());
                    $webhook =  Utility::webhook($module, $store->id);

                    if ($webhook) {
                        $storeDetail=Store::find($user->current_store);
                        $user->current_store=$storeDetail->name;
                        $user->is_assign_store = $storeDetail->name;
                        $parameter = json_encode($user);

                        // 1 parameter is  URL , 2 parameter is data , 3 parameter is method
                        $status = Utility::WebhookCall($webhook['url'], $parameter, $webhook['method']);
                        if ($status != true) {
                            $msgs = 'Webhook call failed.';
                        }
                    }
                    return redirect()->back()->with('success', __('User successfully created.' . (isset($msgs) ? '<br><span class="text-danger">' . $msgs . '</span>' : '')));
                }
                return redirect()->back()->with('success', 'User successfully created.');
            } else {
                return redirect()->back()->with('error', __('Your User limit is over, Please upgrade plan'));
            }
        }
        else{
            return response()->json(['error' => __('Permission denied.')], 401);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Edit User'))
        {
            $user  = User::find($id);
            $roles = Role::where('created_by', '=', auth()->user()->creatorId())->where('store_id', getCurrentStore())->get()->pluck('name', 'id');
            return view('users.edit', compact('user', 'roles'));
        }
        else
        {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateUserRequest $request, $id)
    {
        $user = User::findOrFail($id);

        // SEC-F4. This method was gated on the 'Edit User' permission alone --
        // which every merchant holds for their own tenant -- and then operated
        // on User::findOrFail($id) with no ownership test, so any merchant could
        // edit any user on the platform, including user id 1. The permission
        // check now lives inside UserPolicy::update alongside the tenancy test.
        $this->authorize('update', $user);

        $exitUser = User::where('email', $request->email)->where('id', '!=', $id)->first();
        if ($exitUser && $exitUser->created_by != auth()->user()->id) {
            return redirect()->back()->with('error', __('The email is already being used in another account. please choose another email'));
        }

        // NEW-audit-oldvalues. The snapshot is taken BEFORE the write. It was
        // previously passed to AuditLog::record while being defined six lines
        // AFTER that call, so $oldValues was null at the call site and the audit
        // trail recorded no previous values at all.
        $oldValues = [
            'name' => $user->name,
            'email' => $user->email,
            'type' => $user->type,
        ];

        // The role lookup was Role::find($request->role) -- unscoped, so a
        // merchant could attach any role on the platform and, via the type
        // derivation below, name themselves anything. Scoped here exactly as the
        // sibling edit() above already scopes the role list it renders.
        $role = null;
        if ($request->filled('role')) {
            $role = Role::where('id', $request->input('role'))
                ->where('created_by', '=', auth()->user()->creatorId())
                ->where('store_id', getCurrentStore())
                ->first();

            if (!$role) {
                return redirect()->back()->with('error', __('The selected role is not available in this store.'));
            }
        }

        // Only the allow-listed attributes from the edit form. `type`,
        // `password`, `created_by`, `plan_id`, `storage_limit` and
        // `email_verified_at` are all in User::$fillable and were all writable
        // by the caller through $request->all().
        $input = $request->userAttributes();

        if ($role) {
            // Deriving type server-side from a SCOPED role is correct and is
            // preserved. Reserved names are refused as defence in depth.
            if (in_array(strtolower(trim((string) $role->name)), RolePolicy::RESERVED_NAMES, true)) {
                return redirect()->back()->with('error', __('That role cannot be assigned.'));
            }
            $input['type'] = $role->name;
        }

        $user->fill($input)->save();

        AuditLog::record('user_updated', $user, $oldValues, [
            'name' => $user->name,
            'email' => $user->email,
            'type' => $user->type,
        ]);

        if ($role && !$user->hasRole($role->name)) {
            $user->addRole($role);
        }

        return redirect()->back()->with('success', 'User successfully updated.');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(User $user)
    {

        if (auth()->user() && auth()->user()->isAbleTo('Delete User'))
        {
            Setting::where('created_by', $user->id)->delete();
            $user->delete();


AuditLog::record('user_deleted', $user, [
    'name' => $user->name,
    'email' => $user->email,
    'type' => $user->type,
], []);


            return redirect()->back()->with('success', 'User successfully deleted.');
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function reset($id)
    {
        // SEC-F1. The 'Reset Password' permission was the ONLY gate, so any
        // holder of it could reset any account on the platform. The Crypt
        // wrapper on the route parameter is not an authorization control --
        // APP_KEY is in git history, so an encrypted parameter is forgeable.
        // The authorize() call below is the control.
        $Id = \Crypt::decrypt($id);
        $user = User::findOrFail($Id);

        $this->authorize('resetPassword', $user);

        $employee = $user;

        return view('users.reset', compact('user', 'employee'));
    }

    public function updatePassword(Request $request, $id)
    {
        // SEC-F1. Resolve and authorize the TARGET before any write. Previously
        // the permission alone admitted a reset of any account, and the write at
        // forceFill() happened with no ownership test whatsoever.
        $user = User::findOrFail($id);

        $this->authorize('resetPassword', $user);

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

        $user->forceFill([
            'password' => Hash::make($request->password),
        ])->save();

        AuditLog::record('user_password_reset', $user);

        return redirect()->back()->with('success', __('User Password successfully updated.'));
    }

    public function profile()
    {
        $userDetail = \Auth::user();
        return view('users.profile', compact('userDetail'));
    }

    public function editprofile(Request $request)
    {
        $userDetail = \Auth::guard()->user();
        $dir        = 'uploads/profile';
        $rule['name'] = 'required';
        $rule['email'] = 'required';

        $validator = \Validator::make($request->all(), $rule);
        if($validator->fails())
        {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }

        if ($request->hasFile('profile_image')) {
            $fileName = rand(10,100).'_'.time() . "_" . basename($request->profile_image->getClientOriginalName());
            $path = Utility::upload_file($request,'profile_image',$fileName,$dir,[]);
            if ($path['flag'] == '0') {
                return redirect()->back()->with('error', $path['msg']);
            }
        }

        $user_id = \Auth::guard()->user()->id;
        $user               = User::Where('id', $user_id)->first();
        if (!empty($request->profile_image) && isset($path['url'])) {
            $user['profile_image'] = str_replace('/storage', '', $path['url']);
        }
        $user->name   = $request->name;
        $user->email        = $request->email;

        // A NUMBER THAT CHANGED IS A NUMBER NOBODY HAS PROVED (B4). The field still accepts
        // whatever is typed, exactly as it always has; what must not survive the edit is the
        // claim attached to the old value. Without this a merchant confirms a number they
        // hold and then quietly types another, and `mobile_verified_at` says somebody proved
        // a number nobody has ever answered on. Called BEFORE the write, because it compares
        // the new value against the stored one.
        app(MerchantContactVerification::class)->forgetIfChanged($user, $request->mobile);

        $user->mobile        = $request->mobile;

        $user->save();

        return redirect()->back()->with('success', __('Personal info successfully updated.'));
    }

    public function password_update(Request $request, $slug = '')
    {
        $store = getStore($slug);
       
        # Validation
        $rule['old_password'] = 'required';
        $rule['new_password'] = 'required|confirmed';

        $validator = \Validator::make($request->all(), $rule);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }

        // `==`, not `=`: the original assignments overwrote $request->type and
        // always evaluated truthy, so this guard was dead code.
        if (!empty($request->type) && ($request->type == 'admin' || $request->type == 'superadmin')) {
            #Match The Old Password
            if (!Hash::check($request->old_password, Auth::guard()->user()->password)) {
                return redirect()->back()->with('error', __("Old Password Does not match!"));
            }

            #Update the new Password
            User::whereId(Auth::guard()->user()->id)->update([
                'password' => Hash::make($request->new_password)
            ]);


AuditLog::record(
    'user_password_changed',
    Auth::guard()->user()
);

        } else {
            #Match The Old Password
            if (!Hash::check($request->old_password, auth()->user()->password)) {
                return redirect()->back()->with('error', __("Old Password Does not match!"));
            }

            #Update the new Password
            User::whereId(auth()->user()->id)->update([
                'password' => Hash::make($request->new_password)
            ]);
        }
AuditLog::record(
    'user_password_changed',
    auth()->user()
);



        return redirect()->back()->with('success', __('Password update succefully.'));
    }

    public function userInfo(Request $request, $id)
	{
        if(empty(decrypt($id))){
            $id = $request->id;
        }
		if(!empty($id)){
            $user = User::find(decrypt($id));
            $status = $user->is_active;
		    $data = $this->storeUserCounter(decrypt($id));
		    if($data['is_success']){
		        $users_data = $data['response']['users_data'];
		        $store_data = $data['response']['store_data'];
		        return view('users.user_info', compact('id','users_data','store_data','status'));
		    }
		}
		else
		{
		    return redirect()->back()->with('error', __('Permission denied.'));
		}
    }

    public function storeUserCounter($id)
    {
		$response = [];
		if(!empty($id))
		{
		    $stors= Store::where('created_by', $id)
		    ->selectRaw('COUNT(*) as total_store, SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as disable_store, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_store')
		    ->first();
		    $stores = Store::where('created_by',$id)->get();
		    $users_data = [];
		    foreach($stores as $store)
		    {
		        $users = User::where('type','!=','admin')->where('created_by',$id)->where('current_store',$store->id)->selectRaw('COUNT(*) as total_users, SUM(CASE WHEN is_enable_login = 0 THEN 1 ELSE 0 END) as disable_users, SUM(CASE WHEN is_enable_login = 1 THEN 1 ELSE 0 END) as active_users')->first();

		        $users_data[$store->name] = [
		            'store_id' => $store->id,
		            'total_users' => !empty($users->total_users) ? $users->total_users : 0,
		            'disable_users' => !empty($users->disable_users) ? $users->disable_users : 0,
		            'active_users' => !empty($users->active_users) ? $users->active_users : 0,
		        ];
		    }
		    $store_data =[
		        'total_store' =>  $stors->total_store,
		        'disable_store' => $stors->disable_store,
		        'active_store' => $stors->active_store,
		    ];

		    $response['users_data'] = $users_data;
		    $response['store_data'] = $store_data;

		    return [
		        'is_success' => true,
		        'response' => $response,
		    ];
		}
		return [
		    'is_success' => false,
		    'error' => __( 'Plan is deleted.'),
		];
    }

    /**
     * S13-c. THIS HAD NO AUTHORIZATION OF ANY KIND -- no permission, no ownership, no tenancy --
     * and it is one of only three methods in this controller without any. Every sibling write is
     * gated, and update():220 already carries exactly the authorize() call this needed.
     *
     * WHAT IT WROTE FROM UNVALIDATED REQUEST FIELDS:
     *   users.is_enable_login on $request->id -- the LOGIN GATE. AuthenticatedSessionController:72
     *       refuses sign-in when it is 0, and also when the target's OWNER has it at 0, so one
     *       write reaches a merchant plus every staff member beneath them. Setting it to 1
     *       re-grants the ability to authenticate to an account an operator deliberately locked
     *       out; setting it to 0 locks out an arbitrary tenant.
     *   stores.is_active on $request->id -- re-activating a store an operator locked.
     *   users.current_store on $request->owner_id -- ANOTHER USER'S TENANT DISCRIMINATOR, which
     *       getCurrentStore() resolves and every store_id predicate in the application depends on.
     *
     * REPRODUCED before this change: a staff member in an UNRELATED tenant, holding zero
     * permissions, posted to /user-unable and re-enabled sign-in for a locked-out merchant. The
     * endpoint answered 200 with a success payload.
     *
     * THE FIX RESOLVES THE TARGET FIRST AND AUTHORIZES IT, which is what update():219-220 does:
     * UserPolicy::update requires 'Edit User', refuses anyone below platform-admin editing a
     * platform admin, and otherwise demands the same tenant. The owner id is DERIVED from the
     * resolved target rather than trusted from the body, because it is itself a write target.
     * is_active is normalised to a strict 0|1 before it reaches an update() array.
     */
    public function UserUnable(Request $request)
    {
		if(!empty($request->id) && !empty($request->owner_id))
		{
		    $isActive = (int) ((bool) $request->is_active);

		    if($request->name == 'user')
		    {
		        $target = User::find($request->id);

		        if (!$target) {
		            return response()->json(['error' => __('Something went wrong'),'users_data' => null, 'store_data' => null]);
		        }

		        if (! auth()->user() || ! auth()->user()->can('update', $target)) {
		            return response()->json(['error' => __('Permission denied.'),'users_data' => null, 'store_data' => null], 403);
		        }

		        // Derived from the resolved target, never taken from the request: the owner id is
		        // itself a write target in the `store` branch below.
		        $ownerId = $target->creatorId();

		        User::where('id', $target->id)->update(['is_enable_login' => $isActive]);
		        $data = $this->storeUserCounter($ownerId);



AuditLog::record(
    $isActive ? 'user_login_enabled' : 'user_login_disabled',
    $target
);


		    }
		    elseif($request->name == 'store')
		    {
                // The store must belong to the caller's own tenant, resolved the way
                // Api\DashboardController::resolveOwnedStore() and StoreController do it, with
                // the platform operator exempt. Without this the branch re-activated any store on
                // the platform and rewrote its users' login gate and the owner's current_store.
                $actor = auth()->user();

                if (!$actor) {
                    return response()->json(['error' => __('Permission denied.'),'users_data' => null, 'store_data' => null], 403);
                }

                $storeQuery = Store::where('id', $request->id);

                if (strtolower(trim((string) $actor->type)) !== 'super admin') {
                    $storeQuery->where('created_by', $actor->creatorId());
                }

                $targetStore = $storeQuery->first();

                if (!$targetStore) {
                    return response()->json(['error' => __('Permission denied.'),'users_data' => null, 'store_data' => null], 403);
                }

                // Derived from the store, not from the request body.
                $ownerId = $targetStore->created_by;

                $enabled_stores = Store::where('created_by', $ownerId)
                    ->where('is_active', 1)
                    ->count();
                if($isActive === 0){
                    if($enabled_stores != 1){

                        Store::where('id',$targetStore->id)->update(['is_active' => $isActive]);

                        User::where('current_store',$targetStore->id)->where('type','!=','admin')->update(['is_enable_login' => $isActive]);

                        $stores_enabled = Store::where('created_by', $ownerId)->where('is_active', 1)->first();

                        // Null guard: with no remaining enabled store this dereferenced null.
                        if ($stores_enabled) {
                            User::where('id', $ownerId)->update(['current_store' => $stores_enabled->id]);
                        }
                    }else{
                        return response()->json(['error' => __('All Store can not disable. At least One store must be enabled.')]);
                    }
                }else{
                    Store::where('id',$targetStore->id)->update(['is_active' => $isActive]);
                }
                $data = $this->storeUserCounter($ownerId);
		    }
		    if($data['is_success'])
		    {
		        $users_data = $data['response']['users_data'];
		        $store_data = $data['response']['store_data'];
		    }
		    if($isActive === 1){

		        return response()->json(['success' => __('Successfully Unable.'),'users_data' => $users_data, 'store_data' => $store_data]);
		    }else
		    {
		        return response()->json(['success' => __('Successfull Disable.'),'users_data' => $users_data, 'store_data' => $store_data]);
		    }
		}
        return response()->json(['error' => __('Something went wrong'),'users_data' => null, 'store_data' => null]);
    }

    /**
     * S13-d. SAME DEFECT AS UserUnable() AND STRICTLY WORSE IN ONE RESPECT: it is a TOGGLE, so a
     * single GET both grants sign-in to a locked-out account and revokes it from a live one,
     * depending on the target's current state. No permission, no ownership, no null guard.
     *
     * THE ENCRYPTED PARAMETER IS NOT A CONTROL, and this repository has already established and
     * acted on that position three times for the identical shape (UserController::reset():315,
     * StoreController::storeResetPassword():723, and UserPolicy::impersonate's docblock):
     * APP_KEY is in this repository's git history and unrotated, so an encrypted id is an
     * ENCODING anyone with a clone can mint for any user id.
     *
     * Reproduced before this change with a zero-permission staff member in an unrelated tenant.
     */
    public function userLoginManage($id)
    {
        try {
            $eId = \Crypt::decrypt($id);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $user = User::find($eId);

        // Null guard: find() on a forged or stale id returned null and the next line
        // dereferenced it.
        if (!$user) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (! auth()->user() || ! auth()->user()->can('update', $user)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ($user->is_enable_login == 1) {
            $user->is_enable_login = 0;
            $message = __('User login disable successfully.');
        } else {
            $user->is_enable_login = 1;
            $message = __('User login enable successfully.');
        }
        $user->save();

AuditLog::record(
    $user->is_enable_login ? 'user_login_enabled' : 'user_login_disabled',
    $user
);



        return redirect()->back()->with('success', $message);
    }

    public function list(Request $request, UserDataTable $dataTable)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Manage User')) {
            return $dataTable->render('users.list');
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
}