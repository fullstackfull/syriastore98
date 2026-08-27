<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

use Illuminate\Support\Facades\Route;
use Workdo\Campaigns\app\Http\Controllers\CampaignPaymentController;
use Workdo\Campaigns\app\Http\Controllers\CampaignsController;
use Workdo\Campaigns\app\Http\Controllers\settingmenuController;

Route::middleware(['web'])->group(function () {
    Route::prefix('campaigns')->group(function () {

        Route::group(['middleware' => 'PlanModuleCheck:Campaigns'], function () {
            Route::group([], function () {
                Route::resource('campaigns', CampaignsController::class)->names('campaigns');
            });

            Route::resource('campaigns-system-setup', settingmenuController::class)->names('campaigns.system.setup');
            Route::get('campaigns-settings', [CampaignsController::class, 'campaignsSetup'])->name('campaigns.setting');
            Route::any('campaigns-cost', [CampaignsController::class, 'campaignsCost'])->name('campaigns.cost-setting'); // for save cost setting
            Route::post('campaigns-product', [CampaignsController::class, 'productData'])->name('campaigns.product');
            Route::post('campaigns-costing', [CampaignsController::class, 'costData'])->name('campaigns.costing');
            Route::get('applycouponpromote', [CampaignsController::class, 'applyCouponPromote'])->name('apply.coupon.promote');
            Route::any('campaigns-card-payment/', [CampaignsController::class, 'paymentSuccess'])->name('promote.success');
            Route::get('campaigns-analytics/{id}', [CampaignsController::class, 'productAnalytics'])->name('campaigns.analytics');
            Route::get('campaigns-view/{id}', [CampaignsController::class, 'viewCampaigns'])->name('view.status.campaigns');
            Route::get('campaigns-status/{id}/{response}', [CampaignsController::class, 'ChangeStatus'])->name('change.status.campaigns');
            Route::post('campaigns-enable', [CampaignsController::class, 'campaignsEnable'])->name('campaigns.enable');
        });

        Route::get('campaigns-sort', [settingmenuController::class, 'campaginSorting'])->name('campaigns.sort'); //for sorting on landingpage
        Route::get('campaigns/share/{slug}', [settingmenuController::class, 'share'])->name('campaigns.share'); // for share
        Route::get('campaigns/contact-view/{id}', [settingmenuController::class, 'contactData'])->name('campaigns.contact'); // for contact

        //Powertranz payment
        Route::any('campaigns-pay-with/Powertranz', [CampaignPaymentController::class, 'CampaignPayWithPowertranz'])->name('campaigns.pay.with.Powertranz');
        Route::any('campaigns-Powertranz', [CampaignPaymentController::class, 'campaignsGetPowertranzStatus'])->name('campaigns.get.Powertranz.status');
        // DPO payment
        Route::any('campaigns-DPO/create', [CampaignPaymentController::class, 'create'])->name('campaigns.dpo.view');
        Route::any('campaigns-pay-with/DPO', [CampaignPaymentController::class, 'storePayWithDPO'])->name('campaigns.pay.with.dpo');
        Route::any('campaigns-get-DPO-status', [CampaignPaymentController::class, 'storeGetDPOStatus'])->name('campaigns.get.dpo.status');
        // payU payment
        Route::any('campaigns-payu-payment/{pay}', [CampaignPaymentController::class, 'pay'])->name('campaigns.payu');
        //braintree payment
        Route::any('campaigns-braintree-payment', [CampaignPaymentController::class, 'payBraintree'])->name('campaigns.braintree');
    });
});
