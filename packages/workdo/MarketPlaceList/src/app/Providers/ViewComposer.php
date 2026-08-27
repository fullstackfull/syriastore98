<?php

namespace Workdo\MarketPlaceList\app\Providers;

use App\Models\Plan;
use App\Models\Store;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\ServiceProvider;
use App\Models\Utility;
use Workdo\MarketPlaceList\app\Models\StoreBusinessHour;
use Illuminate\Support\Facades\Cache;

class ViewComposer extends ServiceProvider
{

    public function boot()
    {

        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('MarketPlaceList')) {
                $setting = $view->getData()['setting'] ?? '';
                if (isset($setting) && !empty($setting)) {
                    $view->getFactory()->startPush('appSettingEnableBtn', view('market-place-list::buttons.marketplacelistenable', compact('setting')));
                    $slug = $view->getData()['slug'] ?? null;
                    $store = getStore($slug);
                    $days = StoreBusinessHour::$days;
                    $business_hours = [];
                    $businesshours = StoreBusinessHour::where('store_id', $store->id)->first();
                    if (!empty($businesshours->content)) {
                        $business_hours = json_decode($businesshours->content);
                    }
                    $app_setting_tab = session()->get('app_setting_tab');
                    if (empty($app_setting_tab)) {
                        $app_setting_tab = 'pills-home-tab';
                    }
                    $view->getFactory()->startPush('appSettingTab', view('market-place-list::buttons.appSettingHoursBtn', compact('setting', 'app_setting_tab')));
                    $view->getFactory()->startPush('appSettingTabForm', view('market-place-list::buttons.appSettingViewHours', compact('setting', 'days', 'business_hours', 'businesshours', 'app_setting_tab')));
                }
            }
        });

        view()->composer(['front_end.partison.header'], function ($view) {
            $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
            $store = getStore($slug);
            if ($store && module_is_active('MarketPlaceList', $store->created_by)) {
                $setting = Setting::where('store_id', $store->id)->pluck('value', 'name')->toArray();
                $slug = $store->slug ?? ($view->getData()['slug'] ?? null);
                
                if (isset($setting) && !empty($setting) && isset($slug) && isset($setting['market_place_list_enable']) && $setting['market_place_list_enable'] == 'on') {               
                    $view->getFactory()->startPush('marketPlaceListButton', view('market-place-list::buttons.marketplacelistbutton', compact('setting', 'slug')));
                }
            }
        });

    }
}
