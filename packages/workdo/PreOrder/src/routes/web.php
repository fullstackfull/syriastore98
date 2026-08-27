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
use Workdo\PreOrder\app\Http\Controllers\PreOrderController;
use Workdo\PreOrder\app\Http\Controllers\PreOrderSettingController;

Route::middleware(['web', 'auth', 'PlanModuleCheck:PreOrder'])->group(function () {
    
    Route::resource('preorder', PreOrderController::class)->names('preorders');
    Route::get('preorder-setting', [PreOrderController::class, 'preOrderNotificationSetting'])->name('preorder.setting');
    Route::put('preorder-setting/update/email_template/{id}', [PreOrderController::class, 'updatePreOrderEmailSettings'])->name('update.preorder.email.settings');
    Route::get('preorder-setting/email_template_lang/{id}/{lang?}', [PreOrderController::class, 'managePreOrderEmailLang'])->name('manage.preorder.email.language');
    Route::get('preorder/manually-send-preorder-email/{id}', [PreOrderController::class, 'manuallySendPreOrderEmail'])->name('preorder.manuallySendEmail');

    Route::resource('preordersetting', PreOrderSettingController::class)->names('preordersetting');

    Route::get('preordersetting/get-product-variants/{id}', [PreOrderSettingController::class, 'getVariants']);
});

Route::middleware(['web', \App\Http\Middleware\ActiveTheme::class, 'themelanguage'])->group(function () {
    Route::get('{storeSlug}/preordersetting/out-of-stock-products', [PreOrderSettingController::class, 'getOutOfStockProductIds'])->name('preorder.outofstock.products');
});