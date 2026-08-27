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
use Workdo\PDFVouchers\app\Http\Controllers\CouponRequestController;
use Workdo\PDFVouchers\app\Http\Controllers\MailSettingController;
use Workdo\PDFVouchers\app\Http\Controllers\PDFVouchersController;
use Workdo\PDFVouchers\app\Models\CouponRequest;


Route::middleware(['auth', 'web', 'PlanModuleCheck:PDFVouchers'])->group(function () {
    Route::resource('pdfvouchers', PDFVouchersController::class)->names('pdfvouchers');

});

Route::middleware(['web'])->group(function () {
    Route::get('mail-settings/create', [MailSettingController::class, 'create'])->name('mail_setting.create');
    Route::post('mail-settings', [MailSettingController::class, 'store'])->name('PDFVouchers.mail_setting.store');
    Route::post('coupencode/Status/store', [MailSettingController::class, 'status'])->name('coupencode.ststus.store');
    Route::get('{slug}/coupencode/get', [CouponRequestController::class, 'couponForm'])->name('coupencode.get');
    Route::post('{slug}/coupencode/request/store', [CouponRequestController::class, 'couponRequest'])->name('coupencode.request.store');
});
