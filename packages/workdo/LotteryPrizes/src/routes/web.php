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
use Workdo\LotteryPrizes\app\Http\Controllers\LotteryOrderController;
use Workdo\LotteryPrizes\app\Http\Controllers\LotteryPrizesController;
use Workdo\LotteryPrizes\app\Http\Controllers\LotteryResultController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:LotteryPrizes'])->group(function () {
    Route::resource('lotteryproduct', LotteryPrizesController::class)->names('lotterysetting');
    Route::resource('lotteryresult', LotteryResultController::class)->names('lotteryresult');
    Route::resource('lotteryorder', LotteryOrderController::class)->names('lotteryorder');

    /** Email Templates Routes */
    Route::get('lottery_email_template_lang/{lang?}', [LotteryPrizesController::class, 'LotteryemailTemplate'])->name('lottery_email_template');

    Route::get('lottery_email_lang/{id}/{lang?}', [LotteryPrizesController::class, 'manageLotteryEmailLang'])->name('manage.lottery.email.language');
    Route::put('lottery_email_lang/{id}/', [LotteryPrizesController::class, 'updateLotteryEmailSettings'])->name('update.lottery.email.settings');

});

Route::middleware(['web'])->group(function () {
    Route::get('/{storeSlug}/lottery-product/{product_slug}', [LotteryPrizesController::class, 'lottery_product'])->name('page.lottery_product');
    Route::get('/{storeSlug}/lottery-form/{product_slug}', [LotteryPrizesController::class, 'lottery_data'])->name('lottery.form');
    Route::post('/{storeSlug}/lottery-form-data', [LotteryPrizesController::class, 'lottery_form_data'])->name('lottery-form.data.store');

    Route::get('/{storeSlug}/refund-lottery-form/{product_slug}', [LotteryPrizesController::class, 'refund_lottery'])->name('refund.lottery.form');

    Route::post('/{storeSlug}/refund-lottery-form-data', [LotteryPrizesController::class, 'refund_lottery_form_data'])->name('refund-lottery-form.data.store');

    Route::get('{storeSlug}/store-lottery-payment', [LotteryPrizesController::class, 'StoreLotteryPayment'])->name('store.lottery.payment');
    Route::any('{storeSlug}/get-ticket-payment-paytm', [LotteryPrizesController::class, 'StoreLotteryPayment'])->name('store.ticket.payment.paytm');
    Route::any('{storeSlug}/get-ticket-payment-aamarpay', [LotteryPrizesController::class, 'StoreLotteryPayment'])->name('store.ticket.payment.aamarpay');
    Route::any('{storeSlug}/get-ticket-payment-midtrans', [LotteryPrizesController::class, 'StoreLotteryPayment'])->name('store.ticket.payment.midtrans');
    Route::any('{storeSlug}/store-ticket-payment-khalti', [LotteryPrizesController::class, 'StoreLotteryPayment'])->name('store.ticket.payment.khalti');
    Route::any('{storeSlug}/store-ticket-payment-authorized', [LotteryPrizesController::class, 'StoreLotteryPayment'])->name('store.ticket.payment.authorized');

    Route::post('{storeSlug}/ticket-get-massage', [LotteryPrizesController::class, 'getWhatsappUrl'])->name('ticket.get.whatsappurl');
    Route::any('{storeSlug?}/ticket-telegram', [LotteryPrizesController::class, 'StoreLotteryPayment'])->name('ticket.customer.telegram');
    Route::any('{storeSlug?}/ticket-whatsapp', [LotteryPrizesController::class, 'StoreLotteryPayment'])->name('ticket.customer.whatsapp');

    Route::get('/{storeSlug?}/ticket-success', [LotteryPrizesController::class, 'showSuccessPage'])->name('ticket.customer.success');

    Route::get('lottery-order-view/{id}', [LotteryOrderController::class,'order_view'])->name('lottery.order.view');

    Route::post('lottery-order-status-change/{id}', [LotteryOrderController::class,'order_status_change'])->name('lottery.order.status.change')->middleware('themelanguage');
    Route::get('lottery/shippinglabel/pdf/{id}', [LotteryOrderController::class,'shippinglabel'])->name('lottery.shippinglabel.pdf');
    Route::get('lottery-order-receipt/{id}' ,[LotteryOrderController::class,'order_receipt'])->name('lottery.order.receipt')->middleware('themelanguage');
    Route::post('lottery-order-return', [LotteryOrderController::class,'order_return'])->name('lottery.order.return')->middleware('themelanguage');
});
