<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ADMIN-API STORE OWNERSHIP -- THE TWENTY INLINE PREDICATES, NOW ROUTED THROUGH resolveOwnedStore().
 * =============================================================================================
 *
 * WHAT THIS FILE USED TO SAY, AND WHAT CHANGED. It recorded that the cross-tenant boundary HELD
 * on the admin API (a merchant could not reach another merchant's store) but that the sites
 * guarding it inlined their own predicate instead of calling
 * Api\DashboardController::resolveOwnedStore(), and that this was wrong in two directions:
 *
 *   DEFECT 1  NEW-OWNERSHIP-STAFF-LOCKOUT-01 -- the inline predicate scoped the store by the
 *             ACTOR's own id (`->where('created_by', $adminUser->id)`) rather than by their
 *             TENANT (`$user->creatorId()`, app/Models/User.php:95-105, which resolves a staff
 *             row to its owning merchant). So a staff user was refused their own merchant's
 *             store. Fails closed, so not a vulnerability -- a legitimate workflow denied.
 *
 *   DEFECT 2  NEW-OWNERSHIP-SUPERADMIN-500-01 -- the guard was written
 *             `if (!$store && $adminUser->type !== 'super admin') { return 403; }`, so a platform
 *             operator looking at a store they did not create fell THROUGH with $store still
 *             null. searchProductsByName then did `Product::where('store_id', $store->id)` and
 *             answered 500, `Attempt to read property "id" on null`.
 *
 * BOTH ARE NOW FIXED, by routing every one of those sites through resolveOwnedStore() (:3772),
 * which gets the tenant right (creatorId()) and hands a platform operator the real Store instead
 * of a null. The pinned "reddens when fixed" tests those defects carried have been replaced by
 * the positives they asked for, and the cross-tenant negatives they protected are all still here
 * and still green.
 *
 * THE COUNT WAS 11 AND THE REAL COUNT WAS 20. This file's own structural scan pinned ELEVEN sites,
 * matching `Store::where('id', $storeId)->where('created_by', $adminUser->id)`. That regex is
 * spelled for ONE variable name, and the identical defect had been written with three others:
 *
 *     $adminUser->id            11 sites   (the pinned eleven)
 *     $user->id                  6 sites   indexAllPlan :6277, getCurrencyRatesApi :6447,
 *                                          updateStoreLanguage :6732, getAvailableLanguages :6823,
 *                                          getCurrenciesApi :7002,
 *                                          getCurrentLanguageAndCurrency :7102
 *     $userId                    1 site    createOrderViaApi :5535
 *     keyed on $validated['store_id'] rather than $storeId:
 *                                2 sites   updateCurrencyRateApi :6547,
 *                                          toggleCurrencyStatusApi :6627  -- both WRITE currency
 *                                          rates, i.e. money-adjacent
 *
 * All 20 are routed through the helper now, and the scan below is rewritten to match on the SHAPE
 * (a Store lookup narrowed by a created_by that is not creatorId()) rather than on one spelling,
 * so the defect cannot come back under a fifth variable name. This is the FOURTH time a narrow
 * scan under-reported on this project; the previous three are recorded in this file's history.
 *
 * ONE SITE IS DELIBERATELY LEFT ALONE: DeleteStore (:4118) scopes by `$user->id` on purpose --
 * it irreversibly destroys a store and all its content, and `$user->id` means a staff member
 * cannot destroy their employer's store, which creatorId() would permit. The scan allows exactly
 * that one and asserts it is inside DeleteStore, so it cannot be used as cover for a new one.
 *
 * 403 AND NEVER 401 THROUGHOUT: repo B's lib/network/network.dart maps any 401 to a forced logout
 * that clears stored preferences, so a tenancy denial answered 401 would sign a merchant out of
 * the shipped admin app rather than telling them no.
 *
 * ASSERTION ORDER AND NON-VACUITY. This file has already had two assertions that passed without
 * testing anything -- an `assertNotSame(401, ...)` that was trivially true of a 422, and the same
 * assertion trivially true of a 405 caused by issuing the wrong HTTP verb. Both are fixed and
 * both lessons are applied to the new cases below: every negative asserts assertSame(403), and
 * every endpoint's declared verb and required body are supplied so the request actually REACHES
 * the ownership check instead of dying in validation first.
 */
class ApiStoreOwnershipPredicateTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * Store lookups still narrowed by something other than the caller's TENANT. Exactly one is
     * legitimate -- DeleteStore -- and the scan asserts that the survivor is that one.
     */
    private const SITES_SCOPED_BY_ACTOR_ID = 1;

    /** @return array{0:\App\Models\User,1:\App\Models\Store,2:string} merchant, store, token */
    private function tenantWithToken(): array
    {
        [$merchant, $store] = $this->makeTenant();

        return [$merchant, $store, $merchant->createToken('auth_token')->plainTextToken];
    }

    // -----------------------------------------------------------------------------------------
    // THE SECURITY PROPERTY. These must stay green through any repair of the two defects.
    // -----------------------------------------------------------------------------------------

    public function test_a_foreign_merchant_cannot_search_products_in_another_stores_catalogue(): void
    {
        // The platform operator is created first so it -- not a merchant -- holds user id 1.
        // Self-registered merchants get created_by => 1, so a merchant landing on id 1 would
        // make every other merchant's created_by match its creatorId() and the predicate would
        // appear to pass for an unrelated tenant. SecurityTestCase's own suites hit exactly that.
        $this->makePlatformAdmin();

        [, $victimStore] = $this->tenantWithToken();
        [, , $attackerToken] = $this->tenantWithToken();

        $this->withHeader('Authorization', 'Bearer ' . $attackerToken)
            ->postJson('/api/admin/searchProductsByName?store_id=' . $victimStore->id, ['name' => 'a'])
            ->assertStatus(403);
    }

    public function test_a_foreign_merchant_cannot_list_another_stores_customers(): void
    {
        $this->makePlatformAdmin();

        [, $victimStore] = $this->tenantWithToken();
        [, , $attackerToken] = $this->tenantWithToken();

        $this->withHeader('Authorization', 'Bearer ' . $attackerToken)
            ->getJson('/api/admin/customerlist?store_id=' . $victimStore->id)
            ->assertStatus(403);
    }

    /**
     * 403 AND NOT 401, asserted rather than assumed. repo B's lib/network/network.dart maps ANY
     * 401 into a forced logout that clears stored preferences, so a denial answered 401 would
     * sign a merchant out of the mobile admin app instead of telling them no.
     *
     * THIS ASSERTION WAS VACUOUS IN ITS FIRST VERSION AND IS CORRECTED. It read
     * assertNotSame(401, $status), and when the tenancy predicate was deliberately removed as a
     * teeth proof it PASSED -- because with no predicate there is no denial at all, and "not 401"
     * is trivially true of the 422 the attacker then got. assertSame(403) is the property: a
     * denial, and that denial carrying the code repo B can survive. Same mistake as the
     * storefront smoke test that passed with the tenancy defect reintroduced because an empty
     * basket renders the same chrome as a full one -- assert the thing, not a proxy for it.
     */
    public function test_the_denial_is_403_and_never_401(): void
    {
        $this->makePlatformAdmin();

        [, $victimStore] = $this->tenantWithToken();
        [, , $attackerToken] = $this->tenantWithToken();

        // THE VERB MATTERS, and getting it wrong was the SECOND vacuity in this one test.
        // searchProductsByName is declared POST (routes/api.php:269); the loop issued getJson()
        // to both, so that endpoint answered 405 Method Not Allowed -- and assertNotSame(401)
        // was happily true of a 405 that never reached the authorization check at all. Two
        // different ways for the same assertion to pass without testing anything.
        foreach ([
            ['get',  '/api/admin/customerlist?store_id=' . $victimStore->id],
            ['post', '/api/admin/searchProductsByName?store_id=' . $victimStore->id],
        ] as [$verb, $uri]) {
            $request = $this->withHeader('Authorization', 'Bearer ' . $attackerToken);

            $status = ($verb === 'get'
                ? $request->getJson($uri)
                : $request->postJson($uri, ['name' => 'abc']))
                ->getStatusCode();

            $this->assertSame(
                403,
                $status,
                strtoupper($verb) . ' ' . $uri . ' answered ' . $status
                . ' to a foreign merchant.' . "\n"
                . '  401 would be the dangerous answer: repo B treats any 401 as "the session is '
                . 'dead" and clears preferences, so the merchant is logged out of the admin app.' . "\n"
                . '  Anything in the 2xx or 4xx-validation range means the ownership predicate is '
                . 'GONE and the request reached the handler -- a cross-tenant read, not a denial.' . "\n"
                . '  405 means this test is issuing the wrong HTTP verb and is testing nothing.'
            );
        }
    }

    // -----------------------------------------------------------------------------------------
    // POSITIVE: THE OWNER'S OWN WORKFLOW STILL WORKS.
    //
    // The property is "authorization passed", and the honest way to assert it is NOT "the status
    // differs from the denial" -- a 404 and a 403 are both non-200 and a lazy assertion would
    // accept either. The owner gets a 404 whose BODY says "Product not found", which can only be
    // produced after the ownership check let the request through to the catalogue query.
    // -----------------------------------------------------------------------------------------
    public function test_the_owning_merchant_still_reaches_the_catalogue_search(): void
    {
        $this->makePlatformAdmin();

        [, $store, $token] = $this->tenantWithToken();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/searchProductsByName?store_id=' . $store->id, ['name' => 'abc']);

        $this->assertNotSame(
            403,
            $response->getStatusCode(),
            'The store OWNER was refused their own store. Whatever tightened this predicate has '
            . 'broken the primary workflow, which is worse than the staff lockout below.'
        );

        $response->assertJsonPath('data.message', 'Product not found.');
    }

    public function test_the_owning_merchant_still_reaches_the_customer_list(): void
    {
        $this->makePlatformAdmin();

        [, $store, $token] = $this->tenantWithToken();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/customerlist?store_id=' . $store->id)
            ->assertStatus(200);
    }

    // -----------------------------------------------------------------------------------------
    // DEFECT 1 FIXED -- STAFF REACH THEIR OWN MERCHANT'S STORE.
    //
    // These replace test_staff_are_currently_refused_their_own_merchants_store and
    // ..._customer_list, which pinned the lockout and reddened the moment it was repaired, with
    // exactly what their failure messages asked for.
    // -----------------------------------------------------------------------------------------

    public function test_staff_now_reach_their_own_merchants_catalogue_search(): void
    {
        $this->makePlatformAdmin();

        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);
        $staffToken = $staff->createToken('auth_token')->plainTextToken;

        // Fixture sanity, kept from the pinned version: the staff row must really belong to the
        // merchant that owns the store, or this measures nothing.
        $this->assertSame((int) $merchant->id, (int) $staff->created_by);
        $this->assertSame((int) $merchant->id, (int) $store->created_by);
        $this->assertSame((int) $merchant->id, (int) $staff->creatorId(),
            'creatorId() must resolve a staff user to its owning merchant, or resolveOwnedStore() '
            . 'is not the fix it claims to be.');

        $response = $this->withHeader('Authorization', 'Bearer ' . $staffToken)
            ->postJson('/api/admin/searchProductsByName?store_id=' . $store->id, ['name' => 'abc']);

        $this->assertNotSame(403, $response->getStatusCode(),
            'NEW-OWNERSHIP-STAFF-LOCKOUT-01 IS BACK: staff are refused their own merchant\'s '
            . 'store. The predicate must be creatorId(), which resolveOwnedStore() uses.');

        // NOT just "not 403". The body proves the request reached the CATALOGUE QUERY, which can
        // only happen after the ownership check let it through -- the same non-vacuity argument
        // the owner positive above makes.
        $response->assertJsonPath('data.message', 'Product not found.');
    }

    public function test_staff_now_reach_their_own_merchants_customer_list(): void
    {
        $this->makePlatformAdmin();

        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);
        $staffToken = $staff->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $staffToken)
            ->getJson('/api/admin/customerlist?store_id=' . $store->id)
            ->assertStatus(200);
    }

    /**
     * AND STAFF ARE STILL CONFINED TO THEIR OWN TENANT. This is the limb that makes the fix a
     * fix rather than a removal: creatorId() widened access from "the actor's own rows" to "the
     * actor's TENANT", and nothing further.
     */
    public function test_staff_still_cannot_reach_a_foreign_merchants_store(): void
    {
        $this->makePlatformAdmin();

        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);
        $staffToken = $staff->createToken('auth_token')->plainTextToken;

        [, $victimStore] = $this->makeTenant();

        $this->assertSame(
            403,
            $this->withHeader('Authorization', 'Bearer ' . $staffToken)
                ->getJson('/api/admin/customerlist?store_id=' . $victimStore->id)
                ->getStatusCode(),
            'A staff user reached a store outside their own tenant. creatorId() must resolve to '
            . 'their employer, not act as a bypass.'
        );
    }

    // -----------------------------------------------------------------------------------------
    // DEFECT 2 FIXED -- THE PLATFORM OPERATOR GETS THE REAL STORE, NOT A NULL.
    //
    // Replaces test_a_platform_operator_currently_gets_a_500_on_a_store_it_did_not_create.
    // -----------------------------------------------------------------------------------------

    /**
     * The operator used to fall through the guard holding null and die at
     * DashboardController.php:6068 on `Product::where('store_id', $store->id)`. resolveOwnedStore()
     * skips the created_by narrowing for a platform operator and returns the real Store, so the
     * dereference is sound.
     */
    public function test_a_platform_operator_reaches_a_store_it_did_not_create(): void
    {
        $admin = $this->makePlatformAdmin();
        $adminToken = $admin->createToken('auth_token')->plainTextToken;

        [, $store] = $this->makeTenant();

        $this->assertNotSame((int) $admin->id, (int) $store->created_by,
            'fixture: the operator must NOT be the store creator, or the repaired branch is not '
            . 'taken.');

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/admin/searchProductsByName?store_id=' . $store->id, ['name' => 'abc']);

        $this->assertNotSame(500, $response->getStatusCode(),
            'NEW-OWNERSHIP-SUPERADMIN-500-01 IS BACK: the platform operator fell through the '
            . 'ownership guard holding a null $store and dereferenced it.');

        // The specific evidence that $store is a REAL model now: the handler ran its catalogue
        // query to completion and answered from it.
        $response->assertJsonPath('data.message', 'Product not found.');
    }

    public function test_the_customer_list_still_serves_the_platform_operator(): void
    {
        $admin = $this->makePlatformAdmin();
        $adminToken = $admin->createToken('auth_token')->plainTextToken;

        [, $store] = $this->makeTenant();

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/admin/customerlist?store_id=' . $store->id)
            ->assertStatus(200);
    }

    /**
     * searchCustomerByName was the site where `$store->name ?? ''` HID the null -- `??` has
     * isset() semantics, so a null $store yielded '' with no error and a scan counting `$store->`
     * uses would have filed it as a second 500 and been wrong. The empty store_name was the
     * evidence that the guard had been fallen through.
     *
     * NOW IT MUST BE THE REAL NAME. That is the positive proof that resolveOwnedStore() returned
     * a Store object to the operator rather than a null that `??` papered over -- and it is why
     * this assertion is inverted rather than deleted.
     */
    public function test_the_customer_search_now_hands_the_operator_the_real_store(): void
    {
        $admin = $this->makePlatformAdmin();
        $adminToken = $admin->createToken('auth_token')->plainTextToken;

        [, $store] = $this->makeTenant();

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/admin/searchCustomerByName?store_id=' . $store->id, ['name' => 'abcd'])
            ->assertStatus(200)
            ->assertJsonPath('data.store_name', $store->name);
    }

    /**
     * Top_orders (:6111 before the fix) had the identical guard and dereferences $store at
     * :6139, so it was a candidate for the same 500 -- but it CANNOT BE VERIFIED HERE. The
     * platform operator's request dies earlier on `no such table: currency_rates`, one of the six
     * GATE F money tables that no migration declares.
     *
     * STILL BLOCKED -- EXTERNAL EVIDENCE REQUIRED, and still pinned by name, so when the schema
     * lands this reddens and the operator path through Top_orders becomes measurable for the
     * first time. The ownership guard itself is now resolveOwnedStore() like the rest, and the
     * cross-tenant negative for this endpoint is in the table below -- an ATTACKER is refused
     * before reaching currency_rates, so that half is measurable today.
     */
    public function test_the_top_orders_operator_path_is_blocked_behind_gate_f(): void
    {
        $admin = $this->makePlatformAdmin();
        $adminToken = $admin->createToken('auth_token')->plainTextToken;

        [, $store] = $this->makeTenant();

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/admin/Top_orders?store_id=' . $store->id);

        $this->assertSame(500, $response->getStatusCode());

        $this->assertStringContainsString(
            'currency_rates',
            (string) $response->getContent(),
            'Top_orders no longer dies on the undeclared `currency_rates` table. If GATE F has '
            . 'opened, re-run this endpoint as a platform operator and assert the real answer.'
        );
    }

    // -----------------------------------------------------------------------------------------
    // THE CROSS-TENANT NEGATIVES FOR EVERY SITE THAT WAS REWIRED.
    //
    // One case per endpoint whose guard changed, so the repair is held by behaviour and not only
    // by the structural scan. Every entry supplies the endpoint's DECLARED verb and whatever body
    // its validation requires, because this file has twice had an assertion pass against a 405 or
    // a 422 that never reached the ownership check at all.
    // -----------------------------------------------------------------------------------------

    /**
     * @return array<string, array{0:string,1:string,2:array<string,mixed>}>
     *     name => [verb, uri template with {store}, body]
     */
    private function rewiredEndpoints(): array
    {
        return [
            // Guard sits immediately after the store_id validation -- no body needed.
            'CustomerList'                  => ['getJson',  '/api/admin/customerlist?store_id={store}', []],
            'getActivePaymentMethods'       => ['getJson',  '/api/admin/getActivePaymentMethods?store_id={store}', []],
            'indexAllPlan'                  => ['getJson',  '/api/admin/indexAllPlan?store_id={store}', []],
            'getCurrencyRatesApi'           => ['getJson',  '/api/admin/get-currency-rates?store_id={store}', []],
            'getAvailableLanguages'         => ['getJson',  '/api/admin/getAvailableLanguages?store_id={store}', []],
            'getCurrenciesApi'              => ['getJson',  '/api/admin/getCurrenciesApi?store_id={store}', []],
            'getCurrentLanguageAndCurrency' => ['getJson',  '/api/admin/getCurrentLanguageAndCurrency?store_id={store}', []],
            // Top_orders: the ATTACKER is refused BEFORE the undeclared currency_rates table is
            // touched (guard precedes that query), so unlike the operator path this half is
            // measurable while GATE F is still shut.
            'Top_orders'                    => ['getJson',  '/api/admin/Top_orders?store_id={store}', []],

            // POST, guard still ahead of the body validation.
            'searchProductsByName'          => ['postJson', '/api/admin/searchProductsByName?store_id={store}', ['name' => 'abc']],
            'searchCustomerByName'          => ['postJson', '/api/admin/searchCustomerByName?store_id={store}', ['name' => 'abcd']],
            'updateCurrencyApi'             => ['postJson', '/api/admin/updateCurrencyApi?store_id={store}', []],
            'updateStoreLanguage'           => ['postJson', '/api/admin/updateStoreLanguage?store_id={store}', []],
            'updateStoreOwnerInfo'          => ['postJson', '/api/admin/updateStoreOwnerInfo?store_id={store}', []],

            // Body validation runs FIRST here, so a valid body is required or the request 422s
            // without ever reaching the ownership check -- which would make the assertion vacuous.
            'updateCurrencyRateApi'         => ['putJson',  '/api/admin/update-currency-rate', [
                'store_id' => '{store}', 'currency_code' => 'USD', 'rate_per_usd' => 1,
            ]],
            'toggleCurrencyStatusApi'       => ['putJson',  '/api/admin/toggle-currency-status', [
                'store_id' => '{store}', 'currency_code' => 'USD', 'is_active' => 1,
            ]],
        ];
    }

    public function test_a_foreign_merchant_is_refused_at_every_rewired_admin_endpoint(): void
    {
        $this->makePlatformAdmin();

        [, $victimStore] = $this->tenantWithToken();
        [, , $attackerToken] = $this->tenantWithToken();

        foreach ($this->rewiredEndpoints() as $name => [$verb, $uri, $body]) {
            $uri = str_replace('{store}', (string) $victimStore->id, $uri);
            $body = array_map(
                fn ($v) => $v === '{store}' ? $victimStore->id : $v,
                $body
            );

            $request = $this->withHeader('Authorization', 'Bearer ' . $attackerToken);

            $response = $verb === 'getJson'
                ? $request->getJson($uri)
                : $request->{$verb}($uri, $body);

            $this->assertSame(
                403,
                $response->getStatusCode(),
                $name . ': ' . strtoupper($verb) . ' ' . $uri . ' answered '
                . $response->getStatusCode() . ' to a foreign merchant.' . "\n"
                . '  403 is the required answer. 401 is the DANGEROUS one -- repo B treats any '
                . '401 as a dead session, clears preferences and force-logs-out.' . "\n"
                . '  A 2xx means the ownership predicate is gone and this is a cross-tenant read '
                . 'or write.' . "\n"
                . '  A 422 means this case no longer reaches the ownership check at all and is '
                . 'testing nothing -- fix the body, do not relax the assertion.' . "\n"
                . '  A 405 means the verb here no longer matches routes/api.php.'
            );
        }
    }

    /**
     * THE OWNER STILL GETS THROUGH AT EVERY ONE OF THEM. Without this, the negative above is
     * satisfied by a predicate that refuses everybody -- which is precisely the staff lockout
     * this change exists to repair, re-created at a larger scale.
     *
     * The assertion is "not 403", deliberately and not lazily: these fifteen endpoints answer a
     * legitimate owner with 200, 404, 422 or (where GATE F bites) 500 depending on what else they
     * need, and the property under test here is AUTHORIZATION PASSED, not what the handler then
     * did. The endpoint-specific positives above pin the handler behaviour for the two that
     * matter most.
     */
    public function test_the_owning_merchant_is_not_refused_at_any_rewired_admin_endpoint(): void
    {
        $this->makePlatformAdmin();

        [, $store, $token] = $this->tenantWithToken();

        foreach ($this->rewiredEndpoints() as $name => [$verb, $uri, $body]) {
            $uri = str_replace('{store}', (string) $store->id, $uri);
            $body = array_map(fn ($v) => $v === '{store}' ? $store->id : $v, $body);

            $request = $this->withHeader('Authorization', 'Bearer ' . $token);

            $response = $verb === 'getJson'
                ? $request->getJson($uri)
                : $request->{$verb}($uri, $body);

            $this->assertNotSame(
                403,
                $response->getStatusCode(),
                $name . ': the store OWNER was refused their OWN store at ' . strtoupper($verb)
                . ' ' . $uri . '. Whatever tightened this predicate has broken the primary '
                . 'workflow, which is worse than the staff lockout it replaced.'
            );
        }
    }

    /**
     * A WRITE endpoint, asserted on the DATABASE and not only on the status code. AddCustomer
     * inserts into `customers` with the request's store_id, so before the guard a foreign
     * merchant could plant a customer row -- and a credential -- inside someone else's store.
     *
     * addCustomer validates the BODY before the ownership check, so the body is fully valid here;
     * with a bad body this would 422 and prove nothing.
     */
    public function test_a_foreign_merchant_cannot_plant_a_customer_in_another_stores_tenant(): void
    {
        $this->makePlatformAdmin();

        [, $victimStore] = $this->tenantWithToken();
        [, , $attackerToken] = $this->tenantWithToken();

        $email = 'planted-' . uniqid() . '@example.test';

        $response = $this->withHeader('Authorization', 'Bearer ' . $attackerToken)
            ->postJson('/api/admin/AddCustomer?store_id=' . $victimStore->id, [
                'first_name' => 'Planted',
                'last_name' => 'Customer',
                'email' => $email,
                'mobile' => '+905551234567',
                'password' => 'Passw0rd1',
                'status' => 1,
            ]);

        // THE ROW FIRST. A raw query builder read on purpose: Customer is in
        // config/tenancy.php -> scoped_models, so an Eloquent read here would be narrowed by
        // StoreScope and could report "absent" for a row that exists.
        $this->assertSame(
            0,
            DB::table('customers')->where('email', $email)->count(),
            'CROSS-TENANT WRITE: a foreign merchant created a customer -- with a password -- '
            . 'inside another merchant\'s store.'
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * A DELETE endpoint, also asserted on the database. destroyCustomer validates
     * `customer_id => exists:customers,id` BEFORE the ownership check, so the victim's customer
     * has to really exist for this request to reach the guard at all.
     *
     * The fixture row is inserted with the query builder rather than Customer::create() because
     * Customer carries BelongsToStore and is allowlisted in config/tenancy.php, so an Eloquent
     * insert/read would be rewritten by StoreScope -- and `exists:customers,id` is itself an
     * unscoped query, which is why the validation passes for a foreign store's row.
     */
    public function test_a_foreign_merchant_cannot_delete_a_customer_from_another_stores_tenant(): void
    {
        $this->makePlatformAdmin();

        [, $victimStore] = $this->tenantWithToken();
        [, , $attackerToken] = $this->tenantWithToken();

        $customerId = (int) DB::table('customers')->insertGetId([
            'first_name' => 'Victim',
            'last_name' => 'Customer',
            'email' => 'victim-' . uniqid() . '@example.test',
            'status' => 1,
            'store_id' => $victimStore->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $attackerToken)
            ->deleteJson(
                '/api/admin/destroyCustomer?store_id=' . $victimStore->id
                . '&customer_id=' . $customerId
            );

        $this->assertSame(
            1,
            DB::table('customers')->where('id', $customerId)->whereNull('deleted_at')->count(),
            'CROSS-TENANT DELETION: a foreign merchant deleted a customer out of another '
            . 'merchant\'s store.'
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * getCustomerOrdersApi reads one customer's whole order history and lifetime spend. It
     * validates `id => exists:customers,id` (unscoped) before the ownership check, so the
     * victim's customer must exist for the request to reach the guard.
     */
    public function test_a_foreign_merchant_cannot_read_another_stores_customer_order_history(): void
    {
        $this->makePlatformAdmin();

        [, $victimStore] = $this->tenantWithToken();
        [, , $attackerToken] = $this->tenantWithToken();

        $customerId = (int) DB::table('customers')->insertGetId([
            'first_name' => 'Victim',
            'last_name' => 'Customer',
            'email' => 'victim-' . uniqid() . '@example.test',
            'status' => 1,
            'store_id' => $victimStore->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $attackerToken)
            ->getJson(
                '/api/admin/customer/orders?store_id=' . $victimStore->id . '&id=' . $customerId
            );

        $this->assertSame(403, $response->getStatusCode(),
            'A foreign merchant read another store\'s customer order history and lifetime spend.');
    }

    // -----------------------------------------------------------------------------------------
    // THE STRUCTURAL HALF. Rewritten to match on the SHAPE of the defect rather than on one
    // variable name, because the previous spelling-specific regex reported 11 when the real
    // number was 20.
    // -----------------------------------------------------------------------------------------

    /**
     * Strip comments before scanning. Several docblocks in that file QUOTE the old broken
     * expressions as the thing being described, and matching those would both over-count and
     * pressure someone into deleting the explanation. PHP's own lexer is the only reliable way
     * to find where a comment ends.
     */
    private function dashboardControllerCode(): string
    {
        $source = (string) file_get_contents(
            app_path('Http/Controllers/Api/DashboardController.php')
        );

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? str_repeat("\n", substr_count($token[1], "\n"))
                    : $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    public function test_no_admin_endpoint_scopes_a_store_by_anything_but_the_callers_tenant(): void
    {
        $code = $this->dashboardControllerCode();

        // ANY Store lookup narrowed by a created_by, whatever the id expression is spelled as --
        // $adminUser->id, $user->id, $userId, $validated['store_id'] keyed, or something new.
        // creatorId() is the correct predicate and is excluded by the negative lookahead.
        $occurrences = preg_match_all(
            '/Store::where\s*\(\s*\'id\'\s*,\s*[^)]+\)\s*(?:\r?\n\s*)?'
            . '->\s*where\s*\(\s*\'created_by\'\s*,\s*(?!\$\w+->creatorId\(\))[^)]+\)/',
            $code,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        $this->assertSame(
            self::SITES_SCOPED_BY_ACTOR_ID,
            $occurrences,
            "Expected EXACTLY " . self::SITES_SCOPED_BY_ACTOR_ID . ' Store lookup narrowed by '
            . "something other than the caller's tenant (the deliberate one in DeleteStore), "
            . "found {$occurrences}.\n\n"
            . "MORE: an endpoint has gone back to inlining its own ownership predicate. The whole "
            . "point of this change is that resolveOwnedStore() (:3772) carries that decision for "
            . "every admin endpoint: it uses \$user->creatorId(), so staff reach their employer's "
            . "stores, and it returns the REAL Store to a platform operator instead of a null that "
            . "the next line dereferences.\n"
            . "This scan is spelling-agnostic ON PURPOSE. Its predecessor matched only "
            . "`\$adminUser->id` and reported 11 while the identical defect was also written with "
            . "`\$user->id` (6 sites), `\$userId` (1) and keyed on `\$validated['store_id']` (2) -- "
            . "20 in total.\n"
            . "FEWER: DeleteStore has been changed too. Decide deliberately whether a staff member "
            . "should be able to irreversibly destroy their employer's store and all its content; "
            . "if yes, lower this number."
        );

        // AND THE SURVIVOR IS THE ONE WE MEAN. Without this, the count alone could be satisfied
        // by repairing DeleteStore and introducing a fresh bypass somewhere else.
        $deleteStoreStart = strpos($code, 'function DeleteStore');
        $this->assertNotFalse($deleteStoreStart, 'DeleteStore has moved or been renamed.');

        $nextFunction = strpos($code, ' function ', $deleteStoreStart + 10);
        $this->assertNotFalse($nextFunction);

        foreach ($matches[0] as [$matchText, $offset]) {
            $this->assertGreaterThan($deleteStoreStart, $offset,
                'An actor-id-scoped store lookup exists OUTSIDE DeleteStore: ' . $matchText);
            $this->assertLessThan($nextFunction, $offset,
                'An actor-id-scoped store lookup exists OUTSIDE DeleteStore: ' . $matchText);
        }
    }

    /**
     * The helper really is the mechanism now. A count rather than a spot check, so that
     * "routed through resolveOwnedStore()" cannot quietly become "routed through it in three
     * places and hand-rolled in the rest".
     */
    public function test_resolve_owned_store_carries_the_tenancy_decision_for_the_whole_admin_api(): void
    {
        $code = $this->dashboardControllerCode();

        // MEASURED, both sides of the change, on comment-stripped source: 74 occurrences before
        // (1 declaration + 73 calls) and 94 after -- exactly the +20 sites this change rewired.
        // Comment-stripped is what makes the number trustworthy: the prose in that file mentions
        // the helper by name several times.
        $this->assertGreaterThanOrEqual(
            94,
            substr_count($code, 'resolveOwnedStore('),
            'resolveOwnedStore() is called less than the 94 occurrences measured after this '
            . 'change (74 before it). Either call sites were deleted or some went back to '
            . 'inlining a predicate.'
        );

        // The predicate itself, pinned: tenant-scoped via creatorId(), and the platform-operator
        // limb normalised the way PlatformOperatorOnly and PlanOrderPolicy normalise it rather
        // than compared case-sensitively.
        $this->assertStringContainsString("where('created_by', \$user->creatorId())", $code,
            'resolveOwnedStore() must narrow by the TENANT, not by the actor row.');
        $this->assertStringContainsString(
            "strtolower(trim((string) \$user->type)) !== 'super admin'",
            $code,
            'resolveOwnedStore() must normalise `type` before comparing it, like '
            . 'PlatformOperatorOnly::handle() and PlanOrderPolicy::isPlatformAdmin(). This helper '
            . 'now carries the tenancy decision for over a hundred call sites.'
        );
    }

    /**
     * The sibling helper in StoreController must not drift from the one in the admin-API
     * controller: they are the same predicate for the same purpose on the two surfaces.
     */
    public function test_the_store_controller_helper_uses_the_same_normalised_predicate(): void
    {
        $source = (string) file_get_contents(app_path('Http/Controllers/StoreController.php'));

        $start = strpos($source, 'function resolveOwnedStore');
        $this->assertNotFalse($start, 'StoreController::resolveOwnedStore has moved or been renamed.');

        $body = substr($source, $start, 2000);

        $this->assertStringContainsString("where('created_by', \$user->creatorId())", $body);
        $this->assertStringContainsString(
            "strtolower(trim((string) \$user->type)) !== 'super admin'",
            $body,
            'StoreController::resolveOwnedStore() compared `type` with exact case while every '
            . 'other type predicate written for this phase normalises first. Keep the two '
            . 'resolveOwnedStore() helpers identical.'
        );
    }
}
