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
use Workdo\Signinwithgithub\app\Http\Controllers\SigninwithgithubController;


Route::middleware(['auth', 'web', 'PlanModuleCheck:Signinwithgithub'])->group(function () {
    Route::any('github-login-form', [SigninwithgithubController::class, 'settingForm'])->name('github-login.form');
    Route::post('github-settings', [SigninwithgithubController::class, 'githubSigninSetting'])->name('github.settings');
});

Route::middleware(['web'])->group(function () {
    Route::get('{slug}/auth/github', [SigninwithgithubController::class, 'redirectToGithub']);
    Route::get('{slug}/auth/github/callback', [SigninwithgithubController::class, 'handleGithubCallback'])->name('github.callback');
});