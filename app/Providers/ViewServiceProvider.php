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
            if (request()->route('storeSlug') && !request()->route('theme')) {
                $store = Store::where('slug', request()->route('storeSlug'))->first();
            } else if (request()->route('theme')) {
                $store = Store::find(getCurrentStore());
                if ($store) {
                    $store->theme_id = request()->route('theme');
                } 
            }
            
            $customer = auth('customers')->user() ?? null;
            $customer_name = $customer ? $customer->first_name . ' ' . $customer->last_name : null;
            $themeSettings = getThemeSetting($store->id, $store->theme_id);
            $adminSetting = getAdminAllSetting($store->created_by, $store->id);
            $CategoryList = Category::where('status', 1)->where('store_id', $store->id)->get();
            if (!$store) {
                abort(404);
            }
            
            $search_products = Product::where('store_id', $store->id)
            ->pluck('name', 'id');
            $languages = Utility::languages();
            
            $slug =  $store->slug;
            $currentTheme = $store->theme_id;
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