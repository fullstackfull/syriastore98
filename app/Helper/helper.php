<?php
use App\Models\Themes\{ThemeSection, ThemeBestProductSection, ThemeArticelBlogSection, ThemeArticelBlogSectionDraft, ThemeTopProductSection, ThemeTopProductSectionDraft, ThemeModernProductSection, ThemeModernProductSectionDraft, ThemeBestProductSectionDraft, ThemeBestProductSecondSection, ThemeBestProductSecondSectionDraft, ThemeBestSellingSection, ThemeBestSellingSectionDraft, ThemeLogoSliderSection, ThemeLogoSliderSectionDraft, ThemeBestsellerSliderSection, ThemeBestsellerSliderSectionDraft, ThemeBestsellerSection, ThemeBestsellerSectionDraft, ThemeHeaderSection, ThemeSliderSection, ThemeCategorySection, ThemeReviewSection, ThemeSectionDraft, ThemeHeaderSectionDraft, ThemeSliderSectionDraft, ThemeCategorySectionDraft, ThemeReviewSectionDraft, ThemeSectionMap, ThemeBlogSection, ThemeBlogSectionDraft, ThemeDiscountSection, ThemeDiscountSectionDraft, ThemeProductCategorySection, ThemeProductCategorySectionDraft, ThemeFooterSection, ThemeFooterSectionDraft, ThemeProductSection, ThemeProductSectionDraft, ThemeSubscribeSection, ThemeSubscribeSectionDraft, ThemeVariantBackgroundSection, ThemeVariantBackgroundSectionDraft, ThemeProductBannerSliderSection, ThemeProductBannerSliderSectionDraft, ThemeNewestCateorySectionDraft, ThemeNewestCateorySection, ThemeServiceSection, ThemeServiceSectionDraft, ThemeVideoSection, ThemeVideoSectionDraft, ThemeNewestProductSection, ThemeNewestProductSectionDraft};
use App\Models\ProductAttributeOption;
use App\Models\Product;
use App\Models\Testimonial;
use App\Models\Page;
use App\Models\Category;
use App\Models\Setting;
use App\Models\User;
use App\Models\TaxOption;
use App\Models\Store;
use Illuminate\Support\Facades\Artisan;
use App\Models\Role;
use App\Models\Permission;
use App\Models\Utility;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use App\Models\{Currency, PixelFields};
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Addon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Hashids\Hashids;
use App\Facades\ModuleFacade as Module;
use App\Models\userActiveModule;
use Illuminate\Support\Facades\Session;
use App\Models\AddOnManager;
use App\Models\AppSetting;
use App\Models\Plan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Workdo\SidebarCustomization\app\Models\SideMenuOption;
use Workdo\SidebarCustomization\app\Models\SideMenuOrder;
use Yajra\DataTables\Html\Column;
use App\Models\ThemeCustomize;
use Illuminate\Support\Str;
use App\Models\Blog;

if (!function_exists('module_path')) {
    function module_path($name, $path = '')
    {
        return base_path('packages/Workdo/' . $name . '/' . $path);
    }
}

if (!function_exists('getMenu')) {
    function getMenu()
    {
        $user = auth()->user();
        $role = $user->type ?? null;
        $menu = new \App\Classes\Menu($user);
        if ($role && $role == 'super admin') {
            event(new \App\Events\SuperAdminMenuEvent($menu));
        } else {
            event(new \App\Events\CompanyMenuEvent($menu));
        }
        return generateMenu($menu->menu, null);
    }
}

if (!function_exists('menuTree')) {
    /**
     * The merchant / Super Admin navigation as DATA, for a view to render.
     *
     * This is the seam `docs/design-system/ADMIN-LAYOUT-ARCHITECTURE.md` §0
     * asks for, and it is the thing that was blocking every navigation item in
     * Phase 02: the sidebar was a hand-concatenated HTML string built in this
     * file, so no Blade view and no stylesheet could reach it. Only the
     * rendering moves. Nothing about WHO SEES WHAT changes.
     *
     * THE TWO GATES ARE NOT HERE, AND MUST NOT MOVE HERE. `App\Classes\Menu::add()`
     * filters on module entitlement (`ActivatedModule()`) AND Laratrust
     * `isAbleTo($item['permission'])` before an item ever reaches `$menuItems`.
     * That is a security property, not a convenience: an item that bypasses
     * `Menu::add()` bypasses both gates. This function receives what survived
     * them and re-shapes it; it decides nothing.
     *
     * Ordering, the `mobilescreensetting` exclusion and the `#!` fallback href
     * are reproduced exactly from the string builder this replaces.
     *
     * @param  array      $menuItems  the flat registry, post-gates
     * @param  mixed      $parent     parent `name`, or null for the top level
     * @return array<int, array{name:string,title:string,icon:?string,href:string,isTop:bool,children:array}>
     */
    function menuTree($menuItems, $parent = null)
    {
        $filteredItems = array_filter($menuItems, function ($item) use ($parent) {
            return $item['parent'] == $parent;
        });
        usort($filteredItems, function ($a, $b) {
            return $a['order'] - $b['order'];
        });

        $nodes = [];
        foreach ($filteredItems as $item) {
            if ($item['name'] == 'mobilescreensetting' && env('IS_MOBILE') != 'yes') {
                continue;
            }

            $nodes[] = [
                'name'     => $item['name'],
                'title'    => __($item['title']),
                'icon'     => $item['icon'] ?? null,
                // route() is called here exactly where the string builder
                // called it, so a missing route still throws in the same place
                // rather than failing later inside a view.
                'href'     => !empty($item['route']) ? route($item['route']) : '#!',
                'isTop'    => $item['parent'] == null,
                'children' => hasChildren($menuItems, $item['name'])
                    ? menuTree($menuItems, $item['name'])
                    : [],
            ];
        }

        return $nodes;
    }
}

if (!function_exists('generateMenu')) {
    /**
     * Unchanged signature, unchanged callers, unchanged DOM contract.
     *
     * `partision/sidebar.blade.php` still does `{!! getMenu() !!}`, and
     * getSideMenu() still falls back to this, so every call site is untouched.
     * What changed is that the markup now lives in a Blade partial a designer
     * can read, instead of in string concatenation in a helper.
     *
     * The emitted DOM stays SELECTOR-COMPATIBLE and that is load-bearing: the
     * inline sidebar search at layouts/app.blade.php:421-461 selects
     * `.dash-navbar > .dash-item` with a DIRECT-CHILD combinator, so wrapping
     * an item in any new element breaks search with no error at all. The
     * classes `dash-item`, `dash-hasmenu`, `dash-link`, `dash-mtext` and
     * `dash-submenu` are therefore fixed, as is the nesting depth.
     *
     * Parity is asserted by tests/Support/Security/bin/verify-menu-render-parity.php,
     * which parses this renderer's output and the old string builder's output
     * into DOM trees and compares them element by element.
     */
    function generateMenu($menuItems, $parent = null)
    {
        $nodes = menuTree($menuItems, $parent);

        /* P2-01-11: zones. Three conditions, each for its own reason.
         *
         * 1. TOP LEVEL ONLY. A submenu is already a group; `$parent !== null`
         *    means we are rendering one, so it stays a flat <li> list or the
         *    nesting depth the sidebar search depends on changes.
         *
         * 2. MERCHANTS ONLY. config/merchant-navigation.php maps the 21
         *    top-level entries CompanyMenuListener registers. The platform
         *    operator's sidebar comes from SuperAdminMenuListener and shares
         *    almost none of those names -- only `plan` and `settings` -- and
         *    its landing entry is `dashboard`, not `admin_dashboard`. Zoning
         *    it with the merchant map drew "Administration" (order 60) FIRST
         *    and pushed the operator's own Dashboard, Users, Plan Request,
         *    Country and Currency under a heading called "More" (order 900).
         *    Not a leak, but wrong on every page the operator opens. A
         *    super-admin map can be added later; until one exists the operator
         *    keeps the flat list, which is what they have today.
         *
         * 3. config() DEFENSIVELY, because getSideMenu() can run where the
         *    config repository is not bound. A missing config means the flat
         *    list -- the pre-P2-01-11 behaviour, and the same thing the kill
         *    switch produces.
         */
        $ssUser = function_exists('auth') ? auth()->user() : null;
        $ssIsOperator = $ssUser && class_exists(\App\Policies\UserPolicy::class)
            && \App\Policies\UserPolicy::isOperator($ssUser);

        $zoned = $parent === null
            && ! $ssIsOperator
            && function_exists('config')
            && (bool) config('merchant-navigation.zones_enabled', false);

        if ($zoned) {
            return view('partision.menu-zones', ['zones' => menuZones($nodes)])->render();
        }

        return view('partision.menu-tree', ['nodes' => $nodes])->render();
    }
}

if (!function_exists('menuZones')) {
    /**
     * Group already-built top-level nodes into the zones of
     * config/merchant-navigation.php. P2-01-11.
     *
     * DELIBERATELY SEPARATE FROM menuTree(). menuTree() and
     * partision/menu-tree.blade.php are pinned by
     * tests/Support/Security/bin/verify-menu-render-parity.php, which proves the
     * Blade renderer emits the same DOM as the string builder it replaced.
     * Folding grouping into menuTree() would make that proof compare a tree
     * against a differently-shaped tree, and the guarantee would quietly become
     * worthless. So this is a pure function over menuTree()'s OUTPUT: it
     * reorders and labels, it never builds a node and never drops one.
     *
     * IT GRANTS NOTHING. Every node here already passed Menu::add()'s module
     * and permission gates. An item whose gate refused it is not in $nodes, so
     * no arrangement of this function can surface it.
     *
     * A ZONE WITH NO ITEMS IS NOT RETURNED. The gates run per item, so a
     * narrowly-scoped employee can leave a whole zone empty -- and a heading
     * over nothing reads as a loading failure. Same reasoning as the empty
     * <ul> the arrow fix removed in P2-01-10.
     *
     * @param  array $nodes  top-level nodes from menuTree($items, null)
     * @return array<int, array{key:string,title:?string,nodes:array}>
     */
    function menuZones(array $nodes)
    {
        $config    = function_exists('config') ? (array) config('merchant-navigation', []) : [];
        $zoneDefs  = $config['zones'] ?? [];
        $ungrouped = $config['ungrouped'] ?? [];

        // name => zone key, built once rather than scanned per node.
        $zoneOf = [];
        foreach ($zoneDefs as $key => $def) {
            foreach ($def['items'] ?? [] as $name) {
                $zoneOf[$name] = $key;
            }
        }

        $lead    = [];
        $buckets = [];
        foreach ($nodes as $node) {
            if (in_array($node['name'], $ungrouped, true)) {
                $lead[] = $node;
                continue;
            }
            // An unmapped name lands in 'other' rather than vanishing.
            $key = $zoneOf[$node['name']] ?? 'other';
            $buckets[$key][] = $node;
        }

        // Zones in their declared order; an undeclared key sorts last.
        uksort($buckets, function ($a, $b) use ($zoneDefs) {
            $oa = $zoneDefs[$a]['order'] ?? PHP_INT_MAX;
            $ob = $zoneDefs[$b]['order'] ?? PHP_INT_MAX;
            return $oa === $ob ? strcmp($a, $b) : $oa - $ob;
        });

        $out = [];
        if ($lead) {
            // No title: the dashboard is where the merchant lands, not a
            // category of work. The partial renders no heading for a null.
            $out[] = ['key' => 'lead', 'title' => null, 'nodes' => $lead];
        }
        foreach ($buckets as $key => $bucket) {
            if (!$bucket) {
                continue;
            }
            $title = $zoneDefs[$key]['title'] ?? null;
            $out[] = [
                'key'   => $key,
                'title' => $title === null ? null : __($title),
                'nodes' => $bucket,
            ];
        }

        return $out;
    }
}

if (!function_exists('hasChildren')) {
    function hasChildren($menuItems, $name)
    {
        foreach ($menuItems as $item) {
            if ($item['parent'] === $name) {
                return true;
            }
        }
        return false;
    }
}


if (!function_exists('SetNumberFormat')) {
    function SetNumberFormat($number = 0)
    {
        $settings = getAdminAllSetting();
        $currency = $settings['defult_currancy_symbol'] ?? ($settings['CURRENCY'] ?? '$');
        $number_output = number_format($number, 2);
        return $currency . str_replace(',', '', $number_output);
    }
}

if (!function_exists('SetNumber')) {
    function SetNumber($number = 0)
    {
        if (is_string($number)) {
            preg_match('/\d+/', $number, $matches);
            $number = (float) $matches[0];
        }
        $number_output = number_format($number, 2);
        return str_replace(',', '', $number_output);
    }
}

if (!function_exists('get_file')) {
    function get_file($path)
    {
        $admin = User::where('type', 'super admin')->first();

        // NULL GUARD -- NEW-get-file-500-on-missing-super-admin.
        //
        // This read was `$admin->current_store` with no guard, so `get_file()` raised
        // "Attempt to read property \"current_store\" on null" and turned every page that
        // calls it into a 500 on any database that has no `type = 'super admin'` row. That is
        // not a test artefact: `users.type` is a plain string column, so a deployment whose
        // super admin was renamed, soft-deleted or never seeded hits it, and `get_file()` is
        // called from the shared layout partials, so the whole panel goes down at once.
        // Measured: 7 of the 13 Feature failures on p1b/integration @ 17cb2f91 were this one
        // line (AuthenticationTest, EmailVerificationTest, PasswordConfirmationTest,
        // PasswordResetTest x2, RegistrationTest, ProfileTest).
        //
        // An absent super admin means no remote-storage settings can be resolved, and the
        // correct fallback for that is local storage -- which is exactly what the `else` limb
        // below already does for an empty `$settings`. So the guard yields `[]` rather than
        // inventing a store id. When a super admin DOES exist the behaviour is byte-identical.
        $settings = $admin
            ? Setting::where('store_id', $admin->current_store)->pluck('value', 'name')->toArray()
            : [];

        if (empty($path)) {
            return $path;
        }

        try {
            // UNDEFINED-KEY GUARD -- NEW-get-file-blanks-every-url-without-storage-setting, the third
            // sibling of the two null guards above and MEASURED, not inferred
            // (tests/Feature/Security/StoredFileUrlResolutionTest.php).
            //
            // This read was `$settings['storage_setting']`, unguarded, behind nothing but
            // `count($settings) > 0`. Those are different questions: `$settings` is EVERY settings row of
            // the super admin's current store, so one row of any name satisfies the count while
            // `storage_setting` is absent. PHP 8 raises "Undefined array key", Laravel's error handler
            // promotes a warning to ErrorException, and this function's own `catch (\Throwable)` swallows
            // it and returns ''. So `get_file()` answers an EMPTY STRING for every path -- every logo,
            // avatar, product image and invoice mark in the panel at once -- instead of the local URL the
            // `else` limb below is there to produce. `storage_setting` is not NOT NULL anywhere and the
            // settings screen writes it only when storage is configured, so a store that never visited
            // that screen is in exactly this state.
            //
            // Byte-identical whenever the key exists; the fallback is local storage, which is what an
            // unset `storage_setting` means.
            $storageSetting = (string) ($settings['storage_setting'] ?? '');

            if ($storageSetting === 'wasabi') {
                config(
                    [
                        'filesystems.disks.wasabi.key' => $settings['wasabi_key'],
                        'filesystems.disks.wasabi.secret' => $settings['wasabi_secret'],
                        'filesystems.disks.wasabi.region' => $settings['wasabi_region'],
                        'filesystems.disks.wasabi.bucket' => $settings['wasabi_bucket'],
                        // 'filesystems.disks.wasabi.endpoint' => 'https://s3.' . $settings['wasabi_region'] . '.wasabisys.com'
                    ]
                );

                $bucket = $settings['wasabi_bucket'];
                $region = $settings['wasabi_region'];
                $endpoint =  'https://s3.' . $settings['wasabi_region'] . '.wasabisys.com';

                // Construct the correct URL manually
                return "{$endpoint}/{$bucket}/{$path}";
                // return \Storage::disk($settings['storage_setting'])->url($path);
            } elseif ($storageSetting === 's3') {
                config(
                    [
                        'filesystems.disks.s3.key' => $settings['s3_key'],
                        'filesystems.disks.s3.secret' => $settings['s3_secret'],
                        'filesystems.disks.s3.region' => $settings['s3_region'],
                        'filesystems.disks.s3.bucket' => $settings['s3_bucket'],
                        'filesystems.disks.s3.use_path_style_endpoint' => false,
                    ]
                );
                return \Storage::disk($settings['storage_setting'])->url($path);
            } else {
                $path = url($path);
                return $path;
            }
        } catch (\Throwable $th) {
            return '';
        }
    }
}

