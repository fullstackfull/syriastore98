<?php

namespace Workdo\ProductCSV\app\Providers;

use App\Models\Setting;
use App\Models\Store;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ViewComposer extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */

    public function boot(Request $request)
    {
        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('ProductCSV')) {
                $setting = Setting::where('store_id', getCurrentStore())->pluck('value', 'name')->toArray();
                $store = Store::find(getCurrentStore());
                $setting = getAdminAllSetting();
                $slug = $store->slug;
                $view->getFactory()->startPush('appSettingEnableBtn', view('product-csv::pages.csvSwich'));
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
