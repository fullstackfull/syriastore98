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
use Workdo\ProductsPDF\app\Http\Controllers\ProductsPDFController;

Route::middleware(['web'])->group(function () {

    Route::resource('productspdf', ProductsPDFController::class)->names('productspdf');
});
Route::get('/{storeSlug}/product_pdf/{product_slug}', [ProductsPDFController::class, 'addProductPdf'])->name('product_pdf');