if (!function_exists('getEnableSetting')) {
    function getEnableSetting($remote)
    {
        // P1-SEC-29: only a row whose own store has the matching flag on counts (App\Services\HostClaims).
        $subdomain = \App\Services\HostClaims::row('subdomain', $remote);
        $domain = \App\Services\HostClaims::row('domains', $remote);

        return $subdomain ? $subdomain : $domain;
    }
}

if (!function_exists('isEnabled')) {
    function isEnabled($enableSetting)
    {
        $admin = User::find($enableSetting->created_by);

        // NULL GUARD -- NEW-is-enabled-500-on-orphaned-setting, the sibling of the defect in
        // get_file() above and found while fixing it. `User::find()` returns null for a
        // setting whose creator was deleted, and the `$admin->current_store` read then 500s
        // the domain/subdomain resolution for every request on that host.
        //
        // FAILS CLOSED on purpose: a custom domain whose owning user no longer exists cannot
        // be confirmed enabled, so the answer is "not enabled" rather than an exception. The
        // return value is unchanged in every case where `$admin` is non-null.
        if (! $admin) {
            return false;
        }

        return $enableSetting->value == 'on' && $enableSetting->store_id == $admin->current_store;
    }
}

if (!function_exists('getStoreIdFromUser')) {
    function getStoreIdFromUser($userId)
    {
        $user = User::find($userId);

        return $user ? $user->current_store : 1;
    }
}

if (!function_exists('getStoreIdFromSlugOrUser')) {
    function getStoreIdFromSlugOrUser($slug, $userId)
    {
        if ($slug) {
            return Store::where('slug', $slug)->value('id');
        }

        $userId = $userId ?? auth()->user()->id ?? 1;
        $user = User::find($userId);

        if (!$user) {
            return 1;
        }

        if ($user->type != 'admin' && $user->type != 'super admin') {
            $user = User::find($user->created_by);
        }

        return $user->current_store ?? Store::where('created_by', $user->id)->value('id') ?? 1;
    }
}

