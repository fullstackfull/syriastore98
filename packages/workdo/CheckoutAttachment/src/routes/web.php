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
// use Workdo\AutomaticOrderPrinting\app\Http\Controllers\AutomaticOrderPrintingController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:CheckoutAttachment'])->group(function () {
    //
});
Route::middleware(['web'])->group(function () {
     //Route::get('shippinglabel/pdf/{id}', [OrderController::class,'shippinglabel'])->name('shippinglabel.pdf');
});
