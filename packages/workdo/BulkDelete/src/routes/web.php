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
use Workdo\BulkDelete\app\Http\Controllers\BulkDeleteController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:BulkDelete'])->group(function () {
    Route::post('/bulk-delete',[BulkDeleteController::class,'bulkDelete'])->name('bulk-delete');
});