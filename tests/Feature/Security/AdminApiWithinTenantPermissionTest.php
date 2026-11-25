<?php

namespace Tests\Feature\Security;

use App\Models\DeliveryBoy;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * THE WITHIN-TENANT PERMISSION AXIS ON TWENTY-TWO `api/admin/*` DOORS.
 * =================================================================================================
 *
 * WHAT THIS AXIS IS, AND WHY IT IS NOT THE ONE THE EARLIER WORK CLOSED. The cross-tenant axis on
 * these routes was already closed and is measured elsewhere: a foreign merchant, a foreign driver
 * and a shopper of the victim store are all refused, because every one of these handlers derives
 * its store from the AUTHENTICATED principal (`resolveOwnedStore()` / `resolveChangeableOrder()`)
 * and never from request input. What was open is the principal the merchant HIRED: a real staff
 * login inside the owning store, holding no relevant permission, whom the tenancy predicate admits
 * BY DESIGN, because `User::creatorId()` resolves a staff row to their employer. A handler whose
 * only control is a tenancy predicate is ungated in fact, whatever its source reads like.
 *
 * The behavioural route censuses (`AdminApiRouteBehaviouralCensus{1,2,3,4}Test`) measured that and
 * pinned it. This file is the other half: the gate, and the three legs that make each gate a gate
 * rather than a lockout. The census pins were inverted in the same change, which is what the
 * project's own process requires -- a census row that still asserts admission after a gate lands
 * is a lie in the opposite direction.
 *
 * -------------------------------------------------------------------------------------------------
 * THREE LEGS PER DOOR, AND THE SECOND ONE IS WHAT MAKES THE FIRST MEAN ANYTHING.
 *
 *   1. UNPERMISSIONED STAFF OF THE OWNING STORE -- refused with an explicit 403, and the subject
 *      of the door is read back to prove nothing moved and nothing was served.
 *   2. A PERMITTED PRINCIPAL OF THE SAME STORE -- the same request, the same fixture, the same
 *      tenant, differing ONLY in holding the name the gate asks for. Admitted, AND the write lands
 *      or the withheld field appears. Without this leg every refusal above is equally satisfied by
 *      a gate that admits nobody, by a 404, by a validator above the gate, or by a fixture that
 *      refuses everybody -- which is a regression this project has already shipped (a `category_id`
 *      with no matching row refused every principal and the first draft of a sibling file passed
 *      vacuously).
 *   3. THE STORE'S OWN OWNER -- admitted. A merchant holds no permission implicitly, so a
 *      permission-only gate would lock the owner out of their own store; `callerMayActInStore()`
 *      carries an owner limb for exactly that reason and the known-outcome gate has caught this
 *      family refusing an owner once already.
 *
 * -------------------------------------------------------------------------------------------------
 * THE TRAPS THIS FILE HAD TO AVOID.
 *
 * (a) `ApiResponser::error()` ANSWERS HTTP 200 with `{status: 0}` and its THIRD argument is the
 *     status code, so a 200 is not evidence of admission and a refusal written through it is
 *     invisible to a status-code assertion. Every gate added here answers an explicit 403 and
 *     every verdict below reads the ENVELOPE as well as the code.
 *
 * (b) NEVER 401. The shipped Flutter admin app force-clears its session on any 401
 *     (`lib/network/network.dart`), so a 401 on a path a client calls signs the merchant out of
 *     the whole app instead of telling them they lack a right. Asserted on every refusal.
 *
 * (c) THE GUARDS AND THE SESSION OUTLIVE A REQUEST. `custom.auth` ends by logging the token's
 *     owner in, and that principal resolves a tenant for the NEXT request in the same test --
 *     which flips `StoreScope` from "no predicate" to a stale `store_id`, and `delivery_boys` IS a
 *     scoped model, so the morphTo behind a driver's token finds nothing and a VALID driver is
 *     answered "Unauthenticated". `resetAuth()` runs before every leg.
 *
 * (d) EVERY ROW IS READ THROUGH `DB::table()`, which is the only read that is a statement about
 *     the table. 79 of 95 models are `Cachable`, so an Eloquent read can serve the pre-write row
 *     for the rest of the test under `CACHE_STORE=array`; eleven models also really filter by
 *     tenant, so a `Model::find()` answers against whichever tenant happened to resolve. The query
 *     builder has neither problem, and is stronger than `withoutGlobalScopes()` because it also
 *     bypasses the model cache. Raw inserts are followed by `flushCache()` for the same reason in
 *     the other direction.
 *
 * (e) A FIXTURE CAN MANUFACTURE THE REFUSAL. `makeMerchant()` writes `created_by => 1`; with no
 *     operator row the first merchant IS user 1 and becomes their own tenant root by accident.
 *     `setUp()` creates the operator first and asserts they hold id 1, and
 *     `test_premise_*` asserts that the staff principal is really inside the tenant and really
 *     holds nothing -- so a refusal below is attributable to the permission and not to tenancy.
 *
 * (f) SELF-SERVICE DOORS. `order-detail` is declared inside the `//delivery boy` block of
 *     `routes/api.php` and is the DRIVER app's order screen: a `DeliveryBoy` is not a Laratrust
 *     subject, so a merchant permission on that door would refuse every driver on the platform.
 *     The gate carries a driver limb and `test_order_detail_still_answers_the_assigned_driver`
 *     pins it here as well as in `AdminApiPiiAndCatalogueRouteGateTest`.
 */
class AdminApiWithinTenantPermissionTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** Distinctive enough that "it was served" / "it was not served" cannot pass by accident. */
    private const MARK = 'WITHINTENANT-MARK-4242';

    private const MARGIN = '4242.42';

    private const BUYER_EMAIL = 'nadia.buyer-withintenant@example.test';

    private User $operator;

    private User $merchant;

    private Store $store;

    /** A staff member of $store holding nothing at all. */
    private User $staff;

    /**
     * The unlimited plan every principal in this file is on -- see `seedPlan()`. Held on the
     * instance because `staffIn()` mints staff AFTER the one-off `users` update, and the first
     * draft of this file left those later rows with `plan_id` NULL: `AddProduct` then answered
     * `Attempt to read property "max_products" on null` to the PERMITTED caller, which read as a
     * gate refusing everybody. The test caught it; the premise is now carried per principal.
     */
    private int $planId;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * `Utility::upload_file()` writes through the `theme` disk, whose root is `base_path('')`
         * -- the repository itself. Faking it is not tidiness: without it the two multipart doors
         * below write real files into the working tree.
         */
        Storage::fake('theme');
        Storage::fake('local');
        Mail::fake();

        // Trap (e). The operator must be user 1 for `makeMerchant()`'s `created_by => 1` to mean
        // "rooted at the platform" rather than "its own tenant root".
        $this->operator = $this->makePlatformAdmin();
        $this->assertSame(1, (int) User::orderBy('id')->value('id'),
            'premise: the platform operator must hold id 1');

        [$merchant, $store] = $this->makeTenant();
        $this->merchant = $merchant;
        $this->store = $store;

        $this->seedPlatformStorage();
        $this->seedPlan();

        $this->staff = $this->staffIn($store);

        // Several handlers read the platform admin through `Cache::remember('admin_details', ...)`
        // and would otherwise serve the pre-fixture (absent) answer for the rest of the test.
        Cache::forget('admin_details');
    }

    // ═════════════════════════════════════════════════════════════════════════════════ principals

    private function staffIn(Store $store, ?string $permission = null): User
    {
        $staff = $this->makeStaff($this->merchant);
        $staff->current_store = $store->id;
        $staff->plan_id = $this->planId;
        $staff->save();

        if ($permission !== null) {
            $this->grantPermission(User::find($staff->id), $permission);
        }

        return User::find($staff->id);
    }

    /** A colleague of `$this->staff` differing in exactly one thing: the grant. */
    private function staffWith(string $permission): User
    {
        return $this->staffIn($this->store, $permission);
    }

    /**
     * `Utility::upload_file()` reads `Setting::where('created_by', 1)` and answers
     * `flag => 0, 'Please set proper configuration for storage.'` when `storage_setting` is
     * absent -- which would make the multipart doors answer a refusal-shaped envelope for EVERY
     * principal and read as gated. The storage row is what makes those doors measurable.
     */
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

    /**
     * An unlimited plan on every principal. `AddProduct` dereferences
     * `Plan::find($user->plan_id)->max_products` with no null check and `users.plan_id` is
     * nullable, so a staff member with no plan gets an HTTP 500 there -- AFTER the gate, which
     * would turn a measured admission into an inconclusive one for the wrong reason.
     */
    private function seedPlan(): void
    {
        $planId = (int) DB::table('plans')->insertGetId([
            'name' => 'within-tenant-' . $this->uniqueFixtureRef(),
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

        $this->planId = $planId;

        foreach (['operator', 'merchant'] as $property) {
            $this->{$property} = User::find($this->{$property}->id);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════════════ fixtures

    private function categoryId(): int
    {
        $id = (int) DB::table('categories')->insertGetId([
            'name' => 'within-tenant-cat',
            'slug' => 'within-tenant-cat-' . $this->uniqueFixtureRef(),
            'parent_id' => 0,
            'trending' => 0,
            'status' => 1,
            'store_id' => $this->store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new \App\Models\Category)->flushCache();

        return $id;
    }

    private function productId(array $overrides = []): int
    {
        $id = (int) DB::table('products')->insertGetId(array_merge([
            'name' => 'within-tenant-product',
            'slug' => 'within-tenant-product-' . $this->uniqueFixtureRef(),
            'category_id' => $this->categoryId(),
            'description' => 'fixture',
            'track_stock' => 0,
            'price' => 100,
            'sale_price' => 90,
            'cost_price' => self::MARGIN,
            'product_stock' => 50,
            'trending' => 0,
            'variant_product' => 0,
            'status' => 1,
            'store_id' => $this->store->id,
            'created_by' => $this->merchant->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        (new \App\Models\Product)->flushCache();

        return $id;
    }

    private function brandId(): int
    {
        $id = (int) DB::table('product_brands')->insertGetId([
            'name' => 'within-tenant-brand-' . $this->uniqueFixtureRef(),
            'slug' => 'within-tenant-brand-' . $this->uniqueFixtureRef(),
            'logo' => null,
            'status' => 1,
            'is_popular' => 0,
            'store_id' => $this->store->id,
            'created_by' => $this->merchant->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new \App\Models\ProductBrand)->flushCache();

        return $id;
    }

    private function labelId(): int
    {
        $id = (int) DB::table('product_labels')->insertGetId([
            'name' => 'within-tenant-label-' . $this->uniqueFixtureRef(),
            'slug' => 'within-tenant-label-' . $this->uniqueFixtureRef(),
            'status' => 1,
            'store_id' => $this->store->id,
            'created_by' => $this->merchant->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new \App\Models\ProductLabel)->flushCache();

        return $id;
    }

    private function reviewId(array $overrides = []): int
    {
        $id = (int) DB::table('testimonials')->insertGetId(array_merge([
            'title' => self::MARK,
            'description' => 'the original review text',
            // `testimonials.category_id` DEFAULTS TO 0 behind a foreign key to `categories`, so
            // omitting it inserts 0 and the key fails. NULL is the only safe omission.
            'category_id' => null,
            'rating_no' => 5,
            'status' => 1,
            'store_id' => $this->store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        (new \App\Models\Testimonial)->flushCache();

        return $id;
    }

    private function customerId(): int
    {
        $id = (int) DB::table('customers')->insertGetId([
            'first_name' => 'Nadia',
            'last_name' => 'Haddad',
            'email' => 'within-tenant-' . $this->uniqueFixtureRef() . '@example.test',
            'mobile' => '+963911111111',
            'type' => 'customer',
            'status' => 1,
            'store_id' => $this->store->id,
            'created_by' => $this->merchant->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new \App\Models\Customer)->flushCache();

        return $id;
    }

    /** A coupon with one redemption on it, which is what `view-used-coupon` serves. */
    private function redeemedCouponId(): int
    {
        $couponId = (int) DB::table('coupons')->insertGetId([
            'coupon_name' => 'within-tenant-coupon',
            'coupon_code' => 'WT' . strtoupper(substr($this->uniqueFixtureRef(), -6)),
            'coupon_type' => 'flat',
            'discount_amount' => 10,
            'status' => 1,
            'store_id' => $this->store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // `user_coupons.user_id` points at `customers`, not at `users`: the MySQL-only migration
        // 2024_05_29_102438 repointed it and 2026_10_12_000104 reconciles the other drivers. A
        // `users` id here fails the foreign key on SQLite.
        DB::table('user_coupons')->insert([
            'user_id' => $this->customerId(),
            'coupon_id' => $couponId,
            'amount' => 4242,
            'order_id' => 0,
            'date_used' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new \App\Models\Coupon)->flushCache();

        return $couponId;
    }

    /**
     * An order carrying real PII. `Order::order_detail()` reads the buyer's name, e-mail and
     * telephone out of `order_billing_details` for every non-POS order, so without that row the
     * fields it serves are empty strings and a disclosure assertion would be invisible -- the
     * fixture, not the gate, would be "protecting" the data.
     */
    private function orderWithPii(array $attributes = []): \App\Models\Order
    {
        $order = $this->makeOrder($this->store, array_merge([
            'customer_id' => $this->customerId(),
            'is_guest' => 0,
            'final_price' => 1234.56,
            'payment_type' => 'cod',
            'payment_status' => 'Paid',
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

    private function driver(): DeliveryBoy
    {
        return DeliveryBoy::forceCreate([
            'name' => 'Driver ' . $this->uniqueFixtureRef(),
            'email' => 'driver-' . $this->uniqueFixtureRef() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'contact' => '+963922222222',
            // delivery_boys.created_by is NOT nullable.
            'created_by' => $this->merchant->id,
            'store_id' => $this->store->id,
        ]);
    }

    private function setting(string $name, string $value): void
    {
        // Raw, not Setting::create(): the model's `saving` hook runs HostClaims and
        // StoredPathPolicy, neither of which is what this file measures.
        DB::table('settings')->insert([
            'name' => $name,
            'value' => $value,
            'store_id' => $this->store->id,
            'created_by' => $this->merchant->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new \App\Models\Setting)->flushCache();
    }

    private function image(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='
        ));
    }

    // ══════════════════════════════════════════════════════════════════════════════ driving the wire

    /** Trap (c). Put the process back to "nobody is signed in" between legs. */
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

    /** @param  \App\Models\User|\App\Models\DeliveryBoy  $tokenable */
    private function as($tokenable): self
    {
        $this->resetAuth();

        return $this->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $tokenable->createToken('within_tenant')->plainTextToken);
    }

    private function body(TestResponse $response): string
    {
        return substr((string) preg_replace('/\s+/', ' ', (string) $response->getContent()), 0, 400);
    }

    /**
     * Admitted, decided from the ENVELOPE and never from the status code alone -- trap (a). The
     * success flag is spelled three ways across these handlers: `1` (ApiResponser), the string
     * `'success'` (the brand and label toggles, `getStoreInfo`) and `true`.
     */
    private function admitted(TestResponse $response): bool
    {
        if ($response->status() < 200 || $response->status() >= 300) {
            return false;
        }

        $body = $response->json();

        if (! is_array($body)) {
            return false;
        }

        if (array_key_exists('success', $body)) {
            return $body['success'] === true;
        }

        return in_array($body['status'] ?? null, [1, '1', true, 'success'], true);
    }

    /** The shape every gate added here uses: an explicit 403 whose envelope is not a success. */
    private function assertRefusedByThePermissionGate(TestResponse $response, string $door): void
    {
        $this->assertNotSame(401, $response->status(),
            $door . ': refused with 401. The shipped Flutter app force-clears its session on any '
            . '401, so this signs the merchant out of the whole app rather than telling them they '
            . 'lack a right. Body: ' . $this->body($response));

        $this->assertSame(403, $response->status(),
            $door . ': the permission refusal is not an explicit 403. `error()` answers HTTP 200 '
            . 'with {status: 0}, which no client distinguishes from success. Body: '
            . $this->body($response));

        $this->assertFalse($this->admitted($response),
            $door . ': the envelope says success. Body: ' . $this->body($response));
    }

    private function assertAdmitted(TestResponse $response, string $who): void
    {
        $this->assertTrue($this->admitted($response),
            $who . ' was REFUSED, so the refusal leg beside this one has no control and proves '
            . 'nothing: a gate that admits nobody passes it. Body: ' . $this->body($response));
    }

    /** @param  \App\Models\User|\App\Models\DeliveryBoy  $u */
    private function read($u, string $path): TestResponse
    {
        return $this->as($u)->getJson('/api/admin/' . $path);
    }

    private function send(User $u, string $path, array $payload = []): TestResponse
    {
        return $this->as($u)->postJson('/api/admin/' . $path, $payload);
    }

    /** A multipart POST: `postJson` would leave an UploadedFile in the input bag as an object. */
    private function upload(User $u, string $path, array $payload): TestResponse
    {
        return $this->as($u)->post('/api/admin/' . $path, $payload);
    }

    private function value(string $table, int $id, string $column)
    {
        return DB::table($table)->where('id', $id)->value($column);
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  PREMISES. Without these, every refusal below could be tenancy, or a broken fixture.     ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════╝

    public function test_premise_the_staff_principal_is_inside_the_tenant_and_holds_nothing(): void
    {
        $this->assertSame(
            [],
            \App\Services\MerchantPermissions::names($this->staff, $this->store->id),
            'the staff principal holds a permission, so every refusal below could be measuring '
            . 'the wrong thing'
        );

        $this->assertSame((int) $this->merchant->id, (int) $this->staff->creatorId(),
            'the staff principal is not rooted at this merchant, so the tenancy predicate would '
            . 'refuse them and the permission question would never be reached');

        $this->assertSame((int) $this->store->id, (int) $this->staff->current_store,
            'the staff principal is not in this store, so `current_store` handlers would miss for '
            . 'a tenancy reason');

        $this->assertFalse(\App\Policies\UserPolicy::isOperator($this->staff),
            'the staff principal is the platform operator, whom MerchantPermissions wildcards');
    }

    /**
     * AND THE TENANCY PREDICATE REALLY DOES ADMIT THEM, which is the whole reason this axis
     * existed. Driven on a door this change did NOT gate, so it measures the predicate rather than
     * a gate: `productdropdown` carries the same tenancy control and no permission limb, and is
     * reported as needing an owner decision rather than gated here.
     */
    public function test_premise_the_tenancy_predicate_admits_this_staff_member(): void
    {
        $this->categoryId();

        $response = $this->read($this->staff, 'productdropdown?store_id=' . $this->store->id);

        $this->assertTrue($this->admitted($response),
            'the tenancy predicate now refuses an unpermissioned staff member of the owning '
            . 'store. If that is deliberate, the refusals below are no longer attributable to the '
            . 'permission gates. Body: ' . $this->body($response));
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  1-7. PRODUCT BRANDS AND LABELS                                                          ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * `addproductbrand` -> `Create Product Brand`, the name `ProductBrandController::store()`
     * uses. The write also consumes the OWNER's plan storage quota through
     * `Utility::updateStorageLimit($user->creatorId(), ...)`.
     */
    public function test_addproductbrand_is_gated_on_create_product_brand(): void
    {
        $call = fn (User $u, string $name) => $this->upload($u, 'addproductbrand', [
            'store_id' => $this->store->id,
            'name' => $name,
            'status' => 1,
            'is_popular' => 0,
            'logo' => $this->image('logo.png'),
        ]);

        $refused = $call($this->staff, 'STAFF-BRAND');
        $this->assertRefusedByThePermissionGate($refused, 'addproductbrand');
        $this->assertSame(0, (int) DB::table('product_brands')->where('name', 'STAFF-BRAND')->count(),
            'the refused call still created a brand');

        $this->assertAdmitted($call($this->staffWith('Create Product Brand'), 'PERMITTED-BRAND'),
            'staff granted `Create Product Brand`');
        $this->assertSame(1, (int) DB::table('product_brands')->where('name', 'PERMITTED-BRAND')->count(),
            'the permitted caller got a success envelope and no brand landed, so this door cannot '
            . 'create a brand at all and the refusal above measures nothing');

        $this->assertAdmitted($call($this->merchant, 'OWNER-BRAND'), 'the store owner');
        $this->assertSame(1, (int) DB::table('product_brands')->where('name', 'OWNER-BRAND')->count());
    }

    /** `updateproductbrand` -> `Edit Product Brand` (`ProductBrandController::update()`). */
    public function test_updateproductbrand_is_gated_on_edit_product_brand(): void
    {
        $brand = $this->brandId();
        $original = (string) $this->value('product_brands', $brand, 'name');

        $call = fn (User $u, string $name) => $this->send($u, 'updateproductbrand', [
            'store_id' => $this->store->id,
            'productbrand_id' => $brand,
            'name' => $name,
        ]);

        $this->assertRefusedByThePermissionGate($call($this->staff, self::MARK), 'updateproductbrand');
        $this->assertSame($original, (string) $this->value('product_brands', $brand, 'name'),
            'the brand was renamed by a staff member holding nothing');

        $this->assertAdmitted($call($this->staffWith('Edit Product Brand'), 'PERMITTED-RENAME'),
            'staff granted `Edit Product Brand`');
        $this->assertSame('PERMITTED-RENAME', (string) $this->value('product_brands', $brand, 'name'),
            'the permitted caller was admitted and the rename did not land');

        $this->assertAdmitted($call($this->merchant, 'OWNER-RENAME'), 'the store owner');
        $this->assertSame('OWNER-RENAME', (string) $this->value('product_brands', $brand, 'name'));
    }

    /**
     * The two brand toggles -> `Edit Product Brand`, which is what the WEB twin of these exact
     * toggles already requires (`ProductBrandController::resolveOwnedBrand()`, pinned by
     * `BrandLabelStatusAuthorizationTest`). `product_brands.status` is the column the storefront
     * filters on, so flipping it removes a brand from the shop.
     *
     * A FRESH ROW PER LEG rather than a reset between them: `ProductBrand` is `Cachable`, and a
     * raw reset leaves the handler's own Eloquent read serving the previous principal's row, which
     * makes the next write read as "no change".
     */
    #[DataProvider('brandToggles')]
    public function test_the_brand_toggles_are_gated_on_edit_product_brand(
        string $route,
        string $parameter,
        string $column,
        int $from,
        int $to
    ): void {
        $call = function (User $u) use ($route, $parameter, $to) {
            $brand = $this->brandId();

            $response = $this->read($u, $route . '?store_id=' . $this->store->id
                . '&productbrand_id=' . $brand . '&' . $parameter . '=' . $to);

            return [$response, $brand];
        };

        [$refused, $untouched] = $call($this->staff);
        $this->assertRefusedByThePermissionGate($refused, $route);
        $this->assertSame($from, (int) $this->value('product_brands', $untouched, $column),
            $route . ': the column moved for a staff member holding nothing');

        [$permitted, $written] = $call($this->staffWith('Edit Product Brand'));
        $this->assertAdmitted($permitted, 'staff granted `Edit Product Brand`');
        $this->assertSame($to, (int) $this->value('product_brands', $written, $column),
            $route . ': the permitted caller was admitted and the toggle did not land, so the '
            . 'refusal above is not measuring a gate');

        [$owner, $ownerRow] = $call($this->merchant);
        $this->assertAdmitted($owner, 'the store owner');
        $this->assertSame($to, (int) $this->value('product_brands', $ownerRow, $column));
    }

    public static function brandToggles(): array
    {
        return [
            'product-brand-status' => ['product-brand-status', 'status', 'status', 1, 0],
            'product-brand-popular' => ['product-brand-popular', 'is_popular', 'is_popular', 0, 1],
        ];
    }

    /** `addproductlable` -> `Create Product Label` (`ProductLabelController::store()`). */
    public function test_addproductlable_is_gated_on_create_product_label(): void
    {
        $call = fn (User $u, string $name) => $this->send($u, 'addproductlable', [
            'store_id' => $this->store->id,
            'name' => $name,
            'status' => 1,
        ]);

        $this->assertRefusedByThePermissionGate($call($this->staff, 'STAFF-LABEL'), 'addproductlable');
        $this->assertSame(0, (int) DB::table('product_labels')->where('name', 'STAFF-LABEL')->count());

        $this->assertAdmitted($call($this->staffWith('Create Product Label'), 'PERMITTED-LABEL'),
            'staff granted `Create Product Label`');
        $this->assertSame(1, (int) DB::table('product_labels')->where('name', 'PERMITTED-LABEL')->count(),
            'the permitted caller was admitted and no label landed');

        $this->assertAdmitted($call($this->merchant, 'OWNER-LABEL'), 'the store owner');
        $this->assertSame(1, (int) DB::table('product_labels')->where('name', 'OWNER-LABEL')->count());
    }

    /** `updateproductlable` -> `Edit Product Label` (`ProductLabelController::update()`). */
    public function test_updateproductlable_is_gated_on_edit_product_label(): void
    {
        $label = $this->labelId();
        $original = (string) $this->value('product_labels', $label, 'name');

        $call = fn (User $u, string $name) => $this->send($u, 'updateproductlable', [
            'store_id' => $this->store->id,
            'lable_id' => $label,
            'name' => $name,
        ]);

        $this->assertRefusedByThePermissionGate($call($this->staff, self::MARK), 'updateproductlable');
        $this->assertSame($original, (string) $this->value('product_labels', $label, 'name'),
            'the label was renamed by a staff member holding nothing');

        $this->assertAdmitted($call($this->staffWith('Edit Product Label'), 'PERMITTED-LABEL-NAME'),
            'staff granted `Edit Product Label`');
        $this->assertSame('PERMITTED-LABEL-NAME', (string) $this->value('product_labels', $label, 'name'),
            'the permitted caller was admitted and the rename did not land');

        $this->assertAdmitted($call($this->merchant, 'OWNER-LABEL-NAME'), 'the store owner');
        $this->assertSame('OWNER-LABEL-NAME', (string) $this->value('product_labels', $label, 'name'));
    }

    /** `product-label-status` -> `Edit Product Label` (`ProductLabelController::changeStatus()`). */
    public function test_the_label_status_toggle_is_gated_on_edit_product_label(): void
    {
        $call = function (User $u) {
            $label = $this->labelId();

            return [
                $this->read($u, 'product-label-status?store_id=' . $this->store->id
                    . '&lable_id=' . $label . '&status=0'),
                $label,
            ];
        };

        [$refused, $untouched] = $call($this->staff);
        $this->assertRefusedByThePermissionGate($refused, 'product-label-status');
        $this->assertSame(1, (int) $this->value('product_labels', $untouched, 'status'),
            'the label was unpublished by a staff member holding nothing');

        [$permitted, $written] = $call($this->staffWith('Edit Product Label'));
        $this->assertAdmitted($permitted, 'staff granted `Edit Product Label`');
        $this->assertSame(0, (int) $this->value('product_labels', $written, 'status'),
            'the permitted caller was admitted and the toggle did not land');

        [$owner, $ownerRow] = $call($this->merchant);
        $this->assertAdmitted($owner, 'the store owner');
        $this->assertSame(0, (int) $this->value('product_labels', $ownerRow, 'status'));
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  8-9. CATEGORIES                                                                         ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * `addcategory` -> `Create Product Category` (`CategoryController::store()`).
     *
     * THE PERMITTED LEG IS AN HTTP 500 AND THAT IS THE PROOF OF ADMISSION, not a weaker
     * assertion. `categories.slug` is NOT NULL with no default and this handler never sets one, so
     * the INSERT fails for every principal the gate admits -- a pre-existing second finding that
     * this change does not touch. Reaching that failure is positive evidence the caller passed the
     * gate, because every refusal returns before the insert; the message is asserted so that a
     * DIFFERENT 500 cannot stand in for it. Both engines' wordings are accepted: SQLite says
     * `NOT NULL constraint failed: categories.slug`, MySQL in STRICT_TRANS_TABLES says
     * `Field 'slug' doesn't have a default value`, and production is MariaDB.
     */
    public function test_addcategory_is_gated_on_create_product_category(): void
    {
        $call = fn (User $u, string $name) => $this->upload($u, 'addcategory', [
            'store_id' => $this->store->id,
            'name' => $name,
            'trending' => 0,
            'status' => 1,
            'image' => $this->image('cat.png'),
            'icon_image' => $this->image('icon.png'),
        ]);

        $before = (int) DB::table('categories')->count();

        $refused = $call($this->staff, 'STAFF-CATEGORY');
        $this->assertRefusedByThePermissionGate($refused, 'addcategory');
        $this->assertSame($before, (int) DB::table('categories')->count(),
            'a category row landed for a staff member holding nothing');

        foreach ([
            'staff granted `Create Product Category`' => $this->staffWith('Create Product Category'),
            'the store owner' => $this->merchant,
        ] as $who => $principal) {
            $response = $call($principal, 'ADMITTED-CATEGORY');

            $this->assertSame(500, $response->status(),
                $who . ' no longer reaches the INSERT. If this is a 403 the gate now refuses them, '
                . 'which is a lockout; if it is a success the missing slug has been fixed and this '
                . 'leg should assert the row instead. Body: ' . $this->body($response));
            $this->assertMatchesRegularExpression(
                "/categories\.slug|Field 'slug'/",
                (string) $response->getContent(),
                $who . ': the 500 is no longer the missing-slug write failure, so it is no longer '
                . 'evidence that this principal reached the insert. Body: ' . $this->body($response)
            );
        }
    }

    /** `updatecategory` -> `Edit Product Category` (`CategoryController::update()`). */
    public function test_updatecategory_is_gated_on_edit_product_category(): void
    {
        $category = $this->categoryId();
        $original = (string) $this->value('categories', $category, 'name');

        $call = fn (User $u, string $name) => $this->send($u, 'updatecategory', [
            'store_id' => $this->store->id,
            'category_id' => $category,
            'name' => $name,
        ]);

        $this->assertRefusedByThePermissionGate($call($this->staff, self::MARK), 'updatecategory');
        $this->assertSame($original, (string) $this->value('categories', $category, 'name'),
            'the category was renamed by a staff member holding nothing');

        $this->assertAdmitted($call($this->staffWith('Edit Product Category'), 'PERMITTED-CATEGORY'),
            'staff granted `Edit Product Category`');
        $this->assertSame('PERMITTED-CATEGORY', (string) $this->value('categories', $category, 'name'),
            'the permitted caller was admitted and the rename did not land');

        $this->assertAdmitted($call($this->merchant, 'OWNER-CATEGORY'), 'the store owner');
        $this->assertSame('OWNER-CATEGORY', (string) $this->value('categories', $category, 'name'));
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  10-13. REVIEWS                                                                          ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * `addreview` -> `Create Testimonial` (`TestimonialController::store()`, the same
     * `Testimonial` model).
     *
     * What this door does with no gate: publishes a live 5-star review ATTRIBUTED TO A NAMED
     * SHOPPER of that store, because `user_id` comes straight from request input, and
     * `Testimonial::AvregeRating()` folds it into the product's displayed rating.
     */
    public function test_addreview_is_gated_on_create_testimonial(): void
    {
        $customer = $this->customerId();

        $call = fn (User $u, string $title) => $this->send($u, 'addreview', [
            'store_id' => $this->store->id,
            'product_id' => $this->productId(),
            'user_id' => $customer,
            'rating' => 5,
            'title' => $title,
            'description' => 'probe',
            'status' => '1',
        ]);

        $this->assertRefusedByThePermissionGate($call($this->staff, 'STAFF-REVIEW'), 'addreview');
        $this->assertSame(0, (int) DB::table('testimonials')->where('title', 'STAFF-REVIEW')->count(),
            'a review attributed to a named shopper was published by a staff member holding nothing');

        $this->assertAdmitted($call($this->staffWith('Create Testimonial'), 'PERMITTED-REVIEW'),
            'staff granted `Create Testimonial`');
        $this->assertSame(1, (int) DB::table('testimonials')->where('title', 'PERMITTED-REVIEW')->count(),
            'the permitted caller was admitted and no review landed');

        $this->assertAdmitted($call($this->merchant, 'OWNER-REVIEW'), 'the store owner');
        $this->assertSame(1, (int) DB::table('testimonials')->where('title', 'OWNER-REVIEW')->count());
    }

    /** `updatereview` -> `Edit Testimonial` (`TestimonialController::update()`). */
    public function test_updatereview_is_gated_on_edit_testimonial(): void
    {
        $review = $this->reviewId();

        $call = fn (User $u, string $text) => $this->send($u, 'updatereview', [
            'store_id' => $this->store->id,
            'id' => $review,
            'description' => $text,
        ]);

        $this->assertRefusedByThePermissionGate($call($this->staff, self::MARK), 'updatereview');
        $this->assertSame('the original review text', (string) $this->value('testimonials', $review, 'description'),
            'public review text was rewritten by a staff member holding nothing');

        $this->assertAdmitted($call($this->staffWith('Edit Testimonial'), 'PERMITTED-TEXT'),
            'staff granted `Edit Testimonial`');
        $this->assertSame('PERMITTED-TEXT', (string) $this->value('testimonials', $review, 'description'),
            'the permitted caller was admitted and the rewrite did not land');

        $this->assertAdmitted($call($this->merchant, 'OWNER-TEXT'), 'the store owner');
        $this->assertSame('OWNER-TEXT', (string) $this->value('testimonials', $review, 'description'));
    }

    /**
     * `reviewstatus` -> `Edit Testimonial`. There is no separate status screen on the web: the
     * `Testimonial` editor is where `status` is written, so the editor's name is the twin.
     */
    public function test_reviewstatus_is_gated_on_edit_testimonial(): void
    {
        $call = function (User $u) {
            $review = $this->reviewId();

            return [
                $this->send($u, 'reviewstatus', ['store_id' => $this->store->id, 'id' => $review]),
                $review,
            ];
        };

        [$refused, $untouched] = $call($this->staff);
        $this->assertRefusedByThePermissionGate($refused, 'reviewstatus');
        $this->assertSame(1, (int) $this->value('testimonials', $untouched, 'status'),
            'a review was unpublished by a staff member holding nothing');

        [$permitted, $toggled] = $call($this->staffWith('Edit Testimonial'));
        $this->assertAdmitted($permitted, 'staff granted `Edit Testimonial`');
        $this->assertSame(0, (int) $this->value('testimonials', $toggled, 'status'),
            'the permitted caller was admitted and the toggle did not land');

        [$owner, $ownerRow] = $call($this->merchant);
        $this->assertAdmitted($owner, 'the store owner');
        $this->assertSame(0, (int) $this->value('testimonials', $ownerRow, 'status'));
    }

    /** `reviewlist` -> `Manage Testimonial` (`TestimonialController::index()`, the web list). */
    public function test_reviewlist_is_gated_on_manage_testimonial(): void
    {
        $product = $this->productId();
        $this->reviewId(['product_id' => $product]);

        $path = 'reviewlist?store_id=' . $this->store->id . '&product_id=' . $product;

        $refused = $this->read($this->staff, $path);
        $this->assertRefusedByThePermissionGate($refused, 'reviewlist');
        $this->assertStringNotContainsString(self::MARK, (string) $refused->getContent(),
            'the review list reached a staff member holding no testimonial right');

        $permitted = $this->read($this->staffWith('Manage Testimonial'), $path);
        $this->assertAdmitted($permitted, 'staff granted `Manage Testimonial`');
        $this->assertStringContainsString(self::MARK, (string) $permitted->getContent(),
            'the grant admits them but the payload carries no review, so the absence assertion '
            . 'above was not measuring a withheld list at all');

        $this->assertAdmitted($this->read($this->merchant, $path), 'the store owner');
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  14-15. PRODUCTS                                                                         ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * `addproduct` -> `Create Product` (`ProductController::store()`), the singular seeded name
     * the web twin uses; `Edit Products` is the plural one and belongs to the editor, which
     * `UpdateProduct` already carries.
     *
     * BELOW the F206 operator refusal, so the platform operator keeps
     * `OperatorCatalogueWrite`'s own answer on this door -- pinned by `OperatorCatalogueWriteTest`.
     */
    public function test_addproduct_is_gated_on_create_product(): void
    {
        $category = $this->categoryId();

        $call = fn (User $u, string $name, float $price) => $this->upload($u, 'addproduct', [
            'store_id' => $this->store->id,
            'name' => $name,
            'category_id' => $category,
            'description' => 'probe',
            'price' => $price,
            'product_stock' => 7,
            'trending' => 0,
            'variant_product' => 0,
            'status' => 1,
            'cover_image' => $this->image('cover.png'),
            'product_image' => [$this->image('p1.png')],
        ]);

        $this->assertRefusedByThePermissionGate($call($this->staff, 'STAFF-PRODUCT', 1234.5), 'addproduct');
        $this->assertSame(0, (int) DB::table('products')->where('name', 'STAFF-PRODUCT')->count(),
            'a staff member holding nothing published a priced product');

        $this->assertAdmitted($call($this->staffWith('Create Product'), 'PERMITTED-PRODUCT', 11.0),
            'staff granted `Create Product`');
        $row = DB::table('products')->where('name', 'PERMITTED-PRODUCT')->first();
        $this->assertNotNull($row,
            'the permitted caller was admitted and no product landed, so this door cannot publish '
            . 'at all and the refusal above measures nothing');
        $this->assertEqualsWithDelta(11.0, (float) $row->price, 0.01);
        $this->assertSame(7, (int) $row->product_stock);

        $this->assertAdmitted($call($this->merchant, 'OWNER-PRODUCT', 12.0), 'the store owner');
        $this->assertSame(1, (int) DB::table('products')->where('name', 'OWNER-PRODUCT')->count());
    }

    /**
     * `searchproduct` -> `Manage Products`, which is the name its own sibling one route over
     * (`searchProductsByName`) already carries PRECISELY because it serves `cost_price`, and the
     * name the web catalogue screen (`ProductController::index()`) uses. This door answers the
     * model's own array and `App\Models\Product` declares no hidden attributes, so the margin is
     * in the body.
     */
    public function test_searchproduct_is_gated_on_manage_products(): void
    {
        $category = $this->categoryId();
        $this->productId(['category_id' => $category]);

        $payload = ['store_id' => $this->store->id, 'category_id' => $category];

        $refused = $this->send($this->staff, 'searchproduct', $payload);
        $this->assertRefusedByThePermissionGate($refused, 'searchproduct');
        $this->assertStringNotContainsString(self::MARGIN, (string) $refused->getContent(),
            'MARGIN LEAK: cost_price reached a staff member holding no product right');

        $permitted = $this->send($this->staffWith('Manage Products'), 'searchproduct', $payload);
        $this->assertAdmitted($permitted, 'staff granted `Manage Products`');
        $this->assertStringContainsString(self::MARGIN, (string) $permitted->getContent(),
            'the grant admits them but cost_price is absent even then, so the margin assertion '
            . 'above was not measuring a withheld field at all');

        $this->assertAdmitted($this->send($this->merchant, 'searchproduct', $payload), 'the store owner');
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  16-18. THE TWO SIBLING LISTS AND THE COUPON REDEMPTION LEDGER                           ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════╝

    /** `shippinglist` -> `Manage Shipping Class` (`ShippingController::index()`). */
    public function test_shippinglist_is_gated_on_manage_shipping_class(): void
    {
        DB::table('shippings')->insert([
            'name' => self::MARK,
            'description' => 'probe',
            'slug' => 'within-tenant-shipping-' . $this->uniqueFixtureRef(),
            'store_id' => $this->store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        (new \App\Models\Shipping)->flushCache();

        $path = 'shippinglist?store_id=' . $this->store->id;

        $refused = $this->read($this->staff, $path);
        $this->assertRefusedByThePermissionGate($refused, 'shippinglist');
        $this->assertStringNotContainsString(self::MARK, (string) $refused->getContent());

        $permitted = $this->read($this->staffWith('Manage Shipping Class'), $path);
        $this->assertAdmitted($permitted, 'staff granted `Manage Shipping Class`');
        $this->assertStringContainsString(self::MARK, (string) $permitted->getContent(),
            'the grant admits them but the payload carries no shipping class, so the absence '
            . 'assertion above was not measuring a withheld list');

        $this->assertAdmitted($this->read($this->merchant, $path), 'the store owner');
    }

    /** `taxlist` -> `Manage Tax` (`TaxController::index()`). */
    public function test_taxlist_is_gated_on_manage_tax(): void
    {
        DB::table('taxes')->insert([
            'name' => self::MARK,
            'store_id' => $this->store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        (new \App\Models\Tax)->flushCache();

        $path = 'taxlist?store_id=' . $this->store->id;

        $refused = $this->read($this->staff, $path);
        $this->assertRefusedByThePermissionGate($refused, 'taxlist');
        $this->assertStringNotContainsString(self::MARK, (string) $refused->getContent());

        $permitted = $this->read($this->staffWith('Manage Tax'), $path);
        $this->assertAdmitted($permitted, 'staff granted `Manage Tax`');
        $this->assertStringContainsString(self::MARK, (string) $permitted->getContent(),
            'the grant admits them but the payload carries no tax class');

        $this->assertAdmitted($this->read($this->merchant, $path), 'the store owner');
    }

    /**
     * `view-used-coupon` -> `Manage Coupon` OR `Create Coupon`, and BOTH are measured below.
     *
     * The web spells this one screen with two names and a gate must not be narrower than either:
     * `CouponController::show()` -- the screen that renders exactly this redemption ledger --
     * asks for `Create Coupon`, while the coupon list it is reached from asks for `Manage Coupon`,
     * which is also what the API sibling `couponlist` carries. Naming both refuses nobody the web
     * admits; picking one would have invented a narrowing.
     */
    public function test_view_used_coupon_is_gated_on_either_coupon_name(): void
    {
        $coupon = $this->redeemedCouponId();
        $path = 'view-used-coupon?store_id=' . $this->store->id . '&coupon_id=' . $coupon;

        $refused = $this->read($this->staff, $path);
        $this->assertRefusedByThePermissionGate($refused, 'view-used-coupon');
        $this->assertStringNotContainsString('4242', (string) $refused->getContent(),
            'the redemption ledger reached a staff member holding no coupon right');

        foreach (['Manage Coupon', 'Create Coupon'] as $name) {
            $permitted = $this->read($this->staffWith($name), $path);

            $this->assertAdmitted($permitted, 'staff granted `' . $name . '`');
            $this->assertStringContainsString('4242', (string) $permitted->getContent(),
                'the holder of `' . $name . '` was admitted but the payload carries no redemption, '
                . 'so the absence assertion above was not measuring a withheld ledger');
        }

        $this->assertAdmitted($this->read($this->merchant, $path), 'the store owner');
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  19-21. THE THREE SETTINGS DOORS                                                         ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * `getloyalitysetting` -> `StoreSettingsAccess::permits()`, i.e. the operator, the tenant
     * owner, or `Manage Setting`. That is the predicate its own WRITER (`LoyalitySetting`), the
     * writer's web twin (`SettingController::LoyalityProgramSettings()`) and the settings screen
     * that reads these names (`SettingController::settingForm()`, the `loyality_pro_setting` tab)
     * all already use, and both names are in that class's own restricted list.
     */
    public function test_getloyalitysetting_is_gated_on_the_settings_predicate(): void
    {
        $this->setting('loyality_program_enabled', 'on');
        $this->setting('reward_point', self::MARK);

        $path = 'getloyalitysetting?store_id=' . $this->store->id;

        $refused = $this->read($this->staff, $path);
        $this->assertRefusedByThePermissionGate($refused, 'getloyalitysetting');
        $this->assertStringNotContainsString(self::MARK, (string) $refused->getContent(),
            'the reward point reached a staff member holding no settings right');

        $permitted = $this->read($this->staffWith('Manage Setting'), $path);
        $this->assertAdmitted($permitted, 'staff granted `Manage Setting`');
        $this->assertStringContainsString(self::MARK, (string) $permitted->getContent(),
            'the grant admits them but the reward point is absent even then');

        $this->assertAdmitted($this->read($this->merchant, $path), 'the store owner');

        /* AND THE SIBLING READERS STILL AGREE. Without this the gate could have been added to the
           loyalty reader while the two doors it was aligned with drifted the other way. */
        foreach (['getemailsetting', 'get-stripe-payment'] as $sibling) {
            $this->assertSame(403,
                $this->read($this->staff, $sibling . '?store_id=' . $this->store->id)->status(),
                $sibling . ' no longer refuses this principal, so the alignment this gate was '
                . 'derived from no longer holds');
        }
    }

    /**
     * `getStoreInfo` -> the same settings predicate, because the web reads these same fields on
     * the Settings screen: `SettingController::settingForm()` renders every tab (`brand_setting`
     * IS the store name, e-mail and address block) behind `StoreSettingsAccess::permits()`, and
     * `BusinessSettings()`, which writes them, is behind the same predicate.
     *
     * The field that matters is `users.email` of the OWNER: that is where a password reset goes,
     * so this was the first half of an account-takeover chain rather than a directory listing.
     */
    public function test_get_store_info_is_gated_on_the_settings_predicate(): void
    {
        $path = 'getStoreInfo?store_id=' . $this->store->id;
        $ownerEmail = (string) DB::table('users')->where('id', $this->merchant->id)->value('email');
        $this->assertNotSame('', $ownerEmail, 'premise: the store owner has no e-mail to leak');

        $refused = $this->read($this->staff, $path);
        $this->assertRefusedByThePermissionGate($refused, 'getStoreInfo');
        $this->assertStringNotContainsString($ownerEmail, (string) $refused->getContent(),
            'PII LEAK: the store owner\'s login e-mail reached a staff member holding no settings right');

        $permitted = $this->read($this->staffWith('Manage Setting'), $path);
        $this->assertAdmitted($permitted, 'staff granted `Manage Setting`');
        $this->assertSame($ownerEmail, $permitted->json('data.owner.email'),
            'the grant admits them but the owner block is absent even then, so the leak assertion '
            . 'above was not measuring a withheld field');

        $owner = $this->read($this->merchant, $path);
        $this->assertAdmitted($owner, 'the store owner');
        $this->assertSame($ownerEmail, $owner->json('data.owner.email'));
    }

    /**
     * `updateStoreLanguage` -> `StoreSettingsAccess::permitsStoreScreen()`, which is the settings
     * predicate OR `Manage Store Setting`.
     *
     * THE WIDER OF THE TWO WEB TWINS, DELIBERATELY. Two web handlers write this same column:
     * `SettingController::SystemSettings()` / `BusinessSettings()` behind `permits()`, and
     * `AppSettingController::ThemeSettings()` -- the Store Setting screen -- behind
     * `permitsStoreScreen()`. A staff member holding only `Manage Store Setting` may change the
     * storefront language on the web, so a gate that refused them here would be narrower than the
     * product. BOTH names are driven below for that reason.
     */
    public function test_update_store_language_is_gated_on_either_settings_screen(): void
    {
        $original = (string) $this->value('stores', (int) $this->store->id, 'default_language');
        $this->assertNotSame('ar', $original, 'premise: the store does not already read Arabic');

        $call = function (User $u, string $language) {
            DB::table('stores')->where('id', $this->store->id)
                ->update(['default_language' => 'en']);
            (new Store)->flushCache();

            return $this->send($u, 'updateStoreLanguage?store_id=' . $this->store->id,
                ['default_language' => $language]);
        };

        $refused = $call($this->staff, 'ar');
        $this->assertRefusedByThePermissionGate($refused, 'updateStoreLanguage');
        $this->assertSame('en', (string) $this->value('stores', (int) $this->store->id, 'default_language'),
            'the storefront language every shopper reads was switched by a staff member holding '
            . 'nothing');

        foreach (['Manage Setting', 'Manage Store Setting'] as $name) {
            $permitted = $call($this->staffWith($name), 'ar');

            $this->assertAdmitted($permitted, 'staff granted `' . $name . '`');
            $this->assertSame('ar', (string) $this->value('stores', (int) $this->store->id, 'default_language'),
                'the holder of `' . $name . '` was admitted and the write did not land');
        }

        $owner = $call($this->merchant, 'ar');
        $this->assertAdmitted($owner, 'the store owner');
        $this->assertSame('ar', (string) $this->value('stores', (int) $this->store->id, 'default_language'));
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  22. THE ORDER DOCUMENT, AND THE SELF-SERVICE LEG THAT MUST SURVIVE IT                   ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * `order-detail` -> `Show Order` / `Manage Order`, which are `vieworder`'s own names for the
     * same document. The asymmetry is the finding: `vieworder` refused this principal 403 while
     * this door served them the customer's name, e-mail and telephone, the billing and delivery
     * address, `payment_status` and `final_price`.
     */
    public function test_order_detail_is_gated_on_the_order_read_names(): void
    {
        $order = $this->orderWithPii();
        $path = 'order-detail?order_id=' . $order->id;

        $refused = $this->read($this->staff, $path);
        $this->assertRefusedByThePermissionGate($refused, 'order-detail');

        foreach ([
            'the buyer\'s e-mail' => self::BUYER_EMAIL,
            'the order\'s final price' => '1234.56',
            'the order reference' => $order->product_order_id,
        ] as $what => $needle) {
            $this->assertStringNotContainsString($needle, (string) $refused->getContent(),
                'LEAK: ' . $what . ' reached a staff member holding no order right');
        }

        foreach (['Show Order', 'Manage Order'] as $name) {
            $permitted = $this->read($this->staffWith($name), $path);

            $this->assertAdmitted($permitted, 'staff granted `' . $name . '`');
            $this->assertStringContainsString(self::BUYER_EMAIL, (string) $permitted->getContent(),
                'the holder of `' . $name . '` was admitted but the document carries no billing '
                . 'e-mail, so the leak assertions above were measuring an empty fixture');
        }

        $owner = $this->read($this->merchant, $path);
        $this->assertAdmitted($owner, 'the store owner');
        $this->assertStringContainsString($order->product_order_id, (string) $owner->getContent());
    }

    /**
     * THE SELF-SERVICE LEG, PINNED HERE AS WELL AS IN `AdminApiPiiAndCatalogueRouteGateTest`.
     *
     * This route is the DRIVER app's order screen and is declared inside the `//delivery boy`
     * block of `routes/api.php`. A `DeliveryBoy` is not a Laratrust subject --
     * `MerchantPermissions::names()` answers `[]` for anything that is not a `User` and
     * `isAbleTo()` on one is fatal -- and carrying the parcel IS the authorization, recorded in
     * `orders.deliveryboy_id`. A gate that asked every caller for a merchant permission would
     * refuse EVERY DRIVER ON THE PLATFORM, which this project has shipped twice; the driver limb
     * is the same test the resolver above the gate already makes.
     */
    public function test_order_detail_still_answers_the_assigned_driver(): void
    {
        $driver = $this->driver();
        $order = $this->orderWithPii(['deliveryboy_id' => $driver->id]);

        $this->assertSame([], \App\Services\MerchantPermissions::names($driver, $this->store->id),
            'premise: a DeliveryBoy holds no merchant permission and cannot be granted one, which '
            . 'is why this door needs a limb of its own');

        $response = $this->read($driver, 'order-detail?order_id=' . $order->id);

        $this->assertAdmitted($response, 'the assigned delivery driver');
        $this->assertStringContainsString($order->product_order_id, (string) $response->getContent(),
            'the assigned driver no longer receives their own parcel\'s detail, which is the '
            . 'driver app\'s order screen');
    }

    /**
     * AND THE DRIVER LIMB IS NAMED, NOT UNIVERSAL: a driver of the same store who is NOT on this
     * order is refused. Without this leg "the driver is admitted" could be "every driver is
     * admitted", which would be a wider door than the one that was closed.
     */
    public function test_order_detail_refuses_a_driver_the_order_is_not_assigned_to(): void
    {
        $assigned = $this->driver();
        $bystander = $this->driver();
        $order = $this->orderWithPii(['deliveryboy_id' => $assigned->id]);

        $response = $this->read($bystander, 'order-detail?order_id=' . $order->id);

        $this->assertNotSame(401, $response->status(),
            'a 401 here force-logs-out the driver app. Body: ' . $this->body($response));
        $this->assertFalse($this->admitted($response),
            'a driver of the same store who is not on this order was served its PII. Body: '
            . $this->body($response));
        $this->assertStringNotContainsString(self::BUYER_EMAIL, (string) $response->getContent(),
            'LEAK: the buyer\'s e-mail reached a driver this order is not assigned to');
    }

    /**
     * AND THE LIMB IS A TEST OF THE *ROW*, NOT OF "IS A DRIVER SIGNED IN SOMEWHERE" — the one case
     * that separates those two readings, written because a mutation proved nothing else could.
     *
     * HOW THIS CASE CAME TO EXIST. Widening the limb from
     * `$driver && $order->deliveryboy_id === $driver->id` to a bare `$driver` SURVIVED the whole
     * suite, including `test_order_detail_refuses_a_driver_the_order_is_not_assigned_to` above.
     * The reason is not that the wider form is safe: it is that for a lone driver token the
     * resolver one line higher has already refused a bystander driver (`\Auth::user()` is null in
     * the `deliveryboy` guard, so its tenant limb cannot match), so BOTH readings answer the same
     * thing and the test cannot tell them apart. That is the "ask what input would make the two
     * behaviours disagree" rule, and this is that input.
     *
     * THE PRINCIPAL THAT DISAGREES: an unpermissioned staff member of the owning store whose
     * session ALSO holds a `deliveryboy` login — the two are separate session guards and nothing
     * stops one browser holding both. The resolver then admits via its TENANT limb (`Auth::user()`
     * is the staff member), and a bare `$driver` limb would wave the request through on the
     * strength of a driver session that has nothing to do with this parcel. The row test refuses
     * it, which is what this case pins.
     */
    public function test_order_detail_does_not_admit_a_staff_member_because_a_driver_session_exists(): void
    {
        $bystander = $this->driver();
        $assigned = $this->driver();
        $order = $this->orderWithPii(['deliveryboy_id' => $assigned->id]);

        $response = $this->as($bystander)
            ->actingAs($this->staff, 'web')
            ->getJson('/api/admin/order-detail?order_id=' . $order->id);

        /* THE PREMISE, AND IT IS THE WHOLE CASE: both guards really are populated, so the request
           is the combination this test is about rather than an ordinary driver request. */
        $this->assertTrue($this->app['auth']->guard('deliveryboy')->check(),
            'premise: the deliveryboy guard is not populated, so this request is not the '
            . 'combination this case exists for');
        $this->assertSame((int) $this->staff->id, (int) $this->app['auth']->guard('web')->id(),
            'premise: the web guard does not hold the unpermissioned staff member');

        $this->assertNotSame(401, $response->status(),
            'a 401 here force-logs-out the shipped app. Body: ' . $this->body($response));
        $this->assertFalse($this->admitted($response),
            'the order document was served to an unpermissioned staff member because a driver '
            . 'session existed. The driver limb must test the ORDER ROW, not the presence of a '
            . 'driver. Body: ' . $this->body($response));
        $this->assertStringNotContainsString(self::BUYER_EMAIL, (string) $response->getContent(),
            "LEAK: the buyer's e-mail reached that principal");
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  THE PLATFORM OPERATOR IS NOT LOCKED OUT BY ANY OF THE TWENTY-TWO GATES                  ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * THE ORDERING RULE, MEASURED RATHER THAN DESCRIBED. There is no super-admin bypass in this
     * codebase: operator status is a string comparison on `users.type` and `isAbleTo()` alone
     * REFUSES the operator, so a gate built on it is a lockout and not a tightening -- this
     * project has done exactly that once, to six finance names. Every gate added here goes through
     * `MerchantPermissions` (which answers the operator the wildcard) or
     * `StoreSettingsAccess::permits()` (whose first limb is the operator), and these three doors
     * are the three shapes.
     *
     * `addproduct` is deliberately NOT in this list: the platform operator does not write a
     * merchant's catalogue (F206, an owner decision), so that door refuses them ABOVE this gate
     * and `OperatorCatalogueWriteTest` pins it.
     */
    public function test_the_platform_operator_is_still_admitted_by_every_gate_shape(): void
    {
        $operator = User::find($this->operator->id);
        $operator->current_store = $this->store->id;
        $operator->save();
        $operator = User::find($operator->id);

        $this->setting('reward_point', self::MARK);
        $order = $this->orderWithPii();
        $brand = $this->brandId();

        // (i) callerMayActInStore -> MerchantPermissions wildcard, on a read and on a write.
        $this->assertAdmitted(
            $this->read($operator, 'order-detail?order_id=' . $order->id),
            'the platform operator at `order-detail`'
        );
        $this->assertAdmitted(
            $this->read($operator, 'product-brand-status?store_id=' . $this->store->id
                . '&productbrand_id=' . $brand . '&status=0'),
            'the platform operator at `product-brand-status`'
        );
        $this->assertSame(0, (int) $this->value('product_brands', $brand, 'status'),
            'the operator was admitted and the toggle did not land');

        // (ii) StoreSettingsAccess::permits -> the operator limb is first.
        $this->assertAdmitted(
            $this->read($operator, 'getloyalitysetting?store_id=' . $this->store->id),
            'the platform operator at `getloyalitysetting`'
        );
    }
}
