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
use Workdo\ProductAffiliate\app\Http\Controllers\ProductAffiliateController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:ProductAffiliate'])->group(function () {
    Route::resource('productaffiliate', ProductAffiliateController::class)->names('productaffiliate');
    Route::get('affiliate/transactions', [ProductAffiliateController::class, 'transactionsData'])->name('affiliate.transactions.data');
    Route::get('affiliate/pay-requests', [ProductAffiliateController::class, 'payRequestsData'])->name('affiliate.payrequests.data');
});
Route::middleware(['web'])->group(function () {
    Route::get('request-amount-sent/{id}', [ProductAffiliateController::class, 'requestedAmountSent'])->name('request.amount.sent');
    Route::get('request-amount-cancel/{id}', [ProductAffiliateController::class, 'requestCancel'])->name('request.amount.cancel');
    Route::post('request-amount-store/{id}', [ProductAffiliateController::class, 'requestedAmountStore'])->name('request.amount.store');
    Route::get('request-amount/{id}/{status}', [ProductAffiliateController::class, 'requestedAmount'])->name('amount.request');
});