// setConfigEmail ( SMTP )
if (!function_exists('SetConfigEmail')) {
    function SetConfigEmail($request)
    {
        try {
            config(
                [
                    'mail.driver' => $request->mail_driver,
                    'mail.host' => $request->mail_host,
                    'mail.port' => $request->mail_port,
                    'mail.encryption' => $request->mail_encryption,
                    'mail.username' => $request->mail_username,
                    'mail.password' => $request->mail_password,
                    'mail.from.address' => $request->mail_from_address,
                    'mail.from.name' => $request->mail_from_name,
                ]
            );
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}

if (!function_exists('upload_theme_image')) {
    function upload_theme_image($theme_image, $key = 0, $user_id = 0)
    {
        $return['status'] = false;
        $return['image_url'] = '';
        $return['image_path'] = '';
        $return['message'] = __('Something went wrong.');

        if (!empty($theme_image)) {
            $theme_image = $theme_image;
            $result = Utility::updateStorageLimit($user_id ?? \Auth::user()->creatorId(), $theme_image->getSize());
            if ($result == 1) {
                $filenameWithExt = $theme_image->getClientOriginalName();
                $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                $extension = $theme_image->getClientOriginalExtension();
                $filedownloadable1 = $key . rand(0, 100) . date('Ymd') . '_' . time() . '.' . $extension;
                $dir = 'uploads/' . getCurrentStore() ;
                $save = Storage::disk('theme')->putFileAs(
                    $dir,  // upload path
                    $theme_image, // image name
                    $filedownloadable1  // image new name
                );
                $return['status'] = true;
                $return['image_url'] = url('uploads/' . $save);
                $return['image_path'] = $save;
                $return['message'] = __('Image upload succcessfully.');
            } else {
                $return['status'] = false;
                $return['image_url'] = '';
                $return['image_path'] = '';
                $return['message'] = __('Plan storage limit is over so please upgrade the plan.');
            }
        }
        return $return;
    }
}

if (!function_exists('getSuperAdminAllSetting')) {
    function getSuperAdminAllSetting()
    {
        // Laravel cache
        // return Cache::rememberForever('admin_settings',function () {
        $super_admin = Cache::remember('super_admin_details', 3600, function () {
            return User::where('type', 'super admin')->first();
        });

        $settings = [];
        if ($super_admin) {
            $settings =  Setting::where('created_by', $super_admin->id)->where('store_id', $super_admin->current_store)->pluck('value', 'name')->toArray();
        }
        return $settings;
        // });
    }
}

if (!function_exists('withoutPageExposedCredentials')) {
    /**
     * A settings bag without the one key that a vendored view prints into a public page.  P1-SEC-22 (c).
     *
     * TRACED, which is what that criterion asked for, and the answer is NOT "public, no change".
     *
     *   WHAT THE VALUE IS. `Workdo\Marketing`'s business-card screen has a "page password" field
     *   (store/index.blade.php:2014) whose write VALIDATES IT WITH `Rules\Password::defaults()`
     *   (StoreController.php:1726-1735) and stores it in `settings` under the name `password`. So it is a
     *   real, strength-checked password that a merchant chose -- and therefore one they may well reuse.
     *
     *   WHERE IT WENT. Two vendored views print it into a JavaScript variable:
     *   layouts/theme_script.blade.php:108 (`Appoinment::Marketing("password")`, which is
     *   `getAdminAllSetting()[$var]`) and card/theme2/index.blade.php:1374 (`$company_setting["password"]`,
     *   from StoreController:40). The browser then compares the typed value to that variable
     *   (theme_script.blade.php:127), so the "protection" was always readable in View Source.
     *
     *   WHO COULD SEE IT. `GET /cards/{slug}` is declared in a `Route::middleware(['web'])` group with NO
     *   `auth` and NO `PlanModuleCheck` (Marketing/src/routes/web.php:62-66) -- anonymous. And the print is
     *   inside `@if (!Auth::check())`, so it was shown to ANONYMOUS VISITORS ONLY and never to the merchant
     *   looking at their own card, which is why it went unnoticed.
     *
     * WHY THE FIX IS HERE AND IS THIS NARROW. The views are vendored and cannot be edited; this helper is
     * the host-side chokepoint both read sites pass through. The key is dropped BY EXACT NAME rather than by
     * the project's credential-name predicate, because `getAdminAllSetting()` is how `PaymentService` reads
     * gateway credentials at thirty call sites and guest checkout is anonymous -- a predicate here would
     * empty those and break the storefront, which this phase is told not to do. Measured: the only consumers
     * of a SETTINGS key named `password` are the six Marketing view lines above.
     *
     * WHAT CHANGES FOR A MERCHANT, disclosed: the card's password modal now compares against an empty
     * string, so an empty input passes it. No real protection is lost -- the value was in the page source,
     * so the modal was bypassable by anyone who looked -- but the modal is now bypassable without looking.
     * Whether that feature should exist at all, or be checked server-side, is a question about a vendored
     * module's behaviour and belongs with the owner. The merchant's own settings FORM renders an empty field
     * behind its `****` placeholder, and saving without retyping PRESERVES the stored value, because the
     * writer is guarded on `if ($request->password && $request->is_password_enabled)`.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    function withoutPageExposedCredentials(array $settings): array
    {
        unset($settings['password']);

        return $settings;
    }
}

if (!function_exists('getAdminAllSetting')) {
    function getAdminAllSetting($user_id = null, $store_id = null)
    {
        if (!empty($user_id)) {
            $user = Cache::remember("user_{$user_id}", 3600, function () use ($user_id) {
                return User::find($user_id);
            });
        } else {
            $user =  auth()->user();
        }
        // Check if the user is not 'company' or 'super admin' and find the creator
        if (isset($user->type) && !in_array($user->type, ['admin', 'super admin'])) {
            $user = User::find($user->created_by);
        }
        // P1-SEC-18 F4: with no user and no store named, the last branch below serves the LOWEST-ID MERCHANT ON THE PLATFORM
        // to whoever asked. A shopper's request is addressed to a store -- use it; null keeps the old behaviour.
        if (empty($user) && empty($store_id)) {
            $store_id = getRouteStoreId();
        }
        if (!empty($user)) {
            $store_id = $store_id ?? $user->current_store;
            $store = Store::find($store_id);
            $settings = [];
            $settings = Setting::where('created_by', $user->id)->where('store_id', $store_id)->pluck('value', 'name')->toArray();

            return withoutPageExposedCredentials($settings);
        } elseif (!empty($store_id)) {
            $settings = [];
            $settings = Setting::where('store_id', $store_id)->pluck('value', 'name')->toArray();
            return withoutPageExposedCredentials($settings);
        } else {
            $settings = [];
            $user = User::where('type', 'admin')->first();
            $settings = Setting::where('created_by', $user->id)->pluck('value', 'name')->toArray();
            return withoutPageExposedCredentials($settings);
        }
        return [];
    }
}

if (!function_exists('getCurrentStore')) {
    function getCurrentStore($user_id = null)
    {
        // Get the user
        if (!empty($user_id)) {
            $user = User::find($user_id);
        } else {
            $user = auth()->user();
        }

        if ($user) {
            // Check if user has a current store
            $storeId = $user->current_store;
            if (!empty($storeId)) {
                return $storeId;
            } else {
                // Handle super admin case
                if ($user->type == 'super admin') {
                    return 1; // Default store for super admin
                } else {
                    $store = Store::where('created_by', $user->id)->first();
                    return $store ? $store->id : null;
                }
            }
        }
    }
}

if (!function_exists('APP_THEME')) {
    function APP_THEME($user_id = null)
    {
        // Get the user
        if (!empty($user_id)) {
            $user = Cache::remember("user_{$user_id}", 3600, function () use ($user_id) {
                return User::find($user_id);
            });
        } else {
            $user = auth()->user();
        }

        if ($user) {
            // Check if user has a current store
            $storeId = $user->current_store;

            if (!empty($storeId)) {
                // Cache the store's theme ID to reduce calls
                $store = Store::find($storeId);

                if (!$store) {
                    if (auth()->check()) {
                        auth()->logout();
                    }

                    return redirect()->route('login')->with('message', 'You have been logged out.');
                }
                return $store->theme_id ?? null;
            } else {
                // Handle super admin case
                return 'stylique';
            }
        }
    }
}

if (!function_exists('currency')) {
    function currency($code = null)
    {
        if ($code == null) {
            $c = Currency::get();
        } else {
            $c = Currency::where('code', $code)->first();
        }
        return $c;
    }
}


if (!function_exists('getProductCategory')) {
    function getProductCategory($categoryId, $storeId, $themeId)
    {
        if (count($categoryId) > 0) {
            $categories = Category::with('product_details')->where('status', 1)->whereIn('id', $categoryId)->where('store_id', $storeId)->get();
        } else {
            $categories = Category::with('product_details')->where('status', 1)->where('store_id', $storeId)->get();
        }

        return $categories;
    }
}

if (!function_exists('GetCurrenctTheme')) {
    function GetCurrenctTheme($storeSlug)
    {

        if ($storeSlug == null) {
            $uri = url()->full();
            $segments = explode('/', str_replace('' . url('') . '', '', $uri));
            $segments = $segments[1] ?? null;

            $local = parse_url(config('app.url'))['host'];
            // Get the request host
            $remote = request()->getHost();
            // Get the remote domain
            // remove WWW
            $remote = str_replace('www.', '', $remote);
            $settingQuery = Setting::query();
            // P1-SEC-29: only a row whose own store has the matching flag on counts (App\Services\HostClaims).
            $subdomain = \App\Services\HostClaims::row('subdomain', $remote);
            $domain = \App\Services\HostClaims::row('domains', $remote);

            $enable_subdomain = "";
            $enable_domain = "";

            if ($subdomain || $domain) {
                if ($subdomain) {
                    $enable_subdomain = (clone $settingQuery)->where('name', 'enable_subdomain')->where('value', 'on')->where('store_id', $subdomain->store_id)->first();
                }

                if ($domain) {
                    $enable_domain = (clone $settingQuery)->where('name', 'enable_domain')->where('value', 'on')->where('store_id', $domain->store_id)->first();
                }
            }
            if ($enable_domain || $enable_subdomain) {
                $userQuery = User::query();
                if ($enable_subdomain) {
                    $admin = (clone $userQuery)->find($enable_subdomain->created_by);
                    if ($enable_subdomain->value == 'on' && $enable_subdomain->store_id == $admin->current_store) {
                        if (auth()->user() && getCurrentStore()) {
                            $store_id = getCurrentStore() ?? auth()->user()->current_store;
                        } else {
                            $store_id = $admin->current_store;
                        }
                    } else {
                        $store_id = $admin->current_store;
                    }
                } elseif ($enable_domain) {
                    $admin = (clone $userQuery)->find($enable_domain->created_by);
                    if ($enable_domain->value == 'on' && $enable_domain->store_id == $admin->current_store) {
                        if (auth()->user() && getCurrentStore()) {
                            $store_id = getCurrentStore() ?? auth()->user()->current_store;
                        } else {
                            $store_id = $admin->current_store;
                        }
                    } else {
                        $store_id = $admin->current_store;
                    }
                }
            }


            $store = getStoreById($store_id);
            $theme_id = $store->theme_id;
        } else {
            $store = getStore($storeSlug);
            $theme_id = $store->theme_id ?? '';
        }
        return $theme_id;
    }
}

if (!function_exists('getCurrenctStoreId')) {
    function getCurrenctStoreId($storeSlug)
    {
        if ($storeSlug == null) {
            $store_id = getCurrentStore();
            $store = getStoreById($store_id);
            $id = $store->id;
        } else {
            $store = getStore($storeSlug);
            $id = $store->id ?? '';
        }
        return $id;
    }
}

// Convert the array to an object recursively
if (!function_exists('arrayToObject')) {
    function arrayToObject($array)
    {
        if (is_array($array)) {
            $obj = new stdClass();
            foreach ($array as $key => $value) {
                $obj->$key = arrayToObject($value);
            }
            return $obj;
        } else {
            return $array;
        }
    }
}


if (!function_exists('SetDateFormat')) {
    function SetDateFormat($date = '')
    {
        $date_format = Utility::GetValueByName('date_format');
        if (empty($date_format)) {
            $date_format = 'Y-m-d';
        }
        if (empty($date)) {
            $date = date('Y-m-d');
        }
        try {
            $date_new = date($date_format, strtotime($date));
        } catch (\Throwable $th) {
            $date_new = $date;
        }
        return $date_new;
    }
}

if (!function_exists('pixelSourceCode')) {
    function pixelSourceCode($platform, $pixelId)
    {
        // Facebook Pixel script
        if ($platform === 'facebook') {
            $script = "
                <script>
                    !function(f,b,e,v,n,t,s)
                    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
                    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
                    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
                    n.queue=[];t=b.createElement(e);t.async=!0;
                    t.src=v;s=b.getElementsByTagName(e)[0];
                    s.parentNode.insertBefore(t,s)}(window, document,'script',
                    'https://connect.facebook.net/en_US/fbevents.js');
                    fbq('init', '%s');
                    fbq('track', 'PageView');
                </script>

                <noscript><img height='1' width='1' style='display:none' src='https://www.facebook.com/tr?id=%s&ev=PageView&noscript=1'/></noscript>
            ";

            return sprintf($script, $pixelId, $pixelId);
        }

        // Twitter Pixel script
        if ($platform === 'twitter') {
            $script = "
                <script>
                !function(e,t,n,s,u,a){e.twq||(s=e.twq=function(){s.exe?s.exe.apply(s,arguments):s.queue.push(arguments);
                },s.version='1.1',s.queue=[],u=t.createElement(n),u.async=!0,u.src='https://static.ads-twitter.com/uwt.js',
                a=t.getElementsByTagName(n)[0],a.parentNode.insertBefore(u,a))}(window,document,'script');
                twq('config','%s');
                </script>
            ";

            return sprintf($script, $pixelId);
        }

        // LinkedIn Pixel script
        if ($platform === 'linkedin') {
            // Sanitize the pixelId
            $pixelId = htmlspecialchars($pixelId, ENT_QUOTES, 'UTF-8');
            $script = "
                <script type='text/javascript'>
                    _linkedin_data_partner_id = \"%s\";
                </script>
                <script type='text/javascript'>
                    (function () {
                        var s = document.getElementsByTagName('script')[0];
                        var b = document.createElement('script');
                        b.type = 'text/javascript';
                        b.async = true;
                        b.src = 'https://snap.licdn.com/li.lms-analytics/insight.min.js';
                        s.parentNode.insertBefore(b, s);
                    })();
                </script>
                <noscript><img height='1' width='1' style='display:none;' alt='' src='https://dc.ads.linkedin.com/collect/?pid=%s&fmt=gif'/></noscript>
            ";

            return sprintf($script, $pixelId, $pixelId);
        }

        // Pinterest Pixel script
        if ($platform === 'pinterest') {
            $script = "
                <!-- Pinterest Tag -->
                <script>
                !function(e){if(!window.pintrk){window.pintrk = function () {
                window.pintrk.queue.push(Array.prototype.slice.call(arguments))};var
                n=window.pintrk;n.queue=[],n.version='3.0';var
                t=document.createElement('script');t.async=!0;t.src=e;var
                r=document.getElementsByTagName('script')[0];
                r.parentNode.insertBefore(t,r)}}('https://s.pinimg.com/ct/core.js');
                pintrk('load', '%s');
                pintrk('page');
                </script>
                <noscript>
                <img height='1' width='1' style='display:none;' alt=''
                src='https://ct.pinterest.com/v3/?event=init&tid=%s&noscript=1' />
                </noscript>
                <!-- end Pinterest Tag -->
            ";

            return sprintf($script, $pixelId, $pixelId);
        }

        // Quora Pixel script
        if ($platform === 'quora') {
            $script = "
                <script>
                !function (q, e, v, n, t, s) {
                    if (q.qp) return;
                    n = q.qp = function () {
                        n.qp ? n.qp.apply(n, arguments) : n.queue.push(arguments);
                    };
                    n.queue = [];
                    t = document.createElement(e);
                    t.async = !0;
                    t.src = v;
                    s = document.getElementsByTagName(e)[0];
                    s.parentNode.insertBefore(t, s);
                }(window, 'script', 'https://a.quora.com/qevents.js');
                qp('init', '%s');
                qp('track', 'ViewContent');
                </script>

                <noscript><img height='1' width='1' style='display:none' src='https://q.quora.com/_/ad/%s/pixel?tag=ViewContent&noscript=1'/></noscript>
            ";

            return sprintf($script, $pixelId, $pixelId);
        }

        // Bing Pixel script
        if ($platform === 'bing') {
            $script = "
                <script>
                (function(w,d,t,r,u){var f,n,i;w[u]=w[u]||[],f=function(){var o={ti:'%s'}; o.q=w[u],w[u]=new UET(o),w[u].push('pageLoad')},n=d.createElement(t),n.src=r,n.async=1,n.onload=n.onreadystatechange=function(){var s=this.readyState;s&&s!=='loaded'&&s!=='complete'||(f(),n.onload=n.onreadystatechange=null)},i=d.getElementsByTagName(t)[0],i.parentNode.insertBefore(n,i)})(window,document,'script','https://bat.bing.com/bat.js','uetq');
                </script>
                <noscript><img src='https://bat.bing.com/action/0?ti=%s&Ver=2' height='0' width='0' style='display:none; visibility:hidden;' /></noscript>
            ";

            return sprintf($script, $pixelId, $pixelId);
        }

        // Google AdWords Pixel script
        if ($platform === 'google-adwords') {
            $script = "
                <script type='text/javascript'>
                    var google_conversion_id = '%s';
                    var google_custom_params = window.google_tag_params;
                    var google_remarketing_only = true;
                </script>
                <script type='text/javascript' src='//www.googleadservices.com/pagead/conversion.js'></script>
                <noscript>
                <div style='display:inline;'>
                    <img height='1' width='1' style='border-style:none;' alt='' src='//googleads.g.doubleclick.net/pagead/viewthroughconversion/%s/?guid=ON&script=0'/>
                </div>
                </noscript>
            ";

            return sprintf($script, $pixelId, $pixelId);
        }

        // Google Analytics Pixel script
        if ($platform === 'google-analytics') {
            $script = "
                <script async src='https://www.googletagmanager.com/gtag/js?id=%s'></script>
                <script>
                    window.dataLayer = window.dataLayer || [];
                    function gtag(){dataLayer.push(arguments);}
                    gtag('js', new Date());
                    gtag('config', '%s');
                </script>
            ";

            return sprintf($script, $pixelId, $pixelId);
        }

        // Snapchat Pixel script
        if ($platform === 'snapchat') {
            $script = "
                <script type='text/javascript'>
                (function(e,t,n){if(e.snaptr)return;var a=e.snaptr=function()
                {a.handleRequest?a.handleRequest.apply(a,arguments):a.queue.push(arguments)};
                a.queue=[];var s='script';r=t.createElement(s);r.async=!0;
                r.src=n;var u=t.getElementsByTagName(s)[0];
                u.parentNode.insertBefore(r,u);})(window,document,
                'https://sc-static.net/scevent.min.js');

                snaptr('init', '%s', {
                'user_email': '__INSERT_USER_EMAIL__'
                });

                snaptr('track', 'PAGE_VIEW');
                </script>
            ";

            return sprintf($script, $pixelId);
        }

        // TikTok Pixel script
        if ($platform === 'tiktok') {
            $script = "
                <script>
                !function (w, d, t) {
                w.TiktokAnalyticsObject=t;
                var ttq=w[t]=w[t]||[];
                ttq.methods=['page','track','identify','instances','debug','on','off','once','ready','alias','group','enableCookie','disableCookie'],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};
                for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;
                n++)ttq.setAndDefer(e,ttq.methods[n]);
                return e},ttq.load=function(e,n){var i='https://analytics.tiktok.com/i18n/pixel/events.js';
                ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=i,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};
                var o=document.createElement('script');o.type='text/javascript',o.async=!0,o.src=i+'?sdkid='+e+'&lib='+t;
                var a=document.getElementsByTagName('script')[0];a.parentNode.insertBefore(o,a)};
                ttq.load('%s');
                ttq.page();
                }(window, document, 'ttq');
                </script>
            ";

            return sprintf($script, $pixelId);
        }

        return ''; // Return empty string if platform not matched
    }
}

if (!function_exists('metaKeywordSetting')) {
    function metaKeywordSetting($metakeyword = null, $metadesc = null, $metaimage = null, $slug = null)
    {
        $url = route('landing_page', $slug);
        $metakeyword = $metakeyword ?? config('app.name');
        $metadesc = $metadesc ?? config('app.name');
        $metaimage = $metaimage ?? config('app.name');
        $slug = $slug ?? config('app.name');
        $script = "
        <meta name='title' content='$metakeyword'>
        <meta name='description' content='$metadesc'>

        <meta property='og:type' content='website'>
        <meta property='og:url' content='$url'>
        <meta property='og:title' content=' $metakeyword'>
        <meta property='og:description' content='$metadesc'>
        <meta property='og:image' content=' $metaimage'>

        <meta property='twitter:card' content='summary_large_image'>
        <meta property='twitter:url' content='$url'>
        <meta property='twitter:title' content=' $metakeyword'>
        <meta property='twitter:description' content=' $metadesc'>
        <meta property='twitter:image' content=' $metaimage'>
        ";

        return sprintf($script);
    }
}

if (!function_exists('multi_upload_file')) {
    function multi_upload_file($request, $key_name, $name, $path, $custom_validation = [])
    {
        try {
            $storage_settings = getAdminAllSetting();
            if (isset($storage_settings['storage_setting'])) {
                if ($storage_settings['storage_setting'] == 'wasabi') {
                    config(
                        [
                            'filesystems.disks.wasabi.key' => $storage_settings['wasabi_key'],
                            'filesystems.disks.wasabi.secret' => $storage_settings['wasabi_secret'],
                            'filesystems.disks.wasabi.region' => $storage_settings['wasabi_region'],
                            'filesystems.disks.wasabi.bucket' => $storage_settings['wasabi_bucket'],
                            'filesystems.disks.wasabi.root' => $storage_settings['wasabi_root'],
                            'filesystems.disks.wasabi.endpoint' => $storage_settings['wasabi_url']
                        ]
                    );
                    $max_size = !empty($storage_settings['wasabi_max_upload_size']) ? $storage_settings['wasabi_max_upload_size'] : '2048';
                    $mimes =  !empty($storage_settings['wasabi_storage_validation']) ? $storage_settings['wasabi_storage_validation'] : 'jpeg,jpg,png,svg,zip,txt,gif,docx';
                } else if ($storage_settings['storage_setting'] == 's3') {
                    config(
                        [
                            'filesystems.disks.s3.key' => $storage_settings['s3_key'],
                            'filesystems.disks.s3.secret' => $storage_settings['s3_secret'],
                            'filesystems.disks.s3.region' => $storage_settings['s3_region'],
                            'filesystems.disks.s3.bucket' => $storage_settings['s3_bucket'],
                            // 'filesystems.disks.s3.url' => $storage_settings['s3_url'],
                            // 'filesystems.disks.s3.endpoint' => $storage_settings['s3_endpoint'],
                        ]
                    );
                    $max_size = !empty($storage_settings['s3_max_upload_size']) ? $storage_settings['s3_max_upload_size'] : '2048';
                    $mimes =  !empty($storage_settings['s3_storage_validation']) ? $storage_settings['s3_storage_validation'] : 'jpeg,jpg,png,svg,zip,txt,gif,docx';
                } else {
                    $max_size = !empty($storage_settings['local_storage_max_upload_size']) ? $storage_settings['local_storage_max_upload_size'] : '2048';
                    $mimes =  !empty($storage_settings['local_storage_validation']) ? $storage_settings['local_storage_validation'] : 'jpeg,jpg,png,svg,zip,txt,gif,docx';
                }
                $file = $request;
                $key_validation = $key_name . '*';
                if (count($custom_validation) > 0) {
                    $validation = $custom_validation;
                } else {
                    $validation = [
                        'mimes:' . $mimes,
                        'max:' . $max_size,
                    ];
                }
                $validator = Validator::make(array($key_name => $request), [
                    $key_validation => $validation
                ]);
                if ($validator->fails()) {
                    $res = [
                        'flag' => 0,
                        'msg' => $validator->messages()->first(),
                    ];
                    return $res;
                } else {
                    $name = $name;
                    $save = Storage::disk($storage_settings['storage_setting'])->putFileAs(
                        $path,
                        $file,
                        $name
                    );
                    if ($storage_settings['storage_setting'] == 'wasabi') {
                        $url = $save;
                    } elseif ($storage_settings['storage_setting'] == 's3') {
                        $url = $save;
                    } else {
                        $url = 'uploads/' . $save;
                    }
                    $res = [
                        'flag' => 1,
                        'msg'  => 'success',
                        'url'  => $url
                    ];
                    return $res;
                }
            } else {
                $res = [
                    'flag' => 0,
                    'msg' => 'not set configration',
                ];
                return $res;
            }
        } catch (\Exception $e) {
            $res = [
                'flag' => 0,
                'msg' => $e->getMessage(),
            ];
            return $res;
        }
    }
}

if (!function_exists('GetCurrency')) {
    function GetCurrency()
    {
        return Utility::GetValueByName('CURRENCY');
    }
}

if (!function_exists('getProductSlug')) {
    function getProductSlug($productId)
    {
        $product = Product::where('id', $productId)->select('id', 'name', 'slug')->first();

        return $product->slug ?? null;
    }
}
if (!function_exists('pageDefaultData')) {
    function pageDefaultData($store_id = null)
    {
        $defaultPages = [
            ['page_name' => 'Blog', 'page_slug' => 'blog'],
            ['page_name' => 'FAQs', 'page_slug' => 'faq'],
            ['page_name' => 'Order Tracking', 'page_slug' => 'track-order'],
            ['page_name' => 'About Us', 'page_slug' => 'about'],
            ['page_name' => 'Privacy Policy', 'page_slug' => 'privacy-policy'],
            ['page_name' => 'Product List Page', 'page_slug' => 'product-list'],
            ['page_name' => 'Category Collection', 'page_slug' => 'collections'],
            ['page_name' => 'Contact Us', 'page_slug' => 'contact-us'],
        ];

        foreach ($defaultPages as $page) {
           $page = Page::firstOrCreate(
                [
                    'page_slug' => $page['page_slug'],
                    'store_id' => $store_id,
                ],
                [
                    'page_name' => $page['page_name'],
                    'page_content' => '',
                    'page_status' => 1,
                    'is_default' => 1,
                ]
            );
        }
    }
}


if (!function_exists('defaultSetting')) {
    function defaultSetting($storeId, $type, $user)
    {
        $settingQuery = Setting::query();
        if ($type == 'super admin') {
            $settings = [
                "logo_dark" => "storage/uploads/logo/logo-dark.png",
                "logo_light" => "storage/uploads/logo/logo-light.png",
                "favicon" => "storage/uploads/logo/favicon.png",
                "title_text" => !empty(env('APP_NAME')) ? env('APP_NAME') : 'eCommerceGo SaaS',
                "footer_text" => "Copyright © " . (!empty(env('APP_NAME')) ? env('APP_NAME') : 'eCommerceGo SaaS'),
                "site_date_format" => "M j, Y",
                "site_time_format" => "g:i A",
                "SITE_RTL" => "off",
                "display_landing" => "on",
                "SIGNUP" => "on",
                "email_verification" => "off",
                "color" => "theme-3",
                "cust_theme_bg" => "on",
                "cust_darklayout" => "off",

                "storage_setting" => "local",
                "local_storage_validation" => "jpg,jpeg,png,csv,svg,pdf",
                "local_storage_max_upload_size" => "2048000",
                's3_key' => '',
                's3_secret' => '',
                's3_region' => '',
                's3_bucket' => '',
                's3_endpoint' => '',
                's3_max_upload_size' => '',
                's3_storage_validation' => '',
                'wasabi_key' => '',
                'wasabi_secret' => '',
                'wasabi_region' => '',
                'wasabi_bucket' => '',
                'wasabi_url' => '',
                'wasabi_root' => '',
                'wasabi_max_upload_size' => '',
                'wasabi_storage_validation' => '',

                "CURRENCY_NAME" => "USD",
                "CURRENCYCURRENCY" => "$",
                "currency_format" => "1",
                "defult_currancy" => "USD",
                "defult_language" => "en",
                "defult_timezone" => "Asia/Kolkata",

                // for cookie
                'enable_cookie' => 'on',
                'cookie_logging' => 'on',
                'necessary_cookies' => 'on',
                'cookie_title' => 'We use cookies!',
                'cookie_description' => 'Hi, this website uses essential cookies to ensure its proper operation and tracking cookies to understand how you interact with it',
                'strictly_cookie_title' => 'Strictly necessary cookies',
                'strictly_cookie_description' => 'These cookies are essential for the proper functioning of my website. Without these cookies, the website would not work properly',
                'more_information_description' => 'For any queries in relation to our policy on cookies and your choices, please contact us',
                "more_information_title" => "",
                'contactus_url' => '#',

            ];
        } else {
            $superAdmin = User::where('type', 'super admin')->first();
            if ($superAdmin) {
                $superAdminSetting = (clone $settingQuery)->where('created_by', $superAdmin->id)->pluck('value', 'name')->toArray();
                $logo_dark = $superAdminSetting['logo_dark'] ?? "storage/uploads/logo/logo-dark.png";
                $logo_light = $superAdminSetting['logo_light'] ?? "storage/uploads/logo/logo-light.png";
                $favicon = $superAdminSetting['favicon'] ?? "storage/uploads/logo/favicon.png";
                $title_text = $superAdminSetting['title_text'] ?? (!empty(env('APP_NAME')) ? env('APP_NAME') : 'eCommerceGo SaaS');
                $footer_text = $superAdminSetting['footer_text'] ?? ("Copyright © " . (!empty(env('APP_NAME')) ? env('APP_NAME') : 'eCommerceGo SaaS'));
                $site_date_format = $superAdminSetting['site_date_format'] ?? "M j, Y";
                $site_time_format = $superAdminSetting['site_time_format'] ?? "g:i A";
                $SITE_RTL = $superAdminSetting['SITE_RTL'] ?? "off";
                $color = $superAdminSetting['color'] ?? "theme-3";
                $cust_theme_bg = $superAdminSetting['cust_theme_bg'] ?? "on";
                $cust_darklayout = $superAdminSetting['cust_darklayout'] ?? "off";
                $CURRENCY_NAME = $superAdminSetting['CURRENCY_NAME'] ?? "USD";
                $CURRENCYCURRENCY = $superAdminSetting['CURRENCYCURRENCY'] ?? "$";
                $currency_format = $superAdminSetting['currency_format'] ?? "1";
                $defult_language = $superAdminSetting['defult_language'] ?? "en";
                $defult_currancy = $superAdminSetting['defult_currancy'] ?? "USD";
                $defult_timezone = $superAdminSetting['defult_timezone'] ?? "Asia/Kolkata";

                // $user->update(['language' => ($superAdmin->language ?? 'en')]);
            } else {
                $logo_dark = "storage/uploads/logo/logo-dark.png";
                $logo_light = "storage/uploads/logo/logo-light.png";
                $favicon = "storage/uploads/logo/favicon.png";
                $title_text = (!empty(env('APP_NAME')) ? env('APP_NAME') : 'eCommerceGo SaaS');
                $footer_text = ("Copyright © " . (!empty(env('APP_NAME')) ? env('APP_NAME') : 'eCommerceGo SaaS'));
                $site_date_format = "M j, Y";
                $site_time_format = "g:i A";
                $SITE_RTL = "off";
                $color = "theme-3";
                $cust_theme_bg = "on";
                $cust_darklayout = "off";
                $CURRENCY_NAME = "USD";
                $CURRENCYCURRENCY = "$";
                $currency_format = "1";
                $defult_language = "en";
                $defult_currancy = "USD";
                $defult_timezone = "Asia/Kolkata";
                //$user->update(['language' => 'en']);
            }
            $settings = [
                "logo_dark" => $logo_dark,
                "logo_light" => $logo_light,
                "favicon" => $favicon,
                "title_text" => $title_text,
                "footer_text" => $footer_text,
                "site_date_format" => $site_date_format,
                "site_time_format" => $site_time_format,
                "SITE_RTL" => $SITE_RTL,
                "display_landing" => "on",
                "SIGNUP" => "on",
                "email_verification" => "off",
                "color" => $color,
                "cust_theme_bg" => $cust_theme_bg,
                "cust_darklayout" => $cust_darklayout,

                "CURRENCY_NAME" => $CURRENCY_NAME,
                "CURRENCYCURRENCY" => $CURRENCYCURRENCY,
                "currency_format" => $currency_format,
                "defult_currancy" => $defult_currancy,
                "defult_language" => $defult_language,
                "defult_timezone" => $defult_timezone,

                // for cookie
                'enable_cookie' => 'on',
                'cookie_logging' => 'on',
                'necessary_cookies' => 'on',
                'cookie_title' => 'We use cookies!',
                'cookie_description' => 'Hi, this website uses essential cookies to ensure its proper operation and tracking cookies to understand how you interact with it',
                'strictly_cookie_title' => 'Strictly necessary cookies',
                'strictly_cookie_description' => 'These cookies are essential for the proper functioning of my website. Without these cookies, the website would not work properly',
                'more_information_description' => 'For any queries in relation to our policy on cookies and your choices, please contact us',
                "more_information_title" => "",
                'contactus_url' => '#',

            ];
        }


        foreach ($settings as $key => $value) {
            $exist = (clone $settingQuery)->where('name', $key)->where('store_id', $storeId)->first();
            if (!$exist) {
                (clone $settingQuery)->create([
                    'name' => $key,
                    'value' => (string) $value,
                    'store_id' => $storeId,
                    'created_by' => $user->id
                ]);
            }
        }
        Utility::WhatsappMeassage($user->id);
        pageDefaultData($storeId);
        return true;
    }
}


if (!function_exists('currency_format_with_sym')) {

    function currency_format_with_sym($price, $store_id = null, $productId = null)
    {
        if (!empty($store_id)) {
            $company_settings = getAdminAllSetting(null, $store_id);
        } else {
            $company_settings = getAdminAllSetting();
        }

        if (!empty($productId)) {
            $store = getStoreById($store_id);
            $product = Product::where('id', $productId)->first();
            if ($product && $store) {
                $price = Product::ProductPrice($store->slug, $product->id, $product->variant_id);
            }
        }

        $symbol_position = 'pre';
        $currancy_symbol = '$';
        $format = '1';
        $number = explode('.', $price);
        $length = strlen(trim($number[0]));

        if ($length > 3) {
            $decimal_separator = isset($company_settings['decimal_separator']) && $company_settings['decimal_separator'] === 'dot' ? '.' : ',';
            $thousand_separator = isset($company_settings['thousand_separator']) && $company_settings['thousand_separator'] === 'dot' ? '.' : ',';
        } else {
            $decimal_separator = isset($company_settings['decimal_separator']) && $company_settings['decimal_separator'] === 'dot' ? '.' : ',';

            $thousand_separator = isset($company_settings['thousand_separator'])  && $company_settings['thousand_separator'] === 'dot' ? '.' : ',';
        }
        if (isset($company_settings['site_currency_symbol_position'])) {
            $symbol_position = $company_settings['site_currency_symbol_position'];
        }
        if (isset($company_settings['defult_currancy_symbol'])) {
            $currancy_symbol = $company_settings['defult_currancy_symbol'];
        }
        if (isset($company_settings['currency_format'])) {
            $format = $company_settings['currency_format'];
        } else {
            $format = 2;
        }
        if (isset($company_settings['currency_space'])) {
            $currency_space = isset($company_settings['currency_space']) ? $company_settings['currency_space'] : '';
        }
        if (isset($company_settings['site_currency_symbol_name'])) {
            $defult_currancy = $company_settings['defult_currancy'];
            $defult_currancy_symbol = $company_settings['defult_currancy_symbol'];
            $currancy_symbol = $company_settings['site_currency_symbol_name'] == 'symbol' ? $defult_currancy_symbol : $defult_currancy;
        }
        return (
            ($symbol_position == "pre") ? $currancy_symbol : '') . ((isset($currency_space) && $currency_space) == 'withspace' ? ' ' : '')
            . number_format($price, $format, $decimal_separator, $thousand_separator) . ((isset($currency_space) && $currency_space) == 'withspace' ? ' ' : '') .
            (($symbol_position == "post") ? $currancy_symbol : '');
    }
}


// module alias name
if (!function_exists('Module_Alias_Name')) {
    function Module_Alias_Name($module_name)
    {
        static $addons = [];
        static $resultArray = [];
        if (count($addons) == 0 && count($resultArray) == 0) {
            $addons = AddOnManager::all()->toArray();
            $resultArray = array_reduce($addons, function ($carry, $item) {
                // Check if both "module" and "name" keys exist in the current item
                if (isset($item['module']) && isset($item['name'])) {
                    // Add a new key-value pair to the result array
                    $carry[$item['module']] = $item['name'];
                }
                return $carry;
            }, []);
        }

        $module = Module::find($module_name);
        if (isset($resultArray)) {
            $module_name = array_key_exists($module_name, $resultArray) ? $resultArray[$module_name] : (!empty($module) ? ($module->alias ?? $module->name) : $module_name);
        } elseif (!empty($module)) {
            $module_name = $module->alias ?? $module->name;
        }
        return $module_name;
    }
}

if (!function_exists('get_permission_by_module')) {
    function get_permission_by_module($module)
    {
        $user = auth()->user();

        if ($module == 'General') {
            $permissions = Permission::where('module', 'Base')->orderBy('name')->get();
        } else {
            $permissions = Permission::where('module', $module)->orderBy('name')->get();
        }
        return $permissions;
    }
}

if (!function_exists('sideMenuCacheForget')) {
    function sideMenuCacheForget($type = null, $user_id = null)
    {
        if ($type == 'all') {
            Cache::flush();
        }

        if (!empty($user_id)) {
            $user = User::find($user_id);
        } else {
            $user = auth()->user();
        }
        if ($user->type == 'admin') {
            $users = User::select('id')->where('created_by', $user->id)->pluck('id');
            foreach ($users as $id) {
                try {
                    $key = 'sidebar_menu_' . $id;
                    Cache::forget($key);
                } catch (\Exception $e) {
                    \Log::error('comapnySettingCacheForget :' . $e->getMessage());
                }
            }
            try {
                $key = 'sidebar_menu_' . $user->id;
                Cache::forget($key);
            } catch (\Exception $e) {
                \Log::error('comapnySettingCacheForget :' . $e->getMessage());
            }
            return true;
        }

        try {
            $key = 'sidebar_menu_' . $user->id;
            Cache::forget($key);
        } catch (\Exception $e) {
            \Log::error('comapnySettingCacheForget :' . $e->getMessage());
        }

        return true;
    }
}

if (!function_exists('module_is_active')) {
    function module_is_active($moduleName, $user_id = null)
    {
        // Check if the module exists and is enabled
        if (Module::has($moduleName)) {
            $module = Module::find($moduleName);


            if ($module->isEnabled()) {
                // Check for store slug in the route
                if (request()->route('storeSlug') || request()->route('slug')) {
                    $slug = request()->route('storeSlug') ?? request()->route('slug');

                    // Cache the store query to reduce calls
                    $store = getStore($slug);

                    if ($store) {
                        // Cache the user query by store's `created_by`
                        $user = Cache::remember("user_by_store_{$store->created_by}", 3600, function () use ($store) {
                            return User::where('id', $store->created_by)->first();
                        });

                        if ($user) {
                            // Cache the plan query for the user
                            $plan = Plan::where('id', $user->plan_id)->first();

                            if ($plan && !empty($plan->modules)) {
                                $modules = explode(',', $plan->modules);

                                if (in_array($module->name, $modules)) {
                                    return true;
                                }
                            }

                            return false;
                        }
                        return false;
                    } elseif ($module->name == 'LandingPage') {
                        return true;
                    } else {
                        return false;
                    }
                } else {
                    // If no slug is available, check for authenticated user or specific user_id
                    if (Auth::check()) {
                        $user = Auth::user();
                    } elseif ($user_id != null) {
                        $user = Cache::remember("user_{$user_id}", 3600, function () use ($user_id) {
                            return User::find($user_id);
                        });
                    }
                    if (!empty($user)) {
                        // Super admin can always access all modules
                        if ($user->type == 'super admin') {
                            return true;
                        } else {
                            $active_module = ActivatedModule($user->id);
                            if (count($active_module) > 0 && in_array($module->name, $active_module)) {
                                return true;
                            }
                            return false;
                        }
                    }
                    return false;
                }
            }
            return false;
        }
        return false;
    }
}

if (!function_exists('activated_modules_for_owner')) {
    /**
     * The module names a tenant OWNER has activated, memoised for the life of the
     * application instance.
     *
     * Container-scoped on purpose -- see the note at the call site in ActivatedModule().
     * `app()->bound()` rather than a null check, because an owner with no modules resolves
     * to [] and `[] == null` is true in PHP, so a null check would re-query on every call
     * for exactly the owners whose answer never changes.
     *
     * @return array<int, string>
     */
    function activated_modules_for_owner(int $ownerId): array
    {
        $key = "lynomia.activated_modules.owner.{$ownerId}";

        // scoped(), not instance(). Both memoise per owner, so neither can answer one
        // tenant's question with another's modules -- that was the bug this helper exists to
        // fix. They differ in how long the answer survives: an instance() binding lives as
        // long as the application object, which in a queue worker is many jobs, so an owner
        // whose plan lapsed mid-run would keep passing module_is_active() until the worker
        // restarted. A scoped() binding is cleared by forgetScopedInstances(), which the
        // queue fires between jobs, so the memo lasts exactly one unit of work. Under
        // PHP-FPM, where a request is the unit, the two are identical.
        //
        // bound() guards the REGISTRATION, not the value: after a scope flush the binding is
        // still bound while its resolved instance is gone, so app() re-runs the closure and
        // re-reads the table. Registering again would simply be wasted work.
        if (! app()->bound($key)) {
            app()->scoped(
                $key,
                fn () => userActiveModule::where('user_id', $ownerId)->pluck('module')->toArray()
            );
        }

        return (array) app($key);
    }
}

if (!function_exists('ActivatedModule')) {
    function ActivatedModule($user_id = null)
    {
        $activated_module = User::$superadmin_activated_module;

        $user_active_module = [];
        if ($user_id != null) {
            $user = Cache::remember("user_{$user_id}", 3600, function () use ($user_id) {
                return User::find($user_id);
            });
        } elseif (Auth::check()) {
            $user = Auth::user();
        }

        if (!empty($user)) {

            $available_modules = array_values(Module::getByStatus(1));

            // $available_modules =[];

            if ($user->type == 'super admin') {
                $user_active_module = $available_modules;
            } else {
                // THE MEMO IS KEYED BY OWNER. It used to be `static $active_module = null`,
                // keyed on nothing, so the FIRST non-empty module list resolved in a process
                // was reused for every later principal in that process -- one tenant's
                // entitlements answering another tenant's question.
                //
                // Under PHP-FPM each request is a fresh process, so it did not surface in
                // production. It surfaces anywhere the process outlives a request: a queue
                // worker, `artisan` running a loop, Octane, and a PHPUnit run. Measured:
                // LynomiaConnectionTest's entitlement-denial case passes alone and fails
                // after an entitled test, because the un-entitled merchant inherits the
                // entitled one's list and is let through a gate that should refuse them.
                //
                // Keyed by the resolved owner id, the memo still saves the repeated query it
                // was written for, and answers for the right principal.
                //
                // IT LIVES IN THE CONTAINER, NOT IN A FUNCTION STATIC. A `static` outlives
                // the application instance, and the id it is keyed on is not stable across
                // one: a test suite rebuilds the app and RefreshDatabase restarts the
                // auto-increment, so owner id 1 in one test is a different person from owner
                // id 1 in the next and would have read the first one's memo. The container is
                // rebuilt exactly when the identity space is -- once per request in
                // production, once per test here -- which is the correct lifetime.
                $active_module = null;

                if ($user->type != 'admin') {
                    $user_not_com = Cache::remember("user_{$user->created_by}", 3600, function () use ($user) {
                        return User::find($user->created_by);
                    });
                    // NEW-NULLGUARD-04. This guard tested `$user` -- which the enclosing
                    // `if (!empty($user))` has already proven non-empty -- while the very next
                    // line dereferences `$user_not_com`, a `User::find($user->created_by)` that
                    // IS null whenever a staff member's employer row is absent. So the guard was
                    // a no-op over the one variable that needed guarding. Pre-existing, not a
                    // regression: the same wrong variable is at the p1b/integration baseline. It
                    // was MASKED only because an earlier 500 short-circuited this path first.
                    if (!empty($user_not_com)) {
                        $active_module = activated_modules_for_owner((int) $user_not_com->id);
                    }
                } else {
                    $active_module = activated_modules_for_owner((int) $user->id);
                }

                // Find the common modules.
                //
                // `$active_module ?? []` is REQUIRED by the guard repair above, not cosmetic.
                // With the guard finally doing its job, a staff member whose employer row is
                // missing now leaves `$active_module` at its `null` initial value, and
                // `array_intersect(null, ...)` is a TypeError on PHP 8 -- so fixing the
                // dereference alone would merely have moved the 500 two lines down. The fallback
                // is written HERE, locally, rather than by assigning `$active_module = []`,
                // because `$active_module` is a `static` keyed on nothing: writing [] into it
                // would memoise "no modules" for every later principal in the same process.
                $commonModules = array_intersect($active_module ?? [], $available_modules);
                $user_active_module = array_unique(array_merge($commonModules, $activated_module));
            }
        }
        return count($user_active_module) > 0 ? $user_active_module : [];
    }
}

if (!function_exists('get_module_img')) {
    function get_module_img($module)
    {
        $url = url("/packages/workdo/" . $module . '/favicon.png');
        return $url;
    }
}

if (!function_exists('admin_setting')) {
    function admin_setting($key)
    {
        if ($key) {
            $admin_settings = getAdminAllSetting();
            $setting = (array_key_exists($key, $admin_settings)) ? $admin_settings[$key] : null;
            return $setting;
        }
    }
}

if (!function_exists('check_file')) {
    function check_file($path)
    {

        if (!empty($path)) {
            $storage_settings = getAdminAllSetting();
            if (isset($storage_settings['storage_setting']) && ($storage_settings['storage_setting'] == null || $storage_settings['storage_setting'] == 'local')) {

                return file_exists(base_path($path));
            } else {

                if (isset($storage_settings['storage_setting']) && $storage_settings['storage_setting'] == 's3') {
                    config(
                        [
                            'filesystems.disks.s3.key' => $storage_settings['s3_key'],
                            'filesystems.disks.s3.secret' => $storage_settings['s3_secret'],
                            'filesystems.disks.s3.region' => $storage_settings['s3_region'],
                            'filesystems.disks.s3.bucket' => $storage_settings['s3_bucket'],
                            'filesystems.disks.s3.url' => $storage_settings['s3_url'],
                            'filesystems.disks.s3.endpoint' => $storage_settings['s3_endpoint'],
                        ]
                    );
                } else if (isset($storage_settings['storage_setting']) && $storage_settings['storage_setting'] == 'wasabi') {
                    config(
                        [
                            'filesystems.disks.wasabi.key' => $storage_settings['wasabi_key'],
                            'filesystems.disks.wasabi.secret' => $storage_settings['wasabi_secret'],
                            'filesystems.disks.wasabi.region' => $storage_settings['wasabi_region'],
                            'filesystems.disks.wasabi.bucket' => $storage_settings['wasabi_bucket'],
                            'filesystems.disks.wasabi.root' => $storage_settings['wasabi_root'],
                            'filesystems.disks.wasabi.endpoint' => $storage_settings['wasabi_url']
                        ]
                    );
                }
                try {
                    return Storage::disk($storage_settings['storage_setting'])->exists($path);
                } catch (\Throwable $th) {
                    return 0;
                }
            }
        } else {
            return 0;
        }
    }
}


// module price name
if (!function_exists('ModulePriceByName')) {
    function ModulePriceByName($module_name)
    {
        static $addons = [];
        static $resultArray = [];
        if (count($addons) == 0 && count($resultArray) == 0) {
            $addons = AddOnManager::all()->toArray();
            $resultArray = array_reduce($addons, function ($carry, $item) {
                // Check if both "module" and "name" keys exist in the current item
                if (isset($item['module'])) {
                    // Add a new key-value pair to the result array
                    $carry[$item['module']]['monthly_price'] = $item['monthly_price'];
                    $carry[$item['module']]['yearly_price'] = $item['yearly_price'];
                }
                return $carry;
            }, []);
        }

        $module = Module::find($module_name);
        $data = [];
        $data['monthly_price'] = 0;
        $data['yearly_price'] = 0;

        if (!empty($module)) {
            $path = $module->getPath() . '/module.json';
            $json = json_decode(file_get_contents($path), true);

            $data['monthly_price'] = (isset($json['monthly_price']) && !empty($json['monthly_price'])) ? $json['monthly_price'] : 0;
            $data['yearly_price'] = (isset($json['yearly_price']) && !empty($json['yearly_price'])) ? $json['yearly_price'] : 0;
        }

        if (isset($resultArray)) {
            $data['monthly_price'] = isset($resultArray[$module_name]['monthly_price']) ? $resultArray[$module_name]['monthly_price'] : $data['monthly_price'];
            $data['yearly_price'] = isset($resultArray[$module_name]['yearly_price']) ? $resultArray[$module_name]['yearly_price'] : $data['yearly_price'];
        }

        return $data;
    }
}

if (!function_exists('getshowModuleList')) {
    function getshowModuleList($status = false)
    {
        $all = Module::getOrdered();
        $list = [];
        foreach ($all as $module) {
            if (!isset($module->display) || $module->display) {
                array_push($list, $module->name);
            }
        }

        if (!in_array('Finance', $list)) {
            array_push($list, 'Finance');
        }

        return $list;
    }
}
if (!function_exists('sidebar_logo')) {
    function sidebar_logo()
    {
        $admin_settings = getSuperAdminAllSetting();
        if (\Auth::check() && (\Auth::user()->type != 'super admin')) {
            $company_settings = getAdminAllSetting();

            if ((isset($company_settings['cust_darklayout']) ? $company_settings['cust_darklayout'] : 'off') == 'on') {
                if (!empty($company_settings['logo_light'])) {
                    if (check_file($company_settings['logo_light'])) {
                        return $company_settings['logo_light'];
                    } else {
                        return 'storage/uploads/logo/logo-light.png';
                    }
                } else {
                    if (!empty($admin_settings['logo_light'])) {
                        if (check_file($admin_settings['logo_light'])) {
                            return $admin_settings['logo_light'];
                        } else {
                            return 'storage/uploads/logo/logo-light.png';
                        }
                    } else {
                        return 'storage/uploads/logo/logo-light.png';
                    }
                }
            } else {
                if (!empty($company_settings['logo_dark'])) {
                    if (check_file($company_settings['logo_dark'])) {
                        return $company_settings['logo_dark'];
                    } else {
                        return 'uploads/logo/logo_dark.png';
                    }
                } else {
                    if (!empty($admin_settings['logo_dark'])) {
                        if (check_file($admin_settings['logo_dark'])) {
                            return $admin_settings['logo_dark'];
                        } else {
                            return 'uploads/logo/logo_dark.png';
                        }
                    } else {
                        return 'uploads/logo/logo_dark.png';
                    }
                }
            }
        } else {
            if ((isset($admin_settings['cust_darklayout']) ? $admin_settings['cust_darklayout'] : 'off') == 'on') {
                if (!empty($admin_settings['logo_light'])) {
                    if (check_file($admin_settings['logo_light'])) {
                        return $admin_settings['logo_light'];
                    } else {
                        return 'storage/uploads/logo/logo-light.png';
                    }
                } else {
                    return 'storage/uploads/logo/logo-light.png';
                }
            } else {
                if (!empty($admin_settings['logo_dark'])) {
                    if (check_file($admin_settings['logo_dark'])) {
                        return $admin_settings['logo_dark'];
                    } else {
                        return 'uploads/logo/logo_dark.png';
                    }
                } else {
                    return 'uploads/logo/logo_dark.png';
                }
            }
        }
    }
}

if (!function_exists('light_logo')) {
    function light_logo()
    {
        if (\Auth::check()) {
            $company_settings = getAdminAllSetting();
            $logo_light = isset($company_settings['logo_light']) ? $company_settings['logo_light'] : 'storage/uploads/logo/logo-light.png';
        } else {
            $admin_settings = getSuperAdminAllSetting();
            $logo_light = isset($admin_settings['logo_light']) ? $admin_settings['logo_light'] : 'storage/uploads/logo/logo-light.png';
        }
        if (check_file($logo_light)) {
            return $logo_light;
        } else {
            return 'uploads/logo/logo_dark.png';
        }
    }
}

if (!function_exists('dark_logo')) {
    function dark_logo()
    {
        if (\Auth::check()) {
            $company_settings = getCompanyAllSetting();
            $logo_dark = isset($company_settings['logo_dark']) ? $company_settings['logo_dark'] : 'uploads/logo/logo_dark.png';
        } else {
            $admin_settings = getSuperAdminAllSetting();
            $logo_dark = isset($admin_settings['logo_dark']) ? $admin_settings['logo_dark'] : 'uploads/logo/logo_dark.png';
        }
        if (check_file($logo_dark)) {
            return $logo_dark;
        } else {
            return 'uploads/logo/logo_dark.png';
        }
    }
}

if (!function_exists('delete_file')) {
    function delete_file($path)
    {
        if (check_file($path)) {
            $storage_settings = getAdminAllSetting();
            if (isset($storage_settings['storage_setting'])) {
                if ($storage_settings['storage_setting'] == 'local') {
                    return \App\Services\StoredUpload::delete(is_string($path) ? $path : null);   // P1-SEC-28
                } else {
                    if ($storage_settings['storage_setting'] == 's3') {
                        config(
                            [
                                'filesystems.disks.s3.key' => $storage_settings['s3_key'],
                                'filesystems.disks.s3.secret' => $storage_settings['s3_secret'],
                                'filesystems.disks.s3.region' => $storage_settings['s3_region'],
                                'filesystems.disks.s3.bucket' => $storage_settings['s3_bucket'],
                                'filesystems.disks.s3.url' => $storage_settings['s3_url'],
                                'filesystems.disks.s3.endpoint' => $storage_settings['s3_endpoint'],
                            ]
                        );
                    } else if ($storage_settings['storage_setting'] == 'wasabi') { {
                            config(
                                [
                                    'filesystems.disks.wasabi.key' => $storage_settings['wasabi_key'],
                                    'filesystems.disks.wasabi.secret' => $storage_settings['wasabi_secret'],
                                    'filesystems.disks.wasabi.region' => $storage_settings['wasabi_region'],
                                    'filesystems.disks.wasabi.bucket' => $storage_settings['wasabi_bucket'],
                                    'filesystems.disks.wasabi.root' => $storage_settings['wasabi_root'],
                                    'filesystems.disks.wasabi.endpoint' => $storage_settings['wasabi_url']
                                ]
                            );
                        }
                        return Storage::disk($storage_settings['storage_setting'])->delete($path);
                    }
                }
            }
        }
    }
}

if (!function_exists('get_size')) {
    function get_size($url)
    {
        $url = str_replace(' ', '%20', $url);
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, TRUE);
        curl_setopt($ch, CURLOPT_NOBODY, TRUE);

        $data = curl_exec($ch);
        $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);

        curl_close($ch);
        return $size;
    }
}

