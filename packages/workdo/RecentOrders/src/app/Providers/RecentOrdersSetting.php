<?php

namespace Workdo\RecentOrders\app\Providers;

use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cache;

class RecentOrdersSetting extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */

    public function boot()
    {
        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('RecentOrders')) {
                $store = Store::find(getCurrentStore());
                if ($store) {
                    $setting = getAdminAllSetting($store->created_by, $store->id);
                    if (isset($setting) && !empty($setting)) {
                        $app_setting_tab = session()->get('app_setting_tab');
                        if (empty($app_setting_tab)) {
                            $app_setting_tab = 'pills-home-tab';
                        }
                        $view->getFactory()->startPush('appSettingTab', view('recent-orders::admin.recentOrdersSettingTab', compact('setting', 'app_setting_tab')));
                        $view->getFactory()->startPush('appSettingTabForm', view('recent-orders::admin.recentOrdersSettingDetails', compact('setting', 'app_setting_tab')));
                    }
                }
            }
        });

        view()->composer(['main_file', 'front_end.sections.pages.cart', 'front_end.sections.pages.product', 'front_end.sections.pages.product_list'], function ($view) {
            if (module_is_active('RecentOrders')) {
                $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                $store = getStore($slug);
                $setting = getAdminAllSetting($store->created_by, $store->id);
                $recent_orders_status = isset($setting['recent_orders_enabled']) ? $setting['recent_orders_enabled'] : 'off';
                $recent_orders = isset($setting['recent_orders_enabled']) ? $setting['recent_orders'] : '0';
                $customer = auth('customers')->user() ?? null;
                
                if (isset($customer)) {
                    
                    $customer_recent_Orders = Order::where('customer_id', $customer->id)->where('store_id', $store->id)->orderBy('created_at', 'DESC')->limit($recent_orders)->get();
                    
                    if (!$customer_recent_Orders->isEmpty()) {
                        if ($recent_orders >= 1 && $recent_orders_status == 'on' && $customer_recent_Orders->count() >= 1) {
                        
                            
                            $allProductIds = [];
                            $ProductData = [];
                            foreach ($customer_recent_Orders as $order) {
                                $product_ids = explode(",", $order->product_id);
                                
                                $allProductIds = array_merge($allProductIds, $product_ids);
                            }

                            $uniqueProductIds = array_unique($allProductIds);

                            foreach ($uniqueProductIds as $product_id) {

                                $product = Product::find($product_id);
                                $ProductData[] = $product;
                            }
                            if ($store) {
                                $view->getFactory()->startPush('recentOrdersSlider', view('recent-orders::pages.recentOrdersSlider', compact('slug', 'customer_recent_Orders', 'ProductData', 'store')));
                            }
                        }
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
