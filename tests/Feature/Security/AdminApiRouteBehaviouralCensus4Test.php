<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * A BEHAVIOURAL PERMISSION CENSUS OF 31 `api/admin/*` ROUTES — CENSUS GROUP 4.
 * =================================================================================================
 *
 * WHY THIS IS A TEST AND NOT A DOCUMENT, AND WHY IT DOES NOT GREP.
 *
 * The same 31 routes were censused once by scanning the controllers for refusal-shaped helper
 * names. That reading was WRONG: three of three spot checks were false positives, and the total
 * moved every time the list of helper names widened — because these controllers spell a refusal
 * 22 different ways (`canFinance`, `callerMayActInStore`, `refuseWithoutDeliveryPermission`,
 * `refuseUnlessMayAdministerStoreSettings`, `mayChangeOwnerIdentity`,
 * `OperatorCatalogueWrite::refuses`, `VariantRemovalPolicy::authorize`, a bare `Gate::allows`, and
 * so on). A name census cannot tell a gate from a comment about a gate, nor a gate that runs from
 * one that sits below an unreachable branch.
 *
 * So every row below is MEASURED by driving the route with a real Sanctum bearer token, three
 * times, and reading the answer AND the database:
 *
 *   1. UNPERMISSIONED STAFF OF THE OWNING STORE — `makeStaff()` with `current_store` set and NO
 *      `grantPermission()` call. This is the principal that decides the verdict. If the route
 *      answers success to them, it is UNGATED IN FACT whatever the source says.
 *   2. THE STORE'S OWN OWNER — the positive control. A gate that refuses the owner too is a
 *      lockout, not a gate, and is reported as such.
 *   3. A MERCHANT OF A DIFFERENT TENANT — must be refused, or tenancy is broken.
 *
 * -------------------------------------------------------------------------------------------------
 * THE FOUR TRAPS THAT MAKE THIS KIND OF MEASUREMENT READ BACKWARDS, AND WHAT IS DONE ABOUT EACH.
 *
 * (a) `ApiResponser::error()` ANSWERS HTTP 200 (`app/Traits/ApiResponser.php:54`) with
 *     `{status: 0}`, and its THIRD argument is the code — so `error([...], 404)` puts `404` in the
 *     *message* field and still sends 200. **A 200 is therefore not evidence of admission.**
 *     `admitted()` below reads the ENVELOPE (`status`/`success`), never the code alone, and every
 *     write route additionally reads the row back through the query builder.
 *
 * (b) A `422` raised by a validator that runs ABOVE the gate is not a refusal BY the gate — it is
 *     `VALIDATOR_FIRST`. No row's verdict here rests on one, because every fixture supplies a
 *     valid `store_id`; the shape is measured separately, at the bottom of this list.
 *
 * (c) A 404/500 for want of a fixture is not "gated". Those rows are `INCONCLUSIVE_FIXTURE` with
 *     the reason named, because an honest inconclusive is worth more than a guessed verdict.
 *
 * (d) ELEVEN models really do filter by tenant (`Customer`, `Cart`, `DeliveryBoy`, `Menu`,
 *     `SupportTicket`, `SupportConversion`, `FlashSaleCondition`, `ThemeCustomizeOrder`,
 *     `NotifyUser`, `OrderRefund`, `OrderNote`) and 79 of 95 models are `Cachable`. So an "it was
 *     not written" assertion made through `Model::find()` answers against whichever tenant
 *     happened to resolve, out of an array cache that survives the rest of the test. **Every row
 *     read in this file goes through `DB::table()`**, which is the only read that is a statement
 *     about the table.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THE MEASUREMENT FOUND. 31 routes driven; each row is pinned by its own method below.
 *
 * GATED BY A PERMISSION — unpermissioned staff refused, and the owner not refused. 19 routes.
 * ON 15 OF THEM the SAME staff member is then driven again holding the name the gate asks for and
 * is ADMITTED: that counter-measurement is what separates a real gate from a fixture that merely
 * missed, and it is why these verdicts are not inferences. The four without it name their reason:
 * the two wallet routes isolate the permission on the OWNER instead (measured refused without the
 * grant, admitted with it, because `canFinance()` has no owner limb at all), and `stripe-payment`
 * and `updateCurrencyApi` cannot run past their gate under this suite at all — see their
 * docblocks. 19 routes:
 *
 *   orders/notes/create · orders/update-status · searchCustomerByName · searchProductsByName
 *   searchdata · status-change · stripe-payment · testmail · update-customer · updateCurrencyApi
 *   updatecoupon · updateshipping · updatetax · updatevariant · toggle-currency-status
 *   update-currency-rate · deliveryboysUpdate/{id} · wallet/add-balance · wallet/deduct-balance
 *
 * REFUSED, BUT NOT BY A PERMISSION — 2 routes. Nothing a merchant can delegate makes either work
 * for staff, so neither is a hole, and neither is a gate either:
 *   register-device    — `auth('deliveryboy')` only. Refuses EVERY panel principal, owner included.
 *   store/fcm-token    — `Store::where('created_by', $user->id)`, so staff miss by id, not by right.
 *
 * NO PERMISSION GATE, CONTROLLED BY SOMETHING ELSE — 1 route:
 *   updatepassword     — tenancy (`resolveManageableUser()`) plus knowledge of the current
 *                        password, and NOTHING else. MEASURED, all three limbs: staff may change
 *                        their OWN credential (legitimate); the OWNER's row is refused by the
 *                        tenancy predicate (so this is not an account takeover); and **a
 *                        COLLEAGUE's row IS reachable** — a sibling staff member's `created_by` is
 *                        the same tenant root — so a lateral credential write inside the tenant is
 *                        held back only by the `old_password` check, with no permission consulted.
 *
 * **UNGATED IN FACT — 9 routes. Unpermissioned staff ADMITTED and the write landed (or the data
 * was served).** Each method PINS TODAY'S BEHAVIOUR so this file is green, and every one says in
 * its docblock that it is A MEASUREMENT AWAITING A FIX, not an endorsement — whoever adds the gate
 * INVERTS the marked assertion:
 *
 *   updateproduct        price, sale_price, COST_PRICE, product_stock   ← the worst row in the group
 *   searchproduct        serves every product column, cost_price included
 *   updatereview         public review text, rating, status
 *   reviewstatus         publishes / unpublishes any review
 *   updatecategory       name, slug, images, parent, trending, status
 *   updateproductbrand   name, slug, logo, status
 *   updateproductlable   name, status (and rewrites store_id from `current_store`)
 *   updateStoreLanguage  stores.default_language — what every shopper reads
 *   updateStoreOwnerInfo stores.name — SPLIT ROW: the owner-identity fields ARE gated (403), the
 *                        store profile is not. One verdict would have hidden half of it.
 *
 * `VALIDATOR_FIRST` — no row's VERDICT rests on one, because every fixture here supplies a valid
 * `store_id` so that the gate is what gets measured. But the shape exists on five routes and it
 * leaks: see `test_the_store_id_validator_above_the_gate_distinguishes_absent_from_unowned`.
 */
class AdminApiRouteBehaviouralCensus4Test extends SecurityTestCase
{
    use RefreshDatabase;

    /** Distinctive, so an "it was written" / "it was not written" read cannot pass by accident. */
    private const ATTACKER_MARK = 'CENSUS4-WRITTEN-BY-UNPERMISSIONED-STAFF';

    protected function setUp(): void
    {
        parent::setUp();

        // `testmail` and `stripe-payment` reach a mailer / an uploader; nothing here is allowed to
        // leave the process.
        Mail::fake();

        /*
         * THE PLATFORM OPERATOR MUST EXIST FIRST, AND THIS IS NOT COSMETIC — IT COST A FALSE
         * READING IN THIS VERY FILE.
         *
         * `makeMerchant()` writes `created_by => 1`, which in production is the operator's id. On a
         * fresh `RefreshDatabase` with no operator row, the FIRST merchant created IS user id 1 —
         * so `$merchant->created_by (1) === $merchant->id (1)`, and the merchant became their own
         * tenant root by accident.
         *
         * MEASURED CONSEQUENCE: `test_updatepassword_POST` reported that an unpermissioned staff
         * member had successfully changed THE OWNER'S PASSWORD — an account takeover — because
         * `resolveManageableUser()` compares `$target->created_by` against
         * `UserPolicy::merchantTenantRoot($actor)`, and both happened to be the integer 1. With a
         * real operator row at id 1 the merchant is id 2+ and the comparison separates them, which
         * is the production shape. This is exactly the `created_by = 1` trap CLAUDE.md records:
         * `created_by = 1` is NOT a tenant.
         *
         * `Cache::forget('admin_details')` because several handlers read the platform admin
         * through `Cache::remember('admin_details', 3600, …)` and would otherwise serve the
         * pre-fixture (absent) answer for the rest of the test.
         */
        $this->makePlatformAdmin();
        \Illuminate\Support\Facades\Cache::forget('admin_details');
    }

    // ═════════════════════════════════════════════════════════════════════════════════════ HARNESS

    /** @return array{0:User,1:Store} the owning merchant and their store */
    private function owningTenant(): array
    {
        return $this->makeTenant();
    }

    /**
     * A MEMBER OF THE OWNING STORE WHO HOLDS NOTHING.
     *
     * `current_store` matters: `getCurrentStore()` reads it off the `web`-guard user, and three of
     * the handlers in this group (`orders/update-status`, `updateproductbrand`,
     * `updateproductlable`) take the store from there rather than from the request.
     */
    private function staffIn(User $owner, Store $store, ?string $permission = null): User
    {
        $staff = $this->makeStaff($owner);
        $staff->current_store = $store->id;
        $staff->save();

        if ($permission !== null) {
            $this->grantPermission(User::find($staff->id), $permission);
        }

        return User::find($staff->id);
    }

    /** @return array{0:User,1:Store} a merchant of a DIFFERENT tenant, with their own store */
    private function foreignTenant(): array
    {
        return $this->makeTenant();
    }