if (!function_exists('delete_folder')) {
    function delete_folder($path)
    {
        $storage_settings = getAdminAllSetting();
        if (isset($storage_settings['storage_setting'])) {

            if ($storage_settings['storage_setting'] == 'local') {
                if (is_dir(Storage::path($path))) {
                    return \File::deleteDirectory(Storage::path($path));
                }
            } else {
                if ($storage_settings['storage_setting'] == 's3') {
                    config(
                        [
                            'filesystems.disks.s3.key' => $storage_settings['s3_key'],
                            'filesystems.disks.s3.secret' => $storage_settings['s3_secret'],
                            'filesystems.disks.s3.region' => $storage_settings['s3_region'],
                            'filesystems.disks.s3.bucket' => $storage_settings['s3_bucket'],
                            'filesystems.disks.s3.url' => $storage_settings['s3_url'],
                            'filesystems.disks.s3.endpoint' => $storage_settings['s3_endpoint'],
                        ]
                    );
                } else if ($storage_settings['storage_setting'] == 'wasabi') {
                    config(
                        [
                            'filesystems.disks.wasabi.key' => $storage_settings['wasabi_key'],
                            'filesystems.disks.wasabi.secret' => $storage_settings['wasabi_secret'],
                            'filesystems.disks.wasabi.region' => $storage_settings['wasabi_region'],
                            'filesystems.disks.wasabi.bucket' => $storage_settings['wasabi_bucket'],
                            'filesystems.disks.wasabi.root' => $storage_settings['wasabi_root'],
                            'filesystems.disks.wasabi.endpoint' => $storage_settings['wasabi_url']
                        ]
                    );
                }
                return Storage::disk($storage_settings['storage_setting'])->deleteDirectory($path);
            }
        }
    }
}

