<?php

namespace Workdo\BoostSales\app\Providers;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Models\Utility;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cache;

class ViewComposer extends ServiceProvider
{

    public $settings;

    public function boot()
    {

        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('BoostSales')) {
                $setting = Setting::where('store_id', getCurrentStore())->pluck('value', 'name')->toArray();
                if (isset($setting) && !empty($setting)) {
                    $products = Product::where('store_id', getCurrentStore())->where('product_type', null)->where('variant_product', 0)->get();
                    $app_setting_tab = session()->get('app_setting_tab');
                    if (empty($app_setting_tab)) {
                        $app_setting_tab = 'pills-home-tab';
                    }
                    $view->getFactory()->startPush('appSettingTab', view('boost-sales::admin.boostSalesSettingTab', compact('setting', 'app_setting_tab')));
                    $view->getFactory()->startPush('appSettingTabForm', view('boost-sales::admin.boostSalesSettingDetails', compact('setting', 'products', 'app_setting_tab')));
                }
            }
        });

        view()->composer(['front_end.hooks.footer_link'], function ($view) {
            if (module_is_active('BoostSales')) {
                $store = Store::where('slug', request()->segment(1))->first();
                if (!$store) {
                    $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                    $store = getStore($slug);
                } else {
                    $slug = request()->segment(1);
                }
                if ($store) {
                    $user = User::find($store->created_by);
                    $setting = getAdminAllSetting($user->id, $store->id);
                    if (isset($setting['boost_sales_enabled']) && $setting['boost_sales_enabled'] == 'on') {
                        $currency = Utility::GetValueByName('CURRENCY', $store->id);
                        $currentTheme = $store->theme_id;
                        $boost_sale_product_id = isset($setting['boost_sale_product_id']) && !empty($setting['boost_sale_product_id']) ? explode(',', $setting['boost_sale_product_id']) : [];

                        if ($setting['boost_sales_product_display'] == "selected product") {
                            $products = Product::whereIn('id', $boost_sale_product_id)->where('store_id', $store->id)->where('variant_product', 0)->get();
                        } elseif ($setting['boost_sales_product_display'] == "default product") {
                            $orders = Order::where('store_id', $store->id)->get();
                            $productSales = [];
                            foreach ($orders as $order) {
                                // Decode the product_json column
                                $products = json_decode($order->product_json, true);
                                // Aggregate the quantity sold for each product
                                if (is_array($products)) {
                                    foreach ($products as $product) {
                                        if (is_array($product) && isset($product['product_id'])) {
                                            $productId = $product['product_id'];
                                            $quantity = $product['qty'] ?? $product['quantity'] ?? 0;
                                            if (!isset($productSales[$productId])) {
                                                $productSales[$productId] = 0;
                                            }
                                            $productSales[$productId] += $quantity;
                                        }
                                    }
                                }
                            }
                            // Sort products by quantity sold in descending order and get the top 3
                            arsort($productSales);
                            $topProductIds = array_slice(array_keys($productSales), 0, 3, true);
                            // Fetch product details for the top 3 products
                            $topProducts = Product::whereIn('id', $topProductIds)->get();
                            // Map the sales data to the products for display
                            $products = $topProducts->map(function ($product) use ($productSales) {
                                $product->total_sold = $productSales[$product->id];
                                return $product;
                            });
                        } else {
                            $orders = Order::where('store_id', $store->id)->get();
                            $productSales = [];
                            foreach ($orders as $order) {
                                // Decode the product_json column
                                $products = json_decode($order->product_json, true);
                                // Aggregate the quantity sold for each product
                                if (is_array($products)) {
                                    foreach ($products as $product) {
                                        if (is_array($product) && isset($product['product_id'])) {
                                            $productId = $product['product_id'];
                                            $quantity = $product['qty'] ?? $product['quantity'] ?? 0;
                                            if (!isset($productSales[$productId])) {
                                                $productSales[$productId] = 0;
                                            }
                                            $productSales[$productId] += $quantity;
                                        }
                                    }
                                }
                            }
                            // Sort products by quantity sold in descending order and get the top 3
                            arsort($productSales);
                            $topProductIds = array_slice(array_keys($productSales), 0, 3, true);
                            // Fetch product details for the top 3 products
                            $topProducts = Product::whereIn('id', $topProductIds)->get();
                            // Map the sales data to the products for display
                            $default_products = $topProducts->map(function ($product) use ($productSales) {
                                $product->total_sold = $productSales[$product->id];
                                return $product;
                            });
                            $products = Product::whereIn('id', $boost_sale_product_id)->where('store_id', $store->id)->where('variant_product', 0)->get();
                            $products = $default_products->merge($products)->unique('id');
                        }
                        $total_price = $products->sum('sale_price');
                        $product_ids = $products->pluck('id')->toArray();

                        $tax_price = $total_orignal_price = 0;
                        $customer_id = auth('customers')->user()->id ?? 0;
                        $DeliveryAddress = \App\Models\DeliveryAddress::where('customer_id', $customer_id)->first();
                        $city_id = isset($DeliveryAddress->city_id) ? $DeliveryAddress->city_id : '';
                        $state_id = isset($DeliveryAddress->state_id) ? $DeliveryAddress->state_id : '';
                        $country = isset($DeliveryAddress->country_id) ? $DeliveryAddress->country_id : '';

                        foreach ($products as $product) {
                            if (isset($product->tax_id)) {
                                $tax_price += \App\Models\Cart::getProductTaxAmount($product->tax_id, $product->sale_price, $store->id, $city_id, $state_id, $country, true);
                            } else {
                                $tax_price += 0;
                            }
                        }
                        $tax_option = \App\Models\TaxOption::where('store_id', $store->id)
                            ->pluck('value', 'name')->toArray();

                        if (isset($tax_option['round_tax']) && $tax_option['round_tax'] == 1) {
                            $tax_price = round($tax_price);
                        }

                        if (isset($product)) {
                            if ($product->tax_id !== null && $customer_id != 0) {
                                $data['store_id'] = $store->id;
                                $data['product_tax_id'] = $product->tax_id;
                                $data['sub_total'] = $total_price;
                                $data['product_original_price'] = $product['sale_price'];
                                $taxes = \App\Models\Tax::TaxCount($data);
                                $tax_price = SetNumber($taxes['total_tax_price']);
                                $tax_rate = $taxes['tax_rate'] ?? 0;
                                $tax_name = $taxes['tax_name'] ?? null;
                            }
                        }
                        if (count($product_ids) != 0) {
                            $view->getFactory()->startPush('boostSalesModelPopup', view('boost-sales::pages.boostSalesModelPopup', compact('slug', 'products', 'store', 'setting', 'currency', 'currentTheme', 'product_ids', 'total_price', 'tax_price')));
                        }
                    }
                }
            }
        });
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        //
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [];
    }
}
