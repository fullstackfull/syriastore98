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

            $search_products = Product::where('store_id', $store->id)
            ->pluck('name', 'id');
            $languages = Utility::languages();
            
            $slug =  $store->slug;

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
                'adminSetting' => $adminSetting
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