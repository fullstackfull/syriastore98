<?php

namespace App\Services;

use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * D-24a — THE FOUR CREATE-ACTIONS THE OWNER APPROVED FOR THE COMMAND PALETTE.
 *
 * إنشاء منتج، إنشاء طلب يدوي، إضافة عميل، إنشاء كوبون — and nothing else. The approval is explicit
 * that the palette shows no delete, payment or refund action, so this list is a constant rather than
 * anything derived from the menu: a menu-derived list would grow one the day a module registers it.
 *
 * WHY A SERVICE AND NOT FOUR `@if`s IN THE BLADE. The same decision has to be made twice — once to
 * decide whether the action is RENDERED, and once by the target controller when the request arrives.
 * The owner's condition names both ("على العرض وعلى الطلب"). The second half is the controller's
 * own gate, and the first half is this class. Keeping it here means one place states the pairing,
 * and `QuickActionTargetsAreGatedTest` asserts that every row's `permission` really is what its
 * target enforces — so the two cannot drift.
 *
 * NO NEW PERMISSION IS INVENTED. Every `permission` below is the one the target already enforces:
 *
 *   product   `Create Product`   ProductController::store(), and now create() too (F160)
 *   order     `Manage Order`     OrderController::create() via mayUseOrderScreen()
 *   customer  `Manage Customer`  CustomerController::create()
 *   coupon    `Create Coupon`    CouponController::create() (gate added by F157)
 *
 * FOUR REASONS AN ACTION IS WITHHELD, and the owner named the first two:
 *
 *  1. THE PAGE OR MODULE IS NOT AVAILABLE. Availability is `MerchantPageDirectory::available()`,
 *     which is built from the real `Menu` for this principal — so a module whose page is not
 *     registered, or is registered and not permitted, is simply absent and its action goes with it.
 *     That is the existing answer to "is this page available", not a second one.
 *  2. THE PRINCIPAL DOES NOT HOLD THE PERMISSION — and for the OPERATOR the answer is per action,
 *     which is the one genuinely surprising thing in this class. The four targets DISAGREE about
 *     the platform operator, and each disagreement is deliberate where it sits:
 *
 *       order     ADMITS   `mayUseOrderScreen()` is `isOperator($user) || isAbleTo($permission)`
 *       customer  ADMITS   `create()` tests `$isOperator` first
 *       coupon    REFUSES  F157's decision: the operator may read and export coupons, never write
 *       product   REFUSES  gated on a bare `isAbleTo('Create Product')`, as `store()` is (F160)
 *
 *     So neither "operator-first everywhere" nor "no operator branch" is correct: the first offers
 *     two actions whose targets will refuse them, the second withholds two the operator can really
 *     use. Each row therefore DECLARES what its target does, and
 *     `MerchantQuickActionTargetsTest` drives every target as an operator and fails if the
 *     declaration and the behaviour disagree. That test, not this list, is what keeps the render
 *     and the request from drifting — which is the owner's condition
 *     ("على العرض وعلى الطلب") read as a property rather than as two code paths that happen to
 *     match today.
 *  3. NO STORE RESOLVES. `getCurrentStore()` can answer null for a staff member who owns no store,
 *     and every one of these four screens reads the store. An action that leads to a refusal or a
 *     crash is not an action.
 *  4. THE ROUTE IS NOT REGISTERED, or needs parameters. The same two checks
 *     `MerchantPageDirectory` makes, for the same reason: `route()` on an unregistered or
 *     parameterised name throws inside the view, and a palette is a bad place to discover that.
 */
