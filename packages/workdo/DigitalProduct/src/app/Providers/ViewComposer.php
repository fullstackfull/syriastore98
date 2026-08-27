<?php

namespace Workdo\DigitalProduct\app\Providers;

use App\Models\Product;
use App\Models\Store;
use App\Models\Utility;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cache;

class ViewComposer extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */

    public function boot(){
        view()->composer(['main_file'], function ($view) {
            if(module_is_active('DigitalProduct'))
            {
                $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                $store = getStore($slug);
                $currentTheme = $store->theme_id;
                $products = Product::where('product_type','digital')
                    ->where('store_id', $store->id)
                    ->where('status', 1)
                    ->orderBy('created_at', 'desc')
                    ->limit(8)
                    ->get();
                $enable_digital_product = Utility::GetValueByName('enable_digital_product', $store->id);
                if(isset($enable_digital_product) && $enable_digital_product == 'on')
                {
                    $view->getFactory()->startPush('digitalproductslider', view('digital-product::pages.digital_slider',compact('slug', 'products','store', 'currentTheme')));
                }
            }
        });

        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('DigitalProduct')) {
                $store      = $view->getData()['store'] ?? null;
                if (!$store && \Auth::user()) {
                    $store = Store::find(getCurrentStore());
                }
                $setting = $view->getData()['setting'] ?? null;
                $view->getFactory()->startPush('appSettingEnableBtn', view('digital-product::pages.digitalbutton', compact('setting')));
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
