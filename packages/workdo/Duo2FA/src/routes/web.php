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
use Workdo\Duo2FA\app\Http\Controllers\Duo2FAController;


Route::middleware(['web'])->group(function () {
    Route::group([], function () {
    Route::resource('duo2fa', Duo2FAController::class)->names('duo2fa');
});

Route::post('duo2fa-setting', [Duo2FAController::class, 'duo2FASetting'])->name('duo2fa.settings');
Route::get('authduologin', [Duo2FAController::class, 'showDuoForm'])->name('duo2fa.form');
Route::post('auth/duo/verify', [Duo2FAController::class, 'verifyDuo'])->name('duo2fa.verify');
Route::any('duologin', [Duo2FAController::class, 'duologin'])->name('duologin');

});