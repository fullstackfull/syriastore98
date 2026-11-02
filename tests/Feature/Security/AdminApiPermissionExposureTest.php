<?php

namespace Tests\Feature\Security;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2-07-13 / WS K13 — the app must hide and refuse what the employee may not do.
 * =================================================================================================
 *
 * THE APP CANNOT GATE WHAT IT IS NEVER TOLD. Measured before any of this was written: the Flutter
 * app contains NO permission model at all — `permission`, `role`, `isAble`, `userType` and
 * `hasAccess` return nothing across `lib/`, the single hit being a translation string. The
 * structural reason is this endpoint: `api/admin/adminlogin` returned
 * `id, name, email, image, mobile, current_store, token` and nothing about what the employee may
 * do. So the app showed every screen to everyone and "the app currently trusts what the API
 * returns", exactly as this row's note says.
 *
 * AND THE API ENFORCED NOTHING EITHER, which is the half that matters more. `isAbleTo` appears
 * ZERO times in `Api\DashboardController`, and `routes/api.php` declares no permission middleware
 * anywhere — across 127 `api/admin/*` routes. A merchant-side token was a key to all of them.
 * Hiding a button in the app would have been theatre on its own; the server has to refuse.
 *
 * THE ADDITION IS ADDITIVE, which the owner required: no existing field is removed, renamed or
 * retyped, so the shipped app and Lynomia Chat keep parsing exactly what they parse today. A new
 * `permissions` key is ignored by a client that does not read it.
 *
 * PERMISSIONS ARE PER STORE, so they are reported against `current_store` and must change when the
 * merchant switches store — `test_the_permission_list_follows_a_store_switch` is that, and it is
 * the case a login-only payload could never express.
 *
 * REFUSAL IS 403, NEVER 401, and this is a hard constraint rather than a preference: the shipped
 * app forces a logout on any 401 (`lib/network/network.dart`, and CLAUDE.md records it), while it
 * treats 403 as "authorized but not permitted" and keeps the session —
 * "Deliberately NOT a forced logout: 403 means the session is valid and the action is not
 * allowed". Turning a permission denial into 401 would log merchants out of the app for pressing
 * a button they were never allowed to press.
 */
class AdminApiPermissionExposureTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('auth_token')->plainTextToken;
    }

    private function login(User $user): array
    {
        return $this->postJson('/api/admin/adminlogin', [
            'email' => $user->email,
            'password' => 'password',
        ])->json();
    }

    /** @return array{0: User, 1: Store} */
    private function merchantWithStore(): array
    {
        $merchant = $this->makeMerchant(['password' => bcrypt('password')]);
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();

        return [User::find($merchant->id), $store];
    }

    // ---------------------------------------------------------------------------------------
    //  THE PAYLOAD
    // ---------------------------------------------------------------------------------------

    /**
     * A RATCHET, NOT EVIDENCE FOR THIS CHANGE — the unfixed code also returned these eight
     * fields, so this test cannot fail if the fix is reverted. It is here to catch a LATER change
     * that removes or renames one of them, which is the only way this additive promise can break.
     *
     * PREMISE, and the whole row rests on it: the existing fields are untouched. A client that
     * reads this response today must keep working, so this names them explicitly rather than
     * trusting that nothing was disturbed.
     */
    public function test_the_login_payload_keeps_every_field_it_had(): void
    {
        [$merchant] = $this->merchantWithStore();

        $body = $this->login($merchant);

        foreach (['id', 'name', 'email', 'image', 'mobile', 'current_store', 'token', 'token_type']
                 as $field) {
            $this->assertArrayHasKey($field, $body['data'] ?? [],
                'adminlogin no longer returns `' . $field . '`, which the shipped Flutter app '
                . 'reads — this change must be additive');
        }

        $this->assertSame('Bearer', $body['data']['token_type']);
    }

    /** And it now also says what the principal may do. */
    public function test_the_login_payload_reports_the_permissions(): void
    {
        [$merchant] = $this->merchantWithStore();
        $this->grantPermission($merchant, 'Manage Order');

        $body = $this->login(User::find($merchant->id));

        $this->assertArrayHasKey('permissions', $body['data'] ?? [],
            'the app has no way to know what the employee may do');
        $this->assertContains('Manage Order', $body['data']['permissions']);
    }

    /**
     * A LIMITED EMPLOYEE GETS A LIMITED LIST. This is the case the feature exists for: staff who
     * may read orders and not products must not be handed a list that says otherwise.
     */
    public function test_a_limited_employee_gets_only_their_own_permissions(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        $staff = $this->makeStaff($merchant, ['password' => bcrypt('password')]);
        $staff->current_store = $store->id;
        $staff->save();
        $this->grantPermission($staff, 'Manage Order');
        $staff = User::find($staff->id);

        $permissions = $this->login($staff)['data']['permissions'];

        $this->assertContains('Manage Order', $permissions);
        $this->assertNotContains('Manage Products', $permissions,
            'a staff member was told they may manage products, which they may not');
    }

    /**
     * THE PLATFORM OPERATOR, because `isAbleTo()` alone REFUSES them — CLAUDE.md's standing rule.
     * A permission list built on `isAbleTo` would come back EMPTY for the one principal who may
     * do everything, and the app would then hide everything from them.
     */
    public function test_the_operator_is_not_reported_as_having_no_permissions(): void
    {
        $operator = $this->makePlatformAdmin(['password' => bcrypt('password')]);
        $store = $this->makeStore($operator, ['default_language' => 'en']);
        $operator->current_store = $store->id;
        $operator->save();

        $body = $this->login(User::find($operator->id));

        $this->assertNotSame([], $body['data']['permissions'],
            'the platform operator is reported as having no permissions, so the app would hide '
            . 'every screen from them — this is isAbleTo() refusing the operator');
    }

    /**
     * PERMISSIONS ARE PER STORE AND MUST FOLLOW A SWITCH. A list handed out once at login cannot
     * express this, which is why it is also reported on the dashboard the app calls after a
     * switch.
     */
    public function test_the_permission_list_follows_a_store_switch(): void
    {
        [$merchant, $first] = $this->merchantWithStore();
        $this->grantPermission($merchant, 'Manage Order');
        $merchant = User::find($merchant->id);

        $second = $this->makeStore($merchant, ['default_language' => 'en']);

        $before = $this->dashboardFor($merchant);

        $this->assertArrayHasKey('permissions', $before,
            'the dashboard does not report permissions, so a store switch cannot refresh them');
        $this->assertContains('Manage Order', $before['permissions']);

        $merchant->current_store = $second->id;
        $merchant->save();
        \Illuminate\Support\Facades\Cache::flush();

        $after = $this->dashboardFor(User::find($merchant->id));

        $this->assertArrayHasKey('permissions', $after);
        $this->assertNotSame((int) $first->id, (int) $second->id, 'premise: two distinct stores');

        // The employee-scoped role was created against the FIRST store, so after the switch the
        // per-store resolver must no longer report it. A merchant's own global role is unaffected,
        // which is why this drives the granted (store-scoped) role rather than the global one.
        $this->assertNotContains('Manage Order', $after['permissions'],
            'the permission list did not follow the store switch -- a role scoped to store '
            . $first->id . ' was still reported inside store ' . $second->id);
    }

    // ---------------------------------------------------------------------------------------
    //  ENFORCEMENT — the half that is not theatre
    // ---------------------------------------------------------------------------------------

    // ---------------------------------------------------------------------------------------
    //  PER STORE — the correction `allPermissions()` cannot make for itself
    // ---------------------------------------------------------------------------------------

    /**
     * THE NULL-STORE ARM. A merchant's role is the shared global `admin` role with store_id NULL
     * (RegisteredUserController:154, StoreController:379), handed to them for EVERY store they
     * create. Drop the null arm from the predicate and the store owner loses their own
     * permissions the moment they are read per store.
     */
    public function test_a_merchant_keeps_their_permissions_in_every_store(): void
    {
        [$merchant, $first] = $this->merchantWithStore();

        // The real shape: one global role, no store of its own.
        $global = $this->makeGlobalAdminRole();
        $p = \App\Models\Permission::firstOrCreate(
            ['name' => 'Manage Order'], ['display_name' => 'Manage Order']
        );
        $global->givePermission($p);
        $merchant->addRole($global);
        $merchant->flushCache();

        $second = $this->makeStore($merchant, ['default_language' => 'en']);

        foreach ([$first, $second] as $store) {
            $this->assertTrue(
                \App\Services\MerchantPermissions::allows(
                    User::find($merchant->id), 'Manage Order', $store->id
                ),
                'the store owner lost their own permissions in store ' . $store->id
            );
        }
    }

    /**
     * THE STORE ARM, which is the defect the row exists for. An employee whose role belongs to
     * store A must not be reported as holding it in store B.
     */
    public function test_an_employee_of_another_store_holds_nothing_here(): void
    {
        [$merchant, $storeA] = $this->merchantWithStore();
        $storeB = $this->makeStore($merchant, ['default_language' => 'en']);

        $staff = $this->makeStaff($merchant);
        $staff->current_store = $storeA->id;
        $staff->save();
        $this->grantPermission(User::find($staff->id), 'Manage Order');
        $staff = User::find($staff->id);

        $this->assertTrue(
            \App\Services\MerchantPermissions::allows($staff, 'Manage Order', $storeA->id),
            'premise: the employee really does hold it in their own store'
        );
        $this->assertFalse(
            \App\Services\MerchantPermissions::allows($staff, 'Manage Order', $storeB->id),
            'store A\'s employee was reported as holding store A\'s permission inside store B'
        );
    }

    /** A principal who is not a Laratrust subject at all. Customer::isAbleTo() is a FATAL. */
    public function test_a_non_user_principal_resolves_to_nothing_rather_than_fatally(): void
    {
        $this->assertSame([], \App\Services\MerchantPermissions::names(new \App\Models\Customer(), 1));
        $this->assertSame([], \App\Services\MerchantPermissions::names(null, 1));
        $this->assertFalse(\App\Services\MerchantPermissions::allows(null, 'Manage Order', 1));
        $this->assertFalse(\App\Services\MerchantPermissions::allowsAny(
            new \App\Models\DeliveryBoy(), ['Manage Order'], 1
        ));
    }

    // ---------------------------------------------------------------------------------------
    //  ENFORCEMENT — the half that is not theatre
    // ---------------------------------------------------------------------------------------

    /**
     * MONEY IS WITHHELD FROM A PRINCIPAL WHO MAY NOT SEE ORDERS. `recent_order[].final_price` and
     * `coupons[].discount_amount` are money and reached every authenticated caller.
     *
     * THE FIXTURE IS THE POINT. The first version of this test created no order and no coupon, so
     * it passed against the UNFIXED controller: there was no money in the response because there
     * was no money in the database. It is written from the other direction now --
     * `test_money_is_present_with_the_order_permission` drives the same fixture through a
     * permitted principal and asserts the money IS there, so the pair can only both pass if the
     * permission is what makes the difference.
     */
    public function test_money_is_withheld_without_the_order_permission(): void
    {
        [$merchant, $store] = $this->merchantWithStore();
        $this->seedMoney($store);

        $staff = $this->makeStaff($merchant, ['password' => bcrypt('password')]);
        $staff->current_store = $store->id;
        $staff->save();
        $this->grantPermission($staff, 'Manage Product Category');
        $staff = User::find($staff->id);

        $encoded = (string) json_encode($this->dashboardFor($staff));

        $this->assertStringNotContainsString('final_price', $encoded,
            'order money reached a staff member who may not read orders');
        $this->assertStringNotContainsString('discount_amount', $encoded,
            'coupon money reached a staff member who may not read coupons');
        $this->assertStringNotContainsString('cost_price', $encoded,
            'the store margin reached a staff member who may not read products');
    }

    /**
     * THE COUNTERPART, and the reason the pair is trustworthy: the SAME fixture through a
     * permitted principal must still carry the money. Without this, withholding everything
     * unconditionally would pass.
     */
    public function test_money_is_present_with_the_order_permission(): void
    {
        [$merchant, $store] = $this->merchantWithStore();
        $this->seedMoney($store);
        $this->grantPermission($merchant, 'Manage Order');
        $this->grantPermission(User::find($merchant->id), 'Manage Coupon');
        $this->grantPermission(User::find($merchant->id), 'Manage Products');
        $merchant = User::find($merchant->id);

        $data = $this->dashboardFor($merchant);
        $encoded = (string) json_encode($data);

        // THE KEY, NOT THE STRING. `final_price` is an APPENDED ATTRIBUTE on Product
        // (`App\Models\Product::$appends`), so it appears in the serialised TOP-PRODUCTS block
        // too -- a `assertStringContainsString('final_price')` is satisfied even if the
        // `recent_order` block were wrongly withheld. Independent review caught that; the string
        // assertions below are kept only as a second, weaker signal.
        $this->assertArrayHasKey('recent_order', $data,
            'premise: a permitted principal must still receive the order block itself');
        $this->assertNotSame([], $data['recent_order'],
            'premise: the order block must actually carry the seeded order');
        $this->assertArrayHasKey('final_price', $data['recent_order'][0]);

        $this->assertArrayHasKey('coupons', $data);
        $this->assertArrayHasKey('discount_amount', $data['coupons'][0] ?? []);

        // And the counterpart the withholding test lacked: `cost_price` must be PRESENT for a
        // principal who may read products. Without this, withholding the top-product block
        // unconditionally would have passed both tests.
        $this->assertStringContainsString('cost_price', $encoded,
            'a principal who may read products did not receive the product block');
    }

    /**
     * THE REAL CROSS-TENANT CASE. `test_an_employee_of_another_store_holds_nothing_here` drives
     * two stores of the SAME merchant, which is intra-tenant separation. This is the other
     * boundary: an employee of merchant N asking about merchant M's store.
     */
    public function test_another_tenants_employee_holds_nothing_in_this_store(): void
    {
        [, $storeM] = $this->merchantWithStore();

        $otherMerchant = $this->makeMerchant();
        $storeN = $this->makeStore($otherMerchant, ['default_language' => 'en']);

        $theirStaff = $this->makeStaff($otherMerchant);
        $theirStaff->current_store = $storeN->id;
        $theirStaff->save();
        $this->grantPermission(User::find($theirStaff->id), 'Manage Order');
        $theirStaff = User::find($theirStaff->id);

        $this->assertTrue(
            \App\Services\MerchantPermissions::allows($theirStaff, 'Manage Order', $storeN->id),
            'premise: they really hold it in their own tenant'
        );
        $this->assertFalse(
            \App\Services\MerchantPermissions::allows($theirStaff, 'Manage Order', $storeM->id),
            "another tenant's employee was reported as holding a permission in this store"
        );
    }

    /**
     * ANALYTICS IS BUSINESS DATA TOO. Without this gate the endpoint that withholds every other
     * figure still handed a zero-permission staff token 15 days of pageviews and unique-visitor
     * counts for the store.
     */
    public function test_store_traffic_is_withheld_without_the_analytics_permission(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        $staff = $this->makeStaff($merchant);
        $staff->current_store = $store->id;
        $staff->save();
        $this->grantPermission(User::find($staff->id), 'Manage Product Category');

        $data = $this->dashboardFor(User::find($staff->id));

        $this->assertArrayNotHasKey('visitorchart', $data,
            'store traffic analytics reached a staff member with no analytics permission');
        $this->assertArrayNotHasKey('orderchart', $data);
    }

    /** And present for a principal who may read it. */
    public function test_store_traffic_is_present_with_the_analytics_permission(): void
    {
        [$merchant] = $this->merchantWithStore();
        $this->grantPermission($merchant, 'Manage Store Analytics');

        $data = $this->dashboardFor(User::find($merchant->id));

        $this->assertArrayHasKey('visitorchart', $data);
    }

    /**
     * A VIEW-ONLY COUPON ROLE KEEPS THE COUPON BLOCK. `Show Coupon` is a real granted name, and
     * the first version of the gate accepted only `Manage Coupon` — so "view coupons, do not
     * edit" lost the block, while the order gate beside it already accepted its view-only twin.
     */
    public function test_show_coupon_is_enough_for_the_coupon_block(): void
    {
        [$merchant, $store] = $this->merchantWithStore();
        $this->seedMoney($store);
        $this->grantPermission($merchant, 'Show Coupon');

        $data = $this->dashboardFor(User::find($merchant->id));

        $this->assertArrayHasKey('coupons', $data,
            'a view-only coupon role lost the dashboard coupon block');
    }

    /**
     * A FIGURE IS OMITTED, NOT ZEROED. The app renders `?? "0"`, so a withheld count served as 0
     * would read as "this store has no orders" -- wrong information rather than withheld
     * information.
     */
    public function test_a_withheld_figure_is_absent_rather_than_zero(): void
    {
        [$merchant, $store] = $this->merchantWithStore();
        $this->seedMoney($store);

        $staff = $this->makeStaff($merchant);
        $staff->current_store = $store->id;
        $staff->save();
        $this->grantPermission(User::find($staff->id), 'Manage Product Category');
        $staff = User::find($staff->id);

        $data = $this->dashboardFor($staff);

        $this->assertArrayHasKey('permissions', $data, 'premise: the handler ran to completion');

        foreach (['order', 'sales', 'products'] as $figure) {
            $this->assertArrayNotHasKey($figure, $data,
                'the withheld figure `' . $figure . '` was served as a number, which the app '
                . 'renders as 0 -- a store with hidden orders would read as a store with none');
        }
    }

    /** The guard that was added for the qr-code block and not for the four queries after it. */
    public function test_a_dashboard_for_a_missing_store_does_not_500(): void
    {
        [$merchant] = $this->merchantWithStore();
        $merchant->current_store = 999999;
        $merchant->save();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenFor(User::find($merchant->id)))
            ->postJson('/api/admin/dashboard');

        $this->assertNotSame(500, $response->getStatusCode(),
            'a user whose current_store no longer exists still 500s on $store->id');
    }

    // ---------------------------------------------------------------------------------------
    //  ACCOUNT STATE — the merchant's own "disable this employee" switch
    // ---------------------------------------------------------------------------------------

    /**
     * THE API LOGIN DID NOT CHECK ANY OF THIS, which independent review surfaced while reading the
     * method this slice edits.
     *
     * The WEB login refuses an inactive account and refuses a login disabled on the user or on
     * their owner (`AuthenticatedSessionController:68-74`). `adminlogin` validated the email,
     * called `Auth::attempt` and issued a token — so a merchant who disabled an employee in the
     * web UI found the employee could still sign into the Flutter app, with a token that never
     * expires. This change would have made that worse by also handing them a permission list.
     *
     * THE REFUSAL KEEPS `error()`'s DEFAULT SHAPE — HTTP 200 with `status: 0` — rather than
     * introducing a status code on a path every client calls. A 401 would be actively wrong here:
     * the app force-clears its session on any 401.
     */
    public function test_a_deactivated_user_cannot_log_in_to_the_api(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        $staff = $this->makeStaff($merchant, ['password' => bcrypt('password')]);
        $staff->current_store = $store->id;
        $staff->is_active = 0;
        $staff->save();

        $body = $this->login(User::find($staff->id));

        $this->assertSame(0, (int) ($body['status'] ?? 1),
            'a de-activated employee logged into the API, which the web login refuses');
        $this->assertArrayNotHasKey('token', $body['data'] ?? [],
            'a de-activated employee was issued a token');
    }

    public function test_a_login_disabled_user_cannot_log_in_to_the_api(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        $staff = $this->makeStaff($merchant, ['password' => bcrypt('password')]);
        $staff->current_store = $store->id;
        $staff->is_enable_login = 0;
        $staff->save();

        $body = $this->login(User::find($staff->id));

        $this->assertSame(0, (int) ($body['status'] ?? 1),
            "the merchant's \"disable this employee\" switch did not hold on the API door");
    }

    /** Disabling the merchant disables their staff with them — as the web check does. */
    public function test_an_employee_of_a_disabled_owner_cannot_log_in(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        $staff = $this->makeStaff($merchant, ['password' => bcrypt('password')]);
        $staff->current_store = $store->id;
        $staff->save();

        $merchant->is_enable_login = 0;
        $merchant->save();
        (new User)->flushCache();

        $body = $this->login(User::find($staff->id));

        $this->assertSame(0, (int) ($body['status'] ?? 1),
            'an employee of a disabled merchant still logged into the API');
    }

    /**
     * AND THE EXEMPTION THE WEB CHECK CARRIES. `$user->type != 'super admin'` guards both
     * switches there; a first draft of this guard omitted it and would have locked the platform
     * operator out of the API the moment either column read 0 on their row.
     */
    public function test_the_operator_is_exempt_from_the_account_state_switches(): void
    {
        $operator = $this->makePlatformAdmin(['password' => bcrypt('password')]);
        $store = $this->makeStore($operator, ['default_language' => 'en']);
        $operator->current_store = $store->id;
        $operator->is_active = 0;
        $operator->is_enable_login = 0;
        $operator->save();

        $body = $this->login(User::find($operator->id));

        $this->assertSame(1, (int) ($body['status'] ?? 0),
            'the platform operator was locked out of the API by the account-state switches, '
            . 'which the web check exempts them from');
    }

    /**
     * THE PREMISE THE GATE RESTS ON, and the reason this is a test rather than a comment.
     *
     * Gating the dashboard on `Manage Order` / `Manage Products` / `Manage Coupon` only avoids
     * locking real merchants out because the shared global `admin` role HOLDS those names. The
     * seeder does not grant them -- `PermissionTableSeeder` creates the `admin` role with no
     * permissions at all (:106-116). They are granted by `Utility::addNewData()`, which lists
     * them in `$companyNewPermission` and runs on every WEB login
     * (`AuthenticatedSessionController::store():83`).
     *
     * That call is deliberately NOT added to the API login: it opens with
     * `Artisan::call('cache:clear')`, so wiring it into `adminlogin` would flush the whole
     * application cache on every launch of the Flutter app. The dependency is pinned here
     * instead: drop one of these four names from `$companyNewPermission` and this fails, rather
     * than merchants quietly losing their own dashboard.
     */
    public function test_the_merchant_role_really_holds_every_permission_the_dashboard_gates_on(): void
    {
        [$merchant] = $this->merchantWithStore();
        $this->actingAs($merchant);

        $this->makeGlobalAdminRole();
        \App\Models\Utility::addNewData();

        $admin = \App\Models\Role::where('name', 'admin')->whereNull('store_id')->first();

        $this->assertNotNull($admin, 'premise: the shared global `admin` role exists');

        foreach (['Manage Order', 'Show Order', 'Manage Products', 'Manage Coupon'] as $name) {
            $this->assertTrue($admin->hasPermission($name),
                'the dashboard gates on `' . $name . '` but the shared global `admin` role every '
                . 'merchant is given does not hold it, so gating would lock merchants out of '
                . 'their own figures');
        }
    }

    /**
     * THE PREMISE GUARD, and it is here because its absence cost a false reading. The first draft
     * drove `getJson` at a route declared POST, so three tests asserting that money was ABSENT
     * passed against a 405 Method Not Allowed -- the money was missing because the handler never
     * ran. An assertion of absence is worthless without an assertion that the thing answered.
     */
    private function dashboardFor(User $user): array
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenFor($user))
            ->postJson('/api/admin/dashboard');

        $this->assertSame(200, $response->getStatusCode(),
            'premise: the dashboard must actually have answered -- absence of a field in a 405 or '
            . 'a 500 proves nothing about permission gating. Body: '
            . substr((string) $response->getContent(), 0, 300));

        $body = $response->json();

        $this->assertArrayHasKey('data', $body, 'premise: the dashboard returned no data envelope');

        return $body['data'];
    }

    private function seedMoney(Store $store): void
    {
        $this->makeOrder($store, ['final_price' => 4321, 'user_name' => 'Buyer']);

        // forceCreate for BOTH, and not a style choice: Coupon and Product each declare a
        // non-empty $fillable that omits store_id, so create() drops it silently and the row
        // lands in no store at all -- the trap SecurityTestCase::makeRole() documents.
        \App\Models\Coupon::forceCreate([
            'coupon_name' => 'SS-GATED',
            'coupon_code' => 'SSGATED' . uniqid(),
            'coupon_type' => 'flat',
            'discount_amount' => 1234,
            'coupon_limit' => 5,
            'store_id' => $store->id,
        ]);

        \App\Models\Product::forceCreate([
            'name' => 'Gated product', 'slug' => 'gated-' . uniqid(),
            'price' => 99, 'sale_price' => 99, 'cost_price' => 40,
            'product_stock' => 3, 'track_stock' => 1, 'variant_product' => 0,
            'stock_status' => 'in_stock', 'status' => 1,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    /**
     * ALSO A RATCHET: the pre-change dashboard answered 200 for this staff member too, so this
     * cannot fail on revert. It exists to catch a later "hardening" that turns a permission
     * denial into a 401 — which would force every affected merchant out of the app.
     *
     * A REFUSAL IS 403 AND NEVER 401. The shipped app forces a logout on 401 and keeps the
     * session on 403, so this is the difference between "you may not do that" and "you are
     * signed out".
     */
    public function test_a_permission_refusal_is_not_401(): void
    {
        [$merchant, $store] = $this->merchantWithStore();

        $staff = $this->makeStaff($merchant, ['password' => bcrypt('password')]);
        $staff->current_store = $store->id;
        $staff->save();
        $staff = User::find($staff->id);

        // A gated money endpoint with no permission at all.
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenFor($staff))
            ->postJson('/api/admin/dashboard');

        $this->assertNotSame(401, $response->getStatusCode(),
            'a permission denial answered 401, which logs the merchant out of the Flutter app');
    }
}
