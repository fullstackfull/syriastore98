<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Store;
use App\Models\User;
use App\Models\{Plan, PlanOrder};
use Illuminate\Support\Facades\Auth;
use App\Models\Utility;
use Illuminate\Support\Facades\Hash;
use App\Models\Role;
use App\Models\Page;
use App\Models\AppSetting;
use App\Models\Blog;
use App\Models\Order;
use App\Models\Tax;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Coupon;
use App\Models\Newsletter;
use App\Models\Testimonial;
use App\Models\Setting;
use App\Models\Shipping;
use App\Models\ProductVariant;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Lab404\Impersonate\Impersonate;
use Session;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Cache;
use App\DataTables\StoreDataTable;
use App\Models\StoreWallet;
use App\Models\CurrencyRate;
use App\Models\AuditLog;

class StoreController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        if (auth()->user() && auth()->user()->type == 'super admin') {
            $users = User::select(
                [
                    'users.*',
                ]
            )->join('stores', 'stores.created_by', '=', 'users.id')->where('users.created_by', \Auth::user()->creatorId())->where('users.type', '=', 'admin')->groupBy('users.id')->paginate(7);

            return view('store.index', compact('users'));
        } else {
            return redirect()->back()->with('error', __('Something went wrong'));
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        if (auth()->user() && auth()->user()->type == 'admin') {
            $themes = ['stylique', 'greentic', 'techzonix'];
            $plan = Plan::find(auth()->user()->plan_id);
            if (!empty($plan->themes)) {
                $themes = explode(',', $plan->themes);
            }
            return view('store.create', compact('themes', 'plan'));
        } elseif (auth()->user() && auth()->user()->type == 'super admin') {
            $setting = getSuperAdminAllSetting();

            return view('store.create', compact('setting'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function userCreate()
    {
        if (auth()->user() && auth()->user()->type == 'super admin') {
            $setting = getSuperAdminAllSetting();

            return view('store.user-create', compact('setting'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Store a newly created resource in storage.
     */
   public function store(Request $request)
{
    if (auth()->user() && auth()->user()->type == 'admin') {
        $validator = \Validator::make(
            $request->all(),
            [
                'storename' => 'required',
            ]
        );
    } else {
        $validator = \Validator::make(
            $request->all(),
            [
                'storename' => 'required',
                'name' => 'required',
                'email' => 'required|unique:users,email',
                'password' => 'required',
            ]
        );
    }

    if ($validator->fails()) {
        $messages = $validator->getMessageBag();

        return redirect()->back()->with('error', $messages->first());
    }

    /*
    |--------------------------------------------------------------------------
    | Admin creates a new store
    |--------------------------------------------------------------------------
    */
    if (auth()->user() && auth()->user()->type == 'admin') {

        $user = auth()->user();

        $total_store = $user->countStore();

        $plan = Plan::find($user->plan_id);

        if ($total_store < $plan->max_stores || $plan->max_stores == -1) {

            $slug = User::slugs($request->storename);

            $store = Store::create([
                'name' => $request->storename,
                'email' => $user->email,
                'theme_id' => $request->theme_id ?? 'stylique',
                'slug' => $slug,
                'default_language' => $user->default_language ?? 'en',
                'created_by' => $user->id,
            ]);

            /*
             * Create default store settings first
             */
            defaultSetting($store->id, 'admin', $user);

            /*
             * Force default currency to USD
             */
            $defaultCurrencySettings = [
                'defult_currancy' => 'USD',
                'defult_currancy_symbol' => '$',
                'CURRENCY_NAME' => 'USD',
                'CURRENCY' => '$',
            ];

            foreach ($defaultCurrencySettings as $key => $value) {

                Setting::updateOrCreate(
                    [
                        'name' => $key,
                        'store_id' => $store->id,
                    ],
                    [
                        'value' => $value,
                        'name' => $key,
                        'store_id' => $store->id,
                        'created_by' => $user->id,
                    ]
                );
            }

            /*
             * Create store wallet in USD
             */
            StoreWallet::firstOrCreate(
                [
                    'store_id' => $store->id,
                ],
                [
                    'currency_code' => 'USD',
                    'available_balance' => 0,
                    'pending_balance' => 0,
                    'withdrawal_pending_balance' => 0,
                    'total_income' => 0,
                    'total_withdrawn' => 0,
                ]
            );

$defaultCurrencies = [
    ['code' => 'USD', 'name' => 'US Dollar',          'symbol' => '$',   'rate' => 1],
    ['code' => 'EUR', 'name' => 'Euro',               'symbol' => '€',   'rate' => 1],
    ['code' => 'SYP', 'name' => 'Syrian Pound',       'symbol' => 'ل.س', 'rate' => 1],
    ['code' => 'LBP', 'name' => 'Lebanese Pound',     'symbol' => 'ل.ل', 'rate' => 1],
    ['code' => 'IQD', 'name' => 'Iraqi Dinar',        'symbol' => 'د.ع', 'rate' => 1],
    ['code' => 'EGP', 'name' => 'Egyptian Pound',     'symbol' => 'ج.م', 'rate' => 1],
    ['code' => 'JOD', 'name' => 'Jordanian Dinar',    'symbol' => 'د.أ', 'rate' => 1],
    ['code' => 'SAR', 'name' => 'Saudi Riyal',        'symbol' => 'ر.س', 'rate' => 1],
    ['code' => 'AED', 'name' => 'UAE Dirham',         'symbol' => 'د.إ', 'rate' => 1],
    ['code' => 'KWD', 'name' => 'Kuwaiti Dinar',      'symbol' => 'د.ك', 'rate' => 1],
    ['code' => 'QAR', 'name' => 'Qatari Riyal',       'symbol' => 'ر.ق', 'rate' => 1],
    ['code' => 'BHD', 'name' => 'Bahraini Dinar',     'symbol' => 'د.ب', 'rate' => 1],
    ['code' => 'OMR', 'name' => 'Omani Rial',         'symbol' => 'ر.ع', 'rate' => 1],
];

foreach ($defaultCurrencies as $currency) {

    CurrencyRate::updateOrCreate(
        [
            'store_id' => $store->id,
            'currency_code' => $currency['code'],
        ],
        [
            'currency_name' => $currency['name'],
            'symbol' => $currency['symbol'],
            'rate_per_usd' => $currency['rate'],
            'is_active' => 1,
            'created_by' => $user->id,
        ]
    );
}



            pageDefaultData($store->id);

            return redirect()->back()->with(
                'success',
                'Store successfully created.'
            );

        } else {

            return redirect()->back()->with(
                'error',
                __('Your Store limit is over, Please upgrade plan')
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Super Admin creates a new admin + store
    |--------------------------------------------------------------------------
    */
    else {

        if (auth()->user() && auth()->user()->type == 'super admin') {

            $superAdmin = Cache::remember(
                'super_admin_details',
                3600,
                function () {
                    return User::where('type', 'super admin')->first();
                }
            );

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'profile_image' => 'uploads/profile/avatar.png',
                'type' => 'admin',
                'email_verified_at' => date("Y-m-d H:i:s"),
                'password' => Hash::make($request->password),
                'mobile' => '7878787878',
                'register_type' => 'email',
                'language' => $superAdmin->default_language ?? 'en',
                'default_language' => $superAdmin->default_language ?? 'en',
                'plan_id' => '1',
                'created_by' => 1,
            ]);

            $slug = User::slugs($request->storename);

            $store = Store::create([
                'name' => $request->storename,
                'email' => $request->email,
                'theme_id' => $request->theme_id ?? 'stylique',
                'slug' => $slug,
                'default_language' => $user->default_language,
                'created_by' => $user->id,
            ]);

            /*
             * Set current store
             */
            $user->current_store = $store->id;
            $user->save();

            $user->assignPlan(1);

            /*
             * Create default store settings first
             */
            defaultSetting($store->id, 'admin', $user);

            /*
             * Force default currency to USD
             */
            $defaultCurrencySettings = [
                'defult_currancy' => 'USD',
                'defult_currancy_symbol' => '$',
                'CURRENCY_NAME' => 'USD',
                'CURRENCY' => '$',
            ];

            foreach ($defaultCurrencySettings as $key => $value) {

                Setting::updateOrCreate(
                    [
                        'name' => $key,
                        'store_id' => $store->id,
                    ],
                    [
                        'value' => $value,
                        'name' => $key,
                        'store_id' => $store->id,
                        'created_by' => $user->id,
                    ]
                );
            }

            /*
             * Create store wallet in USD
             */
            StoreWallet::firstOrCreate(
                [
                    'store_id' => $store->id,
                ],
                [
                    'currency_code' => 'USD',
                    'available_balance' => 0,
                    'pending_balance' => 0,
                    'withdrawal_pending_balance' => 0,
                    'total_income' => 0,
                    'total_withdrawn' => 0,
                ]
            );
$defaultCurrencies = [
    ['code' => 'USD', 'name' => 'US Dollar',          'symbol' => '$',   'rate' => 1],
    ['code' => 'EUR', 'name' => 'Euro',               'symbol' => '€',   'rate' => 1],
    ['code' => 'SYP', 'name' => 'Syrian Pound',       'symbol' => 'ل.س', 'rate' => 1],
    ['code' => 'LBP', 'name' => 'Lebanese Pound',     'symbol' => 'ل.ل', 'rate' => 1],
    ['code' => 'IQD', 'name' => 'Iraqi Dinar',        'symbol' => 'د.ع', 'rate' => 1],
    ['code' => 'EGP', 'name' => 'Egyptian Pound',     'symbol' => 'ج.م', 'rate' => 1],
    ['code' => 'JOD', 'name' => 'Jordanian Dinar',    'symbol' => 'د.أ', 'rate' => 1],
    ['code' => 'SAR', 'name' => 'Saudi Riyal',        'symbol' => 'ر.س', 'rate' => 1],
    ['code' => 'AED', 'name' => 'UAE Dirham',         'symbol' => 'د.إ', 'rate' => 1],
    ['code' => 'KWD', 'name' => 'Kuwaiti Dinar',      'symbol' => 'د.ك', 'rate' => 1],
    ['code' => 'QAR', 'name' => 'Qatari Riyal',       'symbol' => 'ر.ق', 'rate' => 1],
    ['code' => 'BHD', 'name' => 'Bahraini Dinar',     'symbol' => 'د.ب', 'rate' => 1],
    ['code' => 'OMR', 'name' => 'Omani Rial',         'symbol' => 'ر.ع', 'rate' => 1],
];

foreach ($defaultCurrencies as $currency) {

    CurrencyRate::updateOrCreate(
        [
            'store_id' => $store->id,
            'currency_code' => $currency['code'],
        ],
        [
            'currency_name' => $currency['name'],
            'symbol' => $currency['symbol'],
            'rate_per_usd' => $currency['rate'],
            'is_active' => 1,
            'created_by' => $user->id,
        ]
    );
}
            pageDefaultData($store->id);

            Utility::userDefaultData($user->id);

            $role_r = Role::where('name', 'admin')->first();

            $user->addRole($role_r);

            $setting = getSuperAdminAllSetting();

            if ($setting['email_verification'] == "on") {

                $user->email_verified_at = null;

                $user->save();

                config([
                    'mail.driver' => $setting['MAIL_DRIVER'] ?? '',
                    'mail.host' => $setting['MAIL_HOST'] ?? '',
                    'mail.port' => $setting['MAIL_PORT'] ?? '',
                    'mail.encryption' => $setting['MAIL_ENCRYPTION'] ?? '',
                    'mail.username' => $setting['MAIL_USERNAME'] ?? '',
                    'mail.password' => $setting['MAIL_PASSWORD'] ?? '',
                    'mail.from.address' => $setting['MAIL_FROM_ADDRESS'] ?? '',
                    'mail.from.name' => $setting['MAIL_FROM_NAME'] ?? '',
                ]);

                try {

                    // event(new Registered($user));

                    $user->sendEmailVerificationNotification();

                } catch (\Exception $e) {
                }
            }

            return redirect()->back()->with(
                'success',
                'User successfully created.'
            );

        } else {

            return redirect()->back()->with(
                'error',
                __('Permission denied.')
            );
        }
    }
}

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        if (auth()->user() && auth()->user()->type == 'super admin') {
            $user = User::find($id);
            $store = Store::where('created_by', $user->id)->first();
            $setting = getSuperAdminAllSetting();

            return view('store.edit', compact('store', 'user', 'setting'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {

        if (auth()->user() && auth()->user()->type == 'super admin') {
            $store = Store::find($id);
            $user = User::find($store->created_by);

            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => 'required|max:120',
                    'storename' => 'required|max:120',
                    'email' => 'required',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }
            $slug = User::slugs($request->storename);

            $store->name = $request->storename;
            $store->slug = $slug;
            $store->email = $request->email;
            $store->update();

            $user->name = $request->name;
            $user->email = $request->email;
            $user->update();

            return redirect()->back()->with('success', __('User Successfully Updated!'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {

        if (auth()->user() && auth()->user()->type == 'super admin') {
            $user = User::find($id);
            Setting::where('created_by', $user->id)->delete();
            $user->delete();
            return redirect()->back()->with('success', __('Store Successfully Deleted!'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }


    public function pwaSetting(Request $request, $id)
    {
        session()->put(['setting_tab' => 'pwa_setting']);

        // NEW-STORE-OWNERSHIP-01. Was `Store::find($id)` with no ownership test, so a merchant
        // could rewrite ANOTHER store's `enable_pwa_store` flag and overwrite that store's
        // published manifest.json with caller-supplied title, name and colours.
        //
        // The raw `$id` is ALSO interpolated straight into three filesystem paths further down
        // ('storage/uploads/customer_app/store_' . $id), so an id of the form `1/../../x` was a
        // path-traversal write. Every one of those sites now uses `$store->id`, an integer the
        // database returned, rather than the request string.
        $store = $this->resolveOwnedStore($id);

        if (!$store) {
            abort(403, __('You do not have permission to access this store.'));
        }

        $themeSettings = getThemeSetting($store->id, $store->theme_id);
        $favicon = $themeSettings['header_favicon'] ?? '';
        $theme_name = APP_THEME();
        
        $favicon = get_file($favicon);
        $store['enable_pwa_store'] = $request->pwa_store ?? 'off';
        if ($request->pwa_store == 'on') {
            $validator = \Validator::make(
                $request->all(),
                [
                    'pwa_app_title' => 'required|max:100',
                    'pwa_app_name' => 'required|max:50',
                    'pwa_app_background_color' => 'required|max:15',
                    'pwa_app_theme_color' => 'required|max:15',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
            $enable_storelink = Utility::GetValueByName('enable_storelink', $store->id);
            $enable_domain = Utility::GetValueByName('enable_domain', $store->id);
            $enable_subdomain = Utility::GetValueByName('enable_subdomain', $store->id);
            $domains = Utility::GetValueByName('domains', $store->id);
            $subdomain = Utility::GetValueByName('subdomain', $store->id);

            if ($enable_domain == 'on') {
                $start_url = 'https://' . $domains;
            } elseif ($enable_subdomain == 'on') {
                $start_url = 'https://' . $subdomain;
            } elseif ($enable_storelink) {
                $start_url = route('landing_page', $store->slug);
            } else {
                $start_url = route('landing_page', $store->slug);
            }

            list($width, $height) = getimagesize($favicon);
            $mainfest =
                '{
                    "lang": "' . $store['lang'] . '",
                    "name": "' . $request->pwa_app_title . '",
                    "short_name": "' . $request->pwa_app_name . '",
                    "start_url": "' . $start_url.'",
                    "display": "standalone",
                    "background_color": "' . $request->pwa_app_background_color . '",
                    "theme_color": "' . $request->pwa_app_theme_color . '",
                    "orientation": "portrait",
                    "categories": [
                        "shopping"
                    ],
                    "icons": [
                        {
                            "src": "' . $favicon . '",
                            "sizes": "128x128",
                            "type": "image/png",
                            "purpose": "any"
                        },
                        {
                            "src": "' . $favicon . '",
                            "sizes": "144x144",
                            "type": "image/png",
                            "purpose": "any"
                        },
                        {
                            "src": "' . $favicon . '",
                            "sizes": "152x152",
                            "type": "image/png",
                            "purpose": "any"
                        },
                        {
                            "src": "' . $favicon . '",
                            "sizes": "192x192",
                            "type": "image/png",
                            "purpose": "any"
                        },
                        {
                            "src": "' . $favicon . '",
                            "sizes": "256x256",
                            "type": "image/png",
                            "purpose": "any"
                        },
                        {
                            "src": "' . $favicon . '",
                            "sizes": "512x512",
                            "type": "image/png",
                            "purpose": "any"
                        },
                        {
                            "src": "' . $favicon . '",
                            "sizes": "1024x1024",
                            "type": "image/png",
                            "purpose": "any"
                        },
                        {
                            "src": "' . $favicon . '",
                            "sizes": "' . ($width ?? 30) . 'x' . ($height ?? 30) . '",
                            "type": "image/png",
                            "purpose": "any"
                        }
                    ]
                }';
            // `$store->id` (an integer from the database), NOT the raw request `$id` -- see the
            // path-traversal note at the top of this method.
            if (!file_exists('storage/uploads/customer_app/store_' . $store->id)) {
                mkdir(storage_path('uploads/customer_app/store_' . $store->id), 0777, true);
            }
            if (!file_exists('storage/uploads/customer_app/store_' . $store->id . '/manifest.json')) {
                fopen('storage/uploads/customer_app/store_' . $store->id . "/manifest.json", "w");
            }
            \File::put('storage/uploads/customer_app/store_' . $store->id . '/manifest.json', $mainfest);
        }
        $store->update();
        return redirect()->back()->with('success', __('PWA setting successfully Update.'));
    }

    /**
     * NEW-STORE-OWNERSHIP-01 -- the one ownership predicate for a caller-supplied store id.
     *
     * This is `changeStore()`'s R4-03 predicate, lifted into a method so the three methods
     * below cannot drift from it. `creatorId()` resolves a staff member to their owning admin
     * (User.php:95-105), which is what keeps staff and multi-store merchants working, and the
     * platform operator is admitted unconstrained because that is the contract every other
     * method in this controller already uses (index:44, edit:439, update:456, destroy:497,
     * list:978).
     *
     * Returns null when the id names no store OR names a store the caller does not own -- the
     * two cases are DELIBERATELY indistinguishable to the caller, so this is not an oracle for
     * which store ids exist.
     */
    private function resolveOwnedStore($id): ?Store
    {
        $user = auth()->user();

        if (!$user) {
            return null;
        }

        $query = Store::where('id', $id);

        // `strtolower(trim())` rather than a bare `!== 'super admin'`. Every other type
        // predicate written for this phase normalises first -- PlatformOperatorOnly::handle()
        // (app/Http/Middleware/PlatformOperatorOnly.php:61) and
        // App\Policies\PlanOrderPolicy::isPlatformAdmin() -- and the exact-case comparison here
        // was the odd one out. It fails in the SAFE direction (a 'Super admin' row would be
        // treated as a merchant and scoped by created_by, not waved through), so this is a
        // consistency repair and not a hole being closed; it matters because the same string is
        // compared eleven different ways across this codebase and the next person copying one of
        // them should copy the normalising form.
        if (strtolower(trim((string) $user->type)) !== 'super admin') {
            $query->where('created_by', $user->creatorId());
        }

        return $query->first();
    }

    public function changeStore($id)
    {
        $user = auth()->user();

        // R4-03: the tenant discriminator (users.current_store) is writable by the
        // caller, so this lookup MUST be constrained to stores the caller owns.
        // Without the predicate, pointing current_store at any store id nullified
        // every `where('store_id', getCurrentStore())` filter in the application.
        // creatorId() resolves staff members to their owning admin (User.php:95).
        $query = Store::where('id', $id);

        if ($user->type !== 'super admin') {
            $query->where('created_by', $user->creatorId());
        }

        $store = $query->first();

        if (!$store) {
            abort(403, __('You do not have permission to access this store.'));
        }

        // `==`, not `=`: the original assignment always evaluated truthy, so the
        // is_active guard was dead code and the error branch was unreachable.
        if ($store->is_active != 1) {
            return redirect()->back()->with('error', __('Store is locked'));
        }

        $user->current_store = $store->id;
        $user->update();

        return redirect()->back()->with('success', __('Store Change Successfully!'));
    }

    public function storeResetPassword($id)
    {
        // SEC-F1. Crypt::decrypt() on the route parameter is NOT an
        // authorization control: APP_KEY is in git history, so an encrypted
        // parameter is forgeable for any user id. Treating it as a control is
        // exactly what produced this bug -- and note the update sibling below
        // never even used the encrypted form, it took the raw id, which is what
        // resources/views/store/reset.blade.php:1 posts.
        $eId = \Crypt::decrypt($id);
        $user = User::findOrFail($eId);

        $this->authorize('resetPassword', $user);

        return view('store.reset', compact('user'));
    }

    public function storeResetPasswordUpdate(Request $request, $id)
    {

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

        // SEC-F1. This method had ZERO authorization: no permission check, no
        // ownership check, no authenticated-user check. Any caller could set the
        // password of any account on the platform, including the platform super
        // admin, and User.php casts 'password' => 'hashed' so the write produced
        // a WORKING credential.
        $user = User::findOrFail($id);

        $this->authorize('resetPassword', $user);

        $user->forceFill([
            'password' => Hash::make($request->password),
        ])->save();

        AuditLog::record('store_user_password_reset', $user);

        return redirect()->back()->with('success', 'User Password successfully updated.');
    }

    public function ownerstoredestroy($id)
    {

        $user = auth()->user();

        // NEW-STORE-OWNERSHIP-01, THE WORST OF THE THREE. This was `Store::find($id)` with NO
        // ownership test, while the `$user_stores > 1` guard below counts the CALLER's stores --
        // so the guard answered a question about the caller and the deletion then ran against a
        // store chosen by the caller. Any merchant holding two or more stores could
        // IRREVERSIBLY destroy ANY OTHER merchant's store: the block below deletes that store's
        // pages, orders, app settings, blogs, contacts, coupons, categories, newsletters, plan
        // orders, testimonials, settings, shippings, taxes, product variants and
        // shetabit_visits, runs ProductController::destroy over every product (which unlinks
        // uploaded media from disk), and finally deletes the store row. Nothing here is
        // recoverable and nothing here was authorized.
        //
        // The `$user_stores` count is left EXACTLY as it was, deliberately: it is the
        // pre-existing "you cannot delete your last store" guard, and rewriting it to
        // creatorId() would newly ALLOW a staff member to delete their employer's store. This
        // change only ever REMOVES capability.
        $store = $this->resolveOwnedStore($id);

        if (!$store) {
            abort(403, __('You do not have permission to access this store.'));
        }

        $user_stores = Store::where('created_by', $user->id)->count();

        if ($user_stores > 1) {
            Page::where('store_id', $store->id)->delete();
            Order::where('store_id', $store->id)->delete();
            AppSetting::where('store_id', $store->id)->delete();
            Blog::where('store_id', $store->id)->delete();
            Contact::where('store_id', $store->id)->delete();
            Coupon::where('store_id', $store->id)->delete();
            Category::where('store_id', $store->id)->delete();
            Newsletter::where('store_id', $store->id)->delete();
            PlanOrder::where('store_id', $store->id)->delete();
            Testimonial::where('store_id', $store->id)->delete();
            Setting::where('store_id', $store->id)->delete();
            Shipping::where('store_id', $store->id)->delete();
            Tax::where('store_id', $store->id)->delete();
            ProductVariant::where('store_id', $store->id)->delete();
            // Wishlist::where('store_id', $store->id)->delete();

            DB::table('shetabit_visits')->where('store_id', $store->id)->delete();

            $products = Product::where('store_id', $store->id)->get();
            $pro_img = new ProductController();
            foreach ($products as $pro) {
                $pro_img->destroy($pro);
            }
            // Product::where('store_id', $store->id)->delete();

            $store->delete();
            $userstore = Store::where('created_by', $user->id)->first();

            $user->current_store = $userstore->id;
            $user->save();

            return redirect()->route('dashboard');
        } else {
            return redirect()->back()->with('error', __('You have only one store'));
        }
    }

    /**
     * SEC-F6 -- IMPERSONATION, PLATFORM TAKEOVER. Previously the ONLY gate here
     * was `auth()->check()`: "is ANYONE logged in", not "may YOU do this". There
     * was no ownership check, no permission check, no type check and no Policy.
     *
     * The reachability chain was: public self-registration (routes/auth.php:18)
     * grants type=admin -> forge an encrypted id, because APP_KEY is in git
     * history -> GET this route -> a fully authenticated session as ANY account,
     * including the platform super admin.
     *
     * The authorize() call below is the control. The decrypt() is not, and must
     * never again be described as one. canImpersonate()/canBeImpersonated() are
     * additionally overridden on App\Models\User so that the vendor trait's
     * unverifiable defaults are not relied upon either.
     */
    public function LoginWithAdmin(Request $request, $id)
    {
        // findOrFail so a bad or forged id is a 404 rather than a silent
        // fall-through that returned no response at all.
        $user = User::findOrFail(decrypt($id));

        $this->authorize('impersonate', $user);

        Impersonate::take($request->user(), $user);

        return redirect('dashboard');
    }

    public function ExitAdmin(Request $request)
    {
        $actor = Auth::user();

        // Previously called leaveImpersonation() unconditionally, which raised a
        // 500 when no impersonation session was active.
        //
        // The trait's isImpersonated() is preferred, but vendor/lab404 is empty
        // in this environment so its presence could not be verified; the session
        // key the package writes is checked as the fallback. Either way this
        // fails CLOSED -- no active impersonation means no leaveImpersonation().
        $isImpersonating = method_exists($actor, 'isImpersonated')
            ? $actor->isImpersonated()
            : Session::has('impersonated_by');

        if (!$actor || !$isImpersonating) {
            return redirect('stores')->with('error', __('You are not impersonating anyone.'));
        }

        $actor->leaveImpersonation($request->user());

        return redirect('stores');
    }

    public function StoreLinks(Request $request, $id)
    {
        // NEW-STORELINKS-UNGATED-01. This method had no authorization at all. It is
        // the only one in this platform-operator family without a `type == 'super
        // admin'` test -- upgradePlan (:813), activePlan (:826), customDomain (:867),
        // subDomain, index (:44) and LoginWithAdmin (via
        // $this->authorize('impersonate')) all have one -- and it discloses every
        // store belonging to the named user.
        //
        // Exploitability was LIMITED, not absent, and the distinction is the reason
        // this is gated rather than ignored: the id is a Crypt payload, so it cannot
        // be forged without APP_KEY, and the only screens that mint one
        // (store/index.blade.php:94 and store/action.blade.php:32) are themselves
        // super-admin-only because StoreController::index refuses anyone else. But a
        // Crypt payload is a bearer capability -- it appears in a URL, so it travels
        // in browser history, proxy logs, Referer headers and anything pasted into a
        // ticket -- and any authenticated merchant holding one reached this screen.
        // APP_KEY is also committed to this repository and the owner has deferred
        // rotation (SEC-ENV-01), which removes the unforgeability argument entirely.
        //
        // Gated on the same literal the rest of the family uses, so the legitimate
        // workflow -- a platform operator clicking "store links" on the stores list
        // -- is unchanged. Both directions are pinned by
        // tests/Feature/Security/ImpersonationTest.
        if (!auth()->user() || auth()->user()->type !== 'super admin') {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        // findOrFail, not find: a valid Crypt payload for a deleted user id used to
        // reach `$user->id` on null and raise a 500.
        $user = User::findOrFail(decrypt($id));

        $stores = Store::where('created_by', $user->id)->get();

        return view('store.view-storelinks', compact('stores'));
    }

    public function upgradePlan($user_id)
    {
        if (auth()->user()->type == 'super admin') {
            $user = User::find(decrypt($user_id));
            $plans = Plan::get();

            return view('store.plan', compact('user', 'plans'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function activePlan($user_id, $plan_id)
    {
        if (auth()->user()->type == 'super admin') {

            $plan = Plan::find($plan_id);
            if ($plan->is_disable == 0) {
                return redirect()->back()->with('error', __('You are unable to upgrade this plan because it is disabled.'));
            }

            $user = User::find($user_id);
            $assignPlan = $user->assignPlan($plan->id);
            if ($assignPlan['is_success'] == true && !empty($plan)) {
                $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
                PlanOrder::create(
                    [
                        'order_id' => $orderID,
                        'name' => null,
                        'card_number' => null,
                        'card_exp_month' => null,
                        'card_exp_year' => null,
                        'plan_name' => $plan->name,
                        'plan_id' => $plan->id,
                        'price' => $plan->price,
                        'price_currency' => Utility::GetValueByName('CURRENCY_NAME'),
                        'txn_id' => '',
                        'payment_type' => __('Manually'),
                        'payment_status' => 'succeeded',
                        'receipt' => null,
                        'user_id' => $user->id,
                        'store_id' => getCurrentStore(),
                    ]
                );

                return redirect()->back()->with('success', __('Plan successfully upgraded.'));
            } else {
                return redirect()->back()->with('error', __('Plan fail to upgrade.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function customDomain()
    {
        if (auth()->user() && auth()->user()->type == 'super admin') {
            $serverName = str_replace(
                [
                    'http://',
                    'https://',
                ],
                '',
                env('APP_URL')
            );
            $serverIp = gethostbyname($serverName);

            if ($serverIp == $_SERVER['SERVER_ADDR']) {
                $serverIp;
            } else {
                $serverIp = request()->server('SERVER_ADDR');
            }
            $users = User::where('created_by', '=', auth()->user()->creatorId())->where('type', '=', 'admin')->get();
            $stores = Store::select('stores.*', 'settings.value as domains')->join('settings', 'settings.store_id', '=', 'stores.id')->where('settings.name', 'domains')->get();

            return view('store.custom_domain', compact('users', 'stores', 'serverIp'));
        } else {
            return redirect()->back()->with('error', __('permission Denied'));
        }
    }

    public function subDomain()
    {
        if (auth()->user() && auth()->user()->type == 'super admin') {
            $serverName = str_replace(
                [
                    'http://',
                    'https://',
                ],
                '',
                env('APP_URL')
            );
            $serverIp = gethostbyname($serverName);

            if ($serverIp != $serverName) {
                $serverIp;
            } else {
                $serverIp = request()->server('SERVER_ADDR');
            }
            $users = User::where('created_by', '=', auth()->user()->creatorId())->where('type', '=', 'admin')->get();
            $stores = Store::select('stores.*', 'settings.value as subdomain')->join('settings', 'settings.store_id', '=', 'stores.id')->where('settings.name', 'subdomain')->get();

            return view('store.subdomain', compact('users', 'stores', 'serverIp'));
        } else {
            return redirect()->back()->with('error', __('permission Denied'));
        }

    }

    public function changeStatus(Request $request)
    {
        // NEW-STORE-OWNERSHIP-01. Was `Store::find($request->id)` with no ownership test, then
        // `$store->is_active = $request->status; $store->save()` -- so ANY authenticated user
        // could take ANY store's storefront offline by posting a competitor's id. It also
        // dereferenced `$store->created_by` BEFORE its own `if ($store)` check two statements
        // later, so an unknown id was "Attempt to read property on null", not an error message.
        $store = $this->resolveOwnedStore($request->id);

        if (!$store) {
            return response()->json([
                'status' => 'error',
                'message' => __('You do not have permission to access this store.'),
            ], 403);
        }

        $adminId = $store->created_by;
        $activeStoresCount = Store::where('is_active', 1)->where('created_by', $adminId)->count();

        if ($activeStoresCount == 1 && $request->status == 0) {
            $return['status'] = 'error';
            $return['message'] = __('You cannot deactivate this store as it is the last active store.');
            return response()->json($return);
        }
     
        if ($store) {
            $store->is_active = $request->status;
            $store->save();
            $return['status'] = 'success';
            $return['message'] = __('Status change successfully.');
            return response()->json($return);
        } else {
            $return['status'] = 'error';
            $return['message'] = __('Something went wrong!!');
            return response()->json($return);
        }
    }

    public function list(Request $request, StoreDataTable $dataTable)
    {
        if (auth()->user() && auth()->user()->type == 'super admin') {
            return $dataTable->render('store.list');
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
}