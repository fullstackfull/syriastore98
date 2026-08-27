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
use Workdo\Helpdesk\app\Http\Controllers\HelpdeskController;
use Workdo\Helpdesk\app\Http\Controllers\HelpdeskConversionController;
use Workdo\Helpdesk\app\Http\Controllers\HelpdeskSystemSetupController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:Helpdesk'])->group(function () {
    Route::get('helpdesk/category', [HelpdeskSystemSetupController::class, 'index'])->name('helpdesk.category');
    Route::get('helpdesk/category-create', [HelpdeskSystemSetupController::class, 'createCategory'])->name('helpdesk.category.create');
    Route::post('helpdesk/store-category', [HelpdeskSystemSetupController::class, 'storeCategory'])->name('helpdesk.category.store');
    Route::get('helpdesk/edit-category/{id}', [HelpdeskSystemSetupController::class, 'editCategory'])->name('helpdesk.category.edit');
    Route::post('helpdesk/update-category/{id}', [HelpdeskSystemSetupController::class, 'updateCategory'])->name('helpdesk.category.update');
    Route::delete('helpdesk/delete-category/{id}', [HelpdeskSystemSetupController::class, 'deleteCategory'])->name('helpdesk.category.delete');

    // Status Routes
    Route::get('status', [HelpdeskSystemSetupController::class, 'helpdeskStatusIndex'])->name('helpdesk.status');
    Route::get('status-create', [HelpdeskSystemSetupController::class, 'createStatus'])->name('helpdesk.status.create');
    Route::post('store-status', [HelpdeskSystemSetupController::class, 'storeStatus'])->name('helpdesk.status.store');
    Route::get('edit-status/{id}', [HelpdeskSystemSetupController::class, 'editStatus'])->name('helpdesk.status.edit');
    Route::post('update-status/{id}', [HelpdeskSystemSetupController::class, 'updateStatus'])->name('helpdesk.status.update');
    Route::delete('delete-status/{id}', [HelpdeskSystemSetupController::class, 'deleteStatus'])->name('helpdesk.status.delete');


    // Routes for Ticket
    Route::get('tickets/{status?}', [HelpdeskController::class, 'index'])->name('helpdesk.ticket.index');

    Route::get('create-ticket', [HelpdeskController::class, 'create'])->name('helpdesk.ticket.create');
    Route::post('store-tickets', [HelpdeskController::class, 'store'])->name('helpdesk.ticket.store');
    Route::get('edit-ticket/{ticketId}', [HelpdeskController::class, 'edit'])->name('helpdesk.ticket.edit');
    Route::post('update-ticket/{ticketId}', [HelpdeskController::class, 'update'])->name('helpdesk.ticket.update');
    Route::get('ticket-reply/{ticketId}', [HelpdeskController::class, 'ticketReply'])->name('helpdesk.ticket.reply');
    Route::delete('delete-ticket/{ticketId}',[HelpdeskController::class,'deleteTicket'])->name('helpdesk.ticket.delete');
    Route::get('helpdesk-tickets/search/{status?}', [HelpdeskController::class, 'index'])->name('helpdesk-tickets.search');

    // store conversion
    Route::post('ticket-conversion-store/{ticketId}',[HelpdeskConversionController::class,'storeTicketConversion'])->name('helpdesk-ticket.conversion.store');
    Route::post('ticket-note-store/{ticketId}',[HelpdeskController::class,'storeTicketNote'])->name('helpdesk-ticket.note.store');
    Route::post('get-user-data', [HelpdeskController::class, 'getSelectedUserData'])->name('helpdesk-tickets.getuser');
});
Route::middleware(['web'])->group(function () {
    Route::get('helpdesk-ticket-show/{ticketId}', [HelpdeskController::class, 'show'])->name('helpdesk.ticket.view');
    Route::post('helpdesk-ticket/{ticketId}', [HelpdeskConversionController::class, 'showLinkTicketReply'])->name('helpdesk-ticket.reply');

});
