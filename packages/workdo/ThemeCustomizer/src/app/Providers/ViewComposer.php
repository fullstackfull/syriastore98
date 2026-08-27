<?php

namespace Workdo\ThemeCustomizer\app\Providers;

use Workdo\ThemeCustomizer\app\Models\StoreThemeCustomizer;
use App\Models\Store;
use App\Models\Setting;
use Illuminate\Support\ServiceProvider;

class ViewComposer extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public $settings;


    public function boot(){
        view()->composer(['theme_customize.pages'], function ($view) {     
            if (module_is_active('ThemeCustomizer')) {   
                $theme = request()->route('theme');                
                $view->getFactory()->startPush('customizerBtn', view('theme-customizer::pages.button', compact('theme')));
            }
        });

        view()->composer(['front_end.layouts.app'], function ($view) {
            $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
            if (empty($slug)) {
                $slug = $view->getData()['storeSlug'];
            }
            
            $store = getStore($slug);
            if (empty($store)) {
                $remote = request()->getHost();
                $remote = str_replace('www.', '', $remote);
                $subdomain = Setting::where('name', 'subdomain')->where('value', $remote)->first();
                $domain = Setting::where('name', 'domains')->where('value', $remote)->first();
                if ($subdomain && !$domain) {
                    $store = Store::where('id',$subdomain->store_id)->first();
                } elseif (!$subdomain && $domain) {
                    $store = Store::where('id',$domain->store_id)->first();
                }
            }
            
            if ($store) {
                $storeCustom = StoreThemeCustomizer::where('store_id', $store->id)->first();
                $view->getFactory()->startPush('page-style', view('theme-customizer::pages.style', compact('storeCustom','slug'))); 
            }
        });
    }


    
}
