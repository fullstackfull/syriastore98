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
use Workdo\ProductCSV\app\Http\Controllers\ProductCSVController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:ProductCSV'])->group(function () {
    Route::resource('productcsv', ProductCSVController::class)->names('productcsv');
});
Route::middleware(['web'])->group(function () {
    Route::get('{storeslug}/csvExport/{id}', [ProductCSVController::class,"csvExport"])->name('csvExport');
});
