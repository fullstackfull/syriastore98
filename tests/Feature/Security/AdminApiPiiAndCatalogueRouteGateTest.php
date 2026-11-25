<?php

namespace Tests\Feature\Security;

use App\Models\DeliveryBoy;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Support\Orders\FulfilmentStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * FOUR `api/admin/*` DOORS MEASURED ACROSS THE TENANT BOUNDARY, PLUS ONE STATE-MACHINE DOOR.
 * =================================================================================================
 *
 * WHY THIS FILE EXISTS AS A MEASUREMENT AND NOT AS A FIX. The four routes below
 * (`order-detail`, `getStoreInfo`, `searchproduct`, `addproduct`) were handed over as
 * "suspected ungated". A token/name census of the same family had already been tried on this
 * codebase and was unusable -- three of three spot checks were false positives -- because these
 * controllers spell a refusal in more than twenty helper names and a grep cannot tell a gate from
 * a comment about a gate. So nothing here is inferred from the source: every row is a real HTTP
 * request with a real Sanctum bearer token, and every "nothing was written" claim is read back
 * through the query builder.
 *
 * WHAT THE MEASUREMENT SAYS, AND IT IS NOT WHAT THE HANDOVER SUSPECTED. All four doors already
 * REFUSE a principal from another tenant. Each one resolves its target through
 * `resolveOwnedStore()` or `resolveChangeableOrder()`, both of which derive the tenant from the
 * AUTHENTICATED principal and never from the request. The methods below pin that, in both
 * directions, so that a later change which drops one of those calls turns this file red.
 *
 * ---------------------------------------------------------------------------------------------
 * THREE PRINCIPALS, BECAUSE ONE OF THEM CANNOT SEE THE TRAP.
 *
 *   1. A FOREIGN-TENANT MERCHANT holding a `User` token. `custom.auth` logs them into the `web`
 *      guard, so `Auth::user()` answers and the tenancy predicate is genuinely exercised.
 *   2. A FOREIGN-TENANT DELIVERY DRIVER holding a `DeliveryBoy` token. `custom.auth` logs a
 *      token's owner into the guard matching its TOKENABLE CLASS, so for a driver the `web` guard
 *      stays EMPTY -- `Auth::user()` is null and any handler shaped
 *      "if nobody is signed in, take the id from the request" takes the attacker's branch.
 *      A merchant probe cannot see that; this is the principal that finds it.
 *   3. A CUSTOMER of the victim store. Same mechanism, `customers` guard, and the one principal
 *      whose own guard a storefront handler would consult.
 *
 * Every refusal leg is followed by a CONTROL that proves the fixture was usable at all -- the
 * owning merchant gets the same row, or the write lands for them. A refusal with no control is
 * satisfied by a 404, a 500, a validator above the gate, or a fixture that refuses everybody.
 *
 * ---------------------------------------------------------------------------------------------
 * FIVE TRAPS THAT MAKE THIS MEASUREMENT READ BACKWARDS.
 *
 * (a) `ApiResponser::error()` ANSWERS HTTP 200 with `{status: 0}`, and its THIRD argument is the
 *     status code while its SECOND is the message -- so `error([...], 404)` puts `404` in the
 *     message and still sends 200. A 200 is therefore not evidence of admission, and every
 *     verdict here reads the ENVELOPE plus, for a write, the row.
 *
 * (b) `custom.auth` ends by logging the token's owner in, and the guards plus the session outlive
 *     a single request inside one test method. A stale principal resolves a tenant, which flips
 *     `StoreScope` from "no predicate" to a foreign `store_id` -- and `delivery_boys` IS a scoped
 *     model, so the morphTo behind a driver's token finds nothing and a VALID driver is answered
 *     "Unauthenticated". `resetAuth()` runs before every leg.
 *
 * (c) `Product` and `Category` carry `StoreScope` and are ABSENT from `config/tenancy.php`'s
 *     `scoped_models`, so the scope adds no predicate for any principal; `Customer`,
 *     `DeliveryBoy` and nine more DO filter, and 79 of 95 models are `Cachable`. Every row read
 *     in this file goes through `DB::table()`, which is the only read that is a statement about
 *     the table rather than about whichever tenant happened to resolve.
 *
 * (d) A FIXTURE CAN MANUFACTURE THE REFUSAL. `makeMerchant()` writes `created_by => 1`; with no
 *     operator row the first merchant IS user 1 and becomes their own tenant root by accident.
 *     `setUp()` creates the operator first and asserts they hold id 1.
 *
 * (e) `AddProduct` dereferences `Plan::find($user->plan_id)->max_products` with no null check and
 *     `Utility::upload_file()` refuses everything without a platform `storage_setting` row, so
 *     without both fixtures that door would answer a refusal-shaped envelope to EVERY principal
 *     and read as gated. Both are seeded, and the owner control proves it.
 *
 * ---------------------------------------------------------------------------------------------
 * THE FIFTH DOOR IS A WRITE, AND IT IS THE ONLY THING THIS FILE CHANGES.
 *
 * `api/admin/orders/update-status` writes `orders.delivered_status` DIRECTLY instead of calling
 * `Order::order_status_change()`, so it never inherited the fulfilment state machine that the
 * five other doors share. `Order::refuseIllegalTransition()`'s own docblock names it as the one
 * door the machine cannot reach. It had grown two rules of its own -- cancel only from Pending,
 * return never from Pending -- and nothing at all on the four progression arms, so a CANCELLED or
 * RETURNED order could be marched back to Delivered and into the refund workflow. Measured below
 * before the gate, and the gate is `FulfilmentStateMachine::refusalFor()`.
 */
