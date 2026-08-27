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
use Workdo\AdditionalFieldInCheckout\app\Http\Controllers\AdditionalFieldInCheckoutController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:AdditionalFieldInCheckout'])->group(function () {
    Route::resource('additionalfieldincheckout', AdditionalFieldInCheckoutController::class)->names('additionalfieldincheckout');
});
