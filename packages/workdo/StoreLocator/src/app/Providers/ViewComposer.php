<?php

namespace Workdo\StoreLocator\app\Providers;

use App\Models\Store;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Auth;
use Workdo\StoreLocator\app\Models\StoreLocator;
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
        view()->composer(['setting.index'], function ($view) {
            if (module_is_active('StoreLocator')) {
                $activeTab = session()->get('setting_tab');
                $view->getFactory()->startPush('settingTab', view('store-locator::pages.tab', compact('activeTab')));
            }
        });

        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('StoreLocator')) {
                $setting = $view->getData()['setting'] ?? null;
                $slug      = $view->getData()['slug'] ?? null;
                $store = getStoreById(getCurrentStore());
                if (empty($store)) {
                    $user = Auth::user();
                    if ($user) {
                        $store = Store::find($user->current_store);
                    }
                }
                $user = Auth::user();

                $settings = [];
                $settings = StoreLocator::where('created_by', $user->id)->where('store_id', $store->id)->first();
                $app_setting_tab = session()->get('app_setting_tab');
                if (empty($app_setting_tab)) {
                    $app_setting_tab = 'pills-home-tab';
                }
                if (isset($settings) && $settings->store_locator_enabled == 'on') {
                    $view->getFactory()->startPush('appSettingTab', view('store-locator::admin.storelocator-button', compact('store', 'app_setting_tab')));
                    $view->getFactory()->startPush('appSettingTabForm', view('store-locator::admin.storelocator-details', compact('settings', 'store', 'app_setting_tab')));
                }
            }
        });

        view()->composer(['front_end.sections.pages.contact-us'], function ($view) {
            if (module_is_active('StoreLocator')) {
                $setting = $view->getData()['setting'] ?? null;
                $slug      = $view->getData()['slug'] ?? null;
                $store      = getStore($slug);
                if (empty($store)) {
                    $user = Auth::user();
                    if ($user) {
                        $store = Store::find($user->current_store);
                    }
                }
                if (empty($store)) {
                    $store = $view->getData()['store'] ?? '';
                }
                if ($store) {
                    $locator_settings = [];
                    $locator_settings = StoreLocator::where('created_by', $store->created_by)->where('store_id', $store->id)->first();
                    if (isset($locator_settings) && $locator_settings->store_locator_enabled == 'on') {
                        $view->getFactory()->startPush('LocatorMapView', view('store-locator::theme.locator_view', compact('locator_settings', 'store')));
                    }
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
