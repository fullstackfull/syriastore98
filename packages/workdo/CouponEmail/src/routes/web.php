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
use Workdo\CouponEmail\app\Http\Controllers\CouponEmailController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:CouponEmail'])->group(function () {
    Route::resource('couponemail', CouponEmailController::class)->names('couponemail');
    Route::post('couponemail-settings', [CouponEmailController::class, 'saveCouponEmailSettings'])->name('couponemail.settings');
    Route::post('get-couponemail-fields', [CouponEmailController::class , 'getCouponEmailFields'])->name('get.couponemail.fields');
    Route::post('coupon-email-setting-form', [CouponEmailController::class, 'settingForm'])->name('coupon-email.form');
});

