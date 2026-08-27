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
use Workdo\SkipCart\app\Http\Controllers\SkipCartController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:SkipCart'])->group(function () {
    Route::resource('skipcart', SkipCartController::class)->names('skipcart');
});
Route::middleware(['web'])->group(function () {
    Route::post('skip-cart-settings', [SkipCartController::class, 'SkipCartSettings'])->name('save.skip.cart.settings');
    Route::get('{storeSlug}/on-page-checkout', [SkipCartController::class, 'OnPagecheckout'])->name('on.page.checkout');

    Route::post('/skip-empty-cart', [SkipCartController::class, 'emptyCart'])->name('skip.cart.empty');
    Route::post('{storeSlug}/checkout-cart', [SkipCartController::class, 'CartView'])->name('CheckoutView');

});