class AdminApiPiiAndCatalogueRouteGateTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** Distinctive enough that "it reached the body" / "it did not" cannot pass by accident. */
    private const BUYER_EMAIL = 'pii-leak-probe-buyer@example.test';
    private const MARGIN = '4242.42';
    private const ATTACKER_PRODUCT = 'GATEPROBE-WRITTEN-BY-A-FOREIGN-TENANT';
    private const OWNER_PRODUCT = 'GATEPROBE-WRITTEN-BY-THE-OWNER';

    private User $operator;

    private User $merchant;

    private Store $store;

    private User $foreign;

    private Store $foreignStore;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * The `theme` disk's root is the repository itself, so faking it is not tidiness: without
         * it `addproduct` writes real files into the working tree.
         */
        Storage::fake('theme');
        Storage::fake('local');

        // Trap (d). The operator must hold id 1 before any merchant is created.
        $this->operator = $this->makePlatformAdmin();
        $this->assertSame(
            1,
            (int) User::orderBy('id')->value('id'),
            'premise: the platform operator must be user 1, or makeMerchant()\'s created_by => 1 '
            . 'makes the first merchant their own tenant root and every tenancy leg below passes '
            . 'or fails for the wrong reason.'
        );

        \Illuminate\Support\Facades\Cache::forget('admin_details');

        [$this->merchant, $this->store] = $this->makeTenant();
        [$this->foreign, $this->foreignStore] = $this->makeTenant();

        $this->seedPlatformStorage();
        $this->seedUnlimitedPlan();
    }

    // ═══════════════════════════════════════════════════════════════════════════════════ fixtures

    private function seedPlatformStorage(): void
    {
        foreach ([
            'storage_setting' => 'local',
            'local_storage_validation' => 'jpg,jpeg,png,csv,svg,pdf',
            'local_storage_max_upload_size' => '2048000',
        ] as $name => $value) {
            DB::table('settings')->updateOrInsert(
                ['name' => $name, 'store_id' => 1, 'created_by' => 1],
                ['value' => $value, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        (new \App\Models\Setting)->flushCache();
    }

    private function seedUnlimitedPlan(): void
    {
        $planId = (int) DB::table('plans')->insertGetId([
            'name' => 'gate-probe-unlimited-' . $this->uniqueFixtureRef(),
            'price' => '0',
            'themes' => 'stylique',
            'max_stores' => '-1',
            'max_products' => '-1',
            'max_users' => '-1',
            'storage_limit' => '-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')->update(['plan_id' => $planId]);
        (new User)->flushCache();

        foreach (['operator', 'merchant', 'foreign'] as $property) {
            $this->{$property} = User::find($this->{$property}->id);
        }
    }

    private function categoryIn(Store $store): int
    {
        return (int) DB::table('categories')->insertGetId([
            'name' => 'gate-probe-cat',
            'slug' => 'gate-probe-cat-' . $this->uniqueFixtureRef(),
            'parent_id' => 0,
            'trending' => 0,
            'status' => 1,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function productIn(Store $store, int $categoryId): int
    {
        $id = (int) DB::table('products')->insertGetId([
            'name' => 'gate-probe-product',
            'slug' => 'gate-probe-product-' . $this->uniqueFixtureRef(),
            'category_id' => $categoryId,
            'track_stock' => 0,
            'price' => 100,
            'sale_price' => 90,
            'cost_price' => self::MARGIN,
            'product_stock' => 50,
            'status' => 1,
            'variant_product' => 0,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new \App\Models\Product)->flushCache();

        return $id;
    }

    /**
     * An order carrying real PII, because `Order::order_detail()` reads the buyer's name, e-mail
     * and telephone out of `order_billing_details` for every non-POS order. Without that row the
     * fields it serves are empty strings and a leak assertion would be invisible -- the fixture,
     * not the gate, would be "protecting" the data.
     */
    private function orderWithPiiIn(Store $store, array $attributes = []): Order
    {
        $order = $this->makeOrder($store, array_merge([
            'delivered_status' => 0,
            'final_price' => 1234.56,
            'payment_type' => 'cod',
        ], $attributes));

        DB::table('order_billing_details')->insert([
            'order_id' => $order->id,
            'product_order_id' => $order->product_order_id,
            'first_name' => 'Nadia',
            'last_name' => 'Haddad',
            'email' => self::BUYER_EMAIL,
            'telephone' => '+963911111111',
            'address' => '14 Straight Street',
            'postcode' => '0100',
            'delivery_address' => '14 Straight Street',
            'delivery_postcode' => '0100',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $order;
    }

    private function driverIn(Store $store): DeliveryBoy
    {
        return DeliveryBoy::forceCreate([
            'name' => 'Driver ' . $this->uniqueFixtureRef(),
            'email' => 'driver' . $this->uniqueFixtureRef() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'contact' => '0100000000',
            // delivery_boys.created_by is NOT nullable.
            'created_by' => $store->created_by,
            'store_id' => $store->id,
        ]);
    }

    private function customerIn(Store $store): \App\Models\Customer
    {
        return \App\Models\Customer::forceCreate([
            'first_name' => 'Gate',
            'last_name' => 'Probe',
            'email' => 'gateprobe' . $this->uniqueFixtureRef() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'store_id' => $store->id,
            'status' => 1,
        ]);
    }

    private function image(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='
        ));
    }

    // ═══════════════════════════════════════════════════════════════════════════ driving the wire

    /** Trap (b). Put the process back to "nobody is signed in" between legs. */
    private function resetAuth(): void
    {
        foreach (array_keys(config('auth.guards')) as $guard) {
            if (config('auth.guards.' . $guard . '.driver') === 'session'
                && $this->app['auth']->guard($guard)->check()) {
                $this->app['auth']->guard($guard)->logout();
            }
        }

        $this->app['session']->flush();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
    }

    /** @param  \App\Models\User|\App\Models\DeliveryBoy|\App\Models\Customer  $tokenable */
    private function as($tokenable): self
    {
        $this->resetAuth();

        return $this->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $tokenable->createToken('gate_probe')->plainTextToken);
    }

    private function body(TestResponse $response): string
    {
        return substr((string) preg_replace('/\s+/', ' ', (string) $response->getContent()), 0, 400);
    }

    /**
     * NOT A SUCCESS, AND NOT A 401.
     *
     * The envelope rather than the code, because `error()` refuses at HTTP 200 (trap a) while the
     * store helpers refuse with an explicit 403 -- both are refusals and this family contains
     * both. And never 401: the shipped Flutter admin app force-clears its session on any 401, so
     * a 401 here signs a merchant out of the whole app instead of refusing one request.
     */
    private function assertRefused(TestResponse $response, string $who): void
    {
        $this->assertNotSame(
            401,
            $response->status(),
            $who . ' was refused with 401, which force-logs-out the shipped app. Body: ' . $this->body($response)
        );

        $status = $response->json('status');

        $this->assertTrue(
            $status !== 1 && $status !== 'success' && $response->json('success') !== true,
            $who . ' was ADMITTED. Body: ' . $this->body($response)
        );
    }

    private function assertAdmitted(TestResponse $response, string $who): void
    {
        $status = $response->json('status');

        $this->assertTrue(
            $status === 1 || $status === 'success' || $response->json('success') === true,
            $who . ' was REFUSED, so the leg above has no control and proves nothing. Body: '
            . $this->body($response)
        );
    }

    // ╔════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  1. GET api/admin/order-detail -- a customer's PII and the order's money               ║
    // ╚════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * MEASURED ALREADY REFUSING, AND NO GATE WAS ADDED HERE.
     *
     * `orderDetail()` passes the requested id through `resolveChangeableOrder()`, which admits
     * three principals and nobody else: the driver the order is ASSIGNED to, the platform
     * operator, and an actor whose own tenant owns the order's store (`ownedStoreIds()`, built
     * from `UserPolicy::merchantTenantRoot($actor)` and never from the request). A foreign
     * merchant, a foreign driver and a shopper all resolve to null and get the handler's own
     * `Order not found!` envelope, which is deliberately the same answer as a non-existent id so
     * the door is not an order-id oracle.
     *
     * THE SIBLING `vieworder` REFUSES ONE MORE PRINCIPAL AND THAT DIFFERENCE IS REAL, BUT IT IS
     * NOT THIS AXIS. `ViewOrder()` adds a permission limb (`Show Order` / `Manage Order` with a
     * store-owner limb above it), so an unpermissioned member of the OWNING store is refused
     * there and admitted here. That is a within-tenant finding, it is already pinned by name in
     * `AdminApiRouteBehaviouralCensus2Test`, and closing it is not this change -- see this file's
     * report. The cross-tenant axis, which is what is asserted here, is closed on both.
     */
    public function test_order_detail_refuses_every_principal_outside_the_orders_tenant(): void
    {
        $order = $this->orderWithPiiIn($this->store);
        $foreignDriver = $this->driverIn($this->foreignStore);
        $shopper = $this->customerIn($this->store);

        $url = '/api/admin/order-detail?order_id=' . $order->id;

        foreach ([
            'a foreign-tenant merchant' => $this->foreign,
            'a foreign-tenant delivery driver' => $foreignDriver,
            'a shopper of the order\'s own store' => $shopper,
        ] as $who => $principal) {
            $response = $this->as($principal)->getJson($url);

            $this->assertRefused($response, $who);
            $this->assertStringNotContainsString(
                self::BUYER_EMAIL,
                (string) $response->getContent(),
                'PII LEAK: the buyer\'s e-mail reached ' . $who
            );
            $this->assertStringNotContainsString(
                '1234.56',
                (string) $response->getContent(),
                'MONEY LEAK: the order\'s final price reached ' . $who
            );
            $this->assertStringNotContainsString(
                $order->product_order_id,
                (string) $response->getContent(),
                'the order reference reached ' . $who
            );
        }

        /*
         * THE CONTROL. Without it every assertion above is satisfied by a door that answers
         * "not found" to everybody -- a missing route, a validator, an exception swallowed into a
         * 200. This proves the fixture is readable and that the PII really is on this path.
         */
        $owner = $this->as($this->merchant)->getJson($url);

        $this->assertAdmitted($owner, 'the owning merchant');
        $this->assertStringContainsString(self::BUYER_EMAIL, (string) $owner->getContent(),
            'the owning merchant cannot read their own order\'s billing e-mail, so the leak '
            . 'assertions above were measuring an empty fixture rather than a gate.');
        $this->assertStringContainsString($order->product_order_id, (string) $owner->getContent());
    }

    /**
     * THE SELF-SERVICE LEG, PINNED SO NOBODY GATES IT AWAY.
     *
     * `order-detail` is declared inside the `//delivery boy` block of `routes/api.php` and is the
     * DRIVER app's order screen. `resolveChangeableOrder()` admits the driver named in
     * `orders.deliveryboy_id` on their own merits -- a `DeliveryBoy` is not a Laratrust subject,
     * `isAbleTo()` on one is fatal, and carrying the parcel IS the authorization. Adding a
     * merchant permission to this door would refuse every driver on the platform; that failure
     * mode has already shipped twice in this project.
     */
    public function test_order_detail_still_answers_the_driver_the_order_is_assigned_to(): void
    {
        $driver = $this->driverIn($this->store);
        $order = $this->orderWithPiiIn($this->store, ['deliveryboy_id' => $driver->id]);

        $response = $this->as($driver)->getJson('/api/admin/order-detail?order_id=' . $order->id);

        $this->assertAdmitted($response, 'the assigned delivery driver');
        $this->assertStringContainsString($order->product_order_id, (string) $response->getContent(),
            'the assigned driver no longer receives their own parcel\'s detail, which is the '
            . 'driver app\'s order screen.');
    }

    // ╔════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  2. GET api/admin/getStoreInfo -- the store owner's login e-mail                       ║
    // ╚════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * MEASURED ALREADY REFUSING, AND NO GATE WAS ADDED HERE.
     *
     * `getStoreOwnerInfo()` reads the store id from the query string and then hands it to
     * `resolveOwnedStore()`, so the caller-supplied value is only ever a SELECTOR inside the
     * caller's own tenant: for a non-operator the lookup carries
     * `created_by = UserPolicy::merchantTenantRoot($user)`, and a foreign or absent store resolves
     * to null. The handler answers an explicit 403 `Permission denied.` -- the same answer for an
     * unowned store and a non-existent one, so it is not a store-id oracle either.
     *
     * A DRIVER AND A SHOPPER ARE REFUSED BY THE SAME LINE FOR A DIFFERENT REASON: `custom.auth`
     * puts them in the `deliveryboy` / `customers` guard, `Auth::user()` is therefore null, and
     * `resolveOwnedStore()` fails closed on a null principal before it looks at any id.
     */
    public function test_get_store_info_refuses_every_principal_outside_the_stores_tenant(): void
    {
        $foreignDriver = $this->driverIn($this->foreignStore);
        $shopper = $this->customerIn($this->store);

        $url = '/api/admin/getStoreInfo?store_id=' . $this->store->id;
        $ownerEmail = (string) User::find($this->store->created_by)->email;

        $this->assertNotSame('', $ownerEmail, 'premise: the store owner has no e-mail to leak.');

        foreach ([
            'a foreign-tenant merchant' => $this->foreign,
            'a foreign-tenant delivery driver' => $foreignDriver,
            'a shopper of that store' => $shopper,
        ] as $who => $principal) {
            $response = $this->as($principal)->getJson($url);

            $this->assertRefused($response, $who);
            $this->assertStringNotContainsString(
                $ownerEmail,
                (string) $response->getContent(),
                'PII LEAK: the store owner\'s login e-mail reached ' . $who
            );
        }

        $owner = $this->as($this->merchant)->getJson($url);

        $this->assertAdmitted($owner, 'the owning merchant');
        $this->assertSame($ownerEmail, $owner->json('data.owner.email'),
            'the owning merchant cannot read their own store\'s owner block, so the leak '
            . 'assertions above were measuring a door that refuses everybody.');
    }

    // ╔════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  3. POST api/admin/searchproduct -- every product column, cost_price included          ║
    // ╚════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * MEASURED ALREADY REFUSING ACROSS TENANTS, AND NO GATE WAS ADDED HERE.
     *
     * `SearchProduct()` resolves the store through `resolveOwnedStore()` before it validates
     * anything, so a foreign store id is refused with `Store not found!` and the margin never
     * reaches the query. That matters more here than on most reads: the handler answers
     * `Product::where(...)->get()->toArray()` and `App\Models\Product` declares no `$hidden`, so
     * `cost_price` -- the merchant's margin -- is in the body. And `Product` is NOT in
     * `config/tenancy.php`'s `scoped_models`, so the global scope contributes NOTHING on this
     * path: `resolveOwnedStore()` is the only tenancy control there is.
     *
     * THE PERMISSION AXIS IS OPEN AND IS DELIBERATELY NOT TOUCHED HERE. The sibling one route
     * over, `searchProductsByName`, is gated on `Manage Products` precisely because it serves
     * `cost_price`; this door is not, and an unpermissioned member of the owning store reads the
     * margin. That is measured and pinned by name in `AdminApiRouteBehaviouralCensus4Test`
     * (`test_searchproduct_POST`), which asserts today's admission -- so adding the permission
     * limb inverts a test in a file this change is not permitted to edit. Reported, not landed.
     */
    public function test_searchproduct_refuses_every_principal_outside_the_stores_tenant(): void
    {
        $categoryId = $this->categoryIn($this->store);
        $this->productIn($this->store, $categoryId);

        $foreignDriver = $this->driverIn($this->foreignStore);
        $shopper = $this->customerIn($this->store);

        $payload = ['store_id' => $this->store->id, 'category_id' => $categoryId];

        foreach ([
            'a foreign-tenant merchant' => $this->foreign,
            'a foreign-tenant delivery driver' => $foreignDriver,
            'a shopper of that store' => $shopper,
        ] as $who => $principal) {
            $response = $this->as($principal)->postJson('/api/admin/searchproduct', $payload);

            $this->assertRefused($response, $who);
            $this->assertStringNotContainsString(
                self::MARGIN,
                (string) $response->getContent(),
                'MARGIN LEAK: cost_price reached ' . $who
            );
        }

        $owner = $this->as($this->merchant)->postJson('/api/admin/searchproduct', $payload);

        $this->assertAdmitted($owner, 'the owning merchant');
        $this->assertStringContainsString(self::MARGIN, (string) $owner->getContent(),
            'the owning merchant does not get cost_price either, so the margin assertions above '
            . 'were not measuring a withheld field at all.');
    }

    // ╔════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  4. POST api/admin/addproduct -- a money write                                         ║
    // ╚════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * MEASURED ALREADY REFUSING ACROSS TENANTS, AND NO GATE WAS ADDED HERE.
     *
     * `AddProduct()` carries two controls and the tenancy one is `resolveOwnedStore()`, so a
     * foreign store id is refused before the validator runs and before anything is uploaded or
     * inserted. The assertion is the ROW and not the envelope: `success()` and `error()` are both
     * HTTP 200 on this path, and `products` is `Cachable` and unscoped, so the read goes through
     * the query builder.
     *
     * THE PERMISSION AXIS IS OPEN AND IS DELIBERATELY NOT TOUCHED HERE, for the same reason as
     * `searchproduct`: `AdminApiRouteBehaviouralCensus3Test`
     * (`test_UNGATED_addproduct_lets_unpermissioned_staff_publish_a_priced_product`) asserts
     * today's admission by name, and this change may not edit that file. The web twin gates
     * creation on `Create Product` and `UpdateProduct` in this same controller was gated on
     * `Edit Products` on 2026-11-25 with a note saying AddProduct "needs its own measurement, not
     * an assumption". This is that measurement, and the gate is a paired change with the census.
     */
    public function test_addproduct_refuses_every_principal_outside_the_stores_tenant(): void
    {
        $categoryId = $this->categoryIn($this->store);
        $foreignDriver = $this->driverIn($this->foreignStore);
        $shopper = $this->customerIn($this->store);

        $attack = fn ($principal) => $this->as($principal)->post('/api/admin/addproduct', [
            'store_id' => $this->store->id,
            'name' => self::ATTACKER_PRODUCT,
            'category_id' => $categoryId,
            'description' => 'gate probe',
            'price' => 1234.5,
            'product_stock' => 7,
            'trending' => 0,
            'variant_product' => 0,
            'status' => 1,
            'cover_image' => $this->image('cover.png'),
            'product_image' => [$this->image('p1.png')],
        ]);

        foreach ([
            'a foreign-tenant merchant' => $this->foreign,
            'a foreign-tenant delivery driver' => $foreignDriver,
            'a shopper of that store' => $shopper,
        ] as $who => $principal) {
            $response = $attack($principal);

            $this->assertRefused($response, $who);
            $this->assertSame(
                0,
                (int) DB::table('products')->where('name', self::ATTACKER_PRODUCT)->count(),
                $who . ' published a priced product into another tenant\'s catalogue.'
            );
        }

        /*
         * THE CONTROL, and on this door it carries more weight than usual: `AddProduct` dies on a
         * null plan and `Utility::upload_file()` refuses everything without the platform storage
         * row, so a missing fixture would refuse every principal and read as a perfect gate.
         */
        $owner = $this->as($this->merchant)->post('/api/admin/addproduct', [
            'store_id' => $this->store->id,
            'name' => self::OWNER_PRODUCT,
            'category_id' => $categoryId,
            'description' => 'gate probe',
            'price' => 10.0,
            'product_stock' => 3,
            'trending' => 0,
            'variant_product' => 0,
            'status' => 1,
            'cover_image' => $this->image('cover.png'),
            'product_image' => [$this->image('p1.png')],
        ]);

        $this->assertAdmitted($owner, 'the owning merchant');

        $row = DB::table('products')->where('name', self::OWNER_PRODUCT)->first();

        $this->assertNotNull($row,
            'the owner got a success envelope and no row landed, so this door writes nothing '
            . 'under the suite and the three refusals above prove nothing.');
        $this->assertSame((int) $this->store->id, (int) $row->store_id);
    }

    // ╔════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  5. POST api/admin/orders/update-status -- the state-machine door                      ║
    // ╚════════════════════════════════════════════════════════════════════════════════════════╝

    /** `orders.delivered_status`, named from the machine's own constants. */
    private const PENDING = FulfilmentStateMachine::PENDING;
    private const DELIVERED = FulfilmentStateMachine::STATE_DELIVERED;
    private const CANCELLED = FulfilmentStateMachine::STATE_CANCELLED;
    private const RETURNED = FulfilmentStateMachine::STATE_RETURN;
    private const CONFIRMED = FulfilmentStateMachine::STATE_CONFIRMED;
    private const PICKED_UP = FulfilmentStateMachine::STATE_PICKED_UP;
    private const SHIPPED = FulfilmentStateMachine::STATE_SHIPPED;
    private const PARTIALLY_PAID = FulfilmentStateMachine::STATE_PARTIALLY_PAID;
    private const PRE_ORDER = FulfilmentStateMachine::STATE_PRE_ORDER;

    /**
     * A merchant who may change an order, which is NOT what `makeMerchant()` builds.
     *
     * `OrderPolicy::updateStatus` has an operator limb and a Laratrust limb and NO store-owner
     * limb, so a merchant holding no role is refused their own order there. Production hands every
     * self-registered merchant the shared global `admin` role carrying `Manage Order`, so that is
     * what the fixture does -- otherwise every transition below would be refused by the permission
     * gate and this whole section would measure nothing.
     */
    private function permittedMerchant(): User
    {
        return $this->grantMerchantGlobalRole(User::find($this->merchant->id), ['Manage Order']);
    }

    private function postStatus(User $actor, Order $order, array $payload): TestResponse
    {
        return $this->as($actor)->postJson('/api/admin/orders/update-status', array_merge([
            'product_order_id' => $order->product_order_id,
        ], $payload));
    }

    private function statusOf(Order $order): int
    {
        return (int) DB::table('orders')->where('id', $order->id)->value('delivered_status');
    }

    /**
     * THE PREMISE. Without it every refusal below is satisfied by a door that refuses everything:
     * a missing route, the permission gate, the `product_order_id` lookup, a 500.
     */
    public function test_premise_a_permitted_fulfilment_transition_still_lands(): void
    {
        $merchant = $this->permittedMerchant();
        $order = $this->makeOrder($this->store, ['delivered_status' => self::PENDING]);

        $response = $this->postStatus($merchant, $order, ['delivered_status' => self::CONFIRMED]);

        $this->assertSame(200, $response->status(), $this->body($response));
        $this->assertTrue($response->json('success'), $this->body($response));
        $this->assertSame(self::CONFIRMED, $this->statusOf($order),
            'Pending -> Confirmed no longer lands, so the refusals below cannot be attributed to '
            . 'the state machine.');
    }

    /**
     * THE DEFECT, STATE BY STATE. Each pair is one the machine refuses and this door permitted:
     * a SETTLED order (cancelled or returned) marched back into the fulfilment workflow, and
     * DELIVERY un-happening while `delivery_date` stays stamped.
     *
     * Why these pairs and not others: `Order::order_status_change()` -- the writer the other five
     * doors share -- refuses all of them through `FulfilmentStateMachine`, and this door is the one
     * `Order::refuseIllegalTransition()`'s docblock names as unreachable by it. Cancelled ->
     * Delivered is the money leg: `Order::product_return()` refuses unless `delivered_status == 1`
     * and then REDUCES `final_price`, so a cancelled order whose merchant entitlement has already
     * been walked back could be walked into the refund workflow.
     *
     * The row is read through the query builder: `Order` is `Cachable`, so an Eloquent read can
     * serve a mutated in-memory instance and report a write that never happened -- or hide one.
     */
    public static function refusedTransitions(): array
    {
        return [
            'cancelled -> delivered (the refund leg)' => [self::CANCELLED, self::DELIVERED],
            'cancelled -> confirmed' => [self::CANCELLED, self::CONFIRMED],
            'cancelled -> picked up' => [self::CANCELLED, self::PICKED_UP],
            'cancelled -> shipped' => [self::CANCELLED, self::SHIPPED],
            'cancelled -> return' => [self::CANCELLED, self::RETURNED],
            'returned -> delivered' => [self::RETURNED, self::DELIVERED],
            'returned -> confirmed' => [self::RETURNED, self::CONFIRMED],
            'delivered -> confirmed (delivery un-happens)' => [self::DELIVERED, self::CONFIRMED],
            'delivered -> picked up' => [self::DELIVERED, self::PICKED_UP],
            'delivered -> shipped' => [self::DELIVERED, self::SHIPPED],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedTransitions')]
    public function test_a_transition_the_state_machine_refuses_is_refused_at_this_door(
        int $from,
        int $to
    ): void {
        $merchant = $this->permittedMerchant();
        $order = $this->makeOrder($this->store, ['delivered_status' => $from]);

        $this->assertSame($from, $this->statusOf($order), 'premise: the fixture did not take.');

        $response = $this->postStatus($merchant, $order, ['delivered_status' => $to]);

        /* A 500 also leaves the row unmoved -- see the terminal-reopening case for the measured
           occasion on which that made ten of these pass while proving nothing. */
        $this->assertSame(200, $response->status(),
            'the door answered HTTP ' . $response->status() . ', so the row read below is not '
            . 'evidence of a refusal. Body: ' . $this->body($response));

        $this->assertSame($from, $this->statusOf($order),
            'delivered_status moved ' . $from . ' -> ' . $to . ', which the shared writer refuses. '
            . 'Body: ' . $this->body($response));

        $this->assertFalse($response->json('success'),
            'the envelope reports success for a transition the state machine refuses. Body: '
            . $this->body($response));

        /*
         * THE REASON, NOT JUST THE REFUSAL. "Nothing moved" is also what a 500, the permission
         * gate, a missing fixture and the no-change short-circuit produce, and a mutant that
         * changes WHICH rule refuses is invisible to a test that asks only "whether".
         */
        $this->assertContains(
            (string) $response->json('message'),
            [FulfilmentStateMachine::MESSAGE_SETTLED, FulfilmentStateMachine::MESSAGE_NOT_PERMITTED],
            'the row did not move, but not for the state machine\'s reason. Body: '
            . $this->body($response)
        );
    }

    /** NEVER 401, and never a 500: HTTP 200 with `success: false`, this handler's own shape. */
    public function test_the_state_machine_refusal_keeps_this_handlers_refusal_shape(): void
    {
        $merchant = $this->permittedMerchant();
        $order = $this->makeOrder($this->store, ['delivered_status' => self::CANCELLED]);

        $response = $this->postStatus($merchant, $order, ['delivered_status' => self::DELIVERED]);

        $this->assertSame(200, $response->status(),
            'the refusal changed this door\'s HTTP contract. A 401 force-logs-out the shipped app, '
            . 'and 403/404 are already this handler\'s permission and lookup refusals -- a client '
            . 'cannot tell them apart from this. Body: ' . $this->body($response));
        $this->assertFalse($response->json('success'));
        $this->assertNotNull($response->json('data'),
            'the other two transition refusals on this door answer with `data` => orderResponse(); '
            . 'this one must too, or the app sees a shape it has never seen.');
    }

    /**
     * THE TWO ESTABLISHED REFUSALS KEEP THEIR EXACT SENTENCES.
     *
     * `cancel` from a non-pending state and `return` from Pending were already refused here by
     * rules of this handler's own (P4-F104 / P4-F105), with two strings a client may be matching
     * on. The state-machine call is placed BELOW both, so those two keep answering first and this
     * change adds the four progression arms rather than rewording anything shipped. The machine's
     * own sentences for the same pairs are different strings; if either appears here, the new
     * check has been placed above the old ones.
     */
    public function test_the_two_pre_existing_transition_refusals_are_unchanged(): void
    {
        $merchant = $this->permittedMerchant();

        $delivered = $this->makeOrder($this->store, ['delivered_status' => self::DELIVERED]);
        $cancelAttempt = $this->postStatus($merchant, $delivered, ['delivered_status' => self::CANCELLED]);

        $this->assertSame('Only a pending order can be cancelled.', $cancelAttempt->json('message'),
            'the established cancel refusal changed its wording. Body: ' . $this->body($cancelAttempt));
        $this->assertSame(self::DELIVERED, $this->statusOf($delivered));

        $pending = $this->makeOrder($this->store, ['delivered_status' => self::PENDING]);
        $returnAttempt = $this->postStatus($merchant, $pending, ['delivered_status' => self::RETURNED]);

        $this->assertSame('A pending order cannot be returned.', $returnAttempt->json('message'),
            'the established return refusal changed its wording. Body: ' . $this->body($returnAttempt));
        $this->assertSame(self::PENDING, $this->statusOf($pending));
    }

    /**
     * THE TWO VALUES THE MACHINE DOES NOT GOVERN ARE STILL ACCEPTED.
     *
     * `orders.delivered_status` holds nine values and two of them are not fulfilment states: 7 is
     * Partially Paid (a payment state) and 8 is Pre Order (an order type), both sharing the
     * column. `FulfilmentStateMachine::governs()` answers false for everything outside the six
     * status words the shared writer's branch chain matches, so neither has a word and neither is
     * governed -- which is what keeps `ApiOrderFilterStatusCoverageTest`'s
     * "the write accepts every value the column holds" true.
     */
    public function test_the_two_non_fulfilment_column_values_are_still_accepted(): void
    {
        $merchant = $this->permittedMerchant();

        foreach ([self::PARTIALLY_PAID, self::PRE_ORDER] as $status) {
            $order = $this->makeOrder($this->store, ['delivered_status' => self::PENDING]);

            $response = $this->postStatus($merchant, $order, ['delivered_status' => $status]);

            $this->assertTrue($response->json('success'),
                'delivered_status=' . $status . ' was refused, and the column holds it. Body: '
                . $this->body($response));
            $this->assertSame($status, $this->statusOf($order));
        }
    }

    /**
     * A PAYMENT-ONLY CALL IS NOT A FULFILMENT TRANSITION, so no fulfilment rule may touch it.
     *
     * The shipped app sends `payment_status` on every call to this endpoint. The new check is
     * asked only when a delivery status was actually requested; a payment-only request must reach
     * the write exactly as it did before.
     *
     * THE SETTLED ORDER IS THE LEG THAT CAN FAIL, and it is here because the PENDING one cannot:
     * with the order at Pending there is no word for the state the machine could be asked about,
     * so "the fulfilment rule is not consulted" and "it is consulted and permits" give the same
     * answer and a mutant deleting the `$hasDeliveredStatus` guard survives. A CANCELLED order
     * separates them -- `refusalFor(2, 'cancel')` refuses -- and recording a settlement on a
     * cancelled order is a real flow: owner decision P4-F124 made `orders.payment_status` the
     * customer's ACTUAL settlement rather than a wallet trigger, precisely so that a cancellation
     * stops rewriting it, which only makes sense if it can still be written afterwards.
     */
    public function test_a_payment_only_change_is_untouched_by_the_fulfilment_rule(): void
    {
        $merchant = $this->permittedMerchant();

        foreach ([self::PENDING, self::CANCELLED, self::RETURNED, self::DELIVERED] as $from) {
            $order = $this->makeOrder($this->store, [
                'delivered_status' => $from,
                'payment_status' => 'Unpaid',
            ]);

            $response = $this->postStatus($merchant, $order, ['payment_status' => 'Paid']);

            $this->assertTrue($response->json('success'),
                'a payment-only call was refused for an order at delivered_status=' . $from
                . ', so a fulfilment rule is being asked about a request that names no '
                . 'fulfilment transition. Body: ' . $this->body($response));
            $this->assertSame(
                'Paid',
                (string) DB::table('orders')->where('id', $order->id)->value('payment_status'),
                'the payment status did not move for an order at delivered_status=' . $from
            );
            $this->assertSame($from, $this->statusOf($order),
                'a payment-only call moved the delivery status.');
        }
    }

    /**
     * THE TWO-HOP BYPASS, AND IT DEFEATS THE ONE-STEP REFUSAL COMPLETELY.
     * =============================================================================================
     *
     * MY OWN FIRST GATE HAD THIS HOLE AND ITS DOCBLOCK ARGUED FOR IT. The word map leaves Pending
     * unmapped, because `FulfilmentStateMachine::governs()` has no `pending` word and
     * `Order::order_status_change()` has no pending arm -- so I reasoned that leaving a move to
     * Pending ungoverned made this door agree with the shared writer. THAT REASONING WAS WRONG, and
     * the asymmetry is the whole point: the shared writer cannot be ASKED to move an order to
     * Pending (no arm matches the word), while this door writes the integer directly and can. So
     * "ungoverned" means something different on the two surfaces, and on this one it is an escape.
     *
     * `PERMITTED[PENDING]` is `[CANCEL, CONFIRMED, PICKED_UP, SHIPPED, DELIVERED]` -- five of the
     * six governed words. So every refused pair above except the `-> return` ones is reachable in
     * TWO requests: `2 -> 0` is ungoverned, and `0 -> 1` is permitted. A one-step refusal that is
     * bypassed by inserting one extra request raises the attacker's cost by one HTTP call and
     * removes nothing.
     *
     * CLOSING IT IS NOT A NEW BUSINESS RULE, which is what makes it mine to close rather than the
     * owner's. `FulfilmentStateMachine::TERMINAL` is `[STATE_CANCELLED, STATE_RETURN]` and its
     * docblock already says "Nothing leaves them through the shared writer"; `PERMITTED` carries no
     * row for either, so `matrix()[2]` and `matrix()[3]` are both `[]`. The invariant is already
     * published -- the escape exists because Pending is missing from the WORD vocabulary, not
     * because anybody decided a settled order may be reopened.
     *
     * READ FROM `TERMINAL` AND `PERMITTED[PENDING]` RATHER THAN TYPED OUT, so that a change to
     * either in the machine moves this test with it instead of leaving it pinning a stale pair.
     */
    public static function terminalReopeningRoutes(): array
    {
        $cases = [];

        foreach (FulfilmentStateMachine::TERMINAL as $terminal) {
            foreach (FulfilmentStateMachine::matrix()[FulfilmentStateMachine::PENDING] as $word) {
                $cases[$terminal . ' -> pending -> ' . $word] = [$terminal, $word];
            }
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('terminalReopeningRoutes')]
    public function test_a_settled_order_cannot_be_reopened_by_going_through_pending(
        int $terminal,
        string $word
    ): void {
        $merchant = $this->permittedMerchant();
        $order = $this->makeOrder($this->store, ['delivered_status' => $terminal]);

        $this->assertSame($terminal, $this->statusOf($order), 'premise: the fixture did not take.');

        /* HOP 1 -- the move the word map does not govern. */
        $first = $this->postStatus($merchant, $order, ['delivered_status' => self::PENDING]);

        /*
         * THE STATUS AND THE REASON BEFORE THE ROW, AND THIS IS NOT BELT-AND-BRACES -- IT CAUGHT A
         * FALSE GREEN IN THIS VERY FILE. When the shared predicate was renamed on disk mid-change,
         * this door raised `Call to undefined method` and answered HTTP 500. The row then did not
         * move, so the bare "it is still settled" assertion below PASSED for all ten cases and
         * reported the bypass closed. That is CLAUDE.md's first way a test passes for the wrong
         * reason, measured here rather than quoted.
         */
        $this->assertSame(200, $first->status(),
            'HOP 1 did not reach the guard: the door answered HTTP ' . $first->status()
            . '. A 500 leaves the row unmoved for a reason that is not a refusal, which makes the '
            . 'assertion below pass while proving nothing. Body: ' . $this->body($first));

        $this->assertFalse($first->json('success'),
            'HOP 1 reported success. Body: ' . $this->body($first));

        $this->assertSame(FulfilmentStateMachine::MESSAGE_SETTLED, $first->json('message'),
            'HOP 1 was refused, but not as a settled row -- so a different rule answered and this '
            . 'case is not measuring the terminal invariant. Body: ' . $this->body($first));

        $this->assertSame(
            $terminal,
            $this->statusOf($order),
            'HOP 1 LANDED: a settled order (delivered_status=' . $terminal . ') was moved to '
            . 'Pending, which `FulfilmentStateMachine::TERMINAL` says nothing may leave. From '
            . 'Pending, `PERMITTED` admits ' . $word . ' -- so the one-step refusal is bypassed by '
            . 'one extra request. Body: ' . $this->body($first)
        );

        /*
         * HOP 2 IS DRIVEN ANYWAY, because asserting only on hop 1 would make this case pass if hop
         * 1 were refused for some unrelated reason while a different route to the same end state
         * stayed open. The end state is the property; the hop is the mechanism.
         */
        $target = array_search($word, [
            self::DELIVERED => FulfilmentStateMachine::DELIVERED,
            self::CANCELLED => FulfilmentStateMachine::CANCEL,
            self::RETURNED => FulfilmentStateMachine::RETURNED,
            self::CONFIRMED => FulfilmentStateMachine::CONFIRMED,
            self::PICKED_UP => FulfilmentStateMachine::PICKED_UP,
            self::SHIPPED => FulfilmentStateMachine::SHIPPED,
        ], true);

        $this->assertNotFalse($target, 'the word ' . $word . ' has no integer this door can express.');

        $second = $this->postStatus($merchant, $order, ['delivered_status' => $target]);

        $this->assertSame(200, $second->status(),
            'HOP 2 answered HTTP ' . $second->status() . ', so the end-state assertion below holds '
            . 'for a reason that is not a refusal. Body: ' . $this->body($second));

        $this->assertSame(
            $terminal,
            $this->statusOf($order),
            'a settled order reached delivered_status=' . $target . ' in two requests. '
            . 'Body: ' . $this->body($second)
        );
    }

    /**
     * EVERY STATUS THIS DOOR ACCEPTS IS EITHER TRANSLATED OR DELIBERATELY EXCLUDED.
     *
     * The gate turns the request's INTEGER into one of the six status words the machine governs,
     * through a private map in the controller. A tenth value added to the column and to this
     * door's validator -- which is itself built from `HomeController`'s constants, so widening it
     * is a one-line change somebody will make -- would silently arrive UNGOVERNED, because a
     * missing key yields `null` and `governs(null)` is false. There is no behaviour to drive for a
     * value that does not exist yet, so this is asserted structurally: the map's key set must be
     * exactly the column's vocabulary minus the three values the map's own docblock excludes, and
     * each exclusion is named here so that adding a fourth has to be a decision.
     *
     * Read by reflection rather than made public: the map is an implementation detail of one
     * handler and widening its visibility to be testable would be the test changing the code.
     */
    public function test_every_delivered_status_this_door_accepts_is_mapped_or_named_as_excluded(): void
    {
        $map = (new \ReflectionClass(\App\Http\Controllers\Api\DashboardController::class))
            ->getConstant('FULFILMENT_WORD_FOR_STATUS');

        $this->assertIsArray($map, 'the controller no longer declares the status word map.');

        /* The column's whole vocabulary, from the constants the door's own validator is built on. */
        $vocabulary = array_filter(
            (new \ReflectionClass(\App\Http\Controllers\HomeController::class))->getConstants(),
            fn ($value, $name) => str_starts_with($name, 'ORDER_STATUS_'),
            ARRAY_FILTER_USE_BOTH
        );

        $this->assertCount(9, $vocabulary,
            'the column vocabulary is no longer nine values, so the exclusions below need re-reading.');

        /*
         * AN EARLIER VERSION OF THIS TEST LISTED PENDING HERE WITH A REASON, AND THAT WAS THE
         * RATCHET THAT WOULD HAVE HELD THE BYPASS IN PLACE. It asserted that Pending must NOT be
         * in the map -- true, since the machine has no `pending` word to map it to -- and offered
         * that as evidence the omission was safe. It is not evidence of anything of the kind: an
         * unmapped status is one `refusalFor()` cannot speak about, and `2 -> 0 -> 1` is what that
         * bought. A structural test that certifies an omission as deliberate is worth less than
         * nothing if the omission is also a hole.
         *
         * SO THE QUESTION ASKED HERE IS DIFFERENT NOW. An unmapped status is acceptable only if
         * SOMETHING ELSE still refuses it out of a settled row, and that something is
         * `refusalForLeaving()`, which asks about the row rather than the target. Every unmapped
         * value is put to it, against every state the machine calls TERMINAL. Nothing is listed by
         * hand and nothing is excused by a sentence.
         */
        $unmapped = [];

        foreach ($vocabulary as $name => $status) {
            if (array_key_exists($status, $map)) {
                continue;
            }

            $unmapped[$name] = $status;
        }

        $this->assertNotSame([], $unmapped,
            'every status is now mapped to a word, which would make the check below vacuous. If '
            . 'the machine has grown a `pending` word, this test should be asserting the map '
            . 'instead.');

        /*
         * DRIVEN, NOT ASKED OF THE MACHINE. An earlier draft asserted this against a machine
         * predicate, which answers what the MACHINE would say and not what this DOOR does -- and
         * the door is the thing with the hole. Every unmapped status is pushed at a row in every
         * state the machine calls settled, over HTTP, and the row is read back.
         */
        $merchant = $this->permittedMerchant();

        foreach ($unmapped as $name => $status) {
            foreach (FulfilmentStateMachine::TERMINAL as $terminal) {
                $order = $this->makeOrder($this->store, ['delivered_status' => $terminal]);
                $response = $this->postStatus($merchant, $order, ['delivered_status' => $status]);

                $this->assertSame(200, $response->status(),
                    $name . ' out of delivered_status=' . $terminal . ' answered HTTP '
                    . $response->status() . ', so the row read below means nothing. Body: '
                    . $this->body($response));

                $this->assertSame($terminal, $this->statusOf($order),
                    $name . ' (' . $status . ') has no state-machine word, so the word-based '
                    . 'refusal is silent about it -- and it moved a SETTLED row out of '
                    . 'delivered_status=' . $terminal . ' anyway. That is the second half of how '
                    . '`2 -> 0 -> 1` got through, and `PERMITTED` admits `delivered` from both 7 '
                    . 'and 8. Body: ' . $this->body($response));
            }
        }

        /* And the ones that ARE mapped must be words the machine actually governs. */
        foreach ($map as $status => $word) {
            $this->assertTrue(FulfilmentStateMachine::governs($word),
                'delivered_status=' . $status . ' is mapped to "' . $word . '", which the state '
                . 'machine does not govern -- so the refusal can never fire for it.');
        }
    }

    /**
     * THE DOOR'S ANSWER FOR AN UNTRANSLATED TARGET IS THE MACHINE'S ANSWER, DERIVED NOT TYPED.
     * =============================================================================================
     *
     * `-> Pending` is the move that cannot be expressed as a governed word, so it is the move whose
     * handling has to be read off the machine rather than decided here. For every state this door
     * can start from, the expectation is `refusalForLeaving($from) === null`: refuse exactly when
     * the machine refuses the row leaving, permit otherwise.
     *
     * WRITTEN THIS WAY DELIBERATELY, AND IT IS THE DIFFERENCE BETWEEN A RATCHET AND A PIN. Asserting
     * "1 -> 0 succeeds" would record today's behaviour as correct and hold it there. Asserting "the
     * door agrees with `refusalForLeaving()`" records the COUPLING, so the day that predicate is
     * widened -- `PERMITTED[STATE_DELIVERED]`'s own comment already says "Never backwards", which is
     * an argument for widening it to Delivered, and that is the machine owner's call and not mine --
     * this test starts demanding the new behaviour from the door instead of defending the old.
     *
     * THE RESIDUAL IS THEREFORE STATED RATHER THAN HIDDEN: with `TERMINAL` as it stands,
     * `1 -> 0 -> 4/5/6` still reaches a state the one-step rule refuses. Reported to the machine's
     * owner; not closed here, because a second local predicate is exactly the drift the shared one
     * exists to prevent.
     */
    public function test_the_door_refuses_a_move_to_pending_exactly_when_the_machine_does(): void
    {
        $merchant = $this->permittedMerchant();
        $disagreements = [];

        foreach (array_keys(FulfilmentStateMachine::matrix()) as $from) {
            $order = $this->makeOrder($this->store, ['delivered_status' => $from]);

            $response = $this->postStatus($merchant, $order, ['delivered_status' => self::PENDING]);

            /*
             * PENDING -> PENDING IS NOT A MOVE, and this case cannot be read with the others --
             * the row is already 0, so "it landed" and "it was always there" give the same answer
             * and the comparison below would call a no-op a disagreement. It is asserted on its
             * own terms instead of skipped: the handler's established no-change short-circuit
             * answers first, above the fulfilment rule, and `MerchantCancelRestockTest` depends on
             * that branch existing and on a `note` being what defeats it.
             */
            if ((int) $from === self::PENDING) {
                $this->assertSame('Order already has the requested statuses.', $response->json('message'),
                    'the no-change short-circuit no longer answers Pending -> Pending, so the '
                    . 'fulfilment rule has been placed above it. Body: ' . $this->body($response));

                continue;
            }

            $landed = $this->statusOf($order) === self::PENDING;
/*
             * `refusalForLeaving()` and not a target-level call, because the target here IS the
             * untranslated one: `refusalFor($from, null)` is null by construction, so asking it
             * would make this comparison vacuous. The row-level question is the whole of what the
             * machine says about a move to Pending -- an earlier draft of the shared predicate also
             * refused every untranslated target, which refused 7 and 8 out of a Pending row and
             * undid `P2-07-12`; it was withdrawn.
             */
            $machineRefuses = FulfilmentStateMachine::refusalForLeaving($from) !== null;

            if ($landed === $machineRefuses) {
                $disagreements[] = $from . ' -> 0: the door ' . ($landed ? 'permitted' : 'refused')
                    . ' it, the machine ' . ($machineRefuses ? 'refuses' : 'permits')
                    . ' an untranslated target from that state';
            }
        }

        $this->assertSame([], $disagreements,
            "this door and `FulfilmentStateMachine::refusalForLeaving()` disagree about "
            . "which rows may be moved to Pending:\n  " . implode("\n  ", $disagreements));
    }

    /**
     * A FORWARD STEP THEN A RETURN IS THE WORKFLOW, NOT A BYPASS -- pinned so nobody "closes" it.
     *
     * `0 -> 3` is refused ("A pending order cannot be returned."), and `0 -> 4 -> 3` lands. That
     * reads like the same two-hop shape as the Pending escape and is the opposite of it: the
     * refusal's own established sentence is *"Your order has not been delivered. You can return the
     * order after delivery."*, and `matrix()[STATE_CONFIRMED]` contains `return` BY DESIGN. The
     * order progressed first, which is exactly what the rule asks for.
     *
     * ASSERTED AGAINST `matrix()` RATHER THAN TYPED, so that if the machine's owner ever removes
     * `return` from the in-flight rows this case stops claiming the route is intended.
     */
    public function test_a_return_after_a_forward_step_is_the_workflow_and_still_lands(): void
    {
        $this->assertContains(
            FulfilmentStateMachine::RETURNED,
            FulfilmentStateMachine::matrix()[self::CONFIRMED],
            'premise: the machine no longer admits `return` out of Confirmed, so this route is no '
            . 'longer the intended workflow and this case should not be pinning it.'
        );

        $merchant = $this->permittedMerchant();
        $order = $this->makeOrder($this->store, ['delivered_status' => self::PENDING]);

        $direct = $this->postStatus($merchant, $order, ['delivered_status' => self::RETURNED]);

        $this->assertSame('A pending order cannot be returned.', $direct->json('message'),
            'premise: the one-step refusal is gone, so the two steps below demonstrate nothing. '
            . 'Body: ' . $this->body($direct));
        $this->assertSame(self::PENDING, $this->statusOf($order));

        $this->postStatus($merchant, $order, ['delivered_status' => self::CONFIRMED]);
        $this->assertSame(self::CONFIRMED, $this->statusOf($order), 'the forward step was refused.');

        $this->postStatus($merchant, $order, ['delivered_status' => self::RETURNED]);
        $this->assertSame(self::RETURNED, $this->statusOf($order),
            'a merchant can no longer return an order that has progressed, which is the workflow '
            . 'the refusal above exists to sequence rather than to forbid.');
    }

    /**
     * A MEASURED RESIDUAL, PINNED RATHER THAN HIDDEN -- AND NOT MINE TO CLOSE.
     * =============================================================================================
     *
     * MEASURED: `1 -> 7 -> 4` lands. A DELIVERED order moves to Partially Paid, which this door
     * exempts from the fulfilment rule because the machine says it does not govern it, and
     * `matrix()[STATE_PARTIALLY_PAID]` then admits `confirmed` -- so delivery un-happens in two
     * requests while the one-step `1 -> 4` is refused. The same holds for 5, 6 and for 8 as the
     * waypoint: six routes, enumerated against the machine and then driven here.
     *
     * WHY IT IS NOT CLOSED IN THIS CHANGE, and the reasoning is the point rather than the excuse:
     *
     *   - IT IS NOT THE MONEY FAMILY. The settled-row question closes every route out of CANCELLED
     *     and RETURN, so `2 -> * -> 1` (the leg `Order::product_return()` gates on before it
     *     REDUCES `final_price`) is gone, and `matrix()[STATE_PARTIALLY_PAID]` holds no `cancel`,
     *     so `1 -> 7 -> 2` -- a cancel restoring stock for delivered goods -- is refused too. What
     *     is left is fulfilment ORDERING, not an entitlement.
     *   - CLOSING IT NEEDS A RULE THAT DOES NOT EXIST YET. `PERMITTED[STATE_DELIVERED]`'s comment
     *     says "Never backwards", but `TERMINAL` deliberately excludes Delivered and no row of
     *     `PERMITTED` can speak about 7 or 8 as a TARGET, because they have no words. So the fix is
     *     either to govern them or to add a row-level backwards question -- both changes to
     *     `FulfilmentStateMachine`, which is the machine owner's file and not mine for this change.
     *   - THE OTHER AVAILABLE FIX IS WORSE. Dropping this door's exemption for 7 and 8 would refuse
     *     them from a PENDING row as well, which undoes P2-07-12 on a path a shipped client calls.
     *
     * WHOEVER CLOSES IT INVERTS THE ASSERTION BELOW. That is what this pin is for; the same
     * convention as the `test_UNGATED_*` rows of the behavioural censuses, and GAP-250 records a
     * pin of exactly this kind going red the moment its gate landed.
     */
    public function test_MEASURED_a_delivered_order_still_moves_backwards_through_an_ungoverned_waypoint(): void
    {
        $merchant = $this->permittedMerchant();

        /* The premise: the one-step move really is refused, or there is no residual to measure. */
        $blocked = $this->makeOrder($this->store, ['delivered_status' => self::DELIVERED]);
        $this->postStatus($merchant, $blocked, ['delivered_status' => self::CONFIRMED]);
        $this->assertSame(self::DELIVERED, $this->statusOf($blocked),
            'premise: `1 -> 4` is no longer refused in one step, so there is no bypass here.');

        foreach ([self::PARTIALLY_PAID, self::PRE_ORDER] as $waypoint) {
            $this->assertContains(
                FulfilmentStateMachine::CONFIRMED,
                FulfilmentStateMachine::matrix()[$waypoint],
                'premise: the machine no longer admits `confirmed` out of delivered_status='
                . $waypoint . ', so this route is closed and this case must be inverted.'
            );

            $order = $this->makeOrder($this->store, ['delivered_status' => self::DELIVERED]);

            $this->postStatus($merchant, $order, ['delivered_status' => $waypoint]);
            $this->assertSame($waypoint, $this->statusOf($order),
                'the waypoint hop is refused now -- invert this case, the residual is closed.');

            $this->postStatus($merchant, $order, ['delivered_status' => self::CONFIRMED]);
            $this->assertSame(self::CONFIRMED, $this->statusOf($order),
                'the second hop is refused now -- invert this case, the residual is closed.');
        }

        /* AND THE TWO MONEY LEGS OUT OF DELIVERED ARE NOT REACHABLE THIS WAY, which is what bounds
           the residual above to fulfilment ordering. A cancel restores stock. */
        $this->assertNotContains(
            FulfilmentStateMachine::CANCEL,
            FulfilmentStateMachine::matrix()[self::PARTIALLY_PAID],
            'the machine now admits `cancel` out of Partially Paid, so `1 -> 7 -> 2` restores stock '
            . 'for goods already delivered and this residual is no longer bounded.'
        );

        $order = $this->makeOrder($this->store, ['delivered_status' => self::DELIVERED]);
        $this->postStatus($merchant, $order, ['delivered_status' => self::PARTIALLY_PAID]);
        $this->postStatus($merchant, $order, ['delivered_status' => self::CANCELLED]);
        $this->assertSame(self::PARTIALLY_PAID, $this->statusOf($order),
            'a delivered order was cancelled through the Partially Paid waypoint, which reverses '
            . 'the merchant entitlement and credits stock for goods that were delivered.');
    }

    /**
     * THE DOOR AND THE SHARED WRITER NOW AGREE, which is the property the gate is for rather than
     * the ten pairs above.
     *
     * Driven as a unit assertion over the machine itself plus one HTTP leg per disagreement, so
     * that a tenth status value added to the column later cannot leave this door behind: the
     * matrix is read from `FulfilmentStateMachine::matrix()` and not typed out.
     */
    public function test_every_governed_pair_this_door_can_express_agrees_with_the_shared_writer(): void
    {
        $merchant = $this->permittedMerchant();

        $words = [
            self::DELIVERED => FulfilmentStateMachine::DELIVERED,
            self::CANCELLED => FulfilmentStateMachine::CANCEL,
            self::RETURNED => FulfilmentStateMachine::RETURNED,
            self::CONFIRMED => FulfilmentStateMachine::CONFIRMED,
            self::PICKED_UP => FulfilmentStateMachine::PICKED_UP,
            self::SHIPPED => FulfilmentStateMachine::SHIPPED,
        ];

        $checked = 0;
        $disagreements = [];

        foreach (array_keys(FulfilmentStateMachine::matrix()) as $from) {
            foreach ($words as $to => $word) {
                if (FulfilmentStateMachine::permits($from, $word)) {
                    continue;
                }

                $checked++;

                $order = $this->makeOrder($this->store, ['delivered_status' => $from]);
                $this->postStatus($merchant, $order, ['delivered_status' => $to]);

                if ($this->statusOf($order) !== $from) {
                    $disagreements[] = $from . ' -> ' . $to . ' (' . $word . ')';
                }
            }
        }

        $this->assertGreaterThan(20, $checked,
            'only ' . $checked . ' refused pairs were found in the machine\'s matrix, so this '
            . 'sweep would pass vacuously. The matrix has shrunk or the word map has drifted.');

        $this->assertSame([], $disagreements,
            "this door still permits transitions the shared writer refuses:\n  "
            . implode("\n  ", $disagreements));
    }
}
