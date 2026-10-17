<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use App\Models\Store;
use App\Models\Plan;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\View\View;
use App\Events\VerifyReCaptchaToken;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Cache;

class RegisteredUserController extends Controller
{
    public function __construct(Request $request)
    {
        if(!file_exists(storage_path() . "/installed"))
        {
            header('location:install');
            die;
        }
    }

    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $settings = \App\Models\Utility::Setting();
        try {
            $planId = \Illuminate\Support\Facades\Crypt::decrypt($request->plan_id);
        } catch (\Exception $e) {
            $planId = 1;
        }

        $validation = [];

        if(isset($settings['RECAPTCHA_MODULE']) && $settings['RECAPTCHA_MODULE'] == 'yes')
        {
            if ($settings['NOCAPTCHA_VERSON'] == 'v2') {
                $validation['g-recaptcha-response'] = 'required';
            } elseif ($settings['NOCAPTCHA_VERSON'] == 'v3') {
                $result = event(new VerifyReCaptchaToken($request));

                if (!isset($result[0]['status']) || $result[0]['status'] != true) {
                    $key = 'g-recaptcha-response';
                    $request->merge([$key => null]); // Set the key to null

                    $validation['g-recaptcha-response'] = 'required';
                }
            }
        }

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'store_name' => ['required', 'string', 'max:255'],
        ]+$validation);

        if(isset($settings['email_verification']) && $settings['email_verification'] == "on")
        {
            try {
                config(
                    [
                        'mail.driver' => $settings['MAIL_DRIVER'],
                        'mail.host' => $settings['MAIL_HOST'],
                        'mail.port' => $settings['MAIL_PORT'],
                        'mail.encryption' => $settings['MAIL_ENCRYPTION'],
                        'mail.username' => $settings['MAIL_USERNAME'],
                        'mail.password' => $settings['MAIL_PASSWORD'],
                        'mail.from.address' => $settings['MAIL_FROM_ADDRESS'],
                        'mail.from.name' => $settings['MAIL_FROM_NAME'],
                    ]
                );

                $superAdmin = Cache::remember('super_admin_details', 3600, function () {
                    return User::where('type','super admin')->first();
                });

                // NEW-registration-grants-admin -- DELIBERATELY NOT CHANGED HERE.
                //
                // type='admin' is the merchant tenant-OWNER type, not the
                // platform-operator type ('super admin'). It is load-bearing:
                // User::creatorId() returns $this->id for it, which is what makes
                // a self-registered merchant the root of their own tenant, and
                // the plan/expiry logic below branches on it. Changing it would
                // break legitimate merchant onboarding, so it is left alone.
                //
                // The real hazard is the UNSCOPED Role::where('name','admin')
                // lookup further down, which attaches every self-registered
                // merchant to ONE SHARED GLOBAL role row (PermissionTableSeeder
                // creates it with a null store_id). The escalation that made that
                // critical -- rewriting that shared role, and the users.type
                // mass-update it triggered -- is CLOSED in RoleController and
                // RolePolicy, which now refuse to edit any role with a null
                // store_id. Giving each store its own admin role is the remaining
                // root-cause fix; it is a role_user + permission_role data
                // migration that needs the live database and is an OWNER ACTION,
                // not a code change that can be made safely from here.
                $user = User::create([
                    'name' => $request->name,
                    'email' => $request->email,
                    'profile_image' => 'uploads/profile/avatar.png',
                    'type' => 'admin',
                    //'email_verified_at' => date('Y-m-d H:i:s'),
                    'password' => Hash::make($request->password),
                    // No number, spelled the one way this column now spells it (B4). Super Admin
                    // store creation writes null here too; an empty string and a null that both
                    // mean "nothing" is how a later reader comes to handle only one of them.
                    'mobile' => null,
                    'language' => $superAdmin->default_language ?? 'en',
                    'default_language' => $superAdmin->default_language ?? 'en',
                    'created_by' => 1,
                    'is_active' => 1,
                ]);

                $slug = User::slugs($request->store_name);
                $store = Store::create([
                        'name' => $request->store_name,
                        'email' => $request->email,
                        'theme_id' => 'stylique',
                        'slug' => $slug,
                        'created_by' => $user->id,
                        'default_language' => $superAdmin->default_language ?? 'en'
                    ]);

                $user->current_store = $store->id;
                $user->save();
                // event(new Registered($user));
                $user->sendEmailVerificationNotification();
                Auth::login($user);
                defaultSetting($store->id, 'admin', $user);
                pageDefaultData($store->id);
                $role_r = Role::where('name', 'admin')->first();

                // NEW-registration-addrole-null-deref -- the null-guard defect named in the
                // brief, same class as get_file() (app/Helper/helper.php:143-145, where
                // User::where('type','super admin')->first() feeds ->current_store with no
                // check).
                //
                // Laratrust's addRole() signature is
                //   addRole(array|string|int|Model|UuidInterface|BackedEnum $role, ...)
                // (vendor/santigarcor/laratrust/src/Traits/HasRolesAndPermissions.php:375-378).
                // It is a TYPED parameter with no null in the union, so passing null is an
                // immediate TypeError, not a quiet no-op.
                //
                // WHY THAT MATTERS MORE THAN AN ORDINARY 500. By the time this line runs the
                // method has ALREADY created the User row, created the Store row, written
                // users.current_store, called Auth::login() and run defaultSetting() /
                // pageDefaultData(). None of that is in a transaction. So a missing `admin`
                // role does not merely fail the request -- it leaves a HALF-REGISTERED
                // merchant: a real user, a real store, a live session, and no role at all.
                // That is a data-integrity defect, and it is reachable on any install where
                // PermissionTableSeeder has not run, or where the role was renamed.
                //
                // The guard lets registration COMPLETE instead of dying mid-way, and logs
                // the condition so a roleless merchant is visible rather than silent. It
                // deliberately does NOT create the role: the missing global `admin` role is
                // the shared-role problem described above, and inventing a row here would
                // make that worse, not better. No secret is logged -- only the user id.
                if ($role_r) {
                    $user->addRole($role_r);
                } else {
                    \Illuminate\Support\Facades\Log::warning(
                        'Registration completed without a role: no Role named "admin" exists. '
                        . 'The new merchant has no permissions until one is assigned.',
                        ['user_id' => $user->id]
                    );
                }
                if($user->type == 'admin')
                {
                    $plan = Plan::find($user->plan_id);
                    if($plan)
                    {
                        if($plan->duration != 'Unlimited')
                        {
                            $datetime1 = new \DateTime($user->plan_expire_date);
                            $datetime2 = new \DateTime(date('Y-m-d'));
                            $interval = $datetime2->diff($datetime1);
                            $days     = $interval->format('%r%a');
                            if($days <= 0)
                            {
                                $user->assignPlan(1);

                                return redirect()->intended(RouteServiceProvider::HOME)->with('error', __('Your Plan is expired.'));
                            }
                        }

                        if($user->trial_expire_date != null)
                        {
                            if(\Auth::user()->trial_expire_date < date('Y-m-d'))
                            {
                                $user->assignPlan(1);

                                return redirect()->intended(RouteServiceProvider::HOME)->with('error', __('Your Trial plan Expired.'));
                            }
                        }
                    } elseif (empty($plan) && isset($planId)) {
                        $user->assignPlan(1);
                        if (isset($planId) && $planId == 1) {
                            return redirect()->intended(RouteServiceProvider::HOME);
                        } else {
                            return redirect()->route('stripe', \Illuminate\Support\Facades\Crypt::encrypt($planId));
                        }
                    }
                }

            } catch (\Exception $e) {
                return redirect('/register')->with('status', __('Email SMTP settings does not configure so please contact to your site admin.'));
            }
            return redirect()->route('verify-email');
        } else {
            $superAdmin = Cache::remember('super_admin_details', 3600, function () {
                return User::where('type','super admin')->first();
            });

                // NEW-registration-grants-admin -- DELIBERATELY NOT CHANGED HERE.
                //
                // type='admin' is the merchant tenant-OWNER type, not the
                // platform-operator type ('super admin'). It is load-bearing:
                // User::creatorId() returns $this->id for it, which is what makes
                // a self-registered merchant the root of their own tenant, and
                // the plan/expiry logic below branches on it. Changing it would
                // break legitimate merchant onboarding, so it is left alone.
                //
                // The real hazard is the UNSCOPED Role::where('name','admin')
                // lookup further down, which attaches every self-registered
                // merchant to ONE SHARED GLOBAL role row (PermissionTableSeeder
                // creates it with a null store_id). The escalation that made that
                // critical -- rewriting that shared role, and the users.type
                // mass-update it triggered -- is CLOSED in RoleController and
                // RolePolicy, which now refuse to edit any role with a null
                // store_id. Giving each store its own admin role is the remaining
                // root-cause fix; it is a role_user + permission_role data
                // migration that needs the live database and is an OWNER ACTION,
                // not a code change that can be made safely from here.
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'profile_image' => 'uploads/profile/avatar.png',
                'type' => 'admin',
                'password' => Hash::make($request->password),
                // No number, spelled the one way this column now spells it (B4). Super Admin
                    // store creation writes null here too; an empty string and a null that both
                    // mean "nothing" is how a later reader comes to handle only one of them.
                    'mobile' => null,
                'language' => $superAdmin->default_language ?? 'en',
                'default_language' => $superAdmin->default_language ?? 'en',
                'created_by' => 1,
                'is_active' => 1
            ]);

            $slug = User::slugs($request->store_name);

            $store = Store::create([
                    'name' => $request->store_name,
                    'email' => $request->email,
                    'theme_id' => 'stylique',
                    'slug' => $slug,
                    'created_by' => $user->id,
                    'default_language' => $superAdmin->default_language ?? 'en'
                ]);

            $user->current_store = $store->id;
            $user->save();
            Auth::login($user);
            defaultSetting($store->id, 'admin', $user);
            $role_r = Role::where('name', 'admin')->first();

            // NEW-registration-addrole-null-deref -- the null-guard defect named in the
            // brief, same class as get_file() (app/Helper/helper.php:143-145, where
            // User::where('type','super admin')->first() feeds ->current_store with no
            // check).
            //
            // Laratrust's addRole() signature is
            //   addRole(array|string|int|Model|UuidInterface|BackedEnum $role, ...)
            // (vendor/santigarcor/laratrust/src/Traits/HasRolesAndPermissions.php:375-378).
            // It is a TYPED parameter with no null in the union, so passing null is an
            // immediate TypeError, not a quiet no-op.
            //
            // WHY THAT MATTERS MORE THAN AN ORDINARY 500. By the time this line runs the
            // method has ALREADY created the User row, created the Store row, written
            // users.current_store, called Auth::login() and run defaultSetting() /
            // pageDefaultData(). None of that is in a transaction. So a missing `admin`
            // role does not merely fail the request -- it leaves a HALF-REGISTERED
            // merchant: a real user, a real store, a live session, and no role at all.
            // That is a data-integrity defect, and it is reachable on any install where
            // PermissionTableSeeder has not run, or where the role was renamed.
            //
            // The guard lets registration COMPLETE instead of dying mid-way, and logs
            // the condition so a roleless merchant is visible rather than silent. It
            // deliberately does NOT create the role: the missing global `admin` role is
            // the shared-role problem described above, and inventing a row here would
            // make that worse, not better. No secret is logged -- only the user id.
            if ($role_r) {
                $user->addRole($role_r);
            } else {
                \Illuminate\Support\Facades\Log::warning(
                    'Registration completed without a role: no Role named "admin" exists. '
                    . 'The new merchant has no permissions until one is assigned.',
                    ['user_id' => $user->id]
                );
            }
            if($user->type == 'admin')
            {
                $plan = Plan::find($user->plan_id);
                if($plan)
                {
                    if($plan->duration != 'Unlimited')
                    {
                        $datetime1 = new \DateTime($user->plan_expire_date);
                        $datetime2 = new \DateTime(date('Y-m-d'));
                        $interval = $datetime2->diff($datetime1);
                        $days     = $interval->format('%r%a');
                        if($days <= 0)
                        {
                            $user->assignPlan(1);

                            return redirect()->intended(RouteServiceProvider::HOME)->with('error', __('Your Plan is expired.'));
                        }
                    }

                    if($user->trial_expire_date != null)
                    {
                        if(\Auth::user()->trial_expire_date < date('Y-m-d'))
                        {
                            $user->assignPlan(1);

                            return redirect()->intended(RouteServiceProvider::HOME)->with('error', __('Your Trial plan Expired.'));
                        }
                    }
                } elseif (empty($plan) && isset($planId)) {
                    $user->assignPlan(1);
                    $user->save();
                    if (isset($planId) && $planId == 1) {
                        return redirect()->intended(RouteServiceProvider::HOME);
                    } else {
                        return redirect()->route('stripe', \Illuminate\Support\Facades\Crypt::encrypt($planId));
                    }                    
                }
            }         

            return redirect(RouteServiceProvider::HOME);
        }
    }

    public function verify_email()
    {
        return view('auth.verify-email');
    }
}
