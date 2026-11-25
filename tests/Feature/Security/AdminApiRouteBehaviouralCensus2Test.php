<?php

namespace Tests\Feature\Security;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * BEHAVIOURAL permission census of 31 `/api/admin/*` routes (group 2 of 4) — EVERY VERDICT DRIVEN.
 * =================================================================================================
 *
 * WHY THIS IS NOT A TOKEN CENSUS, AND WHY THE TOKEN CENSUS FAILED. An earlier pass over this same
 * surface grepped the controllers for refusal-shaped helper names and counted the hits. Three of
 * three spot-checks were FALSE POSITIVES, and the total moved every time the token list widened —
 * because these controllers spell the same decision at least twenty-two different ways
 * (`canFinance`, `refuseWithoutPermission`, `refuseUnlessMayAdministerStoreSettings`,
 * `callerMayActInStore`, `storePermissionDenied`, `orderPermissionDenied`, `storeAccessDenied`,
 * `resolveChangeableOrder`, `resolveOwnedStore`, …) and several of those names are TENANT checks
 * that say nothing about permissions. `resolveOwnedStore()` is the worst of them: it reads like
 * authorization, it is named like authorization, and it admits EVERY staff member of the merchant
 * whatever role they hold, because `User::creatorId()` resolves a staff row to its employer. A
 * handler whose only gate is `resolveOwnedStore()` is UNGATED IN FACT.
 *
 * So each row below is established by driving the route with a real Sanctum bearer token and
 * reading the ENVELOPE, and for the writes by reading the ROW afterwards.
 *
 * THREE PRINCIPALS, AND THE FIRST ONE IS THE QUESTION
 * ---------------------------------------------------
 *   `staff`    UNPERMISSIONED STAFF OF THE OWNING STORE — `makeStaff($merchant)` with
 *              `current_store` set and NO `grantPermission()` call anywhere. This is the principal
 *              the merchant hired and gave nothing to. If a route answers success to them, the
 *              route has no intra-tenant control.
 *   `owner`    the store's own owner, in PRODUCTION SHAPE — `RegisteredUserController` and
 *              `StoreController` hand every merchant the single GLOBAL `admin` role
 *              (`roles.store_id` NULL), so a merchant in production HOLDS permissions.
 *              `makeMerchant()` alone creates a user with no role at all, and four of these routes
 *              refuse that user (measured in
 *              `test_a_role_less_owner_is_refused_by_the_gates_with_no_owner_limb`) — a fixture
 *              artefact that would otherwise read as a lockout.
 *   `foreign`  a merchant of a DIFFERENT tenant, holding the same global role, so a refusal is
 *              provably TENANCY and not a missing permission.
 *
 * THE FOUR TRAPS THAT WOULD HAVE MADE THIS READ WRONG — all of them hit here
 * -------------------------------------------------------------------------
 *  1. `ApiResponser::error()` ANSWERS HTTP 200 (`app/Traits/ApiResponser.php:54`), and its THIRD
 *     argument is the status code, so `error([...], 401)` puts `401` in `$message` and still sends
 *     200. MEASURED on this surface: `DeliveryBoyNotificationController` writes
 *     `$this->error([...], 401)` and the wire answer is **HTTP 200** with `{"status":0,
 *     "message":401}`. A 200 is therefore NOT evidence of admission and `assertStatus(200)` proves
 *     nothing. `admitted()` below decides from the envelope, and for the writes from the row.
 *  2. THE ENVELOPE IS NOT ONE SHAPE. `status` is `1` (ApiResponser), `true` (`noti`), or the
 *     STRING `"success"` (`getStoreInfo`, the three brand/label toggles), against `0` and the
 *     string `"error"` for a refusal. A census keyed on `status === 1` would have read four
 *     admissions as refusals.
 *  3. SCOPED MODELS DEFEAT AN "IT IS GONE" ASSERTION. `Customer` and `OrderNote` DO filter, so a
 *     plain `Customer::find()` answers against whichever tenant happened to resolve. Every row
 *     read here is `DB::table()`.
 *  4. CACHED MODELS SERVE THE PRE-WRITE ROW. `ProductBrand` and `ProductLabel` are `Cachable`, and
 *     the per-principal reset below writes with `DB::table()->update()`, so each is followed by
 *     `flushCache()` or the handler's own Eloquent read would serve the previous principal's row
 *     and the next write would read as "no change".
 *
 * WHAT THE CENSUS FOUND, IN ONE SENTENCE: 19 of these 31 routes answer SUCCESS to a staff member
 * holding no permission at all, three of those 19 LAND A WRITE, and one of them serves the order
 * document — customer name, e-mail, telephone, billing and delivery address, `payment_status` and
 * `final_price` — on a route whose sibling `vieworder` refuses the same principal 403.
 *
 * EVERY `UNGATED` ROW IS A MEASUREMENT OF PRESENT BEHAVIOUR, PINNED SO IT CANNOT DRIFT SILENTLY,
 * AND NOT AN ENDORSEMENT. The suite must stay green (`.github/known-test-outcomes.json` asserts
 * the outcome SET in both directions, so a deliberately failing test would break the gate), so
 * each one asserts what the route does TODAY. When a gate is added, THIS TEST WILL FAIL, and that
 * failure is the signal to move the row from `UNGATED` to `GATED` — which is the whole point of
 * recording the census as a test rather than as a document.
 *
 * SCOPE: this file only measures. It changes no handler, and the fixes it argues for are reported,
 * not applied.
 */
