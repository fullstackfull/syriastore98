<?php

namespace Workdo\DigitalProduct\app\Providers;

use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\ServiceProvider;
use Workdo\DigitalProduct\app\Models\DigitalProductImage;
use Illuminate\Support\Facades\Cache;

class DigitalProductimageProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */

    public function boot()
    {
        view()->composer(['front_end.sections.order_detail', 'order.view'], function ($view) {
            if (module_is_active('DigitalProduct')) {
                $slug = $view->getData()['slug'] ?? '';
                $store = getStore($slug);
                $order = $view->getData()['order_data'] ?? '';
                if (empty($order)) {
                    $order = $view->getData()['order'] ?? '';
                }
                if (empty($store)) {
                    $store = $view->getData()['store'] ?? '';
                }
                $order_id = !empty($order->id) ? $order->id : $order['id'];
                $order_data =  Order::where('id', $order_id)->where('store_id', $store->id)->first();
                $product_image = [];

                if ($order_data) {
                    $productIds = explode(',', $order_data->product_id);
                    foreach ($productIds as $productId) {
                        $product = Product::find($productId);
                        if ($product) {
                            if ($product->product_type == 'digital') {
                                $product_image = DigitalProductImage::where('product_id', $product->id)->where('store_id', $store->id)->get();
                            }
                        }
                    }
                }
                $enable_digital_product = \App\Models\Utility::GetValueByName('enable_digital_product', $store->theme_id, $store->id);
                if (isset($enable_digital_product) && $enable_digital_product == 'on') {
                    $view->getFactory()->startPush('showdigitalproductattachment', view('digital-product::pages.attachment', compact('slug', 'store', 'product_image')));
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