    private function as(User $user)
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . $user->createToken('census4')->plainTextToken
        );
    }

    /**
     * THE GLOBAL `admin` ROLE CARRYING A PERMISSION — IDEMPOTENTLY.
     *
     * `SecurityTestCase::grantMerchantGlobalRole()` calls `givePermission()` unconditionally, and
     * the global role has `store_id = null` so it is SHARED by every self-registered merchant.
     * Granting the same name twice (once for the owner, once for the foreign merchant) therefore
     * violated `permission_role`'s unique key — three tests died on it before this existed.
     *
     * The sharing is the production shape and it is what makes the cross-tenant limbs below
     * meaningful: the foreign merchant genuinely HOLDS the permission and is refused anyway,
     * because the refusal is about the STORE.
     */
    private function withGlobalPermissions(User $user, array $permissions): User
    {
        $role = \App\Models\Role::where('name', 'admin')->whereNull('store_id')->first()
            ?: $this->makeGlobalAdminRole();

        foreach ($permissions as $name) {
            $permission = \App\Models\Permission::firstOrCreate(
                ['name' => $name],
                ['display_name' => $name]
            );

            $alreadyGranted = DB::table('permission_role')
                ->where('permission_id', $permission->id)
                ->where('role_id', $role->id)
                ->exists();

            if (! $alreadyGranted) {
                $role->givePermission($permission);
            }
        }

        if (! $user->hasRole($role->name)) {
            $user->addRole($role);
        }

        $user->flushCache();

        return User::find($user->id);
    }

    /**
     * WAS THE CALLER ADMITTED? Read from the envelope, never from the status code.
     *
     * Trap (a): `error()` is HTTP 200 with `{status: 0}`, and `error([...], 404)` is HTTP 200 with
     * the string `404` in `message`. The success envelopes in play are `{status: 1}` (ApiResponser),
     * `{status: 'success'}` (DeliveryBoyApiController) and `{success: true}` (`orders/update-status`).
     */
    private function admitted($response): bool
    {
        if ($response->status() < 200 || $response->status() >= 300) {
            return false;
        }

        $body = $response->json();

        if (!is_array($body)) {
            return false;
        }

        if (array_key_exists('success', $body)) {
            return $body['success'] === true;
        }

        return in_array($body['status'] ?? null, [1, '1', true, 'success'], true);
    }

    /** The whole answer in one line, so a failure message says what actually happened. */
    private function describe($response): string
    {
        return $response->status() . ' ' . substr((string) $response->getContent(), 0, 400);
    }

    private function assertRefused($response, string $what): void
    {
        $this->assertFalse(
            $this->admitted($response),
            $what . ' was ADMITTED, and this census row says it is refused: ' . $this->describe($response)
        );
    }

    private function assertAdmitted($response, string $what): void
    {
        $this->assertTrue(
            $this->admitted($response),
            $what . ' was REFUSED, so this row proves nothing about the gate: ' . $this->describe($response)
        );
    }

    // ══════════════════════════════════════════════════════════════════════════════════ FIXTURES

    private function categoryIn(Store $store, string $name = 'census4-cat'): int
    {
        return (int) DB::table('categories')->insertGetId([
            'name' => $name,
            'slug' => 'collections/' . $name . '-' . $store->id,
            'status' => 1,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function productIn(Store $store, array $overrides = []): int
    {
        return (int) DB::table('products')->insertGetId(array_merge([
            'name' => 'census4-product',
            'slug' => 'census4-product-' . $store->id . '-' . $this->uniqueFixtureRef(),
            'category_id' => $this->categoryIn($store),
            'description' => 'fixture',
            'price' => 100.00,
            'sale_price' => 90.00,
            'cost_price' => 40.00,
            'product_stock' => 50,
            // NOT NULL on this schema and declared by no default, so the insert fails without it.
            'track_stock' => 0,
            'trending' => 0,
            'variant_product' => 0,
            'status' => 1,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function testimonialIn(Store $store, array $overrides = []): int
    {
        return (int) DB::table('testimonials')->insertGetId(array_merge([
            'title' => 'census4-review',
            'description' => 'original review text',
            // `testimonials.category_id` DEFAULTS TO 0 and carries a foreign key to `categories`,
            // so omitting it inserts 0 and the FK fails. NULL is the only safe omission.
            'category_id' => null,
            'rating_no' => 5,
            'status' => 1,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function customerIn(Store $store): int
    {
        return (int) DB::table('customers')->insertGetId([
            'first_name' => 'Census',
            'last_name' => 'Shopper',
            'email' => 'census4-' . $this->uniqueFixtureRef() . '@example.test',
            'mobile' => '0100000000',
            'password' => bcrypt('x'),
            'status' => 1,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function deliveryBoyIn(Store $store): int
    {
        return (int) DB::table('delivery_boys')->insertGetId([
            'name' => 'Census Driver',
            'email' => 'driver-' . $this->uniqueFixtureRef() . '@example.test',
            'password' => bcrypt('x'),
            'contact' => '0111111111',
            'commission_percentage' => 5,
            'is_commission_enabled' => 1,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function currencyRateIn(Store $store, string $code = 'SYP', array $overrides = []): int
    {
        return (int) DB::table('currency_rates')->insertGetId(array_merge([
            'store_id' => $store->id,
            'currency_code' => $code,
            'currency_name' => 'Census ' . $code,
            'symbol' => '£',
            'rate_per_usd' => 10.0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function walletIn(Store $store, float $balance = 500.0): int
    {
        return (int) DB::table('store_wallets')->insertGetId([
            'store_id' => $store->id,
            'currency_code' => 'USD',
            'available_balance' => $balance,
            'pending_balance' => 0,
            'withdrawal_pending_balance' => 0,
            'total_income' => $balance,
            'total_withdrawn' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function settingIn(Store $store, string $name, string $value): void
    {
        DB::table('settings')->insert([
            'name' => $name,
            'value' => $value,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ══════════════════════════════════════════════════════════ 0. `VALIDATOR_FIRST`, AND AN ORACLE

    /**
     * `VALIDATOR_FIRST` — THE ONE SHAPE IN THIS GROUP WHERE A VALIDATOR DECIDES BEFORE ANY GATE,
     * AND IT LEAKS WHETHER A STORE EXISTS.
     *
     * Five routes here (`searchCustomerByName`, `searchProductsByName`, `updateCurrencyApi`,
     * `toggle-currency-status`, `update-currency-rate`) validate
     * `'store_id' => 'required|integer|exists:stores,id'` ABOVE `resolveOwnedStore()` and above the
     * permission gate. That is not a refusal BY the gate, so no verdict in this census rests on
     * it — every row above supplies a valid `store_id` so that the gate is the thing being
     * measured.
     *
     * BUT THE ORDERING IS ITSELF A FINDING. `resolveOwnedStore()`'s own docblock records a
     * "deliberately non-oracle posture": an unknown id and an unowned id must answer the SAME
     * refusal, so a caller cannot learn which stores exist. The `exists:` rule above it defeats
     * that — a store id that does not exist answers **422**, and one that exists but belongs to
     * someone else answers **403**. Two distinguishable answers, so any authenticated token can
     * enumerate the platform's store ids a few hundred requests at a time.
     *
     * Reported, not fixed: this file may not edit a handler. It is measured here because a census
     * that only recorded "GATED" would have lost it.
     */
    public function test_the_store_id_validator_above_the_gate_distinguishes_absent_from_unowned(): void
    {
        [, $store] = $this->owningTenant();
        [$foreign] = $this->foreignTenant();

        // An id no store holds. `max(id) + 1000` rather than a literal, so a growing fixture set
        // cannot make this collide with a real row.
        $absent = ((int) DB::table('stores')->max('id')) + 1000;

        foreach ([
            '/api/admin/searchCustomerByName' => 'postJson',
            '/api/admin/searchProductsByName' => 'postJson',
        ] as $uri => $verb) {
            $unowned = $this->as($foreign)->{$verb}($uri . '?store_id=' . $store->id, ['name' => 'x']);
            $missing = $this->as($foreign)->{$verb}($uri . '?store_id=' . $absent, ['name' => 'x']);

            $this->assertRefused($unowned, $uri . ' with a real store the caller does not own');
            $this->assertRefused($missing, $uri . ' with a store id that does not exist');

            $this->assertSame(403, $unowned->status(), $uri . ': an unowned store no longer answers 403.');
            $this->assertSame(422, $missing->status(), $uri . ': an absent store no longer answers 422.');
            $this->assertNotSame(
                $unowned->status(),
                $missing->status(),
                $uri . ' now answers identically for an absent and an unowned store. That is the '
                . 'non-oracle posture resolveOwnedStore() documents, so if this fails the leak has '
                . 'been FIXED — delete this test rather than restoring the difference.'
            );
        }
    }

    // ═══════════════════════════════════════════════════════════════════════ 1. orders/notes/create

    /**
     * GATED. `AddOrderNote` → `resolveOwnedStore()` then
     * `callerMayActInStore(['Create Order Note'])`. 403 for unpermissioned staff and no row.
     */
    public function test_orders_notes_create_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $order = $this->makeOrder($store);
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u) => $this->as($u)->postJson('/api/admin/orders/notes/create', [
            'store_id' => $store->id,
            'order_id' => $order->id,
            'note' => self::ATTACKER_MARK,
            'note_type' => 'to_customer',
        ]);

        $staffResponse = $call($staff);
        $this->assertRefused($staffResponse, 'unpermissioned staff');
        $this->assertSame(403, $staffResponse->status());
        $this->assertSame(
            0,
            DB::table('order_notes')->where('notes', self::ATTACKER_MARK)->count(),
            'NO WRITE must have landed for unpermissioned staff.'
        );

        $this->assertAdmitted($call($merchant), 'the store owner');
        $this->assertSame(1, DB::table('order_notes')->where('notes', self::ATTACKER_MARK)->count());

        $this->assertRefused($call($foreign), 'a foreign-tenant merchant');

        // THE REFUSAL IS THE PERMISSION, NOT THE FIXTURE.
        $this->assertAdmitted(
            $call($this->staffIn($merchant, $store, 'Create Order Note')),
            'staff GRANTED Create Order Note'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════ 2. orders/update-status

    /**
     * GATED, via a POLICY rather than a helper — `Gate::forUser($actor)->allows('updateStatus',
     * $order)` → `OrderPolicy::updateStatus()` → `isAbleTo('Manage Order')`.
     *
     * THE OWNER NEEDS THE GRANT HERE AND A NAME CENSUS COULD NOT HAVE SEEN THAT.
     * `OrderPolicy::allow()` has an operator limb and a Laratrust limb and NO store-owner limb, so
     * a merchant who holds no role is refused their own order. In production every self-registered
     * merchant carries the global `admin` role, which `Utility` grants `Manage Order`; the owner
     * limb below is therefore driven through `grantMerchantGlobalRole()`, which is what production
     * actually looks like.
     */
    public function test_orders_update_status_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $order = $this->makeOrder($store, ['delivered_status' => 0]);
        $staff = $this->staffIn($merchant, $store);
        [$foreign, $foreignStore] = $this->foreignTenant();

        $call = fn (User $u) => $this->as($u)->postJson('/api/admin/orders/update-status', [
            'product_order_id' => $order->product_order_id,
            'delivered_status' => 4,
        ]);

        $staffResponse = $call($staff);
        $this->assertRefused($staffResponse, 'unpermissioned staff');
        $this->assertSame(403, $staffResponse->status());
        $this->assertSame(
            0,
            (int) DB::table('orders')->where('id', $order->id)->value('delivered_status'),
            'the status must be unchanged for unpermissioned staff.'
        );

        $owner = $this->withGlobalPermissions(User::find($merchant->id), ['Manage Order']);
        $this->assertAdmitted($call($owner), 'the store owner holding Manage Order');
        $this->assertSame(4, (int) DB::table('orders')->where('id', $order->id)->value('delivered_status'));

        // The foreign merchant's own `current_store` is their own, so `getCurrentStore()` scopes the
        // lookup away from this order: a 404, which is a REFUSAL of the write either way.
        $foreignResponse = $this->as($this->withGlobalPermissions(User::find($foreign->id), ['Manage Order']))
            ->postJson('/api/admin/orders/update-status', [
                'product_order_id' => $order->product_order_id,
                'delivered_status' => 2,
            ]);
        $this->assertRefused($foreignResponse, 'a foreign-tenant merchant');
        $this->assertSame(4, (int) DB::table('orders')->where('id', $order->id)->value('delivered_status'));
        $this->assertNotNull($foreignStore->id);

        /* THE REFUSAL IS THE PERMISSION, NOT THE FIXTURE: the same call, the same fixture, the
           same store — by a staff member who has been GRANTED the name the gate asks for. */
        $this->assertAdmitted(
            $this->as($this->staffIn($merchant, $store, 'Manage Order'))
                ->postJson('/api/admin/orders/update-status', [
                    'product_order_id' => $order->product_order_id,
                    'delivered_status' => 6,
                ]),
            'staff GRANTED Manage Order'
        );
        $this->assertSame(6, (int) DB::table('orders')->where('id', $order->id)->value('delivered_status'));
    }

    // ══════════════════════════════════════════════════════════════════════════════ 3. register-device

    /**
     * GATED BY PRINCIPAL CLASS, NOT BY PERMISSION — and the OWNER IS REFUSED TOO, which is the
     * finding rather than a defect.
     *
     * `DeliveryBoyNotificationController::registerDevice()` reads `auth('deliveryboy')->user()`.
     * `custom.auth` logs a token's owner into the guard that matches its TOKENABLE CLASS, so a
     * `User` token (merchant or staff) resolves in `web` and leaves `deliveryboy` null — every
     * panel principal is refused. The route sits in the `admin` prefix but is a DRIVER route.
     *
     * THE REFUSAL IS **HTTP 200**, NOT 401, AND THE SOURCE READS AS IF IT WERE 401. See the
     * in-body comment: the handler writes `error(['message' => __('Unauthorized')], 401)`, and
     * `error()`'s second parameter is the MESSAGE. The literal `401` is served as the message of
     * a 200.
     *
     * No permission is consulted at all, so there is nothing a merchant could delegate — which
     * matters only if a panel client ever needs to call it. Nothing in either repository does.
     */
    public function test_register_device_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u) => $this->as($u)->postJson('/api/admin/register-device', [
            'fcm_token' => 'census4-token',
            'device_id' => 'census4-device',
            'platform' => 'android',
        ]);

        foreach (['unpermissioned staff' => $staff, 'the store owner' => $merchant, 'a foreign merchant' => $foreign] as $label => $user) {
            $response = $call($user);
            $this->assertRefused($response, $label);

            /*
             * TRAP (a), AT ITS MOST MISLEADING. The handler writes
             * `$this->error(['message' => __('Unauthorized')], 401)` — but `error()`'s SECOND
             * parameter is the MESSAGE and its THIRD is the status, so the literal `401` lands in
             * the `message` field and the response goes out as HTTP **200**. Measured below. A
             * census that read the status code would have scored this route 200 = admitted; a
             * census that read `message` would have scored it 401. Only the envelope is true.
             */
            $this->assertSame(200, $response->status(), $label . ' no longer gets the HTTP 200 this route answers.');
            $this->assertSame(0, $response->json('status'));
            $this->assertSame('401', (string) $response->json('message'),
                'the "401" that this route puts in the MESSAGE field has moved: ' . $this->describe($response));
        }

        $this->assertSame(
            0,
            DB::table('delivery_boy_devices')->count(),
            'no device row may be written by a panel principal.'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════════════ 4. reviewstatus

    /**
     * **UNGATED IN FACT — A MEASUREMENT AWAITING A FIX, NOT AN ENDORSEMENT.**
     *
     * `ReviewStatus()` is `resolveOwnedStore($request->store_id)` and then a bare
     * `Testimonial::where('id',...)->where('store_id', $store->id)` followed by a status TOGGLE and
     * `save()`. There is no permission check of any kind, and `resolveOwnedStore()` admits the
     * owner's STAFF by design (`creatorId()` resolves staff to their employer). So any member of
     * the tenant can publish or unpublish any review in the store.
     *
     * The web twin gates the equivalent action; the API route does not. The assertions below pin
     * TODAY'S behaviour so the suite stays green, and must be INVERTED by whoever adds the gate.
     */
    public function test_reviewstatus_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $reviewId = $this->testimonialIn($store, ['status' => 1]);
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u) => $this->as($u)->postJson('/api/admin/reviewstatus', [
            'store_id' => $store->id,
            'id' => $reviewId,
        ]);

        // MEASURED: admitted, and the toggle landed.
        $staffResponse = $call($staff);
        $this->assertAdmitted($staffResponse, 'unpermissioned staff (UNGATED — pinned, awaiting a fix)');
        $this->assertSame(
            0,
            (int) DB::table('testimonials')->where('id', $reviewId)->value('status'),
            'UNGATED: the review was unpublished by a staff member holding nothing.'
        );

        $this->assertAdmitted($call($merchant), 'the store owner');
        $this->assertSame(1, (int) DB::table('testimonials')->where('id', $reviewId)->value('status'));

        $this->assertRefused($call($foreign), 'a foreign-tenant merchant');
        $this->assertSame(1, (int) DB::table('testimonials')->where('id', $reviewId)->value('status'));
    }

    // ═══════════════════════════════════════════════════════════════════════ 5. searchCustomerByName

    /**
     * GATED — `callerMayActInStore(['Manage Customer'])`, 403, and NO customer PII served.
     */
    public function test_searchCustomerByName_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $customerId = $this->customerIn($store);
        $email = (string) DB::table('customers')->where('id', $customerId)->value('email');
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u) => $this->as($u)
            ->postJson('/api/admin/searchCustomerByName?store_id=' . $store->id, ['name' => 'Census']);

        $staffResponse = $call($staff);
        $this->assertRefused($staffResponse, 'unpermissioned staff');
        $this->assertSame(403, $staffResponse->status());
        $this->assertStringNotContainsString(
            $email,
            (string) $staffResponse->getContent(),
            'PII LEAK: the customer e-mail reached a staff member holding no customer right.'
        );

        $this->assertAdmitted($call($merchant), 'the store owner');
        $this->assertRefused($call($foreign), 'a foreign-tenant merchant');

        /* THE REFUSAL IS THE PERMISSION, NOT THE FIXTURE: the same call, the same fixture, the
           same store — by a staff member who has been GRANTED the name the gate asks for. */
        $permitted = $call($this->staffIn($merchant, $store, 'Manage Customer'));
        $this->assertAdmitted($permitted, 'staff GRANTED Manage Customer');
        $this->assertStringContainsString(
            $email,
            (string) $permitted->getContent(),
            'the grant admits them but the payload no longer carries the customer e-mail, so the '
            . 'PII assertion above was not measuring a WITHHELD field at all.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════ 6. searchProductsByName

    /** GATED — `callerMayActInStore(['Manage Products'])`, 403, and `cost_price` withheld. */
    public function test_searchProductsByName_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $this->productIn($store, ['name' => 'census4-margin-product', 'cost_price' => 4242.42]);
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u) => $this->as($u)
            ->postJson('/api/admin/searchProductsByName?store_id=' . $store->id, ['name' => 'census4']);

        $staffResponse = $call($staff);
        $this->assertRefused($staffResponse, 'unpermissioned staff');
        $this->assertSame(403, $staffResponse->status());
        $this->assertStringNotContainsString(
            '4242.42',
            (string) $staffResponse->getContent(),
            'MARGIN LEAK: cost_price reached a staff member holding no product right.'
        );

        $this->assertAdmitted($call($merchant), 'the store owner');
        $this->assertRefused($call($foreign), 'a foreign-tenant merchant');

        /* THE REFUSAL IS THE PERMISSION, NOT THE FIXTURE: the same call, the same fixture, the
           same store — by a staff member who has been GRANTED the name the gate asks for. */
        $permitted = $call($this->staffIn($merchant, $store, 'Manage Products'));
        $this->assertAdmitted($permitted, 'staff GRANTED Manage Products');
        $this->assertStringContainsString(
            '4242.42',
            (string) $permitted->getContent(),
            'the grant admits them but cost_price is absent even then, so the margin assertion '
            . 'above was not measuring a WITHHELD field at all.'
        );
    }

    // ═════════════════════════════════════════════════════════════════════════════════ 7. searchdata

    /**
     * GATED ON THE `order` BRANCH — `callerMayActInStore(['Manage Order','Show Order'])`, 403, and
     * `final_price` withheld. The `category` branch is deliberately left open (GAP-142 scoped the
     * row to orders) and that asymmetry is pinned by `AdminApiSearchDataPermissionTest`, not here.
     */
    public function test_searchdata_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $this->makeOrder($store, ['final_price' => 4242.42]);
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u) => $this->as($u)->postJson('/api/admin/searchdata', [
            'store_id' => $store->id,
            'type' => 'order',
            'name' => '',
        ]);

        $staffResponse = $call($staff);
        $this->assertRefused($staffResponse, 'unpermissioned staff');
        $this->assertSame(403, $staffResponse->status());
        $this->assertStringNotContainsString(
            '4242.42',
            (string) $staffResponse->getContent(),
            'MONEY LEAK: final_price reached a staff member holding no order right.'
        );

        $this->assertAdmitted($call($merchant), 'the store owner');
        $this->assertRefused($call($foreign), 'a foreign-tenant merchant');

        /* THE REFUSAL IS THE PERMISSION, NOT THE FIXTURE: the same call, the same fixture, the
           same store — by a staff member who has been GRANTED the name the gate asks for. */
        $permitted = $call($this->staffIn($merchant, $store, 'Manage Order'));
        $this->assertAdmitted($permitted, 'staff GRANTED Manage Order');
        $this->assertStringContainsString(
            '4242.42',
            (string) $permitted->getContent(),
            'the grant admits them but final_price is absent even then, so the money assertion '
            . 'above was not measuring a WITHHELD field at all.'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════════ 8. searchproduct

    /**
     * **UNGATED IN FACT — A MEASUREMENT AWAITING A FIX.**
     *
     * `SearchProduct()` is `resolveOwnedStore()` and then
     * `Product::where('store_id',…)->where('category_id',…)->get()->toArray()` — EVERY COLUMN,
     * `cost_price` included. Its sibling one route over, `searchProductsByName`, was gated on
     * `Manage Products` for exactly this reason (GAP-142 / P4-F111); this one was missed. Same
     * store, same table, same margin column, no permission.
     *
     * `category_id` is required, so a caller needs one id — and `productdropdown`, also ungated,
     * hands them the list.
     */
    public function test_searchproduct_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $categoryId = $this->categoryIn($store);
        $this->productIn($store, ['category_id' => $categoryId, 'cost_price' => 4242.42]);
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u) => $this->as($u)->postJson('/api/admin/searchproduct', [
            'store_id' => $store->id,
            'category_id' => $categoryId,
        ]);

        // MEASURED: admitted, and the margin was served.
        $staffResponse = $call($staff);
        $this->assertAdmitted($staffResponse, 'unpermissioned staff (UNGATED — pinned, awaiting a fix)');
        $this->assertStringContainsString(
            '4242.42',
            (string) $staffResponse->getContent(),
            'UNGATED: cost_price was served to a staff member holding nothing. '
            . 'When a gate lands, invert this to assertStringNotContainsString.'
        );

        $this->assertAdmitted($call($merchant), 'the store owner');
        $this->assertRefused($call($foreign), 'a foreign-tenant merchant');
    }

    // ═══════════════════════════════════════════════════════════════════════════════ 9. status-change

    /**
     * GATED — `resolveChangeableOrder()` then `callerMayChangeOrderState()`, which is a
     * store-owner limb plus `MerchantPermissions::allowsAny(['Manage Order'])`. 403, no write.
     */
    public function test_status_change_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $order = $this->makeOrder($store, ['delivered_status' => 0]);
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u) => $this->as($u)->postJson('/api/admin/status-change', [
            'id' => $order->id,
            'delivered' => 4,
        ]);

        $staffResponse = $call($staff);
        $this->assertRefused($staffResponse, 'unpermissioned staff');
        $this->assertSame(403, $staffResponse->status());
        $this->assertSame(
            0,
            (int) DB::table('orders')->where('id', $order->id)->value('delivered_status'),
            'the delivery status must be unchanged for unpermissioned staff.'
        );

        $this->assertAdmitted($call($merchant), 'the store owner');
        $this->assertRefused($call($foreign), 'a foreign-tenant merchant');

        /* THE REFUSAL IS THE PERMISSION, NOT THE FIXTURE: the same call, the same fixture, the
           same store — by a staff member who has been GRANTED the name the gate asks for. */
        $this->assertAdmitted(
            $call($this->staffIn($merchant, $store, 'Manage Order')),
            'staff GRANTED Manage Order'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════ 10. store/fcm-token

    /**
     * GATED BY OWNERSHIP, NOT BY PERMISSION — and staff are refused as a SIDE EFFECT.
     *
     * `ApiController::saveStoreFcmToken()` resolves `Store::where('created_by', $user->id)`. For a
     * staff member that is the OWNER's id, so the lookup misses and the handler answers
     * `{status: 0, "Store not found."}` — HTTP 200, trap (a). No permission is consulted.
     *
     * So nothing a staff member can be granted makes this route work for them: a merchant cannot
     * delegate push registration. That is a FUNCTIONAL limitation rather than a hole, recorded
     * here because the refusal is incidental and would disappear the moment the lookup were
     * loosened to `creatorId()`, with no permission gate underneath it.
     */
    public function test_store_fcm_token_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u) => $this->as($u)->postJson('/api/admin/store/fcm-token', [
            'store_id' => $store->id,
            'fcm_token' => 'census4-fcm',
            'device_id' => 'census4-device',
            'platform' => 'android',
        ]);

        $staffResponse = $call($staff);
        $this->assertRefused($staffResponse, 'unpermissioned staff');
        // Trap (a) in the open: a refusal that is HTTP 200.
        $this->assertSame(200, $staffResponse->status());
        $this->assertSame(0, $staffResponse->json('status'));
        $this->assertSame(
            0,
            DB::table('store_devices')->where('fcm_token', 'census4-fcm')->count(),
            'no device row may be written for a caller the store lookup refused.'
        );

        $this->assertAdmitted($call($merchant), 'the store owner');
        $this->assertSame(1, DB::table('store_devices')->where('fcm_token', 'census4-fcm')->count());

        $this->assertRefused($call($foreign), 'a foreign-tenant merchant');
        $this->assertSame(1, DB::table('store_devices')->where('fcm_token', 'census4-fcm')->count());
    }

    // ═══════════════════════════════════════════════════════════════════════════ 11. stripe-payment

    /**
     * GATED FOR STAFF; THE OWNER LIMB IS `INCONCLUSIVE_FIXTURE` AND THE REASON IS WORTH RECORDING.
     *
     * The gate is `refuseUnlessMayAdministerStoreSettings()` → `StoreSettingsAccess::permits()`:
     * operator OR "I am my own tenant root" (type `admin`) OR `isAbleTo('Manage Setting')`. A staff
     * member is none of the first two, so an unpermissioned one is refused 403 and nothing is
     * written. **That is the census verdict and it is fully established** — the gate runs before
     * any write.
     *
     * PAST THE GATE THE HANDLER CANNOT EXECUTE UNDER THIS SUITE. The writer at
     * `Api\DashboardController:5007` is a RAW
     * `insert into settings (…) ON DUPLICATE KEY UPDATE …` — MySQL-only syntax, absent from
     * SQLite, so the owner's request answers HTTP **500** with a syntax error. Exactly the
     * structural blindness CLAUDE.md records for `ReportController`'s `MONTH(`/`YEAR(`, on a
     * different route. The owner limb therefore asserts only what it can honestly establish: the
     * gate did NOT refuse them (not a 403), and the failure that follows is the MySQL-only SQL and
     * not an authorization decision. Measuring the write needs a real MySQL.
     */
    public function test_stripe_payment_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u) => $this->as($u)->postJson('/api/admin/stripe-payment', [
            'store_id' => $store->id,
            'CURRENCY_NAME' => 'CENSUS4-CURRENCY',
            'CURRENCY' => 'CS4',
        ]);

        $staffResponse = $call($staff);
        $this->assertRefused($staffResponse, 'unpermissioned staff');
        $this->assertSame(403, $staffResponse->status());
        $this->assertSame(
            0,
            DB::table('settings')->where('store_id', $store->id)->where('value', 'CENSUS4-CURRENCY')->count(),
            'no setting may be written for unpermissioned staff.'
        );

        /*
         * THE OWNER IS NOT REFUSED BY THE GATE. What they then hit is the MySQL-only upsert, and
         * the test says so by name rather than calling a 500 a refusal.
         */
        $ownerResponse = $call($merchant);
        $this->assertNotSame(
            403,
            $ownerResponse->status(),
            'the owner was refused BY THE GATE, which would make this a lockout: ' . $this->describe($ownerResponse)
        );
        $this->assertSame(
            500,
            $ownerResponse->status(),
            'the owner path no longer dies on the MySQL-only upsert this row documents; if it now '
            . 'succeeds, replace this with assertAdmitted and read the settings row back: '
            . $this->describe($ownerResponse)
        );
        $this->assertStringContainsString(
            'DUPLICATE',
            (string) $ownerResponse->getContent(),
            'the 500 the owner gets is no longer the ON DUPLICATE KEY UPDATE syntax error, so it '
            . 'may now be hiding something else: ' . $this->describe($ownerResponse)
        );

        $foreignResponse = $call($foreign);
        $this->assertRefused($foreignResponse, 'a foreign-tenant merchant');
        $this->assertSame(
            0,
            DB::table('settings')->where('value', 'CENSUS4-CURRENCY')->count(),
            'no setting may be written by a foreign-tenant merchant.'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════════════ 12. testmail

    /** GATED — the same `refuseUnlessMayAdministerStoreSettings()` gate, 403, no mail queued. */
    public function test_testmail_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $this->settingIn($store, 'MAIL_DRIVER', 'array');
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u) => $this->as($u)->postJson('/api/admin/testmail', [
            'store_id' => $store->id,
            'email' => 'census4@example.test',
        ]);

        $staffResponse = $call($staff);
        $this->assertRefused($staffResponse, 'unpermissioned staff');
        $this->assertSame(403, $staffResponse->status());
        Mail::assertNothingSent();

        $this->assertAdmitted($call($merchant), 'the store owner');
        $this->assertRefused($call($foreign), 'a foreign-tenant merchant');

        /* THE REFUSAL IS THE PERMISSION, NOT THE FIXTURE: the same call, the same fixture, the
           same store — by a staff member who has been GRANTED the name the gate asks for. */
        $this->assertAdmitted(
            $call($this->staffIn($merchant, $store, 'Manage Setting')),
            'staff GRANTED Manage Setting'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════ 13. update-customer

    /**
     * GATED — `resolveOwnedStore()` then `callerMayActInStore([PERMISSION_CUSTOMERS])`, 403, and
     * the customer's PII unchanged in the table.
     */
    public function test_update_customer_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $customerId = $this->customerIn($store);
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u) => $this->as($u)
            ->postJson('/api/admin/update-customer?store_id=' . $store->id, [
                'customer_id' => $customerId,
                'first_name' => self::ATTACKER_MARK,
            ]);

        $staffResponse = $call($staff);
        $this->assertRefused($staffResponse, 'unpermissioned staff');
        $this->assertSame(403, $staffResponse->status());
        $this->assertSame(
            'Census',
            DB::table('customers')->where('id', $customerId)->value('first_name'),
            'the customer PII must be unchanged for unpermissioned staff.'
        );

        $this->assertAdmitted($call($merchant), 'the store owner');
        $this->assertSame(
            self::ATTACKER_MARK,
            DB::table('customers')->where('id', $customerId)->value('first_name')
        );

        $this->assertRefused($call($foreign), 'a foreign-tenant merchant');

        /* THE REFUSAL IS THE PERMISSION, NOT THE FIXTURE: the same call, the same fixture, the
           same store — by a staff member who has been GRANTED the name the gate asks for. */
        $this->assertAdmitted(
            $this->as($this->staffIn($merchant, $store, 'Manage Customer'))
                ->postJson('/api/admin/update-customer?store_id=' . $store->id, [
                    'customer_id' => $customerId,
                    'first_name' => 'census4-granted-staff',
                ]),
            'staff GRANTED Manage Customer'
        );
        $this->assertSame(
            'census4-granted-staff',
            DB::table('customers')->where('id', $customerId)->value('first_name')
        );
    }

    // ═══════════════════════════════════════════════════════════════════════ 14. updateCurrencyApi

    /**
     * GATED — `resolveOwnedStore()` then `refuseUnlessMayAdministerStoreSettings()`, 403.
     *
     * The OWNER limb is recorded as `INCONCLUSIVE_FIXTURE` on purpose: past the gate this handler
     * runs the FIN-CUR conversion, which needs a usable `currency_rates` row, a wallet and a
     * consistent price book, and a fixture that satisfies all of it would be measuring the
     * conversion rather than the gate. What is asserted for the owner is only that they are NOT
     * refused with the gate's 403 — which is the whole claim this census makes about them.
     */
    public function test_updateCurrencyApi_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $this->currencyRateIn($store, 'SYP');
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u) => $this->as($u)
            ->postJson('/api/admin/updateCurrencyApi?store_id=' . $store->id, [
                'defult_currancy' => 'SYP',
                'defult_currancy_symbol' => '£',
            ]);

        $staffResponse = $call($staff);
        $this->assertRefused($staffResponse, 'unpermissioned staff');
        $this->assertSame(403, $staffResponse->status());
        $this->assertSame(
            0,
            DB::table('settings')->where('store_id', $store->id)->where('name', 'defult_currancy')->count(),
            'no currency setting may be written for unpermissioned staff.'
        );

        // The gate does not refuse the owner. Anything further is the conversion, not the gate.
        $ownerResponse = $call($merchant);
        $this->assertNotSame(
            403,
            $ownerResponse->status(),
            'the owner was refused BY THE GATE, which would make this a lockout: ' . $this->describe($ownerResponse)
        );

        $foreignResponse = $call($foreign);
        $this->assertRefused($foreignResponse, 'a foreign-tenant merchant');
        $this->assertSame(403, $foreignResponse->status());
    }

    // ══════════════════════════════════════════════════════════════════════ 15. updateStoreLanguage

    /**
     * **UNGATED IN FACT — A MEASUREMENT AWAITING A FIX.**
     *
     * `updateStoreLanguage()` is `Auth::user()` plus `resolveOwnedStore()` and then writes the
     * store's default language. No permission is consulted — while every OTHER settings door in
     * this controller (`stripe-payment`, `testmail`, `updateCurrencyApi`, `toggle-currency-status`,
     * `update-currency-rate`) goes through `refuseUnlessMayAdministerStoreSettings()`. The default
     * language decides what every shopper reads on the storefront.
     *
     * NOTE FOR WHOEVER FIXES IT: this route is DECLARED TWICE in `routes/api.php` (:253 and :319)
     * with identical middleware, and the later declaration is the one that serves. Both must be
     * read before concluding anything about this path's middleware.
     */
    public function test_updateStoreLanguage_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u) => $this->as($u)
            ->postJson('/api/admin/updateStoreLanguage?store_id=' . $store->id, [
                'default_language' => 'ar',
            ]);

        // MEASURED: admitted, and the write landed on `stores.default_language`.
        $staffResponse = $call($staff);
        $this->assertAdmitted($staffResponse, 'unpermissioned staff (UNGATED — pinned, awaiting a fix)');
        $this->assertSame(
            'ar',
            DB::table('stores')->where('id', $store->id)->value('default_language'),
            'UNGATED: the storefront language every shopper reads was switched by a staff member '
            . 'holding nothing. When a gate lands, invert this to the original value.'
        );

        $this->assertAdmitted($call($merchant), 'the store owner');

        $foreignResponse = $call($foreign);
        $this->assertRefused($foreignResponse, 'a foreign-tenant merchant');
        $this->assertSame(403, $foreignResponse->status());
    }

    // ═════════════════════════════════════════════════════════════════════ 16. updateStoreOwnerInfo

    /**
     * PARTIALLY GATED, AND THE SPLIT IS DELIBERATE (P1-SEC-18).
     *
     * `mayChangeOwnerIdentity()` refuses a CHANGE to `owner_name` / `owner_email` /
     * `owner_mobile` by anyone who is not the owner or the operator — because the owner's e-mail
     * is where a password reset goes, so a staff member could take the account over in two
     * requests. A staff member may still resubmit the stored values and may still change
     * STORE-LEVEL fields, which is what the second half of this test measures: the store NAME is
     * writable by an unpermissioned staff member.
     *
     * So this row is GATED on the credential axis and UNGATED on the store-profile axis. Both are
     * pinned, because a single verdict would hide half of it.
     */
    public function test_updateStoreOwnerInfo_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();
        $ownerEmail = (string) DB::table('users')->where('id', $merchant->id)->value('email');

        // (i) THE CREDENTIAL AXIS — refused, 403, and the owner's login e-mail untouched.
        $credentialAttempt = $this->as($staff)
            ->postJson('/api/admin/updateStoreOwnerInfo?store_id=' . $store->id, [
                'owner_email' => 'attacker-census4@example.test',
            ]);
        $this->assertRefused($credentialAttempt, 'unpermissioned staff changing the owner e-mail');
        $this->assertSame(403, $credentialAttempt->status());
        $this->assertSame(
            $ownerEmail,
            DB::table('users')->where('id', $merchant->id)->value('email'),
            'CREDENTIAL WRITE: the owner login e-mail was changed by a staff member.'
        );

        // (ii) THE STORE-PROFILE AXIS — admitted. MEASURED, awaiting a decision.
        $profileAttempt = $this->as($staff)
            ->postJson('/api/admin/updateStoreOwnerInfo?store_id=' . $store->id, [
                'store_name' => self::ATTACKER_MARK,
            ]);
        $this->assertAdmitted($profileAttempt, 'unpermissioned staff renaming the store (UNGATED — pinned)');
        $this->assertSame(
            self::ATTACKER_MARK,
            DB::table('stores')->where('id', $store->id)->value('name'),
            'UNGATED: `stores.name` — the public shop name — was rewritten by a staff member '
            . 'holding nothing. When a gate lands, invert this to the original name.'
        );

        $this->assertRefused(
            $this->as($foreign)->postJson('/api/admin/updateStoreOwnerInfo?store_id=' . $store->id, [
                'store_name' => 'foreign-rename',
            ]),
            'a foreign-tenant merchant'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════ 17. updatecategory

    /**
     * **UNGATED IN FACT — A MEASUREMENT AWAITING A FIX.**
     *
     * `UpdateCategory()` is `resolveOwnedStore()` and a scoped `Category` lookup, then writes
     * `name`, `slug`, images, `parent_id`, `trending` and `status`. No permission is consulted,
     * although `Manage Category` / `Edit Category` are seeded names the web screen uses — so the
     * gate the web has is simply absent here. A category's `status` controls whether its products
     * are reachable on the storefront.
     */
    public function test_updatecategory_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $categoryId = $this->categoryIn($store, 'census4-original');
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u, string $name) => $this->as($u)->postJson('/api/admin/updatecategory', [
            'store_id' => $store->id,
            'category_id' => $categoryId,
            'name' => $name,
        ]);

        // MEASURED: admitted, and the write landed.
        $staffResponse = $call($staff, self::ATTACKER_MARK);
        $this->assertAdmitted($staffResponse, 'unpermissioned staff (UNGATED — pinned, awaiting a fix)');
        $this->assertSame(
            self::ATTACKER_MARK,
            DB::table('categories')->where('id', $categoryId)->value('name'),
            'UNGATED: the category was renamed by a staff member holding nothing.'
        );

        $this->assertAdmitted($call($merchant, 'census4-owner-rename'), 'the store owner');

        $this->assertRefused($call($foreign, 'census4-foreign-rename'), 'a foreign-tenant merchant');
        $this->assertSame(
            'census4-owner-rename',
            DB::table('categories')->where('id', $categoryId)->value('name')
        );
    }

    // ════════════════════════════════════════════════════════════════════════════ 18. updatecoupon

    /** GATED — `callerMayActInStore(['Edit Coupon','Manage Coupon'])`, 403, no write. */
    public function test_updatecoupon_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $couponId = (int) DB::table('coupons')->insertGetId([
            'coupon_name' => 'census4-coupon',
            'coupon_code' => 'CENSUS4',
            'coupon_type' => 'percentage',
            'discount_amount' => 5,
            'coupon_limit' => 10,
            'status' => 1,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u, float $discount) => $this->as($u)->postJson('/api/admin/updatecoupon', [
            'store_id' => $store->id,
            'coupon_id' => $couponId,
            'discount_amount' => $discount,
        ]);

        $staffResponse = $call($staff, 99.0);
        $this->assertRefused($staffResponse, 'unpermissioned staff');
        $this->assertSame(403, $staffResponse->status());
        $this->assertEquals(
            5.0,
            (float) DB::table('coupons')->where('id', $couponId)->value('discount_amount'),
            'MONEY WRITE: the coupon discount was changed by unpermissioned staff.'
        );

        $this->assertAdmitted($call($merchant, 7.0), 'the store owner');
        $this->assertEquals(7.0, (float) DB::table('coupons')->where('id', $couponId)->value('discount_amount'));

        $this->assertRefused($call($foreign, 50.0), 'a foreign-tenant merchant');
        $this->assertEquals(7.0, (float) DB::table('coupons')->where('id', $couponId)->value('discount_amount'));

        /* THE REFUSAL IS THE PERMISSION, NOT THE FIXTURE: the same call, the same fixture, the
           same store — by a staff member who has been GRANTED the name the gate asks for. */
        $this->assertAdmitted($call($this->staffIn($merchant, $store, 'Edit Coupon'), 9.0), 'staff GRANTED Edit Coupon');
        $this->assertEquals(9.0, (float) DB::table('coupons')->where('id', $couponId)->value('discount_amount'));
    }

    // ═══════════════════════════════════════════════════════════════════════════ 19. updatepassword

    /**
     * NO PERMISSION GATE; the only control is KNOWLEDGE OF THE CURRENT PASSWORD.
     *
     * `UpdatePassword()` is `resolveOwnedStore()`, then `resolveManageableUser($request->user_id)`
     * — a TENANCY predicate (self, or the operator, or a target whose `created_by` is this
     * caller's merchant tenant root) — then `Hash::check($request->old_password, …)`.
     *
     * So an unpermissioned staff member CAN complete a credential write, on any user inside the
     * tenant whose current password they know, including a colleague's. Changing one's OWN password
     * is legitimate and is what the staff limb below drives; the finding is that the SAME route
     * reaches a sibling's row with no permission between, so the whole control is the `old_password`
     * check. `resolveManageableUser()` does NOT admit the owner's row to staff
     * (`$target->created_by` is the operator's id for a merchant, not the merchant's own), which is
     * the limb the second half pins — and the reason this is ranked below `updateproduct`.
     */
    public function test_updatepassword_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        // The factory's password. Taken from the factory rather than assumed.
        $known = 'password';
        $this->assertTrue(
            \Illuminate\Support\Facades\Hash::check($known, (string) DB::table('users')->where('id', $staff->id)->value('password')),
            'the fixture password is not what this test believes, so every branch below would be measuring the wrong thing.'
        );

        $staffOwnHash = (string) DB::table('users')->where('id', $staff->id)->value('password');

        // (i) OWN ROW, correct current password — ADMITTED. No permission involved.
        $own = $this->as($staff)->postJson('/api/admin/updatepassword', [
            'store_id' => $store->id,
            'user_id' => $staff->id,
            'old_password' => $known,
            'password' => 'census4-new-password',
            'password_confirmation' => 'census4-new-password',
        ]);
        $this->assertAdmitted($own, 'unpermissioned staff changing their OWN password (no permission consulted)');
        $this->assertNotSame(
            $staffOwnHash,
            (string) DB::table('users')->where('id', $staff->id)->value('password'),
            'the credential write did not land, so this row is not measuring what it claims.'
        );

        // (ii) THE OWNER'S ROW — refused by the TENANCY predicate, not by a permission.
        $ownerHash = (string) DB::table('users')->where('id', $merchant->id)->value('password');
        $takeover = $this->as($staff)->postJson('/api/admin/updatepassword', [
            'store_id' => $store->id,
            'user_id' => $merchant->id,
            'old_password' => $known,
            'password' => 'census4-takeover',
            'password_confirmation' => 'census4-takeover',
        ]);
        $this->assertRefused($takeover, 'unpermissioned staff changing the OWNER password');
        $this->assertSame(
            $ownerHash,
            (string) DB::table('users')->where('id', $merchant->id)->value('password'),
            'ACCOUNT TAKEOVER: the owner password was changed by a staff member.'
        );

        /*
         * (iii) A COLLEAGUE'S ROW — MEASURED, NOT INFERRED, because the docblock's claim about
         * `resolveManageableUser()` is the whole reason this row is reported at all. A sibling
         * staff member has `created_by` = the owner's id, which IS
         * `UserPolicy::merchantTenantRoot($actor)` for the caller — so the predicate admits them
         * and the only remaining control is the `old_password` check.
         */
        $colleague = $this->staffIn($merchant, $store);
        $colleagueHash = (string) DB::table('users')->where('id', $colleague->id)->value('password');
        $sideways = $this->as($staff)->postJson('/api/admin/updatepassword', [
            'store_id' => $store->id,
            'user_id' => $colleague->id,
            'old_password' => $known,
            'password' => 'census4-colleague',
            'password_confirmation' => 'census4-colleague',
        ]);
        /*
         * CONVERTED 2026-11-25 (GAP-251 / P4-F120). The lateral write between colleagues is now
         * refused: the gate ports `UserPolicy::resetPassword` -- the policy the WEB's own
         * `UserController::updatePassword` uses -- rather than choosing a permission name by
         * analogy.
         *
         * WITH A SELF EXCEPTION, and leg (i) above is why. This endpoint serves two purposes, and
         * the first version of the gate refused an ordinary staff member changing their OWN
         * password -- a self-service regression the shipped app would have hit. This pin caught it.
         */
        $this->assertRefused(
            $sideways,
            'unpermissioned staff changing a COLLEAGUE\'s password (GAP-251: gated via '
            . 'UserPolicy::resetPassword, which requires `Reset Password` after its operator limb)'
        );
        $this->assertSame(
            $colleagueHash,
            (string) DB::table('users')->where('id', $colleague->id)->value('password'),
            'the sideways credential write LANDED: one staff member set a colleague\'s password.'
        );

        /* AND THE GRANT IS WHAT MAKES THE DIFFERENCE -- a holder of `Reset Password` still resets,
         * because that is exactly what the ported policy admits. */
        $permitted = $this->staffIn($merchant, $store);
        $this->grantPermission(User::find($permitted->id), 'Reset Password');
        $this->assertAdmitted(
            $this->as(User::find($permitted->id))->postJson('/api/admin/updatepassword', [
                'store_id' => $store->id,
                'user_id' => $colleague->id,
                'old_password' => $known,
                'password' => 'census4-legit-reset',
                'password_confirmation' => 'census4-legit-reset',
            ]),
            'a holder of `Reset Password` was refused, so the ported policy admits nobody'
        );
        $this->assertNotSame(
            $colleagueHash,
            (string) DB::table('users')->where('id', $colleague->id)->value('password'),
            'the permitted reset did not land'
        );

        // (iv) A FOREIGN tenant's merchant may not reach this store at all.
        $this->assertRefused(
            $this->as($foreign)->postJson('/api/admin/updatepassword', [
                'store_id' => $store->id,
                'user_id' => $merchant->id,
                'old_password' => $known,
                'password' => 'census4-foreign',
                'password_confirmation' => 'census4-foreign',
            ]),
            'a foreign-tenant merchant'
        );
        $this->assertSame(
            $ownerHash,
            (string) DB::table('users')->where('id', $merchant->id)->value('password')
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════ 20. updateproduct

    /**
     * **WAS UNGATED — THE WORST ROW IN THIS GROUP, AND IT IS NOW FIXED (GAP-250 / P4-F119).**
     *
     * This pin did its job: it recorded that a staff member holding nothing rewrote `price` 100 -> 1,
     * `sale_price` 90 -> 1, `cost_price` 40 -> 1 and `product_stock` 50 -> 0, it went RED the moment
     * the gate landed, and it named the route when it did. The expectation below is now inverted to
     * the gated behaviour — which is what a pin is for, not a regression.
     *
     * What was wrong: `UpdateProduct()` opened with `OperatorCatalogueWrite::refuses()`, which
     * refuses the PLATFORM OPERATOR and nothing else, then `resolveOwnedStore()`, which admits the
     * owner's staff by design. No permission check, while the validator accepted every money
     * column, the web twin gated the same write on `Edit Products`, and `searchProductsByName` one
     * route over had been gated on `Manage Products` purely because it READS `cost_price`
     * (GAP-142 / P4-F111) — the route that WRITES it was missed.
     *
     * Now gated on `Edit Products`, BELOW both existing controls so neither the operator's F206
     * refusal nor the foreign tenant's `Store not found!` changes. The gate's own suite is
     * `AdminApiProductWritePermissionTest` (7 cases, including a PREMISE that the write path is
     * reachable at all — its absence made the first draft of that file pass vacuously, because a
     * `category_id` with no matching row refused every principal).
     */
    public function test_updateproduct_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $categoryId = $this->categoryIn($store);
        $productId = $this->productIn($store, ['category_id' => $categoryId]);
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u, float $price, int $stock) => $this->as($u)->postJson('/api/admin/updateproduct', [
            'store_id' => $store->id,
            'product_id' => $productId,
            'name' => self::ATTACKER_MARK,
            'category_id' => $categoryId,
            'description' => 'repriced',
            'price' => $price,
            'sale_price' => 1.00,
            'cost_price' => 1.00,
            'product_stock' => $stock,
            'trending' => 0,
            'variant_product' => 0,
            'status' => 1,
        ]);

        // MEASURED, AFTER THE FIX: refused, and not one money column moved.
        $staffResponse = $call($staff, 1.0, 0);
        $this->assertRefused($staffResponse, 'unpermissioned staff (GAP-250: now gated on Edit Products)');

        $row = DB::table('products')->where('id', $productId)->first();
        $this->assertEquals(
            100.0,
            (float) $row->price,
            'a staff member holding nothing rewrote the product price. This route was gated on '
            . '`Edit Products` as GAP-250 / P4-F119; if this fails the gate has been removed or '
            . 'hoisted above resolveOwnedStore().'
        );
        $this->assertEquals(90.0, (float) $row->sale_price);
        $this->assertEquals(40.0, (float) $row->cost_price, 'the margin was rewritten by an unpermissioned staff member.');
        $this->assertSame(50, (int) $row->product_stock, 'the stock figure was rewritten.');

        /* AND THE GRANT IS WHAT MAKES THE DIFFERENCE — the same staff member, same store, holding
         * `Edit Products`, is admitted. Without this leg the refusal above would also pass against
         * a gate that admits nobody, which is the regression this family has already produced. */
        $permitted = $this->staffIn($merchant, $store);
        $this->grantPermission(User::find($permitted->id), 'Edit Products');
        $this->assertAdmitted($call(User::find($permitted->id), 7.0, 3), 'staff granted Edit Products');
        $this->assertEquals(7.0, (float) DB::table('products')->where('id', $productId)->value('price'));

        $this->assertAdmitted($call($merchant, 200.0, 5), 'the store owner');
        $this->assertEquals(200.0, (float) DB::table('products')->where('id', $productId)->value('price'));

        $this->assertRefused($call($foreign, 3.0, 1), 'a foreign-tenant merchant');
        $this->assertEquals(
            200.0,
            (float) DB::table('products')->where('id', $productId)->value('price'),
            'a foreign merchant repriced this store\'s product.'
        );
    }

    // ══════════════════════════════════════════════════════════════════════ 21. updateproductbrand

    /**
     * **UNGATED IN FACT — A MEASUREMENT AWAITING A FIX.**
     *
     * `UpdateProductBrand()` is `resolveOwnedStore()` plus
     * `whereIn('store_id', $this->ownedStoreIds())` and then writes the brand's `name`, `slug`,
     * `logo` and `status`. No permission is consulted.
     */
    public function test_updateproductbrand_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $brandId = (int) DB::table('product_brands')->insertGetId([
            'name' => 'census4-brand',
            'slug' => 'census4-brand',
            'status' => 1,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u, string $name) => $this->as($u)->postJson('/api/admin/updateproductbrand', [
            'store_id' => $store->id,
            'productbrand_id' => $brandId,
            'name' => $name,
        ]);

        // MEASURED: admitted, and the write landed.
        $staffResponse = $call($staff, self::ATTACKER_MARK);
        $this->assertAdmitted($staffResponse, 'unpermissioned staff (UNGATED — pinned, awaiting a fix)');
        $this->assertSame(
            self::ATTACKER_MARK,
            DB::table('product_brands')->where('id', $brandId)->value('name'),
            'UNGATED: the brand was renamed by a staff member holding nothing.'
        );

        $this->assertAdmitted($call($merchant, 'census4-owner-brand'), 'the store owner');

        $this->assertRefused($call($foreign, 'census4-foreign-brand'), 'a foreign-tenant merchant');
        $this->assertSame(
            'census4-owner-brand',
            DB::table('product_brands')->where('id', $brandId)->value('name')
        );
    }

    // ══════════════════════════════════════════════════════════════════════ 22. updateproductlable

    /**
     * **UNGATED IN FACT — A MEASUREMENT AWAITING A FIX.**
     *
     * `UpdateProductLable()` is `resolveOwnedStore()` plus `whereIn('store_id', ownedStoreIds())`
     * and then writes `name` and `status`. No permission is consulted.
     *
     * NOTE: it also rewrites `store_id = getCurrentStore()` unconditionally, so for a caller whose
     * `current_store` is a DIFFERENT store of the same tenant this route MOVES the label between
     * stores. That is out of this census's scope but is reported.
     */
    public function test_updateproductlable_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $labelId = (int) DB::table('product_labels')->insertGetId([
            'name' => 'census4-label',
            'slug' => 'census4-label',
            'status' => 1,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u, string $name) => $this->as($u)->postJson('/api/admin/updateproductlable', [
            'store_id' => $store->id,
            'lable_id' => $labelId,
            'name' => $name,
        ]);

        // MEASURED: admitted, and the write landed.
        $staffResponse = $call($staff, self::ATTACKER_MARK);
        $this->assertAdmitted($staffResponse, 'unpermissioned staff (UNGATED — pinned, awaiting a fix)');
        $this->assertSame(
            self::ATTACKER_MARK,
            DB::table('product_labels')->where('id', $labelId)->value('name'),
            'UNGATED: the product label was renamed by a staff member holding nothing.'
        );

        $this->assertAdmitted($call($merchant, 'census4-owner-label'), 'the store owner');

        $this->assertRefused($call($foreign, 'census4-foreign-label'), 'a foreign-tenant merchant');
        $this->assertSame(
            'census4-owner-label',
            DB::table('product_labels')->where('id', $labelId)->value('name')
        );
    }

    // ══════════════════════════════════════════════════════════════════════════════ 23. updatereview

    /**
     * **UNGATED IN FACT — A MEASUREMENT AWAITING A FIX.**
     *
     * `UpdateReview()` is `resolveOwnedStore()` plus a store-scoped `Testimonial` lookup, then
     * writes `user_id`, `category_id`, `product_id`, `rating_no`, `title`, `description` and
     * `status`, and recomputes the product's average rating. No permission is consulted — so an
     * unpermissioned staff member can rewrite public review text and move a product's star rating.
     */
    public function test_updatereview_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $reviewId = $this->testimonialIn($store);
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u, string $text) => $this->as($u)->postJson('/api/admin/updatereview', [
            'store_id' => $store->id,
            'id' => $reviewId,
            'description' => $text,
        ]);

        // MEASURED: admitted, and the write landed.
        $staffResponse = $call($staff, self::ATTACKER_MARK);
        $this->assertAdmitted($staffResponse, 'unpermissioned staff (UNGATED — pinned, awaiting a fix)');
        $this->assertSame(
            self::ATTACKER_MARK,
            DB::table('testimonials')->where('id', $reviewId)->value('description'),
            'UNGATED: public review text was rewritten by a staff member holding nothing.'
        );

        $this->assertAdmitted($call($merchant, 'census4-owner-text'), 'the store owner');

        $this->assertRefused($call($foreign, 'census4-foreign-text'), 'a foreign-tenant merchant');
        $this->assertSame(
            'census4-owner-text',
            DB::table('testimonials')->where('id', $reviewId)->value('description')
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════ 24. updateshipping

    /** GATED — `callerMayActInStore(['Edit Shipping Class','Manage Shipping Class'])`, 403, no write. */
    public function test_updateshipping_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $shippingId = (int) DB::table('shippings')->insertGetId([
            'name' => 'census4-shipping',
            'description' => 'fixture',
            'slug' => $store->slug,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u, string $name) => $this->as($u)->postJson('/api/admin/updateshipping', [
            'store_id' => $store->id,
            'shipping_id' => $shippingId,
            'name' => $name,
        ]);

        $staffResponse = $call($staff, self::ATTACKER_MARK);
        $this->assertRefused($staffResponse, 'unpermissioned staff');
        $this->assertSame(403, $staffResponse->status());
        $this->assertSame(
            'census4-shipping',
            DB::table('shippings')->where('id', $shippingId)->value('name'),
            'the shipping class must be unchanged for unpermissioned staff.'
        );

        $this->assertAdmitted($call($merchant, 'census4-owner-shipping'), 'the store owner');
        $this->assertRefused($call($foreign, 'census4-foreign-shipping'), 'a foreign-tenant merchant');

        /* THE REFUSAL IS THE PERMISSION, NOT THE FIXTURE: the same call, the same fixture, the
           same store — by a staff member who has been GRANTED the name the gate asks for. */
        $this->assertAdmitted(
            $call($this->staffIn($merchant, $store, 'Edit Shipping Class'), 'census4-granted-shipping'),
            'staff GRANTED Edit Shipping Class'
        );
        $this->assertSame('census4-granted-shipping', DB::table('shippings')->where('id', $shippingId)->value('name'));
    }

    // ════════════════════════════════════════════════════════════════════════════════ 25. updatetax

    /** GATED — `callerMayActInStore(['Edit Tax','Manage Tax'])`, 403, no write. */
    public function test_updatetax_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $taxId = (int) DB::table('taxes')->insertGetId([
            'name' => 'census4-tax',
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u, string $name) => $this->as($u)->postJson('/api/admin/updatetax', [
            'store_id' => $store->id,
            'tax_id' => $taxId,
            'name' => $name,
        ]);

        $staffResponse = $call($staff, self::ATTACKER_MARK);
        $this->assertRefused($staffResponse, 'unpermissioned staff');
        $this->assertSame(403, $staffResponse->status());
        $this->assertSame(
            'census4-tax',
            DB::table('taxes')->where('id', $taxId)->value('name'),
            'the tax must be unchanged for unpermissioned staff.'
        );

        $this->assertAdmitted($call($merchant, 'census4-owner-tax'), 'the store owner');
        $this->assertRefused($call($foreign, 'census4-foreign-tax'), 'a foreign-tenant merchant');

        /* THE REFUSAL IS THE PERMISSION, NOT THE FIXTURE: the same call, the same fixture, the
           same store — by a staff member who has been GRANTED the name the gate asks for. */
        $this->assertAdmitted(
            $call($this->staffIn($merchant, $store, 'Edit Tax'), 'census4-granted-tax'),
            'staff GRANTED Edit Tax'
        );
        $this->assertSame('census4-granted-tax', DB::table('taxes')->where('id', $taxId)->value('name'));
    }

    // ═══════════════════════════════════════════════════════════════════════════ 26. updatevariant

    /**
     * GATED — but through `VariantRemovalPolicy::authorize()`, which is
     * `MerchantPermissions::allowsAny($user, ['Edit Products'], $storeId)`, AND THE REFUSAL IS AN
     * HTTP 200 (`$this->error()`), trap (a) again.
     *
     * `MerchantPermissions` has an operator wildcard and NO store-owner limb, so the owner needs
     * the grant production gives them — `grantMerchantGlobalRole()` below.
     */
    public function test_updatevariant_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $productId = $this->productIn($store, ['variant_product' => 1]);
        $variantId = (int) DB::table('product_variants')->insertGetId([
            'product_id' => $productId,
            'variant' => 'census4-variant',
            'sku' => 'CENSUS4-SKU',
            'price' => 10.0,
            'stock' => 3,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u, string $name) => $this->as($u)->postJson('/api/admin/updatevariant', [
            'store_id' => $store->id,
            'productvariant_id' => $variantId,
            'name' => $name,
        ]);

        $staffResponse = $call($staff, self::ATTACKER_MARK);
        $this->assertRefused($staffResponse, 'unpermissioned staff');
        // The gate runs, and answers 200/{status:0}: a refusal a status-code-only census misreads.
        $this->assertSame(200, $staffResponse->status());
        $this->assertSame(0, $staffResponse->json('status'));
        $this->assertSame(
            'census4-variant',
            DB::table('product_variants')->where('id', $variantId)->value('variant'),
            'the variant must be unchanged for unpermissioned staff.'
        );

        $owner = $this->withGlobalPermissions(User::find($merchant->id), ['Edit Products']);
        $this->assertAdmitted($call($owner, 'census4-owner-variant'), 'the store owner holding Edit Products');
        $this->assertSame(
            'census4-owner-variant',
            DB::table('product_variants')->where('id', $variantId)->value('variant')
        );

        $this->assertRefused($call($foreign, 'census4-foreign-variant'), 'a foreign-tenant merchant');

        /* THE REFUSAL IS THE PERMISSION, NOT THE FIXTURE: the same call, the same fixture, the
           same store — by a staff member who has been GRANTED the name the gate asks for. */
        $this->assertAdmitted(
            $call($this->staffIn($merchant, $store, 'Edit Products'), 'census4-granted-variant'),
            'staff GRANTED Edit Products'
        );
        $this->assertSame(
            'census4-granted-variant',
            DB::table('product_variants')->where('id', $variantId)->value('variant')
        );
    }

    // ═══════════════════════════════════════════════════════════════════════ 27. wallet/add-balance

    /**
     * GATED — `canFinance('Add Finance Balance')` → `Gate::allows('finance', …)`, which is
     * operator OR `isAbleTo()`. A MONEY route, and the most important row to get right: the
     * unpermissioned staff member is refused 403 and the balance is unmoved.
     *
     * THE OWNER NEEDS THE GRANT, BY OWNER DECISION. The six FINANCE permissions are seeded but
     * granted to no role on purpose, "so nobody is silently handed a money power"
     * (`AuthServiceProvider`). A merchant therefore does NOT hold this by default, and the owner
     * limb below grants it explicitly rather than pretending otherwise. The store lookup is also
     * `created_by = $adminUser->id`, so staff miss it a second time.
     */
    public function test_wallet_add_balance_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $this->walletIn($store, 500.0);
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u, string $description) => $this->as($u)
            ->postJson('/api/admin/wallet/add-balance?store_id=' . $store->id, [
                'amount' => 1000,
                'description' => $description,
            ]);

        $staffResponse = $call($staff, 'census4-staff-credit');
        $this->assertRefused($staffResponse, 'unpermissioned staff');
        $this->assertSame(403, $staffResponse->status());
        $this->assertEquals(
            500.0,
            (float) DB::table('store_wallets')->where('store_id', $store->id)->value('available_balance'),
            'MONEY WRITE: an unpermissioned staff member credited the store wallet.'
        );

        /*
         * THE PERMISSION IS ISOLATED ON THE OWNER, because `canFinance()` is operator OR
         * `isAbleTo()` and has NO store-owner limb: the owner's admission below is caused by the
         * grant and by nothing else. Measured in both directions — refused without it, admitted
         * with it, same caller, same request.
         */
        $ungrantedOwner = $call($merchant, 'census4-owner-credit-ungranted');
        $this->assertRefused($ungrantedOwner, 'the store owner BEFORE the grant');
        $this->assertSame(403, $ungrantedOwner->status());
        $this->assertEquals(
            500.0,
            (float) DB::table('store_wallets')->where('store_id', $store->id)->value('available_balance')
        );

        $owner = $this->withGlobalPermissions(User::find($merchant->id), ['Add Finance Balance']);
        $this->assertAdmitted($call($owner, 'census4-owner-credit'), 'the store owner holding Add Finance Balance');
        $this->assertEquals(
            1500.0,
            (float) DB::table('store_wallets')->where('store_id', $store->id)->value('available_balance')
        );

        $foreignResponse = $this->as($this->withGlobalPermissions(User::find($foreign->id), ['Add Finance Balance']))
            ->postJson('/api/admin/wallet/add-balance?store_id=' . $store->id, [
                'amount' => 9999,
                'description' => 'census4-foreign-credit',
            ]);
        $this->assertRefused($foreignResponse, 'a foreign-tenant merchant holding the permission');
        $this->assertEquals(
            1500.0,
            (float) DB::table('store_wallets')->where('store_id', $store->id)->value('available_balance'),
            'CROSS-TENANT MONEY WRITE: a foreign merchant credited this store\'s wallet.'
        );
    }

    // ════════════════════════════════════════════════════════════════════ 28. wallet/deduct-balance

    /** GATED — `canFinance('Deduct Finance Balance')`, 403, and the balance unmoved. */
    public function test_wallet_deduct_balance_POST(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $this->walletIn($store, 500.0);
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u, string $description) => $this->as($u)
            ->postJson('/api/admin/wallet/deduct-balance?store_id=' . $store->id, [
                'amount' => 100,
                'description' => $description,
            ]);

        $staffResponse = $call($staff, 'census4-staff-debit');
        $this->assertRefused($staffResponse, 'unpermissioned staff');
        $this->assertSame(403, $staffResponse->status());
        $this->assertEquals(
            500.0,
            (float) DB::table('store_wallets')->where('store_id', $store->id)->value('available_balance'),
            'MONEY WRITE: an unpermissioned staff member debited the store wallet.'
        );

        // THE PERMISSION, ISOLATED ON THE OWNER — see `test_wallet_add_balance_POST` for why this
        // is the right control for a `canFinance()` gate.
        $ungrantedOwner = $call($merchant, 'census4-owner-debit-ungranted');
        $this->assertRefused($ungrantedOwner, 'the store owner BEFORE the grant');
        $this->assertSame(403, $ungrantedOwner->status());
        $this->assertEquals(
            500.0,
            (float) DB::table('store_wallets')->where('store_id', $store->id)->value('available_balance')
        );

        $owner = $this->withGlobalPermissions(User::find($merchant->id), ['Deduct Finance Balance']);
        $this->assertAdmitted($call($owner, 'census4-owner-debit'), 'the store owner holding Deduct Finance Balance');
        $this->assertEquals(
            400.0,
            (float) DB::table('store_wallets')->where('store_id', $store->id)->value('available_balance')
        );

        $foreignResponse = $this->as($this->withGlobalPermissions(User::find($foreign->id), ['Deduct Finance Balance']))
            ->postJson('/api/admin/wallet/deduct-balance?store_id=' . $store->id, [
                'amount' => 400,
                'description' => 'census4-foreign-debit',
            ]);
        $this->assertRefused($foreignResponse, 'a foreign-tenant merchant holding the permission');
        $this->assertEquals(
            400.0,
            (float) DB::table('store_wallets')->where('store_id', $store->id)->value('available_balance'),
            'CROSS-TENANT MONEY WRITE: a foreign merchant debited this store\'s wallet.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════ 29. deliveryboysUpdate/{id}

    /**
     * GATED — `refuseUnlessOperatorOwns()` then
     * `refuseWithoutDeliveryPermission('Edit Deliveryboy')`, which is a store-OWNER limb plus
     * `MerchantPermissions::allowsAny()`. 403 for unpermissioned staff, and the driver's
     * COMMISSION (money) and contact details unchanged.
     */
    public function test_deliveryboysUpdate_PUT(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $driverId = $this->deliveryBoyIn($store);
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u, string $name, float $commission) => $this->as($u)
            ->putJson('/api/admin/deliveryboysUpdate/' . $driverId . '?store_id=' . $store->id, [
                'name' => $name,
                'commission_percentage' => $commission,
            ]);

        $staffResponse = $call($staff, self::ATTACKER_MARK, 99.0);
        $this->assertRefused($staffResponse, 'unpermissioned staff');
        $this->assertSame(403, $staffResponse->status());
        $row = DB::table('delivery_boys')->where('id', $driverId)->first();
        $this->assertSame('Census Driver', $row->name);
        $this->assertEquals(
            5.0,
            (float) $row->commission_percentage,
            'MONEY WRITE: the driver commission was changed by unpermissioned staff.'
        );

        $this->assertAdmitted($call($merchant, 'census4-owner-driver', 7.0), 'the store owner');
        $this->assertEquals(
            7.0,
            (float) DB::table('delivery_boys')->where('id', $driverId)->value('commission_percentage')
        );

        // A foreign merchant does not own this store: 404 from refuseUnlessOperatorOwns().
        $foreignResponse = $call($foreign, 'census4-foreign-driver', 50.0);
        $this->assertRefused($foreignResponse, 'a foreign-tenant merchant');
        $this->assertEquals(
            7.0,
            (float) DB::table('delivery_boys')->where('id', $driverId)->value('commission_percentage')
        );

        /* THE REFUSAL IS THE PERMISSION, NOT THE FIXTURE: the same call, the same fixture, the
           same store — by a staff member who has been GRANTED the name the gate asks for. */
        $this->assertAdmitted(
            $call($this->staffIn($merchant, $store, 'Edit Deliveryboy'), 'census4-granted-driver', 9.0),
            'staff GRANTED Edit Deliveryboy'
        );
        $this->assertEquals(
            9.0,
            (float) DB::table('delivery_boys')->where('id', $driverId)->value('commission_percentage')
        );
    }

    // ══════════════════════════════════════════════════════════════════ 30. toggle-currency-status

    /** GATED — `resolveOwnedStore()` then `refuseUnlessMayAdministerStoreSettings()`, 403, no write. */
    public function test_toggle_currency_status_PUT(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $this->currencyRateIn($store, 'SYP', ['is_active' => 1]);
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $call = fn (User $u, int $active) => $this->as($u)->putJson('/api/admin/toggle-currency-status', [
            'store_id' => $store->id,
            'currency_code' => 'SYP',
            'is_active' => $active,
        ]);

        $staffResponse = $call($staff, 0);
        $this->assertRefused($staffResponse, 'unpermissioned staff');
        $this->assertSame(403, $staffResponse->status());
        $this->assertSame(
            1,
            (int) DB::table('currency_rates')->where('store_id', $store->id)->where('currency_code', 'SYP')->value('is_active'),
            'the currency must still be active for unpermissioned staff.'
        );

        $this->assertAdmitted($call($merchant, 0), 'the store owner');
        $this->assertSame(
            0,
            (int) DB::table('currency_rates')->where('store_id', $store->id)->where('currency_code', 'SYP')->value('is_active')
        );

        $this->assertRefused($call($foreign, 1), 'a foreign-tenant merchant');
        $this->assertSame(
            0,
            (int) DB::table('currency_rates')->where('store_id', $store->id)->where('currency_code', 'SYP')->value('is_active')
        );

        /* THE REFUSAL IS THE PERMISSION, NOT THE FIXTURE: the same call, the same fixture, the
           same store — by a staff member who has been GRANTED the name the gate asks for. */
        $this->assertAdmitted(
            $call($this->staffIn($merchant, $store, 'Manage Setting'), 1),
            'staff GRANTED Manage Setting'
        );
        $this->assertSame(
            1,
            (int) DB::table('currency_rates')->where('store_id', $store->id)->where('currency_code', 'SYP')->value('is_active')
        );
    }

    // ═══════════════════════════════════════════════════════════════════════ 31. update-currency-rate

    /**
     * GATED — `resolveOwnedStore()` then `refuseUnlessMayAdministerStoreSettings()`, 403.
     *
     * A MONEY route: `rate_per_usd` is what every price on the storefront is multiplied by, so a
     * write here reprices the whole catalogue. The unpermissioned staff member is refused and the
     * rate is unmoved.
     */
    public function test_update_currency_rate_PUT(): void
    {
        [$merchant, $store] = $this->owningTenant();
        $this->currencyRateIn($store, 'SYP', ['rate_per_usd' => 10.0]);
        $staff = $this->staffIn($merchant, $store);
        [$foreign] = $this->foreignTenant();

        $rate = fn () => (float) DB::table('currency_rates')
            ->where('store_id', $store->id)->where('currency_code', 'SYP')->value('rate_per_usd');

        $call = fn (User $u, float $newRate) => $this->as($u)->putJson('/api/admin/update-currency-rate', [
            'store_id' => $store->id,
            'currency_code' => 'SYP',
            'rate_per_usd' => $newRate,
        ]);

        $staffResponse = $call($staff, 0.00000001);
        $this->assertRefused($staffResponse, 'unpermissioned staff');
        $this->assertSame(403, $staffResponse->status());
        $this->assertEquals(
            10.0,
            $rate(),
            'MONEY WRITE: an unpermissioned staff member repriced the whole catalogue via the FX rate.'
        );

        $this->assertAdmitted($call($merchant, 12.5), 'the store owner');
        $this->assertEquals(12.5, $rate());

        $this->assertRefused($call($foreign, 1.0), 'a foreign-tenant merchant');
        $this->assertEquals(12.5, $rate());

        /* THE REFUSAL IS THE PERMISSION, NOT THE FIXTURE: the same call, the same fixture, the
           same store — by a staff member who has been GRANTED the name the gate asks for. */
        $this->assertAdmitted(
            $call($this->staffIn($merchant, $store, 'Manage Setting'), 20.0),
            'staff GRANTED Manage Setting'
        );
        $this->assertEquals(20.0, $rate());
    }
}
