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
use Workdo\SMS\app\Http\Controllers\NotificationController;
use Workdo\SMS\app\Http\Controllers\SMSController;


Route::middleware(['auth', 'web', 'PlanModuleCheck:SMS'])->group(function () {
    Route::resource('sms', SMSController::class)->names('sms');
    Route::post('sms-settings', [SMSController::class, 'saveSmsSettings'])->name('sms.settings');
    Route::post('sms-setting-form', [SMSController::class, 'settingForm'])->name('sms-setting.form');
    Route::post('get-sms-fields', [SMSController::class, 'getSmsFields'])->name('get.sms.fields');
});
Route::middleware(['web','auth'])->group(function () {
    //notification
    Route::resource('notification-template', NotificationController::class);
    Route::get('notification-template/{id}/{lang?}', [NotificationController::class, 'show'])->name('manage.notification.language');
    Route::post('notification-template/{pid}', [NotificationController::class, 'storeNotificationLang'])->name('store.notification.language');
});