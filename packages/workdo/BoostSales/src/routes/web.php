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
use Workdo\BoostSales\app\Http\Controllers\BoostSalesController;

Route::middleware(['web'])->group(function () {
    Route::group([], function () {
        Route::resource('boostsales', BoostSalesController::class)->names('boostsales');
    });
    Route::post('/boost-sales-setting', [BoostSalesController::class, 'boostSalesSettings'])->name('boost.sales.settings');

    Route::post('{storeSlug}/boost-sales-cart', [BoostSalesController::class, 'product_cartlist'])->name('boost.sales.cart.product.data');
    Route::post('{storeSlug}/boost-sales-price', [BoostSalesController::class, 'product_price'])->name('boost.sales.get.sale.price');
});
