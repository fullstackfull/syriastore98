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
use Workdo\SignInWithBitbucket\app\Http\Controllers\SignInWithBitbucketController;



Route::middleware(['auth', 'web', 'PlanModuleCheck:SignInWithBitbucket'])->group(function () {
    Route::any('bitbucket-login-form', [SignInWithBitbucketController::class, 'settingForm'])->name('bitbucket-login.form');
    Route::post('bitbucket-settings', [SignInWithBitbucketController::class, 'BitBucketSettings'])->name('bitbucket.settings');
});

Route::middleware(['web'])->group(function () {
    Route::get('{slug}/auth/bitbucket', [SignInWithBitbucketController::class, 'bitbucketRedirect']);
    Route::get('{slug}/auth/bitbucket/callback', [SignInWithBitbucketController::class, 'bitbucketCallback'])->name('bitbucket.callback');
});
