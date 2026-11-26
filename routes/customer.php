<?php

use Illuminate\Support\Facades\Route;

use App\Http\Middleware\ActiveTheme;
use App\Http\Controllers\ProductQuestionController;
use App\Http\Controllers\AccountProfileController;
use App\Http\Controllers\WishlistController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Customer\Auth\CustomerLoginController;
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
use App\Http\Controllers\CartController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\Marketing\UnsubscribeController;
use App\Http\Controllers\Marketing\CommunicationPreferencesController;

Route::middleware([ActiveTheme::class, 'themelanguage'])->group(function () {

    /*
     * D8-3a — THE UNSUBSCRIBE LINK. GAP-200 / MKT-008.
     *
     * THE GET ONLY CONFIRMS; THE POST WITHDRAWS. A token is reusable by design -- an unsubscribe link
     * that stops working is a failure to honour an unsubscribe -- so a mail scanner or link prefetcher
     * following an OLD link's GET would otherwise withdraw an opt-in the person has since given again.
     * UnsubscribeTokens' own docblock requires this split, and UnsubscribePageTest asserts it.
     *
     * `{storeSlug}` IS REQUIRED HERE, unlike its optional neighbours below: the token is scoped to one
     * store and the service takes that store as a REQUIRED argument, so this page must be served under a
     * named store rather than whichever one `getStore(null)` would happen to resolve.
     *
     * THROTTLED, because every recipient holds a working link and every redemption appends a row to a
     * table nothing may prune. Declared first in this group so no later pattern can shadow them; both
     * carry the literal `unsubscribe` segment, so they shadow nothing themselves.
     *
     * THE TWO HALVES USE DIFFERENT LIMITERS, and that is a fix rather than tidiness (review finding
     * F5c). They shared one `throttle:30,1`, which keys on the IP -- so a mail scanner prefetching GETs
     * from a provider's egress address could exhaust the budget and the recipient's own POST would come
     * back 429, meaning the withdrawal WAS NEVER RECORDED. The GET is a cheap read and stays per-IP,
     * which is what bounds enumeration; the POST is keyed on the token (`unsubscribe-redeem` in
     * RouteServiceProvider), so nobody else's traffic can spend a recipient's budget.
     */
    Route::get('{storeSlug}/unsubscribe/{token}', [UnsubscribeController::class, 'show'])
        ->middleware('throttle:30,1')
        ->name('marketing.unsubscribe.show');
    Route::post('{storeSlug}/unsubscribe/{token}', [UnsubscribeController::class, 'redeem'])
        ->middleware('throttle:unsubscribe-redeem')
        ->name('marketing.unsubscribe.redeem');

    Route::get('{storeSlug?}/product-list', [HomeController::class, 'product_page'])->name('page.product-list');
    Route::get('{storeSlug?}/product-filter', [HomeController::class, 'product_page_filter'])->name('product.page.filter');
    Route::get('{storeSlug?}/product/{product_slug}', [HomeController::class, 'product_detail'])->name('page.product');
    Route::get('{storeSlug?}/faq', [HomeController::class, 'faqs_page'])->name('page.faq');
    Route::get('{storeSlug?}/about', [HomeController::class, 'about_page'])->name('page.about');
    Route::get('{storeSlug?}/blog', [HomeController::class, 'blog_page'])->name('page.blog');
    // NEW-ROUTE-SHADOW-02 -- the duplicate declaration is REMOVED, nothing else changes.
    //
    // This line used to read
    //     Route::get('{storeSlug?}/contact-us', [HomeController::class, 'contactUs'])
    //         ->name('contact.us');
    // which is the SAME method, the SAME URI and the SAME action as the declaration now at :90
    // below, under a second name. RouteCollection indexes by method+domain+URI and rebuilds the name
    // table from that index, so that one's `page.contact_us` replaced this entry outright and
    // the name `contact.us` was never in the registry: route('contact.us') could only
    // throw RouteNotFoundException.
    //
    // `page.contact_us` is the name that must survive, and it is the one kept: it is read
    // at runtime by resources/views/front_end/jQueryRoute.blade.php:333
    // (`\Request::route()->getName() == 'page.contact_us'`). `contact.us` is called from
    // nowhere -- verified with an ESCAPED dot, `grep -rn "contact\.us"`, because an
    // unescaped one also matches contact-us and contact_us_banner_title and that mistake
    // has already produced a wrong conclusion on this codebase.
    //
    // So this is a dead duplicate declaration, not a lost feature: the storefront contact
    // page keeps exactly the route, URI, action and name it is served by today. Removing
    // the declaration -- rather than giving it a second URI -- is what closes
    // RouteRegistrationIntegrityTest's aggregate assertion without inventing a new public
    // URL for a page that already has one.
    Route::post('{storeSlug?}/contact/save', [HomeController::class, 'contactUsSave'])->name('contact.submit');
    Route::get('{storeSlug?}/article/{slug}', [HomeController::class, 'article_page'])->name('page.article');
    Route::get('{storeSlug?}/cart', [HomeController::class, 'cart_page'])->name('page.cart');
    Route::any('{storeSlug?}/checkout', [HomeController::class, 'checkout'])->name('checkout');
    Route::post('{storeSlug?}/checkout/address/form', [HomeController::class, 'addressForm'])->name('order.address.form');
    // The number is a timestamp, so the email is the only secret. Two limiters, one per axis (P1-ORD-08):
    // `auth` = one address swept across numbers (IP + the posted email), `order-track` = one number tried
    // against many addresses (IP + slug + number).
    Route::post('{storeSlug?}/order-track', [HomeController::class, 'order_track'])
        ->middleware(['throttle:auth', 'throttle:order-track'])
        ->name('order.track');
    Route::get('{storeSlug?}/wishlist', [HomeController::class, 'wishlist'])->name('wishlist');
    Route::get('{storeSlug?}/search-product', [HomeController::class, 'search_products'])->name('search.product');
    Route::get('{storeSlug?}/home', [HomeController::class, 'landing_page'])->name('landing_page');
    Route::get('{storeSlug?}/error', [HomeController::class, 'pageError'])->name('theme.404');
    Route::get('{storeSlug?}/contact-us', [HomeController::class, 'contactUs'])->name('page.contact_us');
    Route::get('{storeSlug?}/collections/{list}', [HomeController::class, 'product_page'])->name('collections.all');
    Route::get('{storeSlug?}/brand/{list}', [HomeController::class, 'product_page'])->name('brands.all');
    Route::get('{storeSlug?}/privacy-policy', [HomeController::class, 'privacy_page'])->name('privacy_page');
    Route::get('{storeSlug?}/track-order', [HomeController::class, 'orderTrack'])->name('track.order');
    Route::get('{storeSlug?}/page/{page_slug}', [HomeController::class, 'customPage'])->name('page.custom');

    Route::get('{storeSlug?}/collections', [HomeController::class, 'showCategory'])->name('collections');

    Route::post('{storeSlug?}/product_price', [ProductController::class, 'product_price'])->name('product.price');

    Route::get('{storeSlug?}/login', [CustomerLoginController::class, 'showLoginForm'])->name('customer.login');
Route::post('{storeSlug?}/login/{cart?}', [CustomerLoginController::class, 'login'])
    ->middleware('throttle:auth')
    ->name('customer.login.save');
    Route::get('{storeSlug?}/register/{ref_id?}', [CustomerLoginController::class, 'register'])->name('customer.register');
    Route::post('{storeSlug?}/register-data', [CustomerLoginController::class, 'registerData'])->name('customer.registerdata');
    Route::get('{storeSlug?}/forgot-password', [CustomerLoginController::class, 'forgotPasswordForm'])->name('customer.password.request');
    Route::post('{storeSlug?}/forgot-password', [CustomerLoginController::class, 'forgotPassword'])->name('customer.password.email');
    Route::get('{storeSlug?}/reset-password', [CustomerLoginController::class, 'resetPasswordForm'])->name('customer.password.reset');
    Route::post('{storeSlug?}/reset-password', [CustomerLoginController::class, 'resetPassword'])->name('customer.password.update');
    Route::post('{storeSlug?}/customer-logout', [CustomerLoginController::class, 'logout'])->name('customer.logout');






// THROTTLED like the sibling login route at :102-104. Neither of the two OTP POSTs carried a
// limiter: `send` could be driven to mint codes without bound -- SMS and e-mail cost real money
// per message on this platform -- and `verify` could be driven to guess them. The in-handler
// attempt limit (CustomerLoginController::MAX_OTP_ATTEMPTS) bounds guesses per CODE; this bounds
// requests per caller, which is the half an attempt counter cannot do.
Route::post(
    '{storeSlug?}/forgot-password/otp/send',
    [CustomerLoginController::class, 'sendPasswordOtp']
)->middleware('throttle:auth')->name('customer.password.otp.send');

Route::get(
    '{storeSlug?}/forgot-password/otp/verify',
    [CustomerLoginController::class, 'passwordOtpForm']
)->name('customer.password.otp.form');

Route::post(
    '{storeSlug?}/forgot-password/otp/verify',
    [CustomerLoginController::class, 'verifyPasswordOtp']
)->middleware('throttle:auth')->name('customer.password.otp.verify');

Route::get(
    '{storeSlug?}/reset-password/otp',
    [CustomerLoginController::class, 'resetPasswordOtpForm']
)->name('customer.password.otp.reset.form');

Route::post(
    '{storeSlug?}/reset-password/otp',
    [CustomerLoginController::class, 'resetPasswordWithOtp']
)->name('customer.password.otp.reset');







    Route::post('{storeSlug?}/product_cart', [CartController::class, 'product_cartlist'])->name('product.cart');
    Route::post('{storeSlug?}/change-cart', [CartController::class, 'change_cart'])->name('change.cart');
    // GAP-267. `Route::post('{storeSlug?}/place-order', [OrderController::class, 'place_order'])
    //            ->name('place.order');` WAS HERE AND IS DELETED.
    //
    // `OrderController` has no `place_order` -- the only one under app/Http/Controllers is
    // `Api\ApiController::place_order` -- so every POST reaching this was an HTTP 500 rather than
    // a checkout. `route:cache` does not catch that: Laravel resolves the METHOD lazily at
    // dispatch, so only a missing CLASS fails at cache time.
    //
    // DELETED RATHER THAN REPOINTED, which was the open half of the register's row:
    //
    //   1. Nothing calls it. `place.order` is referenced nowhere outside this declaration, and the
    //      shipped checkout posts to `payment.process`
    //      (resources/views/front_end/common/checkout/checkout_form.blade.php).
    //   2. `Api\ApiController::place_order` answers through `ApiResponser` -- its first branch is
    //      `return $this->error([...])`, JSON with HTTP 200 -- which a browser form POST cannot use.
    //   3. It would lose the authentication that handler has always had. MEASURED:
    //          POST api/{slug}/place-order    [api, custom.auth, APILog]
    //          POST {storeSlug?}/place-order  [web, web, ActiveTheme, themelanguage]
    //      The handler resolves its subject with `resolveStoreCustomer($store)` and refuses on
    //      null, so it depends on a principal `custom.auth` logs into the `customers` guard --
    //      which nothing on this route establishes. This is the `auth('customers')`-fallback
    //      family that produced 31 measured defects; it is the last one to extend to a new
    //      unauthenticated surface for no caller.
    //   4. A second order-creation path is a second place for stock, pricing and payment to
    //      diverge from the one that works.
    //
    // The API endpoints `api/{slug}/place-order` and `...-guest` are UNTOUCHED -- both point at the
    // method that exists and both are in the Flutter contract.
    // Pinned in all directions by tests/Unit/Routing/WebPlaceOrderRouteWithdrawnTest.
    Route::post('{storeSlug?}/get-shipping-data', [CartController::class, 'get_shipping_data'])->name('get.shipping.data');
    Route::post('{storeSlug?}/shipping-method', [CartController::class, 'get_shipping_method'])->name('shipping.method');
    Route::any('{storeSlug?}/cart-list-sidebar', [CartController::class, 'cart_list_sidebar'])->name('cart.list.sidebar');
    Route::post('{storeSlug?}/cart-remove', [CartController::class, 'cart_remove'])->name('cart.remove');
    Route::post('{storeSlug?}/cart-clear', [CartController::class, 'cartClear'])->name('cart.clear');
    Route::post('{storeSlug?}/get-tax-data', [CartController::class, 'get_tax_data'])->name('get.tax.data');
   
    Route::any('{storeSlug?}/blogs/filter/view', [BlogController::class, 'blog_filter'])->name('blogs.filter.view');
       
    Route::post('{storeSlug?}/applycoupon', [OrderController::class, 'applycoupon'])->name('applycoupon');
    Route::get('{storeSlug?}/paymentlist', [OrderController::class, 'paymentlist'])->name('paymentlist');
    Route::get('{storeSlug?}/additionalnote', [OrderController::class, 'additionalnote'])->name('additionalnote');
     Route::post('{storeSlug?}/status-cancel', [OrderController::class, 'status_cancel'])->name('status.cancel');
    Route::get('{storeSlug?}/order/{id}', [OrderController::class, 'orderDetails'])->name('order.details');
    Route::get('{storeSlug?}/order-complete', [OrderController::class, 'orderComplete'])->name('order.complete');
    
    Route::post('{slug}/product-wishlist', [WishlistController::class, 'product_wishlist'])->name('product.wishlist');
    Route::post('{storeSlug?}/wish-list-count', [WishlistController::class, 'wishlistCount'])->name('wish.list.count');
    Route::post('{storeSlug?}/wish-list', [WishlistController::class, 'wishlist'])->name('wish.list');

    Route::any('{storeSlug?}/process-order', [PaymentController::class, 'processOrder'])->name('payment.process');
    Route::post('{storeSlug?}/get-massage', [PaymentController::class, 'getWhatsappUrl'])->middleware('throttle:storefront-relay')->name('get.whatsappurl');
    Route::post('{storeSlug?}/telegram', [PaymentController::class, 'whatsapp'])->name('user.telegram');
    Route::post('{storeSlug?}/whatsapp', [PaymentController::class, 'whatsapp'])->name('user.whatsapp');
    Route::any('{storeSlug?}/get-payment-status', [PaymentController::class, 'getProductStatus'])->name('store.payment.status');
    
    Route::post('custom-msg/{slug?}', [SettingController::class, 'customMassage'])->name('customMassage');

    Route::post('{storeSlug?}/order-khalti', [KhaltiPaymnetController::class, 'getOrderPaymentStatus'])->name('order.khalti');
    Route::any('{storeSlug?}/authorizenet-status', [AuthorizeNetPaymnetController::class, 'getOrderPaymentStatus'])->name('order.get.authorizenet.status');

   

    Route::any('{storeSlug?}/get-payment-paiementpro', [PaiementProPaymentController::class, 'getProductStatus'])->name('store.payment.paiementpro');

    Route::any('{storeSlug?}/get-payment-cinetPay', [CinetPayController::class, 'getProductStatus'])->name('store.payment.cinetpay');
    Route::any('{storeSlug?}/return-payment-cinetPay', [CinetPayController::class, 'returnBackToStore'])->name('store.payment.cinetpay.return');

    Route::any('{storeSlug?}/get-payment-easebuzz', [EasebuzzController::class, 'getProductStatus'])->name('store.payment.easebuzz');
    
    //Question-answer
    Route::get('{storeSlug?}/question/{id}', [ProductQuestionController::class, 'Question'])->name('question');
    Route::get('{storeSlug?}/more_question/{id}', [ProductQuestionController::class, 'more_question'])->name('more_question');
    Route::post('{storeSlug?}/product-question', [ProductQuestionController::class, 'product_question'])->name('product_question');

    Route::middleware(['auth:customers'])->group(function () {
        // GAP-268. MEASURED: AccountProfileController defines index, create and store, plus the
        // 48 named account handlers declared in the group below. show / edit / update / destroy
        // were each an HTTP 500 for a signed-in shopper.
        Route::resource('{storeSlug?}/my-account', AccountProfileController::class)
            ->only(['index', 'create', 'store']);
    });

    // ====================================================================
    // NEW-customer-account-routes-anonymous -- THE status.cancel ANALOGUE
    // SWEEP, and the largest single finding of this pass.
    //
    // Only the `my-account` resource above was ever inside
    // ['auth:customers']. Every other AccountProfileController route sat in
    // this file's OUTER group, whose middleware is [ActiveTheme,
    // themelanguage] -- no authentication of any kind. That is exactly the
    // status.cancel shape, repeated across the whole customer account area:
    // a storefront route with no auth, a handler taking a bare numeric id
    // straight off the request, and no ownership predicate.
    //
    // Nothing here is theoretical. Each handler was read. The destructive
    // ones, with the line that does it:
    //
    //   remove_address        :348  DeliveryAddress::where('id', $request->id)
    //                               ->delete()      -- anonymous, any id,
    //                               any tenant.
    //   delete_addressbook    :405  the SAME delete, reachable by GET, so a
    //                               drive-by <img src> deletes an address.
    //   delete_wishlist       :553  Wishlist::where('id', $request->id)
    //                               ->delete()      -- anonymous, by GET.
    //   destroy_support_ticket:729  SupportTicket::where('id', $id)->delete()
    //                               -- anonymous ticket deletion, by GET,
    //                               across every store.
    //   update_support_ticket :690  SupportTicket::find($id) then overwrites
    //                               title, description AND customer_id -- an
    //                               anonymous caller can reassign another
    //                               shopper's ticket to themselves.
    //   attachmentDestroy     :736  mutates any ticket's attachment list.
    //   ticket_reply          :771  appends a SupportConversion to any ticket.
    //   saveAddress           :320  DeliveryAddress::where('id',$request->id)
    //                               ->first()->update($data) -- anonymous
    //                               overwrite of any address row.
    //   order_refund_request  :947  Order::find($request->order_id) and then
    //                               REWRITES product_json, product_price and
    //                               final_price. An anonymous caller rewrites
    //                               the money on any order in any store. This
    //                               is worse than status.cancel was.
    //
    // The IDOR reads: addressForm :271 renders any address id into HTML;
    // edit_support_ticket :661, reply_support_ticket :756 and order_refund
    // :908 render any ticket / refund / order; order_return_list :538 lists
    // EVERY OrderRefund in the store with no customer predicate at all.
    //
    // And the guaranteed null dereferences for an anonymous caller -- the
    // get_file() class, where the error branch was already written and simply
    // unreachable: profile_update :106, order_page_filter :470,
    // add_support_ticket :579, ticket_reply :818, attachmentDestroy :740.
    // Those answered 500, not 403.
    //
    // TENANCY DOES NOT COVER ANY OF THIS. SupportTicket and SupportConversion
    // are two of the six enforced models, but StoreScope FAILS OPEN on a null
    // tenant in the storefront context by documented decision -- and an
    // anonymous storefront request is precisely a null tenant. DeliveryAddress
    // and Wishlist are not trait-carrying at all.
    //
    // WHY EnsureCustomerAuthenticated AND NOT `auth:customers`: these are
    // mostly jQuery/JSON endpoints, and Laravel's Authenticate answers
    // expectsJson() with 401. repo-B lib/network/network.dart maps ANY 401
    // into a forced logout. The middleware also redirects to the STORE's
    // customer login rather than the merchant login. See the class docblock.
    //
    // WHAT IS DELIBERATELY LEFT PUBLIC, and why -- this is the half that has
    // gone wrong twice in this project (a brace move behind `auth` killed
    // newsletter signup on every store; scoping Menu blanked the storefront
    // navigation):
    //
    //   add.newsletter   the footer subscribe form. ANONYMOUS BY DESIGN and
    //                    the handler already branches on auth()->user()
    //                    (:421-425) to store user_id 0 for a guest. Gating it
    //                    is the exact regression that shipped before.
    //   states.list      read-only country->state lookup, and
    //   city.list        read-only state->city lookup. Both are wired into
    //                    front_end/jQueryRoute.blade.php:154-155, which the
    //                    storefront layout includes globally, and into the
    //                    checkout address form. A guest MUST be able to pick
    //                    a state and city to check out.
    //   order, order.page.filter is gated but `order` is NOT: order_list
    //                    :447-451 already branches on auth('customers') and
    //                    renders an empty list for a guest, so it leaks
    //                    nothing and a guest seeing an empty account page is
    //                    not a defect. order.page.filter IS gated because it
    //                    dereferences the null guard user at :470.
    //   address          same -- :249-253 renders an empty list for a guest.
    //   support.ticket   same -- :561-565 renders an empty list for a guest.
    //
    // All five of those are asserted POSITIVELY in
    // tests/Feature/Security/CustomerAccountBoundaryTest.php, because the
    // sweep in UnauthenticatedRouteBoundaryTest SKIPS allowlisted names and
    // therefore proves nothing about reachability.
    //
    // NOT GATED HERE, reported instead: delete.wishlist proves guests cannot
    // own wishlist rows at all -- WishlistController::product_wishlist:178-184
    // makes customer_id a REQUIRED validator field with the message "You must
    // be logged in to add items to your wishlist", so no guest flow is lost.
    // ====================================================================
    Route::post('{storeSlug?}/states-list', [AccountProfileController::class, 'states_list'])->name('states.list');
    Route::post('add-newsletter', [AccountProfileController::class, 'add_newsletter'])->name('add.newsletter');
    Route::post('{storeSlug?}/city-list', [AccountProfileController::class, 'city_list'])->name('city.list');
    /*
     * F217 — the paid-download route. OUTSIDE the `EnsureCustomerAuthenticated` group BY DESIGN,
     * and that is not a gap: a GUEST order (`orders.is_guest`) has no account to authenticate, so
     * requiring the customer guard here would make a guest's paid download unreachable, which the
     * owner's decision explicitly forbids ("support the registered buyer AND the guest"). The
     * entitlement is asked INSIDE the controller instead, and it is asked twice over — the
     * signed-in buyer's own order, or a server-held `DownloadGrant`. Neither is the URL.
     *
     * `{order}` is a database id and nothing else; the FILENAME is never a route parameter, so
     * there is no path for a caller to traverse. See `PaidDownloadController`'s docblock.
     */
    Route::get('{storeSlug?}/paid-download/{order}', [\App\Http\Controllers\PaidDownloadController::class, 'show'])
        ->where('order', '[0-9]+')
        ->name('paid.download');

    Route::get('{storeSlug?}/order', [AccountProfileController::class, 'order_list'])->name('order');
    Route::get('{storeSlug?}/address', [AccountProfileController::class, 'address'])->name('address');
    Route::get('{storeSlug?}/support-ticket', [AccountProfileController::class, 'support_ticket'])->name('support.ticket');

    Route::middleware([\App\Http\Middleware\EnsureCustomerAuthenticated::class])->group(function () {
        /*
         * D8-3b — THE OPT-IN. GAP-200 / MKT-008.
         *
         * BEHIND THE CUSTOMER SESSION, because this is the only surface allowed to record a GRANT and a
         * grant needs the person to prove who they are. The unsubscribe token cannot be reused for it:
         * that token can only WITHDRAW the one contact it was issued for, and cannot grant.
         *
         * The store and the addresses come from the signed-in customer's own row, never from the request
         * or from the slug. The slug decides nothing server-side; it is on the URL so the shopper stays
         * inside the store they were browsing.
         *
         * IT IS REQUIRED, NOT OPTIONAL, and that is a fix rather than a preference. With `{storeSlug?}`
         * the bare `/communication-preferences` was a valid URL -- and the one `route()` generated with no
         * arguments. For that shape `EnsureCustomerAuthenticated` has no slug to build a customer-login
         * URL from, so it sent a signed-out shopper to `route('customer.login')` without a slug, i.e.
         * `/login` -- which `routes/auth.php` claims first and is the MERCHANT login. That is precisely the
         * mistake that middleware's docblock says it exists to prevent. Found by the D8-3 tenant-authz
         * review (F3) and pinned by CommunicationPreferencesTest.
         */
        Route::get('{storeSlug}/communication-preferences', [CommunicationPreferencesController::class, 'show'])
            ->name('marketing.preferences.show');
        Route::post('{storeSlug}/communication-preferences', [CommunicationPreferencesController::class, 'update'])
            ->name('marketing.preferences.update');

        Route::get('{storeSlug?}/adress-form/{id?}', [AccountProfileController::class, 'addressForm'])->name('address-form');
        Route::post('{storeSlug?}/save-address', [AccountProfileController::class, 'saveAddress'])->name('save-address');
        Route::post('{storeSlug?}/add-address', [AccountProfileController::class, 'add_address'])->name('add.address');
        Route::post('{storeSlug?}/remove-address', [AccountProfileController::class, 'remove_address'])->name('remove.address');
        Route::get('{storeSlug?}/edit-address-form', [AccountProfileController::class, 'edit_address_form'])->name('edit.address.form');
        Route::post('{storeSlug?}/update-addressbook-data/{id}', [AccountProfileController::class, 'update_addressbook_data'])->name('update.addressbook.data');
        Route::get('{storeSlug?}/delete-addressbook', [AccountProfileController::class, 'delete_addressbook'])->name('delete.addressbook');
        Route::get('{storeSlug?}/delete-wishlist', [AccountProfileController::class, 'delete_wishlist'])->name('delete.wishlist');
        Route::get('{storeSlug?}/order-filter', [AccountProfileController::class, 'order_page_filter'])->name('order.page.filter');
        Route::get('{storeSlug?}/reward-list', [AccountProfileController::class, 'reward_list'])->name('reward.list');
        Route::get('{storeSlug?}/order-return-list', [AccountProfileController::class, 'order_return_list'])->name('order.return.list');

        Route::post('{storeSlug?}/customer_password_change', [AccountProfileController::class, 'password_change'])->name('customer.password.change');
        Route::post('{storeSlug?}/profile-update', [AccountProfileController::class, 'profile_update'])->name('profile.update');

        Route::get('{storeSlug?}/add-ticket', [AccountProfileController::class, 'add_support_ticket'])->name('add.support.ticket');
        Route::post('{storeSlug?}/store-ticket', [AccountProfileController::class, 'support_ticket_store'])->name('support.ticket.store');
        Route::get('{storeSlug?}/destroy-ticket/{eid}', [AccountProfileController::class, 'destroy_support_ticket'])->name('destroy.ticket');
        Route::get('{storeSlug?}/get-support-ticket/{id}', [AccountProfileController::class, 'edit_support_ticket'])->name('get.support.ticket');
        Route::post('{storeSlug?}/update-support-ticket/{eid}', [AccountProfileController::class, 'update_support_ticket'])->name('update.support.ticket');
        Route::get('{storeSlug?}/reply-support-ticket/{id}', [AccountProfileController::class, 'reply_support_ticket'])->name('reply.support.ticket');
        Route::post('{storeSlug?}/support-ticket/{tid}', [AccountProfileController::class, 'ticket_reply'])->name('ticket.reply');
        Route::delete('{storeSlug?}/ticket-attachment/{tid}/destroy/{id}', [AccountProfileController::class, 'attachmentDestroy'])->name('tickets.attachment.destroy');
        Route::get('{storeSlug?}/customerorder/{id}', [AccountProfileController::class, 'customerorder'])->name('customer.order');
        Route::post('{storeSlug?}/downloadable_prodcut', [AccountProfileController::class, 'downloadable_prodcut'])->name('user.downloadable_prodcut');
        Route::get('{storeSlug?}/order-refund/{id}', [AccountProfileController::class, 'order_refund'])->name('order.refund');
        Route::post('{storeSlug?}/order-refund-request/{id}', [AccountProfileController::class, 'order_refund_request'])->name('order.refund.request');
    });
  
    // senagepay
    Route::post('/plan/company/senangpay', [SenangPayController::class, 'planPayWithSenangpay'])->name('plan.pay.with.senagapay');
    Route::any('/senang-pay/call_back', [SenangPayController::class, 'paymentCallback'])->name('senangpay.call_back');
    
    // CyberSource
    Route::post('plan-pay-with-cybersource', [CyberSourceController::class, 'planPayWithCyberSource'])->name('plan.pay.with.cybersource');
    Route::any('plan-get-cybersource-status', [CyberSourceController::class, 'planPayWithCyberSourceData'])->name('plan.get.cybersource.status');
    Route::any('store-get-cybersource-status', [CyberSourceController::class, 'storePayWithCyberSourceData'])->name('store.get.cybersource.status');
    
    //ozow
    Route::post('plan-pay-with/ozow', [OzowController::class, 'planPayWithOzow'])->name('plan.pay.with.ozow');
    Route::get('plan-get-ozow-status/{plan_id}', [OzowController::class, 'planGetOzowStatus'])->name('plan.get.ozow.status');
    Route::any('store-get-ozow-status', [OzowController::class, 'storeGetOzowStatus'])->name('store.get.ozow.status');
    
    // NMI
    Route::any('{storeSlug?}/get-payment-NMI', [NMIPayController::class, 'getNMIProductStatus'])->name('product-order.pay.with.nmi');
    
    //PayU
    Route::any('/store-payu-payment/status', [PayUPaymentController::class, 'storeGetPayUStatus'])->name('store.payu.status');
    
    // Paynow
    Route::any('store-get-Paynow-status', [PaynowController::class, 'storeGetPaynowStatus'])->name('store.get.Paynow.status');
    
    //MyFatoorah
    Route::any('/myfatoorah/store_call_back', [MyFatoorahController::class, 'paymentCallback'])->name('store.myfatoorah.call_back');
    
    // ESewa
    Route::post('{storeSlug?}/transaction-with-esewa', [ESewaPaymentController::class, 'Transactionfailure'])->name('esewa.transaction.failure');
    
    // DPO
    Route::any('store-pay-with/DPO/create', [DPOPayController::class, 'create'])->name('store.dpo.view');
    Route::any('store-pay-with/DPO', [DPOPayController::class, 'storePayWithDPO'])->name('store.pay.with.dpo');
    Route::any('store-get-DPO-status', [DPOPayController::class, 'storeGetDPOStatus'])->name('store.get.dpo.status');
    
    //Braintree
    Route::post('/store/payment/status', [BraintreeController::class, 'storeGetBraintreeStatus'])->name('store.braintree.status');
    Route::get('store/pay', [BraintreeController::class, 'pay'])->name('store.braintree.pay');
    
    // Powertranz
    Route::any('store-pay-with/Powertranz/create', [PowertranzPaymentController::class, 'create'])->name('store.Powertranz.view');
    Route::any('store-pay-with/Powertranz', [PowertranzPaymentController::class, 'storePayWithPowertranz'])->name('store.pay.with.Powertranz');
    Route::any('store-get-Powertranz-status{storeSlug?}', [PowertranzPaymentController::class, 'storeGetPowertranzStatus'])->name('store.get.Powertranz.status');
    
    // SSLCommerz
    

    //  Route::get('{storeSlug?}', [HomeController::class, 'landing_page'])->name('landing_page')->middleware('themelanguage');
});