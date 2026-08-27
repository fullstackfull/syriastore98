<?php

namespace Workdo\PreOrder\app\Providers;

use App\Models\Cart;
use Illuminate\Support\Facades\Route;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cache;
use Workdo\PreOrder\app\Models\PreOrder;
use Workdo\PreOrder\app\Models\PreOrderSetting;

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
        view()->composer(['front_end.sections.pages.product'], function ($view) {
            if (module_is_active('PreOrder')) {
                $customer = auth('customers')->user() ?? null;
                if (isset($customer)) {
                    $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                    $store = getStore($slug);
                    if ($store) {
                        $product = $view->getData()['product'] ?? null;
                        $pre_order_detail = PreOrder::where('store_id', $store->id)->first();
                        
                        if(isset($product) && isset($pre_order_detail) && $pre_order_detail->enable_pre_order == 'on'){
                            if (($product->track_stock == 0 && $product->stock_status == 'out_of_stock')) {
                                $pre_order_button = true;
                                $view->getFactory()->startPush('addPreOrderButton', view('pre-order::pages.button', compact('slug', 'product', 'pre_order_detail', 'pre_order_button')));
                            }
                        }
                    }
                }
            }
        });

        view()->composer(['front_end.sections.pages.checkout','front_end.sections.order_detail','order.view'], function ($view) {
            if (module_is_active('PreOrder')) {
                $customer = auth('customers')->user() ?? null;
                if (isset($customer)) {
                    $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                    $store = getStore($slug);
                    if ($store) {
                        $Carts = Cart::where('customer_id', $customer->id)->where('order_type','pre_order')->where('store_id', $store->id)->get();
                        if(isset($Carts) && (count($Carts) > 0)){
                            $view->getFactory()->startPush('preOrderFormField', view('pre-order::theme.preOrderFormField'));
                        }
                        $view->getFactory()->startPush('AddOrderStatus', view('pre-order::admin.order-status', compact('store')));
                    }
                }
            }
        });
    }
}