if (!function_exists('delete_directory')) {
    function delete_directory($dir)
    {
        if (!file_exists($dir)) {
            return true;
        }

        if (!is_dir($dir)) {
            return unlink($dir);
        }

        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }

            if (!delete_directory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }

        return rmdir($dir);
    }
}

if (!function_exists('getModuleList')) {
    function getModuleList()
    {
        $all = Module::getOrdered();
        $list = [];
        foreach ($all as $module) {
            array_push($list, $module->name);
        }
        return $list;
    }
}

if (!function_exists('default_currency_format_with_sym')) {

    function default_currency_format_with_sym($price, $store_id = null)
    {
        if (!empty($store_id)) {
            $company_settings = getSuperAdminAllSetting(null, $store_id);
        } else {
            $company_settings = getSuperAdminAllSetting();
        }
        $symbol_position = 'pre';
        $currancy_symbol = '$';
        $format = '1';
        $number = explode('.', $price);
        $length = strlen(trim($number[0]));
        if ($length > 3) {
            $decimal_separator = isset($company_settings['decimal_separator']) && $company_settings['decimal_separator'] === 'dot' ? '.' : ',';
            $thousand_separator = isset($company_settings['thousand_separator']) && $company_settings['thousand_separator'] === 'dot' ? '.' : ',';
        } else {
            $decimal_separator = isset($company_settings['decimal_separator']) == 'dot' ? '.' : ',';

            $thousand_separator = isset($company_settings['thousand_separator']) == 'dot' ? '.' : ',';
        }
        if (isset($company_settings['site_currency_symbol_position'])) {
            $symbol_position = $company_settings['site_currency_symbol_position'];
        }
        if (isset($company_settings['defult_currancy_symbol'])) {
            $currancy_symbol = $company_settings['defult_currancy_symbol'];
        }
        if (isset($company_settings['currency_format'])) {
            $format = $company_settings['currency_format'];
        } else {
            $format = 2;
        }
        if (isset($company_settings['currency_space'])) {
            $currency_space = isset($company_settings['currency_space']) ? $company_settings['currency_space'] : '';
        }
        if (isset($company_settings['site_currency_symbol_name'])) {
            $defult_currancy = $company_settings['defult_currancy'];
            $defult_currancy_symbol = $company_settings['defult_currancy_symbol'];
            $currancy_symbol = $company_settings['site_currency_symbol_name'] == 'symbol' ? $defult_currancy_symbol : $defult_currancy;
        }

        return (
            ($symbol_position == "pre") ? $currancy_symbol : '') . ((isset($currency_space) && $currency_space) == 'withspace' ? ' ' : '')
            . number_format($price, $format, $decimal_separator, $thousand_separator) . ((isset($currency_space) && $currency_space) == 'withspace' ? ' ' : '') .
            (($symbol_position == "post") ? $currancy_symbol : '');
    }
}

