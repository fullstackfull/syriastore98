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
use Workdo\DigitalProduct\app\Http\Controllers\DigitalProductController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:DigitalProduct'])->group(function () {
    Route::resource('digitalproduct', DigitalProductController::class)->names('digitalproduct');
});
Route::middleware(['web'])->group(function () {
    Route::delete('digital-products/{id}/delete', [DigitalProductController::class, 'file_delete'])
    ->name('digital.products.file.delete');
});
