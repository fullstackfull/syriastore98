<?php

namespace Workdo\WholesaleProduct\app\Providers;
use App\Models\Store;
use App\Models\{User, Plan, Product};
use Illuminate\Support\Facades\Cache;
// use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\ServiceProvider;

class ViewComposer extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public $settings;


    public function boot()
    {
        
        view()->composer(['product.create', 'product.edit'], function ($view) {
            if (module_is_active('WholesaleProduct')) {
                if (\Auth::user()) {
                    $product = $view->getData()['product'] ?? null;

                    $view->getFactory()->startPush('addwholesalefields', view('wholesale-product::pages.create_price', compact('product')));
                    $view->getFactory()->startPush('editwholesalefields', view('wholesale-product::pages.edit_price', compact('product')));
                }
            }
        });
        view()->composer(['front_end.pages.product-detail'], function ($view) {
            if (module_is_active('WholesaleProduct')) {
                $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                $store = getStore($slug);
                if ($store) {
                    $product_slug = \Request::segment(3);
                    $product = Product::where('slug', $product_slug)->first();

                    $view->getFactory()->startPush('addWholesalepricingButton', view('wholesale-product::pages.price_button', compact('slug', 'product')));
                }
            }
        });

    }

}