class AdminApiRouteBehaviouralCensus2Test extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * `TENANT_N_A` marks the three routes with NO tenant dimension at all: `getStatesByCountry`
     * and `getCitiesByState` serve the global geography tables and resolve no store, and
     * `notification` takes no id — it reads `users.current_store`, so a foreign merchant simply
     * gets their OWN store's bell and there is nothing to cross. Saying "refused" for those would
     * be a verdict the request shape cannot support.
     */
    private const TENANT_N_A = 'no tenant dimension';

    private const TENANT_REFUSED = 'refused';

    /**
     * THE CENSUS. Each row is `[route, verdict, staff HTTP code, staff admitted?, owner admitted?,
     * tenancy, note]`, and `test_route_verdict` re-measures every field of it.
     */
    public static function census(): array
    {
        $rows = [
            // ---------------------------------------------------------------------------------
            // GATED — an unpermissioned staff member of the owning store is refused.
            // ---------------------------------------------------------------------------------
            ['get-stripe-payment', 'GATED', 403, false, true, self::TENANT_REFUSED,
                'StoreSettingsAccess::permits() — operator, else tenant root, else `Manage Setting`.'],
            ['getemailsetting', 'GATED', 403, false, true, self::TENANT_REFUSED,
                'Same gate. The SMTP password is behind it.'],
            ['orderlist', 'GATED', 403, false, true, self::TENANT_REFUSED,
                'refuseWithoutPermission([Manage Order, Show Order]).'],
            ['productlist', 'GATED', 403, false, true, self::TENANT_REFUSED,
                'refuseWithoutPermission([Manage Products]) — serves `cost_price`.'],
            ['variantlist', 'GATED', 403, false, true, self::TENANT_REFUSED,
                'refuseWithoutPermission([Manage Products]).'],
            ['staticsdata', 'GATED', 403, false, true, self::TENANT_REFUSED,
                'refuseWithoutPermission([Manage Store Analytics, Manage Dashboard]).'],
            ['vieworder', 'GATED', 403, false, true, self::TENANT_REFUSED,
                'Owner limb + MerchantPermissions([Show Order, Manage Order]); 403 via orderPermissionDenied().'],
            ['wallet/transactions', 'GATED', 403, false, true, self::TENANT_REFUSED,
                'canFinance(View Finance Dashboard) — and then an OWNER-ONLY ownership test no staff can pass.'],
            ['AddCustomer', 'GATED', 403, false, true, self::TENANT_REFUSED,
                'MerchantPermissions([Manage Customer]). The write is a CREDENTIAL, so this one matters.'],

            // The two driver routes. GATED, but by a GUARD MISMATCH rather than by a permission:
            // `auth('deliveryboy')` is null for every `User` token, so the store's own OWNER is
            // refused too. Not a merchant surface at all — see the dedicated test.
            ['notifications', 'GATED', 200, false, false, self::TENANT_N_A,
                'auth(deliveryboy) only. Refuses staff AND the owner. HTTP 200 carrying {status:0,message:401}.'],
            ['notifications/mark-all-as-read', 'GATED', 200, false, false, self::TENANT_N_A,
                'Same. A WRITE on a GET verb, and no write lands for any User token.'],

            // ---------------------------------------------------------------------------------
            // GATED, ROW-LEVEL — the request is admitted and the ROWS are filtered.
            // ---------------------------------------------------------------------------------
            ['notification', 'GATED_ROW_LEVEL', 200, true, true, self::TENANT_N_A,
                'readableNotificationTypes(): admitted with ZERO rows. No leak, but the refusal is invisible.'],

            // ---------------------------------------------------------------------------------
            // UNGATED — success for a staff member holding nothing. `resolveOwnedStore()` is the
            // only control, and it is a TENANT control.
            // ---------------------------------------------------------------------------------
            ['order-detail', 'UNGATED', 200, true, true, self::TENANT_REFUSED,
                'resolveChangeableOrder() = tenancy only. Serves customer PII + money. Sibling `vieworder` refuses.'],
            ['getStoreInfo', 'UNGATED', 200, true, true, self::TENANT_REFUSED,
                "Serves the store OWNER's name and login e-mail, their mobile, and the store address."],
            ['getloyalitysetting', 'UNGATED', 200, true, true, self::TENANT_REFUSED,
                'Reads two names StoreSettingsAccess calls RESTRICTED; its writer LoyalitySetting IS gated.'],
            ['view-used-coupon', 'UNGATED', 200, true, true, self::TENANT_REFUSED,
                'Coupon redemptions: which customer redeemed what, for how much, on which order.'],
            ['viewproduct', 'UNGATED', 200, true, true, self::TENANT_REFUSED,
                'Admitted; `cost_price` alone is unset. price, sale_price and product_stock are served.'],
            ['reviewlist', 'UNGATED', 200, true, true, self::TENANT_REFUSED, 'Testimonials for a product.'],
            ['shippinglist', 'UNGATED', 200, true, true, self::TENANT_REFUSED,
                'Sibling of orderlist/productlist in the same block; those got the gate, this did not.'],
            ['taxlist', 'UNGATED', 200, true, true, self::TENANT_REFUSED, 'Same block, same omission.'],
            ['productdropdown', 'UNGATED', 200, true, true, self::TENANT_REFUSED, 'Category names.'],
            ['getActivePaymentMethods', 'UNGATED', 200, true, true, self::TENANT_REFUSED,
                'Which gateways the store has switched on.'],
            ['getAvailableLanguages', 'UNGATED', 200, true, true, self::TENANT_REFUSED,
                'Declared TWICE in routes/api.php (:254 and :322); the later wins, same middleware.'],
            ['getCurrenciesApi', 'UNGATED', 200, true, true, self::TENANT_REFUSED, 'Store currencies and rates.'],
            ['getCurrentLanguageAndCurrency', 'UNGATED', 200, true, true, self::TENANT_REFUSED, 'Current pair.'],
            ['indexAllPlan', 'UNGATED', 200, true, true, self::TENANT_REFUSED,
                "The merchant's SaaS plan, its limits and its expiry."],
            ['getStatesByCountry', 'UNGATED', 200, true, true, self::TENANT_N_A,
                'Global geography table. No store, no tenant, nothing sensitive — the benign end.'],
            ['getCitiesByState', 'UNGATED', 200, true, true, self::TENANT_N_A, 'Same.'],

            // UNGATED **WRITES**. All three land a real column change for unpermissioned staff.
            ['product-brand-status', 'UNGATED', 200, true, true, self::TENANT_REFUSED,
                'WRITE: product_brands.status. The column the storefront filters on.'],
            ['product-brand-popular', 'UNGATED', 200, true, true, self::TENANT_REFUSED,
                'WRITE: product_brands.is_popular.'],
            ['product-label-status', 'UNGATED', 200, true, true, self::TENANT_REFUSED,
                'WRITE: product_labels.status.'],
        ];

        $named = [];
        foreach ($rows as $row) {
            $named[$row[0]] = $row;
        }

        return $named;
    }

    /** The names a production merchant's global `admin` role carries in these fixtures. */
    private const ALL_NAMES = [
        'Manage Setting', 'Manage Order', 'Show Order', 'Manage Products',
        'Manage Store Analytics', 'Manage Dashboard', 'View Finance Dashboard',
        'Manage Customer', 'Manage Coupon', 'Edit Product Brand', 'Edit Product Label',
    ];

    /** @var array<string, mixed> */
    private array $w = [];

    // =============================================================================================
    // The world
    // =============================================================================================

    /**
     * Two tenants, and in tenant A one row of everything these 31 handlers look for.
     *
     * FIXTURES EXIST SO THAT A REFUSAL CANNOT BE MISTAKEN FOR AN EMPTY TABLE. Several of these
     * handlers answer `$this->error(['message' => '… not found.'])` — an HTTP 200 with
     * `{status: 0}` — when their table has no row, which is byte-identical in shape to a refusal.
     * Without the rows, `view-used-coupon`, `reviewlist`, `variantlist` and `shippinglist` would
     * all have read as REFUSALS for every principal, and the census would have reported four
     * routes gated that are not.
     */
    private function world(): array
    {
        if ($this->w !== []) {
            return $this->w;
        }

        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        // `getStoreInfo` prints the owner's mobile; an empty column would hide the disclosure.
        $merchantA->mobile = '+963900111222';
        $merchantA->save();

        $staffA = $this->makeStaff($merchantA);
        $staffA->current_store = $storeA->id;
        $staffA->save();

        $category = \App\Models\Category::forceCreate([
            'name' => 'Cat A', 'slug' => 'cat-a-' . $this->uniqueFixtureRef(), 'parent_id' => 0,
            'status' => 1, 'store_id' => $storeA->id,
        ]);

        $product = \App\Models\Product::forceCreate([
            'name' => 'Widget A', 'slug' => 'widget-a-' . $this->uniqueFixtureRef(),
            'category_id' => $category->id, 'track_stock' => 0,
            'price' => 100, 'sale_price' => 90, 'product_stock' => 5,
            'cost_price' => 42, 'status' => 1, 'variant_product' => 0,
            'store_id' => $storeA->id, 'created_by' => $merchantA->id,
        ]);

        \App\Models\ProductVariant::forceCreate([
            'product_id' => $product->id, 'variant' => 'Red',
            'sku' => 'SKU-' . $this->uniqueFixtureRef(),
            'price' => 100, 'stock' => 3, 'store_id' => $storeA->id,
        ]);

        $brand = \App\Models\ProductBrand::forceCreate([
            'name' => 'Brand A', 'slug' => 'brand-a-' . $this->uniqueFixtureRef(), 'logo' => null,
            'status' => 1, 'is_popular' => 0,
            'store_id' => $storeA->id, 'created_by' => $merchantA->id,
        ]);

        $label = \App\Models\ProductLabel::forceCreate([
            'name' => 'Label A', 'slug' => 'label-a-' . $this->uniqueFixtureRef(), 'status' => 1,
            'store_id' => $storeA->id, 'created_by' => $merchantA->id,
        ]);

        $coupon = \App\Models\Coupon::forceCreate([
            'coupon_name' => 'Ten Off',
            'coupon_code' => 'TEN' . strtoupper(substr($this->uniqueFixtureRef(), -5)),
            'coupon_type' => 'flat', 'discount_amount' => 10, 'status' => 1,
            'store_id' => $storeA->id,
        ]);

        // `user_coupons.user_id` points at `customers`, NOT at `users`: the historical MySQL-only
        // migration 2024_05_29_102438 repointed it and 2026_10_12_000104 reconciles the other
        // drivers. A `users` id here fails the foreign key on SQLite.
        $customerId = \DB::table('customers')->insertGetId([
            'first_name' => 'Nadia', 'last_name' => 'Haddad',
            'email' => 'nadia-' . $this->uniqueFixtureRef() . '@example.test',
            'mobile' => '+963911111111', 'type' => 'customer', 'status' => 1,
            'store_id' => $storeA->id, 'created_by' => $merchantA->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        \DB::table('user_coupons')->insert([
            'user_id' => $customerId, 'coupon_id' => $coupon->id, 'amount' => 10,
            'order_id' => 0, 'date_used' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        \App\Models\Testimonial::forceCreate([
            'category_id' => $category->id, 'product_id' => $product->id, 'rating_no' => 5,
            'title' => 'Great', 'description' => 'Good', 'username' => 'shopper', 'status' => 1,
            'store_id' => $storeA->id,
        ]);

        \App\Models\Shipping::forceCreate([
            'name' => 'Standard', 'description' => 'd',
            'slug' => 'standard-' . $this->uniqueFixtureRef(), 'store_id' => $storeA->id,
        ]);

        \App\Models\Tax::forceCreate(['name' => 'VAT', 'store_id' => $storeA->id]);

        \DB::table('store_notifications')->insert([
            'store_id' => $storeA->id, 'user_id' => null, 'type' => 'order_created',
            'title' => 'New order', 'message' => 'An order arrived',
            'is_read' => 0, 'push_sent' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $country = \DB::table('countries')->insertGetId([
            'name' => 'Syria', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $state = \DB::table('states')->insertGetId([
            'name' => 'Damascus', 'country_id' => $country,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \DB::table('cities')->insert([
            'name' => 'Damascus City', 'country_id' => $country, 'state_id' => $state,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Raw inserts, not Setting::create(): the model's `saving` hook runs HostClaims and
        // StoredPathPolicy, neither of which is what this census is measuring.
        foreach ([
            'MAIL_DRIVER' => 'smtp', 'MAIL_HOST' => 'smtp.example.test',
            'MAIL_PORT' => '587', 'MAIL_USERNAME' => 'mailer@example.test',
            'MAIL_PASSWORD' => 'SMTP-SECRET-VALUE', 'MAIL_ENCRYPTION' => 'tls',
            'MAIL_FROM_NAME' => 'Store A', 'MAIL_FROM_ADDRESS' => 'no-reply@example.test',
            'stripe_secret' => 'sk_live_SECRET_VALUE', 'publishable_key' => 'pk_live_PUB',
            'is_stripe_enabled' => 'on', 'CURRENCY' => '$', 'CURRENCY_NAME' => 'USD',
            'loyality_program_enabled' => 'on', 'reward_point' => '7',
            'is_cod_enabled' => 'on', 'mobile' => '+963900111222',
        ] as $name => $value) {
            \DB::table('settings')->insert([
                'name' => $name, 'value' => $value, 'store_id' => $storeA->id,
                'created_by' => $merchantA->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $walletId = \DB::table('store_wallets')->insertGetId([
            'store_id' => $storeA->id, 'currency_code' => 'USD',
            'available_balance' => 500, 'pending_balance' => 0,
            'withdrawal_pending_balance' => 0, 'total_income' => 500, 'total_withdrawn' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        \DB::table('store_wallet_transactions')->insert([
            'store_id' => $storeA->id, 'wallet_id' => $walletId, 'currency_code' => 'USD',
            'type' => 'order_income', 'direction' => 'credit', 'amount' => 500,
            'balance_before' => 0, 'balance_after' => 500,
            'description' => 'Order income', 'status' => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = \App\Models\Order::create([
            'product_order_id' => 'ORD-' . $this->uniqueFixtureRef(),
            'order_date' => now(), 'customer_id' => $customerId, 'is_guest' => 0,
            'product_json' => '[]', 'product_price' => 100, 'final_price' => 123.45,
            'payment_type' => 'cod', 'payment_status' => 'Paid', 'delivered_status' => 0,
            'store_id' => $storeA->id,
        ]);

        // `Order::order_detail()` reads PII from `order_billing_details` for every non-POS order
        // and ONLY falls back to `customers` for `payment_type == 'POS'`. Without this row the
        // name, e-mail and telephone it serves are empty strings and the disclosure this census
        // reports would have been invisible — the fixture, not the gate, would have "protected"
        // the data.
        \DB::table('order_billing_details')->insert([
            'order_id' => $order->id, 'product_order_id' => $order->product_order_id,
            'first_name' => 'Nadia', 'last_name' => 'Haddad',
            'email' => 'nadia.buyer@example.test', 'telephone' => '+963911111111',
            'address' => '14 Straight Street', 'postcode' => '0100',
            'delivery_address' => '14 Straight Street', 'delivery_postcode' => '0100',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $boyId = \DB::table('delivery_boys')->insertGetId([
            'name' => 'Driver A',
            'email' => 'driver-' . $this->uniqueFixtureRef() . '@example.test',
            'type' => 'deliveryboy', 'password' => \Hash::make('Passw0rd!1'),
            'contact' => '+963922222222', 'created_by' => $merchantA->id,
            'store_id' => $storeA->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $driverNotificationId = \DB::table('delivery_boy_notifications')->insertGetId([
            'delivery_boy_id' => $boyId, 'order_id' => $order->id, 'type' => 'order_assigned',
            'title' => 'Parcel', 'message' => 'Collect', 'is_read' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $orderId = $order->id;

        return $this->w = compact(
            'merchantA', 'storeA', 'staffA', 'merchantB', 'storeB',
            'category', 'product', 'brand', 'label', 'coupon', 'customerId',
            'country', 'state', 'orderId', 'walletId', 'boyId', 'driverNotificationId'
        );
    }

    /**
     * The PRODUCTION shape of a merchant, which `makeMerchant()` is not.
     *
     * Registration hands every merchant the single GLOBAL `admin` role (`roles.store_id` NULL),
     * and `MerchantPermissions::names()` keeps a NULL-store arm precisely so that role belongs to
     * them in every store. The role is SHARED, so the permissions are attached ONCE and then only
     * assigned — `grantMerchantGlobalRole()` re-grants and trips `permission_role`'s unique key
     * the second time it is called in one test.
     */
    private function asProductionMerchant(User $merchant): User
    {
        $role = \App\Models\Role::where('name', 'admin')->whereNull('store_id')->first()
            ?: $this->makeGlobalAdminRole();

        foreach (self::ALL_NAMES as $name) {
            $permission = \App\Models\Permission::firstOrCreate(
                ['name' => $name],
                ['display_name' => $name]
            );

            $alreadyAttached = \DB::table('permission_role')
                ->where('permission_id', $permission->id)
                ->where('role_id', $role->id)
                ->exists();

            if (! $alreadyAttached) {
                $role->givePermission($permission);
            }
        }

        if (! $merchant->hasRole($role->name)) {
            $merchant->addRole($role);
        }

        $merchant->flushCache();

        return User::find($merchant->id);
    }

    // =============================================================================================
    // Driving, and reading the answer
    // =============================================================================================

    /** The query string / body each route needs to reach its GATE rather than its VALIDATOR. */
    private function requestFor(string $key, array $w, Store $target): array
    {
        $s = $target->id;
        $order = $w['orderId'];

        $table = [
            'get-stripe-payment' => ['GET', 'get-stripe-payment?store_id=' . $s],
            'getActivePaymentMethods' => ['GET', 'getActivePaymentMethods?store_id=' . $s],
            'getAvailableLanguages' => ['GET', 'getAvailableLanguages?store_id=' . $s],
            'getCitiesByState' => ['GET', 'getCitiesByState?country_id=' . $w['country']
                . '&state_id=' . $w['state']],
            'getCurrenciesApi' => ['GET', 'getCurrenciesApi?store_id=' . $s],
            'getCurrentLanguageAndCurrency' => ['GET', 'getCurrentLanguageAndCurrency?store_id=' . $s],
            'getStatesByCountry' => ['GET', 'getStatesByCountry?country_id=' . $w['country']],
            'getStoreInfo' => ['GET', 'getStoreInfo?store_id=' . $s],
            'getemailsetting' => ['GET', 'getemailsetting?store_id=' . $s],
            'getloyalitysetting' => ['GET', 'getloyalitysetting?store_id=' . $s],
            'indexAllPlan' => ['GET', 'indexAllPlan?store_id=' . $s],
            'notification' => ['GET', 'notification'],
            'notifications' => ['GET', 'notifications'],
            'notifications/mark-all-as-read' => ['GET', 'notifications/mark-all-as-read'],
            'order-detail' => ['GET', 'order-detail?order_id=' . $order],
            'orderlist' => ['GET', 'orderlist?store_id=' . $s],
            'product-brand-popular' => ['GET', 'product-brand-popular?store_id=' . $s
                . '&productbrand_id=' . $w['brand']->id . '&is_popular=1'],
            'product-brand-status' => ['GET', 'product-brand-status?store_id=' . $s
                . '&productbrand_id=' . $w['brand']->id . '&status=0'],
            'product-label-status' => ['GET', 'product-label-status?store_id=' . $s
                . '&lable_id=' . $w['label']->id . '&status=0'],
            'productdropdown' => ['GET', 'productdropdown?store_id=' . $s],
            'productlist' => ['GET', 'productlist?store_id=' . $s],
            'reviewlist' => ['GET', 'reviewlist?store_id=' . $s . '&product_id=' . $w['product']->id],
            'shippinglist' => ['GET', 'shippinglist?store_id=' . $s],
            'staticsdata' => ['GET', 'staticsdata?store_id=' . $s],
            'taxlist' => ['GET', 'taxlist?store_id=' . $s],
            'variantlist' => ['GET', 'variantlist?store_id=' . $s],
            'view-used-coupon' => ['GET', 'view-used-coupon?store_id=' . $s
                . '&coupon_id=' . $w['coupon']->id],
            'vieworder' => ['GET', 'vieworder?store_id=' . $s . '&order_id=' . $order],
            'viewproduct' => ['GET', 'viewproduct?store_id=' . $s . '&product_id=' . $w['product']->id],
            'wallet/transactions' => ['GET', 'wallet/transactions?store_id=' . $s],
            'AddCustomer' => ['POST', 'AddCustomer?store_id=' . $s, [
                'first_name' => 'Probe', 'last_name' => 'Customer',
                'email' => 'probe-' . $this->uniqueFixtureRef() . '@example.test',
                'mobile' => '+963900000000', 'password' => 'Passw0rdProbe1', 'status' => 1,
            ]],
        ];

        $this->assertArrayHasKey($key, $table, "no request recipe for `{$key}`");

        return $table[$key] + [2 => []];
    }

    private function drive(User $principal, string $key, ?Store $target = null)
    {
        $w = $this->world();
        [$method, $path, $body] = $this->requestFor($key, $w, $target ?? $w['storeA']);

        $request = $this
            ->withHeader(
                'Authorization',
                'Bearer ' . $principal->createToken('census')->plainTextToken
            )
            ->withHeader('Accept', 'application/json');

        return $method === 'POST'
            ? $request->postJson('/api/admin/' . $path, $body)
            : $request->getJson('/api/admin/' . $path);
    }

    /**
     * ADMITTED, DECIDED FROM THE ENVELOPE AND NEVER FROM THE STATUS CODE.
     *
     * `ApiResponser::error()` sends HTTP 200 with `{status: 0}`, so a 200 says nothing; and the
     * success flag is spelled three ways across these 31 handlers — `1` (ApiResponser), `true`
     * (`noti`), and the string `"success"` (`getStoreInfo` and the three brand/label toggles) —
     * against `0` and the string `"error"` for a refusal. Anything this does not recognise as an
     * admission is reported as a refusal, which is the fail-closed direction for a census.
     */
    private function admitted($response): bool
    {
        if ($response->getStatusCode() >= 400) {
            return false;
        }

        $body = json_decode((string) $response->getContent(), true);

        if (! is_array($body) || ! array_key_exists('status', $body)) {
            return false;
        }

        return in_array($body['status'], [1, '1', true, 'success'], true);
    }

    private function raw(string $table, $id, string $column)
    {
        return \DB::table($table)->where('id', $id)->value($column);
    }

    /** Put every row a write could touch back to its known start, and flush the two caches. */
    private function resetWritableRows(): void
    {
        $w = $this->world();

        \DB::table('product_brands')->where('id', $w['brand']->id)
            ->update(['status' => 1, 'is_popular' => 0]);
        \DB::table('product_labels')->where('id', $w['label']->id)->update(['status' => 1]);
        \DB::table('delivery_boy_notifications')->where('id', $w['driverNotificationId'])
            ->update(['is_read' => 0]);

        // `ProductBrand` and `ProductLabel` are `Cachable`; a raw update leaves Eloquent reads
        // serving the pre-write row for the rest of the test under `CACHE_STORE=array`.
        (new \App\Models\ProductBrand)->flushCache();
        (new \App\Models\ProductLabel)->flushCache();
    }

    // =============================================================================================
    // THE CENSUS ITSELF — one data set per route
    // =============================================================================================

    /**
     * Every field of one census row, re-measured.
     *
     * A failure here means the route's behaviour has MOVED. If a gate was added, that is the good
     * direction and the row should be edited from `UNGATED` to `GATED`; if a gate was removed, it
     * is a regression. Either way the message names the route and prints the body.
     */
    #[DataProvider('census')]
    public function test_route_verdict(
        string $key,
        string $verdict,
        int $staffCode,
        bool $staffAdmitted,
        bool $ownerAdmitted,
        string $tenancy,
        string $note
    ): void {
        $w = $this->world();
        $this->resetWritableRows();

        // ---- 1. UNPERMISSIONED STAFF OF THE OWNING STORE. The principal that decides the verdict.
        $staff = User::find($w['staffA']->id);
        $this->assertSame(
            [],
            \App\Services\MerchantPermissions::names($staff, $w['storeA']->id),
            'premise: the staff principal must hold NOTHING, or this census measures the fixture'
        );

        $response = $this->drive($staff, $key);

        $this->assertSame($staffCode, $response->getStatusCode(),
            "api/admin/{$key}: HTTP code moved for unpermissioned staff. {$note} Body: "
            . substr((string) $response->getContent(), 0, 300));

        $this->assertSame($staffAdmitted, $this->admitted($response),
            "api/admin/{$key}: ADMISSION moved for unpermissioned staff (verdict on record: "
            . "{$verdict}). {$note} Body: " . substr((string) $response->getContent(), 0, 300));

        // ---- 2. POSITIVE CONTROL: the store's own owner, in production shape.
        $owner = $this->asProductionMerchant($w['merchantA']);
        $this->resetWritableRows();
        $ownerResponse = $this->drive($owner, $key);

        $this->assertSame($ownerAdmitted, $this->admitted($ownerResponse),
            "api/admin/{$key}: the store's OWN OWNER "
            . ($ownerAdmitted ? 'is now refused' : 'is now admitted') . '. ' . $note
            . ' Body: ' . substr((string) $ownerResponse->getContent(), 0, 300));

        if ($ownerAdmitted) {
            $this->assertNotSame(401, $ownerResponse->getStatusCode(),
                "api/admin/{$key} answered 401 to the owner — the shipped Flutter app force-clears "
                . 'its session on any 401 (lib/network/network.dart), so that signs them out.');
        }

        // ---- 3. TENANCY: a merchant of another tenant, holding the same global permissions.
        if ($tenancy === self::TENANT_REFUSED) {
            $foreign = $this->asProductionMerchant($w['merchantB']);
            $this->resetWritableRows();
            $foreignResponse = $this->drive($foreign, $key);

            $this->assertFalse($this->admitted($foreignResponse),
                "api/admin/{$key}: A MERCHANT OF ANOTHER TENANT WAS ADMITTED to store A. This is a "
                . 'cross-tenant read or write, not a permission question. Body: '
                . substr((string) $foreignResponse->getContent(), 0, 300));

            $this->assertNotSame(401, $foreignResponse->getStatusCode(),
                "api/admin/{$key} answered 401 to a tenancy refusal, which logs the merchant out "
                . 'of the shipped app.');
        }
    }

    /** The census covers exactly the 31 routes of this group and nothing else. */
    public function test_the_census_covers_thirty_one_routes(): void
    {
        $this->assertCount(31, self::census());
    }

    /**
     * THE HEADLINE, as one assertion: 19 of the 31 answer success to a staff member with nothing.
     *
     * Recorded as a COUNT as well as per route so that a change in the overall posture cannot hide
     * inside thirty-one green rows — moving one route from `UNGATED` to `GATED` has to move this
     * number too, which makes the edit deliberate.
     */
    public function test_the_ungated_total_is_nineteen(): void
    {
        $verdicts = array_count_values(array_map(static fn ($row) => $row[1], self::census()));

        $this->assertSame(19, $verdicts['UNGATED'] ?? 0, 'the UNGATED total moved');
        $this->assertSame(11, $verdicts['GATED'] ?? 0, 'the GATED total moved');
        $this->assertSame(1, $verdicts['GATED_ROW_LEVEL'] ?? 0, 'the row-level total moved');
    }

    // =============================================================================================
    // What the UNGATED admissions actually hand over — the rows that matter
    // =============================================================================================

    /**
     * RANK 1 — `order-detail` SERVES CUSTOMER PII AND ORDER MONEY TO UNPERMISSIONED STAFF.
     *
     * MEASUREMENT AWAITING A FIX. The only gate is `resolveChangeableOrder()`
     * (`Api/DashboardController`), which admits the assigned driver, the operator, or ANY actor
     * whose tenant owns the order's store — `ownedStoreIds()`, with no permission anywhere in it.
     * The SIBLING `vieworder` serves the same document behind
     * `MerchantPermissions([Show Order, Manage Order])` and refuses this same principal 403, and
     * its own docblock records an asymmetry it was not asked to resolve. This is the other half.
     *
     * The fix is one line of the shape `vieworder` already carries; it is REPORTED, not applied —
     * this file may not edit a handler.
     */
    public function test_order_detail_hands_unpermissioned_staff_the_customers_name_email_and_phone(): void
    {
        $w = $this->world();
        $staff = User::find($w['staffA']->id);

        $response = $this->drive($staff, 'order-detail');
        $body = $response->json();

        $this->assertTrue($this->admitted($response), 'premise: the route admits this principal');

        $this->assertSame('Nadia Haddad', $body['data']['billing_informations']['name']);
        $this->assertSame('nadia.buyer@example.test', $body['data']['billing_informations']['email']);
        $this->assertSame('+963911111111', $body['data']['billing_informations']['phone']);
        $this->assertSame('14 Straight Street', $body['data']['billing_informations']['address']);
        $this->assertSame('Nadia Haddad', $body['data']['delivery_informations']['name']);

        // …and the money, which is what `orderlist` and `vieworder` are both gated for.
        $this->assertSame('Paid', $body['data']['payment_status']);
        $this->assertSame('123.45', $body['data']['final_price']);

        // THE ASYMMETRY, DRIVEN: the same principal, the same order, the gated sibling.
        $this->assertSame(403, $this->drive($staff, 'vieworder')->getStatusCode(),
            '`vieworder` is the gate `order-detail` is missing; if this stops being 403 the '
            . 'comparison in this test no longer says anything');
    }

    /**
     * RANK 2 — `getStoreInfo` hands unpermissioned staff the store OWNER's login e-mail.
     *
     * MEASUREMENT AWAITING A FIX. `users.email` is the address the password-reset flow sends to,
     * so this is the first half of an account-takeover chain rather than a directory listing. The
     * handler's own docblock records closing the CROSS-TENANT hole here
     * (NEW-ADMINAPI-UNOWNED-STORE-01) and says nothing about the intra-tenant one.
     */
    public function test_get_store_info_hands_unpermissioned_staff_the_owners_login_email(): void
    {
        $w = $this->world();
        $staff = User::find($w['staffA']->id);
        $owner = User::find($w['merchantA']->id);

        $body = $this->drive($staff, 'getStoreInfo')->json();

        $this->assertSame('success', $body['status']);
        $this->assertSame($owner->email, $body['data']['owner']['email']);
        $this->assertSame($owner->name, $body['data']['owner']['name']);
        $this->assertSame('+963900111222', $body['data']['owner']['mobile']);
    }

    /**
     * RANK 3 — THREE WRITES LAND FOR A PRINCIPAL HOLDING NOTHING, and the WEB twins are gated.
     *
     * MEASUREMENT AWAITING A FIX, and the most quotable row in the census: the web routes for
     * exactly these three toggles require `Edit Product Brand` / `Edit Product Label`
     * (`BrandLabelStatusAuthorizationTest`), while the API siblings require no permission at all.
     * Somebody closed one surface. `product_brands.status` is the column the storefront filters
     * on, so flipping it removes a brand from the shop.
     *
     * Rows are read with `DB::table()`: both models are `Cachable` and an Eloquent read here can
     * serve the pre-write row.
     */
    public function test_three_catalogue_toggles_land_a_write_for_unpermissioned_staff(): void
    {
        $w = $this->world();
        $staff = User::find($w['staffA']->id);

        foreach ([
            ['product-brand-status', 'product_brands', $w['brand']->id, 'status', 1, 0],
            ['product-brand-popular', 'product_brands', $w['brand']->id, 'is_popular', 0, 1],
            ['product-label-status', 'product_labels', $w['label']->id, 'status', 1, 0],
        ] as [$key, $table, $id, $column, $before, $after]) {
            $this->resetWritableRows();

            $this->assertSame($before, (int) $this->raw($table, $id, $column),
                "premise: {$table}.{$column} starts at {$before}, so a successful write is visible");

            $response = $this->drive($staff, $key);

            $this->assertSame('success', $response->json('status'),
                "api/admin/{$key} refused — if a gate was added, move its census row to GATED");

            $this->assertSame($after, (int) $this->raw($table, $id, $column),
                "api/admin/{$key} answered success but {$table}.{$column} did not move; the "
                . 'census would then be reporting a write that never happens');
        }
    }

    /**
     * RANK 4 — `view-used-coupon` hands unpermissioned staff the coupon redemption ledger.
     *
     * MEASUREMENT AWAITING A FIX. Every row names the CUSTOMER who redeemed
     * (`user_coupons.user_id`, which points at `customers` since the 2024_05_29 migration), the
     * amount discounted and the order. The sibling `couponlist` carries
     * `refuseWithoutPermission([Manage Coupon])`; this one carries only `resolveOwnedStore()`.
     */
    public function test_view_used_coupon_hands_unpermissioned_staff_the_redemption_ledger(): void
    {
        $w = $this->world();
        $staff = User::find($w['staffA']->id);

        $body = $this->drive($staff, 'view-used-coupon')->json();

        $this->assertSame(1, $body['status']);
        $this->assertSame($w['customerId'], $body['data'][0]['user_id']);
        $this->assertSame($w['coupon']->id, $body['data'][0]['coupon_id']);
        $this->assertEquals(10, $body['data'][0]['amount']);
    }

    /**
     * RANK 5 — `getloyalitysetting` READS two names its own WRITER refuses to let staff write.
     *
     * MEASUREMENT AWAITING A FIX, and the cleanest demonstration that the gate was applied per
     * HANDLER rather than per SUBJECT. `loyality_program_enabled` and `reward_point` are both in
     * `StoreSettingsAccess::RESTRICTED_EXACT` — that class's own comment calls the reward "what a
     * point is worth" — and `LoyalitySetting` (the POST) calls
     * `refuseUnlessMayAdministerStoreSettings()`. The GET next to it does not.
     */
    public function test_getloyalitysetting_serves_restricted_names_its_own_writer_protects(): void
    {
        $w = $this->world();
        $staff = User::find($w['staffA']->id);

        $body = $this->drive($staff, 'getloyalitysetting')->json();

        $this->assertSame(1, $body['status']);
        $this->assertSame('on', $body['data']['loyality_program_enabled']);
        $this->assertSame('7', $body['data']['reward_point']);

        // The two SIBLING settings readers in the same block DO refuse, which is what makes this
        // an omission rather than a policy: the same principal, the same table, the same store.
        $this->assertSame(403, $this->drive($staff, 'getemailsetting')->getStatusCode());
        $this->assertSame(403, $this->drive($staff, 'get-stripe-payment')->getStatusCode());
    }

    /**
     * `viewproduct` ADMITS and withholds ONE FIELD. Recorded because "partially gated" is a real
     * answer and collapsing it either way would be false.
     *
     * P1-SEC-46 unset `cost_price` for a caller without `Manage Products` rather than refusing the
     * request, deliberately, because the shipped Flutter app calls this route. So the margin is
     * protected and `price`, `sale_price` and `product_stock` are not. Both halves are pinned: if
     * `cost_price` ever comes back the first assertion fails.
     */
    public function test_viewproduct_admits_unpermissioned_staff_and_withholds_only_cost_price(): void
    {
        $w = $this->world();
        $staff = User::find($w['staffA']->id);

        $staffBody = $this->drive($staff, 'viewproduct')->json();

        $this->assertSame(1, $staffBody['status']);
        $this->assertArrayNotHasKey('cost_price', $staffBody['data']['Product'],
            'the store margin reached a principal holding no product permission');
        $this->assertSame('100.00', $staffBody['data']['Product']['price']);
        $this->assertSame('90.00', $staffBody['data']['Product']['sale_price']);
        $this->assertSame(5, $staffBody['data']['Product']['product_stock']);

        // The positive half: a permitted principal still gets the field.
        $ownerBody = $this->drive(
            $this->asProductionMerchant($w['merchantA']),
            'viewproduct'
        )->json();

        $this->assertArrayHasKey('cost_price', $ownerBody['data']['Product']);
        $this->assertSame('42.00', $ownerBody['data']['Product']['cost_price']);
    }

    // =============================================================================================
    // The gates that hold, and the two routes that are not a merchant surface
    // =============================================================================================

    /**
     * `notification` IS GATED, AND THE REFUSAL IS INVISIBLE — it is an empty list, not a 403.
     *
     * This is why the verdict is `GATED_ROW_LEVEL` and not `GATED`: `admitted()` answers TRUE for
     * the unpermissioned staff member, because `noti()` returns `{status: true}` with a paginator.
     * The control is real — `readableNotificationTypes()` resolves to `[]` and
     * `whereIn('type', [])` matches nothing — but a census that read the envelope alone would have
     * called this route ungated, and one that read the ROW COUNT alone (zero for everybody until
     * someone holds `Manage Order`) would have called it gated for the wrong reason. Both legs are
     * asserted.
     */
    public function test_the_notification_bell_admits_everyone_and_filters_the_rows_by_permission(): void
    {
        $w = $this->world();
        $staff = User::find($w['staffA']->id);

        $staffResponse = $this->drive($staff, 'notification');
        $this->assertTrue($this->admitted($staffResponse), 'the envelope says success …');
        $this->assertSame([], $staffResponse->json('data.data'), '… and carries no rows');
        $this->assertSame(0, $staffResponse->json('count'));

        // The differential. `order_created` maps to `Manage Order`
        // (`MerchantNotificationController::TYPE_PERMISSIONS`), so the owner sees the row the
        // staff member cannot — without which "zero rows" would prove nothing about the gate.
        $ownerResponse = $this->drive($this->asProductionMerchant($w['merchantA']), 'notification');
        $this->assertSame(1, $ownerResponse->json('count'));
        $this->assertSame('order_created', $ownerResponse->json('data.data.0.type'));
    }

    /**
     * THE TWO `notifications*` ROUTES ARE NOT A MERCHANT SURFACE AT ALL, and they refuse the
     * store's own OWNER.
     *
     * They sit in the `admin` prefix beside seventy merchant routes and read `auth('deliveryboy')`,
     * which is NULL for every `User` token: `CustomAuth` logs a token's owner into the guard that
     * matches the TOKENABLE CLASS, so a merchant's token lands in `web` and these answer
     * "Unauthorized". That is a guard mismatch, not a permission gate — which is why the census
     * records `ownerAdmitted = false` for these two and only these two.
     *
     * AND THE REFUSAL IS AN HTTP 200. `$this->error([...], 401)` passes 401 as the MESSAGE, not as
     * the code (`ApiResponser::error($data, $message, $code)`), so the wire answer is 200 with
     * `{"status":0,"message":401}`. A census reading the status code would have scored both of
     * these as ADMITTED. The write leg is checked too: `mark-all-as-read` is a state change on a
     * GET verb, and no `is_read` moves.
     */
    public function test_the_two_driver_notification_routes_refuse_every_user_token_including_the_owner(): void
    {
        $w = $this->world();

        foreach (['notifications', 'notifications/mark-all-as-read'] as $key) {
            foreach ([
                'unpermissioned staff' => User::find($w['staffA']->id),
                "the store's own owner" => $this->asProductionMerchant($w['merchantA']),
                'a foreign merchant' => $this->asProductionMerchant($w['merchantB']),
            ] as $label => $principal) {
                $this->resetWritableRows();

                $response = $this->drive($principal, $key);

                $this->assertSame(200, $response->getStatusCode(),
                    "api/admin/{$key}: the refusal is an HTTP 200 envelope, by construction — if "
                    . 'this is now a real 401 the shipped Flutter app force-logs-out on it');
                $this->assertFalse($this->admitted($response),
                    "api/admin/{$key} admitted {$label} through the `deliveryboy` guard");
                $this->assertSame(401, $response->json('message'),
                    "api/admin/{$key}: 401 is in the MESSAGE, not the status code");

                $this->assertSame(0, (int) $this->raw(
                    'delivery_boy_notifications',
                    $w['driverNotificationId'],
                    'is_read'
                ), "api/admin/{$key} moved a driver's notification for {$label}");
            }
        }
    }

    /**
     * `AddCustomer` IS GATED, AND THE WRITE IT GUARDS IS A CREDENTIAL — so the refusal is checked
     * against the TABLE and not only against the envelope.
     *
     * The create sets `password` from the request and `email_verified_at => now()`, so the account
     * signs in to the merchant's storefront immediately. `MerchantPermissions([Manage Customer])`
     * holds, and the permitted principal still gets 201 — the positive half, without which a
     * broken fixture would read as a gate.
     */
    public function test_add_customer_refuses_unpermissioned_staff_and_writes_no_credential(): void
    {
        $w = $this->world();
        $staff = User::find($w['staffA']->id);

        $before = (int) \DB::table('customers')->count();
        $response = $this->drive($staff, 'AddCustomer');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame($before, (int) \DB::table('customers')->count(),
            'a customer row — with a password and a verified e-mail — was written by a principal '
            . 'holding no Manage Customer');

        $permitted = $this->asProductionMerchant($w['merchantA']);
        $this->assertSame(201, $this->drive($permitted, 'AddCustomer')->getStatusCode());
        $this->assertSame($before + 1, (int) \DB::table('customers')->count());
    }

    /**
     * `wallet/transactions` IS CLOSED TO STAFF TWICE OVER, and the second lock is worth recording
     * because it is a FUNCTIONAL limit rather than a security one.
     *
     * `canFinance('View Finance Dashboard')` refuses an ungranted principal (operator-first via
     * the `finance` gate, because `isAbleTo()` alone refuses the operator). But the ownership test
     * underneath it is `Store::where('created_by', $adminUser->id)` — the OWNER's own id, never
     * `creatorId()` — so a staff member GRANTED the finance permission is still refused, with the
     * other refusal's message. No merchant can delegate the wallet ledger on this surface at all.
     */
    public function test_the_wallet_ledger_refuses_staff_even_when_the_finance_permission_is_granted(): void
    {
        $w = $this->world();

        $ungranted = User::find($w['staffA']->id);
        $this->assertSame(403, $this->drive($ungranted, 'wallet/transactions')->getStatusCode());

        $granted = $this->makeStaff($w['merchantA']);
        $granted->current_store = $w['storeA']->id;
        $granted->save();
        $this->grantPermission(User::find($granted->id), 'View Finance Dashboard');
        $granted = User::find($granted->id);

        $this->assertTrue(
            \Illuminate\Support\Facades\Gate::forUser($granted)
                ->allows('finance', 'View Finance Dashboard'),
            'premise: the finance gate now passes for this staff member'
        );

        $response = $this->drive($granted, 'wallet/transactions');

        $this->assertSame(403, $response->getStatusCode(),
            'if this is now 200 the ownership test has been widened to the tenant and the second '
            . 'lock described in this docblock is gone');
        $this->assertStringContainsString(
            'permission to access this store',
            (string) $response->json('data.message'),
            'the refusal now comes from the OWNERSHIP test, not the permission test'
        );

        // And the owner, who can: the positive control that proves the route works at all.
        $owner = $this->asProductionMerchant($w['merchantA']);
        $ownerResponse = $this->drive($owner, 'wallet/transactions');
        $this->assertSame(1, $ownerResponse->json('status'));
        $this->assertNotEmpty($ownerResponse->json('data.transactions'));
    }

    /**
     * THE FIXTURE TRAP THIS CENSUS HAD TO AVOID, pinned so nobody removes `asProductionMerchant()`.
     *
     * `refuseWithoutPermission()` has NO OWNER LIMB — it is `MerchantPermissions::allowsAny()` and
     * nothing else — so a merchant created by `makeMerchant()`, who holds no role at all, is
     * refused by four of these routes. `vieworder` carries an owner limb for the same family and
     * admits that same role-less owner, which is the asymmetry its own docblock records.
     *
     * In production every merchant holds the global `admin` role, so this is a FIXTURE artefact
     * and not a live lockout — but a positive control built on `makeMerchant()` alone would have
     * read four gates as broken, and a reviewer "fixing" that by weakening the gate would have
     * reopened the hole. The asymmetry is measured here rather than described.
     */
    public function test_a_role_less_owner_is_refused_by_the_gates_with_no_owner_limb(): void
    {
        $w = $this->world();

        // A fresh tenant whose owner holds NO role, which is what makeMerchant() produces.
        [$bareOwner, $bareStore] = $this->makeTenant();

        $this->assertSame(
            [],
            \App\Services\MerchantPermissions::names($bareOwner, $bareStore->id),
            'premise: makeMerchant() creates a merchant with no permissions'
        );
        $this->assertSame((int) $bareStore->created_by, (int) $bareOwner->id,
            'premise: this principal IS the store owner');
        $this->assertFalse(\App\Policies\UserPolicy::isOperator($bareOwner),
            'premise: and is not the platform operator, whom MerchantPermissions wildcards');

        foreach (['orderlist', 'productlist', 'variantlist', 'staticsdata'] as $key) {
            $this->assertSame(403, $this->drive($bareOwner, $key, $bareStore)->getStatusCode(),
                "`{$key}` uses refuseWithoutPermission(), which has no owner limb");
        }

        // The other shape, on the same principal and the same store: admitted.
        $order = \App\Models\Order::create([
            'product_order_id' => 'ORD-' . $this->uniqueFixtureRef(),
            'order_date' => now(), 'customer_id' => 0, 'is_guest' => 1,
            'product_json' => '[]', 'product_price' => 10, 'final_price' => 10,
            'payment_type' => 'cod', 'payment_status' => 'Unpaid', 'delivered_status' => 0,
            'store_id' => $bareStore->id,
        ]);

        $vieworder = $this
            ->withHeader(
                'Authorization',
                'Bearer ' . $bareOwner->createToken('census')->plainTextToken
            )
            ->withHeader('Accept', 'application/json')
            ->getJson('/api/admin/vieworder?store_id=' . $bareStore->id . '&order_id=' . $order->id);

        $this->assertTrue($this->admitted($vieworder),
            '`vieworder` carries an owner limb, so the SAME role-less owner is admitted there — '
            . 'that difference is why the positive control above must use the production shape');
    }
}
