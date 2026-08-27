<?php

use Illuminate\Support\Facades\Route;
use Workdo\SignInWithFacebook\app\Http\Controllers\SignInWithFacebookController;

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


Route::middleware(['auth', 'web', 'PlanModuleCheck:SignInWithFacebook'])->group(function () {
    Route::resource('signinwithfacebook', SignInWithFacebookController::class)->names('signinwithfacebook');
    Route::any('facebook-login-form', [SignInWithFacebookController::class, 'settingForm'])->name('facebook-login.form');
    Route::post('facebooklogin-setting', [SignInWithFacebookController::class, 'facebookSettings'])->name('facebooklogin.settings');
});

Route::middleware(['web'])->group(function () {
    Route::get('{slug}/auth/facebook', [SignInWithFacebookController::class, 'facebookRedirect']);
    Route::get('{slug}/auth/facebook/callback', [SignInWithFacebookController::class, 'facebookCallback'])->name('facebook.callback');
});

