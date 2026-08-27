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
use Workdo\ProductBadgeManagement\app\Http\Controllers\BadgeController;
use Workdo\ProductBadgeManagement\app\Http\Controllers\ImageBadgeController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:productbadgemanagement'])->group(function () {
});
Route::middleware(['web'])->group(function () {
    Route::post('badge-store', [BadgeController::class, 'update'])->name('savebadgedata');
    Route::post('Imagebadge-store', [ImageBadgeController::class, 'store'])->name('saveimagebadgedata');
    Route::any('badge-setting-form', [BadgeController::class, 'settingForm'])->name('badge-setting.form');
});
