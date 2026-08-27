<?php

namespace Workdo\AdditionalFieldInCheckout\app\Providers;

use App\Models\Store;
use Illuminate\Support\ServiceProvider;
use Workdo\AdditionalFieldInCheckout\app\Models\AdditionalField;
use Workdo\AdditionalFieldInCheckout\app\Models\CheckoutAdditionalField;
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

        view()->composer(['front_end.pages.checkout', 'front_end.pages.order-detail', 'order.view'], function ($view) {
            if (module_is_active('AdditionalFieldInCheckout')) {
                $setting = $view->getData()['setting'] ?? null;
                $slug      = $view->getData()['slug'] ?? null;
                $store      = getStore($slug);
                if (empty($store)) {
                    $store = $view->getData()['store'];
                }
                if ($store) {
                    $custom_fields  = AdditionalField::where('store_id', $store->id)->get();
                    $enable_additional_field = \App\Models\Utility::GetValueByName('enable_additional_field', $store->id);
                    if (isset($enable_additional_field) && $enable_additional_field == 'on') {
                        $view->getFactory()->startPush('CustomFieldView', view('additional-field-in-checkout::theme.custom_field', compact('custom_fields')));

                        $order = $view->getData()['order'] ?? null;
                        $order_id = $order['order_id'] ?? null;
                        $order_id = str_replace('#', '', $order_id) ?? null;
                        $fields_data = CheckoutAdditionalField::where('status', 'on')->where('order_id', $order_id)->where('store_id', $store->id)->get();
                        $view->getFactory()->startPush('ViewAdditionalFields', view('additional-field-in-checkout::theme.view_custom_field', compact('fields_data')));
                    }
                }
            }
        });

        view()->composer(['AppSetting.index'], function ($view) {
            if(module_is_active('AdditionalFieldInCheckout'))
            {
                $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                $store = getStoreById(getCurrentStore());
                if ($store) {
                    $setting = $view->getData()['setting'] ?? null;
                    $view->getFactory()->startPush('appSettingEnableBtn', view('additional-field-in-checkout::admin.additionalbutton', compact('setting')));
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
