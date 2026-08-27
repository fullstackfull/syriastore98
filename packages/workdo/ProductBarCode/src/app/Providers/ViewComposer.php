<?php

namespace Workdo\ProductBarCode\app\Providers;

use App\Models\Store;
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
        view()->composer(['front_end.hooks.cart_table_head'], function ($view) {
            if (module_is_active('ProductBarCode')) {
                $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                $store = getStore($slug);
                if (!$store && \Auth::user()) {
                    $store = Store::find(getCurrentStore() ?? \Auth::user()->current_store);
                }
                if ($store) {
                    $enable_product_barcode = \App\Models\Utility::GetValueByName('enable_product_barcode',  $store->id);
                    if (isset($enable_product_barcode) && $enable_product_barcode == 'on') {
                        $view->getFactory()->startPush('productqrcodetitle', view('product-bar-code::pages.barcode_title'));
                    }
                }
            }
        });
        view()->composer(['pos.index','AppSetting.index'], function ($view) {
            if (module_is_active('ProductBarCode')) {
                $store      = $view->getData()['store'] ?? null;
                if (!$store && \Auth::user()) {
                    $store = Store::find(getCurrentStore() ?? \Auth::user()->current_store);
                }
                $enable_product_barcode = \App\Models\Utility::GetValueByName('enable_product_barcode', $store->id);
                $setting = $view->getData()['setting'] ?? null;
                $view->getFactory()->startPush('appSettingEnableBtn', view('product-bar-code::pages.barcodebutton', compact('setting')));
                if ($store && isset($enable_product_barcode) && $enable_product_barcode == 'on') {
                    $view->getFactory()->startPush('AddBarcodeSearch', view('product-bar-code::pages.pos_barcode'));
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