class MerchantQuickActions
{
    /**
     * The approved four. `page` is the navigation entry whose availability gates the action;
     * `permission` is what the target itself enforces.
     */
    private const ACTIONS = [
        [
            'name' => 'create-product',
            'title' => 'Create product',
            'route' => 'product.create',
            'page' => 'product',
            'permission' => 'Create Product',
            'operator' => false,
            'glyph' => '+',
        ],
        [
            'name' => 'create-order',
            'title' => 'Create manual order',
            'route' => 'order.create',
            'page' => 'order',
            'permission' => 'Manage Order',
            'operator' => true,
            'glyph' => '+',
        ],
        [
            'name' => 'create-customer',
            'title' => 'Add customer',
            'route' => 'customer.create',
            // `customers`, PLURAL -- the navigation entry is named that (CompanyMenuListener:408)
            // while its route is `customer.index`. Keying this on the singular would have withheld
            // the action silently and for ever, with nothing to show it had ever been offered.
            'page' => 'customers',
            'permission' => 'Manage Customer',
            'operator' => true,
            'glyph' => '+',
        ],
        [
            'name' => 'create-coupon',
            'title' => 'Create coupon',
            'route' => 'coupon.create',
            'page' => 'coupon',
            'permission' => 'Create Coupon',
            'operator' => false,
            'glyph' => '+',
        ],
    ];

    /**
     * The rows this principal may be offered, keyed by name.
     *
     * @return array<string, array{name:string,title:string,href:string,glyph:string}>
     */
    public static function available(): array
    {
        $user = auth()->user();

        // The same instanceof control MerchantPageDirectory carries, and for the same measured
        // reason: `Customer` has no `isAbleTo()`, so a Customer on the default guard would fatal.
        if (! $user instanceof \App\Models\User) {
            return [];
        }

        /*
         * TWO STORE QUESTIONS, AND THE SECOND ONE IS F157's TRAP.
         *
         * `empty(getCurrentStore())` catches the staff member who owns no store -- the ordinary
         * state of a newly added employee -- for whom the helper answers NULL and every one of
         * these four screens would refuse or crash.
         *
         * It does NOT catch the operator, and that is the half worth the comment: for a
         * `super admin` with no `current_store` the helper returns the **hardcoded literal 1**
         * (`helper.php:636-637`), and `getStoreById(1)` normally RESOLVES. So a null-store guard
         * passes and the operator would be offered four create-actions pointing at **store 1** --
         * a store they never selected. That is exactly the defect F157 found on the coupon list,
         * where the operator was served store 1's coupon book for the same reason, and the owner's
         * decision there forbids by name "اختياراً افتراضياً يمكن أن يكشف متجرًا غير مقصود".
         *
         * So the operator must have chosen a store explicitly. Read from the column rather than
         * through the helper, because the helper is what substitutes the literal.
         */
        if (empty(getCurrentStore())) {
            return [];
        }

        if (\App\Policies\UserPolicy::isOperator($user) && empty($user->current_store)) {
            return [];
        }

        $pages = MerchantPageDirectory::available();
        $offered = [];

        foreach (self::ACTIONS as $action) {
            if (! array_key_exists($action['page'], $pages)) {
                continue;
            }

            if (! self::principalMayUse($user, $action)) {
                continue;
            }

            if (! RouteFacade::has($action['route'])) {
                continue;
            }

            if (RouteFacade::getRoutes()->getByName($action['route'])?->parameterNames()) {
                continue;
            }

            $offered[$action['name']] = [
                'name' => $action['name'],
                'title' => __($action['title']),
                'href' => route($action['route']),
                'glyph' => $action['glyph'],
            ];
        }

        return $offered;
    }

    /**
     * The render-side half of the owner's condition, for ONE action.
     *
     * `isAbleTo()` alone refuses the platform operator — Laratrust has no super-admin bypass and
     * `permissions_as_gates` is false — so an operator reaches a target only where that target
     * says so. `$action['operator']` is that statement, and it is checked FIRST for the same
     * reason every operator check in this codebase is: asking `isAbleTo()` first would answer
     * "no" for a principal the target will admit.
     */
    private static function principalMayUse(\App\Models\User $user, array $action): bool
    {
        if (! empty($action['operator']) && \App\Policies\UserPolicy::isOperator($user)) {
            return true;
        }

        return $user->isAbleTo($action['permission']);
    }

    /**
     * The declared rows, for the test that proves each one's `permission` is what its target
     * actually enforces. Not used by any request path.
     *
     * @return array<int, array<string, string>>
     */
    public static function declared(): array
    {
        return self::ACTIONS;
    }
}
