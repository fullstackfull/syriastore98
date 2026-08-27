<?php

namespace Workdo\StoreLocator\app\Providers;

use App\Models\User;
use Illuminate\Support\ServiceProvider;
use Workdo\StoreLocator\app\Models\StoreLocator;

class MarketplaceViewLocatorProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */

    public function boot()
    {
        view()->composer(['marketplacelist::marketplacelistpage'], function ($view) {
            if (module_is_active('MarketPlaceList')) {
                $store = $view->getData()['store'] ?? null;
                if ($store) {
                    $user      = User::find($store->created_by);
                    $locator_settings = StoreLocator::with('Storedata')->where('created_by', $user->id)->get();
                    if (isset($locator_settings) && $locator_settings->store_locator_enabled == 'on') {
                        $view->getFactory()->startPush('StoreLocatorAllStoreView', view('store-locator::theme.marketplace_locator_view', compact('locator_settings', 'store')));
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
