<?php

namespace Workdo\LotteryPrizes\app\Providers;

use App\Models\EmailTemplate;
use App\Models\EmailTemplateLang;
use App\Models\Product;
use App\Models\Store;
use App\Models\Utility;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Workdo\LotteryPrizes\app\Http\Controllers\LotteryPrizesController;
use Workdo\LotteryPrizes\app\Models\LotterySetting;

class Viewcomposer extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */

    public function boot()
    {

        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('LotteryPrizes')) {
                $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                $store = Store::find(getCurrentStore());
                if ($store) {
                    $setting = $view->getData()['setting'] ?? null;
                    $view->getFactory()->startPush('appSettingEnableBtn', view('lottery-prizes::admin.lotterybutton', compact('setting')));
                }
            }
        });
        view()->composer(['main_file'], function ($view) {
            if (module_is_active('LotteryPrizes')) {
                $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                $store = getStore($slug);
                $currentTheme = $store->theme_id;

                date_default_timezone_set('Asia/Kolkata');
                $storeId = getCurrenctStoreId($slug);

                $currentDateTime = \Carbon\Carbon::now();

                $settings = LotterySetting::where('created_by', $store->created_by)
                    ->where('store_id', $storeId)
                    ->get();

                $activeLotteries = [];

                foreach ($settings as $setting) {
                    $lotteryStartDateTime = \Carbon\Carbon::parse($setting->lottery_start_date . ' ' . $setting->lottery_start_time);
                    $lotteryEndDateTime = \Carbon\Carbon::parse($setting->lottery_end_date . ' ' . $setting->lottery_end_time);

                    if ($lotteryEndDateTime < $lotteryStartDateTime) {
                        $lotteryEndDateTime->addDay();
                    }

                    if ($currentDateTime->between($lotteryStartDateTime, $lotteryEndDateTime)) {
                        
                        $activeLotteries[] = $setting->lottery_products;
                        
                    }
                }
                $enable_lottery_button = Utility::GetValueByName('enable_lottery_button', $store->theme_id, $store->id);
                $products = Product::whereIn('id', $activeLotteries)->where('product_type', null)->where('store_id', $store->id)->get();
                if (isset($enable_lottery_button) && $enable_lottery_button == 'on') {
                    $view->getFactory()->startPush('ProductSlider', view('lottery-prizes::theme.product_slider', compact('products', 'store', 'slug', 'currentTheme')));
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
