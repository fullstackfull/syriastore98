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
use Workdo\SidebarCustomization\app\Http\Controllers\SideMenuController;
use Workdo\SidebarCustomization\app\Http\Controllers\SubMenuController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:SidebarCustomization'])->group(callback: function () {
    Route::resource('sidemenu', SideMenuController::class)->names('sidemenu');
    Route::resource('submenus', SubMenuController::class)->names('submenus')->except(['show', 'edit', 'create']);
    Route::get('/submenus/{id}', [SubMenuController::class, 'create'])->name('submenus.create');
    Route::get('/submenus-edit/{id}/{parentid}', [SubMenuController::class, 'edit'])->name('submenus.edit');

    Route::post('/sidemenu-order/update', [SideMenuController::class, 'UpdateOrder'])->name('menu.order.update');
    Route::get('/sidebar/update', [SideMenuController::class, 'updateSidebar'])->name('sidebar.update');
    Route::get('/manage-child/menu/{menuid}', [SideMenuController::class, 'updateChildMenu'])->name('manage.child.menu');
    Route::post('sidechildmenu/update', [SideMenuController::class, 'updateMenuHierarchy'])->name('sidechildmenu.update');

    Route::get('iframe/{id}', [SideMenuController::class, 'iFrameData'])->name('sidemenubuilder.iframe');

});