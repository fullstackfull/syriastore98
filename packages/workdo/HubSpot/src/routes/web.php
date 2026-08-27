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
use  Workdo\HubSpot\app\Http\Controllers\HubSpotController;


Route::middleware(['auth', 'web', 'PlanModuleCheck:HubSpot'])->group(function () {
    Route::prefix('hubspot')->group(function () {
        Route::post('/setting/store', [HubSpotController::class, 'setting'])->name('hubspot.setting.store');
        Route::post('hub-spot-form', [HubSpotController::class, 'settingForm'])->name('hubspot.form');
        Route::get('/hubspot_index/{module?}', [HubSpotController::class, 'index'])->name('hubspot.index');
        Route::get('/sync/product', [HubSpotController::class, 'product'])->name('hubspot.product');
        Route::get('/product-service-create', [HubSpotController::class, 'create'])->name('hubspot.create');
        Route::any('/hubspot/sync/{productId}', [HubSpotController::class, 'syncProduct'])->name('hubspot.sync');
    });
});
