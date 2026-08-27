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
use Workdo\ProductReview\app\Http\Controllers\ProductReviewController;


Route::middleware(['auth', 'web', 'PlanModuleCheck:ProductReview'])->group(function () {
    Route::resource('product-review', ProductReviewController::class);
});

Route::middleware(['web'])->group(function () {
    Route::get('/{storeSlug}/product_review/{id}', [ProductReviewController::class, 'ProductReview'])->name('product_review');
    Route::get('/{storeSlug}/product-review/{product_slug}', [ProductReviewController::class, 'productReviewForm'])->name('product_review.create');
    Route::post('/{storeSlug}/product-review',[ ProductReviewController::class ,'addProductReview'])->name('product_review.store');
});