if (!function_exists('getProductActualPrice')) {
    function getProductActualPrice($product, $variant = null)
    {
        $store = getStoreById(getCurrentStore());
        $price = Product::ProductPrice($store->slug, $product->id, ($variant->id ?? ($product->variant_id ?? 0)), $product->price);
        $salePrice =  Product::ProductPrice($store->slug, $product->id, ($variant->id ?? ($product->variant_id ?? 0)), $product->sale_price);

        if ($price && $salePrice) {
            return ($price > $salePrice) ? $salePrice : $price;
        }

        return $price ?: $salePrice;
    }
}

if (!function_exists('order_currency_format_with_sym')) {
    function order_currency_format_with_sym($price, $order_id)
    {
        $order = \App\Models\Order::find($order_id);

        if (empty($order)) {
            return $price; // fallback آمن لو الطلب مو موجود
        }

        $store_id = $order->store_id ?? getCurrentStore();
        $company_settings = getAdminAllSetting(null, $store_id);

        $symbol_position = 'pre';
        $format = '1';

        // ✅ الرمز الافتراضي البدائي (fallback لو ما لقينا شي تاني)
        $currancy_symbol = '$';

        $number = explode('.', (string) $price);
        $length = strlen(trim($number[0] ?? '0'));

        // نفس منطق الدالة الأصلية بالضبط (كلا الفرعين متطابقين، محافظين على نفس البنية)
        if ($length > 3) {
            $decimal_separator = isset($company_settings['decimal_separator']) && $company_settings['decimal_separator'] === 'dot' ? '.' : ',';
            $thousand_separator = isset($company_settings['thousand_separator']) && $company_settings['thousand_separator'] === 'dot' ? '.' : ',';
        } else {
            $decimal_separator = isset($company_settings['decimal_separator']) && $company_settings['decimal_separator'] === 'dot' ? '.' : ',';
            $thousand_separator = isset($company_settings['thousand_separator']) && $company_settings['thousand_separator'] === 'dot' ? '.' : ',';
        }

        if (isset($company_settings['site_currency_symbol_position'])) {
            $symbol_position = $company_settings['site_currency_symbol_position'];
        }

        // ✅ التكييف الإجباري: الرمز الافتراضي أول شي نجيبه من إعداد المتجر الحالي (زي الأصلية)...
        if (isset($company_settings['defult_currancy_symbol'])) {
            $currancy_symbol = $company_settings['defult_currancy_symbol'];
        }

        if (isset($company_settings['currency_format'])) {
            $format = $company_settings['currency_format'];
        } else {
            $format = 2;
        }

        $currency_space = '';
        if (isset($company_settings['currency_space'])) {
            $currency_space = $company_settings['currency_space'];
        }

        // ✅ نفس منطق "رمز أو اسم" بالأصلية، بس معتمدين على قيم المتجر الحالية لتحديد "أي واحد يُعرض" (رمز أو اسم)
        // بينما "شو هي العملة نفسها" رح تصير محسومة بالخطوة اللي بعدها من الطلب مباشرة
        $use_symbol_not_name = true; // افتراضي: نعرض الرمز
        if (isset($company_settings['site_currency_symbol_name'])) {
            $use_symbol_not_name = ($company_settings['site_currency_symbol_name'] == 'symbol');
        }

        // ✅✅ التكييف الجوهري: إذا الطلب عنده Snapshot محفوظ لعملته (currency_symbol / currency_code)،
        // بنفضّله دايمًا على إعداد المتجر الحالي — هاد يلي بيحمي الطلبات المكتملة من التغيّر
        if (!empty($order->currency_symbol) || !empty($order->currency_code)) {
            $currancy_symbol = $use_symbol_not_name
                ? ($order->currency_symbol ?? $currancy_symbol)
                : ($order->currency_code ?? $currancy_symbol);
        } elseif (isset($company_settings['site_currency_symbol_name'])) {
            // ما في Snapshot بالطلب (حالة نادرة/قديمة) → نرجع لسلوك الدالة الأصلية بالكامل
            $defult_currancy = $company_settings['defult_currancy'] ?? '';
            $defult_currancy_symbol = $company_settings['defult_currancy_symbol'] ?? '$';
            $currancy_symbol = $use_symbol_not_name ? $defult_currancy_symbol : $defult_currancy;
        }

        return (
            ($symbol_position == "pre") ? $currancy_symbol : '') . (($currency_space === 'withspace') ? ' ' : '')
            . number_format((float) $price, (int) $format, $decimal_separator, $thousand_separator) . (($currency_space === 'withspace') ? ' ' : '') .
            (($symbol_position == "post") ? $currancy_symbol : '');
    }
}
if (!function_exists('bulkDeleteCloneCheckboxColumn')) {
    function bulkDeleteCloneCheckboxColumn()
    {
        // Initialize an array to hold the columns
        $columns = null;

        // Check if the BulkDelete module is active
        if (module_is_active('BulkDelete')) {
            // Add the checkbox column if the module is active
            return Column::computed('checkbox')
                ->title('<input type="checkbox" id="select-all">')
                ->orderable(false)
                ->exportable(false)
                ->printable(false)
                ->addClass('text-left')
                ->render('function() {
                    return \'<input type="checkbox" class="select-row" value="\' + this.id + \'">\';
                }');
        }

        return $columns; // Return the array of columns
    }
}

if (!function_exists('bulkDeleteForm')) {
    function bulkDeleteForm($type, $dataTableId)
    {
        return [
            'text' => '<i class="ti ti-trash" data-bs-toggle="tooltip" title="' . __("Bulk Delete") . '" data-bs-original-title="' . __("Bulk Delete") . '"></i>',
            'className' => 'btn btn-light-danger bulk-delete',
            'action' => "function(e, dt, button, config) {
                bulkDelete('{$type}','{$dataTableId}');
            }"
        ];
    }
}

if (!function_exists('getSideMenu')) {
    function getSideMenu()
    {
        $user = auth()->user();
        $menu = new \App\Classes\Menu($user);

        /*
         * ONE OPERATOR TEST FOR BOTH DECISIONS. This line was `$user->type == 'super admin'`
         * while the gate below went through `UserPolicy::isOperator()`, so a row typed
         * 'Super Admin' took the MERCHANT row set and then had the gate waved for it -- the two
         * comparisons disagreeing about who the operator is, which is the pattern P1-SEC-11
         * exists to end. Found by the independent review. Narrowing both to the helper is the
         * safe direction: the helper is what every other gate in this phase asks.
         */
        $isOperator = \App\Policies\UserPolicy::isOperator($user);

        if ($isOperator) {
            if (module_is_active('SidebarCustomization') && Schema::hasTable('side_menus_option')) {
                $sortedModules = SideMenuOption::where('type', 'super admin')->orderBy('order')->get();
                if ($sortedModules->isEmpty()) {
                    event(new \App\Events\SuperAdminMenuEvent($menu));
                    return generateMenu($menu->menu, null);
                }
            }else{
                event(new \App\Events\SuperAdminMenuEvent($menu));
                return generateMenu($menu->menu, null);
            }
        } else {
            $sideMenuItems = collect();
            if (module_is_active('SidebarCustomization') && Schema::hasTable('side_menus_option')) {
                $sideMenuItems = SideMenuOption::where('type', 'admin')->where('created_by', 0)->where('store_id', null)->orderBy('order')->get();
                $MenuItems = SideMenuOption::where('type', 'admin')->where('created_by', $user->id)->where('store_id', $user->current_store)->orderBy('order')->get();

                if (!$MenuItems->isEmpty()) {
                    $sideMenuItems = $sideMenuItems->merge($MenuItems)->sortBy('order');
                }
                $customOrder = SideMenuOrder::whereIn('menu_id', $sideMenuItems->pluck('id'))
                    ->where('created_by', $user->id)
                    ->where('store_id', $user->current_store)
                    ->get();

                $orderMap = $customOrder->pluck('order', 'menu_id')->toArray();
                $sortedModules = $sideMenuItems->sortBy(function ($module) use ($orderMap) {
                    return isset($orderMap[$module->id]) ? $orderMap[$module->id] : $module->order;
                });

            }

            if ($sideMenuItems->isEmpty()) {
                event(new \App\Events\CompanyMenuEvent($menu));
                return generateMenu($menu->menu, null);
            }
        }
        /*
         * P2-SEC-F4. THE GATE THIS PATH NEVER HAD.
         *
         * `$menu->menu[]` below bypasses `App\Classes\Menu::add()`, which is the only place
         * module entitlement and permission are checked for the navigation, and the two keys the
         * row carries to say what it needs -- `module` and `permission` -- are structurally
         * always null, because `side_menus_option` HAS NEITHER COLUMN. See the `Menu::$denied`
         * docblock for that measurement. So the platform-authored rows (`created_by = 0`,
         * `store_id = null`, selected at :2270) rendered for EVERY merchant and every staff
         * member, whatever they hold and whatever their plan includes.
         *
         * The gate is recovered from the listeners rather than from a schema change. They are the
         * authoritative `name => [module, permission]` map, `Menu::add()` already applies the
         * decision, and `Menu::$denied` now records the refusals -- so a database row naming one
         * of those entries honours the same answer. A row naming something the listeners do not
         * know (a merchant's own external link) has no permission to apply and is governed by the
         * route-existence check in `generateSideMenu()` instead; that is a real limit of this
         * approach and is stated in the ledger rather than papered over.
         *
         * THE OPERATOR LIMB IS FIRST, which is this codebase's rule (the skill's §1, and
         * FinancePermission records the reverse ordering causing a lockout). It is also what
         * makes this change zero-regression for the principal who OWNS this screen: the operator
         * configures the custom sidebar, holds no merchant role, and a permission with no row
         * can be held by nobody -- so enforcing against them is a lockout, not a tightening. The
         * finding's stated harm is "renders for every merchant", and merchants and staff are
         * where this now bites.
         */
        /*
         * BUILT ONLY FOR THE PRINCIPAL THAT CONSULTS IT. The operator short-circuits the gate
         * below, so firing the event for them paid for every module listener's
         * `module_is_active()` and `isAbleTo()` calls to produce a map nothing reads -- and the
         * operator is the principal most likely to be on this code path. Found by the
         * independent review.
         */
        $gate = null;

        if (! $isOperator) {
            $gate = new \App\Classes\Menu($user);
            event(new \App\Events\CompanyMenuEvent($gate));
        }

        foreach ($sortedModules as $item) {
            if (isset($item->is_enable) && $item->is_enable == 'on') {
                $row = [
                    'id' => $item->id,
                    'category' => $item->category,
                    'title' => $item->title,
                    'icon' => $item->icon,
                    'name' => $item->name,
                    'parent' => $item->parent_id,
                    'order' => isset($orderMap[$item->id]) ? $orderMap[$item->id] : $item->order,
                    'link_type' => $item->link_type ?? null,
                    'route' => $item->link,
                    'module' => $item->module ?? null,
                    'window_type' => $item->window_type ?? null,
                    'is_enable' => $item->is_enable ?? null,
                    'permission' => $item->permission ?? null,
                ];

                if ($gate !== null && ! sideMenuRowIsPermitted($row, $gate)) {
                    continue;
                }

                $menu->menu[] = $row;
            }
        }
        return generateSideMenu($menu->menu, null);
    }
}

if (!function_exists('sideMenuRowIsPermitted')) {
    /**
     * P2-SEC-F4. Is this `side_menus_option` row one the caller is entitled to see?
     *
     * TWO INDEPENDENT QUESTIONS, because the row can answer only one of them today:
     *
     * 1. THE ROW'S OWN COLUMNS, applied when a deployment actually has them. Today it does not
     *    -- neither the migration nor the production dump defines `module` or `permission` -- so
     *    this limb is inert and exists so that adding the columns later makes the row's own
     *    declaration authoritative without another change here. `Menu::permits()` is reused
     *    rather than re-spelled.
     *
     * 2. THE LISTENER'S DECISION FOR THAT NAME, which is what actually gates anything today.
     *    `$gate->denied` holds the names the listeners offered and the gate refused for THIS
     *    user -- a module their plan omits, or a permission their role lacks. A row naming one
     *    of them is refused here for the same reason.
     *
     * A name the listeners never offered is NOT refused: there is no permission on record for it
     * and inventing one would hide a merchant's own custom links. `generateSideMenu()`'s
     * route-existence check covers the module half of that case, since an inactive module
     * registers no routes.
     */
    function sideMenuRowIsPermitted(array $row, \App\Classes\Menu $gate): bool
    {
        // Limb 1 -- only ask when the row actually declares something.
        if (! empty($row['module']) || ! empty($row['permission'])) {
            if (! $gate->permits($row)) {
                return false;
            }
        }

        // Limb 2 -- the listeners' answer for this name.
        if (! empty($row['name']) && isset($gate->denied[$row['name']])) {
            return false;
        }

        return true;
    }
}
if (!function_exists('sideMenuIconSlug')) {
    /**
     * P2-SEC-F5. `icon` is a database-sourced string interpolated INSIDE a class attribute
     * (`class="ti ti-<icon>"`), so escaping alone is the weaker control: an allowlist is exact
     * here because the value is a Tabler icon slug and nothing else. Every icon in the restored
     * production dump and in both seeders matches `[a-z0-9-]+` (`home`, `layout-2`,
     * `message-circle`, `user`, `settings`), so this changes no shipping row.
     */
    function sideMenuIconSlug($icon): string
    {
        return preg_replace('/[^A-Za-z0-9-]/', '', (string) $icon) ?? '';
    }
}

if (!function_exists('sideMenuHref')) {
    /**
     * P2-SEC-F4 / F5. Resolve a `side_menus_option` row's href, or NULL to drop the row.
     *
     * NULL means "this row names something that is not there", which is how a path whose table
     * has no `module` column still enforces module entitlement: an add-on registers its routes
     * only when `add_on_managers` holds its row, so `Route::has()` is a true reading of whether
     * the module is active. Dropping the row is also strictly safer than the old behaviour,
     * which called `route()` on the unregistered name and threw from inside the sidebar.
     *
     * The returned string is escaped by the CALLER with `e()`; what is checked here is the
     * SCHEME, which escaping does not address -- `javascript:alert(1)` survives
     * htmlspecialchars intact and still runs on click. `link_type = 'external'` is the one
     * branch whose value is a free-text URL, and the module's own form offers `http://` and
     * `https://` only (SideMenuOption::$hrefs), so anything else is outside what the UI can
     * produce.
     */
    function sideMenuHref(array $item): ?string
    {
        if (! isset($item['link_type'])) {
            return '#!';
        }

        switch ($item['link_type']) {
            case 'internal':
                // Unchanged, including the vendored oddity that the ternary's condition is
                // always true because the concatenation always contains '/'. `url()` returns an
                // application URL, so there is no scheme to validate.
                return !empty((env('APP_URL')) . '/' . $item['route']) ? url((env('APP_URL')) . '/' . $item['route']) : '#';

            case 'external':
                if (! isset($item['route'])) {
                    return '#';
                }

                $routeData = json_decode($item['route'], true);

                if (! isset($routeData['href'][0]) || ! isset($routeData['type'][0])) {
                    return '#';
                }

                $candidate = (string) $routeData['href'][0] . (string) $routeData['type'][0];

                return sideMenuSafeExternalUrl($candidate);

            case 'hash':
                // `link` is not a key this array carries -- `getSideMenu()` maps the column to
                // 'route' -- so this has always resolved to '#'. Left exactly as it was: making
                // it read 'route' would turn a dead branch into a live one, which is a behaviour
                // change this security fix has no business making.
                return '#' . (isset($item['link']) ? $item['link'] : '');

            case 'route':
                if (empty($item['route'])) {
                    return '#';
                }

                if (! \Illuminate\Support\Facades\Route::has($item['route'])) {
                    return null;
                }

                return route($item['route']);
        }

        return '#!';
    }
}

