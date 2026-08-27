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
use Workdo\ProductQuickView\app\Http\Controllers\ProductQuickViewController;

Route::middleware([ 'web','PlanModuleCheck:ProductQuickView'])->group(function () {
    Route::get('/{storeSlug}/product-quick-view/{product_slug}', [ProductQuickViewController::class, 'productQuickViewModel'])->name('product_quick_view.model');
});