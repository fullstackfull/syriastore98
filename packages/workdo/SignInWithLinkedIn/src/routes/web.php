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
use Workdo\SignInWithLinkedIn\app\Http\Controllers\SignInWithLinkedInController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:SignInWithLinkedIn'])->group(function () {
    Route::resource('signinwithlinkedin', SignInWithLinkedInController::class)->names('signinwithlinkedin');
    Route::any('linkedin-login-form', [SignInWithLinkedInController::class, 'settingForm'])->name('linkedin-login.form');
    Route::post('linkedin-setting', [SignInWithLinkedInController::class, 'LinkedinSettings'])->name('linkedinlogin.settings');
});

Route::middleware(['web'])->group(function () {
    Route::get('{slug}/auth/linkedin', [SignInWithLinkedInController::class, 'linkedinRedirect']);
    Route::get('{slug}/auth/linkedin/callback', [SignInWithLinkedInController::class, 'linkedinCallback'])->name('linkedin.callback');
});
