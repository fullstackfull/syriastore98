<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Store;
use App\Models\Product;
use Illuminate\View\FileViewFinder;
use Illuminate\View\ViewServiceProvider as ConcreteViewServiceProvider;
use DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\App;
use App\Models\Utility;
use App\Models\User;
use App\Models\Setting;
use App\Models\ThemeCustomize;
use Qirolab\Theme\Theme;

class ViewServiceProvider extends ConcreteViewServiceProvider
{
    public function boot()
    {
        /*
         * P1-SEC-69 (b). The Campaigns module builds its store, product and user lists inline in
         * its own controller, with the bare `created_by = creatorId()` predicate this branch has
         * been removing everywhere else -- so for a principal rooted at the OPERATOR the screen
         * offered the operator's stores and products. `packages/workdo/` is not edited, and a
         * composer is the last writer of a key the handler set, so this narrows those lists without
         * touching the module. Registered by NAME: the `campaigns::` namespace exists only while
         * the module is loaded, and a composer for a view that never renders costs nothing.
         */
        View::composer(\App\View\Composers\CampaignsTenantFilterScope::VIEWS, \App\View\Composers\CampaignsTenantFilterScope::class);

        View::composer(['front_end.layouts.app', 'main_file', 'front_end.pages.*', 'front_end.Auth.*'], function ($view) {
            // PHASE 03 M0 -- THE GUARD BELOW USED TO SIT FOUR LINES FURTHER DOWN, AND SO HAD NEVER
            // ONCE EXECUTED.
            //
            // `abort(404)` was already written for a null store. But `getThemeSetting($store->id,
            // ...)`, `getAdminAllSetting($store->created_by, ...)` and the `Category` query all
            // dereferenced `$store` BEFORE it. On PHP 8 "Attempt to read property on null" is a
            // warning, and Laravel's handler promotes a warning to an ErrorException -- so a
            // storefront page requested with an unknown slug answered 500 and the 404 three lines
            // later was unreachable code.
            //
            // MEASURED on the dev fixture, unknown slug, no Referer: `/about`, `/address`,
            // `/order` and `/wishlist` all answered 500 while answering 200 for a real slug. One
            // reordering fixes all four, because the composer -- not any controller -- is where
            // they crashed; `about_page()` does not even look the store up.
            //
            // `$store` is initialised because it is otherwise UNASSIGNED when a request carries
            // neither `storeSlug` nor `theme` (a console render, or a view name that matches these
            // patterns from an unexpected route). That was an undefined-variable warning promoted
            // the same way, i.e. another 500. Fail closed instead.
            $store = null;

            // `!== null`, NOT truthiness: `request()->route('storeSlug')` is the STRING '0' for a
            // store whose slug is `0`, and '0' is falsy in PHP. Under the old truthiness test this
            // branch was skipped, `$store` stayed null, and the guard below -- now that it can
            // actually fire -- answered 404 for a store that EXISTS. The same falsy-string rule
            // made `storeSlug()` answer 403 for it. Found by the regression test, not by reading.
            if (request()->route('storeSlug') !== null && !request()->route('theme')) {
                $store = Store::where('slug', request()->route('storeSlug'))->first();
            } else if (request()->route('theme')) {
                $store = Store::find(getCurrentStore());
                if ($store) {
                    $store->theme_id = request()->route('theme');
                } 
            }

            if (!$store) {
                abort(404);
            }

            $customer = auth('customers')->user() ?? null;
            $customer_name = $customer ? $customer->first_name . ' ' . $customer->last_name : null;
            $themeSettings = getThemeSetting($store->id, $store->theme_id);
            $adminSetting = getAdminAllSetting($store->created_by, $store->id);
            $CategoryList = Category::where('status', 1)->where('store_id', $store->id)->get();

            /*
             * CUST-003 — «بحث ذكي ومرادفات وفلاتر ديناميكية». `where('status', 1)`, AND THE LINE
             * ABOVE IS THE EVIDENCE IT WAS AN OMISSION RATHER THAN A DECISION.
             *
             * This is the dataset all three themes' `front_end/partison/header.blade.php` iterate
             * into the storefront search dropdown, so it is embedded in the SOURCE OF EVERY
             * STOREFRONT PAGE whether or not anybody searches. Without the predicate it carried the
             * names of the merchant's UNPUBLISHED products — measured by rendering
             * `/{slug}/home` and finding an inactive product's name in the body.
             *
             * `$CategoryList` two lines up is already `Category::where('status', 1)`, and
             * `HomeController::product_page_filter` — the storefront's own filter panel — is already
             * `->where('status', 1)`. So the rule exists in this requirement's surface and three
             * doors skipped it; this applies the existing rule rather than inventing one.
             *
             * `products.status` is `integer default 1 comment '0 => Inactive, 1 => Active'`, so a
             * store that has never deactivated anything sees no change at all.
             *
             * NOT BOUNDED, and that is deliberate: this is still the WHOLE published catalogue on
             * every page, which is a real performance problem. A `limit()` would silently drop
             * products out of the dropdown, which is a UX decision and not this change's to take.
             * Reported rather than guessed at.
             */
            $search_products = Product::where('store_id', $store->id)
            ->where('status', 1)
            ->pluck('name', 'id');
            $languages = Utility::languages();
            
            $slug =  $store->slug;

            /*
             * GAP-118 / P03-MP-02 -- THE MERCHANT'S SEO META, WHICH WAS WIRED TO NOTHING.
             *
             * `POST /seo-setting` (`AppSettingController::seoSettings`, route `seo.settings`) has
             * always stored `metakeyword`, `metadesc` and `metaimage` into `settings` under the
             * merchant's own `store_id`. No reader existed. MEASURED before this change, on four
             * stores each with all three values stored and on all twelve storefront pages that
             * answer 200: ten of the twelve `<meta>` tags carried `config('app.name')` -- the
             * PLATFORM's name -- on every page of every store, `og:image` included, where a URL
             * belongs. Only `og:url` and `twitter:url` were per-store, because `$slug` was the one
             * argument the layout passed.
             *
             * THIS IS THE RIGHT PLACE and not a controller: all FOUR copies of
             * `front_end/layouts/app.blade.php` (one per installed theme plus the `resources/views`
             * fallback) already call
             *
             *     {!! metaKeywordSetting($metakeyword ?? null, $metadesc ?? null, $metaimage ?? null, $slug) !!}
             *
             * and a composer is keyed on the view NAME, under which all four resolve. So one
             * registration feeds every copy and no Blade file had to change -- which matters,
             * because a layout-level storefront change made in fewer than four places silently
             * does nothing for the other themes.
             *
             * `$store->id` IS PASSED EXPLICITLY, AND THAT IS THE WHOLE TENANCY CONTROL.
             * `Utility::GetValueByName($name)` with no store id defaults to
             * `getCurrentStore() ?: getRouteStoreId()`, and `getCurrentStore()` reads
             * `current_store` off the DEFAULT (`web`) guard. Storefront routes are inside the `web`
             * group and the session cookie path is '/', so a merchant with a live panel session is
             * a populated web-guard principal on every storefront request -- other merchants'
             * storefronts included. The default would therefore have printed store A's SEO metadata
             * into store B's <head> for that visitor. `$store` here is the row this composer
             * already resolved from `request()->route('storeSlug')`, which is the only correct
             * answer for a storefront page.
             * Driven by Tests\Feature\Storefront\StorefrontSeoMetaPerStoreTest.
             *
             * '' IS NORMALISED TO NULL because the helper's `?? config('app.name')` fallback only
             * fires on null. `settings.value` has no length or NOT NULL constraint and several
             * handlers write `$request->all()`, so an empty string is a state the table reaches --
             * and it would otherwise render an empty title on every page of that store.
             *
             * THE IMAGE BECOMES AN ABSOLUTE URL. `og:image` and `twitter:image` are read off-site,
             * where the stored relative path resolves against nothing; `get_file()` is what the
             * merchant settings screen already uses on this same value (AppSettingController:139).
             *
             * The values are merchant-supplied and `GetValueByName` applies no filter to these
             * names, so they arrive verbatim at a `{!! !!}` print: the escaping inside
             * `metaKeywordSetting()` is the whole of the defence and is a precondition of this
             * wiring, not a separate concern (tests/Unit/Security/MetaKeywordSettingEscapingTest).
             */
            $storeMeta = static function (string $name) use ($store) {
                $value = Utility::GetValueByName($name, $store->id);

                return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
            };

            $metakeyword = $storeMeta('metakeyword');
            $metadesc = $storeMeta('metadesc');
            $metaimage = $storeMeta('metaimage');
            if ($metaimage !== null) {
                /*
                 * `get_file()` ends in `catch (\Throwable) { return ''; }`, so a store whose
                 * platform operator has `storage_setting = 'wasabi'` with any of the four wasabi
                 * keys unset gets '' back -- an undefined-array-key warning that Laravel promotes
                 * and this function swallows. That is a reachable state, not a hypothetical: none
                 * of those keys is NOT NULL and the settings screen writes them only when remote
                 * storage is configured. '' would print `content=''` rather than falling back, so
                 * it is normalised to null and the helper's existing fallback applies instead.
                 */
                $resolved = get_file($metaimage);
                $metaimage = is_string($resolved) && trim($resolved) !== '' ? $resolved : null;
            }

            // PHASE 03 M0 -- `$currentTheme` reaches the layout, which builds every stylesheet and
            // script URL as `asset('themes/' . $currentTheme . '/assets/...')`. For a store with no
            // theme chosen `theme_id` is the empty STRING, so SIX asset URLs came out as
            // `themes//assets/...` -- measured on a themeless fixture store, and all six 404.
            // `Theme::set('')` on the next line also undid the view root the middleware had just
            // resolved.
            //
            // Same existing configuration as the middleware and `getThemeSetting()` use, so all
            // three now agree on one answer for a themeless store instead of three different ones.
            $currentTheme = (string) $store->theme_id !== ''
                ? $store->theme_id
                : (string) config('theme.active');

            Theme::set($currentTheme);

            $currantLang = \Cookie::get('LANGUAGE') ?? $store->default_language;
           
            App::setLocale($currantLang);
            $data = [
                'languages' => $languages,
                'currentTheme' => $currentTheme,
                'currantLang' => $currantLang,
                'store' => $store,
                'themeSettings' => $themeSettings,
                'slug' => $slug,
                'search_products' => $search_products,
                'MainCategoryList'=> $CategoryList,
                'customer_name' => $customer_name,
                'customer' => $customer,
                'adminSetting' => $adminSetting,
                'metakeyword' => $metakeyword,     // GAP-118: the store's own SEO meta, by store id
                'metadesc' => $metadesc,
                'metaimage' => $metaimage,
            ];
            $view->with($data);
        });

        
    }

