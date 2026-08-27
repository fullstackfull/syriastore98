<?php

namespace Workdo\Wizzchat\app\Providers;

use App\Models\Store;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cache;

class ViewComposer extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function boot(Request $request)
    {
        view()->composer(['setting.index'], function ($view) {
            if (module_is_active('Wizzchat')) {
                $activeTab = session()->get('setting_tab');
                $view->getFactory()->startPush('settingTab', view('wizzchat::pages.tab', compact('activeTab')));
            }
        });

        view()->composer(['front_end.jQueryRoute'], function ($view) {
            if (module_is_active('Wizzchat')) {
                $store = Store::where('slug', request()->segment(1))->first();
                if (!$store) {
                    $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                    $store = getStore($slug);
                } else {
                    $slug = request()->segment(1);
                }
                if ($store) {
                    $setting = Setting::where('store_id', $store->id)->pluck('value', 'name')->toArray();
                    if(isset($setting['is_wizzchat_enabled']) && ($setting['is_wizzchat_enabled'] == 'on')) {
                        $view->getFactory()->startPush('wizzchatModelPopup', view('wizzchat::pages.widget', compact('slug', 'store', 'setting')));
                    }
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
