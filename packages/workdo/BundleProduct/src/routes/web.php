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
use Workdo\BundleProduct\app\Http\Controllers\BundleProductController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:BundleProduct'])->group(function () {
    Route::resource('bundleproduct', BundleProductController::class)->names('bundleproduct');
    Route::resource('bundle-product', BundleProductController::class);
});
