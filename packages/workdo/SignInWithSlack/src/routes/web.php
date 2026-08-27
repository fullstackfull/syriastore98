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
use Workdo\SignInWithSlack\app\Http\Controllers\SignInWithSlackController;


Route::middleware(['auth', 'web', 'PlanModuleCheck:SignInWithSlack'])->group(function () {
    Route::any('slack-login-form', [SignInWithSlackController::class, 'settingForm'])->name('slack-login.form');
    Route::post('slack-settings', [SignInWithSlackController::class, 'saveSlackSettings'])->name('slack.settings');
});


Route::middleware(['web'])->group(function () {
    Route::get('{slug}/auth/slack', [SignInWithSlackController::class, 'redirectToSlack']);
    Route::get('{slug}/auth/slack/call-back', [SignInWithSlackController::class, 'handleSlackCallback'])->name('slack.callback');
});