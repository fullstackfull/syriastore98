<?php

namespace App\Http\Controllers;

use App\Policies\RolePolicy;

use App\Models\Store;
use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\DataTables\RoleDataTable;

class RoleController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */

    public function __construct()
    {
        
    }

    public function index(RoleDataTable $dataTable)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Manage Role'))
        {
            //$roles = Role::where('name', '!=', 'super admin')->where('created_by', '=', auth()->user()->creatorId())->get();
            
            return $dataTable->render('roles.index');
           // return view('roles.index',compact('roles'));
        }
        else
        {
            return redirect()->back()->with('error', 'Permission denied.');
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        // NEW-ROLE-FORM-UNGATED-01. This method had NO authorization at all, and
        // the view it renders lists every Permission row on the platform -- the
        // config/superadminaccess.php platform-operator names included. store()
        // has always required 'Create Role' (:104), so the gate existed on the
        // write and not on the screen. Same sibling-endpoint asymmetry as SEC-F3,
        // where update() filtered server-side and store() did not.
        //
        // Reached through the Gate, like update() and destroy(), so the decision
        // stays in RolePolicy and there is one place to read it.
        $this->authorize('create', Role::class);

        $user = \Auth::user();
        $permissions = Permission::all()->pluck('name', 'id')->toArray();
        $modules = array_merge(['General'], getshowModuleList());

        return view('roles.create', [
            'permissions' => $permissions,
            'modules' => $modules,
            'grantable' => $this->grantablePermissionNames(),
        ]);
    }

    /**
     * The permission NAMES the signed-in actor may grant, computed once per request.
     *
     * P1-SEC-02. The role editor needs this per permission per module tab, and asking the Gate
     * each time measured 601 GateEvaluated events and 1,742 queries for one `GET /roles/create`
     * on a 200-permission table, against 1 and 14 before the change. `RolePolicy::grantableNames`
     * answers the same question with the tenant owner resolved once, and both it and
     * `grantPermission()` end in the same private per-name rule, so the form and the write path
     * cannot drift -- which was the point of routing the view through the policy at all.
     *
     * @return list<string>
     */
    protected function grantablePermissionNames(): array
    {
        return (new RolePolicy())->grantableNames(
            auth()->user(),
            Permission::pluck('name')->all()
        );
    }


    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Create Role'))
        {
            // GAP-279 / P04-P7-01. A ROLE MUST NOT BE WRITTEN WITHOUT A STORE, because a
            // `store_id IS NULL` role counts for EVERY store.
            //
            // `App\Services\MerchantPermissions::names()` keeps a NULL-store arm deliberately:
            // the seeded global `admin` role that RegisteredUserController and
            // StoreController:387 hand every merchant has `store_id` NULL, and narrowing the
            // READER would take every merchant's permissions away in their own store (that
            // class's docblock records the test that fails without the arm). The reader's
            // premise is that such a role is ONLY ever that seeded one -- and this line is
            // where the premise was broken: `getCurrentStore()` answers NULL for a principal
            // with an empty `current_store` who owns no store under their own id
            // (helper.php:881-895), so the role went in with no store and then answered for
            // every store of the tenant.
            //
            // MEASURED through these routes, not reasoned about -- Gap279NullStoreRoleWriterTest:
            //   POST /users  as an operator with an empty current_store -> a staff row whose
            //                current_store is NULL (UserController:158 copies the creator's),
            //                and getCurrentStore() answers NULL for it
            //   POST /roles  as that principal -> roles.store_id NULL
            //   PUT /users/{self} -> self-assigned (its role lookup's own
            //                `where('store_id', getCurrentStore())` compiles to `IS NULL`, so it
            //                finds exactly the role just minted)
            //   then MerchantPermissions::allowsAny($staff, ['Manage Order'], $siblingStore) -> TRUE
            // WITHIN-TENANT ONLY: cross-tenant is closed before that gate is ever asked, by
            // ApiController::resolveViewableOrder()'s tenant-root and owned-store test and by
            // DashboardController::ownedStoreIds(). This is not a cross-tenant escalation.
            //
            // REFUSE RATHER THAN STAMP A STORE. The two candidate remedies were "refuse" and
            // "stamp the tenant root's own store"; stamping would silently file the role in a
            // store this principal has no context for and hand them a grant somewhere they were
            // not working, which is a quieter version of the same defect.
            //
            // WHO ELSE THIS REFUSES, NAMED IN FULL. `getCurrentStore()` returns `current_store`
            // when set, the hardcoded literal 1 for a `super admin` with none, and otherwise the
            // caller's own store. So an ordinary merchant and a CANONICALLY typed operator are
            // both unaffected -- the merchant half is pinned by
            // `test_the_control_a_merchant_with_a_store_still_creates_roles`, and a storeless
            // `'super admin'` was never refused because the helper hands them the literal 1. TWO
            // principals ARE refused, and BOTH ARE NAMED HERE BECAUSE THIS PARAGRAPH HAS NOW BEEN
            // WRONG TWICE: its first version claimed the guard narrowed nobody, and its second
            // claimed the refused principal "can still create one by selecting a store". Neither
            // was true.
            //
            //   1. OPERATOR-ROOTED STAFF -- live, product-reachable, and the row this change's own
            //      test is built from. `POST /users` while the operator's `current_store` is empty
            //      copies that empty pointer (`UserController` writes the creator's verbatim), so
            //      `getCurrentStore()` answers NULL for the new row and every `POST /roles` by it
            //      is refused from now on.
            //
            //   2. AN OPERATOR WHOSE `type` IS SPELLED ANY OTHER WAY. The helper's operator branch
            //      compares loosely and CASE-SENSITIVELY, unlike `UserPolicy::isOperator()`, which
            //      accepts `'Super Admin'` and `'super admin '` -- values MySQL's
            //      utf8mb4_unicode_ci treats as equal to the literal. Such a row falls to the
            //      owned-store branch and, owning no store, resolves NULL.
            //
            // AND THE REFUSAL IS PERMANENT: NEITHER CAN RECOVER THROUGH THE PRODUCT. MEASURED, not
            // argued -- `test_an_operator_rooted_staff_member_cannot_recover_by_selecting_a_store`
            // drives `POST /change-store/{id}` and reads 403 with the pointer still empty.
            // `StoreController::changeStore()` sends every `type` that is not exactly
            // `'super admin'` through `UserPolicy::merchantTenantRoot()`, which requires a
            // type-`admin` tenant root; for both principals above the root is the operator or
            // nobody, so it answers null and the handler aborts. The admin API twin refuses
            // identically via `resolveOwnedStore()`. Such a row therefore needs its
            // `current_store` SET OUT OF BAND -- the operator editing the user, or the database.
            //
            // THE FIX IS DELIBERATELY NOT WIDENED TO RESTORE THE CAPABILITY, because the capability
            // IS the defect: what these principals could do before was mint a role that counted for
            // every store in the tenant. Normalising the helper's comparison is also NOT done here:
            // it is read by most handlers on the platform and `config/tenancy.php:60-63` records two
            // storefront outages shipped by changing a helper that central.
            //
            // `filter_var` STRICTLY, never `is_numeric` or a cast: a cast turns junk into 0,
            // which is a store id some rows really carry and is literally
            // `OperatorStoreSelection::NO_STORE`. Same spelling as
            // `DocumentDirection::ownRow()`, for the same reason.
            //
            // RESOLVED ONCE AND REUSED below, so the duplicate-name lookup, the uniqueness rule
            // and the write cannot disagree about which store they mean.
            $storeId = filter_var(getCurrentStore(), FILTER_VALIDATE_INT);

            if ($storeId === false || $storeId <= 0) {
                return redirect()->back()->with('error', __('No store is selected, so a role cannot be created. Open the store this role is for and try again.'));
            }

            $existRole =  Role::where('name', $request->name)->where('store_id', $storeId)->first();
            if ($existRole && $existRole->created_by != auth()->user()->id) {
                return redirect()->back()->with('error', __('The role is already being used in another account.'));
            }

            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => [
                        'required',
                        'max:100',
                        // S13-b. THE SIBLING update():335 ALREADY HAD THIS AND store() DID NOT,
                        // which is how the escalation got its role: a staff member with
                        // 'Create Role' created a tenant-scoped role named `admin` here, then
                        // assigned it to themselves through UserController::update(), whose own
                        // reserved-name refusal could not help because RESERVED_NAMES omitted
                        // 'admin'. Closing the list was necessary; closing this validator stops
                        // the role existing in the first place.
                        //
                        // WAS `Rule::notIn(RolePolicy::RESERVED_NAMES)`, which is CASE-SENSITIVE
                        // against a hand-written spelling list and let through 'Super admin',
                        // 'sUper admin', 'SUPER Admin', 'Super ADMIN' and 'aDmin' -- measured. The
                        // gate that actually decides the users.type write (UserController:147,
                        // :302) lowercases, so the two halves of one invariant disagreed about
                        // case. No escalation followed (see RolePolicy::reservedNameRule for the
                        // two independent reasons, both measured), but a defence that only looks
                        // like one is worse than none.
                        RolePolicy::reservedNameRule(),
                        Rule::unique('roles')->where(function ($query) use ($storeId) {
                        return $query->where('store_id', $storeId)->where('created_by', \Auth::user()->id);
                        })
                    ],
                    'permissions' => 'required',
                ]
            );

            if($validator->fails())
            {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', implode(' ', $messages->all()));
            }

            $name             = $request['name'];
            $role             = new Role();
            $role->name       = $name;
            // $role->store_id   = getCurrentStore();
            $role->guard_name  = 'web';
            $role->created_by = Auth::user()->creatorId();
            // GAP-279: the store resolved and refused above, rather than a fourth
            // `getCurrentStore()` call. READABILITY AND NOT A SECOND CONTROL, and a mutation says
            // so: replacing this with `getCurrentStore()` again SURVIVES the whole GAP-279 group,
            // because the guard has already returned for the only principal whose answer differs
            // and `getCurrentStore()` is deterministic within a request. An earlier version of
            // this comment claimed it was "a second chance to write a NULL"; that was an
            // overclaim and is withdrawn. The control is the refusal, in one place.
            $role->store_id   = $storeId;
            $permissions      = $request['permissions'];
            $role->save();

            // SAME CONTROL AS update() AT :405, which this path was missing.
            //
            // config/superadminaccess.php was enforced only in Blade
            // (roles/create.blade.php:72,98), so a crafted POST to roles.store
            // granted platform-operator permissions to any role. update() was
            // filtered server-side and store() was not, which left the escalation
            // fully open through the sibling endpoint: public registration grants
            // type=admin, and the shared admin role carries 'Create Role'
            // (Utility.php:2871), so a self-registered merchant could mint a role
            // holding 'Create Plan', 'Manage Country', 'Manage currency' and the
            // rest, then assign it to themselves.
            $permissions = $this->filterGrantablePermissions($permissions);

            foreach($permissions as $permission)
            {
                $p = Permission::where('id', '=', $permission)->firstOrFail();
                $role->givePermission($p);
            }

            return redirect()->back()->with('success', __('Role successfully created.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
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
    public function edit(Role $role)
    {
        // NEW-ROLE-FORM-UNGATED-01, the second half and the IDOR one. This method
        // had no authorization and no ownership test, so ANY authenticated user
        // could GET /roles/{id}/edit for ANY role id on the platform -- including
        // the shared global `admin` role and roles belonging to other merchants --
        // and the view renders $role, disclosing its name.
        //
        // update() (:339) and destroy() (:506) already authorize; the form that
        // leads to update() did not, which is the same omission one step earlier in
        // the flow. Gated on the SAME policy decision as the write it leads to, so
        // the screen and the action cannot drift apart: a role you may not update
        // is a role whose update form you may not open.
        $this->authorize('update', $role);

        // $permissions = Permission::all()->pluck('name', 'id')->toArray();
        $user = \Auth::user();

        $modules = array_merge(['General'],getshowModuleList());
        $permissions = Permission::pluck('name', 'id')->toArray();
        $rolePermissions = new Collection();
        foreach($user->roles as $role1)
        {
            $rolePermissions = $rolePermissions->merge($role1->permissions);
        }
        $rolePermissions = $rolePermissions->pluck('name', 'id')->toArray();
        $grantable = $this->grantablePermissionNames();

        return view('roles.edit',
            compact('role', 'modules', 'permissions', 'rolePermissions', 'grantable'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Role $role)
    {
        // SEC-F3. This method was gated on the 'Edit Role' permission alone --
        // which every merchant holds -- and then operated on an implicitly bound
        // Role with no ownership test, so any merchant could rewrite ANY role on
        // the platform, including the global `super admin` role.
        $this->authorize('update', $role);

        $existRole =  Role::where('name', $request->name)->where('store_id', getCurrentStore())->where('id', '!=', $role->id)->first();
        if ($existRole && $existRole->created_by != auth()->user()->id) {
            return redirect()->back()->with('error', __('The role is already being used in another account.'));
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'name' => [
                    'required',
                    'max:100',
                    // Reserved because the application decides platform-operator
                    // status by comparing users.type against these literals. Same correction as
                    // store():195 -- this was the case-SENSITIVE Rule::notIn, and THIS site is the
                    // more interesting of the two: a role created under a safe name can be RENAMED
                    // here, which is a create-time-only check's blind spot.
                    RolePolicy::reservedNameRule(),
                    'unique:roles,name,' . $role['id'] . ',id,created_by,' . \Auth::user()->creatorId().',store_id,' . getCurrentStore(),
                ],
                'permissions' => 'required',
            ]
        );

        if($validator->fails())
        {
            $messages = $validator->getMessageBag();

            return redirect()->back()->with('error', implode(' ', $messages->all()));
        }

        $input['name'] = $request['name'];

        // DELETED, and this is the actual privilege-escalation primitive:
        //
        //   $userIds = DB::table('role_user')->where('role_id', $role->id)
        //       ->pluck('user_id')->toArray();
        //   User::whereIn('id', $userIds)->update(['type' => $input['name']]);
        //
        // Every self-registered merchant is attached to ONE SHARED global
        // `admin` role (RegisteredUserController does an unscoped
        // Role::where('name','admin')->first()), so renaming one's own role to
        // 'super admin' promoted EVERY merchant on the platform in a single
        // request. Aimed the other way it renamed every genuine super admin's
        // type away from 'super admin', failing every type check for them --
        // irreversible lockout of all platform administrators.
        //
        // Safe to delete: the users.type consumers (StoreController:43,
        // AddonController:21/:153, EmailIsVerified:46, User::creatorId():97) all
        // compare against the literals 'super admin'/'admin', never a custom
        // role name. Laratrust already owns role membership via role_user.
        // Consequence, deliberately accepted: users.type no longer tracks role
        // renames.

        $permissions = $request['permissions'];

        // NOT $input['store_id'] = getCurrentStore(). That line, combined with
        // Role::$guarded = [], let the fill() STEAL a victim role out of its own
        // store and into the attacker's. A role's store never changes here.
        $role->fill($input)->save();

        // config/superadminaccess.php was enforced ONLY in Blade
        // (roles/create.blade.php:72,98 and roles/edit.blade.php:92), so a
        // crafted POST granted platform-operator permissions to any role.
        // Filter server-side.
        $permissions = $this->filterGrantablePermissions($permissions);

        $p_all = Permission::all();

        foreach($p_all as $p)
        {
            $role->removePermission($p);
        }

        $role->syncPermissions($permissions);

        return redirect()->back()->with('success', __('Role successfully updated.'));
    }

    /**
     * Remove any permission the caller is not entitled to grant.
     *
     * Accepts the permission-id array the form posts and drops every id whose
     * NAME the caller may not grant.
     *
     * ONE DECISION POINT -- NEW-rolepolicy-grantpermission-dead-code.
     *
     * RolePolicy::grantPermission() was called from NOWHERE. `grep -rn
     * "grantPermission" app/` found the policy declaration and no call site,
     * while store() and update() each re-implemented the same rule inline here.
     * A dead policy method that READS like the control is worse than either
     * having it or not: a reviewer checking "is privilege escalation gated?"
     * finds a correct-looking policy and stops, and the two live paths are free
     * to drift apart from it -- which is exactly the shape of the original
     * defect, where update() filtered server-side and store() did not.
     *
     * So the rule now lives in the policy only, and this method is the single
     * place that resolves ids to names and applies it. Both store() and
     * update() call this, so there is one decision point for all three.
     *
     * The decision is reached through the Gate rather than by calling the
     * policy class directly, so the policy stays overridable and testable the
     * same way RolePolicy::update and ::delete are (both reached via
     * $this->authorize()). Gate::callPolicyMethod drops a leading class-string
     * argument (Gate.php:802-809), so `can('grantPermission', [Role::class,
     * $name])` invokes RolePolicy::grantPermission($user, $name) -- verified by
     * execution in RoleEscalationTest, not inferred.
     *
     * Behaviour is deliberately UNCHANGED for both branches: a platform admin
     * may grant anything (policy :99-101), and anybody else may not grant a
     * name listed in config/superadminaccess.php (policy :103). An id with no
     * matching Permission row is dropped rather than passed through -- store()
     * would have thrown on it at firstOrFail() and update() would have
     * syncPermissions()'d a dangling id.
     */
    protected function filterGrantablePermissions($permissions)
    {
        $permissions = (array) $permissions;

        if (empty($permissions)) {
            return [];
        }

        $actor = auth()->user();

        // Fail CLOSED. Reaching here with no principal means the permission
        // check that guards both call sites was removed; granting nothing is
        // the safe answer.
        if (!$actor) {
            return [];
        }

        // Keys are normalised to int explicitly: pluck() keys come back as
        // whatever the driver returns, and a string-keyed map silently misses
        // every lookup -- which would drop EVERY permission and look like a
        // working filter.
        $names = [];

        foreach (
            Permission::whereIn('id', array_map('intval', $permissions))->get(['id', 'name'])
            as $permission
        ) {
            $names[(int) $permission->id] = $permission->name;
        }

        return array_values(array_filter($permissions, function ($permissionId) use ($actor, $names) {
            $name = $names[(int) $permissionId] ?? null;

            if ($name === null) {
                return false;
            }

            return $actor->can('grantPermission', [Role::class, $name]);
        }));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Role $role)
    {
        // R4-04: the implicitly bound id was the only authorization token, so
        // any holder of 'Delete Role' deleted any store's role.
        $this->authorize('delete', $role);

        $role->delete();

        return redirect()->route('roles.index')->with(
            'success', 'Role successfully deleted.'
        );
    }
}
