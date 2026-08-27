<?php

namespace Workdo\ProductsPDF\app\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Setting;

class ViewComposer extends ServiceProvider
{
    public function boot()
    {

        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('ProductsPDF')) {
                $setting = Setting::where('store_id', getCurrentStore())->pluck('value', 'name')->toArray();
                if (isset($setting) && !empty($setting)) {
                    $view->getFactory()->startPush('appSettingEnableBtn', view('products-pdf::pages.productspdfenable', compact('setting')));
                }
            }
        });

    }
}
