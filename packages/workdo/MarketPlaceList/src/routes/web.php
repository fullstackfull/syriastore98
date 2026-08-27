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

use App\Http\Middleware\ActiveTheme;
use Illuminate\Support\Facades\Route;
use Workdo\MarketPlaceList\app\Http\Controllers\MarketPlaceListController;

Route::middleware(['web'])->group(function () {
    Route::group([], function () {
        Route::resource('marketplacelist', MarketPlaceListController::class)->names('marketplacelist');
    });


    Route::middleware([ActiveTheme::class, 'themelanguage'])->group(function () {
        Route::get('/{storeSlug}/market', [MarketPlaceListController::class, 'marketPlaceList'])->name('market.place.list.page');
        Route::post('theme-hours-settings', [MarketPlaceListController::class, 'storeHoursSettings'])->name('theme.hours.settings');

        Route::post('/getstore', [MarketPlaceListController::class, 'getstore'])->name('getstore');


    });
});



