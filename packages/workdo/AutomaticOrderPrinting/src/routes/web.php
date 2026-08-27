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

use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;
use Workdo\AutomaticOrderPrinting\app\Http\Controllers\AutomaticOrderPrintingController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:AutomaticOrderPrinting'])->group(function () {
    Route::resource('automaticorderprinting', AutomaticOrderPrintingController::class)->names('automaticorderprinting');
    Route::resource('print-settings', AutomaticOrderPrintingController::class);
    Route::any('/automatic-print-setting', [AutomaticOrderPrintingController::class, 'printSettings'])->name('automatic-order.print.setting');
    Route::post('/automatic-print-setting-setting-form', [AutomaticOrderPrintingController::class, 'settingForm'])->name('automatic-print.form');
});
Route::middleware(['web'])->group(function () {
    Route::get('/shippinglabel/{id?}/{template?}/{color?}', [AutomaticOrderPrintingController::class, 'shippinglabel'])->name('invoice.shippinglabel.pdf');

    Route::get('/printlabel/{id?}/{template?}/{color?}', [AutomaticOrderPrintingController::class, 'printinglabel'])->name('invoice.shippinglabel.printing');

    Route::get('/invoices/preview/{template}/{color}', [AutomaticOrderPrintingController::class, 'invoice_preview'])->name('invoice.preview');

    Route::get('shippinglabel/pdf/{id}', [OrderController::class,'shippinglabel'])->name('shippinglabel.pdf');
});
