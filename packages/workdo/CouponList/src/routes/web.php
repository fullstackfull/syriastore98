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

use App\Http\Middleware\ActiveTheme;
use Illuminate\Support\Facades\Route;
use Workdo\CouponList\app\Http\Controllers\CouponListController;

Route::middleware(['web'])->group(function () {    
    Route::resource('couponlist', CouponListController::class)->names('couponlist');

    Route::middleware([ActiveTheme::class, 'themelanguage'])->group(function () {
        Route::get('/{storeSlug}/coupons', [CouponListController::class, 'couponList'])->name('coupon.list.page');
    });
});