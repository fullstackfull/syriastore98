<?php

namespace Workdo\TawktoMessenger\app\Providers;

use App\Models\Plan;
use App\Models\Store;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cache;

class ViewComposer extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public $settings;
    public function boot(Request $request)
    {
        view()->composer(['setting.index'], function ($view) {
            if (module_is_active('TawktoMessenger')) {
                $activeTab = session()->get('setting_tab');
                $view->getFactory()->startPush('settingTab', view('tawkto-messenger::pages.tab', compact('activeTab')));
            }
        });
        

        view()->composer(['front_end.jQueryRoute'], function ($view) {
            $store = Store::where('slug', request()->segment(1))->first();
            if (!$store) {
                $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                $store = getStore($slug);
            } else {
                $slug = request()->segment(1);
            }
            if ($store) {
                $setting =  Setting::where('store_id', $store->id)->pluck('value', 'name')->toArray();
                if(module_is_active('TawktoMessenger') && isset($setting['tawkto_setting_enabled']) && ($setting['tawkto_setting_enabled'] == 'on')) {
                    $view->getFactory()->startPush('tawktoModelPopup', view('tawkto-messenger::pages.widget', compact('slug', 'store', 'setting')));
                }
            }
        });
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [];
    }
}
