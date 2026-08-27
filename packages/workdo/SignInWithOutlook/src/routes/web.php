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
use Workdo\SignInWithOutlook\app\Http\Controllers\SignInWithOutlookController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:SignInWithOutlook'])->group(function () {
    Route::post('outlook-login-form', [SignInWithOutlookController::class, 'settingForm'])->name('outlook-login.form');
    Route::post('outlooklogin-setting', [SignInWithOutlookController::class, 'outlookSettings'])->name('outlooklogin.settings');
});


Route::middleware(['web'])->group(function () {
    Route::get('{slug}/auth/outlook', [SignInWithOutlookController::class, 'outlookSignin']);
    Route::get('{slug}/auth/outlook/call-back', [SignInWithOutlookController::class, 'outlookCallback'])->name('outlook.callback');
});