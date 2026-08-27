<?php

namespace Workdo\RecentlyViewedProducts\app\Providers;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\ServiceProvider;
use Workdo\RecentlyViewedProducts\app\Models\RecentlyViewedProduct;
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
        view()->composer(['setting.index'], function ($view) {
            if (module_is_active('RecentlyViewedProducts')) {
                $activeTab = session()->get('setting_tab');
                $view->getFactory()->startPush('settingTab', view('recently-viewed-products::pages.tab', compact('activeTab')));
            }
        });

        view()->composer(['front_end.layouts.app'], function ($view) {
            $store = Store::where('slug', request()->segment(1))->first();
            if (!$store) {
                $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                $store = getStore($slug);
            }
            if ($store) {
                $slug = $store->slug;
                $enable_recently_view_product = \App\Models\Utility::GetValueByName('enable_recently_view_product', $store->id);
                if (isset($enable_recently_view_product) && $enable_recently_view_product == 'on') {
                    $products = RecentlyViewedProduct::where('store_id', $store->id)->get();
                    $setting = getAdminAllSetting($store->created_by, $store->id);
                    $view->getFactory()->startPush('recentViewModelPopup', view('recently-viewed-products::pages.model_popup', compact('slug', 'products', 'store', 'setting')));
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
