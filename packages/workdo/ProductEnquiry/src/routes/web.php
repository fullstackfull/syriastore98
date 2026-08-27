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
use Workdo\ProductEnquiry\app\Http\Controllers\ProductEnquiryController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:ProductEnquiry'])->group(function () {
    Route::resource('productenquiry', ProductEnquiryController::class)->names('productenquiry');
    Route::resource('product-enquiry', ProductEnquiryController::class);
    Route::put('enquiry-email/update/email_template/{id}', [ProductEnquiryController::class, 'updateEnquiryEmailSettings'])->name('update.enquiry.email.settings');
    Route::get('enquiry-email/email_template_lang/{id}/{lang?}', [ProductEnquiryController::class, 'manageEnquiryEmailLang'])->name('manage.enquiry.email.language');
});
Route::middleware(['web'])->group(function () {
    Route::post('/{storeSlug}/product-enquiry',[ ProductEnquiryController::class ,'addproductenquiry'])->name('product_enquiry.store');
});