if (!function_exists('sideMenuSafeExternalUrl')) {
    /**
     * P2-SEC-F5. An http(s) URL, or '#'.
     *
     * Deliberately an ALLOWLIST of two schemes rather than a denylist of `javascript:` and
     * `data:`, because a denylist loses to whitespace and entity tricks
     * (`java\tscript:`, `JaVaScRiPt:`) that browsers still honour. A protocol-relative `//host`
     * is also refused: it is not something the module's form can produce, and it silently
     * inherits the page's scheme.
     */
    function sideMenuSafeExternalUrl(string $candidate): string
    {
        $scheme = strtolower((string) parse_url(trim($candidate), PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $candidate : '#';
    }
}

if (!function_exists('generateSideMenu')) {
    function generateSideMenu($menuItems, $parent = null, $level = 0)
    {
        $html = '';
        $filteredItems = array_filter($menuItems, function ($item) use ($parent) {
            return $item['parent'] == $parent;
        });
        usort($filteredItems, function ($a, $b) {
            return $a['order'] - $b['order'];
        });
        foreach ($filteredItems as $item) {
            if (isset($item['is_enable']) && $item['is_enable'] == 'on') {
                if ($item['name'] == 'mobilescreensetting' && env('IS_MOBILE') != 'yes') {
                    continue;
                }
                /*
                 * P2-SEC-F4 (module entitlement) and P2-SEC-F5 (output escaping).
                 *
                 * RESOLVED BEFORE ANY MARKUP IS EMITTED, and that ordering is load-bearing. The
                 * first version of this change resolved the href after the opening `<li>` and
                 * `continue`d on a drop, which left a DANGLING UNCLOSED `<li>` in the sidebar for
                 * every dropped row -- caught by
                 * CustomSidebarAuthorizationTest::test_a_row_naming_an_unregistered_route_is_dropped_instead_of_throwing,
                 * which asserts the whole output is empty rather than merely that the link is
                 * absent. A `window_type` of `iframe` can also drop a row, so it is resolved here
                 * too.
                 *
                 * `sideMenuHref()` returns null to mean SKIP THIS ROW, which is how module
                 * entitlement is enforced for a path whose table carries no `module` column: an
                 * add-on's provider is registered only when `add_on_managers` holds a row for it
                 * (PackageServiceProvider::loadDynamicAddons), so an inactive module registers no
                 * routes and `Route::has()` answers false for every row pointing at one.
                 *
                 * That also closes an availability defect, not only a disclosure one. The old
                 * line was `route($item['route'])`, and `route()` THROWS RouteNotFoundException
                 * for a name that is not registered -- from inside the sidebar, which every admin
                 * page renders. Live production data holds rows naming `preorders.index`
                 * (PreOrder) and `additionalcustomfields.index` (AdditionalCustomFields), so
                 * turning either module off served HTTP 500 on every admin screen. Those row
                 * values were read from the database dump committed to `main` -- the P1-SEC-40 /
                 * #120 incident artifact, deliberately absent from this branch -- so the claim is
                 * recorded here rather than resting on a file this branch can point at.
                 */
                $href = sideMenuHref($item);

                $target = '';
                if (isset($item['window_type'])) {
                    switch ($item['window_type']) {
                        case 'same_window':
                            $target = '';
                            break;

                        case 'new_window':
                            $target = ' target="_blank"';
                            break;

                        case 'iframe':
                            /*
                             * P2-SEC-F5, SECOND PASS. This branch used to run AFTER
                             * `sideMenuHref()` and OVERWRITE its answer, which quietly undid
                             * both controls for an iframe row: a row naming an unregistered
                             * route was resurrected instead of dropped, and an `external` row's
                             * scheme never reached `sideMenuSafeExternalUrl()`. Found by the
                             * independent tenant/authz review of the first commit.
                             *
                             * `$href === null` is therefore honoured FIRST -- a row the
                             * resolvers already refused stays refused, whatever its
                             * `window_type`. Only then is the wrapper substituted, and only if
                             * the wrapper route itself exists.
                             *
                             * The wrapper page embeds the row's own link as the frame `src`
                             * (SidebarCustomization's super-admin/iframe.blade.php), so the
                             * allowlist has to hold for the EMBEDDED url too, not just for the
                             * wrapper uri. An iframe row whose link is not http(s) is dropped.
                             */
                            if ($href === null) {
                                break;
                            }

                            if (! \Illuminate\Support\Facades\Route::has('sidemenubuilder.iframe')
                                || sideMenuSafeExternalUrl((string) $href) === '#') {
                                $href = null;
                                break;
                            }

                            $href = route("sidemenubuilder.iframe", $item['id']);
                            break;
                    }
                }

                if ($href === null) {
                    continue;
                }

                /*
                 * P2-SEC-F4, THIRD PASS: RENDER THE CHILDREN FIRST, THEN DECIDE ON THE ARROW.
                 *
                 * This was `hasChildren($menuItems, $item['name'])`, which asks whether any ROW
                 * names this one as its parent -- a question answered before the per-row drops
                 * in this same loop. So a parent whose every child was dropped by the
                 * route-existence check still rendered a `.dash-arrow` and an empty
                 * `<ul class="dash-submenu">` holding nothing but the two collapse buttons. The
                 * concrete case is the one this change's own docblock cites: with PreOrder
                 * uninstalled, the `preorder` parent has `route => ''` (so it resolves to '#'
                 * and is kept) while both its children are dropped.
                 *
                 * That is the empty-submenu-with-an-arrow defect P2-01-10 removed from the
                 * listener-fed path, reintroduced here by the drop. Found by the independent
                 * review, not by the tests -- so a test for it is added in the same change.
                 *
                 * Recursing first costs nothing: the recursion ran anyway whenever there WERE
                 * children, and it is skipped entirely when `hasChildren` says there are none.
                 */
                $childHtml = hasChildren($menuItems, $item['name'])
                    ? generateSideMenu($menuItems, $item['name'], $level + 1)
                    : '';

                $hasChildren = $childHtml !== '';

                if ($item['parent'] == null) {
                    $html .= '<li class="dash-item dash-hasmenu">';
                } else {
                    $html .= '<li class="dash-item sub-menu-lnk">';
                }
                $marginLeft = 10 * $level;

                $html .= '<a href="' . e($href) . '" class="dash-link" ' . $target . '>';
                if ($item['parent'] == null) {
                    $html .= ' <span class="dash-micon"><i class="ti ti-' . sideMenuIconSlug($item['icon'] ?? '') . '"></i></span>
                    <span class="dash-mtext">';
                }
                /*
                 * P2-SEC-F5. `__($item['title'])` was interpolated raw. `title` is a
                 * database-sourced, operator- and merchant-editable string
                 * (SidebarCustomization's SideMenuController and Company\SettingsController both
                 * write it), and `__()` returns its INPUT unchanged when the key misses -- which
                 * it does for every custom title -- so the row's bytes reached the document. The
                 * platform-level rows are authored by the operator and render inside every
                 * merchant's admin chrome, which is the stored-XSS shape. `generateMenu()`, the
                 * listener-fed sibling this phase converted, already escapes.
                 */
                $html .= e(__($item['title'])) . '</span>';
                $buttonHtml = '<div class="d-flex justify-content-between"><div><button type="button" class="collepse-menu-buttons collepse-btn-back btn btn-sm btn-secondary"><i class="ti ti-arrow-back"></i></button></div>
                <div><button type="button" class="collepse-menu-buttons collepse-btn-close btn btn-sm btn-danger"><i class="ti ti-x"></i></button></div></div>';

                if ($hasChildren) {
                    $html .= ' </a><span class="dash-arrow"> <i data-feather="chevron-right"></i> </span>';
                    $html .= '<ul class="dash-submenu sub-menu-dropdown">';
                    $html .= $buttonHtml;
                    $html .= $childHtml;
                    $html .= '</ul>';
                } else {
                    $html .= '</a>';
                }
                $html .= '</li>';
            }
        }
        return $html;
    }
}

// Function to recursively create and set permissions for each directory in the path
if (!function_exists('createAndSetPermissionsRecursively')) {
    function createAndSetPermissionsRecursively($path, $disk) {
        $segments = explode('/', $path);
        $currentPath = '';

        foreach ($segments as $segment) {
            $currentPath .= ($currentPath ? '/' : '') . $segment;

            if (!\Storage::disk($disk)->exists($currentPath)) {
                $oldUmask = umask(0);
                \Storage::disk($disk)->makeDirectory($currentPath, 0777, true);
                umask($oldUmask);
            }

            if (function_exists('chmod')) {
                $fullPath = \Storage::disk($disk)->path($currentPath);
                @chmod($fullPath, 0777); // Set permissions to the current directory
            }
        }
    }
}

if (! function_exists('buildDataTableColumn')) {
    function buildDataTableColumn(array $columnConfig, $action = true)
    {
        $columns = [];

        // Always add the ID column (hidden)
        $columns[] = Column::make('id')
            ->searchable(false)
            ->visible(false)
            ->exportable(false)
            ->printable(false);

        $deleteColumn = bulkDeleteCloneCheckboxColumn();
        if ($deleteColumn) {
           $columns[] = $deleteColumn;
        }
        // Add the configured columns
        foreach ($columnConfig as $name => $options) {
            if (is_numeric($name) && is_string($options)) {
                // Simple column definition: just the name
                $columns[] = Column::make($options)
                    ->title(__(ucfirst($options)));
            } else {
                // Advanced column definition with options
                $column = Column::make($name)
                    ->title(__(isset($options['title']) ? $options['title'] : ucfirst($name)));

                // Apply additional options
                if (isset($options['data'])) {
                    $column->data($options['data']);
                }

                if (isset($options['name'])) {
                    $column->name($options['name']);
                }

                if (isset($options['searchable']) && $options['searchable'] === false) {
                    $column->searchable(false);
                }

                if (isset($options['sortable']) && $options['sortable'] === false) {
                    $column->sortable(false);
                }

                if (isset($options['orderable']) && $options['orderable'] === false) {
                    $column->orderable(false);
                }

                if (isset($options['printable']) && $options['printable'] === false) {
                    $column->printable(false);
                }

                if (isset($options['width'])) {
                    $column->width($options['width']);
                }

                if (isset($options['addClass'])) {
                    $column->addClass($options['addClass']);
                }

                $columns[] = $column;
            }
        }

        // Add the action column
        if ($action) {
            $columns[] = Column::computed('action')
                ->exportable(false)
                ->printable(false)
                ->width(60)
                ->title(__('Action'))->addClass('text-center');
        }

        return $columns;
    }
}

if (! function_exists('buildDataTable')) {
    function buildDataTable(string $tableId, $builder, array $columns)
    {
       $dataTable = $builder
            ->setTableId($tableId)
            ->columns($columns)
            ->minifiedAjax()
            ->orderBy(0)
            ->language([
                "paginate" => [
                    "next" => '<i class="ti ti-chevron-right"></i>',
                    "previous" => '<i class="ti ti-chevron-left"></i>'
                ],
                'lengthMenu' => "_MENU_" . __('Entries Per Page'),
                "searchPlaceholder" => __('Search...'),
                "search" => "",
                "info" => __("Showing")." _START_ ".__("to"). " _END_ ".__("of")." _TOTAL_ ".__("entries")
            ])
            ->initComplete('function() {
                        var table = this;

                        var searchInput = $(\'#\'+table.api().table().container().id+\' label input[type="search"]\');
                        searchInput.removeClass(\'form-control form-control-sm\');
                        searchInput.addClass(\'dataTable-input\');
                        var select = $(table.api().table().container()).find(".dataTables_length select").removeClass(\'custom-select custom-select-sm form-control form-control-sm\').addClass(\'dataTable-selector\');
                    }');

        $exportButtonConfig = [];
        $bulkdeleteButtonConfig = [];
        if (module_is_active('BulkDelete')) {
            $type = preg_replace('/-table$/', '', $tableId);
            $bulkdeleteButtonConfig = bulkDeleteForm($type, $tableId);
        }

        $buttonsConfig = array_merge([
            $exportButtonConfig,
            $bulkdeleteButtonConfig,
            [
                'text' => '<i class="ti ti-arrow-back-up" data-bs-toggle="tooltip" title="'.__("Reset").'" data-bs-original-title="'.__("Reset").'"></i>',
                'extend' => 'reset',
                'className' => 'btn btn-light-info',
            ],
            [
                'text' => '<i class="ti ti-refresh" data-bs-toggle="tooltip" title="'.__("Reload").'" data-bs-original-title="'.__("Reload").'"></i>',
                'extend' => 'reload',
                'className' => 'btn btn-light-warning',
            ],
        ]);

        $dataTable->parameters([
            "dom" =>  "
        <'dataTable-top'<'dataTable-dropdown page-dropdown'l><'dataTable-botton table-btn dataTable-search tb-search  d-flex justify-content-end gap-1'Bf>>
        <'dataTable-container'<'col-sm-12'tr>>
        <'dataTable-bottom row'<'col-5'i><'col-7'p>>",
            'buttons' => $buttonsConfig,
            "drawCallback" => 'function( settings ) {
                var tooltipTriggerList = [].slice.call(
                    document.querySelectorAll("[data-bs-toggle=tooltip]")
                  );
                  var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                    return new bootstrap.Tooltip(tooltipTriggerEl);
                  });
                  var popoverTriggerList = [].slice.call(
                    document.querySelectorAll("[data-bs-toggle=popover]")
                  );
                  var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
                    return new bootstrap.Popover(popoverTriggerEl);
                  });
                  var toastElList = [].slice.call(document.querySelectorAll(".toast"));
                  var toastList = toastElList.map(function (toastEl) {
                    return new bootstrap.Toast(toastEl);
                  });
            }'
        ]);

        $dataTable->language([
            'buttons' => [
                'create' => __('Create'),
                'print' => __('Print'),
                'reset' => __('Reset'),
                'reload' => __('Reload'),
            ]
        ]);

        return $dataTable;
    }
}

if (! function_exists('getWebNavMenu')) {
    function getWebNavMenu()
    {
        return Menu::where('store_id', getCurrentStore())->get();
    }
}

if (! function_exists('getThemeSetting')) {
    function getThemeSetting($storeId, $theme)
    {
        $defaultSettings = [];
        $defaultSettingsPath = base_path('themes/'.$theme.'/default_data/settings.php');

        if (file_exists($defaultSettingsPath)) {
            $defaultSettings = include $defaultSettingsPath;
        }

        $themeSettings = ThemeCustomize::where('store_id', $storeId)
                        ->where('theme_id', $theme)
                        ->pluck('value', 'name')
                        ->toArray();
        $mergedSettings = [];

        // Process all keys from default settings
        foreach ($defaultSettings as $key => $value) {
            // If key exists in theme settings, use theme settings value
            if (array_key_exists($key, $themeSettings)) {
                $mergedSettings[$key] = $themeSettings[$key];
                // Remove the key from theme settings to track what's been processed
                unset($themeSettings[$key]);
            } else {
                // Otherwise use default value
                $mergedSettings[$key] = $value;
            }
        }

        // Add any remaining theme settings that weren't in defaults
        foreach ($themeSettings as $key => $value) {
            $mergedSettings[$key] = $value;
        }

        return $mergedSettings;
    }
}

if (! function_exists('getStore')) {
    function getStore($storeSlug = null)
    {
        return Store::where('slug', $storeSlug ?? request()->route('storeSlug'))->first();
    }
}

if (! function_exists('getRouteStoreId')) {
    /**
     * The id of the store the CURRENT REQUEST is addressed to, taken from its route -- or null.
     *
     * P1-SEC-18 F4. A shopper (guest, or on the `customers` guard) is not the `web` guard's user, so `getCurrentStore()` is
     * null for them, and a settings read that names no store then ran with NO `store_id` predicate: every tenant's rows,
     * last one wins -- a shopper at store A was handed store C's gateway key. The storefront's own route already says which
     * store the request is for; this is the same source `getStore()` uses.
     *
     * `storeSlug` always names a store. `slug` names one ONLY as the first path segment (`{slug}/...`, `api/{slug}/...` --
     * the storefront API): `marketplace/{slug}`, `landing-pages/{slug}`, `add-on/details/{slug}` and
     * `{storeSlug}/article/{slug}` reuse the parameter name for a page, an add-on or an article, and a page slug that
     * happens to equal a store's slug must not select that store's credentials.
     *
     * An unknown slug, a route with neither parameter, and a request with no route (console, queue) all return null, and
     * the caller keeps its previous behaviour -- which for an unknown slug is still the all-tenant read; every storefront
     * handler refuses an unknown slug through getStore() before it uses a credential (P1-SEC-18 review, not fail-closed here
     * on purpose: a sentinel id would turn "wrong slug" into a 500 wherever a handler reads a setting without isset()).
     *
     * Memoised on the request, KEYED BY THE SLUG: HomeController re-points `storeSlug` with route()->setParameter() after the
     * route has matched (custom-domain and subdomain landing), so a memo that ignored the slug would keep the first answer.
     */
    function getRouteStoreId(): ?int
    {
        $request = request();
        $route = $request?->route();
        if (! $route instanceof \Illuminate\Routing\Route) {
            return null;
        }

        $slug = $route->parameter('storeSlug');
        if (! is_string($slug) || $slug === '') {
            $segments = explode('/', ltrim($route->uri(), '/'));
            if (($segments[0] ?? '') === 'api') {
                array_shift($segments);
            }
            $slug = in_array($segments[0] ?? '', ['{slug}', '{slug?}'], true) ? $route->parameter('slug') : null;
        }

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        $memo = $request->attributes->get('_route_store_ids', []);
        if (! array_key_exists($slug, $memo)) {
            $storeId = Store::where('slug', $slug)->value('id');
            $memo[$slug] = $storeId ? (int) $storeId : null;
            $request->attributes->set('_route_store_ids', $memo);
        }

        return $memo[$slug];
    }
}

if (! function_exists('getStoreById')) {
    function getStoreById($id)
    {
        return Store::where('id', $id)->first();
    }
}

if (! function_exists('getNavMenu')) {
    function getNavMenu($menuId)
    {
        // THE STOREFRONT NAV IS A PUBLIC READ OF PUBLIC DATA, so it bypasses the tenant
        // scope explicitly.
        //
        // Menu is now on config/tenancy.php's `scoped_models`, which closes a real
        // cross-tenant IDOR on the ADMIN paths (MenuController::findOrFail($id)->delete(),
        // MenuItemsController's five bare Menu::find($menu_id) calls). But this function
        // serves the storefront, and the predicate narrows it for any web-guard-
        // authenticated visitor: a signed-in merchant browsing ANOTHER store's storefront
        // got no menu at all, so the navigation AND the footer went blank in all three
        // shipped themes. `find()` returning null here is indistinguishable from "no such
        // menu", so it failed silently.
        //
        // Scoping buys NO confidentiality here -- a storefront menu is served to anonymous
        // visitors by design, and an anonymous visitor resolves no tenant and so was never
        // filtered. The admin paths keep the scope; this one read opts out, deliberately
        // and visibly, which is what withoutGlobalScope is for.
        $menu = Menu::withoutGlobalScope(\App\Scopes\StoreScope::class)
            ->with('store')
            ->find($menuId);
        if (! $menu) {
            return [];
        }

        // Fetch all menu items including children
        $menuItems = MenuItem::with(['menuItemable', 'children.menuItemable'])
            ->where('menu_id', $menuId)
            ->whereNull('parent_id')
            ->orderBy('order')
            ->get();

        // Recursively build the menu tree
        return buildMenuTree($menuItems, $menu->store);
    }
}

if (! function_exists('buildMenuTree')) {
    function buildMenuTree($menuItems, $store = null)
    {
        $branch = [];

        foreach ($menuItems as $menuItem) {
            // Get the related model dynamically
            $relatedModel = $menuItem->menuItemable;

            // Default values
            $title = null;
            $url = '#';
            $parentTitle = null;
            $target = $menuItem->target ?? '_self';

            // Handle different models dynamically
            if ($relatedModel) {
                switch ($menuItem->menu_itemable_type) {
                    case 'App\Models\Category':
                        $title = $relatedModel->name;
                        $url = url($store->slug . '/'.$relatedModel->slug);
                        break;

                    case 'App\Models\Product':
                        $title = $relatedModel->name;
                        $url = url($store->slug.'/product/'.$relatedModel->slug);
                        break;

                    case 'App\Models\Page':
                        $title = $relatedModel->name;
                        if ($relatedModel->is_default == 0) {
                            $url = url($store->slug.'/page/'.$relatedModel->page_slug);
                        } else {
                            $url = url($store->slug.'/'.$relatedModel->page_slug);
                        }
                        break;

                    case 'App\Models\ProductBrand':
                        $title = $relatedModel->name;
                        $url = url($store->slug.'/brand/'.$relatedModel->slug);
                        break;

                    case 'App\Models\Blog':
                        $title = $relatedModel->name;
                        $url = url($store->slug.'/blog/'.$relatedModel->slug);

                        break;

                    case 'App\Models\CustomLink':
                        $title = $relatedModel->title ?? 'Custom Link';
                        $url = $relatedModel->url ?? '#';
                        break;
                }
            }

            // Fetch Parent Menu Name if Available
            if ($menuItem->parent) {
                $parentModel = $menuItem->parent->menuItemable;
                $parentTitle = $parentModel ? ($parentModel->name ?? $parentModel->title) : 'Root';
            }

            // Ensure menu item has children loaded properly
            $children = $menuItem->children()->with('menuItemable')->orderBy('order')->get();

            $branch[] = [
                'id' => $menuItem->id,
                'title' => $title,
                'url' => $url,
                'parent' => $parentTitle,
                'target' => $target,
                'children' => $children->isNotEmpty()
                    ? buildMenuTree($children, $store)
                    : [],
            ];
        }

        return $branch;
    }
}

if (! function_exists('buildCategoryTree')) {
    // $seen is the chain of ids already on the path from the root (P1-INV-11): a parent_id loop written by any writer ends the walk instead of
    // recursing until the process runs out of memory.
    function buildCategoryTree($categories, $parentId = null, $prefix = '', array $seen = [])
    {
        $tree = [];
        foreach ($categories as $category) {
            if ($category->parent_id == $parentId && ! isset($seen[$category->id])) {
                $tree[] = [
                    'id' => $category->id,
                    'name' => $prefix.$category->name,
                ];
                $tree = array_merge($tree, buildCategoryTree($categories, $category->id, $prefix.'&nbsp;&nbsp;&nbsp;', $seen + [$category->id => true]));
            }
        }
        return $tree;
    }
}

if (! function_exists('generateUniqueSlug')) {
    function generateUniqueSlug($name, $model, $field = 'slug')
    {
        if (isset($model->slug) && $name == $model->slug) {
            return $model->slug;
        }
        // Generate the initial slug
        $slug = Str::slug($name);
        $originalSlug = $slug;
        // Check if the slug already exists in the database
        $count = 1;
        while ($model->where($field, $slug)->exists()) {
            $slug = $originalSlug.'-'.$count;
            $count++;
        }

        return $slug;
    }
}

// if (! function_exists('getPredefinedLinks')) {
//     function getPredefinedLinks($slug = null)
//     {
//         $links = collect([
//             [
//                 'label' => __('Products Page'),
//                 'url' => route('page.product-list', ['storeSlug' => $slug ?? 'store'])
//             ],
//             [
//                 'label' => __('Categories Page'),
//                 'url' => route('collections', ['storeSlug' => $slug ?? 'store'])
//             ],
//             [
//                 'label' => __('About Page'),
//                 'url' => route('page.about', ['storeSlug' => $slug ?? 'store'])
//             ],
//             [
//                 'label' => __('Contact Page'),
//                 'url' => route('page.contact_us', ['storeSlug' => $slug ?? 'store'])
//             ],
//             [
//                 'label' => __('Blog Page'),
//                 'url' => route('page.blog', ['storeSlug' => $slug ?? 'store'])
//             ]
//         ]);

//         return $links;
//     }
// }

if (! function_exists('getPredefinedLinks')) {
    function getPredefinedLinks($slug = null)
    {
        if (!$slug) {
            $storeId = getCurrentStore();
            if ($storeId) {
                $store = getStoreById($storeId);
                $slug = $store ? $store->slug : 'store';
            } else {
                $slug = 'store';
            }
        }

        return collect([
            ['label' => __('Products Page'), 'url' => '/' . $slug . '/product-list'],
            ['label' => __('Categories Page'), 'url' => '/' . $slug . '/collections'],
            ['label' => __('About Page'), 'url' => '/' . $slug . '/about'],
            ['label' => __('Contact Page'), 'url' => '/' . $slug . '/contact-us'],
            ['label' => __('Blog Page'), 'url' => '/' . $slug . '/blog']
        ]);
    }
}

if (! function_exists('getOrderStatusLabel')) {
    function getOrderStatusLabel($key)
    {
        $statusButtons = [
                    0 =>  __('Pending'),
                    1 =>  __('Delivered'),
                    2 =>  __('Cancel'),
                    3 =>  __('Return'),
                    4 =>  __('Confirmed'),
                    5 =>  __('Picked Up'),
                    6 =>  __('Shipped'),
                    7 =>  __('Partially Paid'),
                    8 =>  __('Pre Order')
                ];

        return $statusButtons[$key];
    }
}

if (! function_exists('getFrontThemeUrl')) {
    function getFrontThemeUrl($store)
    {
        $enable_storelink = Utility::GetValueByName('enable_storelink', $store->id);
        $enable_domain = Utility::GetValueByName('enable_domain', $store->id);
        $enable_subdomain = Utility::GetValueByName('enable_subdomain', $store->id);
        $domains = Utility::GetValueByName('domains', $store->id);
        $subdomain = Utility::GetValueByName('subdomain', $store->id);

        if ($enable_domain == 'on') {
            return 'https://' . $domains;
        } elseif ($enable_subdomain == 'on') {
            return 'https://' . $subdomain;
        } elseif ($enable_storelink) {
            return env('APP_URL');
        } else {
            return env('APP_URL');
        }
    }
}

if (!function_exists('getAiModelName')) {
    function getAiModelName()
    {
        return [
            'GPT-4 Series' => [
                'gpt-4o' => 'GPT-4o',
                'gpt-4-turbo' => 'GPT-4-Turbo',
                'gpt-4' => 'GPT-4',
            ],
            'GPT-3.5 Series' => [
                'gpt-3.5-turbo' => 'GPT-3.5-Turbo',
                'gpt-3.5-turbo-instruct' => 'GPT-3.5-Turbo-Instruct',
            ],
        ];
    }
}
/*
|--------------------------------------------------------------------------------------------------
| TENANCY NOTE -- WHY getCurrentStore() WAS *NOT* CHANGED
|--------------------------------------------------------------------------------------------------
|
| Appended at the END OF THE FILE ON PURPOSE. Inserting it next to getCurrentStore() would shift
| every line number below it, and this file is cited by line in other workstreams' documents
| (docs/STABILIZATION-REGISTER.md cites app/Helper/helper.php:143-145 and :1373-1378) and in test
| failure messages. A note that invalidates other people's references pays for its own
| discoverability with everyone else's accuracy. Grep for `getCurrentStore` and you will find both
| ends.
|
| THE PROBLEM. getCurrentStore() (this file, the `getCurrentStore` function) reads the `web` guard
| and returns the principal's `current_store`. Storefronts are path-prefixed (`{storeSlug?}`, 106
| declarations in routes/customer.php) on the SAME host; routes/customer.php is `require`d from
| routes/web.php:1222 so storefront routes carry the `web` group; and config/session.php:146 sets
| the session cookie path to '/'. So on a storefront request made by anyone holding a live admin
| session, this function returns THEIR ADMIN STORE -- not the store whose shop is being rendered.
|
| That mismatch is what made a tenant predicate derived from this function AND the storefront's own
| `where('store_id', $slugResolvedStore->id)` compile to `store_id = A AND store_id = B` -> zero
| rows, silently. It is why Cart and Customer were enabled once and reverted.
|
| THE OBVIOUS FIX IS THE WRONG ONE, and that is the whole point of this note. The tempting change
| is to make THIS FUNCTION return the slug-resolved store on a storefront request. It must not be
| made here:
|
|   1. BLAST RADIUS. This is the single tenant resolver for the whole product -- roughly 497 of the
|      1,282 `where('store_id', ...)` filters pass its result, and it also feeds writes. Changing
|      what it returns would silently re-target INSERTs and UPDATEs, so a merchant doing admin work
|      in one browser tab while another tab sat on some storefront could write rows against the
|      wrong store. That is data corruption, not a blank page.
|   2. IT WOULD FIGHT THE CALL SITES, NOT HELP THEM. The storefront already resolves its own store
|      correctly, through `getStore($storeSlug)` in this file (405 call sites). Those sites are not
|      broken and do not need this function to change; they needed the SCOPE to stop disagreeing
|      with them.
|   3. IT WOULD BREAK THE AUTHORIZATION HELPERS. `App\Models\Concerns\BelongsToStore::
|      isOwnedByCurrentStore()` is used as `abort_unless($model->isOwnedByCurrentStore(), 403)`.
|      If "current store" became the slug store, that check would start ADMITTING requests it
|      denies today -- a permission change wearing a tenancy change's clothes.
|
| WHERE THE FIX ACTUALLY LIVES. App\Scopes\StoreScope::resolveTenant() resolves the tenant PER
| CONTEXT and leaves this function's contract untouched. On a storefront request the slug-resolved
| store is admitted ALONGSIDE the owned stores (`tenancy.storefront_tenant` = 'additive'), so the
| set is a strict superset of today's and cannot blank a working page; on an admin route there is
| no slug and the predicate is the owned-stores set exactly. `StoreScope::resolveStoreId()` still
| returns what this function returns, which is what keeps point 3 above true -- asserted by
| tests/Feature/Tenancy/StorefrontContextTenantResolutionTest::
| test_the_storefront_repair_did_not_widen_an_authorization_helper.
|
| So: ONE resolver, ONE `store_id` discriminator, ONE shared database. The scope learned to ask the
| right question per context; the resolver did not change its answer.
|
*/
