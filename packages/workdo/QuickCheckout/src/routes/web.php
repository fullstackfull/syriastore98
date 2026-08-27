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
use Workdo\QuickCheckout\app\Http\Controllers\QuickCheckoutController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:QuickCheckout'])->group(function () {
    Route::resource('quickcheckout', QuickCheckoutController::class)->names('quickcheckout');
});
Route::middleware(['web'])->group(function () {
    Route::post('store-quick-checkout', [QuickCheckoutController::class, 'updateSettingButtonData'])->name('update.quick.checkout.settings');
});
