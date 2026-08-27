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
use Workdo\EMailBox\app\Http\Controllers\EmailBoxController;
use Workdo\EMailBox\app\Http\Controllers\Company\SettingsController;


Route::middleware(['auth', 'web', 'PlanModuleCheck:EMailBox'])->group(function () {

    Route::any('/emailbox/index/{type?}', [EmailBoxController::class, 'index'])->name('emailbox.index');
    Route::get('/emailbox/create/{id?}/{type?}', [EmailBoxController::class, 'create'])->name('emailbox.create');
    Route::any('/emailbox/store', [EmailBoxController::class, 'store'])->name('emailbox.store');
    Route::any('/mail/view/{folder}/{id}', [EmailBoxController::class, 'show'])->name('emailbox.show');
    Route::post('/emailbox/action', [EmailBoxController::class, 'action'])->name('emailbox.action');
    Route::any('/emailbox/delete/{folder}/{id}', [EmailBoxController::class, 'destroy'])->name('emailbox.destroy');
    Route::post('/emailbox/reply/sent', [EmailBoxController::class, 'reply_send'])->name('emailbox.reply.sent');
    Route::get('/emailbox/mail/reply/{id}/{type}', [EmailBoxController::class, 'reply'])->name('emailbox.mail.reply');
    Route::post('/emailbox/mail/move', [EmailBoxController::class, 'move_mail'])->name('emailbox.mail.move');

    
    Route::any('/emailbox/Setting', [SettingsController::class, 'index'])->name('emailbox.setting');
    Route::post('/emailbox/configuration/store', [SettingsController::class, 'store'])->name('emailbox.configuration.store');

});     