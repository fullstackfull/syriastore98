<?php

namespace App\Http\Middleware;

use App\Scopes\StoreScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * NEW-BULKDELETE-01 -- the confirmed cross-tenant DESTRUCTIVE vulnerability, fixed OUTSIDE
 * packages/.
 *
 * ============================================================================================
 *  WHAT IS WRONG, exactly, with a file:line.
 * ============================================================================================
 * packages/workdo/BulkDelete/src/app/Http/Controllers/BulkDeleteController.php:116 runs
 *
 *     $model::whereIn('id', $request->ids)->delete();
 *
 * and :114 runs the `customer_id` variant for `abandoncart` and `wishlist`, over a map of 65
 * request-type keys resolving to 61 model classes (counted from the controller's own source by
 * tests/Feature/Tenancy/ResidualTenancyExposureTest). There is NO tenant predicate and NO
 * per-type permission check anywhere in that method. The only gate is the route declaration at
 * packages/workdo/BulkDelete/src/routes/web.php:17,
 * `Route::middleware(['auth', 'web', 'PlanModuleCheck:BulkDelete'])` -- i.e. "be logged in and
 * have the addon in your plan".
 *
 * Both halves of that are reachable by a merchant:
 *   - `Builder::delete()` (vendor Builder.php:1381-1388) DOES go through `toBase()`, so
 *     App\Scopes\StoreScope constrains the TEN allowlisted models. Five of them are in this map
 *     (Cart, Customer, Menu, OrderRefund, SupportTicket). The other 56 are NOT constrained.
 *   - The map also carries Country, State, City, Currency, PlanCoupon, PlanUserCoupon, PlanOrder
 *     and PlanRequest -- PLATFORM reference and SaaS billing data. countries/states/cities have
 *     no `store_id` at all, so no global scope can ever reach them, and the merchant-reachable
 *     admin pages already render the bulk-delete checkbox over them
 *     (resources/views/country/index.blade.php:187).
 *
 * The single-record paths for exactly those models are NOT this permissive, which is the
 * evidence that the bulk path is an omission and not a design:
 *   CountryController::destroy   app/Http/Controllers/CountryController.php:128  -- super admin only
 *   StateController::destroy     app/Http/Controllers/StateController.php:119    -- super admin only
 *   CityController::destroy      app/Http/Controllers/CityController.php:118     -- super admin only
 *   CurrencyController::destroy  app/Http/Controllers/CurrencyController.php:126 -- body commented out,
 *                                                                                  nobody can delete a currency
 * So for a merchant the bulk endpoint is strictly MORE powerful than every per-record screen it
 * was bolted onto.
 *
 * ============================================================================================
 *  WHY THIS IS A MIDDLEWARE IN app/ AND NOT AN EDIT TO packages/.
 * ============================================================================================
 * The owner's instruction was to prefer a genuinely effective fix outside the protected package,
 * and one exists. Measured, not assumed -- tests/Feature/Tenancy/BulkDeleteRouteReachabilityTest
 * asserts the resolved middleware stack of the `bulk-delete` route and finds, in order:
 *
 *   EncryptCookies, AddQueuedCookiesToResponse, StartSession, ShareErrorsFromSession,
 *   ValidateCsrfToken, Authenticate, SubstituteBindings, SetLocate, <this guard>,
 *   PlanModuleCheck:BulkDelete
 *
 * Three properties make that position sufficient:
 *   1. The package route group declares `web` (routes/web.php:17) and its RouteServiceProvider
 *      wraps the same file in `Route::middleware('web')` (RouteServiceProvider.php:49), so
 *      appending to the `web` group in bootstrap/app.php reaches it. It cannot be dodged by
 *      route-registration ORDER, which a shadowing re-declaration in routes/web.php could be --
 *      the addon registers its routes from `$this->app->booted()`
 *      (app/Providers/PackageServiceProvider.php:20), i.e. AFTER routes/web.php has been read,
 *      so a re-declaration there would be the loser and the fix would silently not apply.
 *   2. `bootstrap/app.php` always executes, including under `route:cache`. Pushing to the group
 *      from inside routes/web.php would not: cached routes never load the route files, and the
 *      guard would silently vanish in exactly the environment that matters.
 *   3. It runs AFTER Authenticate, so `auth()->user()` is populated here. It still treats a null
 *      user as "not mine to answer" and defers to Authenticate, so the guard can never turn a
 *      sign-in redirect into a 403.
 *
 * Consequently packages/workdo/ is UNMODIFIED by this fix and
 * .github/scripts/guard-no-workdo-changes.sh keeps its blanket refusal -- no narrow allowance was
 * added, because none was needed.
 *
 * ============================================================================================
 *  WHAT THIS GUARD DOES. Four controls, in order.
 * ============================================================================================
 *  A. INPUT SHAPE. `type` must be a non-empty string and `ids` a non-empty flat array of
 *     scalars. (`array_key_exists($request->type, ...)` at the controller's :111 is a TypeError
 *     on an array `type`, so this also removes a 500.)
 *  B. PLATFORM-GLOBAL DENIAL. The eight platform/SaaS types are refused to everyone who is not
 *     a platform operator, matching the per-record controllers quoted above.
 *  C. PERMISSION. For the 23 types whose single-record `destroy()` already requires a named
 *     permission, the SAME name is required here. The names are not invented and not chosen:
 *     they are read out of those controllers, and
 *     BulkDeleteAuthorizationTest::test_every_permission_name_this_guard_requires_is_really_seeded
 *     asserts each one is created by Utility::addNewData(). Types whose per-record path uses a
 *     POLICY instead (Order, OrderRefund, Role, Customer) or has no check at all get no
 *     permission gate here -- gating them on a name no seeder creates is the defect that locked
 *     out the finance surface once already, so that residue is REPORTED, not papered over.
 *  D. TENANT FILTER. `ids` is REWRITTEN to the subset the caller's tenant provably owns, read
 *     back out of the database, before the controller ever sees it. Legitimate rows still go;
 *     foreign rows are not merely "protected by a scope" -- they are no longer in the request.
 *
 * The tenant comes from StoreScope::resolveTenant(), the SAME resolver the global scope uses, so
 * the guard and the scope cannot drift apart into a state where one allows what the other denies.
 * A platform operator (MODE_SUPER_ADMIN) is passed through untouched: narrowing them is what
 * breaks platform administration in a way that looks like missing data.
 *
 * FAIL-CLOSED, DELIBERATELY. An unknown `type`, a model class that cannot be resolved, a missing
 * table, or a table with no ownership column this guard understands all produce 403 rather than
 * an unfiltered delete. That is a real behaviour change for the 23 `Workdo\` addon models in the
 * map whose tables carry no `store_id`: their bulk delete is DISABLED until someone states how
 * their ownership is derived. It is reported as FAIL-CLOSED, not as verified.
 *
 * 403, NEVER 401: repo B's lib/network/network.dart turns any 401 into a forced logout that
 * clears preferences, and 403 into a plain "not allowed".
 */
class BulkDeleteTenantGuard
{
    /** The name the addon gives its one route (packages/.../BulkDelete/src/routes/web.php:18). */
    public const ROUTE_NAME = 'bulk-delete';

    /** Matched as well as the name, so an upstream rename cannot silently unhook the guard. */
    public const CONTROLLER = 'Workdo\\BulkDelete\\app\\Http\\Controllers\\BulkDeleteController';

    /**
     * The controller's own `$modelMap` (:62-105) plus its `loadConditionalModels()` table
     * (:133-157), mirrored here because that map is a LOCAL VARIABLE inside bulkDelete() and
     * neither reflection nor a container binding can reach it.
     *
     * A mirror can rot, so it is ratcheted:
     * BulkDeleteAuthorizationTest::test_this_guard_knows_every_type_the_controller_knows parses
     * the controller's source every run and fails by name on any type or class this const does
     * not match. And an unknown type is refused rather than passed, so the failure mode of a
     * stale mirror is a 403, not a cross-tenant delete.
     */
    public const MODEL_MAP = [
        'blog' => \App\Models\Blog::class,
        'blog-category' => \App\Models\BlogCategory::class,
        'plan-coupon' => \App\Models\PlanCoupon::class,
        'product-brand' => \App\Models\ProductBrand::class,
        'abandoncart' => \App\Models\Cart::class,
        'contact' => \App\Models\Contact::class,
        'coupon' => \App\Models\Coupon::class,
        'currency' => \App\Models\Currency::class,
        'customer' => \App\Models\Customer::class,
        'faqs' => \App\Models\Faq::class,
        'flash-sale' => \App\Models\FlashSale::class,
        'main-category' => \App\Models\Category::class,
        'menu-table' => \App\Models\Menu::class,
        'newsletter' => \App\Models\Newsletter::class,
        'order' => \App\Models\Order::class,
        'order-refunds' => \App\Models\OrderRefund::class,
        'page' => \App\Models\Page::class,
        'plan-coupon-detail' => \App\Models\PlanUserCoupon::class,
        'plan-order' => \App\Models\PlanOrder::class,
        'plan-request' => \App\Models\PlanRequest::class,
        'product-attribute' => \App\Models\ProductAttribute::class,
        'Product' => \App\Models\Product::class,
        'product-label' => \App\Models\ProductLabel::class,
        'product-question' => \App\Models\ProductQuestion::class,
        'roles' => \App\Models\Role::class,
        'sales-downloadable-product' => \App\Models\Order::class,
        'shipping' => \App\Models\Shipping::class,
        'shipping-taxmethod' => \App\Models\ShippingMethod::class,
        'shipping-zone' => \App\Models\ShippingZone::class,
        'supportticket' => \App\Models\SupportTicket::class,
        'tag' => \App\Models\Tag::class,
        'taxes' => \App\Models\Tax::class,
        'tax-method' => \App\Models\TaxMethod::class,
        'testimonial' => \App\Models\Testimonial::class,
        'user-coupon' => \App\Models\UserCoupon::class,
        'wishlist' => \App\Models\Wishlist::class,
        'auction-product-addon' => \App\Models\Product::class,
        'digital-product-addon' => \App\Models\Product::class,
        'product-barcode-addon' => \App\Models\Product::class,
        'country' => \App\Models\Country::class,
        'state' => \App\Models\State::class,
        'city' => \App\Models\City::class,
        // loadConditionalModels() -- present only where the addon is installed AND its
        // namespace is autoloadable. `Workdo\` is absent from composer.json's psr-4 block, so
        // App\Providers\PackageServiceProvider::loadAddons() registers it at runtime for
        // ENABLED addons only. Listed here as strings for the same reason the controller does.
        'additionalfield' => 'Workdo\AdditionalFieldInCheckout\app\Models\AdditionalField',
        'auctionproductorder' => 'Workdo\AuctionProduct\app\Models\AuctionProductOrder',
        'auctionproduct-setting' => 'Workdo\AuctionProduct\app\Models\AuctionProductSetting',
        'backuprestorelog' => 'Workdo\BackupRestore\app\Models\BackupRestoreLog',
        'bundleproduct' => 'Workdo\BundleProduct\app\Models\BundleProduct',
        'campaigns' => 'Workdo\Campaigns\app\Models\Campaigns',
        'customerdonation' => 'Workdo\Donation\app\Models\CustomerDonation',
        'donation' => 'Workdo\Donation\app\Models\Donation',
        'helpdeskticketcategory' => 'Workdo\Helpdesk\app\Models\HelpdeskCategory',
        'helpdeskticket' => 'Workdo\Helpdesk\app\Models\HelpdeskTicket',
        'helpdeskticket-status' => 'Workdo\Helpdesk\app\Models\HelpdeskStatus',
        'marketing-appointment' => 'Workdo\Marketing\app\Models\AppointmentDeatail',
        'marketing-contact' => 'Workdo\Marketing\app\Models\MarketingContact',
        'orderpartialpayment' => 'Workdo\PartialPayments\app\Models\OrderPartialPayments',
        'pdfVoucher-addon' => 'Workdo\PDFVouchers\app\Models\CouponRequest',
        'productcatelog' => 'Workdo\ProductCatelog\app\Models\ProductCatelog',
        'productenquiry' => 'Workdo\ProductEnquiry\app\Models\ProductEnquiry',
        'product-review' => 'Workdo\ProductReview\app\Models\ProductReview',
        'recentlyviewedproduct' => 'Workdo\RecentlyViewedProducts\app\Models\RecentlyViewedProduct',
        'order-reviews' => 'Workdo\ReviewReminder\app\Models\OrderReviews',
        'productcomment' => 'Workdo\SpamPrevent\app\Models\ProductComment',
        'countdown-addon' => 'Workdo\CountDownTimer\app\Models\CountDownTimer',
        'productpricing-addon' => 'Workdo\ProductPricing\app\Models\ProductPricingRule',
    ];

    /**
     * NOT merchant data, under any tenant. Four are platform reference tables with no
     * discriminator at all; four are SaaS billing/subscription records belonging to the platform
     * operator. `plan-order` is here DESPITE `plan_orders.store_id` existing: it is the record of
     * a merchant's own subscription payment, and letting the payer erase it is an audit problem,
     * not a tenancy one.
     */
    public const PLATFORM_GLOBAL = [
        'country',
        'state',
        'city',
        'currency',
        'plan-coupon',
        'plan-coupon-detail',
        'plan-order',
        'plan-request',
    ];

    /**
     * The two types the controller deletes by `customer_id` rather than by primary key
     * (BulkDeleteController.php:113-114). The ids in the request are customer ids, so the filter
     * has to be about which customers' rows may be touched, not which row ids.
     */
    public const CUSTOMER_KEYED = ['abandoncart', 'wishlist'];

    /**
     * type => the permission the SINGLE-RECORD delete for the same model already requires.
     * Read out of the controllers, never invented:
     *   Delete Blog            BlogController::destroy            Delete Blog Category   BlogCategoryController
     *   Delete Product Brand   ProductBrandController             Delete Contact Us      ContactController
     *   Delete Coupon          CouponController                   Delete Faqs            FaqController
     *   Delete Flash Sale      FlashSaleController                Delete Product Category CategoryController
     *   Delete Menu            MenuController                     Delete Newsletter      NewsletterController
     *   Delete Page            PageController                     Delete Attributes      ProductAttributeController
     *   Delete Products        ProductController                  Delete Product Label   ProductLabelController
     *   Delete Product Question ProductQuestionController         Delete Shipping Class  ShippingController
     *   Delete Shipping Zone   ShippingZoneController             Delete Support Ticket  SupportTicketController
     *   Delete Tag             TagController                      Delete Tax             TaxController
     *   Delete Tax Method      TaxMethodController                Delete Testimonial     TestimonialController
     *   Delete Wishlist        WishlistController
     */
    public const PERMISSIONS = [
        'blog' => 'Delete Blog',
        'blog-category' => 'Delete Blog Category',
        'product-brand' => 'Delete Product Brand',
        'contact' => 'Delete Contact Us',
        'coupon' => 'Delete Coupon',
        'faqs' => 'Delete Faqs',
        'flash-sale' => 'Delete Flash Sale',
        'main-category' => 'Delete Product Category',
        'menu-table' => 'Delete Menu',
        'newsletter' => 'Delete Newsletter',
        'page' => 'Delete Page',
        'product-attribute' => 'Delete Attributes',
        'Product' => 'Delete Products',
        'auction-product-addon' => 'Delete Products',
        'digital-product-addon' => 'Delete Products',
        'product-barcode-addon' => 'Delete Products',
        'product-label' => 'Delete Product Label',
        'product-question' => 'Delete Product Question',
        'shipping' => 'Delete Shipping Class',
        'shipping-zone' => 'Delete Shipping Zone',
        'supportticket' => 'Delete Support Ticket',
        'tag' => 'Delete Tag',
        'taxes' => 'Delete Tax',
        'tax-method' => 'Delete Tax Method',
        'testimonial' => 'Delete Testimonial',
        'wishlist' => 'Delete Wishlist',
    ];

    /**
     * Ownership that has to be reached through a relation because the table itself carries no
     * discriminator.
     *
     * `user_coupons` records a STORE coupon's redemption (coupon_id, user_id, order_id) and has
     * no `store_id` -- so unlike the eight platform types it IS merchant data, and refusing it
     * outright would remove a legitimate screen. Its tenant is the coupon's:
     * `coupons.store_id`. A row whose coupon no longer exists is not deletable here, which is
     * the fail-closed direction.
     *
     * type => [local column, related table, related tenant column]
     */
    public const DERIVED_OWNERSHIP = [
        'user-coupon' => ['coupon_id', 'coupons', 'store_id'],
    ];

    /** Memo of table => has store_id, so one request pays for the schema lookup once. */
    private static array $storeColumnMemo = [];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isBulkDeleteRoute($request)) {
            return $next($request);
        }

        $user = auth()->user();

        // NOT a denial. Authenticate runs before this guard on the real stack, so an anonymous
        // request never gets here; if the stack is ever reordered, deferring keeps the sign-in
        // redirect intact instead of answering 403 to someone who merely has no session.
        if (! $user) {
            return $next($request);
        }

        // THE ONE PLATFORM-OPERATOR BYPASS, same branch StoreScope::resolveTenant() takes and
        // same as CountryController::destroy:128.
        if (isset($user->type) && strtolower(trim((string) $user->type)) === 'super admin') {
            return $next($request);
        }

        // ---- A. INPUT SHAPE ----------------------------------------------------------------
        $type = $request->input('type');

        if (! is_string($type) || trim($type) === '') {
            return $this->reject(__('Invalid type specified'), 422);
        }

        $ids = $request->input('ids');

        if (! is_array($ids) || $ids === []) {
            return $this->reject(__('No rows selected'), 422);
        }

        foreach ($ids as $id) {
            if (! is_scalar($id)) {
                return $this->reject(__('No rows selected'), 422);
            }
        }

        $ids = array_values(array_unique(array_map('strval', $ids)));

        // ---- B. PLATFORM-GLOBAL DENIAL -----------------------------------------------------
        if (in_array($type, self::PLATFORM_GLOBAL, true)) {
            return $this->reject(__('Permission denied.'));
        }

        // Fail closed on anything this guard cannot reason about, INCLUDING a type the
        // controller would have answered 400 for. A 403 there is a smaller lie than a delete.
        if (! array_key_exists($type, self::MODEL_MAP)) {
            return $this->reject(__('Permission denied.'));
        }

        // ---- C. PERMISSION -----------------------------------------------------------------
        if (isset(self::PERMISSIONS[$type])
            && ! $this->userCan($user, self::PERMISSIONS[$type])) {
            return $this->reject(__('Permission denied.'));
        }

        // ---- D. TENANT FILTER --------------------------------------------------------------
        $tenant = StoreScope::resolveTenant();

        if ($tenant['mode'] !== StoreScope::MODE_TENANT || $tenant['store_ids'] === []) {
            return $this->reject(__('Permission denied.'));
        }

        $storeIds = array_map('intval', $tenant['store_ids']);

        $class = self::MODEL_MAP[$type];

        if (! class_exists($class)) {
            return $this->reject(__('Permission denied.'));
        }

        $table = (new $class())->getTable();

        if (! Schema::hasTable($table)) {
            return $this->reject(__('Permission denied.'));
        }

        $owned = $this->ownedIds($type, $table, $ids, $storeIds, $user);

        if ($owned === null) {
            return $this->reject(__('Permission denied.'));
        }

        // The controller reads `$request->ids`, which Request::__get resolves through
        // Request::all(); merge() writes into the active input source (form or JSON), so the
        // rewritten list is what :114/:116 actually binds.
        $request->merge(['ids' => $owned]);

        return $next($request);
    }

    /**
     * Is the MATCHED route the addon's bulk-delete endpoint?
     *
     * Checked by name AND by controller class: the name is what the view's `route("bulk-delete")`
     * uses, and the class is what survives an upstream rename. Either match arms the guard.
     */
    private function isBulkDeleteRoute(Request $request): bool
    {
        $route = $request->route();

        if ($route === null) {
            return false;
        }

        if ($route->getName() === self::ROUTE_NAME) {
            return true;
        }

        return str_starts_with((string) $route->getActionName(), self::CONTROLLER);
    }

    /**
     * The subset of $ids the tenant provably owns, or null when ownership cannot be established
     * at all for this type (caller turns that into a 403).
     *
     * @param  array<int, string>  $ids
     * @param  array<int, int>  $storeIds
     * @return array<int, string>|null
     */
    private function ownedIds(string $type, string $table, array $ids, array $storeIds, $user): ?array
    {
        $hasStoreColumn = $this->hasStoreColumn($table);

        // ---- ids are customer ids, not row ids (controller :114) ---------------------------
        if (in_array($type, self::CUSTOMER_KEYED, true)) {
            if (! $hasStoreColumn || ! Schema::hasColumn($table, 'customer_id')) {
                return null;
            }

            // A customer id survives only when EVERY row it names belongs to this tenant.
            // Filtering to "has at least one row here" would let one foreign row ride along on
            // the same customer id, and `delete()` would take it.
            $foreign = DB::table($table)
                ->whereIn('customer_id', $ids)
                ->where(function ($q) use ($storeIds) {
                    $q->whereNotIn('store_id', $storeIds)->orWhereNull('store_id');
                })
                ->distinct()
                ->pluck('customer_id')
                ->map(fn ($value) => (string) $value)
                ->all();

            return array_values(array_diff($ids, $foreign));
        }

        // ---- ownership reached through a relation (user_coupons -> coupons) -----------------
        if (! $hasStoreColumn && isset(self::DERIVED_OWNERSHIP[$type])) {
            [$localColumn, $relatedTable, $relatedColumn] = self::DERIVED_OWNERSHIP[$type];

            if (! Schema::hasColumn($table, $localColumn)
                || ! Schema::hasTable($relatedTable)
                || ! Schema::hasColumn($relatedTable, $relatedColumn)) {
                return null;
            }

            return DB::table($table)
                ->whereIn($table . '.id', $ids)
                ->whereIn($localColumn, function ($q) use ($relatedTable, $relatedColumn, $storeIds) {
                    $q->select('id')->from($relatedTable)->whereIn($relatedColumn, $storeIds);
                })
                ->pluck($table . '.id')
                ->map(fn ($value) => (string) $value)
                ->all();
        }

        // ---- no discriminator and no stated derivation: FAIL CLOSED ------------------------
        if (! $hasStoreColumn) {
            return null;
        }

        $query = DB::table($table)->whereIn('id', $ids)->whereIn('store_id', $storeIds);

        // `roles` is the one table in this map whose discriminator is NULLABLE: the shared global
        // `admin` role that every self-registered merchant uses is created with store_id NULL
        // (laratrust_setup_tables.php:25). `whereIn('store_id', ...)` already refuses NULL, and
        // `created_by` is added so the predicate is the same pair App\Policies\RolePolicy::
        // ownsRole() applies on the single-record path rather than a looser cousin of it.
        if ($type === 'roles' && Schema::hasColumn($table, 'created_by')) {
            $query->where('created_by', $user->creatorId());
        }

        return $query->pluck('id')->map(fn ($value) => (string) $value)->all();
    }

    private function hasStoreColumn(string $table): bool
    {
        if (! array_key_exists($table, self::$storeColumnMemo)) {
            self::$storeColumnMemo[$table] = Schema::hasColumn($table, 'store_id');
        }

        return self::$storeColumnMemo[$table];
    }

    private function userCan($user, string $permission): bool
    {
        try {
            return (bool) $user->isAbleTo($permission);
        } catch (\Throwable $e) {
            // Laratrust throws when the permission row does not exist at all. A missing
            // permission is not a grant.
            return false;
        }
    }

    /**
     * 403 in the controller's own JSON envelope (`status` + `message`), because the addon's
     * script.blade.php reads `response.status` / `response.message`. NEVER 401 -- repo B's
     * lib/network/network.dart maps 401 to a forced logout that clears preferences.
     */
    private function reject(string $message, int $status = 403): Response
    {
        return response()->json(['status' => false, 'message' => $message], $status);
    }
}
