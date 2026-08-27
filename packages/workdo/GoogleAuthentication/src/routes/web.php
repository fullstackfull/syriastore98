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
use Workdo\GoogleAuthentication\app\Http\Controllers\GoogleAuthenticationController;
use Workdo\LandingPage\Http\Controllers\HomeController;

Route::middleware(['web'])->group(function () {

    Route::resource('googleauthentication', GoogleAuthenticationController::class)->names('googleauthentication');
    Route::post('/generateSecret', [GoogleAuthenticationController::class, 'generate2faSecret'])->name('generate2faSecret');
    Route::post('/enable2fa', [GoogleAuthenticationController::class, 'enable2fa'])->name('enable2fa');
    Route::post('/disable2fa', [GoogleAuthenticationController::class, 'disable2fa'])->name('disable2fa');

    Route::get('/2fa-verify', [HomeController::class, 'index'])->name('2fa.dashboard')->middleware('2fa');
    Route::post('/2faVerify', function () {
        return redirect(request()->get('2fa_referrer'));
    })->name('2faVerify')->middleware(['2fa']);



});