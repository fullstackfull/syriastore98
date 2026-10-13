<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ZidOAuthController;
use App\Services\Zid;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\PlanRequestController;
use App\Http\Controllers\StripePaymentController;
use App\Http\Controllers\PlanCouponController;
use App\Http\Controllers\PaystackPaymentController;
use App\Http\Controllers\RazorpayPaymentController;
use App\Http\Controllers\MercadoPaymentController;
use App\Http\Controllers\SkrillPaymentController;
use App\Http\Controllers\PaymentWallPaymentController;
use App\Http\Controllers\PaypalPaymentController;
use App\Http\Controllers\FlutterwaveController;
use App\Http\Controllers\PaytmPaymentController;
use App\Http\Controllers\MolliePaymentController;
use App\Http\Controllers\CoingateController;
use App\Http\Controllers\SspayController;
use App\Http\Controllers\ToyyibpayController;
use App\Http\Controllers\PaytabsController;
use App\Http\Controllers\IyziPayController;
use App\Http\Controllers\BankTransferController;
use App\Http\Controllers\PayFastController;
use App\Http\Controllers\BenefitPaymentController;
use App\Http\Controllers\CashfreeController;
use App\Http\Controllers\AamarpayController;
use App\Http\Controllers\PaytrController;
use App\Http\Controllers\YookassaController;
use App\Http\Controllers\XenditPaymentController;
use App\Http\Controllers\MidtransController;
use App\Http\Controllers\EmailTemplateController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ShippingController;
use App\Http\Controllers\ShippingZoneController;
use App\Http\Controllers\ShippingMethodController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\StateController;
use App\Http\Controllers\CityController;
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\PixelFieldsController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\BlogCategoryController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\TaxController;
use App\Http\Controllers\TaxOptionController;
use App\Http\Controllers\TaxMethodController;
use App\Http\Controllers\FlashSaleController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\TestimonialController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\SubCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\DeliveryBoyController;
use App\Http\Controllers\ProductAttributeController;
use App\Http\Controllers\ProductAttributeOptionController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\MenuItemsController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\AppSettingController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\ProductQuestionController;
use App\Http\Controllers\AccountProfileController;
use App\Http\Controllers\AddonController;
use App\Http\Controllers\WishlistController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\AITemplateController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\Customer\Auth\CustomerLoginController;
use App\Http\Controllers\ThemeAnalyticController;
use App\Http\Controllers\OrderNoteController;
use App\Http\Controllers\WoocomCategoryController;
use App\Http\Controllers\WoocomProductController;
use App\Http\Controllers\WoocomCustomerController;
use App\Http\Controllers\WoocomCouponController;
use App\Http\Controllers\ShopifyProductController;
use App\Http\Controllers\ShopifyCategoryController;
use App\Http\Controllers\ShopifyCustomerController;
use App\Http\Controllers\ShopifyCouponController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\OrderRefundController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\NepalstePaymnetController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\KhaltiPaymnetController;
use App\Http\Controllers\PayHerePaymnetController;
use App\Http\Controllers\ProductBrandController;
use App\Http\Controllers\AuthorizeNetPaymnetController;
use App\Http\Controllers\TapPaymnetController;
use App\Http\Controllers\PhonePePaymentController;
use App\Http\Controllers\PaddlePaymentController;
use App\Http\Controllers\PaiementProPaymentController;
use App\Http\Controllers\FedPayPaymentController;
use App\Http\Controllers\ProductLabelController;
use App\Http\Controllers\CinetPayController;
use App\Http\Controllers\SenangPayController;
use App\Http\Controllers\CyberSourceController;
use App\Http\Controllers\OzowController;
use App\Http\Controllers\EasebuzzController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\MyFatoorahController;
use App\Http\Controllers\NMIPayController;
use App\Http\Controllers\PayUPaymentController;
use App\Http\Controllers\OfertemagController;
use App\Http\Controllers\PaynowController;
use App\Http\Controllers\SofortController;
use App\Http\Controllers\ESewaPaymentController;
use App\Http\Controllers\DPOPayController;
use App\Http\Controllers\BraintreeController;
use App\Http\Controllers\PowertranzPaymentController;
use App\Http\Controllers\SSLCommerzPaymentController;
use App\Http\Controllers\PaymentSettingController;
use App\Http\Controllers\ThemeCustomizeController;
use App\Http\Controllers\DeliverySyriaController;
use App\Http\Controllers\newsyria\ChatwootIntegrationController;
use App\Http\Controllers\newsyria\AbandonedCartController;
use App\Http\Controllers\OtpIntegrationController;
use App\Http\Controllers\StoreFinanceController;
use App\Http\Controllers\CurrencyRateController;

use Illuminate\Support\Facades\Http;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/









// NEW-finance-authorization / NEW-withdrawal-authz / SEC-R5-01.
//
// The permission group below used to close immediately after the `/finance` GET,
// leaving EVERY finance mutator with no route middleware at all -- not even
// `auth`. The effective gate was `where('store_id', getCurrentStore())` inside
// the controller, which rejects an anonymous caller only by the accident of SQL
// NULL semantics, and does not constrain an authenticated caller's permissions
// at all. Reachable today by any authenticated web-guard user whose
// current_store resolves, with NO permission -- i.e. merchant staff escalating
// past 'View Finance Withdrawal Requests'.
//
// CORRECTION TO THIS COMMENT'S EARLIER CLAIM. It previously said "every
// permission name below already exists and is already used by this same
// controller. None is invented." The second half was right -- the names match
// the controller's own call sites -- but "already exists" was wrong, and the
// distinction turned out to be the whole problem. The names exist as CALL SITES.
// They do not exist as ROWS. Utility::addNewData() is the only mechanism that
// creates permission rows and contains zero occurrences of "Finance", so on a
// database built from source all six resolve to "denied" for everybody.
// Verified by execution: isAbleTo() returned false for a super admin on all six.
// One of them, View Finance Dashboard, was already in the baseline here, so this
// is pre-existing rather than introduced.
//
// These routes therefore use `finance.permission:<name>`, which routes through
// the `finance` gate in AuthServiceProvider and has a super-admin path that
// needs no seeded row. Two alternatives were tried and rejected, both verified
// by execution rather than by reading:
//
//   permission:<name>   Laratrust's own alias. Resolves purely through the pivot
//                       tables, no super-admin bypass -> 403 for everyone.
//   can:finance,<name>  Laravel's alias. Authorize::getModel() turns an UNQUOTED
//                       literal into NULL, so the gate received null and raised
//                       "Argument #2 ($permission) must be of type string" on the
//                       first request. A quoted form works but makes an
//                       authorization control depend on quote characters inside a
//                       middleware string.
//
// Seeding the six names in Utility::addNewData() is still the clean completion
// and is reported as a cross-agent dependency (Agent 2 owns Utility.php). The
// gate keeps the platform operable without it and keeps delegated users working
// once it lands. Pinned by tests/Feature/Security/FinancePermissionTest.php.
//
// CurrencyRateController::update already calls auth()->id() at :40, so it
// assumes an authenticated user; it simply never had `auth` applied.
Route::middleware(['auth'])->group(function () {

    Route::get(
        '/currency-rates',
        [CurrencyRateController::class, 'index']
    )->name('currency-rates.index');

    Route::post(
        '/currency-rates/{id}',
        [CurrencyRateController::class, 'update']
    )->name('currency-rates.update');

    Route::get('/finance', [StoreFinanceController::class, 'index'])
        ->middleware('finance.permission:View Finance Dashboard')
        ->name('finance.index');

    Route::post('/finance/add-balance', [StoreFinanceController::class, 'addBalance'])
        ->middleware('finance.permission:Add Finance Balance')
        ->name('finance.add.balance');

    Route::post('/finance/deduct-balance', [StoreFinanceController::class, 'deductBalance'])
        ->middleware('finance.permission:Deduct Finance Balance')
        ->name('finance.deduct.balance');

    Route::post(
        '/finance/withdrawal/request',
        [StoreFinanceController::class, 'requestWithdrawal']
    )->middleware('finance.permission:Request Finance Withdrawal')
     ->name('finance.withdrawal.request');

    Route::get('/finance/withdrawals', [StoreFinanceController::class, 'withdrawals'])
        ->middleware('finance.permission:View Finance Withdrawal Requests')
        ->name('finance.withdrawals');

    // approve / reject / pay had NO authorization of any kind, in the route file
    // or in the controller. The controller halves are now guarded too
    // (StoreFinanceController::approveWithdrawal / rejectWithdrawal /
    // payWithdrawal), so removing this middleware no longer leaves them open.
    //
    // NEW-withdrawal-view-permission-authorises-state-change -- FIXED HERE.
    //
    // These three used to carry `finance.permission:View Finance Withdrawal
    // Requests`: a VIEW permission authorising three state-changing,
    // money-moving actions, so anyone who could READ the withdrawal list could
    // also APPROVE and PAY. The comment that used to sit here flagged exactly
    // that and left it standing; it is closed now.
    //
    // The guard requires ALL of StoreFinanceController::
    // WITHDRAWAL_REVIEW_PERMISSIONS -- the view permission AND 'Edit Finance
    // Payment Status'. `finance.permission:A,B` is AND, never OR
    // (App\Http\Middleware\FinancePermission::handle, which also fails closed
    // on an empty parameter list). A delegated user holding only the view
    // permission keeps the list at finance.withdrawals and loses approve/pay.
    //
    // WHY NOT A DEDICATED 'Approve Finance Withdrawal' PERMISSION. It would be
    // the cleaner split, and it is reported as an owner decision rather than
    // taken here, because a permission name absent from Utility::addNewData()
    // cannot exist as a ROW -- and that is the precise defect that made all six
    // finance names 403 for every user including the platform operator. A
    // seventh unseeded name would recreate that lockout for every delegated
    // user. Both names used here are already seeded (Utility.php:2881-2886).
    //
    // The parameter list is built from the controller's own constant so the
    // route guard and the controller guard cannot drift apart. The controller's
    // DB::transaction + lockForUpdate are deliberately left untouched.
    $withdrawalReviewGate = 'finance.permission:'
        . implode(',', StoreFinanceController::WITHDRAWAL_REVIEW_PERMISSIONS);

    Route::post('/finance/withdrawals/{id}/approve', [StoreFinanceController::class, 'approveWithdrawal'])
        ->middleware($withdrawalReviewGate)
        ->name('finance.withdrawals.approve');

    Route::post('/finance/withdrawals/{id}/reject', [StoreFinanceController::class, 'rejectWithdrawal'])
        ->middleware($withdrawalReviewGate)
        ->name('finance.withdrawals.reject');

    Route::post('/finance/withdrawals/{id}/pay', [StoreFinanceController::class, 'payWithdrawal'])
        ->middleware($withdrawalReviewGate)
        ->name('finance.withdrawals.pay');

    Route::post(
        '/finance/order-financials/{id}/payment-status',
        [StoreFinanceController::class, 'updateFinancialPaymentStatus']
    )->middleware('finance.permission:Edit Finance Payment Status')
     ->name('finance.order-financial.status');
});











