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
use Workdo\Marketing\app\Http\Controllers\AppointmentController;
use Workdo\Marketing\app\Http\Controllers\ContactController;
use Workdo\Marketing\app\Http\Controllers\StoreController;



Route::middleware(['auth', 'web', 'PlanModuleCheck:Marketing'])->group(function () {

    Route::get('marketing/store', [StoreController::class, 'index'])->name('vcard.store');
    Route::get('marketing/contacts', [ContactController::class, 'index'])->name('Marketing.contacts.index');

    // extract
    Route::any('marketing/store/domain-store/{id}', [StoreController::class, 'domainsetting'])->name('vcard.store.domain-setting');
    Route::post('marketing/store/block-setting/{id}', [StoreController::class, 'blocksetting'])->name('vcard.store.block-setting');
    Route::post('marketing/store/seo/{id}', [StoreController::class, 'saveseo'])->name('vcard.store.seo-setting');
    Route::post('marketing/store/pwa/{id}', [StoreController::class, 'savePWA'])->name('vcard.store.pwa-setting');
    Route::post('marketing/store/cookie/{id}}', [StoreController::class, 'saveCookiesetting'])->name('vcard.store.cookie-setting');
    Route::post('marketing/store/custom_qrcode/{id}', [StoreController::class, 'saveCustomQrsetting'])->name('vcard.store.qrcode_setting');

    /*==================================== Store ====================================================*/

    Route::any('marketing/Store/update/{id}', [StoreController::class, 'updateData'])->name('vcard.store.update');
    Route::any('marketing/Store/edit-theme/{id}', [StoreController::class, 'storedata'])->name('vcard.store.edit-theme');
    Route::post('marketing/Store/destroy/', [StoreController::class, 'destroyGallery'])->name('destory.gallery');

    Route::get('business/{slug}/get_card', [StoreController::class, 'cardpdf'])->name('get.card');
    Route::get('card_cookie_consent', [StoreController::class, 'cardCookieConsent'])->name('card-cookie-consent');


    /*==================================== Contacts ====================================================*/
    Route::resource('appointments', AppointmentController::class)->names('appointments')->except(['index']);

    Route::get('appoinments', [AppointmentController::class, 'index'])->name('appointments.index');
    Route::get('get-appointment-detail/{id}', [AppointmentController::class, 'getAppointmentDetails'])->name('appointment.details');
    Route::get('appointment-note/{id?}', [AppointmentController::class, 'add_note'])->name('appointment.add-note');
    Route::post('appointment-note-store/{id?}', [AppointmentController::class, 'note_store'])->name('appointment.note.store');
    Route::any('/get_appointment_data', [AppointmentController::class, 'get_appointment_data'])->name('get_appointment_data');
    Route::any('appointments/delete/{id}', [AppointmentController::class, 'destroy'])->name('appointment.destroy');

    /*==================================== Contacts ====================================================*/

    Route::get('/contact-note/{id?}', [ContactController::class, 'add_note'])->name('contact.add-note');
    Route::any('contacts/delete/{id}', [ContactController::class, 'destroy'])->name('vCard.contacts.destroy');
    Route::get('contacts/store/show{id}', [ContactController::class, 'index'])->name('business.contacts.show');
    Route::get('contacts/edit/{id}', [ContactController::class, 'edit'])->name('vCard.contacts.edit');
    Route::post('contacts/update/{id}', [ContactController::class, 'update'])->name('Contacts.update');
    
    Route::post('/contact-note-store/{id?}', [ContactController::class, 'note_store'])->name('contact.note.store');
});
Route::middleware(['web'])->group(function () {
    Route::get('/download/{slug}', [StoreController::class, 'getVcardDownload'])->name('vcard.save');
    Route::get('cards/{slug}', [StoreController::class, 'getcard'])->name('get.vcard');
    Route::any('appoinments/store/{slug}', [AppointmentController::class, 'store'])->name('appoinment.store');
    Route::any('marketing/contacts/store/{slug}', [ContactController::class, 'store'])->name('marketing.contacts.store');
});