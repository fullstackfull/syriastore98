<?php

namespace Workdo\QuickCheckout\app\Providers;

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
            if (module_is_active('QuickCheckout')) {
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
                $view->getFactory()->startPush('appSettingTab', view('quick-checkout::admin.quickcheckout-tab',compact('store','app_setting_tab')));
                $view->getFactory()->startPush('appSettingTabForm', view('quick-checkout::admin.checkout-details', compact('store','settings','app_setting_tab')));
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
