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
use Workdo\ProductBarCode\app\Http\Controllers\ProductBarCodeController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:ProductBarCode'])->group(function () {
    Route::resource('productbarcode', ProductBarCodeController::class)->names('productbarcode');
    Route::resource('product-barcode', ProductBarCodeController::class);
});
Route::middleware(['web'])->group(function () {
    Route::post('barcode-settings', [ProductBarCodeController::class, 'BarCodeSettings'])->name('barcode.setting');
    Route::get('barcode-type', [ProductBarCodeController::class, 'BarCodeType'])->name('barcode-type');
    Route::get('search-products-barcode', [ProductBarCodeController::class, 'searchProductsBarcode'])->name('search.products.barcode');

});
