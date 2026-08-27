<?php

namespace Workdo\CouponEmail\app\Providers;

use App\Models\Store;
use Illuminate\Support\ServiceProvider;

class ViewComposer extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */

    public $settings;

    public function boot()
    {
        view()->composer(['setting.index','AppSetting.index'], function ($view) {
            if (module_is_active('CouponEmail')) {
                $setting = $view->getData()['setting'] ?? null;
                $view->getFactory()->startPush('appSettingEnableBtn', view('coupon-email::pages.enablebutton', compact('setting')));
                $store      = $view->getData()['store'] ?? null;
                if (!$store && \Auth::user()) {
                    $store = Store::find(getCurrentStore() ?? \Auth::user()->current_store);
                }
                $enable_coupon_email = \App\Models\Utility::GetValueByName('enable_coupon_email',  $store->id);
                
                if(isset($enable_coupon_email) && $enable_coupon_email === 'on')
                {
                    $activeTab = session()->get('setting_tab');
                    $view->getFactory()->startPush('settingTab', view('coupon-email::pages.tab', compact('activeTab')));
                }
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
