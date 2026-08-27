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
use Workdo\AuctionProduct\app\Http\Controllers\AuctionOrdersController;
use Workdo\AuctionProduct\app\Http\Controllers\AuctionProductController;
use Workdo\AuctionProduct\app\Http\Controllers\AuctionProductSettingController;

Route::middleware(['auth', 'web', 'PlanModuleCheck:AuctionProduct'])->group(function () {
    Route::resource('auctionproduct', AuctionProductController::class)->names('auctionproduct');
    Route::resource('auctionsetting', AuctionProductSettingController::class)->names('auctionsetting');
    Route::resource('auctionorder', AuctionOrdersController::class)->names('auctionorder');
});
Route::middleware(['web'])->group(function () {
    Route::get('/{storeSlug}/place-bid/{product_slug}', [AuctionProductController::class, 'bidingForm'])->name('place_bid.create');
    Route::post('/{storeSlug}/product-bid',[AuctionProductController::class ,'addProductbidamount'])->name('place_bid.store');
    Route::get('/{storeSlug}/auction-product/{product_slug}', [AuctionProductController::class, 'product_detail'])->name('page.auction_product');
    Route::post('update-auction-status', [AuctionProductSettingController::class, 'updateStatus'])->name('update-auction-setting');
    Route::get('auction-order-view/{id}', [AuctionOrdersController::class,'order_view'])->name('auction.order.view');
    Route::get('{storeSlug}/auction-order/{id}', [AuctionOrdersController::class, 'orderdetails'])->name('auction.order.details')->middleware('themelanguage');
    Route::post('auction-order-assign', [AuctionOrdersController::class,'order_assign'])->name('auction.order.assign');
    Route::post('auction-order-status-change/{id}', [AuctionOrdersController::class,'order_status_change'])->name('auction.order.status.change')->middleware('themelanguage');
    Route::post('auction-order-return', [AuctionOrdersController::class,'order_return'])->name('auction.order.return')->middleware('themelanguage');
    Route::get('auction-order-receipt/{id}' ,[AuctionOrdersController::class,'order_receipt'])->name('auction.order.receipt')->middleware('themelanguage');
    Route::get('auction/shippinglabel/pdf/{id}', [AuctionOrdersController::class,'shippinglabel'])->name('auction.shippinglabel.pdf');
    Route::get('{storeSlug}/auction-order-list', [AuctionOrdersController::class,'order_list'])->name('auction.order.list');

});
