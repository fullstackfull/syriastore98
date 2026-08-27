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
use Workdo\AdditionalCustomFields\app\Http\Controllers\SettingController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:AdditionalCustomFields'])->group(function () {
    Route::prefix('additionalcustomfields')->group(function () {
        Route::get('/index',[SettingController::class,'index'])->name('additionalcustomfields.index'); 
        Route::get('/create',[SettingController::class,'create'])->name('additionalcustomfields.create'); 
        Route::post('/store', [SettingController::class, 'store'])->name('additionalcustomfields.store'); 
        Route::get('/edit/{id}', [SettingController::class, 'edit'])->name('additionalcustomfields.edit'); 
        Route::put('/update/{id}', [SettingController::class, 'update'])->name('additionalcustomfields.update');
        Route::delete('/destroy/{id}', [SettingController::class, 'destroy'])->name('additionalcustomfields.destroy');
    });
});