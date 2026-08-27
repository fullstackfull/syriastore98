<?php

namespace Workdo\ProductBadgeManagement\app\Providers;

use App\Models\Plan;
use Illuminate\Support\Facades\Route;
use App\Models\Store;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\ServiceProvider;
use Workdo\ProductBadgeManagement\app\Models\BadgeSetting;
use Workdo\ProductBadgeManagement\app\Models\ImageBadge;
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
       
        view()->composer(['front_end.sections.product_sales', 'components.product-card'], function ($view) {

            $store = Store::where('slug', request()->route('storeSlug') ?? request()->segment(1))->first();
            if ($store) {
                $slug = $store->slug;
                if (module_is_active('ProductBadgeManagement')) {
                    $existingImageBadge = ImageBadge::where('store_id', $store->id)->first();
                    
                    $setting = $badgesetting = BadgeSetting::where('store_id', $store->id)->first();
                    $view->getFactory()->startPush('BadgeView', view('product-badge-management::pages.mainform', compact('slug', 'badgesetting', 'setting', 'existingImageBadge')));
                }
            }
        });

        view()->composer(['setting.index'], function ($view) {
            if (module_is_active('ProductBadgeManagement')) {
                $store = Store::find(getCurrentStore());
                if ($store) {
                    $activeTab = session()->get('setting_tab');
                    $view->getFactory()->startPush('settingTab', view('product-badge-management::pages.tab', compact('activeTab')));
                }
            }
        });
    }
}
