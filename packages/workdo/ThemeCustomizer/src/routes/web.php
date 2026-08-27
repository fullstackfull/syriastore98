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
use Workdo\ThemeCustomizer\app\Http\Controllers\ThemeCustomizerController;


Route::middleware(['web', 'auth', 'PlanModuleCheck:ThemeCustomizer'])->group(function () {
    Route::post('theme-customizer-setting', [ThemeCustomizerController::class, 'themeCustomizerSetting'])->name('themecustomizer.settings');

    Route::get('theme-preview/{theme?}/change-style', [ThemeCustomizerController::class, 'themeCustomizerPreview'])->name('themecustomizer.preview');

    Route::get('theme-preview-content/{theme?}', [ThemeCustomizerController::class, 'themeCustomize'])->name('theme-preview-content');
});