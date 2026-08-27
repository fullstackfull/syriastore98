<?php

namespace Workdo\ProductCompare\app\Providers;

use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\ServiceProvider;
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
        view()->composer(['front_end.hooks.footer_link'], function ($view) {
            
            if(module_is_active('ProductCompare'))
            {
                $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                $store = getStore($slug);
                if ($store) {
                    $enable_compare = \App\Models\Utility::GetValueByName('enable_product_compare', $store->id);
                    if (isset($enable_compare) && $enable_compare == 'on') {
                        $view->getFactory()->startPush('addCompareButton', view('product-compare::product-compare.compare-button', compact('store')));
                    }
                }
            }
        });
        view()->composer(['AppSetting.index'], function ($view) {
            if(module_is_active('ProductCompare'))
            {
                $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                $store = Store::find(getCurrentStore());
                if ($store) {
                    $setting = $view->getData()['setting'] ?? null;
                    $view->getFactory()->startPush('appSettingEnableBtn', view('product-compare::admin.comparebutton', compact('setting')));
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
