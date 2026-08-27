<?php

namespace Workdo\AuctionProduct\app\Providers;

use App\Models\Store;
use App\Models\User;
use App\Models\Utility;
use Illuminate\Support\ServiceProvider;
use Workdo\AuctionProduct\app\Models\AuctionProductBid;
use Workdo\AuctionProduct\app\Models\AuctionProductOrder;
use Illuminate\Support\Facades\Cache;

class AuctionProductOrderProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */

    public function boot()
    {
        view()->composer(['front_end.sections.pages.my_account'], function ($view) {
            if (module_is_active('AuctionProduct')) {
            $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
            $store = getStore($slug);
            $user = User::where('current_store', $store->id)->where('type', 'admin')->first();
            $user_id = !empty($user->id) ? $user->id : 0;
            $currency = Utility::GetValueByName('CURRENCY', $store->id);
            $orders = AuctionProductOrder::where('customer_id', auth('customers')->user()->id)->where('store_id', $store->id)->paginate(10);

            $bid_product = AuctionProductBid::where('customer_id', auth('customers')->user()->id)->where('store_id', $store->id)->paginate(10);
            $enable_auction_product = \App\Models\Utility::GetValueByName('enable_auction_product', $store->id);
            if(isset($enable_auction_product) && $enable_auction_product == 'on')
            {
                $view->getFactory()->startPush('auctionproductordersTab', view('auction-product::auctionorder.order_button', compact('store', 'slug')));
                $view->getFactory()->startPush('auctionproductordersView', view('auction-product::auctionorder.tabs_view', compact('store', 'slug', 'orders', 'currency')));
                $view->getFactory()->startPush('auctionproductbidingView', view('auction-product::auctionorder.biding_view', compact('store', 'slug', 'orders', 'currency', 'bid_product')));
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
