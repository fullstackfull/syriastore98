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
use Workdo\CountDownTimer\app\Http\Controllers\CountDownTimerController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:CountDownTimer'])->group(function () {
    Route::resource('countdowntimer', CountDownTimerController::class)->names('countdowntimer');
    Route::post('update-count-down-status', [CountDownTimerController::class, 'updateStatus'])->name('update-count-down-status');
    Route::post('/count-down-setting', [CountDownTimerController::class, 'countDownSettings'])->name('count.down.settings');
    Route::get('count-down-timers', [CountDownTimerController::class, 'getCountDownTimers'])->name('countdowntimers.list');
});