// NEW-ANON-SETTINGS-ROUTES-01. These five declarations sat at the TOP LEVEL of this
// file, outside every group, so they carried only the `web` group: no auth, no
// verified, nothing. They are MERCHANT SETTINGS endpoints -- the only callers in
// either repository are resources/views/setting/otp_setting.blade.php:8 and :380 and
// resources/views/setting/partials/abandoned-carts.blade.php:411, all admin-side
// screens. No theme and no storefront view references any of them, so nothing
// anonymous is lost by requiring a session.
//
// MEASURED ANONYMOUSLY, before the fix, with no session at all:
//   POST /otp-integration                  -> 500 (it reached the write; the 500 is
//                                             the undeclared `otp_integrations`
//                                             table, which is in the GATE F family)
//   GET  /otp-integration                  -> 500 (same, through the view)
//   POST /chatwoot/abandoned-carts/send    -> 302 from the CONTROLLER, i.e. it ran
//                                             the loop over caller-supplied
//                                             customer_ids
//
// WHY IT MATTERS MORE THAN A JUNK ROW. OtpIntegrationController::save() writes
// `OtpIntegration::firstOrNew(['store_id' => $storeId])` and $storeId is
// getCurrentStore(), which is NULL for an anonymous caller by documented decision
// (R4-02, app/Helper/helper.php:357-381). Illuminate's Builder::where() turns a null
// value into `whereNull`, so the row an anonymous caller writes with store_id NULL is
// the SAME row that OtpIntegrationController::test() then reads back with
// `OtpIntegration::where('store_id', $storeId)->first()`. test() posts to
// $integration->base_url. The two endpoints together were therefore an anonymous
// outbound-request primitive with a caller-chosen URL -- an SSRF, reachable without
// any credential, and the stored api_secret is attacker-chosen too.
//
// Gated with the same stack the rest of the admin surface uses. A permission check
// on top of `auth` would be better still and is NOT added here: the controllers are
// not this workstream's files, and a permission name that Utility::addNewData() does
// not seed cannot exist as a row -- the precise defect that made all six finance
// names 403 for everyone. Recorded as a cross-agent dependency.
//
// Pinned in both directions by tests/Feature/Security/AnonymousSettingsRoutesTest.
Route::middleware(['auth', 'xss', 'setlocate', 'verified'])->group(function () {
    Route::post(
        '/otp-integration/test',
        [OtpIntegrationController::class, 'test']
    )->name('otp.integration.test');

    Route::get('/otp-integration', [OtpIntegrationController::class, 'index'])
        ->name('otp.integration');

    Route::post('/otp-integration', [OtpIntegrationController::class, 'save'])
        ->name('otp.integration.save');









    Route::get(
        '/chatwoot/abandoned-carts',
        [AbandonedCartController::class, 'index']
    )->name('chatwoot.abandoned-carts');

    // Anonymous before this group existed: it validated `customer_ids` off the
    // request and asked ChatwootAbandonedCartNotificationService to message each one
    // (app/Http/Controllers/newsyria/AbandonedCartController.php:49-80), so an
    // unauthenticated caller could drive outbound WhatsApp/Chatwoot traffic at
    // caller-chosen customer ids. Measured: it answered 302 FROM THE CONTROLLER.
    Route::post(
        '/chatwoot/abandoned-carts/send',
        [AbandonedCartController::class, 'send']
    )->name('chatwoot.abandoned-carts.send');
});





Route::post(
    '/settings/chatwoot/connect',
    [ChatwootIntegrationController::class, 'connect']
)->name('chatwoot.connect')->middleware('auth');


Route::post(
    '/settings/chatwoot/inbox',
    [ChatwootIntegrationController::class, 'saveInbox']
)->name('chatwoot.inbox.save')->middleware('auth');

Route::get(
    '/settings/chatwoot/inboxes',
    [ChatwootIntegrationController::class, 'getInboxes']
)->name('chatwoot.inboxes')->middleware('auth');

Route::get(
    '/settings/chatwoot/templates',
    [ChatwootIntegrationController::class, 'getTemplates']
)->name('chatwoot.templates')->middleware('auth');

Route::post(
    '/settings/chatwoot/notification-templates',
    [ChatwootIntegrationController::class, 'saveNotificationTemplates']
)->name('chatwoot.notification.templates.save')->middleware('auth');

Route::get(
    '/settings/chatwoot/notification-templates',
    [ChatwootIntegrationController::class, 'getNotificationTemplates']
)->name('chatwoot.notification.templates')->middleware('auth');









Route::get(
    '/settings/chatwoot/test-order-notification/{order}',
    [ChatwootIntegrationController::class, 'testOrderNotification']
)->name('chatwoot.test.order.notification')->middleware('auth');












Route::get('/store/paymera/callback/{orderId}', [PaymentController::class, 'handlePaymeraCallback'])->name('paymera.callback');




