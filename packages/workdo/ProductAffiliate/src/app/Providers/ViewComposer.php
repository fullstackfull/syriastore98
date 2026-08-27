<?php

namespace Workdo\ProductAffiliate\app\Providers;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\ServiceProvider;
use Workdo\ProductAffiliate\app\Models\AffiliateSetting;
use Illuminate\Support\Facades\Cache;

class ViewComposer extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */

    public function boot()
    {
        view()->composer(['front_end.pages.account', 'front_end.pages.order', 'front_end.pages.address', 'front_end.pages.support_ticket'], function ($view) {
            if (module_is_active('ProductAffiliate')) {
                $data = $view->getData();
                $store = $data['store']; 
                $slug=$store->slug;
                $user = User::where('current_store', $store->id)->where('type', 'admin')->first();
                $user_id = !empty($user->id) ? $user->id : 0;
                $setting = AffiliateSetting::where('created_by', $user_id)->first();
                $enable_affiliate_product = \App\Models\Utility::GetValueByName('enable_affiliate_product',  $store->id);
                if ($store && isset($enable_affiliate_product) && $enable_affiliate_product == 'on') {
                    $user      = User::find($store->created_by);
                    $view->getFactory()->startPush('affiliatesettings', view('product-affiliate::pages.button',compact('store')));
                    $view->getFactory()->startPush('affiliatesettingTabs', view('product-affiliate::pages.tabs_view', compact('store', 'slug', 'setting')));
                }
            }
        });
        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('ProductAffiliate')) {
                $store      = $view->getData()['store'] ?? null;
                if (!$store && \Auth::user()) {
                    $store = Store::find(getCurrentStore());
                }
                $setting = $view->getData()['setting'] ?? null;
                $view->getFactory()->startPush('appSettingEnableBtn', view('product-affiliate::pages.enablebutton', compact('setting')));
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
