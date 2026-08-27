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
use Workdo\Wizzchat\app\Http\Controllers\WizzchatController;

Route::middleware(['web', 'PlanModuleCheck:Wizzchat'])->group(function () {
    Route::resource('wizzchat', WizzchatController::class)->names('wizzchat');
    Route::post('Wizzchat-form', [WizzchatController::class, 'settingForm'])->name('Wizzchat.form');
    Route::post('wizzchat-setting', [WizzchatController::class, 'wizzchatSetting'])->name('wizzchat.settings');
});