Route::middleware(['web'])->group(function () {
  // Route::get('/', function () {
  //     return view('welcome');
  // });
  require __DIR__ . '/auth.php';

  //Route::get('/', [HomeController::class, 'Landing'])->name('landing')->middleware('setlocate');

  Route::get('change-languages/{lang}', [LanguageController::class, 'changelanguage'])->name('changelanguage')->middleware('setlocate');

  Route::get('add-on/details/{slug}', [HomeController::class, 'SoftwareDetails'])->name('software.details')->middleware('setlocate');

  // module page before login
  Route::get('add-on', [HomeController::class, 'Software'])->name('apps.software');
  Route::any('/', [HomeController::class, 'Landing'])->name('start')->middleware('setlocate');
  Route::get('pricing', [HomeController::class, 'Pricing'])->name('apps.pricing');

  // Session Save Addon Routes
  Route::get('add-on/session/create', [ModuleController::class, 'AddOnSessioncreate'])->name('add-on.session.create');
  Route::post('add-on/session/save', [ModuleController::class, 'AddOnSessionSave'])->name('add-on.session.save');

  Route::middleware(['auth', 'xss', 'setlocate', 'verified'])->group(function () {

    Route::get('/dashboard', [HomeController::class, 'index'])->name('dashboard');
    Route::post('/top-brand-category-chart', [HomeController::class, 'top_brand_category_chart'])->name('top.brand.category.chart');

    Route::get('themean-alytic', [ThemeAnalyticController::class, 'index'])->name('theme_analytic');
    // NEW-ROUTE-SHADOW-01. This declaration USED TO BE `Route::get('/profile', ...)`,
    // which shares a method and a URI with routes/web.php:865
    // `Route::get('profile', [UserController::class, 'profile'])->name('profile')`.
    // Illuminate\Routing\RouteCollection indexes routes by method+domain+URI and
    // rebuilds the name lookup from that index, so the later declaration replaced this
    // one outright and the NAME `profile.edit` disappeared from the registry with no
    // error. THE DEFECT WAS LATENT, NOT LIVE. An earlier draft of this comment claimed
    // every successful PATCH /profile wrote the record and then 500ed; that was inferred
    // from the Redirect call, and it is measurably wrong. Nothing a merchant can reach
    // posted to PATCH /profile. The profile screen this product ships is
    // resources/views/users/profile.blade.php, whose form is
    // `Form::model($userDetail, array('route' => array('update.account'), ...))` at :47 --
    // a POST to UserController@editprofile (:925), which never enters ProfileController.
    // The only poster to auth.profile.update is the Breeze partial
    // resources/views/profile/partials/update-profile-information-form.blade.php:16,
    // included solely by resources/views/profile/edit.blade.php, which only
    // ProfileController@edit renders -- that is, the very GET this shadowing had dropped.
    // The two route('profile.edit') links in
    // resources/views/layouts/navigation.blade.php:37,83 could not 500 either: nothing
    // extends or includes that view. Proven positively by
    // tests/Feature/Platform/ProductProfileSavePathTest, which saves successfully on a
    // tree where route('profile.edit') genuinely throws. The repair below still stands:
    // the name belongs in the registry and the Breeze screen needs its own URI.
    //
    // THE URI MOVES, THE NAME AND THE ACTION DO NOT. GET /profile deliberately keeps
    // serving UserController@profile under the name `profile`: that is the profile
    // screen this product ships, it is what resources/views/partision/header.blade.php
    // links to, and five GoogleAuthentication callbacks redirect to route('profile').
    // Taking that URI for the Breeze screen instead would silently change which page a
    // merchant sees at /profile, which is a product change, not a defect repair.
    // Pinned from both sides by tests/Feature/Platform/RouteRegistrationIntegrityTest
    // and tests/Feature/Security/RouteShadowingTest.
    Route::get('/profile/edit', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('auth.profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::resource('roles', RoleController::class);
    Route::resource('users', UserController::class);
    Route::get('user-info/{id}', [UserController::class, 'userInfo'])->name('user.info');
    Route::post('user-unable', [UserController::class, 'userUnable'])->name('user.unable');
    Route::get('user-enable-login/{id}', [UserController::class, 'userLoginManage'])->name('users.enable.login');

    //*************************country-state-city**********************
    Route::resource('countries', CountryController::class);
    Route::resource('state', StateController::class);
    Route::resource('city', CityController::class);
    Route::get('/stock-report/low-stock', [ReportController::class, 'getLowStockProducts'])->name('stockReport.lowStock');
    Route::get('/stock-report/out-stock', [ReportController::class, 'getOutOfStockProducts'])->name('stockReport.outOfStock');
    Route::get('/stock-report/most-stocked', [ReportController::class, 'getMostStockedProducts'])->name('stockReport.mostStocked');

    Route::post('/get-country', [CountryController::class, 'getCountry'])->name('get.country');
    Route::post('/country-tab', [CountryController::class, 'getAllCountry'])->name('country.tab');
    Route::post('/get-state', [StateController::class, 'getState'])->name('get.state');
    Route::post('/get-city-state', [StateController::class, 'getCityState'])->name('getcitystate');
    Route::get('/get-all-city', [StateController::class, 'getAllState'])->name('get.all.state');

    Route::resource('plan', PlanController::class);

    //strip
    Route::get('/stripe/{code}', [StripePaymentController::class, 'stripe'])->name('stripe.plan');

    Route::get('/refund/{id}/{user_id}', [PlanController::class, 'refund'])->name('plan.order.refund');

    Route::resource('plan-request', PlanRequestController::class);
    Route::get('request-send/{id}', [PlanRequestController::class, 'userRequest'])->name('send.request');
    Route::get('request-cancel/{id}', [PlanRequestController::class, 'cancelRequest'])->name('request.cancel');
    Route::get('request-response/{id}/{response}', [PlanRequestController::class, 'acceptRequest'])->name('response.request');

    // *************************PlanCoupon*****************************
    Route::resource('plan-coupon', PlanCouponController::class);
    Route::get('/apply-coupon', [PlanCouponController::class, 'applyCoupon'])->name('apply.coupon');
    Route::resource('setting', SettingController::class);







    // *************************newcode*****************************

Route::post('/store/paymera/trigger/{orderId}', [PaymentController::class, 'handlePaymeraTrigger'])->name('paymera.trigger');




         Route::post('/delivery-syria/update', [SettingController::class, 'saveDeliverySyria'])->name('delivery.syria.update');



Route::get(
    '/delivery-syria',
    [DeliverySyriaController::class, 'index']
)->name('delivery-syria.index');



    Route::post('storage-settings', [SettingController::class, 'StorageSettings'])->name('storage.settings');
    Route::post('business-settings', [SettingController::class, 'BusinessSettings'])->name('business.settings');
    Route::post('/payment-setting', [PaymentSettingController::class, 'update'])->name('payment.settings');
    Route::post('/setting-form', [SettingController::class, 'settingForm'])->name('setting.form');

    Route::get('plan/prepare-amount', [PlanController::class, 'planPrepareAmount'])->name('plan.prepare.amount');

    // cinetpay
    Route::post('/plan-pay-with-cinetpay', [CinetPayController::class, 'planPayWithCinetpay'])->name('plan.pay.with.cinetpay');
    Route::match(['get', 'post'], '/plan-cinetpay-payment-return', [CinetPayController::class, 'return_url'])->name('plan.cinetpay.return');
    Route::match(['get', 'post'], '/plan-cinetpay-payment-notify', [CinetPayController::class, 'notify_url'])->name('plan.get.cinetpay.notify');


    // strip
    Route::get('/plan-stripe/{code}', [StripePaymentController::class, 'stripe'])->name('stripe');
    Route::post('plan-stripe-payment', [StripePaymentController::class, 'addpayment'])->name('stripe.payment');

    // Paystack
    Route::post('/plan-pay-with-paystack', [PaystackPaymentController::class, 'planPayWithPaystack'])->name('plan.pay.with.paystack');
    Route::get('/plan-paystack-payment/{pay_id}/{plan_id}', [PaystackPaymentController::class, 'getPaymentStatus'])->name('plan.paystack');


    Route::post('/plan-pay-with-razorpay', [RazorpayPaymentController::class, 'planPayWithRazorpay'])->name('plan.pay.with.razorpay');
    Route::get('/plan-razorpay-payment/{txref}/{plan_id}', [RazorpayPaymentController::class, 'getPaymentStatus'])->name('plan.razorpay');

    Route::post('/plan-pay-with-mercado', [MercadoPaymentController::class, 'planPayWithMercado'])->name('plan.pay.with.mercado');
    Route::get('/plan-mercado-payment/{plan}', [MercadoPaymentController::class, 'getPaymentStatus'])->name('plan.mercado');


    Route::post('/plan-pay-with-skrill', [SkrillPaymentController::class, 'planPayWithSkrill'])->name('plan.pay.with.skrill');
    Route::get('/plan-skrill-payment/{plan}', [SkrillPaymentController::class, 'getPaymentStatus'])->name('plan.skrill');


    Route::post('/plan-pay-with-paymentwalls', [PaymentWallPaymentController::class, 'paymentwall'])->name('plan.paymentwallpayment');
    Route::post('/plan-paymentwalls-payment/{plan}', [PaymentWallPaymentController::class, 'planPayWithPaymentWall'])->name('plan.pay.with.paymentwall');
    Route::get('/plan/{flag}', [PaymentWallPaymentController::class, 'planeerror'])->name('error.plan.show');


    Route::post('plan-pay-with-paypal', [PaypalPaymentController::class, 'addpayment'])->name('paypal.payment');
    Route::get('{id}/{amount}/plan-paypal-payment/', [PaypalPaymentController::class, 'planGetPaymentStatus'])->name('plan.get.payment.status');


    Route::post('/plan-pay-with-flutterwave', [FlutterwaveController::class, 'addpayment'])->name('flutterwave.payment');
    Route::get('/plan-flaterwave-payment/{txref}/{plan_id}', [FlutterwaveController::class, 'getPaymentStatus'])->name('plan.flaterwave');


    Route::post('/plan-pay-with-paytm', [PaytmPaymentController::class, 'planPayWithPaytm'])->name('plan.pay.with.paytm');
    Route::post('/plan-paytm-payment/{plan_id}', [PaytmPaymentController::class, 'getPaymentStatus'])->name('plan.paytm');


    Route::post('/plan-pay-with-mollie', [MolliePaymentController::class, 'planPayWithMollie'])->name('plan.pay.with.mollie');
    Route::get('/plan-mollie-payment/{plan}', [MolliePaymentController::class, 'getPaymentStatus'])->name('plan.mollie');

    Route::post('plan-pay-with-coingate', [CoingateController::class, 'coingatePaymentPrepare'])->name('coingate.prepare.plan');
    Route::get('plan-coingate-payment', [CoingateController::class, 'coingatePlanGetPayment'])->name('coingate.coingate.callback');

    Route::post('plan-pay-with-sspay', [SspayController::class, 'SspayPaymentPrepare'])->name('sspay.prepare.plan');
    Route::get('plan-sspay-payment/{plan_id}/{amount}/{couponCode}', [SspayController::class, 'SspayPlanGetPayment'])->name('plan.sspay.callback');

    Route::post('plan-pay-with-toyyibpay', [ToyyibpayController::class, 'toyyibpayPaymentPrepare'])->name('toyyibpay.prepare.plan');
    Route::get('plan-toyyibpay-payment/{plan_id}/{amount}/{couponCode}', [ToyyibpayController::class, 'toyyibpayPlanGetPayment'])->name('plan.toyyibpay.callback');

    Route::post('plan-pay-with-paytabs', [PaytabsController::class, 'PaytabsPaymentPrepare'])->name('paytabs.prepare.plan');
    Route::any('/plan-paytabs-payment', [PaytabsController::class, 'planGetPaymentStatus'])->name('plan.paytabs.callback');

    Route::post('plan-pay-with-iyzipay', [IyziPayController::class, 'initiatePayment'])->name('iyzipay.payment.init');
    Route::post('iyzipay/callback/plan/{id}/{amount}/{coupan_code?}', [IyziPayController::class, 'iyzipayCallback'])->name('iyzipay.payment.callback');

    Route::post('plan-pay-with-bank', [BankTransferController::class, 'planPayWithbank'])->name('plan.pay.with.bank');
    Route::get('plan/orders/show/{id}', [BankTransferController::class, 'show'])->name('plan.order.show');
    Route::delete('/bank_transfer/{order}/', [BankTransferController::class, 'destroy'])->name('bank_transfer.destroy');
    Route::any('order_approve/{id}', [BankTransferController::class, 'orderapprove'])->name('order.approve');
    Route::any('order_reject/{id}', [BankTransferController::class, 'orderreject'])->name('order.reject');

    Route::post('plan-pay-with-payfast', [PayFastController::class, 'index'])->name('payfast.payment');
    Route::any('plan-payfast-payment/{success}', [PayFastController::class, 'success'])->name('payfast.payment.success');

    Route::any('plan-pay-with-Benefit', [BenefitPaymentController::class, 'initiatePayment'])->name('benefit.initiate');
    Route::any('plan-Benefit-payment', [BenefitPaymentController::class, 'call_back'])->name('benefit.call_back');

    Route::post('plan-pay-with-cashfree', [CashfreeController::class, 'cashfreePayment'])->name('cashfree.payment');
    Route::any('cashfree/payments/success', [CashfreeController::class, 'cashfreePaymentSuccess'])->name('cashfreePayment.success');


    Route::post('aamarpay/payment', [AamarpayController::class, 'paywithaamarpay'])->name('pay.aamarpay.payment');
    Route::any('aamarpay/success/{data}', [AamarpayController::class, 'aamarpaysuccess'])->name('pay.aamarpay.success');

    Route::post('plan-pay-with-paytr', [PaytrController::class, 'PlanpayWithPaytr'])->name('plan.pay.with.paytr');
    Route::any('plan-paytr-payment/success/', [PaytrController::class, 'paytrsuccessCallback'])->name('pay.paytr.success');

    Route::post('plan-pay-with-yookassa', [YookassaController::class, 'paywithyookassa'])->name('pay.yookassa.payment');
    Route::get('/plan-yookassa-payment/success', [YookassaController::class, 'planGetYooKassaStatus'])->name('plan.get.yookassa.status');

    Route::get('plan-pay-with-Xendit', [XenditPaymentController::class, 'PaywithXendit'])->name('pay.Xendit.payment');
    Route::any('plan-xendit-payment/status', [XenditPaymentController::class, 'planGetXenditStatus'])->name('plan.xendit.status');


    Route::post('plan-pay-with-midtrans', [MidtransController::class, 'paywithMidtrans'])->name('pay.midtrans.payment');
    Route::any('/plan-midtrans-payment', [MidtransController::class, 'planGetMidtransStatus'])->name('plan.get.midtrans.status');
    Route::post('/recaptcha-settings', [SettingController::class, 'RecaptchaSetting'])->name('recaptcha.settings');
    Route::post('loyality-program-settings', [SettingController::class, 'LoyalityProgramSettings'])->name('loyality.program.settings');
    Route::resource('pixel-setting', PixelFieldsController::class);

    Route::post('plan-pay-with-nepalste', [NepalstePaymnetController::class, 'planPayWithnepalste'])->name('pay.nepalste.payment');
    Route::get('nepalste/status/', [NepalstePaymnetController::class, 'planGetNepalsteStatus'])->name('nepalste.status');
    Route::get('nepalste/cancel/', [NepalstePaymnetController::class, 'planGetNepalsteCancel'])->name('nepalste.cancel');

    Route::post('plan-disable', [PlanController::class, 'planDisable'])->name('plan.disable');
    Route::get('plan-trial/{id}', [PlanController::class, 'planTrial'])->name('plan.trial');

    Route::post('plan-pay-with-khalti', [KhaltiPaymnetController::class, 'planPayWithKhalti'])->name('pay.khalti.payment');
    Route::post('plan-get-khalti-status', [KhaltiPaymnetController::class, 'planGetKhaltiStatus'])->name('plan.get.khalti.status');

    Route::post('plan-pay-with-payhere', [PayHerePaymnetController::class, 'planPayWithPayHere'])->name('pay.payhere.payment');
    Route::any('plan-get-payhere-status/{plan_id}', [PayHerePaymnetController::class, 'planGetPayHereStatus'])->name('plan.get.payhere.status');

    Route::post('plan-pay-with-authorizenet', [AuthorizeNetPaymnetController::class, 'planPayWithAuthorizeNet'])->name('pay.authorizenet.payment');
    Route::post('/plan-get-authorizenet-status', [AuthorizeNetPaymnetController::class, 'planPayWithAuthorizeNetData'])->name('plan.get.authorizenet.status');

    Route::post('plan-pay-with-tap', [TapPaymnetController::class, 'planPayWithTap'])->name('pay.tap.payment');
    Route::get('/plan-get-tap-status', [TapPaymnetController::class, 'planGetTapStatus'])->name('plan.get.tap.status');

    Route::post('plan-pay-with-phonepe', [PhonePePaymentController::class, 'planPayWithPhonePe'])->name('pay.phonepe.payment');
    Route::any('/plan-get-phonepe-status', [PhonePePaymentController::class, 'planGetPhonePeStatus'])->name('plan.get.phonepe.status');

    Route::post('plan-pay-with-paddle', [PaddlePaymentController::class, 'planPayWithPaddle'])->name('pay.paddle.payment');
    Route::any('/plan-get-paddle-status', [PaddlePaymentController::class, 'planGetPaddleStatus'])->name('plan.get.paddle.status');
    Route::any('/plan-get-paddle', [PaddlePaymentController::class, 'planGetStatus'])->name('plan.get.paddle');

    Route::post('plan-pay-with-paiementpro', [PaiementProPaymentController::class, 'planPayWithPaiementpro'])->name('pay.paiementpro.payment');
    Route::any('/plan-get-paiementpro-status', [PaiementProPaymentController::class, 'planGetPaiementproStatus'])->name('plan.get.paiementpro.status');

    Route::post('plan-pay-with-fedpay', [FedPayPaymentController::class, 'planPayWithFedPay'])->name('pay.fedpay.payment');
    Route::any('/plan-get-fedpay-status', [FedPayPaymentController::class, 'planGetFedPayStatus'])->name('plan.get.fedpay.status');

    // easebuzz
    Route::post('/plan-pay-with-easebuzz', [EasebuzzController::class, 'planPayWithEasebuzz'])->name('plan.pay.with.easebuzz');
    Route::match(['get', 'post'], '/plan-easebuzz-payment-return', [EasebuzzController::class, 'return_url'])->name('plan.easebuzz.return');
    Route::match(['get', 'post'], 'plan-easebuzz-payment-notify', [EasebuzzController::class, 'notify_url'])->name('plan.get.easebuzz.notify');

    //nmi
    Route::post('/plan/company/nmi', [NMIPayController::class, 'planPayWithNMI'])->name('plan.pay.with.nmi');
    Route::any('/plan/company/nmi/payment/{plan_id}', [NMIPayController::class, 'planPayWithNMIView'])->name('plan.pay.with.nmi.view');

    //PayU
    Route::post('/plan-pay-with-payu', [PayUPaymentController::class, 'planPayWithPayU'])->name('plan.pay.with.payu');
    Route::any('/plan-payu-payment/status/{plan_id}', [PayUPaymentController::class, 'planGetPayUStatus'])->name('plan.payu.status');
    
    // Paynow
    Route::post('plan-pay-with/paynow', [PaynowController::class, 'planPayWithPaynow'])->name('plan.pay.with.Paynow');
    Route::get('plan-get-Paynow-status/{plan_id}', [PaynowController::class, 'planGetPaynowStatus'])->name('plan.get.Paynow.status');

    //MyFatoorah
    Route::post('/plan/company/myfatoorah', [MyFatoorahController::class, 'planPayWithmyfatoorah'])->name('plan.pay.with.myfatoorah');
    Route::any('/myfatoorah/call_back', [MyFatoorahController::class, 'paymentCallback'])->name('myfatoorah.call_back');
    // Sofort
    Route::post('plan-sofort-payment', [SofortController::class, 'addpayment'])->name('sofort.payment');
    Route::post('/plan-pay-with-soforts', [SofortController::class, 'failure'])->name('plan.payment.failure');
    Route::any('/plan-pay-with-sofort', [SofortController::class, 'success'])->name('plan.payment.success');

    // ESewa
    Route::post('plan-esewa-payment', [ESewaPaymentController::class, 'addpayment'])->name('esewa.payment');
    Route::post('/plan-pay-with-esewa', [ESewaPaymentController::class, 'failure'])->name('plan.esewa.payment.failure');
    Route::any('/plan-pay-with-esewa', [ESewaPaymentController::class, 'success'])->name('plan.esewa.payment.success');

    // DPO
    Route::any('plan-pay-with/DPO/create', [DPOPayController::class, 'create'])->name('plan.dpo.view');
    Route::any('plan-pay-with/DPO', [DPOPayController::class, 'planPayWithDPOPay'])->name('plan.pay.with.dpo');
    Route::any('plan-get-DPO/{plan_id}', [DPOPayController::class, 'planGetDPOPayStatus'])->name('plan.get.dpo.status');

    //Braintree

    Route::post('/plan/payment', [BraintreeController::class, 'planPayWithBraintree'])->name('plan.pay.with.braintree');
    Route::post('/plan/payment/status', [BraintreeController::class, 'planGetBraintreeStatus'])->name('plan.braintree.status');
    Route::get('pay', [BraintreeController::class, 'pay'])->name('braintree.pay');

    //Powertranz
    Route::any('plan-pay-with/Powertranz/create', [PowertranzPaymentController::class, 'create'])->name('plan.Powertranz.view');
    Route::any('plan-pay-with/Powertranz', [PowertranzPaymentController::class, 'planPayWithPowertranz'])->name('plan.pay.with.Powertranz');
    Route::any('plan-get-Powertranz-status/{plan_id}', [PowertranzPaymentController::class, 'planGetPowertranzStatus'])->name('plan.get.Powertranz.status');

    //SSLCommerz
    Route::post('plan-pay-with/sslcommerz', [SSLCommerzPaymentController::class, 'planPayWithSSLCommerz'])->name('plan.sslcommerz.view');
    Route::any('/plan/sslcommerz/status/{plan_id}', [SSLCommerzPaymentController::class, 'planGetSSLCommerzStatus'])->name('plan.get.sslcommerz.status');

    ///*************************cache setting******************** ***/
    Route::get('/config-cache', function () {
      session()->put(['setting_tab' => 'cache_setting']);
      Artisan::call('optimize');
      Artisan::call('cache:clear');
      Artisan::call('route:clear');
      Artisan::call('view:clear');
      Artisan::call('optimize:clear');
      Artisan::call('config:clear');
      return redirect()->back()->with('success', 'Clear Cache successfully.');
    })->withoutMiddleware('setlocate');

    // **************************style custmization**************************
    Route::post('/customize-settings', [SettingController::class, 'CustomizeSetting'])->name('customize.settings');
    Route::post('/meta-seo-settings', [SettingController::class, 'SEOSetting'])->name('meta-seo.settings');

    Route::post('twilio-settings', [SettingController::class, 'TwilioSettings'])->name('twilio.settings');
    Route::post('chatgpt-setting', [SettingController::class, 'ChatgptSettings'])->name('chatgpt.setting');
    Route::post('stock-settings', [SettingController::class, 'StockSettings'])->name('stock.settings');

    /** Email Setting Routes */
    Route::post('email-settings', [SettingController::class, 'saveEmailSettings'])->name('email.settings');
    Route::post('email-test', [SettingController::class, 'TestMail'])->name('email.test');
    Route::post('test-send-mail', [SettingController::class, 'testSendMail'])->name('test.send.mail');
    Route::post('get-email-fields', [SettingController::class, 'getEmailSettingFields'])->name('get.email.fields');
    Route::post('email-notification-status', [EmailTemplateController::class, 'updateEmailNotificationStatus'])->name('update.email.statue');

    /** Email Templates Routes */
    Route::get('email_template_lang/{lang?}', [EmailTemplateController::class, 'emailTemplate'])->name('email_template');
    Route::get('email_template_lang/{id}/{lang?}', [EmailTemplateController::class, 'manageEmailLang'])->name('manage.email.language');
    Route::put('email_template_lang/{id}/', [EmailTemplateController::class, 'updateEmailSettings'])->name('updateEmail.settings');

    /** Shipping Routes */
    Route::resource('shipping', ShippingController::class);

    /** Shipping Zone Routes */
    Route::resource('shipping-zone', ShippingZoneController::class);
    Route::post('shipping-states-list', [ShippingZoneController::class, 'states_list'])->name('shipping-states.list');

    /** Shipping Method Routes */
    Route::resource('shipping-method', ShippingMethodController::class);
    Route::get('{id}/free-shipping-edit', [ShippingMethodController::class, 'freeShippingEdit'])->name('free-shipping.edit');
    Route::post('{id}/free-shipping', [ShippingMethodController::class, 'freeShippingUpdate'])->name('free-shipping.update');
    Route::get('{id}/local-shipping-edit', [ShippingMethodController::class, 'localShippingEdit'])->name('local-shipping.edit');
    Route::post('{id}/local-shipping', [ShippingMethodController::class, 'localShippingUpdate'])->name('local-shipping.update');

    /** Blog Routes */
    Route::resource('blog', BlogController::class);
    /** Blog  Category Routes */
    Route::resource('blog-category', BlogCategoryController::class);

    // webhook Setting
    Route::resource('webhook', WebhookController::class);

    /** Tax Routes */
    Route::resource('taxes', TaxController::class);
    Route::resource('taxes-method', TaxMethodController::class)->except(['create']);
    Route::get('taxes-method/create/{tax_option_id}', [TaxMethodController::class, 'create'])->name('taxes-method.create');
    Route::post('tax-option-settings', [TaxOptionController::class, 'taxSettings'])->name('tax-option.settings');
    Route::post('cities-list', [TaxMethodController::class, 'cities_list'])->name('cities.list');
    Route::post('states-list', [ShippingZoneController::class, 'states_list'])->name('states.lists');

    /** Flash Sale */
    Route::resource('flash-sale', FlashSaleController::class);
    Route::post('update-flashsale-status', [FlashsaleController::class, 'updateStatus'])->name('update-flashsale-status');
    Route::post('get-options', [FlashSaleController::class, 'get_options'])->name('get.options');
    /** Faqs Routes */
    Route::resource('faqs', FaqController::class);
    // Store Setting
    Route::resource('stores', StoreController::class);
    Route::get('stores/user/create', [StoreController::class, 'userCreate'])->name('store.user.create');
    Route::get('store/customdomain', [StoreController::class, 'customDomain'])->name('store.customdomain');
    Route::get('store/subdomain', [StoreController::class, 'subDomain'])->name('store.subdomain');
    Route::get('store/change-status', [StoreController::class, 'changeStatus'])->name('store.active.status');
    Route::get('store/list', [StoreController::class, 'list'])->name('store.list');

    Route::post('pwa-settings/{id}', [StoreController::class, 'pwaSetting'])->name('pwa.setting');
    Route::post('/cookie-setting', [SettingController::class, 'CookieSettings'])->name('cookie.setting');
    Route::any('/cookie-consent', [SettingController::class, 'CookieConsent'])->name('cookie-consent');

    Route::resource('stores', StoreController::class);
    Route::get('store-reset-password/{id}', [StoreController::class, 'storeResetPassword'])->name('stores.reset.password');
    Route::post('store-reset-password-update/{id}', [StoreController::class, 'storeResetPasswordUpdate'])->name('stores.reset.password.update');

    Route::get('/change-store/{id}', [StoreController::class, 'changeStore'])->name('change.store');

    Route::resource('testimonial', TestimonialController::class);
    Route::post('get-product', [TestimonialController::class, 'get_product'])->name('get.product');

    Route::resource('category', CategoryController::class);

    Route::resource('product', ProductController::class);
    Route::get('product-list', [ProductController::class, 'list'])->name('product.list');

    Route::post('get-slug', [ProductController::class, 'get_slug'])->name('get.slug');

    // Menus Routes
    Route::resource('menus', MenuController::class)->except(['update']);
    Route::post('add-category-menu/{menu_id}', [MenuItemsController::class, 'addCategoryMenu'])->name('add.category.menu');
    Route::post('add-page-menu/{menu_id}', [MenuItemsController::class, 'addPageMenu'])->name('add.page.menu');
    Route::post('add/customlink/{menu_id}', [MenuItemsController::class, 'addCustomLink'])->name('add.customlink.menu');
    Route::post('add-brand-menu/{menu_id}', [MenuItemsController::class, 'addBrandMenu'])->name('add.brand.menu');
    Route::post('add-product-menu/{menu_id}', [MenuItemsController::class, 'addProductMenu'])->name('add.product.menu');
    Route::post('add-blog-menu/{menu_id}', [MenuItemsController::class, 'addBlogMenu'])->name('add.blog.menu');
    Route::post('/menu/update-order', [MenuController::class, 'updateOrder'])->name('menu.updateOrder');
    Route::post('/menu/add-item', [MenuController::class, 'addItem'])->name('menu.addItem');
    Route::delete('/menu/delete-item/{id}', [MenuController::class, 'deleteItem'])->name('menu.deleteItem');
    Route::post('update/customlink/{id}', [MenuItemsController::class, 'updateCustomLink'])->name('update.customlink.menu');
    Route::delete('delete-custom-link/{id}', [MenuItemsController::class, 'deleteCustomLink'])->name('delete.custom.link.menu');

    // Pages Routes
    Route::resource('pages', PageController::class);
    Route::post('update-page-status', [PageController::class, 'updateStatus'])->name('update-page-status');

    Route::resource('deliveryboy', DeliveryBoyController::class);
    Route::get('deliveryboy/reset-password/{id}', [DeliveryBoyController::class, 'resetPassword'])->name('deliveryboy.reset');
    Route::post('deliveryboy/reset/{id}', [DeliveryBoyController::class, 'updatePassword'])->name('deliveryboy.reset.password');
    Route::get('deliveryboy-list', [DeliveryBoyController::class, 'list'])->name('deliveryboy.list');

    Route::post('order-assign', [OrderController::class, 'order_assign'])->name('order.assign');

    Route::resource('product-attributes', ProductAttributeController::class);

    Route::post('products/attrinbute_option', [ProductController::class, 'attribute_option'])->name('products.attribute_option');

    Route::post('products/attribute_combination', [ProductController::class, 'attribute_combination'])->name('products.attribute_combination');

    Route::get('add-attribute-option/{id?}', [ProductAttributeOptionController::class, 'create'])->name('product-attribute-option.create');

    Route::get('attribute-option/{id?}', [ProductAttributeOptionController::class, 'show'])->name('product-attribute-option.show');

    Route::post('store-attribute-option/{id?}', [ProductAttributeOptionController::class, 'store'])->name('product-attribute-option.store');

    Route::post('product-attribute/option', [ProductAttributeOptionController::class, 'option'])->name('attribute-option');

    Route::get('edit-attribute-option/{id?}', [ProductAttributeOptionController::class, 'edit'])
      ->name('product-attribute-option.edit');

    Route::delete('delete-attribute-option/{id?}', [ProductAttributeOptionController::class, 'destroy'])->name('product-attribute-option.destroy');

    Route::post('update-attribute-option/{id?}', [ProductAttributeOptionController::class, 'update'])->name('product-attribute-option.update');

    Route::post('products/attribute_combination', [ProductController::class, 'attribute_combination'])->name('products.attribute_combination');

    Route::post('products/sku_combination', [ProductController::class, 'sku_combination'])->name('products.sku_combination');

    Route::delete('products/{id}/delete', [ProductController::class, 'file_delete'])->name('products.file.detele');

    Route::put('products/attribute_combination/edit', [ProductController::class, 'attribute_combination_edit'])->name('products.attribute_combination_edit');
    Route::put('products/sku_combination/edit', [ProductController::class, 'sku_combination_edit'])->name('products.sku_combination_edit');
    Route::any('products/attribute_combination_data/edit', [ProductController::class, 'attribute_combination_data'])->name('products.attribute_combination_data');

    Route::delete('product-attribute-delete/{id}', [ProductController::class, 'product_attribute_delete'])->name('product.attribute.delete');

    Route::resource('app-setting', AppSettingController::class);
    Route::get('mobile-screen-content', [AppSettingController::class, 'MobileScreenContent'])->name('mobilescreen.content');
    Route::post('theme-settings', [AppSettingController::class, 'ThemeSettings'])->name('theme.settings');
    Route::DELETE('ownerstore-remove/{id}', [StoreController::class, 'ownerstoredestroy'])->name('ownerstore.remove');

    Route::post('/seo-setting', [AppSettingController::class, 'seoSettings'])->name('seo.settings');
    Route::post('/shipping-label-setting', [AppSettingController::class, 'shippingLabelSettings'])->name('shippinglabel.settings');

    Route::post('site-setting', [AppSettingController::class, 'SiteSetting'])->name('site.setting');
    // Coupon Routes
    Route::get('coupon/export', [CouponController::class, 'fileExport'])->name('coupon.export');
    Route::resource('coupon', CouponController::class);

    //Woocommerce
    Route::post('woocommerce-settings', [SettingController::class, 'WoocommerceSettings'])->name('woocommerce.settings');

    Route::resource('woocom_coupon', WoocomCouponController::class);
    Route::resource('woocom_product', WoocomProductController::class);
    Route::resource('woocom_category', WoocomCategoryController::class);
    Route::resource('woocom_customer', WoocomCustomerController::class);

    // shopify
    Route::post('shopify-settings', [SettingController::class, 'shopifySettings'])->name('shopify.settings');

    Route::resource('shopify_product', ShopifyProductController::class);
    Route::resource('shopify_category', ShopifyCategoryController::class);
    Route::resource('shopify_customer', ShopifyCustomerController::class);
    Route::resource('shopify_coupon', ShopifyCouponController::class);

    Route::post('whatsapp-settings', [SettingController::class, 'WhatsappSettings'])->name('whatsapp.settings');
    // Route::post('custom-msg', [StoreController::class, 'customMassage'])->name('customMassage');
    Route::get('store/{id}/login-with-admin', [StoreController::class, 'LoginWithAdmin'])->name('login.with.admin');
    Route::get('login-with-admin/exit', [StoreController::class, 'ExitAdmin'])->name('exit.admin');
    Route::get('{id}/stores-link', [StoreController::class, 'StoreLinks'])->name('stores.link');
    Route::get('stores/{id}/plan', [StoreController::class, 'upgradePlan'])->name('plan.upgrade');
    Route::get('store-plan-active/{id}/plan/{pid}', [StoreController::class, 'activePlan'])->name('plan.active');

    /** System Setting Routes */
    Route::post('system-settings', [SettingController::class, 'SystemSettings'])->name('system.settings');

    /** Currency Setting Routes */
    Route::post('currency-settings', [SettingController::class, 'currencySettings'])->name('currency.settings');
    Route::post('update-note-value', [SettingController::class, 'updateNoteValue'])->name('update.note.value');

    // Profile route
    Route::get('profile', [UserController::class, 'profile'])->name('profile');
    Route::post('edit-profile', [UserController::class, 'editprofile'])->name('update.account');
    Route::put('user-password-update', [UserController::class, 'password_update'])->name('user.password.update');
    Route::get('user-list', [UserController::class, 'list'])->name('user.list');

    // SEC-F5. Theme install/delete/enable unpack or activate code inside the
    // running application, so they are platform-operator actions. The Gate is
    // defined in AuthServiceProvider::boot(). Each controller method ALSO calls
    // $this->authorize() itself, so the control does not rest on this grouping
    // alone -- relying on grouping alone is what failed in SEC-F2.
    Route::middleware('can:manage-platform-addons')->group(function () {
        Route::resource('addon', AddonController::class);
        Route::get('addon-apps', [AddonController::class, 'AddonApps'])->name('addon.apps');
        Route::post('theme-addon', [AddonController::class, 'ThemeInstall'])->name('addon.theme');
        Route::post('theme-enable', [AddonController::class, 'ThemeEnable'])->name('theme.enable');
    });

    // Report
    Route::resource('reports', ReportController::class);
    Route::get('reports-chart', [ReportController::class, 'reports_chart'])->name('reports.chart');
    Route::get('report-export', [ReportController::class, 'export'])->name('reports.export');
    Route::get('order-reports', [ReportController::class, 'OrderReport'])->name('reports.order_report');
    Route::get('order-reports-chart', [ReportController::class, 'order_reports_chart'])->name('reports.order.chart');
    Route::get('order-report-export', [ReportController::class, 'order_report_export'])->name('order.reports.export');
    Route::get('order-barchart-report-export', [ReportController::class, 'order_bar_report_export'])->name('order.barchart.reports.export');
    Route::get('order-reports-data', [ReportController::class, 'BarChartOrderReport'])->name('orders.barchart_order_report');
    Route::get('order-reports-chart-data', [ReportController::class, 'order_reports_chart_data'])->name('reports.order.chart.data');
    Route::get('stock-reports', [ReportController::class, 'StockReport'])->name('reports.stock_report');
    Route::get('/stock-report', [ReportController::class, 'StockReport'])->name('stock.report');

    Route::get('product-order-sale-reports', [ReportController::class, 'OrderSaleProductReport'])->name('reports.order_product_report');
    Route::get('product-order-reports', [ReportController::class, 'order_product_reports'])->name('reports.product.order.chart');
    Route::get('product-order-export', [ReportController::class, 'product_order_export'])->name('product.order.export');
    Route::get('category-order-sale-reports', [ReportController::class, 'OrderSaleCategoryReport'])->name('reports.order_category_report');
    Route::get('category-order-reports', [ReportController::class, 'order_category_reports'])->name('reports.category.order.chart');
    Route::get('category-order-export', [ReportController::class, 'category_order_export'])->name('category.order.export');
    Route::get('order-downloadable-reports', [ReportController::class, 'OrderDownlodableReport'])->name('reports.order_downloadable_report');

    Route::get('Brand-order-sale-reports', [ReportController::class, 'OrderSaleBrandReport'])->name('reports.order_brand_report');
    Route::get('brand-order-reports', [ReportController::class, 'order_brand_reports'])->name('reports.brand.order.chart');
    Route::get('brand-order-export', [ReportController::class, 'brand_order_export'])->name('brand.order.export');

    Route::get('top-all-reports', [ReportController::class, 'top_product'])->name('reports.top_product');
    Route::get('Status-reports', [ReportController::class, 'showOrderStatusReport'])->name('reports.orderStatusReport');
    Route::get('order-country-reports', [ReportController::class, 'showCountryOrderReport'])->name('reports.orderCountryReport');

    Route::resource('wishlist', WishlistController::class);
    Route::post('abandon-wish-emailsend', [WishlistController::class, 'abandon_wish_emailsend'])->name('wish.emailsend');
    Route::post('abandon-wishlist-message-send', [WishlistController::class, 'abandonWishlistMsgSend'])->name('wishlist.message.send');

    Route::post('whatsapp-notification', [SettingController::class, 'whatsapp_notification_setting'])->name('whatsapp-notification');
    Route::get('update-whatsapp-notification', [SettingController::class, 'whatsapp_notification'])->name('update.whatsapp.notification');
    //send test whatsapp message
    Route::post('whatsapp-massage-test', [SettingController::class, 'Testwhatsappmassage'])->name('whatsappmassage.test');

    Route::post('whatsapp-send-massage', [SettingController::class, 'testSendwhatsappmassage'])->name('whatsapp.send.massage');

    // abandon carts handled
    Route::get('abandon-carts-handled', [CartController::class, 'abandon_carts_handled'])->name('abandon.carts.handled');
    Route::get('abandon-carts-show/{cartId}', [CartController::class, 'abandon_carts_show'])->name('carts.show');
    Route::delete('abandon-carts-destroy/{cartId}', [CartController::class, 'abandon_carts_destroy'])->name('carts.destroy');
    Route::post('abandon-carts-emailsend', [CartController::class, 'abandon_carts_emailsend'])->name('carts.emailsend');

    Route::post('abandon-carts-messagesend', [CartController::class, 'abandon_carts_messsend'])->name('carts.messagesend');

    Route::post('abandon-wish-emailsend', [WishlistController::class, 'abandon_wish_emailsend'])->name('wish.emailsend');

    //support-ticket
    Route::resource('support_ticket', SupportTicketController::class);
    Route::get('ticket-view/{id}', [SupportTicketController::class, 'show'])->name('support_ticket.view');
    Route::post('ticket/{id}/conversion', [SupportTicketController::class, 'conversion_store'])->name('conversion.store');
    Route::post('ticket-status-change/{id}', [SupportTicketController::class, 'ticket_status_change'])->name('support_ticket.status.change');

    //Question-answer
    Route::resource('product-question', ProductQuestionController::class);

    // AI tool Route
    Route::get('generate/{template_name}', [AITemplateController::class, 'create'])->name('generate');
    Route::post('generate/keywords/{id}', [AITemplateController::class, 'getKeywords'])->name('generate.keywords');
    Route::post('generate/response', [AITemplateController::class, 'aiGenerate'])->name('generate.response');

    // Customer Route
    Route::resource('customer', CustomerController::class);
    Route::get('/customer-filter', [CustomerController::class, 'CustomFilter'])->name('customer.filter');
    Route::get('/customer-filter-data', [CustomerController::class, 'CustomFilterData'])->name('customer.filter.data');
    Route::get('/customer-status', [CustomerController::class, 'customerStatus'])->name('update.customer.status');
    Route::get('/customer-timeline/{id}', [CustomerController::class, 'customerTimeline'])->name('customer.timeline');
    
    
    
    
    Route::get('customer-grid', [CustomerController::class, 'customer_grid_view'])->name('customer.grid');

    // Order Route
    Route::get('order/export', [OrderController::class, 'fileExport'])->name('order.export');
    Route::resource('order', OrderController::class);
    Route::get('order/all', [OrderController::class, 'allOrders']);
    // GATE C, limb 1 -- NEW-order-view-auth-shadowed-by-duplicate-registration.
    //
    // THIS registration was DEAD. `order-view/{id}` was declared twice: here,
    // inside the ['auth','xss','setlocate','verified'] group, and again in the
    // public block further down with ['themelanguage']. Laravel's
    // RouteCollection::addToCollections keys allRoutes by method+domain+uri, so
    // the SECOND declaration REPLACED this one -- the surviving route was the
    // UNAUTHENTICATED one. Measured, not inferred: a route-table dump on
    // bd6900bc printed exactly one `GET|HEAD order-view/{id}` and its middleware
    // was [web,themelanguage]. The earlier comment claiming the duplicate had
    // been deleted was wrong; only one of the two copies had been.
    //
    // UnauthenticatedRouteBoundaryTest::test_order_view_is_registered_exactly_once
    // counted 1 and passed, because the replacement had already happened by the
    // time it looked. It was asserting the symptom away.
    //
    // The public copy is deleted and this one survives. Nothing customer-facing
    // is lost: OrderController::order_view:287 requires isAbleTo('Show Order'),
    // a merchant permission no shopper holds, and not one of the three shipped
    // themes references this route (`grep -rn "order.view\|order-view" themes/`
    // returns nothing). Every caller is an admin screen or DataTable --
    // app/DataTables/OrderDataTable.php:31, resources/views/order/action.blade.php:20,
    // resources/views/order/grid.blade.php:89,112, resources/views/dashboard.blade.php:364,
    // resources/views/customer/view.blade.php:44,185, plus four workdo admin packages.
    //
    // `themelanguage` is added here so the effective middleware is what was
    // actually running before PLUS auth, rather than silently dropping the
    // locale middleware the surviving copy carried.
    Route::get('order-view/{id}', [OrderController::class, 'order_view'])
        ->name('order.view')
        ->middleware('themelanguage');

    Route::get('order-grid', [OrderController::class, 'order_grid_view'])->name('order.grid');
    // Pos Route
    Route::resource('pos', PosController::class);
    Route::get('product-categories', [CategoryController::class, 'getProductCategories'])->name('product.categories');
    Route::get('search-products', [ProductController::class, 'searchProducts'])->name('search.products');
    Route::get('search-products-sku', [ProductController::class, 'searchProductsSku'])->name('search.products.sku');
    Route::post('/cartdiscount', [PosController::class, 'cartDiscount'])->name('cartdiscount');
    Route::get('addToCart/{id}/{session}/{variation_id?}', [ProductController::class, 'addToCart'])->name('pos.add.to.cart');
    Route::patch('update-cart', [ProductController::class, 'updateCart']);
    Route::delete('remove-from-cart', [ProductController::class, 'removeFromCart'])->name('remove-from-cart');
    Route::get('printview/pos', [PosController::class, 'printView'])->name('pos.printview');
    Route::post('pos/data/store', [PosController::class, 'store'])->name('pos.data.store');
    Route::post('empty-cart', [ProductController::class, 'emptyCart'])->name('empty-cart');
    // POS variant Route
    Route::get('pos/product-variant/{id}/{session}', [ProductController::class, 'productVariant'])->name('pos.product.variant');
    Route::get('pos/add-cart-variant/{id}/{session}/{variation_id?}', [ProductController::class, 'addToCartVariant'])->name('addToCartVariant');
    Route::get('get-products-variant-quantity', [ProductController::class, 'getProductsVariantQuantity'])->name('get.products.variant.quantity');

    Route::resource('contacts', ContactController::class)->except(['store']);

    // Tag Route
    Route::resource('tag', TagController::class);

    Route::get('change-language/{lang}', [LanguageController::class, 'changeLanquage'])->name('change.language');
    Route::get('manage-language/{lang?}/{module?}', [LanguageController::class, 'manageLanguage'])->name('manage.language');
    Route::post('store-language-data/{lang?}/{module?}', [LanguageController::class, 'storeLanguageData'])->name('store.language.data');
    Route::get('create-language', [LanguageController::class, 'createLanguage'])->name('create.language');
    Route::post('store-language', [LanguageController::class, 'storeLanguage'])->name('store.language');
    Route::delete('/lang/{lang}', [LanguageController::class, 'destroyLang'])->name('lang.destroy');

    Route::post('disable-language', [LanguageController::class, 'disableLang'])->name('disablelanguage');

    Route::post('product-image-delete', [AppSettingController::class, 'image_delete'])->name('product.image.delete');
    Route::post('firebase-settings', [AppSettingController::class, 'FirebaseSettings'])->name('firebase.settings');


    // Module Install
    Route::get('modules/list', [ModuleController::class, 'index'])->name('module.index');
    Route::post('modules-enable', [ModuleController::class, 'enable'])->name('module.enable');
    Route::post('remove-modules/{module}', [ModuleController::class, 'remove'])->name('module.remove');
    Route::get('modules/add', [ModuleController::class, 'add'])->name('module.add');
    // SEC-F5. install() extracts an uploaded archive into packages/workdo.
    Route::post('install-modules', [ModuleController::class, 'install'])
        ->middleware('can:manage-platform-addons')
        ->name('module.install');

    // Product Brand
    Route::resource('product-brand', ProductBrandController::class);
    Route::get('product-brand-status', [ProductBrandController::class, 'changeStatus'])->name('product-brand.status');
    Route::get('product-brand-popular', [ProductBrandController::class, 'changePopular'])->name('product-brand.popular');

    // Product Label
    Route::resource('product-label', ProductLabelController::class);
    Route::get('product-label-status', [ProductLabelController::class, 'changeStatus'])->name('product-label.status');

    Route::get('newsletter/export', [NewsletterController::class, 'fileExport'])->name('newsletter.export');

    Route::resource('currency', CurrencyController::class)->except(['edit']);
    Route::get('/currency/{id}/edit', [CurrencyController::class, 'edit'])->name('currency.edit');

    /** Themes Setting Route */
    Route::get('theme-customize', [ThemeCustomizeController::class, 'index'])->name('theme.index');
    Route::get('theme-customize/{theme}/pages', [ThemeCustomizeController::class, 'themePages'])->name('theme.pages');
    Route::get('theme-customize/{theme}/page/{slug?}/{sub_slug?}', [ThemeCustomizeController::class, 'themeCustomize'])->name('theme.customize');
    Route::post('theme-customize/{theme}/update', [ThemeCustomizeController::class, 'themeCustomizeUpdate'])->name('theme.customize.update');
    Route::post('theme-customize/file-get', [ThemeCustomizeController::class, 'imageFileGet'])->name('theme.file.get');
    Route::get('theme-customize/{theme}/pages/order/{slug?}/{sub_slug?}', [ThemeCustomizeController::class, 'changeOrder'])->name('theme.customize.order');
    Route::post('theme-customize/{theme}/order/update', [ThemeCustomizeController::class, 'updateOrder'])->name('theme.customize.update.order');
    Route::post('theme-preview/make-active', [ThemeCustomizeController::class, 'makeActiveTheme'])->name('theme-preview.make-active');

  // ===================================================================
  // SEC-F2 / NEW-public-mutating-routes / NEW-order-payment-status.
  //
  // THE BRACE MOVE. The `auth` group opened above used to close immediately
  // after the line above this comment, which left the whole administrative
  // order/refund block below inside the ['web'] group but OUTSIDE `auth` --
  // session middleware, no authentication. `themelanguage` adds nothing:
  // app/Http/Middleware/ThemeLanguage.php:22-38 only sets the locale.
  //
  // The worst limb was `order-payment-status`, an ANONYMOUS arbitrary write of
  // payment_status onto any order in any store
  // (OrderController::order_payment_status -- Order::find($request->order_id),
  // assign, save; no auth, no permission, no tenant scope, no validation).
  //
  // These declarations are unchanged; only the group boundary moved. The
  // genuinely public routes, and the customer-reachable order READS that are
  // pending GATE C, stay outside the group below.
  // ===================================================================
  // `store` is EXCLUDED and re-registered outside this group below. It is the
  // storefront footer subscribe form, which is anonymous by design in all three
  // shipped themes. Sweeping it inside `auth` stopped newsletter signup working
  // on every store.
  Route::resource('newsletter', NewsletterController::class)->except(['store'])->middleware('themelanguage');
  Route::get('shippinglabel/pdf/{id}', [OrderController::class, 'shippinglabel'])->name('shippinglabel.pdf');
  Route::post('order-status-change/{id}', [OrderController::class, 'order_status_change'])->name('order.status.change')->middleware('themelanguage');
  Route::post('order-payment-status', [OrderController::class, 'order_payment_status'])->name('order.payment.status')->middleware('themelanguage');
  Route::resource('order-note', OrderNoteController::class);

  Route::post('update-order-status/{id}', [OrderController::class, 'updateStatus'])->name('order.order_status_update')->middleware('themelanguage');

  // ===================================================================
  // GATE C, limbs 2-5 -- CLOSED. These four were in
  // UnauthenticatedRouteBoundaryTest's PUBLIC_ROUTE_ALLOWLIST marked "PENDING
  // GATE C", awaiting the owner's answer on whether guest order tracking is a
  // real customer flow. The evidence answers it: it is NOT this flow.
  //
  //   1. NOT ONE of the three shipped storefront themes references any of them.
  //      `grep -rn "order.receipt\|order.order_view\|order.return\|order-receipt\|
  //      order-return\|order-view" themes/` returns NOTHING across
  //      themes/stylique, themes/greentic and themes/techzonix.
  //   2. Every caller is an ADMIN view:
  //        order.receipt      resources/views/order/view.blade.php:31 (the admin
  //                           order screen that order_view itself renders)
  //        order.order_view   resources/views/order/action.blade.php:13,
  //                           resources/views/order/grid.blade.php:105,
  //                           packages/workdo/PreOrder/.../preorder/action.blade.php:19
  //        order.return       resources/views/order/view.blade.php:635 (admin AJAX)
  //        order.return.request
  //                           resources/views/order/index.blade.php:56,
  //                           resources/views/order/grid.blade.php:153,
  //                           packages/workdo/AuctionProduct/.../auctionorder/index.blade.php:37,
  //                           packages/workdo/PreOrder/.../preorder/index.blade.php:37
  //   3. The customer-facing order flow is a SEPARATE set of routes in
  //      routes/customer.php -- order.track:63, track.order:72,
  //      customer.order:208, order.return.list:193, order.refund:210,
  //      order.refund.request:211 -- served by AccountProfileController and
  //      rendering `front_end.sections.order_view`
  //      (AccountProfileController.php:831), not `order.*`. So guest/customer
  //      order tracking is not served by these routes at all and nothing
  //      storefront-facing breaks.
  //
  // WHAT WAS ACTUALLY OPEN, and it is worse than two unguarded reads:
  //
  //   order.return.request  POST, ANONYMOUS, arbitrary write.
  //                         OrderController::order_return_request:451 does
  //                         `Order::find($request->id)` -- no auth, no
  //                         permission, no tenant predicate -- then on
  //                         status==2 walks product_json and does
  //                         `$product->product_stock += ...; $product->save()`
  //                         on ANY store's products (:460-471), then writes
  //                         return_price and return_status onto that order
  //                         (:474-476). An anonymous caller could inflate any
  //                         merchant's stock and mark any order returned.
  //   order.return          POST, ANONYMOUS, arbitrary write. Reaches
  //                         Order::product_return (app/Models/Order.php:637),
  //                         which does `Order::find($order_id)` and then
  //                         increments Product::product_stock /
  //                         ProductVariant::stock and re-saves product_json on
  //                         ANY store's order. It also dereferences
  //                         `$Order->delivered_status` with no null check, so an
  //                         unknown id is a 500.
  //   order.order_view      GET, ANONYMOUS, full read. OrderController::show:276
  //                         has NO auth and NO permission check whatsoever --
  //                         it decrypts the id and renders the ADMIN blade
  //                         order.order_show with the complete order detail.
  //   order.receipt         GET, ANONYMOUS, full read. order_receipt:416 is the
  //                         same shape: decrypt, Order::order_detail, render.
  //
  // This is the same class as `order-payment-status` above, which was already
  // closed for exactly this reason. Crypt::decrypt() of the id is NOT an
  // authorization control: it is an encoding, it is keyed by the one shared
  // APP_KEY, and an id that leaks once (a link, a referrer, a browser history,
  // a shared screenshot) stays valid forever.
  //
  // Each keeps `themelanguage`, so the only change to their effective
  // middleware is the addition of auth/xss/setlocate/verified -- the same set
  // their siblings order.status.change and order.payment.status directly above
  // already carry.
  //
  // IF the owner later decides guest order tracking IS wanted, the READS
  // (order.receipt, order.order_view) are the two candidates, and the right
  // home is routes/customer.php behind Laravel's `signed` middleware, so the
  // link itself carries a verifiable grant. The two WRITES must not go back
  // outside auth under any decision: they are merchant return-approval
  // actions, as their own response strings say -- "Return request approve
  // successfully" / "Return request reject successfully"
  // (OrderController.php:484-487).
  // ===================================================================
  Route::post('order-return', [OrderController::class, 'order_return'])->name('order.return')->middleware('themelanguage');
  Route::get('orders/order_view/{id}', [OrderController::class, 'show'])->name('order.order_view')->middleware('themelanguage');
  Route::get('order-receipt/{id}', [OrderController::class, 'order_receipt'])->name('order.receipt')->middleware('themelanguage');
  Route::post('order-return-request', [OrderController::class, 'order_return_request'])->name('order.return.request')->middleware('themelanguage');

  Route::post('update-refund-status', [OrderRefundController::class, 'updateRefundStatus'])->name('update-refund-status')->middleware('themelanguage');
  Route::post('cancel-refund-status', [OrderRefundController::class, 'CancelRefundStatus'])->name('cancel.refund.status')->middleware('themelanguage');
  Route::post('refund-stock', [OrderRefundController::class, 'RefundStock'])->name('refund.stock')->middleware('themelanguage');
  Route::post('/update-final-price/{id}', [OrderRefundController::class, 'updateFinalPrice'])->name('updateFinalPrice')->middleware('themelanguage');
  Route::post('/refund-amount/{id}', [OrderRefundController::class, 'RefundAmonut'])->name('refund.amount')->middleware('themelanguage');

  Route::resource('refund-request', OrderRefundController::class)->middleware('themelanguage');
  Route::post('refund-settings', [OrderRefundController::class, 'updateStatus'])->name('refund.settings')->middleware('themelanguage');

  Route::post('product-page-setting', [AppSettingController::class, 'product_page_setting'])->name('product.page.setting');
  });
  // ^^^ the `auth` group now closes HERE, after the administrative block.

  // --- Genuinely public: these four are the PUBLIC_ROUTE_ALLOWLIST asserted by
  // --- tests/Feature/Security/UnauthenticatedRouteBoundaryTest.php.
  Route::post('contacts/{slug?}', [ContactController::class, 'store'])->name('contacts.store');
  // The storefront footer subscribe form. All three shipped themes post here
  // anonymously -- themes/stylique/views/front_end/partison/footer.blade.php:52,
  // themes/greentic/views/front_end/common/subscribe.blade.php:10 and
  // themes/techzonix/views/front_end/common/subscribe.blade.php:2, each
  // `action="{{ route('newsletter.store', $slug) }}"`.
  //
  // Declared with NO uri parameter on purpose: NewsletterController::store()
  // takes only a Request and recovers the store from the QUERY STRING
  // (NewsletterController.php:44-48 walks $request->query() and uses the first
  // key as the slug). `route('newsletter.store', $slug)` therefore renders
  // /newsletter?<slug>, which is the URL shape the themes have always posted to
  // and the shape the controller already parses. Adding a {slug?} segment here
  // would change that URL and break the controller's own slug recovery.
  Route::post('newsletter', [NewsletterController::class, 'store'])->name('newsletter.store')->middleware('themelanguage');
  Route::get('change-language-store/{lang}', [LanguageController::class, 'changeLanquageStore'])->name('change.languagestore');

  // --- GATE C: CLOSED. The five order routes that used to sit here -- the two
  // --- reads (order.receipt, order.order_view), the two writes (order.return,
  // --- order.return.request) and the duplicate order-view/{id} -- have moved
  // --- inside the administrative `auth` group above. The evidence for each,
  // --- including the route-table measurement showing that the duplicate
  // --- order-view/{id} declared HERE was shadowing the authenticated one, is
  // --- recorded at both ends. Nothing replaces them in this public block: the
  // --- customer-facing order flow lives in routes/customer.php.

  Route::get('/pay/{pay}', [PayUPaymentController::class, 'pay'])->name('payu.pay');


  Route::post('/format-price', [ProductController::class, 'formatPrice'])->name('format.price');
  require __DIR__ . '/customer.php';
});



// Was registered with no middleware at all: an anonymous caller could read any
// store's Zid orders using that merchant's stored Zid credentials.
Route::get('/zid/{storeId}/orders', function ($storeId) {
    return Zid::store($storeId)->get('/managers/store/orders')->json();
})->middleware(['auth']);

Route::get('/zid/{storeId}/webhooks', function ($storeId) {
    return \App\Services\Zid::store($storeId)->get('/managers/webhooks')->json();
})->middleware('auth');


Route::post('/zid/events/{v?}', function (\Illuminate\Http\Request $r) {
    // NEW-ZID-HOOK-FAILOPEN-01. This check used to read:
    //     $allowed = $r->header('X-Hook-Secret') === config('services.zid.hook_pass')
    //         || ($r->getUser() === config('services.zid.hook_user')
    //             && $r->getPassword() === config('services.zid.hook_pass'));
    //
    // Request::header() returns NULL for an absent header and Request::getUser() /
    // getPassword() return NULL for a request with no basic-auth credentials, so if
    // either configured value were ever absent -- moved to .env and the key not set,
    // a typo in the config key, config:cache run before the value was added -- the
    // comparison became `null === null`, $allowed became TRUE and this endpoint
    // accepted ANY anonymous request. It writes `zid_events` and, on an
    // `application.uninstall` payload, DELETES the caller-named store's `zid_stores`
    // row (below), so failing open here is a destructive anonymous write.
    //
    // It is latent rather than live at HEAD: config/services.php does define both
    // values today (not reproduced here). It is still wrong, because the whole point
    // of moving those literals into the environment -- which SEC-CFG-02 asks for --
    // would have silently opened the endpoint.
    //
    // Fails closed now, on both arms, and compares in constant time. Covered in both
    // directions by tests/Feature/Security/ZidWebhookSecretTest: a correct secret is
    // still accepted (header arm and basic-auth arm), and an unset secret is refused
    // instead of waved through.
    //
    // The 401 below is NOT changed to 403. This is a server-to-server webhook
    // rejection on a non-/api path; the 403-not-401 rule exists because repo B's
    // lib/network/network.dart turns any 401 from the API it calls into a forced
    // logout, and repo B is not in this worktree so no claim about its call sites is
    // made here. Flagged for the coordinator rather than changed blind.
    $configuredSecret = (string) config('services.zid.hook_pass');
    $configuredUser   = (string) config('services.zid.hook_user');

    $allowed = false;

    if ($configuredSecret !== '') {
        $headerSecret = (string) $r->header('X-Hook-Secret');

        if ($headerSecret !== '' && hash_equals($configuredSecret, $headerSecret)) {
            $allowed = true;
        }

        if (! $allowed && $configuredUser !== '') {
            $basicUser = (string) $r->getUser();
            $basicPass = (string) $r->getPassword();

            if (
                $basicUser !== '' && $basicPass !== ''
                && hash_equals($configuredUser, $basicUser)
                && hash_equals($configuredSecret, $basicPass)
            ) {
                $allowed = true;
            }
        }
    }

    if (! $allowed) {
        \Illuminate\Support\Facades\Log::warning('Zid webhook rejected 401', [
            'path'    => $r->path(),
            'headers' => array_keys($r->headers->all()),
            'body'    => \Illuminate\Support\Str::limit(json_encode($r->all(), JSON_UNESCAPED_UNICODE), 500),
        ]);
        abort(401);
    }

    $event   = $r->header('X-Zid-Event') ?? $r->input('event');
    $raw     = json_encode($r->all(), JSON_UNESCAPED_UNICODE);
    $storeId = $r->input('store_id') ?? $r->input('store.id');

    DB::table('zid_events')->insert([
        'store_id'   => $storeId,
        'event'      => $event,
        'payload'    => $raw,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if ($storeId && str_contains($raw . ' ' . $event, 'application.uninstall')) {
        DB::table('zid_stores')->where('store_id', $storeId)->delete();
    }

    return response()->json(['ok' => true]);
})->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);




////////////new



// NEW-DS-ROUTE-SHADOW-01 -- DELETED, and the deletion is the fix.
//
// This file used to declare an inline closure on
//     Route::match(['GET', 'POST'], '/api/delivery-syria/orders/update-status', ...)
// and routes/api.php:35-38 declares the SAME METHOD AND URI:
//     Route::post('/delivery-syria/orders/update-status',
//         [DeliverySyriaOrderController::class, 'updateStatus'])
// (the api group is registered under the `api` prefix, so both normalise to
// `api/delivery-syria/orders/update-status`).
//
// Illuminate\Foundation\Configuration\ApplicationBuilder::buildRoutingCallback
// registers the API file FIRST (vendor/.../ApplicationBuilder.php:210-214) and the
// web file SECOND (:244-248), and RouteCollection::addToCollections keys
// $routes[$method] by domain+URI, so THIS CLOSURE WON THE POST SLOT and the
// controller was unreachable.
//
// MEASURED, by dispatching the real request rather than reading the table:
//   POST /api/delivery-syria/orders/update-status with an empty body
//     -> 422 {"success":false,"message":"product_order_id and status are required."}
//   POST the same URI WITH the correct `X-Platform: deliverysyria` header
//     -> 422, the same closure
// Api\DeliverySyriaOrderController answers an absent platform header with
// 403 {"message":"Invalid platform."} (:27-32) and an unmatched bearer token with
// 403 {"message":"Invalid credentials."} (:161-179, hash_equals against the store
// owner's users.deliverysyria_secret). Neither reply was reachable. The per-store
// webhook credential control added for NEW-delivery-syria-status was dead code on
// this endpoint.
//
// AND THE CLOSURE WAS ITSELF AN ANONYMOUS STATE CHANGE. It accepted GET as well as
// POST, took product_order_id off the query string, stripped the 'ZID-' prefix,
// looked the row up in `zid_orders`, decrypted that merchant's stored Zid
// authorization and manager token with Crypt::decryptString and POSTed a
// caller-chosen order_status to api.zid.sa/.../change-order-status. No
// authentication, no platform header, no token, no tenant predicate, no signature:
//   GET /api/delivery-syria/orders/update-status?product_order_id=ZID-1&status=1
// reaches the `zid_orders` lookup with no credential of any kind (measured: on this
// schema it reaches SQLSTATE[HY000] "no such table: zid_orders", i.e. it got past
// every control to the database).
//
// WHAT IS LOST, stated plainly for the owner rather than implied away: the closure
// also PUSHED the new status back to Zid, and the controller does not. That push
// cannot be kept at this URI, because occupying this URI is exactly what disabled
// the authenticated webhook -- the two cannot both own
// `POST api/delivery-syria/orders/update-status`, and moving the push to a different
// URI would break the integration just as surely, since the URI is what
// DeliverySyria is configured to call. It is also unverifiable here: `zid_orders`
// and `zid_stores` are declared by no migration (the GATE F family), so no test in
// this repository can exercise a Zid push at all. If the owner wants it, the place
// for it is inside Api\DeliverySyriaOrderController::updateStatus AFTER the
// hash_equals secret check, behind a Schema::hasTable('zid_stores') guard, and it
// needs the schema first. Recorded as a cross-agent dependency, NOT re-implemented
// here.
//
// COMPATIBILITY: a caller that was using GET now gets 405, and a POST caller now
// has to send `X-Platform: deliverysyria` and a bearer token equal to the store
// owner's users.deliverysyria_secret. That is the documented contract of
// Api\DeliverySyriaOrderController and of the sibling endpoint
// POST api/delivery-syria/status, which has required both since
// NEW-delivery-syria-status; this endpoint is the one that was still exempt.
//
// Pinned by tests/Feature/Security/RouteShadowingTest.







// NEW-ZID-TOKEN-DUMP-01 -- DELETED, and the deletion is the fix.
//
// This file used to declare, right here and with NO authentication at all:
//     Route::get('/zid/show-token', function (Request $r) {
//         abort_unless($r->query('key') === '<hardcoded literal>', 403);
//         $s = DB::table('zid_stores')->first();
//         return response("Authorization:\nBearer " . Crypt::decryptString($s->authorization)
//             . "\n\nX-Manager-Token:\n" . Crypt::decryptString($s->access_token));
//     });
//
// Measured posture before deletion (dumped from the booted router):
//     GET|HEAD  zid/show-token  name=  action=Closure  mw=[web]
// -- no `auth`, no gate, no signature. The only control was equality against an
// eight-character literal committed in this file, and the response body was the
// DECRYPTED Zid Authorization bearer and X-Manager-Token of whichever row
// `zid_stores` returned first. Reading the repository was therefore enough to
// take over a merchant's Zid account.
//
// This is the SECOND instance of the defect class that
// tests/Feature/Security/UnauthenticatedRouteBoundaryTest::test_zid_statuses_test_route_no_longer_exists
// was written for. That test matches the literal substring 'zid-statuses-test'
// in the URI, so it passed while this route -- same closure shape, same
// Crypt::decryptString disclosure, different URI -- stayed live. The URI-specific
// assertion is kept and a property-based one is added next to it:
// tests/Feature/Security/RouteShadowingTest::test_no_unauthenticated_closure_route_decrypts_credentials
// now scans the route table for closure routes outside `auth` whose source
// contains Crypt::decryptString, so instance three cannot be added silently.
//
// No replacement is declared. Nothing in either repository calls this route
// (`grep -rn "zid/show-token"` outside this comment returns nothing) and a
// credential read-out has no legitimate caller; the OAuth flow that establishes
// those credentials lives in ZidOAuthController below.
Route::get('/zid/oauth/callback', [ZidOAuthController::class, 'callback']);




Route::get('/zid/oauth/redirect', [ZidOAuthController::class, 'redirect']);


Route::get('/zid/connect/return', [\App\Http\Controllers\ZidOAuthController::class, 'connectReturn']);