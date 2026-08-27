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
use Workdo\Api\app\Http\Controllers\ApiController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:Api'])->group(function () {
    Route::get('/api-docs',[ApiController::class,'index'])->name('api.docs');
    Route::get('/api-settings',[ApiController::class,'ApiSetting'])->name('api.setting');
    Route::post('/api-page-setting', [ApiController::class, 'API_page_setting'])->name('api.setting.data');
});
