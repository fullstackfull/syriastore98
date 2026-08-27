<?php

namespace Workdo\FreeShippingPopup\app\Providers;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cache;

class ViewComposer extends ServiceProvider
{
    public function boot()
    {
        // Admin Side
        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('FreeShippingPopup')) {
                if (isset($view->getData()['plan'])) {
                    $setting = getAdminAllSetting();
                    $app_setting_tab = session()->get('app_setting_tab');
                    if (empty($app_setting_tab)) {
                        $app_setting_tab = 'pills-home-tab';
                    }
                    $view->getFactory()->startPush('appSettingTab', view('free-shipping-popup::admin.freeShippingSettingTab', compact('setting', 'app_setting_tab')));
                    $view->getFactory()->startPush('appSettingTabForm', view('free-shipping-popup::admin.freeShippingSettingDetails', compact('setting', 'app_setting_tab')));
                }
            }
        });

        // Theme side
        view()->composer(['front_end.hooks.footer_link'], function ($view) {
            if (module_is_active('FreeShippingPopup')) {
             
                if (isset($view->getData()['store']) && isset($view->getData()['slug'])) {
                    $slug = $view->getData()['slug'] ?? null;
                    $store = $view->getData()['store'] ?? null;
                    $admin = $store->created_by;
                    $setting = getAdminAllSetting($store['created_by'], $store['id']);
                    $view->getFactory()->startPush('freeShippingPopupButton', view('free-shipping-popup::theme.freeShippingPopupbutton', compact('setting', 'slug', 'store')));
                }
            }
        });
    }
}
