<?php

namespace Workdo\PurchaseNotification\app\Providers;

use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Collection;
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
            if (module_is_active('PurchaseNotification')) {
                $setting = getAdminAllSetting(auth()->user()->id, getCurrentStore());
                $order_product_id = Order::where('store_id', getCurrentStore())->get()->pluck('product_id');
                $allProductIds = new Collection();
                foreach ($order_product_id as $productIds) {
                    $productIdsArray = explode(',', $productIds);
                    $allProductIds = $allProductIds->merge($productIdsArray);
                }
                $uniqueProductIds = $allProductIds->unique()->values();

                $products = Product::whereIn('id', $uniqueProductIds)->where('store_id', getCurrentStore())->get();
                $app_setting_tab = session()->get('app_setting_tab');
                if (empty($app_setting_tab)) {
                    $app_setting_tab = 'pills-home-tab';
                }
                $view->getFactory()->startPush('appSettingTab', view('purchase-notification::admin.purchaseNotificationSettingTab', compact('setting','app_setting_tab')));
                $view->getFactory()->startPush('appSettingTabForm', view('purchase-notification::admin.purchaseNotificationSettingDetails', compact('setting', 'products','app_setting_tab')));
            }
        });
        view()->composer(['front_end.layouts.app'], function ($view) {
            if (module_is_active('PurchaseNotification')) {
                $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                $store = getStore($slug);
                if ($store) {               
                    $setting = getAdminAllSetting($store->created_by, $store->id);
                    $product_ids = isset($setting['product_id']) && !empty($setting['product_id']) ? explode(',', $setting['product_id']) : [];
                    $products = Product::whereIn('id', $product_ids)->get();

                    if (count($product_ids) != 0 && isset($setting['purchase_notification_enabled']) && $setting['purchase_notification_enabled'] == 'on') {
                        
                        $view->getFactory()->startPush('purchaseNotificationPopup', view('purchase-notification::pages.model_popup', compact('slug', 'products', 'store', 'setting')));
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
