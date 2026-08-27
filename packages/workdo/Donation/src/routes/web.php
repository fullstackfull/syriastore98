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
use Workdo\Donation\app\Http\Controllers\CustomerDonationController;
use Workdo\Donation\app\Http\Controllers\DonationController;
use Workdo\Donation\app\Http\Controllers\DonationHistoryController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:Donation'])->group(function () {
    Route::resource('donation', DonationController::class)->names('donation');
    Route::resource('donationhistory', DonationHistoryController::class)->names('donationhistory');
    Route::get('donation-data/export', [DonationHistoryController::class, 'fileExport'])->name('donationhistory.export');
});
Route::middleware(['web'])->group(function () {
    Route::post('store-donation', [DonationController::class, 'updateDonationData'])->name('update.store.donation.settings');

    Route::get('{storeSlug}/customer-donation-create', [CustomerDonationController::class, 'create'])->name('customerdonation.create');
    Route::post('{storeSlug}/get-donation-amount', [CustomerDonationController::class, 'get_donation_amount'])->name('get.donation.amount');
    Route::post('{storeSlug}/customer-donation-store', [CustomerDonationController::class, 'store'])->name('customerdonation.store');
    Route::get('{storeSlug}/store-donation-payment', [CustomerDonationController::class, 'StoreDonationPayment'])->name('store.donation.payment');
    Route::any('{storeSlug}/get-donation-payment-paytm', [CustomerDonationController::class, 'StoreDonationPayment'])->name('store.paytm.payment.paytm');
    Route::any('{storeSlug}/get-donation-payment-iyzipay', [CustomerDonationController::class, 'StoreDonationPayment'])->name('store.paytm.payment.iyzipay');
    Route::any('{storeSlug}/get-donation-payment-aamarpay', [CustomerDonationController::class, 'StoreDonationPayment'])->name('store.paytm.payment.aamarpay');
    Route::any('{storeSlug}/get-donation-payment-midtrans', [CustomerDonationController::class, 'StoreDonationPayment'])->name('store.donation.payment.midtrans');

    Route::post('{storeSlug}/donation-get-massage', [CustomerDonationController::class, 'getWhatsappUrl'])->name('donation.get.whatsappurl');
    Route::any('{storeSlug?}/donation-telegram', [CustomerDonationController::class, 'StoreDonationPayment'])->name('donation.customer.telegram');
    Route::any('{storeSlug?}/donation-whatsapp', [CustomerDonationController::class, 'StoreDonationPayment'])->name('donation.customer.whatsapp');
    Route::any('{storeSlug}/store-donation-payment-khalti', [CustomerDonationController::class, 'StoreDonationPayment'])->name('store.donation.payment.khalti');
    Route::get('/{storeSlug?}/donation-success', [CustomerDonationController::class, 'showSuccessPage'])->name('donation.customer.success');
    Route::any('{storeSlug}/store-donation-payment-authorized', [CustomerDonationController::class, 'StoreDonationPayment'])->name('store.donation.payment.authorized');
});
