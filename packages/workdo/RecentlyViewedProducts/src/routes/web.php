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
use Workdo\RecentlyViewedProducts\app\Http\Controllers\RecentlyViewedProductsController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:RecentlyViewedProducts'])->group(function () {
    Route::resource('recently-view', RecentlyViewedProductsController::class);
    Route::post('recently-view-setting-form', [RecentlyViewedProductsController::class, 'settingForm'])->name('recently-viewed.form');
    Route::post('recently-view-setting', [RecentlyViewedProductsController::class, 'recentlyViewSetting'])->name('recently-view.settings');
});
Route::middleware(['web'])->group(function () {
});
