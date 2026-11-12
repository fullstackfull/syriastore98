<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Services\UserDeletionSafety;
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

                return redirect()->back()->with('error', implode(' ', $messages->all()));
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

                // P1-SEC-16. This block COPIED the creator's TOTP seed and enable flag onto the new sub-user, so two accounts shared one
                // authenticator secret: the creator's phone generated the sub-user's codes. A new user starts without two-factor and enrols their
                // own seed (behaviour change, recorded on the task).

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

            /*
             * P2.5-SEC-READ. SEC-F4 CLOSED THE WRITE DOOR AND LEFT THIS ONE OPEN.
             *
             * `update()` below carries SEC-F4's own account of this exact defect -- gated on
             * `Edit User` alone, "which every merchant holds for their own tenant", then operating
             * on a user found by id with no ownership test -- and it was repaired there with
             * `$this->authorize('update', $user)` at :260. This method, twenty lines above it, was
             * not touched: same file, same permission, same missing check, only the verb differs.
             *
             * MEASURED: a merchant holding `Edit User` requested another tenant's staff member and
             * got HTTP 200 with the victim's NAME and EMAIL rendered into the form
             * (`users/edit.blade.php` prints both). `Utility::addNewData()` grants `Edit User` to
             * the `admin` role that every merchant has -- 31 of the 33 users in the production
             * dump -- so the read reached every user on the platform while the write did not.
             *
             * `update` IS THE RIGHT ABILITY TO ASK FOR, not a weaker read ability: being allowed
             * to open the edit form is the same question as being allowed to submit it, and
             * borrowing the sibling's check is what keeps the two from drifting apart again. The
             * null guard is because `find()` returns null for an id that does not exist, and
             * `authorize()` on null would raise rather than refuse.
             */
            if (! $user) {
                return response()->json(['error' => __('Permission denied.')], 401);
            }

            $this->authorize('update', $user);

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
    /**
     * P0-DELETE. This used to be the `isAbleTo('Delete User')` check and nothing else, on a
     * `User` bound from the URL -- the exact shape SEC-F4 repaired in `update()` above, left
     * open on the one operation that cannot be undone.
     *
     * `User` is not in `config/tenancy.php`'s `scoped_models`, so route-model binding reached
     * every tenant's users and a permission every merchant holds for their own staff was the only
     * gate. `users` has no soft delete, and `stores.created_by` is ON DELETE CASCADE against
     * `users.id`, so the row-delete took the target's store and the money tables hanging off it.
     * There is a live button for this at resources/views/users/index.blade.php:124.
     *
     * `authorize()` is now the control, exactly as it is on `update()` (:259) and
     * `resetPassword()` (:360, :374). The permission test moved inside `UserPolicy::delete()` so
     * it sits beside the tenancy test and the two cannot drift apart.
     */
    public function destroy(User $user)
    {
        $this->authorize('delete', $user);

        /*
         * DATA SAFETY, SEPARATE FROM AUTHORIZATION, and owner-approved as fail-closed.
         *
         * `authorize()` above answers "may this principal delete this row". It does NOT answer
         * "is deleting this row safe at all", and the two are different questions: an operator
         * deleting a store owner destroys the same wallet a merchant would.
         *
         * THE CHECK AND THE DELETE ARE ONE TRANSACTION, with the row locked, because a
         * check-then-delete is a race: a store created between the count and the DELETE would be
         * destroyed by a guard that had just proved there was none.
         *
         * WHAT THE LOCK ACTUALLY CLOSES, corrected 2026-11-10 after independent review found this
         * paragraph claiming more than the code does. It said "the window is closed on both". It
         * is not, and the distinction is the same one the two dependency lists are built on:
         *
         *   CLOSED for the 15 CASCADE_DEPENDENCIES. InnoDB takes a SHARED record lock on the
         *   parent row while checking a foreign key, so an `INSERT INTO stores (created_by = X)`
         *   blocks behind our exclusive lock on user X. That -- not `lockForUpdate()` reaching
         *   into the child table -- is the mechanism, and it works only because all 15 of those
         *   tables have a real FK to `users(id)`.
         *
         *   OPEN for the ORPHAN_DEPENDENCIES. Those tables are in that list PRECISELY BECAUSE
         *   they carry no foreign key, so an insert into one takes no lock on `users` and is not
         *   serialised by anything here. T1 locks user X and counts zero orders; T2 inserts
         *   `orders.created_by = X` and commits; T1 deletes. The order is orphaned. The window
         *   needs the target user to be writing during their own deletion, which is narrow, and
         *   it is real. There is no cheap lock that covers an FK-less table, so this is REPORTED
         *   rather than closed -- see docs/security/P0-USER-DELETION-CASCADE.md.
         *
         * The SQLite half of the old claim was also loose: the guard's COUNT is a reader and the
         * write lock is not taken until the DELETE. Immaterial in the suite (one connection) and
         * in production (MySQL), but it was not the argument it was written as.
         *
         * If the row has gone between the route-model binding and the lock, there is nothing to
         * delete and nothing to report.
         */
        $blockers = [];

        DB::transaction(function () use ($user, &$blockers) {
            $locked = User::whereKey($user->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            $blockers = UserDeletionSafety::blockers($locked);

            if ($blockers !== []) {
                return;
            }

            // UNREACHABLE BY DESIGN, and kept rather than deleted. `settings` is an entry in
            // ORPHAN_DEPENDENCIES, so a user with any `settings` row is refused above and this
            // line only ever runs when there is nothing to delete. It stays because it is the
            // correct cleanup if `settings` is ever moved out of that list.
            Setting::where('created_by', $locked->id)->delete();
            $locked->delete();

            AuditLog::record('user_deleted', $locked, [
                'name' => $locked->name,
                'email' => $locked->email,
                'type' => $locked->type,
            ], []);
        });

        if ($blockers !== []) {
            return redirect()->back()->with(
                'error',
                __('This account cannot be deleted because it still owns data.') . ' '
                . implode('; ', $blockers)
            );
        }

        return redirect()->back()->with('success', 'User successfully deleted.');
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

            // P2.5: toast KEPT (five security tests read it), bag and old input ADDED, both
            // inside fails(). Never bare withInput(): see CredentialSinksTest:260. Full reasoning
            // in tests/Feature/Merchant/ProfileValidationFeedbackTest.php.
            //
            // `type` IS EXCLUDED HERE FOR THE SAME REASON AS password_update BELOW, and the two
            // lists are deliberately identical. profile.blade.php:209 renders
            // Form::hidden('type', Auth::guard()->user()->type), and konekt's
            // getValueAttribute() (vendor/konekt/html/src/FormBuilder.php:1293-1297) reads old()
            // BEFORE the model value, so a flashed `type` re-renders an attacker-supplied value
            // in that hidden field. This method persists only name/email/mobile/profile_image by
            // explicit assignment -- there is no fill() and no $user->type here -- so nothing
            // escalates today; excluding it keeps that true if anyone later adds one.
            return redirect()->back()
                ->with('error', $messages->first())
                ->withErrors($validator)
                ->withInput($request->except([
                    'password',
                    'password_confirmation',
                    'old_password',
                    'new_password',
                    'new_password_confirmation',
                    'type',
                ]));
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

            // P2.5 area-wide-no-withinput-witherrors (CLASS A). Same shape as editprofile
            // above: the toast stays, the bag and old input are added, both inside fails().
            //
            // `type` IS IN THE EXCLUSION LIST ON PURPOSE. resources/views/users/profile.blade.php
            // renders Form::hidden('type', Auth::guard()->user()->type), and konekt/html's
            // getValueAttribute() (vendor/konekt/html/src/FormBuilder.php:1293-1297) reads
            // old() BEFORE the model value -- so a flashed `type` would overwrite the
            // account's real type in the re-rendered hidden field.
            return redirect()->back()
                ->with('error', $messages->first())
                ->withErrors($validator)
                ->withInput($request->except([
                    'password',
                    'password_confirmation',
                    'old_password',
                    'new_password',
                    'new_password_confirmation',
                    'type',
                ]));
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

    /**
     * The tenant-owner overview behind "Admin Info" on the operator's store list.
     * =============================================================================================
     *
     * P2-05-5. THIS HANDLER HAD NO AUTHORIZATION OF ANY KIND -- no permission check, no ownership
     * predicate, no null guard -- and it reports the target's total / active / disabled STORE
     * counts plus, per store, their total / active / disabled USER counts. Driven: a merchant of
     * store A asking for store B's owner got **HTTP 200 and the figures**.
     *
     * Nothing else was stopping it, and each was checked rather than assumed: the route
     * (`web.php:443`) sits in the group carrying only `['auth', 'xss', 'setlocate', 'verified']`;
     * `User` is not in `config/tenancy.php`'s `scoped_models` and registers no global scope, so
     * `find()` reaches every tenant.
     *
     * AND `Crypt::decrypt()` WAS DOING THE WORK OF A CONTROL WITHOUT BEING ONE. `APP_KEY` is in
     * git history and unrotated, so an encrypted id in a URL is forgeable for any user id --
     * the same point `StoreController::storeResetPassword` records as SEC-F1. It is kept here
     * only as the URL FORMAT the two callers already use, never as the reason to trust the id.
     *
     * THE CALLERS DISAGREE ABOUT THAT FORMAT, which is why the id is resolved defensively rather
     * than by `decrypt()` alone: `store/action.blade.php:29` passes `encrypt($user->id)` while
     * `store/index.blade.php:159` passes the RAW id. The original tried to paper over that with
     * `if (empty(decrypt($id))) { $id = $request->id; }` -- a fallback to request input, the shape
     * this codebase has paid for repeatedly, and broken anyway because `decrypt()` THROWS on a
     * non-payload rather than returning empty, so the raw-id caller never reached the fallback.
     */
    public function userInfo(Request $request, $id)
	{
        $actor = auth()->user();

        if (! $actor) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        /* Accept either shape the two callers send, and treat NEITHER as proof of anything. */
        $targetId = null;

        try {
            $targetId = \Crypt::decrypt($id);
        } catch (\Throwable $e) {
            $targetId = is_numeric($id) ? (int) $id : null;
        }

        if (empty($targetId) || ! is_numeric($targetId)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $user = User::find((int) $targetId);

        /* A valid-but-unknown id reached `$user->is_active` unguarded and raised
           "property on null" -- an HTTP 500 that doubled as an existence oracle. */
        if (! $user) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        /*
         * OPERATOR OR SELF, and nothing else. Both are deliberate.
         *
         * OPERATOR FIRST because `isAbleTo()` alone REFUSES the platform operator here, and this
         * is the operator's own screen: an ownership fix written as a permission check would
         * have locked them out of it while every cross-tenant test still passed.
         *
         * THROUGH `UserPolicy::isOperator()` rather than a local comparison. The first version
         * wrote `strtolower(trim($actor->type))`, and `trim()` is WIDER THAN THE MySQL COLLATION:
         * the helper deliberately uses `rtrim(..., ' ')` to match PAD SPACE, because the engine
         * ignores a trailing space but not a leading one and not a tab. A row typed
         * `' super admin'` would have been operator here and not-operator to the database. That
         * is measured and documented at `UserPolicy:74-93`, and having two spellings of one
         * decision is the drift `OperatorCheckCensusTest` exists to stop.
         *
         * NO SAME-TENANT LIMB, and that is a deliberate narrowing rather than an omission. An
         * earlier version admitted anyone whose `creatorId()` matched the target's, which let a
         * ZERO-PERMISSION staff member read their own tenant's store counts and, through the
         * view, every colleague's name and login state -- while the sibling screens gate exactly
         * that roster on `Manage User` (`:36`, `:805`). It bought nothing: BOTH callers of this
         * route are operator-only -- `store/index.blade.php:159` sits inside
         * `@if (Auth::user()->type == 'super admin')` at `:148`, and `store/action.blade.php:29`
         * renders only through `StoreDataTable` for `StoreController::list`, which is gated the
         * same way. Checked that those are the only two callers before narrowing.
         */
        if (! \App\Policies\UserPolicy::isOperator($actor) && (int) $actor->id !== (int) $user->id) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $status = $user->is_active;
        $data = $this->storeUserCounter($user->id);

        if (empty($data['is_success'])) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $users_data = $data['response']['users_data'];
        $store_data = $data['response']['store_data'];

        /*
         * `$user->id`, NOT the route parameter. `compact('id')` passed the RAW parameter through,
         * and `users/user_info.blade.php:59` uses it as a tenant predicate:
         * `User::where('created_by', $id)`. From the operator's primary button that parameter is
         * CIPHERTEXT, and `users.created_by` is an unsignedBigInteger -- so on MySQL the
         * ciphertext casts to **0** and the modal lists whatever rows carry `created_by = 0`,
         * from any tenant. A caller-supplied value used as the tenant predicate, inside a view.
         *
         * THE SUITE COULD NEVER HAVE SHOWN THIS: on SQLite the same comparison matches nothing,
         * which is CLAUDE.md's standing warning that SQLite's type affinity hides real MySQL
         * defects. Pre-existing rather than introduced here, and closable now only because the
         * target is resolved and authorized above. It also fixes a plain functional bug -- the
         * operator's Admin Info modal printed "User not found." in every store tab.
         */
        return view('users.user_info', [
            'id' => $user->id,
            'users_data' => $users_data,
            'store_data' => $store_data,
            'status' => $status,
        ]);
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