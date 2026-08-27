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
use Workdo\RewardClubPoint\app\Http\Controllers\RewardClubPointController;


Route::middleware(['auth', 'web', 'PlanModuleCheck:RewardClubPoint'])->group(function () {
    Route::resource('rewardclubpoint', RewardClubPointController::class);
    Route::post('/club-point-setting', [RewardClubPointController::class, 'clubPointSettings'])->name('club.point.settings');
    Route::get('/customer-club-point-history/{customerId}', [RewardClubPointController::class, 'clubPointHistory'])->name('customer.club.point.history');
    
    Route::post('{storeSlug}/apply-points', [RewardClubPointController::class, 'applyPoints'])->name('apply.points');
});