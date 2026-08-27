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
use Workdo\SpinToWinReward\app\Http\Controllers\SpinToWinRewardController;
use Workdo\SpinToWinReward\app\Http\Controllers\SpinToWinLabelController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:SpinToWinReward'])->group(function () {
    Route::resource('spintowinreward', SpinToWinRewardController::class)->names('spintowinreward')->except(['store','create']);
    Route::resource('spintowin-label', SpinToWinLabelController::class)->names('spintowin-label');
});
Route::middleware(['web'])->group(function () {
    Route::get('spintowinreward-create/{slug?}', [SpinToWinRewardController::class, 'create'])->name('spintowinreward.create');
    Route::post('spintowinreward-store/{slug?}', [SpinToWinRewardController::class, 'store'])->name('spintowinreward.store');
    Route::post('manage-winner-customer/{slug?}', [SpinToWinRewardController::class, 'WinnerCustomer'])->name('manage-winner-customer');
    Route::put('customer-email/update/email_template/{id}', [SpinToWinRewardController::class, 'updateCustomerEmailSettings'])->name('update.customer.email.settings');
    Route::get('customer-email/email_template_lang/{id}/{lang?}', [SpinToWinRewardController::class, 'manageCustomerEmailLang'])->name('manage.customer.email.language');

    Route::post('manage-customer-data/{slug?}', [SpinToWinRewardController::class, 'CustomerData'])->name('manage-customer-data');

});
