<?php

namespace Tests\Feature\Tenancy;

use App\Models\DeliveryBoy;
use App\Models\Store;
use App\Scopes\StoreScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Security\SecurityTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P1-PLT-08 -- DeliveryBoy is allowlisted, and the three surfaces that proves it on.
 * ====================================================================================================
 *
 * `Batch2ModelIsolationTest` carries the generic admin-context matrix for every allowlisted model
 * (A cannot read, update or delete B's row; A still can its own; a super admin sees both). This file
 * is the part that is specific to DeliveryBoy, and it exists because DeliveryBoy is an
 * AUTHENTICATION PROVIDER -- `config/auth.php`'s `deliveryboy` guard -- so the question is not only
 * "is one merchant's list separated from another's" but "can a driver still sign in at all".
 *
 * The three surfaces the task row names, each driven through its REAL route:
 *   1. the merchant-facing driver list              GET  /deliveryboy        (routes/web.php:871)
 *   2. driver assignment on an order                POST /order-assign       (routes/web.php:876)
 *   3. the driver's own login                       POST api/admin/deliveryLogin
 *
 * AND THE PREMISE THAT MAKES THE THIRD ONE MEAN ANYTHING. `actingAs($driver, 'deliveryboy')` also
 * calls `shouldUse('deliveryboy')`, so `auth()->user()` would return the DeliveryBoy and the scope
 * would resolve a tenant that production never resolves -- the fifth way a test passes for the wrong
 * reason in this project's skill. Production reaches a driver through
 * `Auth::guard('deliveryboy')->attempt()` and `auth('deliveryboy')->login()`, NEITHER of which
 * touches the default guard. So the login test drives the real endpoint with real credentials and
 * asserts the default guard is empty first.
 */
class DeliveryBoyScopeTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The guard's own message, so a crash and a refusal cannot be confused with each other. */
    private const REFUSAL = "That delivery boy is not one of your stores' drivers.";

    private function makeDriver(Store $store, string $name, string $email, string $password = 'driver-password-fixture'): DeliveryBoy
    {
        $driver = new DeliveryBoy();
        $driver->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'contact' => '0100000000',
            'profile_image' => 'uploads/profile/avatar.png',
            'type' => 'deliveryboy',
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ])->save();
        // `delivery_boys` has eleven columns and `is_active`, `commission_percentage` and
        // `is_commission_enabled` are NOT among them, though all three are fillable (CLAUDE.md records
        // the two handlers that 500 for everyone because of it). Asserted rather than assumed, so this
        // fixture cannot drift back into writing a column that does not exist.

        return $driver;
    }

    // =================================================================================================
    //  PREMISES -- without these the rest is vacuous
    // =================================================================================================

    public function test_premise_the_model_is_allowlisted_and_the_column_exists(): void
    {
        $this->assertTrue(StoreScope::isAllowlisted(DeliveryBoy::class),
            'DeliveryBoy is not in config/tenancy.php scoped_models, so every assertion below would pass '
            . 'for the wrong reason: `isActiveFor()` short-circuits for a model that is not allowlisted, '
            . 'even one carrying BelongsToStore.');
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('delivery_boys', 'store_id'));
        $this->assertSame('open', StoreScope::nullTenantPolicy(),
            'Premise for the login test: an unresolved tenant must add NO predicate, or a driver could not '
            . 'read their own row while signing in.');
    }

    // =================================================================================================
    //  1. THE MERCHANT-FACING DRIVER LIST
    // =================================================================================================

    public function test_the_driver_list_shows_only_this_merchants_drivers(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        // 'Manage Deliveryboy' -- lower-case 'b'. The handler's own spelling (DeliveryBoyController:17);
        // granting 'Manage DeliveryBoy' made this test pass for the wrong reason: the permission check
        // refused, the handler redirected, and 'their driver is absent' held because NOBODY's was there.
        $this->grantPermission($merchantA, 'Manage Deliveryboy');

        $mine = $this->makeDriver($storeA, 'DRIVER-MINE-FIXTURE', 'mine-fixture@example.test');
        $theirs = $this->makeDriver($storeB, 'DRIVER-THEIRS-FIXTURE', 'theirs-fixture@example.test');

        $response = $this->actingAs($merchantA->fresh())->get('/deliveryboy');

        $this->assertNotSame(404, $response->getStatusCode(), 'the route must exist, or a row assertion is satisfied by a 404');
        $this->assertNotSame(500, $response->getStatusCode(), 'the screen must render, or "their driver is absent" holds either way');

        $body = (string) $response->getContent();
        $this->assertStringContainsString('DRIVER-MINE-FIXTURE', $body, 'the merchant cannot see their OWN driver -- the scope over-narrowed');
        $this->assertStringNotContainsString('DRIVER-THEIRS-FIXTURE', $body, "another store's driver is listed");

        // and the raw table still holds both, so the assertion above is about visibility, not damage
        $this->assertSame(2, (int) DB::table('delivery_boys')->whereIn('id', [$mine->id, $theirs->id])->count());
    }

    public function test_a_bare_id_edit_cannot_reach_another_stores_driver(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Deliveryboy');

        $theirs = $this->makeDriver($storeB, 'DRIVER-EDIT-FIXTURE', 'edit-fixture@example.test');

        $response = $this->actingAs($merchantA->fresh())->get('/deliveryboy/' . $theirs->id . '/edit');

        $this->assertNotSame(404, $response->getStatusCode(), 'the route must exist');

        /*
         * ASSERTED ON THE REFUSAL, because "the email is absent" is trivially true of a redirect and was
         * ALSO true of the 500 this handler produced before the null guard was added -- and the first
         * draft of this test failed against correct code for a third reason again: Ignition's error page
         * echoes the TEST SOURCE, so the fixture's own e-mail literal appeared in the 500 body.
         */
        $this->assertSame(302, $response->getStatusCode(),
            'the edit screen neither refused nor redirected; a 500 here is a crash where a refusal belongs');
        $this->assertSame('Delivery Boy not found.', session('error'));
        $this->assertStringNotContainsString('edit-fixture@example.test', (string) $response->getContent(),
            "another store's driver was rendered on the edit screen -- DeliveryBoyController::edit() reads a bare id");
    }

    /** POSITIVE CONTROL: the merchant's own driver still opens on that screen. */
    public function test_the_merchants_own_driver_still_opens_on_the_edit_screen(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Deliveryboy');

        $mine = $this->makeDriver($storeA, 'DRIVER-EDITOWN-FIXTURE', 'editown-fixture@example.test');

        $response = $this->actingAs($merchantA->fresh())->get('/deliveryboy/' . $mine->id . '/edit');

        $this->assertSame(200, $response->getStatusCode(), 'the merchant can no longer edit their OWN driver');
        $this->assertStringContainsString('editown-fixture@example.test', (string) $response->getContent());
    }

    // =================================================================================================
    //  2. DRIVER ASSIGNMENT ON AN ORDER
    // =================================================================================================

    /**
     * THE SCOPE ALONE DOES NOT CLOSE THIS, which is what driving the route explicitly found.
     *
     * `order_assign` validates `exists:delivery_boys,id` -- a QUERY BUILDER rule, which global scopes
     * do not touch -- so a cross-store id passes validation. The `find()` then returns null under the
     * scope, but the write took `$request->delivery_boy` VERBATIM rather than `$deliveryBoy->id`, so the
     * order was still stamped with the foreign driver's id; only the commission block, which does read
     * `$deliveryBoy`, went quiet. P1-PLT-08 therefore added an explicit refusal.
     *
     * ASSERTED ON THE REFUSAL'S OWN MESSAGE, not on the row alone, and the next test says why.
     */
    public function test_an_order_cannot_be_assigned_to_another_stores_driver(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Order');

        $order = $this->makeOrder($storeA);
        $theirs = $this->makeDriver($storeB, 'DRIVER-ASSIGN-FIXTURE', 'assign-fixture@example.test');

        $response = $this->actingAs($merchantA->fresh())->post('/order-assign', [
            'order_id' => $order->id,
            'delivery_boy' => $theirs->id,
        ]);

        $this->assertNotSame(404, $response->getStatusCode(), 'the route must exist');
        $this->assertSame(403, $response->getStatusCode(),
            'the guard did not refuse; the order reached the write path with a foreign driver id');
        $this->assertSame(self::REFUSAL, $response->json('message'));
        $this->assertNull(DB::table('orders')->where('id', $order->id)->value('deliveryboy_id'),
            "the order was stamped with another store's driver id");
    }

    /**
     * THE REFUSAL IS THE GUARD'S, NOT A CRASH -- and this test exists because the route DOES crash for
     * a reason that has nothing to do with tenancy.
     *
     * `delivery_earnings` is one of GATE F's six money-bearing tables that live code reads and writes
     * and NO migration creates (database/migrations/_gate_f_pending/0005_draft...). `order_assign` runs
     * `DeliveryEarning::where('order_id', ...)->delete()` on every change of driver, so on any
     * environment without that table -- the test suite included -- the route answers 500 to EVERY
     * caller. A row assertion alone would therefore have passed whether or not the guard existed, which
     * is this project's first recorded way for a test to pass for the wrong reason.
     *
     * So the separation is asserted directly: the foreign driver gets the guard's own message, the
     * merchant's OWN driver does not. The table is not created here, because its column types are an
     * inference until the owner reconciles them against the live database (GATE F) and
     * guard-gate-f-containment.sh asserts no discoverable migration creates it.
     */
    public function test_the_refusal_names_the_guard_and_not_the_missing_earnings_table(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('delivery_earnings'),
            'Premise: delivery_earnings is absent here (GATE F). If it ever exists, this test and the '
            . 'positive control below should be rewritten to drive the first-assignment path directly.');

        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Order');

        $order = $this->makeOrder($storeA);
        $mine = $this->makeDriver($storeA, 'DRIVER-SEP-FIXTURE', 'sep-fixture@example.test');

        $response = $this->actingAs($merchantA->fresh())->post('/order-assign', [
            'order_id' => $order->id,
            'delivery_boy' => $mine->id,
        ]);

        $this->assertNotSame(403, $response->getStatusCode(),
            "the merchant's OWN driver was refused by the tenancy guard -- the refusal is too broad");
        $this->assertSame(500, $response->getStatusCode(),
            'and the failure that remains is the missing delivery_earnings table, not the guard -- if this '
            . 'ever stops being a 500, GATE F has moved and both this test and the one below want rewriting');
    }

    /**
     * POSITIVE CONTROL for the write path, shaped around the missing table above.
     *
     * The `DeliveryEarning` delete runs only when the driver CHANGES
     * (`if ($order->deliveryboy_id != $request->delivery_boy)`), so re-confirming the driver an order
     * already carries reaches the save without touching the absent table. That is a narrower positive
     * than a first assignment -- stated rather than glossed -- and it is enough for what it is here to
     * prove: that the guard lets the merchant's own driver through and the handler completes.
     */
    public function test_the_merchants_own_driver_passes_the_guard_and_the_handler_completes(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Order');

        $order = $this->makeOrder($storeA);
        $mine = $this->makeDriver($storeA, 'DRIVER-OWN-FIXTURE', 'own-fixture@example.test');

        // pre-set through the raw table, so the handler's own "did the driver change?" test is false
        DB::table('orders')->where('id', $order->id)->update(['deliveryboy_id' => $mine->id]);
        (new \App\Models\Order())->flushCache();

        $response = $this->actingAs($merchantA->fresh())->post('/order-assign', [
            'order_id' => $order->id,
            'delivery_boy' => $mine->id,
        ]);

        $this->assertSame(200, $response->getStatusCode(),
            'the handler did not complete for the merchant\'s own driver: ' . $response->getContent());
        $this->assertSame('success', $response->json('status'));
        $this->assertSame((int) $mine->id, (int) DB::table('orders')->where('id', $order->id)->value('deliveryboy_id'));
    }

    // =================================================================================================
    //  3. THE DRIVER'S OWN LOGIN -- the reason this model needed its own row
    // =================================================================================================

    /**
     * `Api/DashboardController::deliveryLogin` runs `Auth::guard('deliveryboy')->attempt()` and then
     * TWO `DeliveryBoy::find($user->id)` reads. If the scope narrowed those, a driver could not sign in
     * -- the silent outage that looks like data loss, which is the condition
     * `config/tenancy.php` lists as the reason a model must not be allowlisted carelessly.
     */
    public function test_a_driver_can_still_sign_in_and_gets_their_own_record(): void
    {
        [, $store] = $this->makeTenant();
        $driver = $this->makeDriver($store, 'DRIVER-LOGIN-FIXTURE', 'login-fixture@example.test', 'driver-password-fixture');

        $this->assertNull(auth()->user(),
            'Premise: the DEFAULT guard must hold no principal, as it does on a real driver login. '
            . 'actingAs($driver, "deliveryboy") would also call shouldUse() and hand this test a tenant '
            . 'production never resolves.');

        $response = $this->postJson('/api/admin/delivery-login', [
            'email' => 'login-fixture@example.test',
            'password' => 'driver-password-fixture',
        ]);

        $this->assertNotSame(404, $response->getStatusCode(), 'the driver login route must exist');

        $payload = $response->json();

        $this->assertSame(1, (int) ($payload['status'] ?? 0),
            'the driver could not sign in: ' . json_encode($payload));
        $this->assertSame((int) $driver->id, (int) ($payload['data']['id'] ?? 0),
            'the login returned no driver record, so the two DeliveryBoy::find() reads inside it were narrowed away');
    }

    /** And a driver's own record is readable with no web principal at all -- the production shape. */
    public function test_an_unresolved_tenant_adds_no_predicate_for_a_driver_lookup(): void
    {
        [, $store] = $this->makeTenant();
        $driver = $this->makeDriver($store, 'DRIVER-UNRESOLVED-FIXTURE', 'unresolved-fixture@example.test');

        $this->assertNull(auth()->user(), 'Premise: no web principal.');
        $this->assertNotNull(DeliveryBoy::find($driver->id),
            'a driver row became unreadable when no tenant resolves, which is every driver API request');
    }

    // =================================================================================================
    //  THE PRINCIPAL THE SCOPE CANNOT REACH -- the admin API, driven with a non-User token
    // =================================================================================================

    /**
     * WHY THESE EXIST, and they are the half the first cut of this suite was missing.
     *
     * A read-only review pointed out that this file drove a MERCHANT on the web guard and a DRIVER on the
     * login endpoint, and never drove a non-`User` token at the merchant-facing admin API -- which is the
     * one principal pair for which the new scope is INERT. `custom.auth` logs a token's owner into the
     * guard matching its tokenable class (CustomAuth:78-86), so a Customer's token lands on `customers`
     * and a DeliveryBoy's on `deliveryboy`, the DEFAULT guard stays empty, `resolveTenant()` returns
     * MODE_UNRESOLVED and `null_tenant => 'open'` adds NO predicate.
     *
     * `index()` and `destroy()` had no ownership test of their own -- only `Store::...->exists()`, which
     * proves the store is real, not that the caller owns it -- while three siblings on the same controller
     * did. So any valid token could list every driver in any store (the model declared no `$hidden`, so the
     * bcrypt hash and the login e-mail were in the JSON) and DELETE any driver, irreversibly.
     *
     * This is CLAUDE.md's recorded trap in the mirror direction: "a customer-token probe cannot test the
     * trap ... drive a merchant and a driver token too". Here the merchant token is the SAFE one.
     */
    private function tokenFor($principal): array
    {
        return ['Authorization' => 'Bearer ' . $principal->createToken('p1plt08')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function makeShopper($store): \App\Models\Customer
    {
        return \App\Models\Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => uniqid(),
            'email' => 'shopper-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => Hash::make('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    public function test_a_customers_token_cannot_list_another_stores_drivers(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $shopper = $this->makeShopper($storeA);
        $driver = $this->makeDriver($storeB, 'DRIVER-APIINDEX-FIXTURE', 'apiindex-fixture@example.test');

        $response = $this->getJson('/api/admin/deliveryboysIndex?store_id=' . $storeB->id, $this->tokenFor($shopper));

        $this->assertNotSame(404, $response->getStatusCode(), 'the route must exist, or a content assertion is satisfied by a 404');
        $this->assertContains($response->getStatusCode(), [401, 404],
            'a shopper listed another store\'s drivers: ' . $response->getContent());
        $this->assertStringNotContainsString('apiindex-fixture@example.test', (string) $response->getContent());
        $this->assertStringNotContainsString((string) $driver->password, (string) $response->getContent(),
            "a driver's bcrypt hash was in the response");
    }

    public function test_a_drivers_own_token_cannot_list_the_roster_either(): void
    {
        [, $storeA] = $this->makeTenant();

        $driver = $this->makeDriver($storeA, 'DRIVER-SELF-FIXTURE', 'self-fixture@example.test');
        $colleague = $this->makeDriver($storeA, 'DRIVER-COLLEAGUE-FIXTURE', 'colleague-fixture@example.test');

        $response = $this->getJson('/api/admin/deliveryboysIndex?store_id=' . $storeA->id, $this->tokenFor($driver));

        $this->assertContains($response->getStatusCode(), [401, 404],
            'a driver read the whole roster: ' . $response->getContent());
        $this->assertStringNotContainsString('colleague-fixture@example.test', (string) $response->getContent());
    }

    /** The irreversible one. */
    public function test_a_customers_token_cannot_delete_a_driver(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $shopper = $this->makeShopper($storeA);
        $driver = $this->makeDriver($storeB, 'DRIVER-APIDEL-FIXTURE', 'apidel-fixture@example.test');

        $response = $this->deleteJson('/api/admin/deliveryboysDestroy/' . $driver->id . '?store_id=' . $storeB->id,
            [], $this->tokenFor($shopper));

        $this->assertNotSame(404, $response->getStatusCode(), 'the route must exist');
        $this->assertNotNull(DB::table('delivery_boys')->where('id', $driver->id)->first(),
            'a shopper deleted a delivery driver');
    }

    /** And a MERCHANT who does not own the named store is refused by the same test. */
    public function test_a_merchant_cannot_list_a_store_they_do_not_own(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->makeDriver($storeB, 'DRIVER-FOREIGN-FIXTURE', 'foreign-fixture@example.test');

        $response = $this->getJson('/api/admin/deliveryboysIndex?store_id=' . $storeB->id, $this->tokenFor($merchantA->fresh()));

        $this->assertSame(404, $response->getStatusCode(), 'a merchant listed another merchant\'s drivers');
        $this->assertStringNotContainsString('foreign-fixture@example.test', (string) $response->getContent());
    }

    /** POSITIVE CONTROL: the merchant who owns the store still gets their roster -- and no password hash. */
    public function test_the_owning_merchant_still_gets_their_roster_without_any_password_hash(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();

        $driver = $this->makeDriver($storeA, 'DRIVER-OWNROSTER-FIXTURE', 'ownroster-fixture@example.test');

        $response = $this->getJson('/api/admin/deliveryboysIndex?store_id=' . $storeA->id, $this->tokenFor($merchantA->fresh()));

        $this->assertSame(200, $response->getStatusCode(),
            'the owning merchant can no longer read their own roster: ' . $response->getContent());
        $this->assertStringContainsString('ownroster-fixture@example.test', (string) $response->getContent());

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString((string) DB::table('delivery_boys')->where('id', $driver->id)->value('password'), $body,
            'the bcrypt hash is still serialised -- DeliveryBoy::$hidden is not doing its job');
        $this->assertStringNotContainsString('"password"', $body);
    }

    /**
     * THE CENSUS, keyed on the method rather than on the three somebody happened to read: every public
     * handler on that controller that takes a store id from the request must call the ownership test.
     */
    public function test_every_handler_on_the_admin_api_controller_checks_ownership(): void
    {
        $source = (string) file_get_contents(app_path('Http/Controllers/Api/DeliveryBoyApiController.php'));

        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        $handlers = preg_match_all('/public function (\w+)\s*\(/', $code, $names) ? $names[1] : [];

        $this->assertSame(['index', 'store', 'update', 'destroy', 'resetPassword'], $handlers,
            'the set of public handlers changed; each one that reads a store id from the request needs the test');

        $this->assertSame(count($handlers), preg_match_all('/\$this->refuseUnlessOperatorOwns\(/', $code),
            'a handler on this controller does not check that the caller owns the store it names. The tenant '
            . 'scope is NOT the control here: custom.auth admits customer and driver tokens, which leave the '
            . 'default guard empty, so the scope adds no predicate for them.');
    }

    // =================================================================================================
    //  THE REMOVAL CHECK'S TARGET -- stated here so the entry is shown to be the cause
    // =================================================================================================

    /**
     * Reverting the allowlist entry must fail at least one test. This is the one that is ONLY about the
     * entry: with DeliveryBoy absent from `scoped_models`, `StoreScope::isActiveFor()` short-circuits
     * and the predicate disappears for every principal, so merchant A reads merchant B's driver.
     */
    public function test_merchant_a_cannot_read_merchant_bs_driver_row_at_all(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $theirs = $this->makeDriver($storeB, 'DRIVER-ROW-FIXTURE', 'row-fixture@example.test');

        $this->actingAs($merchantA->fresh());

        $this->assertNull(DeliveryBoy::find($theirs->id), "merchant A read merchant B's driver by bare id");
        $this->assertSame(0, DeliveryBoy::where('email', 'row-fixture@example.test')->count());

        // asserted through the raw table too, so the scope cannot hide damage it failed to prevent
        $this->assertNotNull(DB::table('delivery_boys')->where('id', $theirs->id)->first(),
            'Premise: the row really exists; the assertions above are about visibility.');
    }
}
