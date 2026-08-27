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
use Workdo\PartialPayments\app\Http\Controllers\PartialPaymentsController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:PartialPayments'])->group(function () {
    Route::resource('partialpayments', PartialPaymentsController::class)->names('partialpayments');
});
Route::middleware(['web'])->group(function () {
    Route::put('payment-email/update/email_template/{id}', [PartialPaymentsController::class, 'updateCustomerEmailSettings'])->name('update.payment.email.settings');
    Route::get('payment-email/email_template_lang/{id}/{lang?}', [PartialPaymentsController::class, 'manageCustomerEmailLang'])->name('manage.payment.email.language');
    Route::post('partial-payment-settings', [PartialPaymentsController::class, 'PartialPaymentSettings'])->name('update.partial.payment.settings');


    Route::post('{storeSlug}/manage-deposit', [PartialPaymentsController::class, 'ManageDeposit'])->name('manage.deposit.value');
    Route::get('{storeSlug?}/customerpayment/{id}', [PartialPaymentsController::class, 'customerpayment'])->name('customer.payment')->middleware('themelanguage');
    Route::post('{storeSlug}/make-payment', [PartialPaymentsController::class,'ManagePartialPayments'])->name('manage.partial.payments');

    Route::resource('partialpayments', PartialPaymentsController::class)->names('partialpayments');

    Route::post('payment-status-change/{id}', [PartialPaymentsController::class,'payment_status_change'])->name('payment.status.change');

});
