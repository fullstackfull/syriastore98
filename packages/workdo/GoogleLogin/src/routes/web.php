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
use Workdo\GoogleLogin\app\Http\Controllers\GoogleLoginController;


Route::middleware(['auth', 'web', 'PlanModuleCheck:GoogleLogin'])->group(function () {
    Route::post('google-login-form', [GoogleLoginController::class, 'settingForm'])->name('google-login.form');
    Route::post('googlelogin-setting', [GoogleLoginController::class, 'googleLoginSetting'])->name('googlelogin.settings');
});

Route::middleware(['web'])->group(function () {
    Route::get('{slug}/auth/google', [GoogleLoginController::class, 'redirectToGoogle']);
    Route::get('{slug}/auth/google/call-back', [GoogleLoginController::class, 'handleGoogleCallback'])->name('google.callback');
});