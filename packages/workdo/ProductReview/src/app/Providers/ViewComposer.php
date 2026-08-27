<?php

namespace Workdo\ProductReview\app\Providers;

use Illuminate\Support\Facades\Route;
use App\Models\Product;
use App\Models\Store;
use Workdo\ProductReview\app\Models\ProductReview;
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


    public function boot()
    {

        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('ProductReview')) {
                $store = Store::find(getCurrentStore());
                if ($store) {
                    $setting = getAdminAllSetting($store->created_by, $store->id) ?? null;
                    $view->getFactory()->startPush('appSettingEnableBtn', view('product-review::product-review.switch', compact('setting')));
                }
            }
        });

        view()->composer(['front_end.pages.product-detail'], callback: function ($view) {
            if (module_is_active('ProductReview')) {
                $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                $store = getStore($slug);
                if ($store) {
                    $product_slug = \Request::segment(3);
                    $product = Product::where('slug', $product_slug)->first();

                    $view->getFactory()->startPush('addReviewButton', view('product-review::pages.button', compact('slug', 'product')));
                }
            }
        });
    }
}
