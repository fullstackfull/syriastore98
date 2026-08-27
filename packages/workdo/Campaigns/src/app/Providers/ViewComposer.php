<?php

namespace Workdo\Campaigns\app\Providers;

use App\Models\Store;
use App\Models\User;
use App\Models\Product;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;
use Workdo\Campaigns\app\Models\Campaigns;

class ViewComposer extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    // public $settings;
    public function boot(): void
    {

        view()->composer(['landing-page::layouts.landingpage', 'landing-page::layouts.tab'], function ($view) {
            $currentDate = \Carbon\Carbon::now();
            $productDetail = Campaigns::where('status', 1)->whereDate('campaigns.start_date', '<=', $currentDate)
            ->whereDate('campaigns.end_date', '>=', $currentDate)->get();
            $view->getFactory()->startPush('campaignsPage', view('campaigns::superAdmin.campaigns', compact('productDetail')));
            if (module_is_active('Campaigns')) {
                $view->getFactory()->startPush('campaignsTabForm', view('campaigns::superAdmin.campigns-setting'));
            }
        });
    }

    public function provides(): array
    {
        return [];
    }
}
