<?php

namespace Workdo\ProductImageZoom\app\Providers;

use App\Models\Setting;
use App\Models\Store;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class ViewComposer extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */

     public function boot(){
        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('ProductImageZoom')) {
                $setting = $view->getData()['setting'] ?? null;
                $slug      = $view->getData()['slug'] ?? null;
                $store = Store::find(getCurrentStore());
                if(empty($store))
                {
                    $user = Auth::user();
                    if($user)
                    {
                        $store = getStoreById($user->current_store);
                    }
                }
                $user = Auth::user();


                $app_setting_tab = session()->get('app_setting_tab');
                if (empty($app_setting_tab)) {
                    $app_setting_tab = 'pills-home-tab';
                }
                $settings = [];
                $settings = Setting::where('store_id', $store->id)->pluck('value', 'name')->toArray();
                $view->getFactory()->startPush('appSettingEnableBtn', view('product-image-zoom::ProductImageZoom-tab', compact('setting')));
            }
        });

    }

    public function register()
    {
        //
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }
}
