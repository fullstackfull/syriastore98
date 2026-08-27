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
use Workdo\RecentOrders\app\Http\Controllers\RecentOrdersController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:RecentOrders'])->group(function () {
    Route::resource('recentorders', RecentOrdersController::class)->names('recentorders');
    Route::post('/recent-orders-settings', [RecentOrdersController::class, 'recentOrdersSettings'])->name('recent.orders.settings');
});
Route::middleware(['web'])->group(function () {
});
