<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * "STORE OWNERSHIP CHECKS" -- THE INDEPENDENT VERDICT, MEASURED END TO END.
 * =============================================================================================
 *
 * WHAT WAS ASKED. Establish the current state of the store-ownership checks on the admin API
 * with concrete evidence. The headline answer is that the CROSS-TENANT BOUNDARY HOLDS: a
 * merchant cannot reach another merchant's store through either endpoint tested here, and that
 * is the security property. It is asserted below and must stay asserted through any fix.
 *
 * BUT TWO SITES ARE WRONG IN THE OTHER DIRECTION, and reading the code was not enough to find
 * them -- both were measured by issuing the requests. 80 call sites in
 * app/Http/Controllers/Api/DashboardController.php resolve the store through
 * resolveOwnedStore() (:3772-3787), whose predicate is
 *
 *     Store::where('id', $storeId)->where('created_by', $user->creatorId())
 *
 * and creatorId() (app/Models/User.php:95-105) returns the OWNER's id for a staff user. Two
 * sites do not use that helper and inline a DIFFERENT predicate:
 *
 *     app/Http/Controllers/Api/DashboardController.php:5200-5201   CustomerList
 *     app/Http/Controllers/Api/DashboardController.php:6042-6043   searchProductsByName
 *         Store::where('id', $storeId)->where('created_by', $adminUser->id)
 *                                                            ^^^^ not creatorId()
 *
 * DEFECT 1 -- STAFF ARE LOCKED OUT OF THEIR OWN MERCHANT'S STORE. For a staff user,
 * `$adminUser->id` is the staff row's own id while the store's created_by is the MERCHANT's, so
 * the lookup finds nothing and the request is refused. MEASURED, with a real token:
 *     STAFF, own merchant's store, POST searchProductsByName -> 403 "You do not have permission
 *                                                                   to access this store."
 *     STAFF, own merchant's store, GET  customerlist          -> 403 (same message)
 * It fails CLOSED, so it is not a vulnerability -- it is a legitimate workflow being denied,
 * which is the failure mode this phase has already caused twice by tightening a predicate
 * without checking who it excludes.
 *
 * DEFECT 2 -- THE SUPER-ADMIN ESCAPE HATCH IS A 500, NOT AN ESCAPE. searchProductsByName writes
 * the guard as
 *     if (!$store && $adminUser->type !== 'super admin') { return 403; }
 * so a platform operator looking at a store they did not create falls THROUGH the guard with
 * $store still null, and the next statement is
 *     Product::where('store_id', $store->id)        // :6068
 * MEASURED: HTTP 500, `Attempt to read property "id" on null`, at
 * DashboardController.php:6068. CustomerList has the identical guard shape but then queries by
 * $storeId rather than $store->id (:5216), so it does NOT crash -- which is why reading the two
 * as "the same pattern" would have missed this, and why both are exercised separately here.
 *
 * NEITHER IS FIXED HERE. app/ is not this workstream's to edit; both are reported as cross-agent
 * dependencies with these line numbers. The correct fix for Defect 1 is creatorId(), NOT dropping
 * the predicate -- hence the cross-tenant negatives below, which any fix must keep green.
 *
 * THE TWO DEFECT ASSERTIONS REDDEN WHEN THE DEFECTS ARE FIXED. That is deliberate and their
 * failure messages say exactly what to do. The alternative -- leaving the finding in prose only
 * -- is how the last cycle's "already fixed" claims turned out to be wrong.
 */
class ApiStoreOwnershipPredicateTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * MEASURED, and the number is the finding. The first draft of this file asserted 2 -- the two
     * endpoints that had been read -- and the scan returned 11. Eleven admin endpoints scope the
     * store by the ACTOR's own id instead of their tenant's, so eleven of them refuse a staff
     * user their own merchant's store. That is the difference between reading two call sites and
     * scanning all of them, and it is the third time in this project that a narrow scan
     * under-reported.
     */
    private const SITES_SCOPED_BY_ACTOR_ID = 11;

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
     */
    public function test_the_denial_is_403_and_never_401(): void
    {
        $this->makePlatformAdmin();

        [, $victimStore] = $this->tenantWithToken();
        [, , $attackerToken] = $this->tenantWithToken();

        foreach ([
            '/api/admin/customerlist?store_id=' . $victimStore->id,
            '/api/admin/searchProductsByName?store_id=' . $victimStore->id,
        ] as $uri) {
            $status = $this->withHeader('Authorization', 'Bearer ' . $attackerToken)
                ->getJson($uri)
                ->getStatusCode();

            $this->assertNotSame(
                401,
                $status,
                $uri . ' answers 401 to an AUTHENTICATED caller who is merely not allowed. '
                . 'repo B treats any 401 as "the session is dead" and clears preferences, so this '
                . 'denial would log the merchant out of the admin app. It must be 403.'
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
    // DEFECT 1 -- STAFF LOCKED OUT. Pinned; reddens when fixed.
    // -----------------------------------------------------------------------------------------

    public function test_staff_are_currently_refused_their_own_merchants_store(): void
    {
        $this->makePlatformAdmin();

        [$merchant, $store] = $this->makeTenant();

        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);
        $staffToken = $staff->createToken('auth_token')->plainTextToken;

        // Fixture sanity: the staff row must really belong to the merchant that owns the store,
        // or this test would be measuring an ordinary cross-tenant denial and proving nothing.
        $this->assertSame((int) $merchant->id, (int) $staff->created_by);
        $this->assertSame((int) $merchant->id, (int) $store->created_by);
        $this->assertSame(
            (int) $merchant->id,
            (int) $staff->creatorId(),
            'creatorId() must resolve a staff user to its owning merchant, or the diagnosis below '
            . 'is wrong about why these two sites refuse.'
        );

        $message = 'NEW-OWNERSHIP-STAFF-LOCKOUT-01 has been fixed -- staff now reach their own '
            . "merchant's store. Delete this test and "
            . 'test_staff_are_currently_refused_their_own_merchants_customer_list, and check that '
            . 'the cross-tenant negatives at the top of this file are still green: the fix must be '
            . 'creatorId() (DashboardController.php:5201 and :6043), not removing the predicate.';

        $this->assertSame(
            403,
            $this->withHeader('Authorization', 'Bearer ' . $staffToken)
                ->postJson('/api/admin/searchProductsByName?store_id=' . $store->id, ['name' => 'abc'])
                ->getStatusCode(),
            $message
        );
    }

    public function test_staff_are_currently_refused_their_own_merchants_customer_list(): void
    {
        $this->makePlatformAdmin();

        [$merchant, $store] = $this->makeTenant();

        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);
        $staffToken = $staff->createToken('auth_token')->plainTextToken;

        $this->assertSame(
            403,
            $this->withHeader('Authorization', 'Bearer ' . $staffToken)
                ->getJson('/api/admin/customerlist?store_id=' . $store->id)
                ->getStatusCode(),
            'NEW-OWNERSHIP-STAFF-LOCKOUT-01 has been fixed at CustomerList '
            . '(DashboardController.php:5201). Delete this test and keep the cross-tenant negative.'
        );
    }

    // -----------------------------------------------------------------------------------------
    // DEFECT 2 -- THE SUPER-ADMIN HATCH CRASHES. Pinned; reddens when fixed.
    // -----------------------------------------------------------------------------------------

    public function test_a_platform_operator_currently_gets_a_500_on_a_store_it_did_not_create(): void
    {
        $admin = $this->makePlatformAdmin();
        $adminToken = $admin->createToken('auth_token')->plainTextToken;

        [, $store] = $this->makeTenant();

        $this->assertNotSame(
            (int) $admin->id,
            (int) $store->created_by,
            'fixture: the operator must NOT be the store creator, or the broken branch is not taken.'
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/admin/searchProductsByName?store_id=' . $store->id, ['name' => 'abc']);

        $this->assertSame(
            500,
            $response->getStatusCode(),
            'NEW-OWNERSHIP-SUPERADMIN-500-01 has been fixed. The guard at '
            . 'DashboardController.php:6046 used to let a super admin past with $store still null '
            . 'and then dereference it at :6068. Delete this test; keep the cross-tenant negatives.'
        );

        // Not just "a 500": the specific null dereference, at the specific line. A different 500
        // here would be a different defect wearing this one's status code -- and one of the
        // sibling endpoints (Top_orders) really does answer 500 for a DIFFERENT reason, so this
        // distinction is load-bearing rather than pedantic.
        //
        // Asserted through the decoded JSON, not the raw body: the response is JSON, so the
        // quotes around "id" arrive escaped as \" and a raw assertStringContainsString() for
        // `property "id" on null` fails against a body that contains exactly that message. That
        // is a trap this test fell into on its first run.
        $response->assertJsonPath('message', 'Attempt to read property "id" on null');
        $response->assertJsonPath('line', 6068);
    }

    /**
     * CustomerList has the SAME guard shape and does NOT crash, because it queries by $storeId
     * rather than $store->id. Asserted so the two are never "fixed" as one pattern, and so that a
     * future refactor of CustomerList to use $store->id is caught the moment it lands.
     */
    public function test_the_customer_list_does_not_share_the_null_dereference(): void
    {
        $admin = $this->makePlatformAdmin();
        $adminToken = $admin->createToken('auth_token')->plainTextToken;

        [, $store] = $this->makeTenant();

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/admin/customerlist?store_id=' . $store->id)
            ->assertStatus(200);
    }

    /**
     * TWO MORE SITES LOOKED LIKE DEFECT 2 TO A STATIC READING, AND THE MEASUREMENT SPLIT THEM.
     * Recorded as tests so neither classification rests on my reading of the code.
     *
     * searchCustomerByName (:8033) dereferences $store at :8077 -- but as `$store->name ?? ''`,
     * and `??` has isset() semantics, so a null $store yields '' with no error. REFUTED: measured
     * 200 for a platform operator on a foreign store. A scan that counts `$store->` uses would
     * have filed this alongside :6068 and been wrong.
     */
    public function test_the_customer_search_is_not_a_second_null_dereference(): void
    {
        $admin = $this->makePlatformAdmin();
        $adminToken = $admin->createToken('auth_token')->plainTextToken;

        [, $store] = $this->makeTenant();

        $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->postJson('/api/admin/searchCustomerByName?store_id=' . $store->id, ['name' => 'abcd'])
            ->assertStatus(200)
            // The empty store_name is the `?? ''` doing its work, and is the evidence that the
            // null $store really did reach that expression -- i.e. the guard was fallen through,
            // and only the null-coalesce stopped it being a 500.
            ->assertJsonPath('data.store_name', '');
    }

    /**
     * Top_orders (:6111) has the identical guard and dereferences $store at :6139, so it is a
     * candidate for the same 500 -- but it CANNOT BE VERIFIED IN THIS ENVIRONMENT. The platform
     * operator's request dies earlier, on `no such table: currency_rates`, which is one of the
     * six GATE F money tables that no migration declares. So the status code is 500 for a reason
     * that is NOT the null dereference, and asserting 500 here would be asserting the wrong
     * defect.
     *
     * Classified BLOCKED -- EXTERNAL EVIDENCE REQUIRED, and asserted as such: the test pins the
     * GATE F blocker by name, so when the schema finally lands this reddens and the null
     * dereference becomes measurable for the first time.
     */
    public function test_the_top_orders_null_dereference_is_blocked_behind_gate_f(): void
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
            . 'opened, re-run this endpoint as a platform operator and find out whether the null '
            . 'dereference at DashboardController.php:6139 is real -- it has never been reachable '
            . 'here. Then replace this test with whatever the answer is.'
        );
    }

    // -----------------------------------------------------------------------------------------
    // THE STRUCTURAL HALF. Pins the inconsistency itself, so the two inline predicates cannot be
    // copied to a third site without this reddening.
    // -----------------------------------------------------------------------------------------
    public function test_the_set_of_sites_scoping_a_store_by_the_actors_own_id_has_not_grown(): void
    {
        $path = app_path('Http/Controllers/Api/DashboardController.php');
        $source = (string) file_get_contents($path);

        // Strip comments: four of them quote the old broken expressions as the thing being
        // described, and matching those would both over-count and pressure someone into deleting
        // the explanation. PHP's own lexer is the only reliable way to find where a comment ends.
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

        $occurrences = preg_match_all(
            "/Store::where\s*\(\s*'id'\s*,\s*\\\$storeId\s*\)\s*(?:\r?\n\s*)?->\s*where\s*\(\s*'created_by'\s*,\s*\\\$adminUser->id\s*\)/",
            $code,
            $matches
        );

        $this->assertSame(
            self::SITES_SCOPED_BY_ACTOR_ID,
            $occurrences,
            "Expected EXACTLY " . self::SITES_SCOPED_BY_ACTOR_ID . " store lookups scoped by "
            . "\$adminUser->id rather than \$adminUser->creatorId(), found {$occurrences}.\n\n"
            . "THE ELEVEN, measured by this same scan with the enclosing function resolved from "
            . "the line number:\n"
            . "    :5200 CustomerList            :5346 destroy               :5430 store\n"
            . "    :5740 getActivePaymentMethods :6042 searchProductsByName  :6111 Top_orders\n"
            . "    :6901 updateCurrencyApi       :7207 getCustomerOrdersApi  :7415 addCustomer\n"
            . "    :7534 updateStoreOwnerInfo    :8033 searchCustomerByName\n\n"
            . "MORE: the broken predicate has been copied to another endpoint, locking staff out "
            . "of something else. Use resolveOwnedStore() (:3772), which gets this right at 80 "
            . "call sites.\n"
            . "FEWER: one has been repaired. Good -- lower this number in the same commit, and if "
            . "it was CustomerList or searchProductsByName delete the matching pinned test above."
        );
    }
}
