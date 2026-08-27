<?php
use Illuminate\Support\Facades\Route;
use Workdo\LandingPage\Http\Controllers\LandingPageController;
use Workdo\LandingPage\Http\Controllers\CustomPageController;
use Workdo\LandingPage\Http\Controllers\HomeController;
use Workdo\LandingPage\Http\Controllers\FeaturesController;
use Workdo\LandingPage\Http\Controllers\ScreenshotsController;
use Workdo\LandingPage\Http\Controllers\PricingPlanController;
use Workdo\LandingPage\Http\Controllers\JoinUsController;
use Workdo\LandingPage\Http\Controllers\FooterController;
use Workdo\LandingPage\Http\Controllers\ReviewController;
use Workdo\LandingPage\Http\Controllers\DedicatedSectionController;
use Workdo\LandingPage\Http\Controllers\BuiltTechSectionController;
use Workdo\LandingPage\Http\Controllers\PackageDetailsController;
use Workdo\LandingPage\Http\Controllers\MarketPlaceController;
use Workdo\LandingPage\Http\Controllers\MenuPagesController;
use Workdo\LandingPage\Http\Controllers\OwnerMenuController;
use Workdo\LandingPage\Http\Controllers\DiscoverController;
use Workdo\LandingPage\Http\Controllers\FaqController;
use Workdo\LandingPage\Http\Controllers\TestimonialsController;


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
Route::middleware(['auth','web', 'xss', 'setlocate','verified'])->group(function () {
  
    Route::resource('landingpage', LandingPageController::class);


    Route::resource('custom_page', CustomPageController::class);
    Route::post('custom_store/', [CustomPageController::class, 'customStore'])->name('custom_store');

    Route::resource('menu-pages', MenuPagesController::class);
    Route::resource('ownermenus', OwnerMenuController::class)->except(['update']);
    Route::get('add-page-to-ownermenu', [OwnerMenuController::class, 'addPageToMenu'])->name('ownermenus.addPage');
    Route::get('update-ownermenu', [OwnerMenuController::class, 'updateMenu'])->name('ownermenus.update');
    Route::get('add-ownercustom-link', [OwnerMenuController::class, 'addLinkToMenu'])->name('ownermenus.addLink');
    Route::post('update-ownermenuitem/{id}', [OwnerMenuController::class, 'updateMenuItem'])->name('ownermenus.updateItems');
    Route::get('delete-ownermenuitem/{id}/{key}/{in?}', [OwnerMenuController::class, 'deleteMenuItem'])->name('ownermenus.deleteItems');
    Route::post('manage_ownwemenu/', [OwnerMenuController::class, 'manageOwnermenu'])->name('manage_ownwemenu');

    Route::get('seo-data', [LandingPageController::class, 'seoView'])->name('seo.page');
    Route::post('seo-data/store/', [LandingPageController::class, 'seoStore'])->name('seo.store');

    Route::resource('homesection', HomeController::class);


    Route::resource('features', FeaturesController::class);

    Route::get('feature/create/', [FeaturesController::class, 'feature_create'])->name('feature_create');
    Route::post('feature/store/', [FeaturesController::class, 'feature_store'])->name('feature_store');
    Route::get('feature/edit/{key}', [FeaturesController::class, 'feature_edit'])->name('feature_edit');
    Route::post('feature/update/{key}', [FeaturesController::class, 'feature_update'])->name('feature_update');
    Route::get('feature/delete/{key}', [FeaturesController::class, 'feature_delete'])->name('feature_delete');

    Route::post('feature_highlight_create/', [FeaturesController::class, 'feature_highlight_create'])->name('feature_highlight_create');

    Route::get('features/create/', [FeaturesController::class, 'features_create'])->name('features_create');
    Route::post('features/store/', [FeaturesController::class, 'features_store'])->name('features_store');
    Route::get('features/edit/{key}', [FeaturesController::class, 'features_edit'])->name('features_edit');
    Route::post('features/update/{key}', [FeaturesController::class, 'features_update'])->name('features_update');
    Route::get('features/delete/{key}', [FeaturesController::class, 'features_delete'])->name('features_delete');



    Route::resource('discover', DiscoverController::class);
    Route::get('discover/create/', [DiscoverController::class, 'discover_create'])->name('discover_create');
    Route::post('discover/store/', [DiscoverController::class, 'discover_store'])->name('discover_store');
    Route::get('discover/edit/{key}', [DiscoverController::class, 'discover_edit'])->name('discover_edit');
    Route::post('discover/update/{key}', [DiscoverController::class, 'discover_update'])->name('discover_update');
    Route::get('discover/delete/{key}', [DiscoverController::class, 'discover_delete'])->name('discover_delete');



    Route::resource('screenshots', ScreenshotsController::class);
    Route::get('screenshots/create/', [ScreenshotsController::class, 'screenshots_create'])->name('screenshots_create');
    Route::post('screenshots/store/', [ScreenshotsController::class, 'screenshots_store'])->name('screenshots_store');
    Route::get('screenshots/edit/{key}', [ScreenshotsController::class, 'screenshots_edit'])->name('screenshots_edit');
    Route::post('screenshots/update/{key}', [ScreenshotsController::class, 'screenshots_update'])->name('screenshots_update');
    Route::get('screenshots/delete/{key}', [ScreenshotsController::class, 'screenshots_delete'])->name('screenshots_delete');


    Route::resource('pricing_plan', PricingPlanController::class);



    Route::resource('faq', FaqController::class);
    Route::get('faq/create/', [FaqController::class, 'faq_create'])->name('faq_create');
    Route::post('faq/store/', [FaqController::class, 'faq_store'])->name('faq_store');
    Route::get('faq/edit/{key}', [FaqController::class, 'faq_edit'])->name('faq_edit');
    Route::post('faq/update/{key}', [FaqController::class, 'faq_update'])->name('faq_update');
    Route::get('faq/delete/{key}', [FaqController::class, 'faq_delete'])->name('faq_delete');


    Route::resource('testimonials', TestimonialsController::class);
    Route::get('testimonials/create/', [TestimonialsController::class, 'testimonials_create'])->name('testimonials_create');
    Route::post('testimonials/store/', [TestimonialsController::class, 'testimonials_store'])->name('testimonials_store');
    Route::get('testimonials/edit/{key}', [TestimonialsController::class, 'testimonials_edit'])->name('testimonials_edit');
    Route::post('testimonials/update/{key}', [TestimonialsController::class, 'testimonials_update'])->name('testimonials_update');
    Route::get('testimonials/delete/{key}', [TestimonialsController::class, 'testimonials_delete'])->name('testimonials_delete');


    Route::resource('join_us', JoinUsController::class);


    Route::resource('footer', FooterController::class);
    Route::post('footer_store', [FooterController::class, 'store'])
    ->name('footer_store');

    Route::get('footer/create', [FooterController::class, 'footer_section_create'])
    ->name('footer_section_create');

    Route::post('footer/store', [FooterController::class, 'footer_section_store'])
    ->name('footer_section_store');

    Route::get('footer/edit/{key}', [FooterController::class, 'footer_section_edit'])
    ->name('footer_section_edit');

    Route::post('footer/update/{key}', [FooterController::class, 'footer_section_update'])
    ->name('footer_section_update');

    Route::get('footers/delete/{key}', [FooterController::class, 'footer_section_delete'])
    ->name('footer_section_delete');

    Route::resource('review', ReviewController::class);

    Route::get('review/create', [ReviewController::class, 'review_create'])
    ->name('review_create');

    Route::post('review/store', [ReviewController::class, 'review_store'])
    ->name('review_store');

    Route::get('review/edit/{key}', [ReviewController::class, 'review_edit'])
    ->name('review_edit');

    Route::post('review/update/{key}', [ReviewController::class, 'review_update'])
    ->name('review_update');

    Route::get('review/delete/{key}', [ReviewController::class, 'review_delete'])
    ->name('review_delete');

    Route::resource('dedicated', DedicatedSectionController::class);

    Route::post('dedicated/store', [DedicatedSectionController::class, 'dedicated_store'])
    ->name('dedicated_store');

    Route::get('dedicated/create', [DedicatedSectionController::class, 'dedicated_card_create'])
    ->name('dedicated_card_create');

    Route::post('dedicateds/store', [DedicatedSectionController::class, 'dedicated_card_store'])
    ->name('dedicated_card_store');

    Route::get('dedicated/edit/{key}', [DedicatedSectionController::class, 'dedicated_card_edit'])
    ->name('dedicated_card_edit');

    Route::post('dedicated/update/{key}', [DedicatedSectionController::class, 'dedicated_card_update'])
    ->name('dedicated_card_update');

    Route::get('dedicated/delete/{key}', [DedicatedSectionController::class, 'dedicated_card_delete'])
    ->name('dedicated_card_delete');

    Route::resource('buildtech', BuiltTechSectionController::class);

    Route::post('buildtech/store', [BuiltTechSectionController::class, 'buildtech_store'])
    ->name('buildtech_store');

    Route::get('buildtech/create', [BuiltTechSectionController::class, 'buildtech_card_create'])
    ->name('buildtech_card_create');

    Route::post('buildtechs/store', [BuiltTechSectionController::class, 'buildtech_card_store'])
    ->name('buildtech_card_store');

    Route::get('buildtech/edit/{key}', [BuiltTechSectionController::class, 'buildtech_card_edit'])
    ->name('buildtech_card_edit');

    Route::post('buildtech/update/{key}', [BuiltTechSectionController::class, 'buildtech_card_update'])
    ->name('buildtech_card_update');

    Route::get('buildtech/delete/{key}', [BuiltTechSectionController::class, 'buildtech_card_delete'])
    ->name('buildtech_card_delete');

    Route::resource('packagedetails', PackageDetailsController::class);

    Route::post('packagedetails/store', [PackageDetailsController::class, 'packagedetails_store'])
    ->name('packagedetails_store');


    // *******************// Marketplace Controller starts// ************************//


    // Route::resource('marketplace', MarketPlaceController::class);

    Route::any('marketplace/{slug?}', [MarketPlaceController::class, 'marketplaceindex'])
    ->name('marketplace.index');
    // *******************// Product Main Section Starts// ************************//
    Route::any('marketplace/{slug}/product', [MarketPlaceController::class, 'productindex'])
    ->name('marketplace_product');

    Route::post('marketplace/{slug}/product/store', [MarketPlaceController::class, 'product_main_store'])
    ->name('product_main_store');

    // *******************// Product Main Section Ends// ************************//



    // *******************// Dedicated Section Starts// ************************//

    Route::any('marketplace/{slug}/dedicated', [MarketPlaceController::class, 'dedicatedindex'])
    ->name('marketplace_dedicated');

    Route::post('marketplaces/{slug}/dedicated/store', [MarketPlaceController::class, 'dedicated_theme_header_store'])
    ->name('dedicated_theme_header_store');

    Route::get('marketplace/{slug}/dedicated/create', [MarketPlaceController::class, 'dedicated_theme_create'])
    ->name('dedicated_theme_section_create');

    Route::post('marketplace/{slug}/dedicated/store', [MarketPlaceController::class, 'dedicated_theme_store'])
    ->name('dedicated_theme_section_store');

    Route::get('marketplace/{slug}/dedicated/edit/{key}', [MarketPlaceController::class, 'dedicated_theme_edit'])
    ->name('dedicated_theme_section_edit');

    Route::post('marketplace/{slug}/dedicated/update/{key}', [MarketPlaceController::class, 'dedicated_theme_update'])
    ->name('dedicated_theme_section_update');

    Route::get('marketplace/{slug}/dedicated/delete/{key}', [MarketPlaceController::class, 'dedicated_theme_delete'])
    ->name('dedicated_theme_section_delete');

    // *******************// Dedicated Section ends// ************************//



    // *******************// Whychoose Section Starts// ************************//

    Route::any('marketplace/{slug}/whychoose', [MarketPlaceController::class, 'whychooseindex'])
    ->name('marketplace_whychoose');

    Route::post('marketplace/{slug}/whychoose/store', [MarketPlaceController::class, 'whychoose_store'])
    ->name('whychoose_store');

    Route::get('marketplace/{slug}/create', [MarketPlaceController::class, 'pricing_plan_create'])
    ->name('pricing_plan_create');

    Route::post('marketplace/{slug}/store', [MarketPlaceController::class, 'pricing_plan_store'])
    ->name('pricing_plan_store');

    Route::get('marketplace/{slug}/edit/{key}', [MarketPlaceController::class, 'pricing_plan_edit'])
    ->name('pricing_plan_edit');

    Route::post('marketplace/{slug}/update/{key}', [MarketPlaceController::class, 'pricing_plan_update'])
    ->name('pricing_plan_update');

    Route::get('marketplace/{slug}/delete/{key}', [MarketPlaceController::class, 'pricing_plan_delete'])
    ->name('pricing_plan_delete');

    // *******************// Whychoose Section Ends// ************************//

    // *******************// Screenshot Section Starts// ************************//

    Route::any('marketplace/{slug}/screenshot', [MarketPlaceController::class, 'screenshotindex'])
    ->name('marketplace_screenshot');

    Route::get('marketplace/{slug}/screenshot/create', [MarketPlaceController::class, 'screenshots_create'])
    ->name('marketplace_screenshots_create');

    Route::post('marketplace/{slug}/screenshot/store', [MarketPlaceController::class, 'screenshots_store'])
    ->name('marketplace_screenshots_store');

    Route::get('marketplace/{slug}/screenshot/edit/{key}', [MarketPlaceController::class, 'screenshots_edit'])
    ->name('marketplace_screenshots_edit');

    Route::post('marketplace/{slug}/screenshot/update/{key}', [MarketPlaceController::class, 'screenshots_update'])
    ->name('marketplace_screenshots_update');

    Route::get('marketplace/{slug}/screenshot/delete/{key}', [MarketPlaceController::class, 'screenshots_delete'])
    ->name('marketplace_screenshots_delete');

    // *******************// Screenshot Section Ends// ************************//



    // *******************// Add-on Section Starts// ************************//

    Route::any('marketplace/{slug}/addon', [MarketPlaceController::class, 'addonindex'])
    ->name('marketplace_addon');

    Route::post('marketplaces/{slug}/addon/store', [MarketPlaceController::class, 'addon_store'])
    ->name('addon_store');

    // *******************// Add-on Section Ends// ************************//

    Route::any('image-view/{slug}/{section?}', [LandingPageController::class ,'getInfoImages'])
    ->name('info.image.view');


    // *******************// Page Url Section Starts// ************************//

    Route::any('marketplace/{slug}/page_url', [MarketPlaceController::class, 'pageUrlIndex'])
    ->name('marketplace_page_url');

    Route::get('marketplace/{slug}/page_url/create', [MarketPlaceController::class, 'pageUrlCreate'])
    ->name('marketplace_page_url_create');

    Route::post('marketplace/{slug}/page_url/store', [MarketPlaceController::class, 'pageUrlStore'])
    ->name('marketplace_page_url_store');

    Route::get('marketplace/{slug}/page_url/edit/{key}', [MarketPlaceController::class, 'pageUrlEdit'])
    ->name('marketplace_page_url_edit');

    Route::post('marketplace/{slug}/page_url/update/{key}', [MarketPlaceController::class, 'pageUrlUpdate'])
    ->name('marketplace_page_url_update');

    Route::get('marketplace/{slug}/page_url/delete/{key}', [MarketPlaceController::class, 'pageUrlDelete'])
    ->name('marketplace_page_url_delete');

    // *******************// Page Url Section Ends// ************************//

    // *******************// Embeded Url Section Starts// ************************//

    Route::any('marketplace/{slug}/embeded_url', [MarketPlaceController::class, 'embededUrlIndex'])
    ->name('marketplace_embeded_url');

    Route::get('marketplace/{slug}/embeded_url/create', [MarketPlaceController::class, 'embededUrlCreate'])
    ->name('marketplace_embeded_url_create');

    Route::post('marketplace/{slug}/embeded_url/store', [MarketPlaceController::class, 'embededUrlStore'])
    ->name('marketplace_embeded_url_store');

    Route::get('marketplace/{slug}/embeded_url/edit/{key}', [MarketPlaceController::class, 'embededUrlEdit'])
    ->name('marketplace_embeded_url_edit');

    Route::post('marketplace/{slug}/embeded_url/update/{key}', [MarketPlaceController::class, 'embededUrlUpdate'])
    ->name('marketplace_embeded_url_update');

    Route::get('marketplace/{slug}/embeded_url/delete/{key}', [MarketPlaceController::class, 'embededUrlDelete'])
    ->name('marketplace_embeded_url_delete');

    // *******************// Embeded Url Section Ends// ************************//

});
Route::get('landing-pages/{slug}', [CustomPageController::class, 'customPage'])->name('custom.pages');
Route::post('join_us/store/', [JoinUsController::class, 'joinUsUserStore'])->name('join_us_store')->middleware([ 'web', 'xss']);


