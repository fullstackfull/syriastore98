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
use Workdo\SpamPrevent\app\Http\Controllers\SpamPreventController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:SpamPrevent'])->group(function () {
    Route::resource('spamprevent', SpamPreventController::class)->names('spamprevent')->except(['store']);
});
Route::middleware(['web'])->group(function () {
    Route::post('{storeSlug}/spamprevent', [SpamPreventController::class, 'store'])->name('spamprevent.store');
    Route::post('spam-prevent', [SpamPreventController::class, 'spam_admin_store'])->name('spamprevent.admin.store');
    Route::get('spam-fetch-prevent', [SpamPreventController::class, 'get_comment'])->name('fetch.comments');
});
