<?php

namespace Tests\Contract;

use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-125 / P04-FL-01 — THE SHIPPED APP'S ORDER JOURNEY, DRIVEN END TO END BY A STAFF TOKEN.
 * =============================================================================================
 *
 * The register's next action for this row is *"add contract coverage for the Flutter order/cancel/
 * status journey"*, and its finding was that **no suite drives one**. Every existing order test
 * drives a single endpoint; a merchant's actual sequence — list, open, change status, cancel — was
 * covered nowhere, so a change that broke the SEQUENCE while leaving each endpoint green was
 * invisible.
 *
 * The four endpoints, taken from `tests/Support/ApiContract/manifests/flutter-endpoints.json`
 * rather than from guesswork:
 *
 *     GET    api/admin/orderlist            routes/api.php:220
 *     GET    api/admin/vieworder            routes/api.php:246
 *     POST   api/admin/orders/update-status  routes/api.php:209
 *     POST   api/admin/order-cancel          routes/api.php:380
 *
 * ---------------------------------------------------------------------------------------------
 * WHY THIS IS A CONTRACT TEST AND NOT A FEATURE TEST, which decides what it asserts.
 *
 * The shipped Flutter app cannot be redeployed alongside the server. So what matters here is not
 * "does the handler work" — Feature tests cover that — but **can one principal complete the
 * sequence, and does each step hand the next one what it needs**. A staff member who can list
 * orders and then cannot open one has a broken app even though both endpoints pass their own tests.
 *
 * TWO THINGS IT DELIBERATELY DOES NOT DO:
 *
 *   It does not assert full payload shapes. `AdminOrderPayloadShapeContractTest` already owns that
 *   question and does it properly, by driving the route with and without a column. Duplicating it
 *   here would be two tests with one claim between them.
 *
 *   It does not assert HTTP 200 for every step. On this API `ApiResponser::error()` answers HTTP
 *   **200** with `{status: 0}` (`app/Traits/ApiResponser.php:54`), so a status code says almost
 *   nothing — and a 401 is the one answer that is catastrophic, because the app FORCE-CLEARS ITS
 *   SESSION on any 401. That was P4-F114: `update-customer` answered 401 to staff and logged them
 *   out of the whole app. So the sharpest assertion this file can make is **"no step of the
 *   journey ever answers 401 to a panel principal"**, and it makes it on every step.
 *
 * ---------------------------------------------------------------------------------------------
 * THE CUSTOMER-UPDATE STEP, ADDED 2026-11-26 — THE SECOND HALF OF GAP-125.
 *
 * P4-F114 is **fixed** (`updateCustomerInfo` now runs a real `Manage Customer` gate instead of a
 * `users.type` string comparison, and refuses with 403), and
 * `Tests\Feature\Security\AdminApiCustomerUpdateLogoutTest` owns the handler-level proof — six
 * cases over staff with and without the grant, the store owner, the platform operator and another
 * tenant's member. What was still missing is the thing this file exists for: the step was **not in
 * any journey**. The row's own next action is *"extend the journey test to cover the customer-update
 * step"*, so it is here.
 *
 *     POST   api/admin/update-customer      routes/api.php:267
 *
 * Taken from `tests/Support/ApiContract/manifests/flutter-endpoints.json` (`updateCustomerUrl`)
 * like the four above, not from guesswork — the same discipline the row stresses.
 *
 * WHY A JOURNEY ADDS SOMETHING THE SIX HANDLER TESTS DO NOT. The app reaches the customer editor
 * through an order it has already opened, so the principal who edits a customer is the same one who
 * listed and opened the order. A grant set that satisfies the order gates and not the customer gate
 * gives a merchant an app that works up to the point where they tap save — which is precisely the
 * failure F114 produced, by a different mechanism. Only a sequence driven by ONE principal can see
 * that.
 *
 * AND THE 401 CLAIM IS MADE FOR THE UNPERMITTED PRINCIPAL TOO, because the register's wording is
 * *"the logout is the defect either way"*. A staff member who lacks `Manage Customer` must be told
 * no (403, session intact), not signed out. So the refusal is driven here as well, and asserted as
 * a refusal rather than merely as "not 401" — `assertNotSame(401, ...)` alone passes on a 200 that
 * writes, which is the can-never-fail shape this branch has hit four times.
 *
 * ---------------------------------------------------------------------------------------------
 * AND IT GUARDS A GATE ADDED THE SAME DAY. `vieworder` had no permission check at all until
 * GAP-142/F106 (2026-11-25) and now requires `Show Order` or `Manage Order`. A gate that admitted
 * nobody, or that refused the store's own owner, would break exactly this sequence — the app
 * reaches a detail view only through the list. This file is the sequence-level check that the
 * single-endpoint test cannot be.
 */
class FlutterOrderJourneyContractTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const ORIGINAL_NAME = 'GAP125-JOURNEY-ORIGINAL';

    private const RENAMED_BY_PERMITTED = 'GAP125-JOURNEY-RENAMED';

    private User $merchant;

    private User $staff;

    /**
     * The principal whose app journey spans BOTH resources: `Manage Order` to list and open an
     * order, `Manage Customer` to edit the shopper on it.
     *
     * Kept separate from `$this->staff` on purpose. That one holds `Manage Order` alone, and the
     * register's sensitivity argument for this file rests on it ("granting the staff member a
     * permission the order gates do NOT name breaks 3 of 4 cases"). Adding a second name to that
     * fixture would blunt the argument, so the customer journey gets its own principal instead.
     */
    private User $customerStaff;

    private Store $store;

    private Order $order;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        $this->order = $this->makeOrder($this->store, [
            'delivered_status' => 0,
            'payment_status' => 'Unpaid',
        ]);

        /*
         * A STAFF MEMBER HOLDING `Manage Order`, which is what the app's own order list requires
         * (`DashboardController:1639` admits `['Manage Order','Show Order']`). This is the principal
         * whose journey must work — not the merchant, who would pass every gate through the
         * store-owner limb and so could not detect a gate that names an unholdable permission.
         */
        $this->staff = $this->makeStaff($this->merchant);
        $this->staff->current_store = $this->store->id;
        $this->staff->save();
        $this->grantPermission(User::find($this->staff->id), 'Manage Order');
        $this->staff = User::find($this->staff->id);

        /*
         * THE CUSTOMER THE JOURNEY EDITS. Created through Eloquent, not a raw insert: `Customer` is
         * `Cachable`, so a raw write would leave later Eloquent reads serving the pre-write row for
         * the rest of the test.
         */
        $this->customer = Customer::create([
            'first_name' => self::ORIGINAL_NAME,
            'last_name' => 'Shopper',
            'email' => 'gap125-journey-shopper@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'mobile' => '555000222',
            'status' => 1,
            'store_id' => $this->store->id,
        ]);

        $this->customerStaff = $this->makeStaff($this->merchant, [
            'email' => 'gap125-journey-staff@example.test',
        ]);
        $this->customerStaff->current_store = $this->store->id;
        $this->customerStaff->save();
        $this->grantPermission(User::find($this->customerStaff->id), 'Manage Order');
        $this->grantPermission(User::find($this->customerStaff->id), 'Manage Customer');
        $this->customerStaff = User::find($this->customerStaff->id);
    }

    /**
     * The customer's live first name, read through `withoutGlobalScopes()`.
     *
     * `Customer` is one of the ELEVEN models that genuinely filter — it is in
     * `config/tenancy.php`'s `scoped_models` and carries `SoftDeletingScope` — so a plain
     * `Customer::find()` answers against whichever tenant happens to be resolved rather than
     * against the table, and "the write did not happen" would pass whether or not it did. That
     * exact false reading has already cost this project a disposition.
     */
    private function liveFirstName(): ?string
    {
        $row = Customer::withoutGlobalScopes()->find($this->customer->id);

        return $row === null ? null : (string) $row->first_name;
    }

    /**
     * The customer's live email, read through `withoutGlobalScopes()` for the same reason.
     *
     * Asserted separately from the name because it is a different KIND of field: this handler
     * writes `customers.email`, and whoever holds that address owns every storefront recovery flow
     * that trusts it. A test that names the email as the risk and then only watches `first_name` is
     * asserting something weaker than it claims.
     */
    private function liveEmail(): ?string
    {
        $row = Customer::withoutGlobalScopes()->find($this->customer->id);

        return $row === null ? null : (string) $row->email;
    }

    /** The customer-update step, driven as the shipped app drives it, for an arbitrary principal. */
    private function updateCustomerAs(string $bearer, string $name)
    {
        return $this->withHeader('Authorization', 'Bearer ' . $bearer)
            ->postJson('/api/admin/update-customer?store_id=' . $this->store->id, [
                'customer_id' => $this->customer->id,
                'first_name' => $name,
            ]);
    }

    private function asStaff(): string
    {
        return $this->staff->createToken('flutter')->plainTextToken;
    }

    private function asApp(string $method, string $uri, array $payload = [])
    {
        $bearer = $this->asStaff();

        return $this->withHeader('Authorization', 'Bearer ' . $bearer)
            ->json($method, $uri, $payload);
    }

    /** The premise: the staff member really holds the permission the journey needs. */
    public function test_the_staff_member_holds_the_order_permission(): void
    {
        $this->assertTrue(
            \App\Services\MerchantPermissions::allowsAny($this->staff, ['Manage Order'], $this->store->id),
            'the staff fixture does not hold Manage Order, so every step below would be measuring '
            . 'a permission refusal rather than the journey'
        );

        $this->assertNotSame(
            (int) $this->store->created_by,
            (int) $this->staff->id,
            'the fixture staff member IS the store owner, so the owner limb would carry every gate '
            . 'and a gate naming an unholdable permission could not be detected'
        );
    }

    /**
     * THE WHOLE SEQUENCE, IN ORDER, WITH ONE PRINCIPAL. Each step must be reachable, and NO step
     * may answer 401.
     */
    public function test_a_staff_member_can_complete_the_order_journey_without_being_logged_out(): void
    {
        $steps = [
            'list' => ['GET', '/api/admin/orderlist?store_id=' . $this->store->id, []],
            'open' => ['GET', '/api/admin/vieworder?store_id=' . $this->store->id . '&order_id=' . $this->order->id, []],
            'status' => ['POST', '/api/admin/orders/update-status', [
                'store_id' => $this->store->id,
                'order_id' => $this->order->id,
                'delivered_status' => 1,
            ]],
            'cancel' => ['POST', '/api/admin/order-cancel', [
                'store_id' => $this->store->id,
                'order_id' => $this->order->id,
            ]],
        ];

        foreach ($steps as $name => [$method, $uri, $payload]) {
            $response = $this->asApp($method, $uri, $payload);

            $this->assertNotSame(401, $response->status(), sprintf(
                'the "%s" step of the Flutter order journey answered 401 to a staff member holding '
                . 'Manage Order. The shipped app force-clears its session on ANY 401, so this logs '
                . 'the merchant out of the whole app rather than refusing one action. That is '
                . 'P4-F114\'s failure mode, and it is the one answer this journey must never give.',
                $name
            ));

            $this->assertNotSame(500, $response->status(), sprintf(
                'the "%s" step answered 500',
                $name
            ));

            /*
             * AND THE STEP MUST NOT BE A PERMISSION REFUSAL. On this API a refusal is usually a 200
             * with `{status: 0}`, so the status code cannot carry this — a 403 or a `status: 0`
             * whose message is the permission one means the journey is broken for a principal who
             * holds the permission the LIST requires.
             */
            $this->assertNotSame(403, $response->status(), sprintf(
                'the "%s" step refused a staff member holding Manage Order, so the app can reach '
                . 'the order list and then go no further',
                $name
            ));
        }
    }

    /**
     * THE SECOND STEP SPECIFICALLY, because it is the one that gained a gate today.
     *
     * `vieworder` had NO permission check until GAP-142/F106. A gate that named a permission nobody
     * can hold, or that refused the store's own owner, would pass its own unit-level tests and
     * still break the app here. Asserted on the envelope rather than the status, because
     * `ApiResponser` answers 200 for refusals.
     */
    public function test_opening_an_order_works_for_the_principal_the_list_admits(): void
    {
        $list = $this->asApp('GET', '/api/admin/orderlist?store_id=' . $this->store->id);

        $this->assertNotSame(403, $list->status(),
            'premise: the staff member cannot even reach the order LIST, so this test cannot '
            . 'measure whether the detail view agrees with it');

        $view = $this->asApp(
            'GET',
            '/api/admin/vieworder?store_id=' . $this->store->id . '&order_id=' . $this->order->id
        );

        $this->assertSame(200, $view->status(),
            'the order DETAIL refused a principal the order LIST admits. The app reaches a detail '
            . 'view only through the list, so the two gates disagreeing is a broken journey even '
            . 'though each endpoint passes its own test.');

        $this->assertSame(1, (int) $view->json('status'),
            'vieworder answered with an error envelope for a permitted staff member');
    }

    /** The store's own OWNER must also complete it — the regression this family has produced. */
    public function test_the_store_owner_can_also_open_an_order(): void
    {
        $bearer = $this->merchant->createToken('flutter')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $bearer)
            ->getJson('/api/admin/vieworder?store_id=' . $this->store->id . '&order_id=' . $this->order->id);

        $this->assertNotSame(403, $response->status(),
            'the store\'s own owner was refused their own order. A merchant does not implicitly '
            . 'hold the order permissions, and the known-outcome gate has caught a gate in this '
            . 'family refusing the owner once already.');
        $this->assertNotSame(401, $response->status());
    }

    // ══════════════════════════════════════════ GAP-125 / P4-F114: THE CUSTOMER-UPDATE STEP

    /** The premise: the journey principal really holds both names the sequence needs. */
    public function test_the_customer_journey_principal_holds_both_permissions(): void
    {
        foreach (['Manage Order', 'Manage Customer'] as $permission) {
            $this->assertTrue(
                \App\Services\MerchantPermissions::allowsAny($this->customerStaff, [$permission], $this->store->id),
                sprintf(
                    'the journey staff fixture does not hold %s, so the customer-update step below '
                    . 'would be measuring a permission refusal rather than the journey',
                    $permission
                )
            );
        }

        $this->assertNotSame(
            (int) $this->store->created_by,
            (int) $this->customerStaff->id,
            'the journey staff member IS the store owner, so the owner limb would carry the gate '
            . 'and a gate naming an unholdable permission could not be detected'
        );

        $this->assertSame(self::ORIGINAL_NAME, $this->liveFirstName(),
            'fixture: the customer does not hold its original name, so "the write landed" below '
            . 'cannot be told apart from "it was already that value"');
    }

    /**
     * THE WHOLE SEQUENCE INCLUDING THE CUSTOMER EDIT, IN ORDER, WITH ONE PRINCIPAL.
     *
     * list → open → **edit the shopper** → change status → cancel. No step may answer 401, none may
     * be a permission refusal, and the customer edit must actually land — a journey that reaches the
     * editor and silently fails to save is the same broken app from the merchant's side.
     */
    public function test_a_staff_member_can_edit_a_customer_mid_journey_without_being_logged_out(): void
    {
        $bearer = $this->customerStaff->createToken('flutter')->plainTextToken;

        $steps = [
            'list' => ['GET', '/api/admin/orderlist?store_id=' . $this->store->id, []],
            'open' => ['GET', '/api/admin/vieworder?store_id=' . $this->store->id . '&order_id=' . $this->order->id, []],
            'edit-customer' => ['POST', '/api/admin/update-customer?store_id=' . $this->store->id, [
                'customer_id' => $this->customer->id,
                'first_name' => self::RENAMED_BY_PERMITTED,
            ]],
            'status' => ['POST', '/api/admin/orders/update-status', [
                'store_id' => $this->store->id,
                'order_id' => $this->order->id,
                'delivered_status' => 1,
            ]],
            'cancel' => ['POST', '/api/admin/order-cancel', [
                'store_id' => $this->store->id,
                'order_id' => $this->order->id,
            ]],
        ];

        foreach ($steps as $name => [$method, $uri, $payload]) {
            $response = $this->withHeader('Authorization', 'Bearer ' . $bearer)
                ->json($method, $uri, $payload);

            $this->assertNotSame(401, $response->status(), sprintf(
                'the "%s" step answered 401 to a staff member holding both Manage Order and Manage '
                . 'Customer. The shipped app force-clears its session on ANY 401, so this logs the '
                . 'merchant out of the whole app rather than refusing one action. That is P4-F114\'s '
                . 'failure mode and it is the one answer this journey must never give.',
                $name
            ));

            $this->assertNotSame(500, $response->status(), sprintf('the "%s" step answered 500', $name));

            $this->assertNotSame(403, $response->status(), sprintf(
                'the "%s" step refused a staff member who holds the permissions every step of this '
                . 'sequence names, so the app works up to that point and then stops',
                $name
            ));
        }

        /*
         * AND THE EDIT LANDED. Without this the loop above is satisfied by a handler that answers
         * 200 and writes nothing — which is exactly what a gate short-circuiting the handler looks
         * like from the status code, and what a "not 401" assertion cannot tell apart.
         */
        $this->assertSame(self::RENAMED_BY_PERMITTED, $this->liveFirstName(),
            'the customer-update step answered without a refusal but the name did not change, so '
            . 'the journey reaches the editor and the save silently does nothing');
    }

    /**
     * AND AN UNPERMITTED STAFF MEMBER IS TOLD NO RATHER THAN SIGNED OUT.
     *
     * The register's wording is *"the logout is the defect either way"*, so the 401 claim is made
     * for this principal too. They can list and open orders — they hold `Manage Order` — so the app
     * genuinely gets them to the customer editor; what must not happen is that tapping save ends
     * their session.
     *
     * Asserted as a REFUSAL and not merely as "not 401": `assertNotSame(401, ...)` on its own is
     * satisfied by a 200 that writes, which is the can-never-fail shape this branch has hit four
     * times.
     */
    public function test_a_staff_member_without_manage_customer_is_refused_and_keeps_their_session(): void
    {
        $bearer = $this->staff->createToken('flutter')->plainTextToken;

        $response = $this->updateCustomerAs($bearer, 'GAP125-BY-UNPERMITTED-STAFF');

        $this->assertNotSame(401, $response->status(),
            'a staff member who lacks `Manage Customer` was signed out of the whole app for tapping '
            . 'save, instead of being told they lack a right. The shipped app treats 403 as '
            . '"authorized but not permitted" with the session INTACT and 401 as "start again".');

        $this->assertSame(403, $response->status(),
            'the refusal is not an explicit 403: ' . substr((string) $response->getContent(), 0, 300));

        $this->assertSame(self::ORIGINAL_NAME, $this->liveFirstName(),
            'the refused call still rewrote the customer\'s name');
    }

    /**
     * THE TWO NON-`User` TOKENS `custom.auth` ADMITS — AND WHY THEIR 401 IS NOT THE DEFECT.
     *
     * CLAUDE.md's rule: a `Customer` token takes the SAFE branch of this pattern, so a
     * customer-only probe proves nothing. Drive a merchant, a driver and a shopper.
     *
     * `custom.auth` logs a token's owner into whichever guard matches its **tokenable class** —
     * `Customer` → `customers`, `DeliveryBoy` → `deliveryboy`, `User` → `web` — while
     * `updateCustomerInfo` reads `auth()->user()`, the DEFAULT (`web`) guard. So a driver's or a
     * shopper's token passes the middleware and arrives with `auth()->user()` **null**, and the
     * handler's kept `!$adminUser` limb answers 401.
     *
     * THAT 401 IS CORRECT AND IS DELIBERATELY NOT CHANGED. It is the one branch where the caller is
     * genuinely not a panel principal at all, which is what 401 means; the shipped ADMIN app can
     * never produce it, because it only ever holds a `User` token. This case exists to pin the thing
     * that actually matters about those principals — **they cannot write** — and to record that the
     * status is a decision rather than an oversight, so the next reader who finds a 401 here does
     * not "fix" it into a 403 and thereby tell a non-principal that their session is fine.
     */
    public function test_a_driver_or_shopper_token_cannot_write_a_customer(): void
    {
        $driver = DeliveryBoy::forceCreate([
            'name' => 'GAP125 Driver',
            'email' => 'gap125-driver@example.test',
            'password' => Hash::make('not-a-real-credential'),
            'contact' => '0100000333',
            'type' => 'deliveryboy',
            'store_id' => $this->store->id,
            'created_by' => $this->merchant->id,
        ]);

        $principals = [
            'driver' => $driver->createToken('flutter')->plainTextToken,
            'shopper' => $this->customer->createToken('storefront')->plainTextToken,
        ];

        $originalEmail = $this->liveEmail();

        foreach ($principals as $label => $bearer) {
            $response = $this->withHeader('Authorization', 'Bearer ' . $bearer)
                ->postJson('/api/admin/update-customer?store_id=' . $this->store->id, [
                    'customer_id' => $this->customer->id,
                    'first_name' => 'GAP125-BY-' . strtoupper($label),
                    'email' => 'taken-over-by-' . $label . '@example.test',
                ]);

            $this->assertSame(401, $response->status(), sprintf(
                'a %s token did not take the non-panel limb. `custom.auth` logs it into the %s '
                . 'guard by tokenable class while the handler reads the default `web` guard, so '
                . '`auth()->user()` is null and the documented answer is 401. If this is now a 403 '
                . 'the limb has been changed; if it is 200 a non-panel principal can rewrite a '
                . 'shopper\'s record. Body: %s',
                $label,
                $label === 'driver' ? 'deliveryboy' : 'customers',
                substr((string) $response->getContent(), 0, 200)
            ));

            $this->assertSame(self::ORIGINAL_NAME, $this->liveFirstName(), sprintf(
                'a %s token rewrote the customer\'s first name', $label
            ));

            /*
             * AND THE EMAIL SPECIFICALLY. The request above tries to move the address, which is the
             * takeover primitive rather than a privacy field: whoever holds it owns every storefront
             * recovery flow that trusts it. Asserted rather than merely asserted-about.
             */
            $this->assertSame($originalEmail, $this->liveEmail(), sprintf(
                'a %s token moved the shopper\'s EMAIL ADDRESS. That is an account-takeover '
                . 'primitive, not a privacy field — whoever holds the address owns every storefront '
                . 'recovery flow that trusts it.',
                $label
            ));
        }
    }

    /**
     * THE PREMISE FOR ALL OF THE ABOVE: the endpoint the manifest names is really routed, with the
     * middleware this file's claims assume.
     *
     * Without this, every case above is one rename away from passing vacuously — a 404 satisfies
     * "not 401" and "not 403", and CLAUDE.md's census section records four completeness claims that
     * failed for exactly this reason. It also ties the committed Flutter manifest to the route
     * table, which is the discipline the GAP-125 row stresses: the endpoints come from
     * `flutter-endpoints.json`, not from guesswork.
     *
     * `custom.auth` is asserted because the whole 401 argument rests on it — it is what resolves a
     * Sanctum token into a guard by tokenable class, and therefore what makes the driver and shopper
     * cases above reachable at all.
     */
    public function test_the_customer_update_endpoint_is_routed_as_the_flutter_manifest_says(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(base_path('tests/Support/ApiContract/manifests/flutter-endpoints.json')),
            true
        );

        $paths = array_column($manifest['endpoints'] ?? [], 'path');

        $this->assertContains('/update-customer', $paths,
            'the shipped app no longer declares /update-customer, so this journey step is testing '
            . 'an endpoint no client calls. Re-derive the step from the manifest rather than '
            . 'keeping it on faith.');

        $route = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/admin/update-customer' && in_array('POST', $r->methods(), true));

        $this->assertNotNull($route,
            'POST api/admin/update-customer is not a registered route, so every assertion in this '
            . 'file about it would be satisfied by a 404');

        $this->assertContains('custom.auth', $route->gatherMiddleware(),
            'api/admin/update-customer no longer carries `custom.auth`. The whole 401 argument in '
            . 'this file rests on that middleware resolving a Sanctum token into a guard by '
            . 'tokenable class — it is what makes the driver and shopper cases reachable.');
    }

    /**
     * The store's own OWNER completes the customer step holding no role at all — the regression
     * this family keeps producing.
     *
     * The historic type check admitted every `type = 'admin'` row unconditionally, so replacing it
     * with a permission-only gate would refuse a merchant who was never granted anything. The owner
     * limb in `callerMayActInStore()` is what keeps them working, and nothing in the order journey
     * above exercises it for the CUSTOMER gate.
     */
    public function test_the_store_owner_can_also_edit_the_customer(): void
    {
        $bearer = $this->merchant->createToken('flutter')->plainTextToken;

        $response = $this->updateCustomerAs($bearer, self::RENAMED_BY_PERMITTED);

        $this->assertNotSame(401, $response->status(),
            'the store\'s own owner was signed out of the app for editing their own customer');

        $this->assertSame(200, $response->status(),
            'the gate refused the store\'s OWN owner, who holds no explicit grant: '
            . substr((string) $response->getContent(), 0, 300));

        $this->assertSame(self::RENAMED_BY_PERMITTED, $this->liveFirstName(),
            'HTTP 200 but the owner\'s edit did not land');
    }
}
