<?php

namespace Workdo\AuctionProduct\app\Providers;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\ServiceProvider;
use Workdo\AuctionProduct\app\Models\AuctionProductSetting;
use Illuminate\Support\Facades\Cache;

class ViewComposer extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */

     public function boot(){
        view()->composer(['main_file'], function ($view) {
           
            if (module_is_active('AuctionProduct'))
            {
                $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                $store = getStore($slug);
                $currentTheme = $store->theme_id;
                date_default_timezone_set('Asia/Kolkata');
                $currentDateTime = \Carbon\Carbon::now();
                $auctionProductIds = [];
                $auctionProductSettings = AuctionProductSetting::where('is_active', 1)->get();

                foreach ($auctionProductSettings as $setting) {
                    $startDate = \Carbon\Carbon::parse($setting->start_date . ' ' . $setting->start_time);
                    $endDate = \Carbon\Carbon::parse($setting->end_date . ' ' . $setting->end_time);

                    if ($endDate < $startDate) {
                        $endDate->addDay();
                    }

                    $currentDateTimeClone = clone $currentDateTime;
                    $currentDateTime->setTimezone($startDate->getTimezone());
                    if ($currentDateTime >= $startDate && $currentDateTime <= $endDate) {
                        if ($setting->auction_product) {
                            $auctionProductIds = array_merge($auctionProductIds, explode(',', $setting->auction_product));
                        }
                    }
                }

                $auctionProductIds = array_unique($auctionProductIds);

                $auction_product = Product::where('product_type', 'auction')
                    ->where('store_id', $store->id)
                    ->whereIn('id', $auctionProductIds)
                    ->get();
                $enable_auction_product = \App\Models\Utility::GetValueByName('enable_auction_product', $store->id);
                if(isset($enable_auction_product) && $enable_auction_product == 'on')
                {
                    $view->getFactory()->startPush('auctionproductslider', view('auction-product::pages.auction_slider',compact('auction_product','store','slug','currentTheme')));
                }
             }

        });

        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('AuctionProduct')) {
                $store      = $view->getData()['store'] ?? null;
                if (!$store && \Auth::user()) {
                    $store = Store::find(getCurrentStore());
                }
                $setting = $view->getData()['setting'] ?? null;
                $view->getFactory()->startPush('appSettingEnableBtn', view('auction-product::pages.auctionproductbutton', compact('setting')));
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
