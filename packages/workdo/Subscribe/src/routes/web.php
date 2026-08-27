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
use Workdo\Subscribe\app\Http\Controllers\SubscribeController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:Subscribe'])->group(function () {
});
Route::middleware(['web'])->group(function () {
    Route::resource('subscribe', SubscribeController::class)->names('subscribe');
});