    /**
     * Register the view finder implementation.
     *
     * @return void
     */
    public function registerViewFinder()
    {
        if(file_exists(storage_path() . "/installed"))
        {
            $local = parse_url(config('app.url'))['host'];
            // Get the request host
            $remote = request()->getHost();
            // Get the remote domain
            // remove WWW
            $remote = str_replace('www.', '', $remote);
         
            try {
            // P1-SEC-29: only a row whose own store has the matching flag on counts (App\Services\HostClaims).
            $subdomain = \App\Services\HostClaims::row('subdomain', $remote);
            $domain = \App\Services\HostClaims::row('domains', $remote);
    
            if ($subdomain || $domain ) {
                if ($subdomain) {
                    $enable_subdomain = DB::table('settings')->where('name','enable_subdomain')->where('value','on')->where('store_id',$subdomain->store_id)->first();
                    if ($enable_subdomain) {
                    $admin = DB::table('users')->find($enable_subdomain->created_by);
                        if ($enable_subdomain->value == 'on' &&  $enable_subdomain->store_id == $admin->current_store) {
                            $store = DB::table('stores')->find($admin->current_store);
                            if ($store) {                              
                                \Config::set('view.paths',[resource_path('views'), base_path('themes/'.$store->theme_id.'/views')]);
                            }
                        } else {
                            if (request()->segments()) {
                                $slug =request()->segments()[0];                    
                                if ($slug != 'admin' && $slug != 'logout' && $slug != 'install' && $slug != 'api') {
                                    $store = DB::table('stores')->where('slug',$slug)->first();                                    
                                    if ($store) {
                                        \Config::set('view.paths',[
                                            resource_path('views'),
                                            base_path('themes/'.$store->theme_id.'/views')
                                        ]);
                                    }
                                }
                            }
                        }
                    }
                }
    
                if ($domain) {
                    $enable_domain = DB::table('settings')->where('name','enable_domain')->where('value','on')->where('store_id',$domain->store_id)->first();
                    if( $enable_domain) {
                        $admin = DB::table('users')->find($enable_domain->created_by);
                        if ($enable_domain->value == 'on' &&  $enable_domain->store_id == $admin->current_store){
                            $store = DB::table('stores')->find($admin->current_store);
                            
                            if ($store) {
                                \Config::set('view.paths',[resource_path('views'), base_path('themes/'.$store->theme_id.'/views')]);
                            }
                        } else {
                            if (request()->segments()) {
                                $slug =request()->segments()[0];                    
                                if ($slug != 'admin' && $slug != 'logout' && $slug != 'install' && $slug != 'api') {
                                    $store = DB::table('stores')->where('slug',$slug)->first();                                    
                                    if ($store) {
                                        \Config::set('view.paths',[
                                            resource_path('views'),
                                            base_path('themes/'.$store->theme_id.'/views')
                                        ]);
                                    }
                                }
                            } else {
                                if (isset($admin)) {
                                    $store = DB::table('stores')->where('id', $admin->current_store)->first();
                                    
                                    if($store)
                                    {
                                        \Config::set('view.paths',[
                                            resource_path('views'),
                                            base_path('themes/'.$store->theme_id.'/views')
                                        ]);
                                    }
                                }
                               
                            }
                        }
                    }
                }
            } else {
                if (request()->segments()) {
                    $slug =request()->segments()[0];        
                    if ($slug != 'admin' && $slug != 'logout' && $slug != 'install' && $slug != 'api') {
                        $store = DB::table('stores')->where('slug',$slug)->first();                        
                        if ($store) {
                            \Config::set('view.paths',[
                                resource_path('views'),
                                base_path('themes/'.$store->theme_id.'/views')
                            ]);
                        }
                    }
                } else {
                    if (isset($admin)) {
                        $store = DB::table('stores')->where('id', $admin->current_store)->first();                        
                        if ($store) {
                            \Config::set('view.paths',[
                                resource_path('views'),
                                base_path('themes/'.$store->theme_id.'/views')
                            ]);
                        }
                    }
                }
            }
            } catch (\Exception $e) {
                if (request()->segments()) {
                    $slug =request()->segments()[0];                    
                    if ($slug != 'admin' && $slug != 'logout' && $slug != 'install' && $slug != 'api') {
                        $store = DB::table('stores')->where('slug',$slug)->first();                        
                        if ($store) {
                            \Config::set('view.paths',[
                                resource_path('views'),
                                base_path('themes/'.$store->theme_id.'/views')
                            ]);
                        }
                    }
                }
            }
        }
    }
}