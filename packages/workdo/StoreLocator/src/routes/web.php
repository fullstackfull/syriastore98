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
use Workdo\StoreLocator\app\Http\Controllers\StoreLocatorController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:StoreLocator'])->group(function () {
    Route::resource('storelocator', StoreLocatorController::class)->names('storelocator');
    Route::post('store-locator', [StoreLocatorController::class, 'updateData'])->name('update.store.locator.settings');
    Route::post('store-locator-setting', [StoreLocatorController::class, 'updateSettingsData'])->name('update.locator.settings');
    Route::post('store-locator-setting-form', [StoreLocatorController::class, 'settingForm'])->name('store.locator.form');
});

