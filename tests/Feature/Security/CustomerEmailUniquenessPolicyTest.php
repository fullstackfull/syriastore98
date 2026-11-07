<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * THE APPROVED CUSTOMER E-MAIL UNIQUENESS POLICY, ENFORCED AT THE APPLICATION LEVEL.
 * =================================================================================================
 *
 * OWNER DECISION, 2026-11-07 (approved in principle), in the owner's own three clauses:
 *   1. the same e-mail MAY exist in different stores;
 *   2. two ACTIVE customers with the same e-mail in ONE store MUST NOT be allowed;
 *   3. ARCHIVED customers MAY re-register safely.
 *
 * Together those are one rule: **(email, store_id) is unique over LIVE rows only.**
 *
 * The owner's condition on implementing it was *"after completing the tenant-authorization review
 * and confirming that the payment and storefront lookups are properly store-scoped"*. Both are
 * done and both are evidenced elsewhere rather than asserted here: four rounds of
 * `tenant-authz-reviewer` on this slice, and `UnscopedCustomerEmailLookupTest` for the two lookups.
 *
 * THE TWO LOOKUPS ARE NOT EQUALLY LIVE, and flattening them would be an overclaim in the summary
 * the owner reads. `PaymentController.php:2095-2096` (guest checkout) was a **cross-tenant WRITE**:
 * the `else` branch wrote `last_active` to the row it found and returned it as the order's
 * customer, and on an anonymous storefront request no tenant resolves so `StoreScope` adds nothing.
 * That is a live repair. `HomeController.php:1229-1230` (order status) is **latent**: the same
 * shape, but `orders` has no `email` column, so `$order->email` is always null and the lookup never
 * runs. `UnscopedCustomerEmailLookupTest` establishes that by measurement and says so outright —
 * the predicate added there fixes nothing reachable, and is consistency rather than a fix. Both
 * now carry `store_id`; only one of them was doing damage.
 *
 * WHAT THIS FILE COVERS — the THREE MERCHANT WRITE PATHS, which previously disagreed with each
 * other on BOTH axes of the same operation:
 *
 *   | path                                         | per-store? | archived-aware? |
 *   |----------------------------------------------|------------|-----------------|
 *   | `POST /customer` (merchant web form)         | NO, global | yes             |
 *   | `POST api/admin/AddCustomer` (admin app)     | yes        | NO              |
 *   | `POST api/admin/update-customer` (admin app) | yes        | NO              |
 *
 * So the web form and the shipped Flutter app gave OPPOSITE answers to the same question, which is
 * precisely what CLAUDE.md's second standing principle (Flutter parity) forbids. After this change
 * all three read `(email, store_id)` over live rows, and the parity test at the end asserts that
 * the web and API answers agree rather than trusting that they do.
 *
 * WHAT THIS FILE DELIBERATELY DOES **NOT** TOUCH, and why it is a separate owner decision:
 *   * `Api\AuthController:76` — `unique:customers,email`, GLOBAL, on the ROUTED and PUBLIC
 *     `POST api/{slug}/register`. Scoping it would change who may create a storefront login
 *     credential, and the owner's instruction in the same message is *"No silent payment
 *     behavior or authentication policy changes."*
 *   * `Customer\Auth\CustomerLoginController` registration existence check — soft-delete-blind, so
 *     an archived shopper is told to log in and cannot (pinned by
 *     `StorefrontArchivedCustomerDeadEndTest`). Same reason: a storefront authentication path.
 *   * `AccountProfileController:127` and `:183` — a shopper editing their OWN address. These are
 *     per-store but archived-INSENSITIVE, which is STRICTER than the policy, i.e. they fail in the
 *     safe direction. Loosening them lets a live shopper take an address an archived account holds,
 *     and if that account were ever restored the store would hold two live rows with one address —
 *     the state clause 2 forbids. There is no restore route today, so this is a latent rather than
 *     a live inconsistency, and it is recorded for the owner rather than changed here.
 *
 * NO SCHEMA CHANGE IS MADE OR IMPLIED. The owner's message is explicit — *"Database migration is
 * NOT yet authorized."* The database has no unique index on `customers.email` at all, so every
 * assertion below is enforced by validation and validation alone, which is check-then-act and
 * therefore racy. That race is NOT concealed: it is measured in
 * `tests/Support/Security/bin/verify-customer-email-uniqueness-on-mysql.php` section D on the real
 * production engine version, and the one test at the end of this file states it in the suite too.
 */
class CustomerEmailUniquenessPolicyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;
    private Store $secondStore;
    private User $otherMerchant;
    private Store $otherStore;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->secondStore = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->grantPermission(User::find($this->merchant->id), 'Manage Customer');
        $this->merchant = User::find($this->merchant->id);
        $this->token = $this->merchant->createToken('auth_token')->plainTextToken;

        $this->otherMerchant = $this->makeMerchant();
        $this->otherStore = $this->makeStore($this->otherMerchant);
    }

    /** Through withoutGlobalScopes: Customer carries StoreScope AND SoftDeletingScope, so a plain
     *  find() answers against whichever tenant happens to be resolved, not against the table. */
    private static function rows()
    {
        return Customer::withoutGlobalScopes();
    }

    private function customerIn(Store $store, string $email, bool $archived = false): Customer
    {
        $customer = Customer::forceCreate([
            'first_name' => 'Existing', 'last_name' => 'Shopper',
            'email' => $email, 'password' => bcrypt('known-secret'),
            'store_id' => $store->id, 'created_by' => $store->created_by,
            'status' => 1,
        ]);

        if ($archived) {
            Customer::withoutGlobalScopes()->where('id', $customer->id)->delete();
            $customer = self::rows()->find($customer->id);
        }

        return $customer;
    }

    private function webPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Rania',
            'last_name' => 'Aswad',
            'email' => 'rania@example.test',
            'mobile' => '555000222',
            'password' => 'a-long-enough-password',
            'status' => '1',
        ], $overrides);
    }

    /** The AddCustomer endpoint's password regex is /^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d@$!%*?&]{8,}$/
     *  — a hyphen is NOT in that class, so the web payload's password would fail it. */
    private function apiPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Rania',
            'last_name' => 'Aswad',
            'email' => 'rania@example.test',
            'mobile' => '555000222',
            'password' => 'Passw0rdLong',
            'status' => '1',
        ], $overrides);
    }

    private function postWeb(array $payload)
    {
        return $this->actingAs($this->merchant)->post('/customer', $payload);
    }

    private function postApiAdd(int $storeId, array $payload)
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/AddCustomer?store_id=' . $storeId, $payload);
    }

    private function postApiUpdate(int $storeId, array $payload)
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/update-customer?store_id=' . $storeId, $payload);
    }

    // =============================================================================================
    //  PREMISES — so a later failure cannot be read as the policy when it is the fixture
    // =============================================================================================

    /**
     * ASKED BY COLUMN, NOT BY NAME. The first version of this filtered the index list for a name
     * containing "email", which answers *"is there an index whose NAME mentions email"* — a
     * different question. An inline `CREATE TABLE … unique` yields `sqlite_autoindex_customers_2`,
     * and any hand-named index escapes too, so the premise could not detect its own condition in
     * either form. Raised by the tenant-authz review of this change.
     *
     * AND THIS PREMISE CARRIES THE RACE, which is the thing the owner asked not be concealed.
     * There is no unique index on `customers.email` anywhere — no migration declares one, and the
     * live 133-table schema has only (billable_id, billable_type) and (store_id). So clause 2 is
     * enforced by `Rule::unique`, which is a SELECT followed by an INSERT: two requests that
     * interleave between the two both see "free" and both insert. A single-process test cannot
     * simulate that, and an `assertTrue(true)` pretending to is worse than saying so here — the
     * first draft of this file had exactly that, and the review called it the one row of fifteen
     * that proved nothing. What DOES measure it is
     * `tests/Support/Security/bin/verify-customer-email-uniqueness-on-mysql.php` section D, on
     * MariaDB 11.4.9 — production's own engine — where exactly one of two racing inserts survives
     * WITH the index. The migration is not authorised, so the window stays open, and this
     * assertion is what will fail on the day it closes.
     */
    public function test_premise_the_database_has_no_unique_index_on_customer_email(): void
    {
        $indexed = [];

        foreach (\DB::select("pragma index_list('customers')") as $index) {
            $columns = collect(\DB::select('select name from pragma_index_info(?)', [$index->name]))
                ->pluck('name')
                ->map(fn ($column) => strtolower((string) $column))
                ->all();

            if (in_array('email', $columns, true)) {
                $indexed[] = $index->name;
            }
        }

        $this->assertSame([], $indexed,
            'an index now covers `customers.email` (' . implode(', ', $indexed) . '). Every '
            . 'assertion in this file is about VALIDATION, so if the schema has gained a '
            . 'constraint the reasoning here needs re-reading — and no migration is authorised. '
            . 'If this is the approved uniqueness index, the race described above is closed and '
            . 'every writer must now handle a duplicate-key QueryException.');
    }

    public function test_premise_the_two_stores_belong_to_the_same_merchant_and_the_third_does_not(): void
    {
        $this->assertSame($this->merchant->id, (int) $this->store->created_by);
        $this->assertSame($this->merchant->id, (int) $this->secondStore->created_by);
        $this->assertNotSame($this->merchant->id, (int) $this->otherStore->created_by,
            'the third store must belong to a DIFFERENT merchant or the cross-store tests prove '
            . 'nothing about tenancy');
    }

    // =============================================================================================
    //  CLAUSE 1 — the same address MAY exist in different stores
    // =============================================================================================

    public function test_an_address_live_in_another_merchants_store_may_now_be_created_here(): void
    {
        $theirs = $this->customerIn($this->otherStore, 'shared@example.test');

        // THE FIXTURE'S OWN STORE IS ASSERTED, not assumed. Without this the test relies on
        // `BelongsToStore` not auto-populating `store_id` (BelongsToStore.php:104-106); if that
        // ever changed, the fixture would land with a null or different store, the web create
        // would succeed, and this test would pass while proving nothing about cross-store at all.
        // Raised by the tenant-authz review of this change.
        $this->assertSame((int) $this->otherStore->id, (int) self::rows()->find($theirs->id)->store_id,
            'premise: the fixture customer really is in the OTHER merchant\'s store');

        $this->postWeb($this->webPayload(['email' => 'shared@example.test']))
            ->assertSessionHasNoErrors();

        $mine = self::rows()->where('store_id', $this->store->id)
            ->where('email', 'shared@example.test')->get();

        $this->assertCount(1, $mine,
            'CLAUSE 1 of the approved policy: the same e-mail may exist in different stores. The '
            . 'rule on CustomerController::store() is still global, so a merchant cannot add a '
            . 'customer whose address is live in ANOTHER merchant\'s store');
    }

    public function test_that_create_does_not_touch_the_other_stores_account(): void
    {
        $theirs = $this->customerIn($this->otherStore, 'shared@example.test');
        $theirPassword = $theirs->password;

        $this->postWeb($this->webPayload(['email' => 'shared@example.test']));

        $after = self::rows()->find($theirs->id);

        $this->assertNotNull($after, "the other store's customer row disappeared");
        $this->assertSame((int) $this->otherStore->id, (int) $after->store_id,
            "the other store's customer was MOVED into this merchant's store — an adoption, not a "
            . 'create');
        $this->assertSame($theirPassword, $after->password,
            "the other store's customer password was rewritten by a create in a different store");
        $this->assertSame('Existing', $after->first_name,
            "the other store's customer record was overwritten rather than left alone");
    }

    public function test_the_store_comes_from_the_authenticated_principal_not_from_request_input(): void
    {
        // The single most productive defect pattern in this codebase is a handler that derives its
        // target from request input. The new predicate must come from getCurrentStore(), so naming
        // another store in the body must change nothing at all.
        $this->postWeb($this->webPayload([
            'email' => 'input@example.test',
            'store_id' => $this->otherStore->id,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(0, self::rows()->where('store_id', $this->otherStore->id)
            ->where('email', 'input@example.test')->count(),
            'a store_id in the REQUEST BODY placed the new customer in another merchant\'s store');
        $this->assertSame(1, self::rows()->where('store_id', $this->store->id)
            ->where('email', 'input@example.test')->count(),
            'the customer did not land in the authenticated merchant\'s own store');
    }

    /**
     * AND THE TEST ABOVE CANNOT DETECT THE THING IT IS NAMED FOR, which the adversarial review of
     * this change caught. Where the new row LANDS is decided by `'store_id' => $store` in the
     * `Customer::create()` call, not by the uniqueness rule's predicate. So if the RULE were built
     * from `$request->store_id`, every assertion above would still pass.
     *
     * THIS one discriminates, because it makes the two stores give OPPOSITE answers: a live row
     * holding the address exists in the OTHER merchant's store, and that store's id is what the
     * body names. A rule reading the body finds the duplicate and refuses; a rule reading the
     * principal's store finds nothing and accepts. The test above keeps its place as the
     * where-did-it-land half; this is the which-store-did-the-RULE-ask half.
     */
    public function test_the_uniqueness_RULE_reads_the_principals_store_not_the_posted_one(): void
    {
        $this->customerIn($this->otherStore, 'ruleinput@example.test');

        $this->postWeb($this->webPayload([
            'email' => 'ruleinput@example.test',
            'store_id' => $this->otherStore->id,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, self::rows()->where('store_id', $this->store->id)
            ->where('email', 'ruleinput@example.test')->count(),
            'the uniqueness rule refused an address that is live only in the store named by the '
            . 'REQUEST BODY — so the predicate is built from caller-supplied input, which is the '
            . 'defect pattern that has produced four separate faults in this codebase');
    }

    public function test_the_api_allows_the_same_address_in_a_second_store_the_caller_owns(): void
    {
        $this->customerIn($this->store, 'twostores@example.test');

        $this->postApiAdd($this->secondStore->id, $this->apiPayload(['email' => 'twostores@example.test']))
            ->assertStatus(201);

        $this->assertSame(1, self::rows()->where('store_id', $this->secondStore->id)
            ->where('email', 'twostores@example.test')->count(),
            'the admin app refused an address that is live only in the merchant\'s OTHER store');
    }

    // =============================================================================================
    //  CLAUSE 2 — two ACTIVE customers with one address in ONE store MUST NOT be allowed
    // =============================================================================================

    public function test_a_live_duplicate_in_the_same_store_is_still_refused_on_the_web_form(): void
    {
        $this->customerIn($this->store, 'nadia@example.test');

        $this->postWeb($this->webPayload(['email' => 'nadia@example.test']))
            ->assertSessionHasErrors('email');

        $this->assertSame(1, self::rows()->where('store_id', $this->store->id)
            ->where('email', 'nadia@example.test')->count(),
            'CLAUSE 2 of the approved policy was broken: scoping the rule per store removed it '
            . 'rather than narrowing it, and one store now holds two live rows for one address');
    }

    public function test_a_live_duplicate_in_the_same_store_is_still_refused_on_the_api(): void
    {
        $this->customerIn($this->store, 'nadia@example.test');

        $this->postApiAdd($this->store->id, $this->apiPayload(['email' => 'nadia@example.test']))
            ->assertStatus(422);

        $this->assertSame(1, self::rows()->where('store_id', $this->store->id)
            ->where('email', 'nadia@example.test')->count(),
            'the admin app created a second LIVE row for one address in one store');
    }

    public function test_an_update_onto_a_live_address_held_by_another_customer_here_is_refused(): void
    {
        $held = $this->customerIn($this->store, 'held@example.test');
        $mover = $this->customerIn($this->store, 'mover@example.test');

        $this->postApiUpdate($this->store->id, [
            'customer_id' => $mover->id,
            'email' => 'held@example.test',
        ])->assertStatus(422);

        $this->assertSame('mover@example.test', (string) self::rows()->find($mover->id)->email,
            'an update moved one customer onto an address another LIVE customer in the same store '
            . 'already holds');
        $this->assertSame('held@example.test', (string) self::rows()->find($held->id)->email);
    }

    // =============================================================================================
    //  CLAUSE 3 — ARCHIVED customers may re-register safely
    // =============================================================================================

    public function test_an_archived_address_may_be_reused_on_the_web_form(): void
    {
        $archived = $this->customerIn($this->store, 'gone@example.test', archived: true);
        $this->assertNotNull($archived->deleted_at, 'premise: the fixture row is not archived');

        $this->postWeb($this->webPayload(['email' => 'gone@example.test']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, self::rows()->where('store_id', $this->store->id)
            ->where('email', 'gone@example.test')->whereNull('deleted_at')->count(),
            'CLAUSE 3: an archived address stays reserved on the web form');
    }

    public function test_an_archived_address_may_be_reused_on_the_api(): void
    {
        $this->customerIn($this->store, 'gone@example.test', archived: true);

        $this->postApiAdd($this->store->id, $this->apiPayload(['email' => 'gone@example.test']))
            ->assertStatus(201);

        $this->assertSame(1, self::rows()->where('store_id', $this->store->id)
            ->where('email', 'gone@example.test')->whereNull('deleted_at')->count(),
            'CLAUSE 3 on the admin app: `Rule::unique` has no deleted_at predicate, so the admin '
            . 'app still reserves an archived address for ever while the web form does not');
    }

    public function test_an_update_onto_an_address_only_an_archived_row_holds_is_allowed(): void
    {
        $this->customerIn($this->store, 'gone@example.test', archived: true);
        $mover = $this->customerIn($this->store, 'mover@example.test');

        $this->postApiUpdate($this->store->id, [
            'customer_id' => $mover->id,
            'email' => 'gone@example.test',
        ])->assertStatus(200);

        $this->assertSame('gone@example.test', (string) self::rows()->find($mover->id)->email,
            'an archived row blocked a LIVE customer from taking that address on update-customer');
    }

    public function test_the_archived_row_itself_is_left_in_place_by_the_re_registration(): void
    {
        $archived = $this->customerIn($this->store, 'gone@example.test', archived: true);

        $this->postWeb($this->webPayload(['email' => 'gone@example.test']));

        $still = self::rows()->find($archived->id);
        $this->assertNotNull($still, 'the archived row was deleted by the re-registration');
        $this->assertNotNull($still->deleted_at, 'the archived row was silently REVIVED');
        $this->assertSame(2, self::rows()->where('store_id', $this->store->id)
            ->where('email', 'gone@example.test')->count(),
            'the store should now hold exactly two rows for that address: one archived, one live');
    }

    // =============================================================================================
    //  PARITY — the whole point of changing all three together
    // =============================================================================================

    /**
     * THE OUTCOME IS CAPTURED BEFORE THE SECOND REQUEST IS ISSUED, and the first draft did not do
     * that. It read `session()->has('errors')` AFTER `postApiAdd()`, i.e. from global session state
     * an intervening request had had a chance to age. It was safe only because the `api` group has
     * no `StartSession`, so nothing aged the flashed bag — a property of another file's middleware
     * stack, not of this test. In a (web refuses, api accepts) combination that would have been a
     * FALSE PASS. Its first disjunct was dead too: both outcomes of `store()` are redirects.
     * Raised by the tenant-authz review of this change.
     */
    private function webAccepted(\Illuminate\Testing\TestResponse $response, string $email): bool
    {
        // ASK THE TABLE, NOT THE SESSION -- and the session version was a real hole, found by the
        // adversarial review of this change. `store()` has FOUR refusal paths and only ONE of them
        // flashes `errors`:
        //
        //   :52  permission denied          -> ->with('error', ...)      singular
        //   :84  operator named no store    -> ->with('error', ...)      singular
        //   :90  empty $store               -> ->with('error', ...)      singular
        //        validation failure         -> ->withErrors(...)         plural, via Laravel
        //
        // `! has('errors')` is therefore TRUE for three refusals that create nothing, so both
        // parity tests could have reported "the web form accepted" while the form refused. The row
        // is the thing the parity claim is actually about, so the row is what is asked.
        return self::rows()->where('store_id', $this->store->id)
            ->where('email', $email)
            ->whereNull('deleted_at')
            ->exists();
    }

    public function test_the_web_form_and_the_admin_app_agree_about_an_ARCHIVED_address(): void
    {
        // Same question, asked of both surfaces in two separate stores of the same merchant so
        // neither answer can be influenced by the other's row.
        $this->customerIn($this->store, 'parity@example.test', archived: true);
        $this->customerIn($this->secondStore, 'parity@example.test', archived: true);

        $webAccepted = $this->webAccepted(
            $this->postWeb($this->webPayload(['email' => 'parity@example.test'])),
            'parity@example.test'
        );
        $apiAccepted = $this->postApiAdd(
            $this->secondStore->id,
            $this->apiPayload(['email' => 'parity@example.test'])
        )->getStatusCode() === 201;

        // DIRECTION, NOT JUST AGREEMENT. `assertSame($api, $web)` alone is satisfied by BOTH
        // surfaces refusing, which is agreement about the wrong answer. Clause 3 of the approved
        // policy says an archived address may be reused, so the assertion names the value.
        // Raised by the adversarial review of this change.
        $this->assertTrue($webAccepted && $apiAccepted,
            'clause 3 of the approved policy says an ARCHIVED address may be reused, and the two '
            . 'surfaces must BOTH accept it. web accepted=' . var_export($webAccepted, true)
            . ', api accepted=' . var_export($apiAccepted, true)
            . ' — CLAUDE.md\'s second standing principle is Flutter parity');
    }

    /**
     * THE SECOND PARITY AXIS, and without it the test above does not cover the web form's own
     * defect. The pre-change web rule was global but LIVE-ONLY, so it accepted an archived address
     * too — meaning the archived case alone passes whether or not the per-store predicate exists.
     * This one uses a LIVE row in a store the caller does not own, which is the axis the file's own
     * table calls the web form's defect. Raised by the tenant-authz review of this change.
     */
    public function test_the_web_form_and_the_admin_app_agree_about_ANOTHER_STORES_LIVE_address(): void
    {
        $this->customerIn($this->otherStore, 'crossparity@example.test');

        // EACH SURFACE IS ASKED ABOUT A DIFFERENT STORE, and the first draft of this test was not.
        // It asked both about `$this->store`, so the web create landed first and the API's refusal
        // was clause 2 working correctly on the row the web form had just made — a real answer to
        // the wrong question. Found by running it. The web form always writes to the merchant's
        // `current_store`, so the API is pointed at the merchant's SECOND store instead, and
        // neither answer can be influenced by the other's row.
        $webAccepted = $this->webAccepted(
            $this->postWeb($this->webPayload(['email' => 'crossparity@example.test'])),
            'crossparity@example.test'
        );
        $apiAccepted = $this->postApiAdd(
            $this->secondStore->id,
            $this->apiPayload(['email' => 'crossparity@example.test'])
        )->getStatusCode() === 201;

        $this->assertTrue($webAccepted && $apiAccepted,
            'the two surfaces disagree about an address that is live only in a store the caller '
            . 'does NOT own. Clause 1 of the approved policy says both must accept it. '
            . 'web accepted=' . var_export($webAccepted, true)
            . ', api accepted=' . var_export($apiAccepted, true));
    }

}
