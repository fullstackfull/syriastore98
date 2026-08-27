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
use Workdo\ProductCompare\app\Http\Controllers\ProductCompareController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:ProductCompare'])->group(function () {
    Route::resource('productcompare', ProductCompareController::class)->names('productcompare');
});
Route::middleware(['web'])->group(function () {
    Route::get('{slug}/product-compare-list', [ProductCompareController::class, 'product_compare'])->name('product_compare_list');
    Route::get('/{storeSlug}/product_compare/{product_slug}', [ProductCompareController::class, 'addProductCompare'])->name('product_compare');
    Route::any('/{storeSlug}/product_compare_remove/{id}', [ProductCompareController::class, 'remove'])->name('product.compare.remove');
});
