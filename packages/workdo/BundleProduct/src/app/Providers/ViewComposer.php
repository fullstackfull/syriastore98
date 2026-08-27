<?php

namespace Workdo\BundleProduct\app\Providers;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\ServiceProvider;
use Workdo\BundleProduct\app\Models\BundleProduct;
use Illuminate\Support\Facades\Cache;

class ViewComposer extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */

     public function boot(){
        view()->composer(['front_end.pages.product-detail'], function ($view) {
            if(module_is_active('BundleProduct'))
            {
                $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                $store = getStore($slug);
                $product_slug = \Request::segment(3);
                $product = Product::where('slug',$product_slug)->where('store_id',$store->id)->first();
                $bundle_product = BundleProduct::where('product_id',$product->id)->first();

                if ($bundle_product) {
                    $bundle_product_ids = json_decode($bundle_product->bundle_products);
                    $bundle_products_data = Product::whereIn('id', $bundle_product_ids)->get();
                } else {
                    $bundle_products_data = collect();
                }
                $enable_bundle_product = \App\Models\Utility::GetValueByName('enable_bundle_product', $store->id);
                if (isset($enable_bundle_product) && $enable_bundle_product == 'on')
                {
                   
                    $view->getFactory()->startPush('bundleproductslider', view('bundle-product::pages.bundle_product_slider',compact('slug','bundle_products_data','store')));
                }
            }
        });
        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('BundleProduct')) {
                $store      = $view->getData()['store'] ?? null;
                if (!$store && \Auth::user()) {
                    $store = Store::find(getCurrentStore());
                }
                $enable_product_barcode = \App\Models\Utility::GetValueByName('enable_product_barcode', $store->id);
                $setting = $view->getData()['setting'] ?? null;
                $view->getFactory()->startPush('appSettingEnableBtn', view('bundle-product::enable_button', compact('setting')));
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
