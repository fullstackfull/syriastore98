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
use Workdo\FrequentlyProduct\app\Http\Controllers\FrequentlyProductController;


Route::middleware(['web'])->group(function () {

    Route::group([], function () {
        Route::resource('frequentlyproduct', FrequentlyProductController::class)->names('frequentlyproduct');
        Route::post('frequently-product-setting', [FrequentlyProductController::class, 'frequentlySetting'])->name('frequently-product.settings');
        Route::any('frequently-product-setting-form', [FrequentlyProductController::class, 'settingForm'])->name('frequently-product-setting.form');
    });

    Route::post('{storeSlug}/frequently_product_cart', [FrequentlyProductController::class, 'product_cartlist'])->name('cart.product-data');
    Route::post('{storeSlug}/frequently_product_price', [FrequentlyProductController::class, 'product_price'])->name('get.sale.price');
});
