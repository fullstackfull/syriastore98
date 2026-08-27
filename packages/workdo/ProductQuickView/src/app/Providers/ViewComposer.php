<?php

namespace Workdo\ProductQuickView\app\Providers;

use Illuminate\Support\Facades\Route;
use App\Models\Product;
use App\Models\Store;

// use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cache;

class ViewComposer extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public $settings;


    public function boot(){
        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('ProductQuickView')) {                
                $store = Store::find(getCurrentStore());                
                if ($store) {
                    $setting = getAdminAllSetting($store->created_by, $store->id) ?? null;
                    $view->getFactory()->startPush('appSettingEnableBtn', view('product-quick-view::pages.switch', compact('setting')));
                }
            }
        });

    }

}
