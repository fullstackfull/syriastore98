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
use Workdo\ImportExport\app\Http\Controllers\BrandImportExportController;
use Workdo\ImportExport\app\Http\Controllers\CityImportExportController;
use Workdo\ImportExport\app\Http\Controllers\CountryImportExportController;
use Workdo\ImportExport\app\Http\Controllers\LabelImportExportController;
use Workdo\ImportExport\app\Http\Controllers\MainCategoryImportExportController;
use Workdo\ImportExport\app\Http\Controllers\ProductImportExportController;
use Workdo\ImportExport\app\Http\Controllers\StateImportExportController;
use Workdo\ImportExport\app\Http\Controllers\SubCategoryImportExportController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:ImportExport'])->group(function () {
    // Products
    Route::get('export-products', [ProductImportExportController::class, 'exportProduct'])->name('product.export');

    Route::post('import/product', [ProductImportExportController::class, 'fileImport'])->name('product.import');
    Route::get('product/import/export', [ProductImportExportController::class, 'fileImportExport'])->name('product.importExport');
    Route::get('product/import/modal', [ProductImportExportController::class, 'fileImportModal'])->name('product.importModal');

    // brands
    Route::post('import/brands', [BrandImportExportController::class, 'fileImport'])->name('brand.import');
    Route::get('export/brands', [BrandImportExportController::class, 'exportBrand'])->name('brand.export');
    Route::get('brand/import/export', [BrandImportExportController::class, 'fileImportExport'])->name('brand.importExport');
    // labels
    Route::post('import/labels', [LabelImportExportController::class, 'fileImport'])->name('label.import');
    Route::get('export/labels', [LabelImportExportController::class, 'exportLabel'])->name('label.export');
    Route::get('labels/import/export', [LabelImportExportController::class, 'fileImportExport'])->name('label.importExport');
    // main category
    Route::post('import/maincategory', [MainCategoryImportExportController::class, 'fileImport'])->name('maincategory.import');
    Route::get('export/maincategory', [MainCategoryImportExportController::class, 'exportMaincategory'])->name('maincategory.export');
    Route::get('main-category/import/export', [MainCategoryImportExportController::class, 'fileImportExport'])->name('maincategory.importExport');

    // main category
    Route::post('import/subcategory', [SubCategoryImportExportController::class, 'fileImport'])->name('subcategory.import');
    Route::get('export/subcategory', [SubCategoryImportExportController::class, 'exportSubcategory'])->name('subcategory.export');
    Route::get('sub-category/import/export', [SubCategoryImportExportController::class, 'fileImportExport'])->name('subcategory.importExport');

    // country
    Route::post('import/country', [CountryImportExportController::class, 'fileImport'])->name('country.import');
    Route::get('export/country', [CountryImportExportController::class, 'exportCountry'])->name('country.export');
    Route::get('country/import/export', [CountryImportExportController::class, 'fileImportExport'])->name('country.importExport');

    // state
    Route::post('import/state', [StateImportExportController::class, 'fileImport'])->name('state.import');
    Route::get('export/state', [StateImportExportController::class, 'exportState'])->name('state.export');
    Route::get('state/import/export', [StateImportExportController::class, 'fileImportExport'])->name('state.importExport');

    // city
    Route::post('import/city', [CityImportExportController::class, 'fileImport'])->name('city.import');
    Route::get('export/city', [CityImportExportController::class, 'exportCity'])->name('city.export');
    Route::get('city/import/export', [CityImportExportController::class, 'fileImportExport'])->name('city.importExport');
});
Route::middleware(['web'])->group(function () {});
