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
use Workdo\ReviewReminder\app\Http\Controllers\ReviewReminderController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:ReviewReminder'])->group(function () {
    Route::resource('reviewreminder', ReviewReminderController::class)->names('reviewreminder');
    Route::resource('order-review-reminder', ReviewReminderController::class);
});

Route::middleware(['web'])->group(function () {

    Route::get('order-review/email_template_lang/{id}/{lang?}', [ReviewReminderController::class, 'manageReviewEmailLang'])->name('manage.review.email.language');
    Route::post('order-review/update/email_template/{id}', [ReviewReminderController::class, 'updateReviewEmailSettings'])->name('update.review.email.settings');
    Route::post('order-review/update/email_template_status/{id}', [ReviewReminderController::class, 'updateEmailTemplateStatus'])->name('update.emailtemplate.status');
    Route::get('order-review/{slug}/{order?}', [ReviewReminderController::class, 'orderReview'])->name('order.review');
    Route::post('order-review-store/{slug}/{order?}', [ReviewReminderController::class, 'orderReviewStore'])->name('orderreview.store');
    Route::delete('order-review-store/{Id}', [ReviewReminderController::class, 'destroy'])->name('orderreview.destroy');

});

