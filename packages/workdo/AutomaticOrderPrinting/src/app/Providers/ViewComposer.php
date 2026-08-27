<?php

namespace Workdo\AutomaticOrderPrinting\app\Providers;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\ServiceProvider;
use Workdo\AutomaticOrderPrinting\app\Models\AutomaticOrderPrinting;
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
            if (module_is_active('AutomaticOrderPrinting')) {
                $activeTab = session()->get('setting_tab');
                $view->getFactory()->startPush('settingTab', view('automatic-order-printing::pages.tab', compact('activeTab')));
            }
        });

        view()->composer(['order.view', 'front_end.sections.order_detail'], function ($view) {
            if (module_is_active('AutomaticOrderPrinting')) {
                $slug = $view->getData()['slug'] ?? '';
                $store = getStore($slug);
                if (empty($store)) {
                    $store = $view->getData()['store'];
                }
                if ($store) {
                    $user = User::find($store->created_by);
                    $order = $view->getData()['order'] ?? '';

                    $automaticOrderPrinting = AutomaticOrderPrinting::where('store_id', $store->id)->pluck('value', 'name')->toArray();
                    $enable_order_print = $automaticOrderPrinting['print_setting_enabled'] ?? '';
                    $view->getFactory()->startPush('invoice-button', view('automatic-order-printing::pages.button', compact('order', 'enable_order_print', 'slug')));
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
