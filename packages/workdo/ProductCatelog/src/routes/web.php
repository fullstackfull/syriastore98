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
use Workdo\ProductCatelog\app\Http\Controllers\ProductCatelogController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:ProductCatelog'])->group(function () {

});
Route::middleware(['web'])->group(function () {
    Route::get('{slug}/product-catelog-list', [ProductCatelogController::class, 'product_catelog'])->name('product_catelog_list');
    Route::post('admin_email_template_lang/{id}/', [ProductCatelogController::class, 'updateProductCatelogEmail'])->name('updateProductCatelogEmail.setting');
    Route::get('admin_email_template_lang/{id}/{lang?}', [ProductCatelogController::class, 'adminManageEmailLang'])->name('manage.adminemail.language');
    Route::resource('productcatelog', ProductCatelogController::class)->names('productcatelog');
});
