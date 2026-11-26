<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * GAP-142 / P4-F113 (READ HALF) and P4-F111 — THE LAST TWO UNGATED ADMIN-API FAMILIES.
 * =============================================================================================
 *
 * Eight of the nine families under GAP-142 were gated on 2026-11-25. These four handlers are what
 * remained, and they share one shape: **the tenancy gate is present and the permission limb is
 * absent**. Each calls `resolveOwnedStore()` and answers 403 for a store the caller does not own —
 * so this is not a cross-tenant hole. It is that **every member of the store reads the data,
 * whatever they were granted.**
 *
 *     GET  api/admin/customerlist          CustomerList:7003          routes/api.php:268
 *     GET  api/admin/customer/orders       getCustomerOrdersApi:9501  routes/api.php:196
 *     POST api/admin/searchCustomerByName  searchCustomerByName:10732 routes/api.php:199
 *     POST api/admin/searchProductsByName  searchProductsByName:8251  routes/api.php:305
 *
 * WHAT EACH ONE HANDS OVER, which is why they are worth gating rather than being a tidy-up:
 *
 *   `CustomerList` serves every customer's name, mobile, **e-mail**, order count, **total spend**
 *   and **default postal address** — the whole customer book, with money, in one paginated call.
 *   `getCustomerOrdersApi` serves one customer's orders **and a full delivery address**.
 *   `searchCustomerByName` is the same book behind a search term.
 *   `searchProductsByName` serves **`products.cost_price`** — the merchant's margin, which is the
 *   single most commercially sensitive column in the catalogue.
 *
 * ---------------------------------------------------------------------------------------------
 * THE PERMISSION NAMES, AND A MEASUREMENT THAT CHANGED THE CHOICE.
 *
 * All three names are **seeded**, so every gate here is a right a merchant can actually delegate:
 * `Manage Customer` and `Show Customer` at `Utility.php:3085-3086` in `$arrPermissions` and
 * `:3336-3337` in `$companyNewPermission` (which grants them to the global `admin` role every
 * merchant holds), and `"Manage Products"` at `:2941` and `:3271` of the same two arrays.
 *
 * TWO EARLIER CLAIMS IN THIS DOCBLOCK WERE WRONG, both caught by the independent review of this
 * slice rather than by any test. Recorded instead of quietly deleted, because each has a reusable
 * cause.
 *
 *   (1) IT SAID `Manage Products` IS "SEEDED NOWHERE IN THIS REPOSITORY" and then argued why the
 *       gate was safe anyway. False. The measuring script extracted quoted strings with a
 *       SINGLE-QUOTE-ONLY pattern, and `Utility.php` writes the three product names in DOUBLE
 *       quotes while the customer names are single-quoted — so the search returned exactly the
 *       names that confirmed the wrong conclusion. `AdminApiPermissionExposureTest:566-577` had
 *       already measured the truth by running `Utility::addNewData()` and asserting
 *       `hasPermission('Manage Products')`, and `DashboardPermissionNamesTest:162` states the
 *       seeded name is the plural in its own failure message. GAP-246 was withdrawn.
 *
 *   (2) IT SAID THE WEB "ADMITS EITHER NAME ON A CUSTOMER READ", on one citation. True of the
 *       DETAIL screen, false of the LIST — and the citation pointed at neither:
 *       `CustomerController:52` is inside `store()`, the CREATE handler, and `:525` is inside
 *       `customerTimeline()`. The real gates are `index():312` → `:314`, `Manage Customer` ONLY,
 *       and `show():335` → `:339-341`, operator OR `Show Customer`.
 *
 * SO THE GATES ARE NOT UNIFORM, and the asymmetry is the web's own:
 *
 *     customerlist, searchCustomerByName  ->  ['Manage Customer']                  (the web's LIST)
 *     customer/orders                     ->  ['Manage Customer','Show Customer']  (its DETAIL)
 *
 * THE MONEY DECIDES IT. The web puts `Payments Collected` and `Net Retained` on the LIST
 * (`CustomerDataTable:296-297`) behind `Manage Customer`, while the `Show Customer` detail screen
 * is handed `compact('orders')` and shows no money at all. These two list payloads carry
 * `total_spend`, `aov`, the whole `ledger` block and the default postal address — so admitting
 * `Show Customer` to them would hand a deliberately-restricted staff member money the web
 * withholds from that exact principal.
 *
 * ---------------------------------------------------------------------------------------------
 * EVERY CASE DRIVES A STAFF MEMBER, NOT THE MERCHANT. A merchant passes through the owner limb, so
 * a merchant-token test cannot tell a working gate from a gate naming a permission nobody holds —
 * the regression this family has already produced once. And every refusal is checked for **403,
 * never 401**: the shipped app force-clears its session on any 401.
 */
class AdminApiCustomerReadPermissionTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;

    private Store $store;

    private Customer $customer;

    /** `searchProductsByName` answers **404** when nothing matches (`:8341-8342`), so a control
     *  without a matching row would measure an empty catalogue rather than the gate. */
    private const PRODUCT_NAME = 'F111-MARGIN-WIDGET';

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        $this->customer = Customer::forceCreate([
            'first_name' => 'F113', 'last_name' => 'Shopper',
            'email' => 'f113-shopper@example.test',
            'mobile' => '555000999', 'password' => bcrypt('x'),
            'store_id' => $this->store->id, 'created_by' => $this->merchant->id,
            'status' => 1, 'register_type' => 'email',
        ]);

        \App\Models\Product::forceCreate([
            'name' => self::PRODUCT_NAME, 'slug' => 'f111-' . uniqid(),
            'description' => 'x', 'product_stock' => 5, 'track_stock' => 1,
            'variant_product' => 0, 'stock_status' => 'in_stock', 'trending' => 0,
            'status' => 1, 'price' => 100, 'sale_price' => 100,
            'store_id' => $this->store->id, 'created_by' => $this->merchant->id,
        ]);
    }

    private function staffIn(User $owner, Store $store, ?string $permission = null): User
    {
        $staff = $this->makeStaff($owner);
        $staff->current_store = $store->id;
        $staff->save();

        $staff = User::find($staff->id);

        if ($permission !== null) {
            $this->grantPermission($staff, $permission);
            $staff = User::find($staff->id);
        }

        return $staff;
    }

    private function token(User $user): string
    {
        return $user->createToken('app')->plainTextToken;
    }

    /** @return array<string,array{0:string,1:string,2:array<string,mixed>}> */
    private function doors(): array
    {
        return [
            'customerlist' => ['GET', '/api/admin/customerlist?store_id=' . $this->store->id, []],
            'customer/orders' => ['GET', '/api/admin/customer/orders?store_id=' . $this->store->id
                . '&id=' . $this->customer->id, []],
            /*
             * `store_id` IN THE QUERY STRING, NOT THE BODY, on both POST routes: they read
             * `$request->query('store_id')` (`:10744`, `:8263`), so a body value is invisible and
             * the `exists:stores,id` validator throws **422 before any gate runs**. The first
             * version of this file sent it in the body and four control cases failed against an
             * UNGATED handler — a 422 that looked like a refusal. Worth recording: a test whose
             * controls fail before the fix is as misleading as one that cannot fail after it.
             */
            'searchCustomerByName' => ['POST', '/api/admin/searchCustomerByName?store_id='
                . $this->store->id, ['name' => 'F113']],
            'searchProductsByName' => ['POST', '/api/admin/searchProductsByName?store_id='
                . $this->store->id, ['name' => self::PRODUCT_NAME]],
        ];
    }

    private function drive(User $actor, string $method, string $uri, array $payload = [])
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->token($actor))
            ->json($method, $uri, $payload);
    }

    /**
     * THE PREMISE — AND IT NOW MEASURES THE SEEDERS RATHER THAN THE HARNESS.
     *
     * The first version asserted only `allowsAny($staff, ['Manage Customer'])` while its NAME
     * claimed it had established which names are grantable. It had not:
     * `SecurityTestCase::grantPermission()` is a `Permission::firstOrCreate`, so it creates the row
     * itself and that assertion holds for any arbitrary string — it measured the fixture. Reading
     * grantability means running the real seeder, as `AdminApiPermissionExposureTest:566-577` does,
     * and doing that here would have caught the false "seeded nowhere" claim this file carried.
     */
    public function test_every_permission_name_these_gates_use_is_really_seeded(): void
    {
        \App\Models\Utility::addNewData();

        foreach (['Manage Customer', 'Show Customer', 'Manage Products'] as $name) {
            $this->assertNotNull(
                DB::table('permissions')->where('name', $name)->first(),
                sprintf(
                    'the seeder does not create `%s`, so a gate naming it admits nobody a merchant '
                    . 'can grant -- the defect DashboardPermissionNamesTest exists for. If this '
                    . 'fails, read Utility.php for BOTH quote styles: the product names are '
                    . 'double-quoted there and a single-quoted search misses them, which is exactly '
                    . 'how this file once claimed `Manage Products` was seeded nowhere.',
                    $name
                )
            );
        }

        $staff = $this->staffIn($this->merchant, $this->store, 'Manage Customer');

        $this->assertTrue(
            \App\Services\MerchantPermissions::allowsAny($staff, ['Manage Customer'], $this->store->id),
            'the staff fixture cannot hold `Manage Customer`, so every control case below would be '
            . 'measuring a grant failure rather than the gate'
        );

        $this->assertNotSame(
            (int) $this->store->created_by,
            (int) $staff->id,
            'the staff fixture IS the store owner, so the owner limb would carry every gate and a '
            . 'gate naming an unholdable permission could not be detected'
        );
    }

    /** THE DEFECT: a staff member of the store, holding nothing, reads all four. */
    public function test_an_unpermissioned_staff_member_is_refused_on_every_read(): void
    {
        $staff = $this->staffIn($this->merchant, $this->store);

        foreach ($this->doors() as $name => [$method, $uri, $payload]) {
            $response = $this->drive($staff, $method, $uri, $payload);

            $this->assertSame(403, $response->status(), sprintf(
                'a staff member of the store holding NO permission was admitted to "%s" (HTTP %d). '
                . 'The tenancy gate (resolveOwnedStore) is present on all four and is not the '
                . 'question: every member of the store read the whole customer book with money and '
                . 'addresses, or the catalogue with cost_price, whatever they were granted. Body: %s',
                $name,
                $response->status(),
                mb_substr((string) $response->getContent(), 0, 220)
            ));
        }
    }

    /** AND THE REFUSAL IS NEVER A 401, which would log the merchant out of the whole app. */
    public function test_the_refusal_is_not_a_401(): void
    {
        $staff = $this->staffIn($this->merchant, $this->store);

        foreach ($this->doors() as $name => [$method, $uri, $payload]) {
            $this->assertNotSame(401, $this->drive($staff, $method, $uri, $payload)->status(),
                '"' . $name . '" refused an unpermitted staff member with a 401. The shipped '
                . 'Flutter app force-clears its session on ANY 401, so this signs the merchant out '
                . 'of the app rather than telling them they lack a right.');
        }
    }

    /**
     * CONTROL: staff holding the named permission still read — AND THE PAYLOAD IS CHECKED, not just
     * the status. On this controller a refusal is a 403, so a 200 does distinguish here; but a gate
     * that admitted the caller and then returned an empty envelope would still be broken, and the
     * three customer routes are the ones a merchant delegates.
     */
    public function test_staff_holding_manage_customer_still_read_the_customer_routes(): void
    {
        $staff = $this->staffIn($this->merchant, $this->store, 'Manage Customer');

        foreach (['customerlist', 'customer/orders', 'searchCustomerByName'] as $name) {
            [$method, $uri, $payload] = $this->doors()[$name];

            $response = $this->drive($staff, $method, $uri, $payload);

            $this->assertSame(200, $response->status(), sprintf(
                'staff holding `Manage Customer` were refused "%s" (HTTP %d) — the gate admits '
                . 'nobody a merchant can actually grant. Body: %s',
                $name,
                $response->status(),
                mb_substr((string) $response->getContent(), 0, 220)
            ));
        }

        /* THE DATA ITSELF on the list, so "admitted" is distinguishable from "admitted and empty". */
        $list = $this->drive($staff, 'GET', '/api/admin/customerlist?store_id=' . $this->store->id);

        $this->assertStringContainsString('f113-shopper@example.test', (string) $list->getContent(),
            'staff holding `Manage Customer` got a 200 from customerlist and the store\'s own '
            . 'customer was not in it, so the gate is short-circuiting the handler');
    }

    /**
     * `Show Customer` OPENS THE DETAIL AND IS REFUSED THE TWO LISTS — the web's own asymmetry, and
     * the case the review changed.
     *
     * Its first version asserted 200 on all three, citing `CustomerController:341` for "the web
     * admits either on a read". The web's LIST requires `Manage Customer` only (`index():312` ->
     * `:314`) and puts the money columns behind it (`CustomerDataTable:296-297`), while the
     * `Show Customer` DETAIL screen shows no money. Admitting this name to the two list routes
     * handed a restricted staff member `total_spend`, `aov` and the `ledger` block the web
     * withholds from them. BOTH legs are driven, so this cannot pass by refusing everybody.
     */
    public function test_show_customer_opens_the_detail_but_not_the_two_money_bearing_lists(): void
    {
        $staff = $this->staffIn($this->merchant, $this->store, 'Show Customer');

        [$method, $uri, $payload] = $this->doors()['customer/orders'];
        $this->assertSame(200, $this->drive($staff, $method, $uri, $payload)->status(),
            'staff holding `Show Customer` were refused the customer DETAIL. Its web analogue is '
            . 'show() (:335, gated :339-341), which admits that name, so refusing it here is '
            . 'stricter than the web -- a product change rather than a port.');

        foreach (['customerlist', 'searchCustomerByName'] as $name) {
            [$method, $uri, $payload] = $this->doors()[$name];

            $this->assertSame(403, $this->drive($staff, $method, $uri, $payload)->status(), sprintf(
                '`Show Customer` alone was admitted to "%s", which serves total_spend, aov, the '
                . 'ledger block and the default postal address. The web refuses that principal the '
                . 'customer LIST outright and keeps Payments Collected / Net Retained behind '
                . '`Manage Customer`, so admitting it here hands a deliberately-restricted staff '
                . 'member money the web withholds from them.',
                $name
            ));
        }
    }

    /**
     * THE MISSING CONTROL THE REVIEW ASKED FOR: staff GRANTED `Manage Products` still search the
     * catalogue. This is the one case that falsifies "the name is not grantable" — the claim this
     * file used to make — so its absence is precisely why that claim survived.
     */
    public function test_staff_holding_manage_products_still_search_the_catalogue(): void
    {
        $staff = $this->staffIn($this->merchant, $this->store, 'Manage Products');

        [$method, $uri, $payload] = $this->doors()['searchProductsByName'];

        $response = $this->drive($staff, $method, $uri, $payload);

        $this->assertSame(200, $response->status(), sprintf(
            'staff granted `Manage Products` were refused the catalogue search (HTTP %d). The name '
            . 'is seeded (Utility.php:2941 and :3271, in DOUBLE quotes) and is grantable, so this '
            . 'gate is a delegable right rather than one carried only by the owner and operator '
            . 'limbs. Body: %s',
            $response->status(),
            mb_substr((string) $response->getContent(), 0, 200)
        ));

        $this->assertStringContainsString(self::PRODUCT_NAME, (string) $response->getContent(),
            'the permitted staff member got a 200 and the store\'s own product was not in it');
    }

    /** CONTROL: the store's own OWNER still reads all four, holding no role at all. */
    public function test_the_store_owner_still_reads_every_route(): void
    {
        foreach ($this->doors() as $name => [$method, $uri, $payload]) {
            $this->assertSame(200, $this->drive(User::find($this->merchant->id), $method, $uri, $payload)->status(),
                'the store\'s own owner was refused "' . $name . '". In production a merchant\'s '
                . 'global `admin` role carries all three of these names, but a merchant built by a '
                . 'FIXTURE holds no role at all -- so without the store-owner limb this gate locks '
                . 'the fixture owner out, and any real merchant whose role was edited.');
        }
    }

    /** CONTROL: the platform operator still reads all four. `isAbleTo()` alone refuses them. */
    public function test_the_platform_operator_still_reads_every_route(): void
    {
        $operator = $this->makePlatformAdmin();

        foreach ($this->doors() as $name => [$method, $uri, $payload]) {
            $this->assertNotSame(403, $this->drive(User::find($operator->id), $method, $uri, $payload)->status(),
                'the platform operator was refused "' . $name . '". There is no super-admin bypass '
                . 'in this codebase, so a gate using bare isAbleTo() locks them out.');
        }
    }

    /**
     * THE MUTANT NOTHING ELSE CATCHES: a gate keyed on `auth()->user()->current_store` instead of
     * the store under test. Every other fixture here sets the staff member's `current_store` to
     * this store, so the two readings are the SAME NUMBER and all the cases above pass against a
     * gate reading the wrong one. This case builds the inputs where they disagree — a grant in
     * store B used against store A — and drives both legs, so it cannot pass by refusing everybody.
     */
    public function test_a_grant_in_the_merchants_other_store_does_not_admit_here(): void
    {
        $storeB = $this->makeStore($this->merchant);

        $staff = $this->makeStaff($this->merchant);
        $staff->current_store = $storeB->id;
        $staff->save();
        $staff = User::find($staff->id);

        $this->grantPermissionInStore($staff, 'Manage Customer', $storeB);
        $staff = User::find($staff->id);

        /* Leg 1: refused in store A, where they hold nothing. */
        $this->assertSame(403,
            $this->drive($staff, 'GET', '/api/admin/customerlist?store_id=' . $this->store->id)->status(),
            'a grant in the merchant\'s OTHER store admitted this staff member to store A\'s '
            . 'customer book, so the permission is being read globally or against current_store '
            . 'rather than against the store under test');

        /* Leg 2: and admitted in store B, so leg 1 is not "refuses everybody". */
        $this->assertSame(200,
            $this->drive($staff, 'GET', '/api/admin/customerlist?store_id=' . $storeB->id)->status(),
            'the same staff member was refused in the store where they DO hold the grant, so leg 1 '
            . 'proves nothing — the gate refuses everybody rather than reading the store');
    }

    /** And a staff member of ANOTHER tenant still gets the TENANCY answer, not the permission one:
     *  the permission limb must sit BELOW resolveOwnedStore(), or three shipped suites that assert
     *  the tenancy status turn red. */
    public function test_another_tenants_staff_still_gets_the_tenancy_refusal(): void
    {
        $otherMerchant = $this->makeMerchant();
        $otherStore = $this->makeStore($otherMerchant);
        $outsider = $this->staffIn($otherMerchant, $otherStore, 'Manage Customer');

        $response = $this->drive($outsider, 'GET', '/api/admin/customerlist?store_id=' . $this->store->id);

        $this->assertSame(403, $response->status());
        $this->assertStringContainsString('this store', (string) $response->getContent(),
            'a caller from another tenant got the PERMISSION refusal rather than the store one, so '
            . 'the permission limb has been hoisted above resolveOwnedStore(). The ordering is '
            . 'load-bearing: tenancy answers first.');
    }

    /** Grants a permission scoped to a specific store, for the current_store mutant above. */
    private function grantPermissionInStore(User $user, string $permission, Store $store): void
    {
        $role = $this->makeRole($this->merchant, $store, 'F113-ROLE-' . $store->id);

        $permissionId = DB::table('permissions')->where('name', $permission)->value('id')
            ?: DB::table('permissions')->insertGetId([
                'name' => $permission, 'display_name' => $permission,
                'created_at' => now(), 'updated_at' => now(),
            ]);

        DB::table('permission_role')->insertOrIgnore([
            'permission_id' => $permissionId, 'role_id' => $role->id,
        ]);

        DB::table('role_user')->insertOrIgnore([
            'role_id' => $role->id, 'user_id' => $user->id, 'user_type' => User::class,
        ]);

        (new User)->flushCache();
    }
}
