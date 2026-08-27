<?php

namespace Workdo\CheckoutAttachment\app\Providers;

use App\Models\Order;
use App\Models\Store;
use Illuminate\Support\ServiceProvider;
use Workdo\CheckoutAttachment\app\Models\CheckoutAttachment;
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
        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('CheckoutAttachment')) {
                $store      = $view->getData()['store'] ?? null;
                if (!$store && \Auth::user()) {
                    $store = Store::find(getCurrentStore());
                }
                $enable_product_barcode = \App\Models\Utility::GetValueByName('enable_product_barcode', $store->id);
                $setting = $view->getData()['setting'] ?? null;
                $view->getFactory()->startPush('appSettingEnableBtn', view('checkout-attachment::attachmentbutton', compact('setting')));
            }
        });
        view()->composer(['front_end.pages.checkout'], function ($view) {
            $store = $view->getData()['store'];
            if (module_is_active('CheckoutAttachment')) {
                $store = $view->getData()['store'];
                $slug = $store->slug;
                if ($store) {
                    $enable_checkout_attachment = \App\Models\Utility::GetValueByName('enable_checkout_attachment', $store->id);
                    if (isset($enable_checkout_attachment) && $enable_checkout_attachment == 'on') {
                        $view->getFactory()->startPush('addCheckoutAttachment', view('checkout-attachment::create', compact('slug')));
                    }
                }
            }
        });

        view()->composer(['order.view', 'front_end.pages.order-detail'], function ($view) {
            if (module_is_active('CheckoutAttachment')) {
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
                if ($order_data) {
                    $checkoutAttachments = CheckoutAttachment::where('product_order_id', $order_data['product_order_id'])->get();
                    $enable_checkout_attachment = \App\Models\Utility::GetValueByName('enable_checkout_attachment', $store->id);
                    if (isset($enable_checkout_attachment) && $enable_checkout_attachment == 'on') {
                        $view->getFactory()->startPush('CheckoutAttachment', view('checkout-attachment::attachment', compact('checkoutAttachments')));
                    }
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
