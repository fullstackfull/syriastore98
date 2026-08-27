<?php

namespace Workdo\FrequentlyProduct\app\Providers;

use Illuminate\Support\Facades\Route;
use App\Models\Product;
use App\Models\Store;
use App\Models\Utility;
use workdo\FrequentlyProduct\app\Models\FrequentlyProduct;
use App\Models\Plan;
use App\Models\User;
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
        view()->composer(['setting.index'], function ($view) {
            if (module_is_active('FrequentlyProduct')) {
                $activeTab = session()->get('setting_tab');
                $view->getFactory()->startPush('settingTab', view('frequently-product::pages.tab', compact('activeTab')));
            }
        });

        view()->composer(['front_end.pages.product-detail'], function ($view) {
            $store = Store::where('slug', request()->segment(1))->first();

            if (!$store) {
                $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                $store = getStore($slug);
            }
            $product_slug = \Request::segment(3);

            if ($store && $product_slug) {
                $slug = $store->slug;
                $user = User::find($store->created_by);
                if ($user) {

                    if (module_is_active('FrequentlyProduct')) {
                        $currency = Utility::GetValueByName('CURRENCY', $store->theme_id, $store->id);
                        $currentTheme = $store->theme_id;
                        $setting = getAdminAllSetting($user->id, $store->id);
                        $limit = $setting['frequently_product_limit'] ?? '2';
                        $product = Product::where('slug', $product_slug)->where('store_id', $store->id)->first();
                        if ($product) {
                            $w_product = Product::where('category_id', $product->category_id)->where('id', '!=', $product->id)->where('store_id', $store->id)->where('variant_product', 0)->inRandomOrder()->limit($limit)->get();
                            $merged_products = collect([$product])->merge($w_product);


                            $total_price = $merged_products->sum('sale_price');
                            $product_ids = $merged_products->pluck('id')->toArray();


                            $tax_price = $total_orignal_price = 0;
                            $customer_id = auth('customers')->user()->id ?? 0;
                            $DeliveryAddress = \App\Models\DeliveryAddress::where('customer_id', $customer_id)->first();
                            $city_id = isset($DeliveryAddress->city_id) ? $DeliveryAddress->city_id : '';
                            $state_id = isset($DeliveryAddress->state_id) ? $DeliveryAddress->state_id : '';
                            $country = isset($DeliveryAddress->country_id) ? $DeliveryAddress->country_id : '';

                            foreach ($merged_products as $product) {
                                if (isset($product->tax_id)) {
                                    $tax_price += \App\Models\Cart::getProductTaxAmount($product->tax_id, $product->sale_price, $store->id, $store->theme_id, $city_id, $state_id, $country, true);
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
                            $view->getFactory()->startPush('frequentlyproductslider', view('frequently-product::pages.frequently_product_slider', compact('merged_products', 'slug', 'currentTheme', 'store', 'total_price', 'currency', 'product_ids', 'tax_price')));
                        }
                    }
                }
            }
        });
    }

}
