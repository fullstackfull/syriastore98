<?php

namespace Workdo\CartQuantityControl\app\Providers;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\ServiceProvider;

class ViewComposer extends ServiceProvider
{
    public function boot()
    {
        view()->composer(['AppSetting.index' , 'product.create','product.edit' , 'product.attribute_combinations'], function ($view) {

            if (module_is_active('CartQuantityControl')) {

                $setting = $view->getData()['setting'] ?? null;

                if (isset($setting) && !empty($setting)) {
                    $view->getFactory()->startPush('appSettingEnableBtn', view('cart-quantity-control::admin.cartQuantityControlButton', compact('setting')));
                }
                $user = \Auth::User();
                $store = getStoreById($user->current_store);
                $setting = getAdminAllSetting($store->created_by, $store->id);
                $cart_quantity_control_status = isset($setting['cart_quantity_control_enable']) ? $setting['cart_quantity_control_enable'] : 'off';
                $product = $view->getData()['product']?? null;

                if($cart_quantity_control_status == 'on'){
                    $view->getFactory()->startPush('addCartQuantityControlFilds', view('cart-quantity-control::pages.addCartQuantityControlFilds'));
                    $view->getFactory()->startPush('editCartQuantityControlFilds', view('cart-quantity-control::pages.editCartQuantityControlFilds',compact('product')));
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
