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
use Workdo\FreeShippingPopup\app\Http\Controllers\FreeShippingPopupController;

Route::middleware(['web'])->group(function () {
    Route::resource('freeshippingpopup', FreeShippingPopupController::class)->names('freeshippingpopup');
    Route::post('/free-shipping-popup-setting', [FreeShippingPopupController::class, 'freeShippingPopupSettings'])->name('free.shipping.popup.settings');
});
