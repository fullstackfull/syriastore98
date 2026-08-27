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
use Workdo\ProductPricing\app\Http\Controllers\ProductPricingController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:ProductPricing'])->group(function () {
    Route::resource('productpricing', ProductPricingController::class)->names('productpricing');
    Route::post('/productpricing/fields', [ProductPricingController::class,'productPricingFields'])->name('productpricing.productPricingFields');
    Route::post('/productpricing/conditionFields', [ProductPricingController::class,'conditionFields'])->name('productpricing.conditionFields');
    Route::post('/productpricing/rulesCondition', [ProductPricingController::class,'rulesCondition'])->name('productpricing.rulesCondition');
    Route::post('/productpricing/ruleCondition', [ProductPricingController::class,'ruleCondition'])->name('productpricing.ruleCondition');
    Route::get('/product-pricing/list', [ProductPricingController::class, 'getProductPricingRules'])->name('productpricing.list');

});