<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class DemoModeMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Get the current route action (Controller@method)
        $routeAction = Route::currentRouteAction();

        // Extract the controller and method from the route action
        if (str_contains($routeAction, '@')) {
            [$controller, $method] = explode('@', $routeAction);

            // Controllers where the demo mode should always apply for the 'store' method
            $storeRestrictedControllers = [
                'AppSettingController',
                'BackupRestoreController',
                'CustomPageController',
                'DiscoverController',
                'FaqController',
                'FeaturesController',
                'FooterController',
                'HomeController',
                'JoinUsController',
                'LandingPageController',
                'PricingPlanController',
                'ScreenshotsController',
                'TestimonialsController',
                'MailSettingController',
                'ProductAffiliateController',
                'ImageBadgeController',
                'ProductPricingController',
                'ProductReviewController',
                'RecentlyViewedProductsController',
                'StoreController',
                'WebhookController',
                'PixelFieldsController'
            ];

            $showRestrictedControllers = [
                'WoocomCategoryController',
                'WoocomCouponController',
                'WoocomCustomerController',
                'WoocomProductController',
                'ShopifyCategoryController',
                'ShopifyCouponController',
                'ShopifyCustomerController',
                'ShopifyProductController',
            ];

            $editRestrictedControllers = [
                'ProductCatelogController',
                'WoocomCategoryController',
                'WoocomCouponController',
                'WoocomCustomerController',
                'WoocomProductController',
                'ShopifyCategoryController',
                'ShopifyCouponController',
                'ShopifyCustomerController',
                'ShopifyProductController',
            ];

            // Methods where demo mode restriction should apply (e.g., 'update', 'destroy')
            $restrictedMethods = ["password_change","update_support_ticket","destroy_support_ticket","attachmentDestroy","update","ThemeInstall","ThemeEnable","seoSettings","shippingLabelSettings","product_page_setting","ThemeSettings","FirebaseSettings","SiteSetting","orderreject","abandon_carts_destroy","customerStatus","updatePassword","updateEmailNotificationStatus","storeLanguageData","disableLang","storeLanguage","destroyLang","updateMenuItem","deleteMenuItem","addLinkToMenu","updateMenu","enable","remove","install","order_return","order_return_request","order_status_change","updateStatus","updateRefundStatus","CancelRefundStatus","RefundStock","updateFinalPrice","RefundAmonut","planTrial","refund","cancelRequest","acceptRequest","changeStatus","changePopular","StorageSettings","saveEmailSettings","CookieSettings","RecaptchaSetting","ChatgptSettings","CustomizeSetting","LoyalityProgramSettings","WoocommerceSettings","shopifySettings","SystemSettings","customMassage","StockSettings","WhatsappSettings","whatsapp_notification","whatsapp_notification_setting","testSendwhatsappmassage","TwilioSettings","currencySettings","SEOSetting","freeShippingUpdate","localShippingUpdate","pwaSetting","storeResetPasswordUpdate","ownerstoredestroy","activePlan","taxSettings","saveThemeLayout","publishTheme","pageSetting","makeActiveTheme","updatePassword","editprofile","password_update","userUnable","userUnable","userLoginManage","printSettings","backupDatabase","boostSalesSettings","ChangeStatus","campaignsEnable","countDownSettings","updateStatus","saveCouponEmailSettings","updateDonationData","duo2FASetting","freeShippingPopupSettings","product_cartlist","frequentlySetting","generate2faSecret","enable2fa","disable2fa","googleLoginSetting","deleteTicket","storeTicketConversion","showLinkTicketReply","updateCategory","deleteCategory","updateStatus","deleteStatus","fileImport","buildtech_store","buildtech_card_store","buildtech_card_update","buildtech_card_delete","customStore","dedicated_store","dedicated_card_store","dedicated_card_update","dedicated_card_delete","discover_store","discover_update","discover_delete","faq_store","faq_update","faq_delete","feature_store","feature_update","feature_delete","feature_highlight_create","features_store","features_update","features_delete","footer_section_create","footer_section_update","footer_section_delete","joinUsUserStore","product_main_store","dedicated_theme_header_store","dedicated_theme_store","dedicated_theme_update","dedicated_theme_delete","whychoose_store","screenshots_store","screenshots_update","screenshots_delete","addon_store","pageUrlStore","pageUrlUpdate","pageUrlDelete","embededUrlStore","embededUrlUpdate","embededUrlDelete","addPageToMenu","manageOwnermenu","packagedetails_store","review_store","review_update","review_delete","screenshots_store","screenshots_update","screenshots_delete","testimonials_store","testimonials_update","testimonials_delete","storedata","updatedata","blocksetting","domainsetting","saveseo","savePWA","saveCookiesetting","saveCustomQrsetting","storeHoursSettings","updateCustomerEmailSettings","PartialPaymentSettings","payment_status_change","status","adminManageEmailLang","updateProductCatelogEmail","rulesCondition","ruleCondition","purchaseNotificationSettings","updateSettingButtonData","recentlyViewSetting","recentOrdersSettings","manageReviewEmailLang","updateReviewEmailSettings","clubPointSettings","BitBucketSettings","facebookSettings","githubSigninSetting","LinkedinSettings","outlookSettings","saveSlackSettings","TwitterSettings","SkipCartSettings","storeNotificationLang","saveSmsSettings","spam_admin_store","updateCustomerEmailSettings","updateSettingsData","tawkToMessengerSetting","wizzchatSetting","themeCustomizerSetting"];

            // 1. Check for specific controllers and 'store' method
            if (in_array($controller, $storeRestrictedControllers) && $method === 'store') {
                // Apply demo mode condition for 'store' method
                return $this->handleDemoMode($request);
            }

            // 2. Check for specific controllers and 'store' method
            if (in_array($controller, $showRestrictedControllers) && $method === 'show') {
                // Apply demo mode condition for 'store' method
                return $this->handleDemoMode($request);
            }

            // 3. Check for specific controllers and 'store' method
            if (in_array($controller, $editRestrictedControllers) && $method === 'edit') {
                // Apply demo mode condition for 'store' method
                return $this->handleDemoMode($request);
            }

            // 4. For other controllers, check if the method exists in the restricted methods array
            if (in_array($method, $restrictedMethods)) {
                // Apply demo mode condition for specific methods
                return $this->handleDemoMode($request);
            }

            // 5. Bypass demo mode for AuthenticatedSessionController's destroy (logout) method
            if ($controller === 'AuthenticatedSessionController' && $method === 'destroy') {
                return $next($request);
            }
        }

        // Allow the request to proceed if it's not targeting 'update' or 'destroy'
        return $next($request);
    }
}
