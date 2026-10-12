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
        $user = \Auth::user();
        $permissions = Permission::all()->pluck('name', 'id')->toArray();
        $modules = array_merge(['General'], getshowModuleList());
        return view('roles.create', ['permissions' => $permissions, 'modules' => $modules]);
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
            $existRole =  Role::where('name', $request->name)->where('store_id', getCurrentStore())->first();
            if ($existRole && $existRole->created_by != auth()->user()->id) {
                return redirect()->back()->with('error', __('The role is already being used in another account.'));
            }

            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => [
                        'required',
                        Rule::unique('roles')->where(function ($query) {
                        return $query->where('store_id', getCurrentStore())->where('created_by', \Auth::user()->id);
                        })
                    ],
                    'permissions' => 'required',
                ]
            );

            if($validator->fails())
            {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }

            $name             = $request['name'];
            $role             = new Role();
            $role->name       = $name;
            // $role->store_id   = getCurrentStore();
            $role->guard_name  = 'web';
            $role->created_by = Auth::user()->creatorId();
            $role->store_id   = getCurrentStore();
            $permissions      = $request['permissions'];
            $role->save();

            // SAME CONTROL AS update() AT :227, which this path was missing.
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
        return view('roles.edit', compact('role', 'modules', 'permissions', 'rolePermissions'));
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
                    // status by comparing users.type against these literals.
                    \Illuminate\Validation\Rule::notIn(RolePolicy::RESERVED_NAMES),
                    'unique:roles,name,' . $role['id'] . ',id,created_by,' . \Auth::user()->creatorId().',store_id,' . getCurrentStore(),
                ],
                'permissions' => 'required',
            ]
        );

        if($validator->fails())
        {
            $messages = $validator->getMessageBag();

            return redirect()->back()->with('error', $messages->first());
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
     * Accepts the permission-id array the form posts and drops the ids whose
     * names appear in config/superadminaccess.php when the caller is not a
     * platform administrator.
     */
    protected function filterGrantablePermissions($permissions)
    {
        $permissions = (array) $permissions;

        if (empty($permissions)) {
            return [];
        }

        $actor = auth()->user();

        if ($actor && $actor->type === RolePolicy::PLATFORM_ADMIN) {
            return $permissions;
        }

        $restricted = (array) config('superadminaccess', []);

        if (empty($restricted)) {
            return $permissions;
        }

        $blockedIds = Permission::whereIn('name', $restricted)->pluck('id')->all();

        return array_values(array_filter($permissions, function ($permissionId) use ($blockedIds) {
            return !in_array((int) $permissionId, array_map('intval', $blockedIds), true);
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
