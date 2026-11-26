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

        /*
         * GAP-014 -- THE ROLE IS LOOKED UP BEFORE ANYTHING IS WRITTEN, AND ITS ABSENCE REFUSES
         * THE WHOLE REGISTRATION.
         *
         * This lookup used to sit at the END of each provisioning branch, immediately above
         * `addRole()`, with a tolerant `if ($role_r)` around it (the two sites were :179 and
         * :310). That guard was a correct first move -- `addRole()` is typed
         * `array|string|int|Model|UuidInterface|BackedEnum`, so `addRole(null)` is an immediate
         * TypeError -- but it only moved the damage. By the time either site ran, the method had
         * already created the user row and the store row, provisioned the store wallet, written
         * `users.current_store`, called `Auth::login()` and run `defaultSetting()` /
         * `pageDefaultData()`, NONE of it in a transaction. So a missing role stopped being a 500
         * and became worse: registration reported SUCCESS and committed a merchant with a real
         * store, a live session and no role at all. MEASURED on a fresh database (`roles` holds 0
         * rows, because `RefreshDatabase` runs migrations and not seeders): 302, authenticated,
         * users +1, stores +1, store_wallets +1, role_user 0.
         *
         * THE GAP REGISTER'S WORDS ARE "the half-provisioned row should not survive", and the
         * traceability row's expected behaviour is "a merchant who registers is provisioned with a
         * role, or registration fails loudly". Checking first satisfies both without a
         * transaction around a verification e-mail and a session login: when the role is absent
         * nothing is created, nobody is signed in, no mail is sent, and the visitor is told. The
         * half-provisioned merchant cannot survive because it is never born.
         *
         * IT DELIBERATELY DOES NOT CREATE THE ROLE. The `admin` role is ONE SHARED GLOBAL row
         * with a null `store_id` that every self-registered merchant is attached to -- see the
         * note at the first `User::create()` below -- so inventing one here would make that
         * hazard worse, and granting privileges is an owner decision rather than an engineering
         * one. Giving each store its own admin role is still the root-cause fix and is still a
         * `role_user` + `permission_role` data migration against the live database.
         *
         * ONE COPY, ABOVE THE BRANCH SPLIT. `$role_r` now reaches both `addRole()` calls proven
         * non-null, so neither tolerates a null any more and there is no second copy of the
         * condition to drift out of step with the first -- which is how :179 and :310 came to
         * hold byte-identical tolerant guards.
         *
         * LATENT IN PRODUCTION, REACHABLE ON A BROKEN INSTALL. `PermissionTableSeeder` creates
         * the role, so a seeded install never takes this path. It is reachable where the seeder
         * has not run or the role was renamed, and the log line is what makes that visible to an
         * operator instead of showing up later as a merchant who cannot do anything.
         * Pinned by `tests/Feature/Auth/RegistrationRoleProvisioningTest`.
         */
        $role_r = Role::where('name', 'admin')->first();

        if (! $role_r) {
            \Illuminate\Support\Facades\Log::warning(
                'Registration refused: no Role named "admin" exists, so a merchant could only be '
                . 'created with no permissions. Nothing was provisioned. Run the permission '
                . 'seeder or restore the role.'
            );

            return redirect('/register')
                ->withInput($request->except(['password', 'password_confirmation', 'g-recaptcha-response']))
                ->with('status', __('Registration is unavailable right now because the default merchant role is missing. Please contact the site administrator.'));
        }

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

                /*
                 * P4-F032 -- THE WALLET, WITHOUT WHICH EVERY ORDER THIS MERCHANT EVER TAKES LOSES
                 * ITS CREDIT.
                 *
                 * `StoreController` has always provisioned one here; this door and
                 * `Api\DashboardController::createStoreApi` did not. A store created without a
                 * `store_wallets` row makes `OrderFinancialService::createForOrder()` throw into
                 * `OrderObserver::created()`'s catch, so the order commits, no
                 * `store_order_financials` row is written, and the credit is gone permanently --
                 * with nothing on any path to revisit it.
                 *
                 * Provisioning at creation makes deterministic a transition that already happens
                 * today non-deterministically: a wallet-less merchant's wallet is created the first
                 * time they open `/finance`.
                 */
                app(\App\Services\StoreWalletProvisioning::class)->ensure((int) $store->id);

                $user->current_store = $store->id;
                $user->save();
                // event(new Registered($user));
                $user->sendEmailVerificationNotification();
                Auth::login($user);
                defaultSetting($store->id, 'admin', $user);
                pageDefaultData($store->id);

                // GAP-014. THE TOLERANT `if ($role_r)` THAT USED TO BE HERE IS GONE, and the
                // lookup it guarded has moved to the top of this method, above every write.
                //
                // What stood here was a null guard with a Log::warning in its else-branch. It
                // stopped the TypeError that `addRole(null)` raises -- the parameter is typed
                // `array|string|int|Model|UuidInterface|BackedEnum` with no null in the union
                // (vendor/santigarcor/laratrust/src/Traits/HasRolesAndPermissions.php:375-378)
                // -- but by this line the User row, the Store row, the store wallet,
                // users.current_store, Auth::login() and defaultSetting() have all happened
                // outside a transaction, so tolerating the null committed a HALF-PROVISIONED
                // merchant and reported success. The gap register's instruction is that such a
                // row must not survive, so the absence is now refused before the first write
                // and `$role_r` arrives here proven non-null. See the block at the lookup.
                $user->addRole($role_r);
                if($user->type == 'admin')
                {
                    $plan = Plan::find($user->plan_id);
                    if($plan)
                    {
                        if($plan->duration != 'Unlimited')
                        {
                            /*
                             * SAAS-002. THE EXPIRY DATE IS THE LAST DAY OF SERVICE, and the expression that used
                             * to stand here treated it as the first day after. `$datetime2->diff($datetime1)
                             * ->format('%r%a')` is 0 on the expiry date, so `$days <= 0` was true and a merchant
                             * who logged in on a day they had paid for was downgraded to plan 1: expiry nulled,
                             * modules cut to plan 1's, and every store and staff seat past plan 1's limits
                             * switched off. Meanwhile `SaasRevenueFigures` counted that same row as `live` and the
                             * merchant's own plan screen did not say `Expired` -- three surfaces, two answers.
                             *
                             * One predicate now, for all three auth sites and the revenue classifier's own
                             * boundary. An empty `plan_expire_date` on a recurring plan still downgrades, exactly
                             * as `new \DateTime('')` made it: see the predicate's docblock for why that half is
                             * preserved rather than quietly changed. Driven by
                             * `tests/Feature/Saas/LoginExpiryBoundaryTest.php`.
                             */
                            if (\App\Support\Saas\SubscriptionLifecycle::recurringSubscriptionNeedsDowngrade($user))
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

            // P4-F032 -- the second registration branch creates a store too, and needs the same
            // wallet for the same reason. See the sibling call above.
            app(\App\Services\StoreWalletProvisioning::class)->ensure((int) $store->id);

            $user->current_store = $store->id;
            $user->save();
            Auth::login($user);
            defaultSetting($store->id, 'admin', $user);

            // GAP-014. THE TOLERANT `if ($role_r)` THAT USED TO BE HERE IS GONE, and the
            // lookup it guarded has moved to the top of this method, above every write.
            //
            // What stood here was a null guard with a Log::warning in its else-branch. It
            // stopped the TypeError that `addRole(null)` raises -- the parameter is typed
            // `array|string|int|Model|UuidInterface|BackedEnum` with no null in the union
            // (vendor/santigarcor/laratrust/src/Traits/HasRolesAndPermissions.php:375-378)
            // -- but by this line the User row, the Store row, the store wallet,
            // users.current_store, Auth::login() and defaultSetting() have all happened
            // outside a transaction, so tolerating the null committed a HALF-PROVISIONED
            // merchant and reported success. The gap register's instruction is that such a
            // row must not survive, so the absence is now refused before the first write
            // and `$role_r` arrives here proven non-null. See the block at the lookup.
            $user->addRole($role_r);
            if($user->type == 'admin')
            {
                $plan = Plan::find($user->plan_id);
                if($plan)
                {
                    if($plan->duration != 'Unlimited')
                    {
                        /*
                         * SAAS-002. THE EXPIRY DATE IS THE LAST DAY OF SERVICE, and the expression that used
                         * to stand here treated it as the first day after. `$datetime2->diff($datetime1)
                         * ->format('%r%a')` is 0 on the expiry date, so `$days <= 0` was true and a merchant
                         * who logged in on a day they had paid for was downgraded to plan 1: expiry nulled,
                         * modules cut to plan 1's, and every store and staff seat past plan 1's limits
                         * switched off. Meanwhile `SaasRevenueFigures` counted that same row as `live` and the
                         * merchant's own plan screen did not say `Expired` -- three surfaces, two answers.
                         *
                         * One predicate now, for all three auth sites and the revenue classifier's own
                         * boundary. An empty `plan_expire_date` on a recurring plan still downgrades, exactly
                         * as `new \DateTime('')` made it: see the predicate's docblock for why that half is
                         * preserved rather than quietly changed. Driven by
                         * `tests/Feature/Saas/LoginExpiryBoundaryTest.php`.
                         */
                        if (\App\Support\Saas\SubscriptionLifecycle::recurringSubscriptionNeedsDowngrade($user))
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
