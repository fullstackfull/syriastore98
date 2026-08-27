<?php

namespace Workdo\SpamPrevent\app\Providers;

use App\Models\Product;
use App\Models\Utility;
use Illuminate\Support\ServiceProvider;
use Workdo\SpamPrevent\app\Models\ProductComment;
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
        view()->composer(['front_end.hooks.footer_link'], function ($view) {
            if (module_is_active('SpamPrevent')) {
                $store = $view->getData()['store'] ?? null;
                if ($store) {
                    $customer = auth('customers')->user() ?? null;
                    $products = Product::where('product_type', null)->where('store_id', $store->id)->pluck('name', 'id')->prepend('Select Product', '');
                    $enable_spam_prevent = Utility::GetValueByName('enable_spam_prevent', $store->id);
                    if (isset($customer)) {
                        if (isset($enable_spam_prevent) && $enable_spam_prevent == 'on') {
                            $comments = ProductComment::where('customer_id', $customer->id)->where('store_id', $store->id)->get();
                            $view->getFactory()->startPush('CommmetIconView', view('spam-prevent::theme.icon_view', compact('products', 'store', 'comments')));
                        }
                    }
                }
            }
        });
        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('SpamPrevent')) {
                $setting = $view->getData()['setting'] ?? null;
                $store = getStoreById(getCurrentStore());
                if ($store) {
                    $view->getFactory()->startPush('appSettingEnableBtn', view('spam-prevent::admin.enable_button', compact('setting')));
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
