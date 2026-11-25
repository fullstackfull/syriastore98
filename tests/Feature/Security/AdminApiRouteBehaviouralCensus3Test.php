<?php

namespace Tests\Feature\Security;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A BEHAVIOURAL PERMISSION CENSUS OF 31 `api/admin/*` ROUTES (GROUP 3) — EACH ONE DRIVEN, NOT GREPPED.
 * =====================================================================================================
 *
 * WHY THIS IS A TEST AND NOT A DOCUMENT. A token census of the same 31 routes was tried first and
 * failed: 3 of 3 spot-checks were false positives, and the figure moved when the token list widened,
 * because these controllers spell "refuse" in at least 22 different helper names
 * (`callerMayActInStore`, `refuseUnlessMayAdministerStoreSettings`, `callerMayChangeOrderState`,
 * `refuseWithoutDeliveryPermission`, `refuseUnlessOperatorOwns`, `resolveManageableDeliveryBoy`,
 * `resolveManageableUser`, `storePermissionDenied`, `orderPermissionDenied`, …). Grepping for a name
 * cannot tell a gate from a comment about a gate, and cannot tell a gate that fires from one that is
 * dead code behind an earlier `return`. So every row below is a real HTTP request with a real Sanctum
 * bearer token, and every "a write landed" / "no write landed" claim is a row read.
 *
 * -----------------------------------------------------------------------------------------------------
 * THE THREE PRINCIPALS, AND WHY THE FIRST IS THE ONLY ONE THAT ANSWERS THE QUESTION.
 *
 *   1. AN UNPERMISSIONED STAFF MEMBER OF THE OWNING STORE — `makeStaff($merchant)` with
 *      `current_store` set and NO `grantPermission()` call at all. This is the principal a merchant
 *      creates for a shop assistant. If a route answers it with success, that route is ungated IN
 *      FACT, whatever its source reads like.
 *   2. THE STORE'S OWN OWNER — a positive control. A refusal here means the probe is wrong (or, as
 *      `createOrderViaApi` turns out to be, that the gate itself refuses the owner).
 *   3. A MERCHANT OF A DIFFERENT TENANT, pointed at the victim store's id — the tenancy axis.
 *
 * -----------------------------------------------------------------------------------------------------
 * FIVE TRAPS, ALL OF WHICH BIT THIS FILE WHILE IT WAS BEING WRITTEN, AND TWO OF WHICH PRODUCED A
 * FALSE READING BEFORE THEY WERE FOUND.
 *
 * (A) `ApiResponser::error()` ANSWERS HTTP **200** WITH `{status: 0}` (`app/Traits/ApiResponser.php`),
 *     and its third argument is the *status code* while its second is the *message* — so
 *     `error([...], 403)` puts the integer `403` in `message` and still sends HTTP 200. `createStoreApi`
 *     does exactly that (measured: `{"status":0,"message":403}` at HTTP 200). **A 200 is therefore not
 *     evidence of admission.** Every verdict below is decided on the envelope plus a row read.
 *
 * (B) THE MEMOISED GUARD POLLUTES THE NEXT REQUEST IN THE SAME TEST. `CustomAuth` ends with
 *     `auth()->login($tokenable)`, and the `AuthManager` and its guards live for the whole test
 *     method — so a later request to a route that carries NO `custom.auth` (`adminlogin`,
 *     `delivery-login`) still sees the previous request's principal. That resolves a tenant, which
 *     switches `StoreScope` from "no predicate" (`null_tenant => 'open'`) to
 *     `store_id = <the stale store>`, and `delivery_boys` IS a scoped model — so
 *     `EloquentUserProvider::retrieveByCredentials()` (which uses `newQuery()`, i.e. WITH global
 *     scopes) found nothing and a VALID driver login answered "Invalid login details". A first draft
 *     of this census drove all 31 routes in one method and recorded `delivery-login` as broken for
 *     every principal on exactly that artefact.
 *
 *     AND FORGETTING THE GUARDS IS NOT ENOUGH, which cost a second false reading: `login()` writes the
 *     principal into the SESSION, and the session store outlives a forgotten guard, so the next `web`
 *     guard reads the stale user straight back out. At the driver leg of `markAsRead`,
 *     `StoreScope::resolveTenant()` still answered `reason="resolved from the web guard"` for the
 *     OUTSIDER's store three requests later — which filtered `delivery_boys` out from under the
 *     morphTo behind `$accessToken->tokenable`, so `CustomAuth` answered "Unauthenticated." to a
 *     DRIVER holding a VALID token. One route per method, and `resetAuth()` (logout + session flush +
 *     forgetGuards + flushHeaders) between legs.
 *
 * (C) FOUR OF THESE HANDLERS CANNOT COMPLETE ON SQLITE AT ALL. `EmailSetting`, `LoyalitySetting`,
 *     `CodPayment` and `BankPayment` persist through a raw
 *     `insert into settings ... ON DUPLICATE KEY UPDATE`, which is MySQL-only, so the *permitted*
 *     caller gets HTTP 500 (`near "DUPLICATE": syntax error`) here while production succeeds. That is
 *     **not** a reason to call the route inconclusive: the refusal is an explicit 403 that happens
 *     BEFORE the statement, so the unpermissioned-staff verdict is fully established, and the 500
 *     itself is positive proof that the permitted caller was admitted past the gate. Each such case
 *     says so in its own docblock rather than hiding behind a bare `assertSame(500, …)`.
 *
 * (D) SCOPED AND CACHED MODELS MAKE "IT IS GONE" PASS EITHER WAY. `DeliveryBoy` and `Customer` are in
 *     `config/tenancy.php`'s `scoped_models`, and 79 of 95 models are `Cachable`. Every row read here
 *     goes through `DB::table()`, never `Model::find()`.
 *
 * (E) THE FIXTURE CAN MANUFACTURE THE FINDING. `makeMerchant()` sets `created_by => 1`, so with no
 *     platform operator in the database the MERCHANT is user 1 and `created_by === id` — which makes
 *     `resolveManageableUser()` treat the owner as a member of their own staff's tenant. An earlier
 *     draft of this census therefore reported that unpermissioned staff can rewrite the OWNER's email
 *     through `editprofile`. They cannot; production roots a merchant at the operator. `setUp()`
 *     creates the operator first and asserts they are user 1, and the `editprofile` case now asserts
 *     the owner is OUT of reach as well as that colleagues are IN it — so the finding is bounded by a
 *     measurement in both directions rather than by one lucky id.
 *
 * -----------------------------------------------------------------------------------------------------
 * WHAT THE GREEN OF THIS FILE MEANS.
 *
 * Some of these assertions PIN A DEFECT rather than a control — they record that an unpermissioned
 * staff member succeeded, because that is what was measured today and a census that fails is a census
 * that gets deleted (and would break `.github/known-test-outcomes.json`'s declared outcome set). Every
 * such case is named `test_UNGATED_…` and its docblock says what should happen instead. When a gate is
 * added, that test fails loudly and is the place to record the fix. The naming is the point: nothing
 * in this file should be read as approval of the behaviour it pins.
 */
class AdminApiRouteBehaviouralCensus3Test extends SecurityTestCase
{
    use RefreshDatabase;

    /** The platform operator, created first so that the merchant's `created_by` is the OPERATOR's id. */
    private User $operator;

    private User $merchant;

    private Store $store;

    /** A staff member of $store holding no permission at all. */
    private User $staff;

    /** A second staff member of the same tenant — the target of the identity-write probes. */
    private User $colleague;

    private User $outsider;

    private Store $outsiderStore;

    /**
     * The unlimited plan `seedPlan()` puts every principal on.
     *
     * HELD ON THE INSTANCE BECAUSE `staffIn()` IS ALSO CALLED FROM TESTS, after that one-off
     * `users` update, and `AddProduct` dereferences `Plan::find($user->plan_id)->max_products`
     * with no null check -- so a staff member minted inside a test answered
     * `Attempt to read property "max_products" on null` AFTER passing the gate, which reads as a
     * gate refusing everybody. Measured while inverting the `addproduct` pin on 2026-11-25.
     */
    private ?int $planId = null;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * The `theme` disk's root is `base_path('')` — the repository itself (config/filesystems.php:49).
         * Faking it is not tidiness: without it, the three upload routes below write real files into
         * the working tree.
         */
        Storage::fake('theme');
        Storage::fake('local');

        /*
         * THE OPERATOR IS CREATED FIRST, AND THAT IS LOAD-BEARING. `makeMerchant()` sets
         * `created_by => 1`, which is production's shape only when user 1 is the platform operator.
         * Without this line the merchant IS user 1, so `created_by === id`, and
         * `resolveManageableUser()` then treats the OWNER as a member of their own staff's tenant —
         * which made an earlier draft of this census report that unpermissioned staff can rewrite the
         * owner's email. That was a fixture artefact. With the operator at id 1 the owner's
         * `created_by` is 1 and the staff member's tenant root is the merchant, so the two no longer
         * collide, and the measurement below is the one production would make.
         */
        $this->operator = $this->makePlatformAdmin();
        $this->assertSame(1, (int) User::orderBy('id')->value('id'),
            'premise: the platform operator must be user 1 for makeMerchant()\'s created_by => 1 to mean "rooted at the platform"');

        [$merchant, $store] = $this->makeTenant();
        $this->merchant = $merchant;
        $this->store = $store;

        $this->staff = $this->staffIn($merchant, $store);
        $this->colleague = $this->staffIn($merchant, $store);

        [$outsider, $outsiderStore] = $this->makeTenant();
        $this->outsider = $outsider;
        $this->outsiderStore = $outsiderStore;

        $this->seedPlatformStorage();
        $this->seedPlan();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════ fixtures

    private function staffIn(User $owner, Store $store, ?string $permission = null): User
    {
        $staff = $this->makeStaff($owner);
        $staff->current_store = $store->id;

        if ($this->planId !== null) {
            $staff->plan_id = $this->planId;
        }

        $staff->save();

        if ($permission !== null) {
            $this->grantPermission(User::find($staff->id), $permission);
        }

        return User::find($staff->id);
    }

    /**
     * `Utility::upload_file()` reads `Setting::where('created_by', 1)` and returns
     * `flag => 0, msg => 'Please set proper configuration for storage.'` when `storage_setting` is
     * absent — which would make the three upload routes answer a refusal-shaped envelope for EVERY
     * principal and read as "gated". The storage row is what makes those three measurable.
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
     * An unlimited plan on every principal.
     *
     * `AddProduct` dereferences `Plan::find($user->plan_id)->max_products` with no null check, and
     * `users.plan_id` is nullable — so a staff member with no plan gets HTTP 500 there. That 500 is a
     * real defect, but it lands AFTER every gate, so leaving it in place would have turned an UNGATED
     * route into an INCONCLUSIVE one for the wrong reason.
     */
    private function seedPlan(): void
    {
        $planId = (int) DB::table('plans')->insertGetId([
            'name' => 'census-unlimited-' . $this->uniqueFixtureRef(),
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

        foreach (['operator', 'merchant', 'staff', 'colleague', 'outsider'] as $property) {
            $this->{$property} = User::find($this->{$property}->id);
        }
    }

    private function seedCategory(Store $store): int
    {
        return (int) DB::table('categories')->insertGetId([
            'name' => 'census-cat',
            'slug' => 'census-cat-' . $this->uniqueFixtureRef(),
            'parent_id' => 0,
            'trending' => 0,
            'status' => 1,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedProduct(Store $store): int
    {
        $id = (int) DB::table('products')->insertGetId([
            'name' => 'census-product',
            'slug' => 'census-product-' . $this->uniqueFixtureRef(),
            'category_id' => $this->seedCategory($store),
            'track_stock' => 0,
            'price' => 100,
            'sale_price' => 100,
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

    private function seedCustomer(Store $store): int
    {
        $id = (int) DB::table('customers')->insertGetId([
            'first_name' => 'Census',
            'last_name' => 'Shopper',
            'email' => 'census' . $this->uniqueFixtureRef() . '@example.test',
            'status' => 1,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new \App\Models\Customer)->flushCache();

        return $id;
    }

    private const DRIVER_PASSWORD = 'census-driver-pass';

    private function seedDeliveryBoy(Store $store): int
    {
        $id = (int) DB::table('delivery_boys')->insertGetId([
            'name' => 'CENSUS-DRIVER-ORIGINAL',
            'email' => 'driver' . $this->uniqueFixtureRef() . '@example.test',
            'password' => bcrypt(self::DRIVER_PASSWORD),
            'contact' => '0100000000',
            'store_id' => $store->id,
            'created_by' => $store->created_by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new \App\Models\DeliveryBoy)->flushCache();

        return $id;
    }

    /** A driver notification. `order_id` and `type`/`title`/`message` are NOT NULL in the GATE F migration. */
    private function seedDriverNotification(int $deliveryBoyId, int $orderId): int
    {
        return (int) DB::table('delivery_boy_notifications')->insertGetId([
            'delivery_boy_id' => $deliveryBoyId,
            'order_id' => $orderId,
            'type' => 'census',
            'title' => 'census',
            'message' => 'census',
            'is_read' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function png(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='
        );
    }

    private function image(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->png());
    }

    // ═════════════════════════════════════════════════════════════════════════════ driving the wire

    /**
     * Put the process back to "nobody is signed in" between legs — see trap (B) in the class docblock.
     *
     * ALL FOUR LINES ARE LOAD-BEARING, AND THE FIRST TWO WERE ADDED AFTER A MEASURED FAILURE.
     * `forgetGuards()` alone is NOT enough: `CustomAuth` ends with `auth()->login($tokenable)`, which
     * writes the principal's id into the SESSION, and the session store outlives a forgotten guard —
     * so the next `web` guard built in the same test simply read the previous leg's user back out of
     * it. Measured: at the driver leg of `markAsRead`, `StoreScope::resolveTenant()` still answered
     * `mode=tenant, store_id=2, reason="resolved from the web guard"` — the OUTSIDER's store from
     * three requests earlier — which filtered `delivery_boys` out from under the morphTo behind
     * `$accessToken->tokenable`, so `CustomAuth` answered "Unauthenticated." to a DRIVER holding a
     * VALID driver token. A census that accepted that would have recorded a correct route as broken.
     *
     * `flushHeaders()` is the same class of problem one layer up: `withHeader()` persists on the test
     * instance, so without it a request to a route that takes no token still carries the previous
     * leg's bearer.
     */
    private function resetAuth(): void
    {
        foreach (array_keys(config('auth.guards')) as $guard) {
            if (config('auth.guards.' . $guard . '.driver') === 'session' && $this->app['auth']->guard($guard)->check()) {
                $this->app['auth']->guard($guard)->logout();
            }
        }

        $this->app['session']->flush();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
    }

    private function as(User $user): self
    {
        $this->resetAuth();

        return $this->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $user->createToken('auth_token')->plainTextToken);
    }

    private function anonymously(): self
    {
        $this->resetAuth();

        return $this->withHeader('Accept', 'application/json');
    }

    /** The envelope's own verdict field, which is what the Flutter app reads — never the HTTP code alone. */
    private function envelope(TestResponse $response)
    {
        return $response->json('status');
    }

    private function body(TestResponse $response): string
    {
        return substr((string) preg_replace('/\s+/', ' ', (string) $response->getContent()), 0, 400);
    }

    /**
     * The shape every permission refusal in this controller family uses when it is done properly:
     * an explicit HTTP 403 whose envelope is NOT a success. Asserted as one helper so that a route
     * which starts answering `error()` (HTTP 200, `{status: 0}`) instead cannot pass quietly.
     */
    private function assertRefused(TestResponse $response, string $route): void
    {
        $this->assertNotSame(401, $response->status(),
            $route . ': refused with 401. The shipped Flutter app force-clears its session on any '
            . '401 (repo-B lib/network/network.dart:285-287), so this signs the merchant out of the '
            . 'whole app rather than telling them they lack a right.');

        $this->assertNotSame(1, $this->envelope($response),
            $route . ': the envelope says success. Body: ' . $this->body($response));
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  1. addcategory — GATED 2026-11-25 (and the INSERT is still broken, separately)              ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    private function addCategory(User $u, int $storeId, string $name): TestResponse
    {
        return $this->as($u)->post('/api/admin/addcategory', [
            'store_id' => $storeId,
            'name' => $name,
            'trending' => 0,
            'status' => 1,
            'image' => $this->image('cat.png'),
            'icon_image' => $this->image('icon.png'),
        ]);
    }

    /**
     * GATED 2026-11-25 by `callerMayActInStore(['Create Product Category'])` -> explicit 403.
     *
     * WHAT THIS PINNED BEFORE: `AddCategory` asked only a tenancy predicate, so an unpermissioned
     * staff member was admitted all the way to the `INSERT`. The name the gate took is the web
     * twin's own -- `CategoryController::store()` gates on `Create Product Category` (`:54`) and
     * the screen on `Manage Product Category` (`:26`), both seeded by `Utility.php`. (An earlier
     * draft of this docblock said `Create Main Category` / `MaincategoryController`; neither
     * exists, which is the same class of mistake as the token census this file replaces, so the
     * names were checked against `Utility.php` and against the controller rather than guessed from
     * the route's wording.)
     *
     * THE SECOND FINDING IS UNCHANGED AND IS STILL PINNED HERE: **`AddCategory` cannot create a
     * category on any engine that enforces NOT NULL.** `categories.slug` is `NOT NULL` with no
     * default (`2023_12_25_093024_create_categories_table.php:17`) and the handler never sets it,
     * so every principal the gate ADMITS ends in an integrity-constraint 500 -- the owner included,
     * and production's MariaDB enforces it (STRICT_TRANS_TABLES). The permission gate does not
     * touch that and must not be reported as touching it.
     *
     * SO THE ADMITTED LEGS ARE 500 AND THAT IS THE PROOF OF ADMISSION, because every refusal in
     * this handler returns before the insert. The message is asserted so a DIFFERENT 500 cannot
     * stand in for it. What changed is the STAFF leg: 500 (admitted) -> 403 (refused).
     */
    public function test_GATED_addcategory(): void
    {
        $before = DB::table('categories')->count();

        $staffResponse = $this->addCategory($this->staff, $this->store->id, 'CENSUS-STAFF-CATEGORY');

        $this->assertSame(403, $staffResponse->status(),
            'addcategory admitted unpermissioned staff. A 500 here means the gate is gone and the '
            . 'caller reached the INSERT again. Body: ' . $this->body($staffResponse));
        $this->assertRefused($staffResponse, 'addcategory');
        $this->assertSame($before, DB::table('categories')->count(),
            'the refused call still created a category');

        /* THE CONTROL, ON BOTH LIMBS OF THE GATE: a colleague who differs only in holding the name,
           and the store's own owner, who holds no permission at all and must not be locked out.
           EITHER ENGINE'S WORDING is accepted for the insert failure -- SQLite says
           `NOT NULL constraint failed: categories.slug`, MySQL in STRICT_TRANS_TABLES says
           `Field 'slug' doesn't have a default value` -- because this file's finding is about
           production's MariaDB and pinning the weaker message would hide the stronger one. */
        foreach ([
            'staff granted `Create Product Category`'
                => $this->staffIn($this->merchant, $this->store, 'Create Product Category'),
            "the store's own owner" => $this->merchant,
        ] as $who => $principal) {
            $response = $this->addCategory($principal, $this->store->id, 'CENSUS-ADMITTED-CATEGORY');

            $this->assertSame(500, $response->status(),
                $who . ' no longer reaches the INSERT. A 403 here is a LOCKOUT, not a gate; a '
                . 'success means categories.slug has been fixed and this leg should assert the row '
                . 'instead. Body: ' . $this->body($response));
            $this->assertMatchesRegularExpression(
                "/categories\.slug|Field 'slug'/",
                (string) $response->getContent(),
                $who . ': the 500 is no longer the missing-slug write failure, so it is no longer '
                . 'evidence that this principal reached the insert. Body: ' . $this->body($response)
            );
        }

        $this->assertSame($before, DB::table('categories')->count(),
            'a category row landed despite the NOT NULL failure');

        /* TENANCY HOLDS, and keeps its own answer: the gate sits BELOW the tenancy predicate, so an
           outsider still gets `Store not found!` rather than a permission refusal. */
        $outsiderResponse = $this->addCategory($this->outsider, $this->store->id, 'CENSUS-OUTSIDER-CATEGORY');
        $this->assertSame(200, $outsiderResponse->status());
        $this->assertSame(0, $this->envelope($outsiderResponse),
            'another tenant\'s merchant was not refused by the tenancy predicate');
        $this->assertSame('Store not found!', $outsiderResponse->json('data.message'));
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  2. addcoupon — GATED                                                                        ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    private function addCoupon(User $u, int $storeId, string $name): TestResponse
    {
        return $this->as($u)->postJson('/api/admin/addcoupon', [
            'store_id' => $storeId,
            'coupon_name' => $name,
            'coupon_type' => 'percentage',
            'discount_amount' => 50,
            'coupon_limit' => 10,
            'coupon_expiry_date' => '2030-01-01',
            'coupon_code' => 'CENSUS' . random_int(100000, 999999),
            'status' => 1,
        ]);
    }

    /** GATED by `callerMayActInStore(['Create Coupon','Manage Coupon'])` → explicit 403 (GAP-142/P4-F065). */
    public function test_GATED_addcoupon(): void
    {
        $response = $this->addCoupon($this->staff, $this->store->id, 'CENSUS-STAFF-COUPON');

        $this->assertSame(403, $response->status(), 'addcoupon admitted unpermissioned staff: ' . $this->body($response));
        $this->assertRefused($response, 'addcoupon');
        $this->assertSame(0, DB::table('coupons')->where('coupon_name', 'CENSUS-STAFF-COUPON')->count(),
            'the refused call still minted a coupon — discount_amount is money off a basket');

        $owner = $this->addCoupon($this->merchant, $this->store->id, 'CENSUS-OWNER-COUPON');
        $this->assertSame(1, $this->envelope($owner), 'the owner was refused: ' . $this->body($owner));
        $this->assertSame(1, DB::table('coupons')->where('coupon_name', 'CENSUS-OWNER-COUPON')->count(),
            'the owner got a success envelope and no coupon landed, so the control proves nothing');

        $outsider = $this->addCoupon($this->outsider, $this->store->id, 'CENSUS-OUTSIDER-COUPON');
        $this->assertSame(0, $this->envelope($outsider), 'another tenant minted a coupon in this store');
        $this->assertSame(0, DB::table('coupons')->where('coupon_name', 'CENSUS-OUTSIDER-COUPON')->count());
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  3. addproduct — GATED 2026-11-25 (it is a PRICE write)                                      ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    private function addProduct(User $u, int $storeId, string $name, float $price): TestResponse
    {
        return $this->as($u)->post('/api/admin/addproduct', [
            'store_id' => $storeId,
            'name' => $name,
            'category_id' => $this->seedCategory($this->store),
            'description' => 'census',
            'price' => $price,
            'product_stock' => 7,
            'trending' => 0,
            'variant_product' => 0,
            'status' => 1,
            'cover_image' => $this->image('cover.png'),
            'product_image' => [$this->image('p1.png')],
        ]);
    }

    /**
     * GATED 2026-11-25 by `callerMayActInStore(['Create Product'])` -> explicit 403.
     *
     * WHAT THIS PINNED BEFORE, AND IT WAS THE MONEY ROW OF THIS GROUP: `AddProduct` checked
     * `OperatorCatalogueWrite::refuses()` (the PLATFORM OPERATOR only -- the opposite axis) and a
     * tenancy predicate, and asked nothing about what the caller may do inside the store. So an
     * unpermissioned staff member published a sellable product with its own `price` and
     * `product_stock`, and `products.price` is what a shopper is charged.
     *
     * `Create Product` is the web twin's own name: `ProductController::store()` gates creation on
     * it (`:571`, `:614`), while the catalogue SCREEN uses `Manage Products` (`:475`) and the
     * EDITOR uses the plural `Edit Products`, which is what the sibling `UpdateProduct` took
     * (GAP-250). Three names, three doors, each read from the twin rather than chosen.
     *
     * THE GATE IS BELOW THE F206 OPERATOR REFUSAL, so the platform operator keeps
     * `OperatorCatalogueWrite`'s own answer here -- that was an owner decision and must not be
     * reworded into a permission refusal. `OperatorCatalogueWriteTest` pins it.
     *
     * The write legs read the ROW back rather than trusting the envelope, because `success()` and
     * `error()` are both HTTP 200 here.
     */
    public function test_GATED_addproduct(): void
    {
        $response = $this->addProduct($this->staff, $this->store->id, 'CENSUS-STAFF-PRODUCT', 1234.5);

        $this->assertSame(403, $response->status(),
            'addproduct admitted unpermissioned staff: ' . $this->body($response));
        $this->assertRefused($response, 'addproduct');
        $this->assertSame(0, DB::table('products')->where('name', 'CENSUS-STAFF-PRODUCT')->count(),
            'UNPERMISSIONED STAFF PUBLISHED A PRICED PRODUCT through api/admin/addproduct');

        /* THE CONTROL: a colleague of the same store differing only in holding the name. Without
           it the refusal above is equally satisfied by a gate that admits nobody. */
        $permitted = $this->addProduct(
            $this->staffIn($this->merchant, $this->store, 'Create Product'),
            $this->store->id,
            'CENSUS-PERMITTED-PRODUCT',
            11.0
        );
        $this->assertSame(1, $this->envelope($permitted),
            'addproduct refused the holder of `Create Product`: ' . $this->body($permitted));

        $row = DB::table('products')->where('name', 'CENSUS-PERMITTED-PRODUCT')->first();
        $this->assertNotNull($row,
            'the permitted caller got a success envelope and no product landed, so this door '
            . 'cannot publish at all and the refusal above measures nothing');
        $this->assertSame((int) $this->store->id, (int) $row->store_id);
        $this->assertEqualsWithDelta(11.0, (float) $row->price, 0.01);
        $this->assertSame(7, (int) $row->product_stock);

        $owner = $this->addProduct($this->merchant, $this->store->id, 'CENSUS-OWNER-PRODUCT', 10.0);
        $this->assertSame(1, $this->envelope($owner), 'the owner was refused: ' . $this->body($owner));
        $this->assertSame(1, DB::table('products')->where('name', 'CENSUS-OWNER-PRODUCT')->count());

        $outsider = $this->addProduct($this->outsider, $this->store->id, 'CENSUS-OUTSIDER-PRODUCT', 10.0);
        $this->assertSame(0, $this->envelope($outsider), 'another tenant published into this store');
        $this->assertSame(0, DB::table('products')->where('name', 'CENSUS-OUTSIDER-PRODUCT')->count());
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  4. addproductbrand — GATED 2026-11-25                                                       ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    private function addProductBrand(User $u, int $storeId, string $name): TestResponse
    {
        return $this->as($u)->post('/api/admin/addproductbrand', [
            'store_id' => $storeId,
            'name' => $name,
            'status' => 1,
            'is_popular' => 0,
            'logo' => $this->image('logo.png'),
        ]);
    }

    /**
     * GATED 2026-11-25 by `callerMayActInStore(['Create Product Brand'])` -> explicit 403, the name
     * the web twin `ProductBrandController::store()` already used (`:41`, `:53`; the screen uses
     * `Manage Product Brand`, `:18`).
     *
     * WHAT THIS PINNED BEFORE: the handler resolved the store and nothing else, so an
     * unpermissioned staff member created a brand and uploaded its logo -- consuming the OWNER's
     * plan storage quota through `Utility::updateStorageLimit($user->creatorId(), …)`.
     */
    public function test_GATED_addproductbrand(): void
    {
        $response = $this->addProductBrand($this->staff, $this->store->id, 'CENSUS-STAFF-BRAND');

        $this->assertSame(403, $response->status(),
            'addproductbrand admitted unpermissioned staff: ' . $this->body($response));
        $this->assertRefused($response, 'addproductbrand');
        $this->assertSame(0, DB::table('product_brands')->where('name', 'CENSUS-STAFF-BRAND')->count(),
            'the refused call still created a brand');

        $permitted = $this->addProductBrand(
            $this->staffIn($this->merchant, $this->store, 'Create Product Brand'),
            $this->store->id,
            'CENSUS-PERMITTED-BRAND'
        );
        $this->assertSame(1, $this->envelope($permitted),
            'addproductbrand refused the holder of `Create Product Brand`: ' . $this->body($permitted));
        $this->assertSame(1, DB::table('product_brands')->where('name', 'CENSUS-PERMITTED-BRAND')->count(),
            'the permitted caller was admitted and no brand landed, so the refusal above measures '
            . 'nothing');

        $owner = $this->addProductBrand($this->merchant, $this->store->id, 'CENSUS-OWNER-BRAND');
        $this->assertSame(1, $this->envelope($owner), 'the owner was refused: ' . $this->body($owner));
        $this->assertSame(1, DB::table('product_brands')->where('name', 'CENSUS-OWNER-BRAND')->count());

        $outsider = $this->addProductBrand($this->outsider, $this->store->id, 'CENSUS-OUTSIDER-BRAND');
        $this->assertSame(0, $this->envelope($outsider), 'another tenant created a brand in this store');
        $this->assertSame(0, DB::table('product_brands')->where('name', 'CENSUS-OUTSIDER-BRAND')->count());
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  5. addproductlable — GATED 2026-11-25                                                       ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    private function addProductLabel(User $u, int $storeId, string $name): TestResponse
    {
        return $this->as($u)->postJson('/api/admin/addproductlable', [
            'store_id' => $storeId,
            'name' => $name,
            'status' => 1,
        ]);
    }

    /**
     * GATED 2026-11-25 by `callerMayActInStore(['Create Product Label'])` -> explicit 403, the name
     * the web twin `ProductLabelController::store()` already used (the screen uses
     * `Manage Product Label`, `:16`).
     *
     * WHAT THIS PINNED BEFORE: the handler resolved the store, validated a name and wrote. No
     * permission limb.
     */
    public function test_GATED_addproductlable(): void
    {
        $response = $this->addProductLabel($this->staff, $this->store->id, 'CENSUS-STAFF-LABEL');

        $this->assertSame(403, $response->status(),
            'addproductlable admitted unpermissioned staff: ' . $this->body($response));
        $this->assertRefused($response, 'addproductlable');
        $this->assertSame(0, DB::table('product_labels')->where('name', 'CENSUS-STAFF-LABEL')->count(),
            'the refused call still created a label');

        $permitted = $this->addProductLabel(
            $this->staffIn($this->merchant, $this->store, 'Create Product Label'),
            $this->store->id,
            'CENSUS-PERMITTED-LABEL'
        );
        $this->assertSame(1, $this->envelope($permitted),
            'addproductlable refused the holder of `Create Product Label`: ' . $this->body($permitted));
        $this->assertSame(1, DB::table('product_labels')->where('name', 'CENSUS-PERMITTED-LABEL')->count(),
            'the permitted caller was admitted and no label landed');

        $owner = $this->addProductLabel($this->merchant, $this->store->id, 'CENSUS-OWNER-LABEL');
        $this->assertSame(1, $this->envelope($owner), 'the owner was refused: ' . $this->body($owner));
        $this->assertSame(1, DB::table('product_labels')->where('name', 'CENSUS-OWNER-LABEL')->count());

        $outsider = $this->addProductLabel($this->outsider, $this->store->id, 'CENSUS-OUTSIDER-LABEL');
        $this->assertSame(0, $this->envelope($outsider), 'another tenant created a label in this store');
        $this->assertSame(0, DB::table('product_labels')->where('name', 'CENSUS-OUTSIDER-LABEL')->count());
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  6. addreview — GATED 2026-11-25; the FABRICATED AUTHOR is a second, still-open finding      ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    private function addReview(User $u, int $storeId, int $productId, int $customerId, string $title): TestResponse
    {
        return $this->as($u)->postJson('/api/admin/addreview', [
            'store_id' => $storeId,
            'product_id' => $productId,
            'user_id' => $customerId,
            'rating' => 5,
            'title' => $title,
            'description' => 'census',
            'status' => '1',
        ]);
    }

    /**
     * GATED 2026-11-25 by `callerMayActInStore(['Create Testimonial'])` -> explicit 403, the name
     * the web twin `TestimonialController::store()` already used (`:55`; the screen uses
     * `Manage Testimonial`, `:25`). Both are seeded by `Utility.php`, and the twin writes the same
     * `Testimonial` model.
     *
     * WHAT THIS PINNED BEFORE, AND WHY IT WAS WORSE THAN A CATALOGUE WRITE: `user_id` is taken
     * STRAIGHT FROM REQUEST INPUT and is a `customers.id`, so an unpermissioned staff member
     * published a live, 5-star, `status = 1` review ATTRIBUTED TO A REAL SHOPPER of that store, and
     * `Testimonial::AvregeRating()` folded it into the product's displayed `average_rating`.
     *
     * THE ATTRIBUTION HALF IS NOT FIXED BY THIS GATE and keeps its own case below: a PERMITTED
     * caller can still name any `user_id`, including another tenant's customer or none at all. A
     * permission gate is not a validation rule and must not be reported as one.
     */
    public function test_GATED_addreview(): void
    {
        $productId = $this->seedProduct($this->store);
        $customerId = $this->seedCustomer($this->store);

        $response = $this->addReview($this->staff, $this->store->id, $productId, $customerId, 'CENSUS-STAFF-REVIEW');

        $this->assertSame(403, $response->status(),
            'addreview admitted unpermissioned staff: ' . $this->body($response));
        $this->assertRefused($response, 'addreview');
        $this->assertSame(0, DB::table('testimonials')->where('title', 'CENSUS-STAFF-REVIEW')->count(),
            'UNPERMISSIONED STAFF PUBLISHED A REVIEW IN A NAMED CUSTOMER\'S NAME');

        /* THE CONTROL: the same request by a colleague who differs only in holding the name. The
           row is read back, because the fabricated attribution is the property that makes this
           door worth gating and a success envelope alone does not show it. */
        $permittedProduct = $this->seedProduct($this->store);
        $permitted = $this->addReview(
            $this->staffIn($this->merchant, $this->store, 'Create Testimonial'),
            $this->store->id,
            $permittedProduct,
            $customerId,
            'CENSUS-PERMITTED-REVIEW'
        );
        $this->assertSame(1, $this->envelope($permitted),
            'addreview refused the holder of `Create Testimonial`: ' . $this->body($permitted));

        $row = DB::table('testimonials')->where('title', 'CENSUS-PERMITTED-REVIEW')->first();
        $this->assertNotNull($row,
            'the permitted caller was admitted and no review landed, so the refusal above measures '
            . 'nothing');
        $this->assertSame($customerId, (int) $row->user_id);
        $this->assertSame(1, (int) $row->status, 'the review was published live, not held for moderation');

        $ownerProduct = $this->seedProduct($this->store);
        $owner = $this->addReview($this->merchant, $this->store->id, $ownerProduct, $customerId, 'CENSUS-OWNER-REVIEW');
        $this->assertSame(1, $this->envelope($owner), 'the owner was refused: ' . $this->body($owner));
        $this->assertSame(1, DB::table('testimonials')->where('title', 'CENSUS-OWNER-REVIEW')->count());

        $outsiderProduct = $this->seedProduct($this->store);
        $outsider = $this->addReview($this->outsider, $this->store->id, $outsiderProduct, $customerId, 'CENSUS-OUTSIDER-REVIEW');
        $this->assertSame(0, $this->envelope($outsider), 'another tenant reviewed into this store');
        $this->assertSame(0, DB::table('testimonials')->where('title', 'CENSUS-OUTSIDER-REVIEW')->count());
    }

    /**
     * AND `user_id` IS STILL NOT CHECKED AGAINST ANYTHING AT ALL — a second finding on the same
     * handler, separated out so that the permission gate above cannot be mistaken for a fix for
     * this. STILL OPEN after 2026-11-25.
     *
     * `AddReview` validates `user_id` as `required` and nothing more: no `exists:customers,id`, and
     * no `store_id` predicate against the customer. The PRODUCT is checked
     * (`where('store_id', $store->id)`) and the duplicate test is store-scoped, so the tenancy
     * control on the SUBJECT of the review is real — but the ATTRIBUTED AUTHOR is free text.
     *
     * DRIVEN BY A PERMITTED PRINCIPAL NOW, and that rewrite is the point rather than a convenience:
     * this case used to drive the unpermissioned staff member, so the gate made it red. A case that
     * merely asserted "it is now refused" would have lost the finding — the missing validation is a
     * property of the handler for EVERY caller it admits, so the holder of `Create Testimonial` is
     * the principal that still demonstrates it.
     *
     * Why it matters beyond tidiness: `testimonials.user_id` is what a storefront joins on to print
     * the reviewer, and `Customer` IS a `scoped_models` member — so the row written here is one the
     * owning store can neither see nor moderate, pointing at a person who is not their customer.
     */
    public function test_addreview_still_accepts_a_user_id_from_another_tenant(): void
    {
        $author = $this->staffIn($this->merchant, $this->store, 'Create Testimonial');
        $productId = $this->seedProduct($this->store);
        $foreignCustomerId = $this->seedCustomer($this->outsiderStore);

        $response = $this->addReview($author, $this->store->id, $productId, $foreignCustomerId, 'CENSUS-FOREIGN-AUTHOR');

        $this->assertSame(1, $this->envelope($response),
            'addreview now refuses either the holder of `Create Testimonial` or the foreign '
            . 'user_id. The first would be a LOCKOUT; the second is the fix this row is waiting '
            . 'for. Body: ' . $this->body($response));

        $row = DB::table('testimonials')->where('title', 'CENSUS-FOREIGN-AUTHOR')->first();
        $this->assertNotNull($row, 'the success envelope is not matched by a row');
        $this->assertSame($foreignCustomerId, (int) $row->user_id,
            'addreview attributed the review to ANOTHER TENANT\'S CUSTOMER id — `user_id` carries no '
            . 'exists: rule and no store predicate');
        $this->assertSame((int) $this->store->id, (int) $row->store_id,
            'the row did not land in the review\'s own store, so this case measures something else');

        /* An id belonging to NO customer at all is accepted too — the same missing rule. */
        $absent = 987654321;
        $this->assertSame(0, DB::table('customers')->where('id', $absent)->count(), 'fixture: that id exists');

        $second = $this->seedProduct($this->store);
        $ghost = $this->addReview($author, $this->store->id, $second, $absent, 'CENSUS-GHOST-AUTHOR');
        $this->assertSame(1, $this->envelope($ghost),
            'addreview now rejects a user_id that matches no customer — the exists: rule has been added');
        $this->assertSame($absent, (int) DB::table('testimonials')->where('title', 'CENSUS-GHOST-AUTHOR')->value('user_id'));

        /* AND THE GATE ABOVE IS STILL THERE, so this case cannot be read as "the door reopened". */
        $this->assertSame(403,
            $this->addReview($this->staff, $this->store->id, $this->seedProduct($this->store), $absent, 'CENSUS-UNGATED-AUTHOR')
                ->status(),
            'an unpermissioned staff member reached this handler again');
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  7. addshipping   8. addtax — GATED                                                          ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    private function addShipping(User $u, int $storeId, string $name): TestResponse
    {
        return $this->as($u)->postJson('/api/admin/addshipping', [
            'store_id' => $storeId, 'name' => $name, 'description' => 'census',
        ]);
    }

    private function addTax(User $u, int $storeId, string $name): TestResponse
    {
        return $this->as($u)->postJson('/api/admin/addtax', ['store_id' => $storeId, 'name' => $name]);
    }

    /** GATED by `callerMayActInStore(['Create Shipping Class','Manage Shipping Class'])` → 403 (GAP-142/P4-F075). */
    public function test_GATED_addshipping(): void
    {
        $response = $this->addShipping($this->staff, $this->store->id, 'CENSUS-STAFF-SHIPPING');

        $this->assertSame(403, $response->status(), 'addshipping admitted unpermissioned staff: ' . $this->body($response));
        $this->assertRefused($response, 'addshipping');
        $this->assertSame(0, DB::table('shippings')->where('name', 'CENSUS-STAFF-SHIPPING')->count());

        $owner = $this->addShipping($this->merchant, $this->store->id, 'CENSUS-OWNER-SHIPPING');
        $this->assertSame(1, $this->envelope($owner), 'the owner was refused: ' . $this->body($owner));
        $this->assertSame(1, DB::table('shippings')->where('name', 'CENSUS-OWNER-SHIPPING')->count());

        $outsider = $this->addShipping($this->outsider, $this->store->id, 'CENSUS-OUTSIDER-SHIPPING');
        $this->assertSame(0, $this->envelope($outsider));
        $this->assertSame(0, DB::table('shippings')->where('name', 'CENSUS-OUTSIDER-SHIPPING')->count());
    }

    /** GATED by `callerMayActInStore(['Create Tax','Manage Tax'])` → 403 (GAP-142/P4-F075). */
    public function test_GATED_addtax(): void
    {
        $response = $this->addTax($this->staff, $this->store->id, 'CENSUS-STAFF-TAX');

        $this->assertSame(403, $response->status(), 'addtax admitted unpermissioned staff: ' . $this->body($response));
        $this->assertRefused($response, 'addtax');
        $this->assertSame(0, DB::table('taxes')->where('name', 'CENSUS-STAFF-TAX')->count());

        $owner = $this->addTax($this->merchant, $this->store->id, 'CENSUS-OWNER-TAX');
        $this->assertSame(1, $this->envelope($owner), 'the owner was refused: ' . $this->body($owner));
        $this->assertSame(1, DB::table('taxes')->where('name', 'CENSUS-OWNER-TAX')->count());

        $outsider = $this->addTax($this->outsider, $this->store->id, 'CENSUS-OUTSIDER-TAX');
        $this->assertSame(0, $this->envelope($outsider));
        $this->assertSame(0, DB::table('taxes')->where('name', 'CENSUS-OUTSIDER-TAX')->count());
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  9. addvariant — no permission limb, but the handler is INERT: it refuses everybody           ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    private function addVariant(User $u, int $storeId): TestResponse
    {
        return $this->as($u)->postJson('/api/admin/addvariant', [
            'store_id' => $storeId, 'name' => 'CENSUS-VARIANT', 'type' => 'select',
        ]);
    }

    /**
     * `AddVariant` (`:2616`) has NO permission limb — and no write either. After `resolveOwnedStore()`
     * and the validator it returns `error()` unconditionally ("a product variant cannot be created
     * without a product"). So there is nothing for a gate to protect, and the OWNER is refused too.
     *
     * Recorded rather than filed: the route is reachable by an unpermissioned staff member, but the
     * only thing they can obtain is that refusal. If the endpoint is ever reinstated it will need the
     * gate it has never had, and this test will fail at that moment.
     */
    public function test_addvariant_is_inert_and_refuses_every_principal(): void
    {
        foreach (['staff' => $this->staff, 'owner' => $this->merchant] as $label => $principal) {
            $response = $this->addVariant($principal, $this->store->id);

            $this->assertSame(200, $response->status(), $label . ': ' . $this->body($response));
            $this->assertSame(0, $this->envelope($response),
                $label . ': addvariant is no longer inert — it answered a success envelope, so it now '
                . 'needs the permission gate it has never had. Body: ' . $this->body($response));
            $this->assertStringContainsString('cannot be created without a product',
                (string) $response->json('data.message'),
                $label . ': the refusal is no longer the endpoint-disabled one');
        }

        $outsider = $this->addVariant($this->outsider, $this->store->id);
        $this->assertSame('Store not found!', $outsider->json('data.message'));
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║ 10. adminlogin — an AUTHENTICATION door; no permission axis exists                           ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * `adminlogin` carries `AdminApiLog` and `throttle:auth` and NO `custom.auth` — by design: it is
     * where a token comes from. "Unpermissioned staff" is not an attacker here, it is the user: a
     * staff member signing into the Flutter app with their own password gets their own token.
     *
     * So this row is recorded as GATED BY CREDENTIALS, and what is asserted is the three properties
     * that make that true: a wrong password is refused, the account-state switches are honoured, and
     * the token issued belongs to the caller and to nobody else. `login()` also now returns a
     * `permissions` list (P2-07-13), and the measurement that matters for this census is that an
     * unpermissioned staff member's list is EMPTY — the app is told the truth about what they may do.
     */
    public function test_GATED_BY_CREDENTIALS_adminlogin(): void
    {
        $good = $this->anonymously()->postJson('/api/admin/adminlogin', [
            'email' => $this->staff->email, 'password' => 'password',
        ]);

        $this->assertSame(1, $this->envelope($good), 'a staff member could not sign in: ' . $this->body($good));
        $this->assertSame((int) $this->staff->id, (int) $good->json('data.id'),
            'the login handed back somebody else\'s identity');
        $this->assertSame([], $good->json('data.permissions'),
            'adminlogin told the app that an unpermissioned staff member holds permissions');
        $this->assertNotEmpty($good->json('data.token'));

        $tokenOwner = DB::table('personal_access_tokens')
            ->where('token', hash('sha256', explode('|', (string) $good->json('data.token'), 2)[1]))
            ->first();
        $this->assertNotNull($tokenOwner);
        $this->assertSame((int) $this->staff->id, (int) $tokenOwner->tokenable_id,
            'the issued token belongs to a different user than the one who authenticated');

        $bad = $this->anonymously()->postJson('/api/admin/adminlogin', [
            'email' => $this->staff->email, 'password' => 'not-the-password',
        ]);
        $this->assertSame(0, $this->envelope($bad), 'adminlogin accepted a wrong password');
        $this->assertSame('Invalid login details', $bad->json('data.message'));

        /* The account-state limb, which the API door did not have before FIX-1: a staff member whose
           login the merchant disabled must not be able to mint a fresh never-expiring token. */
        DB::table('users')->where('id', $this->staff->id)->update(['is_enable_login' => 0]);
        (new User)->flushCache();

        $disabled = $this->anonymously()->postJson('/api/admin/adminlogin', [
            'email' => $this->staff->email, 'password' => 'password',
        ]);
        $this->assertSame(0, $this->envelope($disabled),
            'a staff member with is_enable_login = 0 was still issued a token');
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║ 11. adminlogout — GATED to the bearer's own token                                            ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * `logouto` (`:1238`) re-implements token verification itself: it splits `{id}|{secret}`, loads the
     * `personal_access_tokens` row and compares with `hash_equals` before deleting it. The route also
     * carries `custom.auth` now (FIX-1).
     *
     * There is no permission axis — a principal may always end their own session — so what is measured
     * is the ONLY thing that could be wrong: that the id in the bearer cannot be pointed at somebody
     * else's token row. Asserted by handing the handler a FORGED bearer that carries another user's
     * token id with the caller's own secret.
     */
    public function test_GATED_adminlogout_deletes_only_the_callers_own_token(): void
    {
        $staffToken = $this->staff->createToken('auth_token')->plainTextToken;
        $victimToken = $this->merchant->createToken('auth_token')->plainTextToken;

        [$victimId] = explode('|', $victimToken, 2);
        [, $staffSecret] = explode('|', $staffToken, 2);

        $forged = $this->anonymously()
            ->withHeader('Authorization', 'Bearer ' . $victimId . '|' . $staffSecret)
            ->postJson('/api/admin/adminlogout', []);

        $this->assertSame(401, $forged->status(),
            'adminlogout accepted a bearer pairing ANOTHER user\'s token id with the caller\'s secret: '
            . $this->body($forged));
        $this->assertSame(1, DB::table('personal_access_tokens')->where('id', $victimId)->count(),
            'the forged logout deleted the owner\'s token');

        $self = $this->anonymously()
            ->withHeader('Authorization', 'Bearer ' . $staffToken)
            ->postJson('/api/admin/adminlogout', []);

        $this->assertSame(1, $this->envelope($self), 'a staff member could not end their own session: ' . $this->body($self));
        $this->assertSame(0, DB::table('personal_access_tokens')->where('id', explode('|', $staffToken, 2)[0])->count(),
            'the logout returned success and the token row survived');
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║ 12. assignOrders — GATED                                                                     ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    /** GATED by `callerMayActInStore(['Manage Order'])` → `orderPermissionDenied()` 403. */
    public function test_GATED_assignOrders(): void
    {
        $order = $this->makeOrder($this->store);
        $boy = $this->seedDeliveryBoy($this->store);

        $response = $this->as($this->staff)->postJson('/api/admin/assignOrders?store_id=' . $this->store->id, [
            'order_id' => $order->id, 'delivery_boy' => $boy,
        ]);

        $this->assertSame(403, $response->status(), 'assignOrders admitted unpermissioned staff: ' . $this->body($response));
        $this->assertRefused($response, 'assignOrders');
        $this->assertNull(DB::table('orders')->where('id', $order->id)->value('deliveryboy_id'),
            'the refused call still assigned the order');

        $owner = $this->as($this->merchant)->postJson('/api/admin/assignOrders?store_id=' . $this->store->id, [
            'order_id' => $order->id, 'delivery_boy' => $boy,
        ]);
        $this->assertSame(200, $owner->status(), 'the owner was refused: ' . $this->body($owner));
        $this->assertSame($boy, (int) DB::table('orders')->where('id', $order->id)->value('deliveryboy_id'),
            'the owner got a success envelope and the assignment did not land');

        $outsider = $this->as($this->outsider)->postJson('/api/admin/assignOrders?store_id=' . $this->store->id, [
            'order_id' => $order->id, 'delivery_boy' => $boy,
        ]);
        $this->assertSame(403, $outsider->status(), 'another tenant assigned this store\'s order');
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║ 13. bank-payment   16. cod-payment   26. emailsetting   29. loyalitysetting — GATED          ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * All four settings writers are GATED by `refuseUnlessMayAdministerStoreSettings()` →
     * `StoreSettingsAccess::permits()` → operator, OR the tenant root themselves, OR `Manage Setting`.
     * The refusal is `error([...], 'fail', 403)`, i.e. an explicit HTTP 403 with `{status: 0}`.
     *
     * THE PERMITTED CALLER CANNOT BE DRIVEN TO COMPLETION ON SQLITE, and that is recorded rather than
     * papered over. All four persist through a raw
     * `insert into settings (...) ... ON DUPLICATE KEY UPDATE`, which SQLite cannot parse — so the
     * owner's leg ends in a 500 at the statement. **That 500 is the positive control**: the gate
     * returns before the statement, so reaching the statement proves the owner was admitted. What
     * cannot be established here is the final row write; that needs a real MySQL
     * (`tests/Support/Security/bin/` is where such evidence belongs).
     *
     */
    #[DataProvider('theSettingsWriters')]
    public function test_GATED_settings_writers(string $route, array $payload, string $witnessSetting, string $witnessValue): void
    {
        $payload['store_id'] = $this->store->id;

        $response = $this->as($this->staff)->postJson('/api/admin/' . $route, $payload);

        $this->assertSame(403, $response->status(),
            $route . ' admitted a staff member holding no `Manage Setting`: ' . $this->body($response));
        $this->assertRefused($response, $route);
        $this->assertSame('Permission denied.', $response->json('data.message'));

        $this->assertNull(
            DB::table('settings')->where('store_id', $this->store->id)->where('name', $witnessSetting)->value('value'),
            $route . ': the refused call still wrote ' . $witnessSetting
        );

        /* The owner is admitted PAST the gate — proved by the request dying inside the MySQL-only
           statement rather than at the 403. */
        $owner = $this->as($this->merchant)->postJson('/api/admin/' . $route, $payload);

        $this->assertSame(500, $owner->status(),
            $route . ': the owner no longer reaches the ON DUPLICATE KEY UPDATE statement. If this is '
            . 'now a 200, the statement has been made portable and this leg should assert the row '
            . 'instead; if it is a 403, the gate has started refusing the tenant root. Body: '
            . $this->body($owner));
        $this->assertStringContainsString('DUPLICATE', (string) $owner->getContent(),
            $route . ': the owner\'s 500 is no longer the MySQL-only statement, so it is no longer '
            . 'evidence that the gate admitted them. Body: ' . $this->body($owner));

        $outsider = $this->as($this->outsider)->postJson('/api/admin/' . $route, $payload);
        $this->assertSame(200, $outsider->status());
        $this->assertSame(0, $this->envelope($outsider), $route . ': another tenant was admitted');
        $this->assertSame('Store not found!', $outsider->json('data.message'),
            $route . ': another tenant got past resolveOwnedStore()');
    }

    public static function theSettingsWriters(): array
    {
        return [
            'bank-payment' => ['bank-payment', [
                'CURRENCY' => 'USD', 'CURRENCY_NAME' => 'Dollar',
                'is_bank_transfer_enabled' => 'on', 'bank_transfer' => 'CENSUS-ATTACKER-IBAN',
            ], 'bank_transfer', 'CENSUS-ATTACKER-IBAN'],
            'cod-payment' => ['cod-payment', [
                'CURRENCY' => 'USD', 'CURRENCY_NAME' => 'Dollar',
                'is_cod_enabled' => 'on', 'cod_info' => 'CENSUS-ATTACKER-COD',
            ], 'cod_info', 'CENSUS-ATTACKER-COD'],
            'emailsetting' => ['emailsetting', [
                'mail_driver' => 'smtp', 'mail_host' => 'attacker.example.test', 'mail_port' => '587',
                'mail_username' => 'census', 'mail_password' => 'CENSUS-SMTP-SECRET',
                'mail_encryption' => 'tls', 'mail_from_address' => 'a@example.test',
                'mail_from_name' => 'Census',
            ], 'MAIL_PASSWORD', 'CENSUS-SMTP-SECRET'],
            'loyalitysetting' => ['loyalitysetting', [
                'loyality_program_enabled' => 'on', 'reward_point' => '99999',
            ], 'reward_point', '99999'],
        ];
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║ 14. change-profile — UNGATED, and it rewrites a DRIVER'S LOGIN IDENTIFIER                    ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    private function changeProfile(User $u, int $deliveryBoyId, string $name, string $email): TestResponse
    {
        return $this->as($u)->postJson('/api/admin/change-profile', [
            'id' => $deliveryBoyId, 'name' => $name, 'email' => $email,
        ]);
    }

    /**
     * MEASUREMENT AWAITING A FIX — `changeProfile` (`:6369`) resolves the target through
     * `resolveManageableDeliveryBoy()`, which is TENANCY ONLY: the driver themselves, the platform
     * operator, or anyone whose `ownedStoreIds()` contains the driver's store. An unpermissioned staff
     * member of the tenant is in that last set, so they may rewrite any of the tenant's drivers'
     * `name`, `contact` and — the part that matters — `email`.
     *
     * `email` IS THE DRIVER'S LOGIN IDENTIFIER. `deliveryLogin` (`:5961`) authenticates on
     * `['email' => …, 'password' => …]`, so moving a driver's address to one the staff member controls
     * points that account's identity, and any address-based recovery, at the attacker — while the
     * SIBLING route `deliveryboysReset-password` is correctly gated on `Reset password Deliveryboy`.
     * Two doors onto the same account, one of them gated.
     *
     * The web twin gates driver edits on `Edit Deliveryboy`, which is the name a fix should use.
     */
    public function test_change_profile_refuses_unpermissioned_staff_a_drivers_login_email(): void
    {
        $boy = $this->seedDeliveryBoy($this->store);
        $original = DB::table('delivery_boys')->where('id', $boy)->first();

        /*
         * CONVERTED 2026-11-25 (GAP-251 / P4-F120): this pin went RED the moment the gate
         * landed and named the route -- which is what a pin is for, not a regression. The
         * expectation below is now the GATED one. A grant leg is included so the refusal
         * cannot pass against a gate that admits nobody.
         */
        $response = $this->changeProfile($this->staff, $boy, 'CENSUS-HIJACKED', 'attacker-census@example.test');

        /* `assertNotSame(1, ...)` rather than `assertSame(0, ...)`: `storePermissionDenied()`
         * answers `{"status":"error"}`, a STRING, while this API's older refusals answer `0`. The
         * claim is "not admitted", and pinning one spelling of refusal would make this case fail
         * the next time a handler's envelope is normalised. The ROW below carries the real claim. */
        $this->assertNotSame(1, $this->envelope($response),
            'change-profile admitted unpermissioned staff. It is gated on `Edit Deliveryboy` as '
            . 'GAP-251 / P4-F120. Body: ' . $this->body($response));

        $after = DB::table('delivery_boys')->where('id', $boy)->first();
        $this->assertSame((string) $original->email, (string) $after->email,
            'UNPERMISSIONED STAFF REWROTE A DRIVER\'S LOGIN EMAIL through api/admin/change-profile '
            . '-- the identifier deliveryLogin authenticates on');
        $this->assertSame((string) $original->name, (string) $after->name);

        /* THE GRANT IS WHAT MAKES THE DIFFERENCE. */
        $permitted = $this->staffIn($this->merchant, $this->store);
        $this->grantPermission(User::find($permitted->id), 'Edit Deliveryboy');
        $this->assertSame(1, $this->envelope(
            $this->changeProfile(User::find($permitted->id), $boy, 'CENSUS-OK', 'granted-census@example.test')
        ), 'staff GRANTED `Edit Deliveryboy` were refused, so the gate admits nobody');
        $this->assertSame('granted-census@example.test',
            (string) DB::table('delivery_boys')->where('id', $boy)->value('email'));

        /* The password hash is NOT touched by this handler — recorded so the finding is not overstated. */
        $this->assertSame((string) $original->password, (string) $after->password,
            'change-profile also rewrote the password hash, which would make this a direct credential write');

        $outsider = $this->changeProfile($this->outsider, $boy, 'CENSUS-OUTSIDER', 'outsider-census@example.test');
        $this->assertSame(0, $this->envelope($outsider), 'another tenant edited this store\'s driver');
        $this->assertSame('granted-census@example.test',
            (string) DB::table('delivery_boys')->where('id', $boy)->value('email'));
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║ 15. changestore — UNGATED, but the only row it writes is the caller's own                    ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * `ChangeStore` (`:5641`) has no permission limb: after `resolveOwnedStore()` it writes
     * `$user->current_store`. Reachable by an unpermissioned staff member — and harmless by
     * construction, because the row written is the CALLER's own and the store must already be inside
     * their tenant. Recorded as UNGATED with no privilege consequence, so that a later reader does not
     * count it among the findings that matter.
     *
     * The tenancy limb is the real control here, and it holds: `storeAccessDenied()` refuses a
     * foreign store with an explicit 403.
     */
    public function test_UNGATED_changestore_writes_only_the_callers_own_current_store(): void
    {
        $second = $this->makeStore($this->merchant);

        $response = $this->as($this->staff)->postJson('/api/admin/changestore', ['store_id' => $second->id]);

        $this->assertSame(1, $this->envelope($response),
            'changestore now refuses unpermissioned staff. Body: ' . $this->body($response));
        $this->assertSame((int) $second->id, (int) DB::table('users')->where('id', $this->staff->id)->value('current_store'),
            'the success envelope is not matched by the row');

        /* Nobody else's current_store moved. */
        $this->assertSame((int) $this->store->id, (int) DB::table('users')->where('id', $this->merchant->id)->value('current_store'),
            'changestore wrote a row other than the caller\'s own');

        $outsider = $this->as($this->outsider)->postJson('/api/admin/changestore', ['store_id' => $this->store->id]);
        $this->assertSame(403, $outsider->status(), 'another tenant switched into this store: ' . $this->body($outsider));
        $this->assertSame((int) $this->outsiderStore->id,
            (int) DB::table('users')->where('id', $this->outsider->id)->value('current_store'));
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║ 17. createOrderViaApi — GATED, and the gate ALSO refuses the store's own owner               ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    private function createOrder(User $u, int $storeId): TestResponse
    {
        $productId = $this->seedProduct($this->store);
        $customerId = $this->seedCustomer($this->store);

        return $this->as($u)->postJson('/api/admin/createOrderViaApi?store_id=' . $storeId, [
            'customer_id' => $customerId,
            'products' => [['product_id' => $productId, 'quantity' => 1, 'price' => 100]],
            'payment_type' => 'bank_transfer',
            'payment_status' => 'Paid',
            'first_name' => 'Census', 'last_name' => 'Buyer',
            'email' => 'buyer@example.test', 'telephone' => '0100000000',
            'address' => '1 Census Street',
        ]);
    }

    /**
     * GATED by `MerchantPermissions::allowsAny(Auth::user(), ['Manage Order'], $store->id)` → 403.
     *
     * AND THE POSITIVE CONTROL FAILS, WHICH IS A FINDING IN ITS OWN RIGHT. That call has no
     * store-owner limb — unlike `callerMayActInStore()`, which tries `callerIsStoreOwner()` FIRST
     * precisely because "a merchant does not implicitly hold any of these permission names". So the
     * store's own owner, holding no granted role, is refused their own order-creation endpoint with
     * the same 403 as a stranger. Measured, both legs, below.
     *
     * This is reported, not fixed: whether a bare owner should be admitted is the same product
     * question `callerMayActInStore()` already answered one way, and this file owns no app code. The
     * third leg proves the gate is otherwise correct by granting `Manage Order` and watching the
     * order land.
     */
    public function test_GATED_createOrderViaApi_but_the_gate_also_refuses_the_bare_owner(): void
    {
        $ordersBefore = DB::table('orders')->count();

        $staffResponse = $this->createOrder($this->staff, $this->store->id);
        $this->assertSame(403, $staffResponse->status(),
            'createOrderViaApi admitted unpermissioned staff: ' . $this->body($staffResponse));
        $this->assertRefused($staffResponse, 'createOrderViaApi');

        $ownerResponse = $this->createOrder($this->merchant, $this->store->id);
        $this->assertSame(403, $ownerResponse->status(),
            'the bare store owner is NO LONGER refused by createOrderViaApi. That is the fix this row '
            . 'was waiting for — MerchantPermissions::allowsAny() has no callerIsStoreOwner() limb. '
            . 'Body: ' . $this->body($ownerResponse));

        $this->assertSame($ordersBefore, DB::table('orders')->count(),
            'one of the two refused calls still created an order');

        /* The gate admits the name it asks for, and the order really lands — so the 403s above are the
           gate firing, not the fixture failing. */
        $permitted = $this->staffIn($this->merchant, $this->store, 'Manage Order');
        $permittedResponse = $this->createOrder($permitted, $this->store->id);

        $this->assertNotSame(403, $permittedResponse->status(),
            'a staff member holding `Manage Order` was still refused: ' . $this->body($permittedResponse));
        $this->assertGreaterThan($ordersBefore, DB::table('orders')->count(),
            'the holder of `Manage Order` was admitted and no order landed, so the gate is '
            . 'short-circuiting the handler rather than admitting it: ' . $this->body($permittedResponse));

        $outsider = $this->createOrder($this->outsider, $this->store->id);
        $this->assertSame(403, $outsider->status(), 'another tenant created an order in this store');
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║ 18. createStoreApi — GATED by `users.type`, but the refusal is HTTP 200                      ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * GATED: `createStoreApi` (`:8487`) refuses anyone whose `users.type` is not `admin` or
     * `super admin`, so an unpermissioned staff member (type `staff`) never reaches the write. It takes
     * no `store_id`, so there is no tenancy axis — it creates a store FOR THE CALLER, which is why the
     * other tenant's merchant legitimately succeeds.
     *
     * THE REFUSAL IS MIS-SHAPED, AND THAT IS WORTH A LINE: the handler writes
     * `$this->error(['message' => __('Permission denied.')], 403)`, but `ApiResponser::error()`'s
     * SECOND argument is the message and its THIRD is the status — so `403` lands in `message` and the
     * response is **HTTP 200** with `{"status":0,"message":403}`. The control works; a client that
     * reads the HTTP code sees success. Pinned below so the shape cannot drift unnoticed.
     */
    public function test_GATED_createStoreApi(): void
    {
        $before = DB::table('stores')->count();

        $response = $this->as($this->staff)->postJson('/api/admin/createStoreApi', [
            'storename' => 'CENSUS-STAFF-STORE',
        ]);

        $this->assertSame(0, $this->envelope($response),
            'createStoreApi admitted a staff member: ' . $this->body($response));
        $this->assertSame(0, DB::table('stores')->where('name', 'CENSUS-STAFF-STORE')->count(),
            'the refused call still created a store');
        $this->assertSame($before, DB::table('stores')->count());

        $this->assertSame(200, $response->status(),
            'createStoreApi\'s refusal is no longer HTTP 200. If it is now 403 the argument order has '
            . 'been corrected, which is the fix this assertion was waiting for.');
        $this->assertSame(403, $response->json('message'),
            'the integer 403 is no longer sitting in the envelope\'s `message` field, so '
            . 'ApiResponser::error()\'s argument order has changed. Body: ' . $this->body($response));

        $owner = $this->as($this->merchant)->postJson('/api/admin/createStoreApi', [
            'storename' => 'CENSUS-OWNER-STORE',
        ]);
        $this->assertSame(1, $this->envelope($owner), 'the owner was refused: ' . $this->body($owner));
        $this->assertSame((int) $this->merchant->id,
            (int) DB::table('stores')->where('name', 'CENSUS-OWNER-STORE')->value('created_by'));

        /* No tenancy axis: the other merchant gets a store of their OWN, which is not a crossing. */
        $outsider = $this->as($this->outsider)->postJson('/api/admin/createStoreApi', [
            'storename' => 'CENSUS-OUTSIDER-STORE',
        ]);
        $this->assertSame(1, $this->envelope($outsider));
        $this->assertSame((int) $this->outsider->id,
            (int) DB::table('stores')->where('name', 'CENSUS-OUTSIDER-STORE')->value('created_by'),
            'createStoreApi attributed the new store to somebody other than its caller');
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║ 19. currency — UNGATED read, and it reads the CALLER'S OWN store only                        ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * `currency` (`:1061`) has no permission limb. It is recorded as UNGATED-but-harmless for a reason
     * that is measured rather than assumed: it IGNORES `store_id` entirely and reads
     * `$user->current_store`, so a caller can only ever obtain their own store's `CURRENCY` and
     * `CURRENCY_NAME`. No PII, no money, and no cross-tenant read — asserted by driving the outsider
     * at this store's id and watching them get THEIR OWN currency back.
     */
    public function test_UNGATED_currency_is_a_self_read(): void
    {
        DB::table('settings')->insert([
            ['name' => 'CURRENCY', 'value' => 'CENSUS-VICTIM-SYMBOL', 'store_id' => $this->store->id, 'created_by' => $this->merchant->id, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'CURRENCY', 'value' => 'CENSUS-OUTSIDER-SYMBOL', 'store_id' => $this->outsiderStore->id, 'created_by' => $this->outsider->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
        (new \App\Models\Setting)->flushCache();

        $staffResponse = $this->as($this->staff)->postJson('/api/admin/currency', ['store_id' => $this->store->id]);
        $this->assertSame(1, $this->envelope($staffResponse),
            'currency now refuses unpermissioned staff. Body: ' . $this->body($staffResponse));
        $this->assertSame('CENSUS-VICTIM-SYMBOL', $staffResponse->json('data.currency'));

        $outsider = $this->as($this->outsider)->postJson('/api/admin/currency', ['store_id' => $this->store->id]);
        $this->assertSame('CENSUS-OUTSIDER-SYMBOL', $outsider->json('data.currency'),
            'currency answered another tenant with THIS store\'s setting — it has started trusting '
            . 'the request\'s store_id instead of the principal\'s current_store');
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║ 20. dashboard — GATED PER FIGURE: admitted, but the business data is withheld                ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * `dashboard` (`:1289`) answers an unpermissioned staff member with a SUCCESS envelope — so a
     * status-only census would record it as ungated — but every figure in it is behind a
     * `MerchantPermissions::held()` test, and the response omits the keys rather than zeroing them.
     * What remains is the caller's own name and avatar and the store's public landing URL.
     *
     * Verdict: GATED, per figure. Asserted by ABSENCE of the keys, and then by presence once the
     * matching names are granted — because an assertion that only checks absence would also pass
     * against a handler that had stopped producing those figures for everybody.
     */
    public function test_GATED_dashboard_withholds_every_business_figure_from_unpermissioned_staff(): void
    {
        $this->makeOrder($this->store, ['final_price' => 4242, 'delivered_status' => 1]);
        $this->seedProduct($this->store);
        DB::table('coupons')->insert([
            'coupon_name' => 'CENSUS-DASH-COUPON', 'coupon_code' => 'CENSUSDASH',
            'coupon_type' => 'percentage', 'discount_amount' => 25, 'coupon_limit' => 5,
            'coupon_expiry_date' => '2030-01-01', 'status' => 1, 'store_id' => $this->store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->as($this->staff)->postJson('/api/admin/dashboard', ['store_id' => $this->store->id]);

        $this->assertSame(1, $this->envelope($response), 'the dashboard refused outright: ' . $this->body($response));
        $this->assertSame([], $response->json('data.permissions'),
            'the fixture staff member holds a permission, so this case no longer measures a '
            . 'zero-permission principal');

        foreach (['order', 'sales', 'products', 'product', 'recent_order', 'coupons', 'orderchart', 'visitorchart'] as $key) {
            $this->assertNull($response->json('data.' . $key),
                'dashboard handed a zero-permission staff token `' . $key . '`: ' . $this->body($response));
        }

        /* AND THE FIGURES EXIST FOR SOMEBODY — otherwise the absences above would pass against a
           dashboard that had simply stopped computing them. */
        $permitted = $this->staffIn($this->merchant, $this->store, 'Manage Order');
        $this->grantPermission($permitted, 'Manage Products');
        $permitted = User::find($permitted->id);

        $allowed = $this->as($permitted)->postJson('/api/admin/dashboard', ['store_id' => $this->store->id]);
        $this->assertNotNull($allowed->json('data.order'),
            'the holder of `Manage Order` was also denied the order count, so the withholding above '
            . 'is not a permission decision: ' . $this->body($allowed));
        $this->assertNotNull($allowed->json('data.products'),
            'the holder of `Manage Products` was denied the product count');
        $this->assertNull($allowed->json('data.coupons'),
            'a staff member holding no coupon name was handed the coupon block');
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║ 21. delete-user — UNGATED, and it DELETES a delivery boy                                     ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * MEASUREMENT AWAITING A FIX — `delete_user` (`:6444`) is the delivery-boy deletion door
     * (`resolveManageableDeliveryBoy()`, tenancy only, no permission limb), reached by an
     * unpermissioned staff member of the tenant.
     *
     * The sibling route `deliveryboysDestroy/{id}` on `DeliveryBoyApiController` IS gated
     * (`Delete Deliveryboy`), so this is the same resource with two doors and one gate — exactly the
     * shape that makes a grep-based census read "covered". `Delete Deliveryboy` is the name a fix
     * should use.
     *
     * `delivery_boys` is a `scoped_models` member and `Cachable`, so the "it is gone" assertion reads
     * `DB::table()` — a `DeliveryBoy::find()` here would answer against whichever tenant resolved and
     * pass whether or not the row survived.
     */
    public function test_delete_user_refuses_unpermissioned_staff_a_delivery_boy(): void
    {
        $boy = $this->seedDeliveryBoy($this->store);

        $response = $this->as($this->staff)->postJson('/api/admin/delete-user', ['user_id' => $boy]);

        /* CONVERTED 2026-11-25 (GAP-251 / P4-F120): this pin went RED when the gate landed and
         * named the route -- what a pin is for, not a regression. `assertNotSame(1, ...)` because
         * `storePermissionDenied()` answers `{"status":"error"}` while older refusals answer `0`;
         * the claim is "not admitted" and the ROW carries it. A grant leg follows so the refusal
         * cannot pass against a gate that admits nobody. */
        $this->assertNotSame(1, $this->envelope($response),
            'delete-user now refuses unpermissioned staff — the fix this row was waiting for. '
            . 'Body: ' . $this->body($response));
        $this->assertSame(1, DB::table('delivery_boys')->where('id', $boy)->count(),
            'UNPERMISSIONED STAFF DELETED A DRIVER through api/admin/delete-user. Gated on '
            . '`Delete Deliveryboy` as GAP-251 / P4-F120.');

        /* THE GRANT IS WHAT MAKES THE DIFFERENCE. */
        $permitted = $this->staffIn($this->merchant, $this->store);
        $this->grantPermission(User::find($permitted->id), 'Delete Deliveryboy');
        $this->as(User::find($permitted->id))->postJson('/api/admin/delete-user', ['user_id' => $boy]);
        $this->assertSame(0, DB::table('delivery_boys')->where('id', $boy)->count(),
            'staff GRANTED `Delete Deliveryboy` could not delete, so the gate admits nobody');

        $foreign = $this->seedDeliveryBoy($this->store);
        $outsider = $this->as($this->outsider)->postJson('/api/admin/delete-user', ['user_id' => $foreign]);
        $this->assertSame(0, $this->envelope($outsider), 'another tenant deleted this store\'s driver');
        $this->assertSame(1, DB::table('delivery_boys')->where('id', $foreign)->count());
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║ 22. delivery-login — an AUTHENTICATION door for DRIVERS                                      ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * `delivery-login` carries `AdminApiLog` and `throttle:auth` and no `custom.auth`: like
     * `adminlogin`, it is where a token comes from, so there is no permission axis. GATED BY
     * CREDENTIALS, and what is asserted is that a wrong password is refused and that the token issued
     * belongs to the driver who authenticated.
     *
     * THE MERCHANT-SIDE PRINCIPALS CANNOT BE PROBED HERE — a staff member has no driver password, so
     * "unpermissioned staff drives this route" is not a meaningful question. It becomes one the moment
     * `change-profile` is used to move a driver's address (see that case): the two routes compose.
     */
    public function test_GATED_BY_CREDENTIALS_delivery_login(): void
    {
        $boy = $this->seedDeliveryBoy($this->store);
        $email = (string) DB::table('delivery_boys')->where('id', $boy)->value('email');

        $good = $this->anonymously()->postJson('/api/admin/delivery-login', [
            'email' => $email, 'password' => self::DRIVER_PASSWORD,
        ]);

        $this->assertSame(1, $this->envelope($good),
            'a driver could not sign in. If this says "Invalid login details" while the row exists, '
            . 'the memoised guard of an earlier request has resolved a tenant and StoreScope is '
            . 'filtering delivery_boys out from under EloquentUserProvider — see trap (B). Body: '
            . $this->body($good));
        $this->assertSame($boy, (int) $good->json('data.id'));

        $token = (string) $good->json('data.token');
        $row = DB::table('personal_access_tokens')
            ->where('token', hash('sha256', explode('|', $token, 2)[1]))->first();
        $this->assertSame(\App\Models\DeliveryBoy::class, (string) $row->tokenable_type,
            'delivery-login issued a token for something other than a DeliveryBoy');
        $this->assertSame($boy, (int) $row->tokenable_id);

        $bad = $this->anonymously()->postJson('/api/admin/delivery-login', [
            'email' => $email, 'password' => 'not-the-password',
        ]);
        $this->assertSame(0, $this->envelope($bad), 'delivery-login accepted a wrong password');
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║ 23. deliveryboyStore   24. deliveryboysReset-password — GATED                                ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    /** GATED by `refuseWithoutDeliveryPermission('Create DeliveryBoy', …)` → 403. */
    public function test_GATED_deliveryboyStore(): void
    {
        $payload = [
            'store_id' => $this->store->id,
            'name' => 'CENSUS-NEW-DRIVER',
            'email' => 'census-new-driver@example.test',
            'password' => 'census-secret',
            'commission_percentage' => 25,
            'is_commission_enabled' => 1,
        ];

        $response = $this->as($this->staff)->postJson('/api/admin/deliveryboyStore', $payload);

        $this->assertSame(403, $response->status(), 'deliveryboyStore admitted unpermissioned staff: ' . $this->body($response));
        $this->assertSame(0, DB::table('delivery_boys')->where('email', $payload['email'])->count(),
            'the refused call still created a driver account with a commission percentage');

        $owner = $this->as($this->merchant)->postJson('/api/admin/deliveryboyStore', $payload);
        $this->assertSame(201, $owner->status(), 'the owner was refused: ' . $this->body($owner));
        $this->assertSame(1, DB::table('delivery_boys')->where('email', $payload['email'])->count());

        $payload['email'] = 'census-outsider-driver@example.test';
        $outsider = $this->as($this->outsider)->postJson('/api/admin/deliveryboyStore', $payload);
        $this->assertSame(404, $outsider->status(), 'another tenant created a driver in this store');
        $this->assertSame(0, DB::table('delivery_boys')->where('email', $payload['email'])->count());
    }

    /** GATED by `refuseWithoutDeliveryPermission('Reset password Deliveryboy', …)` → 403. */
    public function test_GATED_deliveryboys_reset_password(): void
    {
        $boy = $this->seedDeliveryBoy($this->store);
        $originalHash = (string) DB::table('delivery_boys')->where('id', $boy)->value('password');

        $uri = '/api/admin/deliveryboysReset-password/' . $boy . '?store_id=' . $this->store->id;
        $payload = ['password' => 'census-new-pass', 'password_confirmation' => 'census-new-pass'];

        $response = $this->as($this->staff)->postJson($uri, $payload);

        $this->assertSame(403, $response->status(),
            'deliveryboysReset-password admitted unpermissioned staff — a CREDENTIAL write: ' . $this->body($response));
        $this->assertSame($originalHash, (string) DB::table('delivery_boys')->where('id', $boy)->value('password'),
            'THE REFUSED CALL STILL REWROTE THE DRIVER\'S PASSWORD HASH');

        $owner = $this->as($this->merchant)->postJson($uri, $payload);
        $this->assertSame(200, $owner->status(), 'the owner was refused: ' . $this->body($owner));
        $this->assertNotSame($originalHash, (string) DB::table('delivery_boys')->where('id', $boy)->value('password'),
            'the owner got a success envelope and the hash did not move');

        $outsider = $this->as($this->outsider)->postJson(
            '/api/admin/deliveryboysReset-password/' . $boy . '?store_id=' . $this->store->id, $payload
        );
        $this->assertSame(404, $outsider->status(), 'another tenant reset this store\'s driver password');
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║ 25. editprofile — UNGATED for a COLLEAGUE'S identity; the owner is out of reach              ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    private function editProfile(User $u, int $storeId, int $targetId, string $name, ?string $email = null): TestResponse
    {
        $payload = ['store_id' => $storeId, 'user_id' => $targetId, 'name' => $name];

        if ($email !== null) {
            $payload['email'] = $email;
        }

        return $this->as($u)->postJson('/api/admin/editprofile', $payload);
    }

    /**
     * MEASUREMENT AWAITING A FIX — `EditProfile` (`:4452`) resolves the target through
     * `resolveManageableUser()`, which admits the caller themselves, the platform operator, or any
     * user whose `created_by` equals the caller's merchant tenant root. That last clause is TENANCY,
     * not permission: a staff member may therefore rewrite the `name` and `email` of EVERY OTHER PANEL
     * USER their merchant created, holding nothing.
     *
     * `users.email` IS THE PANEL LOGIN IDENTIFIER (`adminlogin` authenticates on it and the web
     * password reset sends to it), so this is an identity write on a colleague's account, not a
     * cosmetic one.
     *
     * THE LIMIT OF THE FINDING, MEASURED RATHER THAN GUESSED: the OWNER is out of reach. The merchant's
     * `created_by` is the platform operator (1), not their own id, so the clause does not match and
     * the call is refused — asserted below, because an earlier draft of this census got this wrong.
     * It had no platform operator in the fixture, which made the merchant user 1 with `created_by = 1`,
     * so `created_by === tenant root` accidentally held and the census reported an owner takeover that
     * production cannot reproduce. The real reach is "every colleague", which is still a finding.
     *
     * The web twin gates user edits on `Edit User` (`UserController::update`).
     */
    public function test_editprofile_refuses_unpermissioned_staff_a_colleagues_login_email(): void
    {
        $original = DB::table('users')->where('id', $this->colleague->id)->first();

        $response = $this->editProfile(
            $this->staff, $this->store->id, (int) $this->colleague->id,
            'CENSUS-COLLEAGUE-HIJACKED', 'attacker-colleague@example.test'
        );

        /* CONVERTED 2026-11-25 (GAP-251 / P4-F120): this pin went RED when the gate landed and
         * named the route -- what a pin is for, not a regression. `assertNotSame(1, ...)` because
         * `storePermissionDenied()` answers `{"status":"error"}` while older refusals answer `0`;
         * the claim is "not admitted" and the ROW carries it. A grant leg follows so the refusal
         * cannot pass against a gate that admits nobody. */
        $this->assertNotSame(1, $this->envelope($response),
            'editprofile now refuses unpermissioned staff on a colleague — the fix this row was '
            . 'waiting for. Body: ' . $this->body($response));

        $after = DB::table('users')->where('id', $this->colleague->id)->first();
        $this->assertSame((string) $original->email, (string) $after->email,
            'UNPERMISSIONED STAFF REWROTE A COLLEAGUE\'S PANEL LOGIN EMAIL through api/admin/editprofile');
        $this->assertSame((string) $original->name, (string) $after->name);

        /* THE GRANT IS WHAT MAKES THE DIFFERENCE. */
        $permitted = $this->staffIn($this->merchant, $this->store);
        $this->grantPermission(User::find($permitted->id), 'Edit User');
        $this->editProfile(User::find($permitted->id), $this->store->id, $this->colleague->id, 'CENSUS-OK', 'granted-colleague@example.test');
        $this->assertSame('granted-colleague@example.test',
            (string) DB::table('users')->where('id', $this->colleague->id)->value('email'),
            'staff GRANTED `Edit User` could not edit a colleague, so the gate admits nobody');
        $this->assertSame((string) $original->password, (string) $after->password,
            'editprofile also rewrote the password hash, which would make this a direct credential write');

        /* THE OWNER IS OUT OF REACH — the boundary of the finding, asserted so it is not overstated. */
        $ownerBefore = (string) DB::table('users')->where('id', $this->merchant->id)->value('email');
        $atOwner = $this->editProfile(
            $this->staff, $this->store->id, (int) $this->merchant->id, 'CENSUS-OWNER-HIJACKED', 'attacker-owner@example.test'
        );
        $this->assertSame(0, $this->envelope($atOwner),
            'UNPERMISSIONED STAFF REACHED THE TENANT OWNER\'S OWN ROW. resolveManageableUser() matches '
            . 'on `target.created_by === merchantTenantRoot(caller)`, and the owner\'s created_by is '
            . 'the platform operator — so this passing means that clause has widened. Body: '
            . $this->body($atOwner));
        $this->assertSame($ownerBefore, (string) DB::table('users')->where('id', $this->merchant->id)->value('email'));

        /* Tenancy holds across merchants. */
        $outsiderColleague = $this->staffIn($this->outsider, $this->outsiderStore);
        $cross = $this->editProfile(
            $this->staff, $this->store->id, (int) $outsiderColleague->id, 'CENSUS-CROSS-TENANT'
        );
        $this->assertSame(0, $this->envelope($cross), 'a staff member edited another tenant\'s user');
        $this->assertNotSame('CENSUS-CROSS-TENANT',
            (string) DB::table('users')->where('id', $outsiderColleague->id)->value('name'));
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║ 27. generatecode — UNGATED, and it writes nothing                                            ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * `GenerateCode` (`:2296`) resolves the store and returns ten random characters. No permission
     * limb, no write, no read of anything. Recorded as UNGATED with no consequence; the assertion
     * below exists so that if the handler ever starts PERSISTING the code it generates (which is what
     * a coupon-code generator usually ends up doing) the missing gate is not inherited silently.
     */
    public function test_UNGATED_generatecode_returns_a_random_string_and_writes_nothing(): void
    {
        $couponsBefore = DB::table('coupons')->count();

        $response = $this->as($this->staff)->postJson('/api/admin/generatecode', ['store_id' => $this->store->id]);

        $this->assertSame(1, $this->envelope($response),
            'generatecode now refuses unpermissioned staff. Body: ' . $this->body($response));
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{10}$/', (string) $response->json('data'),
            'generatecode no longer answers a bare ten-character code — if it now persists one, this '
            . 'route needs the permission gate it has never had');
        $this->assertSame($couponsBefore, DB::table('coupons')->count(),
            'generatecode persisted something');

        $outsider = $this->as($this->outsider)->postJson('/api/admin/generatecode', ['store_id' => $this->store->id]);
        $this->assertSame(0, $this->envelope($outsider), 'another tenant was admitted');
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║ 28. logOut — UNGATED: revokes ANY of the tenant's drivers' tokens                            ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * MEASUREMENT AWAITING A FIX — `logout` (`:6424`, route `logOut`) takes `user_id` from request
     * input, resolves it with `resolveManageableDeliveryBoy()` (tenancy only) and deletes that
     * driver's tokens. An unpermissioned staff member can therefore sign any of the tenant's drivers
     * out of the Flutter app, repeatedly — a denial of service against the delivery fleet rather than
     * a data breach, and the handler does not even validate that `user_id` is present.
     */
    public function test_logOut_refuses_unpermissioned_staff_another_drivers_tokens(): void
    {
        $boy = $this->seedDeliveryBoy($this->store);
        \App\Models\DeliveryBoy::withoutGlobalScopes()->findOrFail($boy)->createToken('auth_token');

        $this->assertSame(1, DB::table('personal_access_tokens')
            ->where('tokenable_type', \App\Models\DeliveryBoy::class)->where('tokenable_id', $boy)->count(),
            'fixture: the driver holds no token, so nothing below measures a revocation');

        $response = $this->as($this->staff)->postJson('/api/admin/logOut', ['user_id' => $boy]);

        /* CONVERTED 2026-11-25 (GAP-251 / P4-F120). `assertNotSame(1, ...)` because
         * `storePermissionDenied()` answers `{"status":"error"}` while older refusals answer `0`. */
        $this->assertNotSame(1, $this->envelope($response),
            'logOut now refuses unpermissioned staff — the fix this row was waiting for. '
            . 'Body: ' . $this->body($response));
        $this->assertSame(1, DB::table('personal_access_tokens')
            ->where('tokenable_type', \App\Models\DeliveryBoy::class)->where('tokenable_id', $boy)->count(),
            'the success envelope is not matched by the deletion');

        $foreign = $this->seedDeliveryBoy($this->store);
        \App\Models\DeliveryBoy::withoutGlobalScopes()->findOrFail($foreign)->createToken('auth_token');

        $outsider = $this->as($this->outsider)->postJson('/api/admin/logOut', ['user_id' => $foreign]);
        $this->assertSame(0, $this->envelope($outsider), 'another tenant revoked this store\'s driver tokens');
        $this->assertSame(1, DB::table('personal_access_tokens')
            ->where('tokenable_type', \App\Models\DeliveryBoy::class)->where('tokenable_id', $foreign)->count());
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║ 30. notifications/mark-as-read — GATED to the DRIVER guard (401 for every merchant principal) ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * `DeliveryBoyNotificationController::markAsRead` reads `auth('deliveryboy')->user()`. A merchant
     * or staff token passes `custom.auth` into the `web` guard, so `auth('deliveryboy')` stays NULL and
     * every merchant-side principal is refused — the inverse of the trap CLAUDE.md records for
     * `auth('customers')` fallbacks, and the SAFE shape of it: there is no `$request->some_id` branch
     * to fall into.
     *
     * THE REFUSAL IS **HTTP 200**, AND THAT WAS MEASURED AGAINST A WRONG EXPECTATION. The handler
     * writes `$this->error(['message' => __('Unauthorized')], 401)` and reads like a 401 — but
     * `ApiResponser::error()`'s second argument is the MESSAGE and its third is the status, so the
     * integer `401` lands in `message` and the wire carries HTTP 200 with
     * `{"status":0,"message":401}`. The first draft of this case asserted 401 from reading the source
     * and failed; the same argument-order mistake is in `createStoreApi` (see that case), which is why
     * both are pinned on the ENVELOPE. Two consequences, in opposite directions: no client that reads
     * the HTTP code can tell this refusal from success, but equally the shipped Flutter app does NOT
     * force-clear its session here, which a real 401 would have caused.
     *
     * The driver's own leg is the positive control, and the second driver is the tenancy axis: the
     * `exists` rule is scoped by `delivery_boy_id`, so one driver cannot mark another's notification.
     */
    public function test_GATED_notifications_mark_as_read_is_driver_only(): void
    {
        $order = $this->makeOrder($this->store);
        $boy = $this->seedDeliveryBoy($this->store);
        $notification = $this->seedDriverNotification($boy, (int) $order->id);

        foreach (['staff' => $this->staff, 'owner' => $this->merchant, 'outsider' => $this->outsider] as $label => $principal) {
            $response = $this->as($principal)->postJson('/api/admin/notifications/mark-as-read', [
                'notification_id' => $notification,
            ]);

            $this->assertSame(0, $this->envelope($response),
                $label . ': markAsRead admitted a merchant-side (web-guard) token to a DRIVER inbox. '
                . 'Body: ' . $this->body($response));
            $this->assertSame(401, $response->json('message'),
                $label . ': the refusal is no longer the "Unauthorized" one with 401 misplaced into '
                . '`message`. If the HTTP status is now 401 the argument order has been corrected — '
                . 'note that a real 401 force-clears the shipped app\'s session. Body: ' . $this->body($response));
            $this->assertSame(0, (int) DB::table('delivery_boy_notifications')->where('id', $notification)->value('is_read'),
                $label . ': the refused call still marked the notification read');
        }

        /* The driver themselves — the positive control. */
        $driverToken = \App\Models\DeliveryBoy::withoutGlobalScopes()->findOrFail($boy)
            ->createToken('auth_token')->plainTextToken;

        $this->resetAuth();
        $own = $this->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $driverToken)
            ->postJson('/api/admin/notifications/mark-as-read', ['notification_id' => $notification]);

        $this->assertSame(1, $this->envelope($own), 'the driver could not read their own notification: ' . $this->body($own));
        $this->assertSame(1, (int) DB::table('delivery_boy_notifications')->where('id', $notification)->value('is_read'));

        /* Another driver in the same store cannot touch it. */
        $otherBoy = $this->seedDeliveryBoy($this->store);
        $otherNotification = $this->seedDriverNotification($boy, (int) $order->id);
        $otherToken = \App\Models\DeliveryBoy::withoutGlobalScopes()->findOrFail($otherBoy)
            ->createToken('auth_token')->plainTextToken;

        $this->resetAuth();
        $cross = $this->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $otherToken)
            ->postJson('/api/admin/notifications/mark-as-read', ['notification_id' => $otherNotification]);

        $this->assertSame(0, $this->envelope($cross),
            'one driver marked ANOTHER driver\'s notification read: ' . $this->body($cross));
        $this->assertSame(0, (int) DB::table('delivery_boy_notifications')->where('id', $otherNotification)->value('is_read'));
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║ 31. order-cancel — GATED                                                                     ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * GATED by `callerMayChangeOrderState($order)` → the assigned driver, the store owner, or
     * `Manage Order` → `orderPermissionDenied()` 403.
     *
     * THIS IS A MONEY DOOR, which is why the refusal is asserted against the ORDER ROW and not only
     * the status: cancelling sets `delivered_status = 2`, restores stock through
     * `restoreCancelledOrderStock()` and runs `OrderEntitlementReversal`, which reverses the
     * merchant's wallet credit.
     */
    public function test_GATED_order_cancel(): void
    {
        $order = $this->makeOrder($this->store);

        $response = $this->as($this->staff)->postJson('/api/admin/order-cancel', ['order_id' => $order->id]);

        $this->assertSame(403, $response->status(), 'order-cancel admitted unpermissioned staff: ' . $this->body($response));
        $this->assertRefused($response, 'order-cancel');
        $this->assertSame(0, (int) DB::table('orders')->where('id', $order->id)->value('delivered_status'),
            'the refused call still cancelled the order — which reverses the merchant\'s wallet credit '
            . 'and restores stock');

        $owner = $this->as($this->merchant)->postJson('/api/admin/order-cancel', ['order_id' => $order->id]);
        $this->assertSame(1, $this->envelope($owner), 'the owner was refused: ' . $this->body($owner));
        $this->assertSame(2, (int) DB::table('orders')->where('id', $order->id)->value('delivered_status'),
            'the owner got a success envelope and the order was not cancelled');

        $second = $this->makeOrder($this->store);
        $outsider = $this->as($this->outsider)->postJson('/api/admin/order-cancel', ['order_id' => $second->id]);
        $this->assertSame(0, $this->envelope($outsider), 'another tenant cancelled this store\'s order');
        $this->assertSame(0, (int) DB::table('orders')->where('id', $second->id)->value('delivered_status'));
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  NON-VACUITY: every GATED verdict is flipped by granting the name the handler asks for        ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * THE CENSUS'S OWN CONTROL, AND THE REASON IT CAN BE BELIEVED.
     *
     * Each `test_GATED_*` case above asserts that an unpermissioned staff member got a 403. On its own
     * that is weak evidence: a 403 can come from a gate, from a tenancy predicate, from a validator,
     * or from a handler that refuses everybody (`addvariant` really does). What distinguishes a gate
     * is that it is a function of the GRANT — so for every gated route, a staff member of the SAME
     * store, differing only in holding the name the handler names, must get a different answer.
     *
     * This is the discipline CLAUDE.md records as the project's own addition to
     * test-driven-development: after a fix, revert it and watch the test fail again, because the
     * failure mode that actually bites here is a test that passes for the wrong reason. A mutation
     * sweep over the handlers would be the direct form of that, and it is deliberately NOT done here
     * because this slice owns no file under `app/` — this test is the version of the same check that
     * can be made from inside the census.
     *
     * The names are each handler's own, read from the source and not invented: `Create Coupon`,
     * `Create Tax`, `Create Shipping Class`, `Manage Order` (twice — `assignOrder` and `orderCancel`),
     * `Create DeliveryBoy`, `Reset password Deliveryboy`, `Manage Setting`.
     *
     * SCOPE, STATED SO THE METHOD NAME STAYS TRUE. These are the eight gates this file inherited.
     * The five added on 2026-11-25 — `addcategory`, `addproduct`, `addproductbrand`,
     * `addproductlable`, `addreview` — carry this same control INSIDE their own `test_GATED_*`
     * case, because each needed the permitted leg to read a row back rather than only to flip an
     * envelope. So the property holds for every gated route in this file; it is measured in two
     * places rather than one, and a case that lost its permitted leg would fail there.
     */
    public function test_every_gated_route_is_opened_by_the_permission_its_handler_names(): void
    {
        $storeId = (int) $this->store->id;

        /* addcoupon */
        $coupon = $this->addCoupon($this->staffIn($this->merchant, $this->store, 'Create Coupon'), $storeId, 'CENSUS-GRANTED-COUPON');
        $this->assertSame(1, $this->envelope($coupon), 'addcoupon refused the holder of `Create Coupon`: ' . $this->body($coupon));
        $this->assertSame(1, DB::table('coupons')->where('coupon_name', 'CENSUS-GRANTED-COUPON')->count(),
            'addcoupon answered success to the holder of `Create Coupon` and no coupon landed');

        /* addtax */
        $tax = $this->addTax($this->staffIn($this->merchant, $this->store, 'Create Tax'), $storeId, 'CENSUS-GRANTED-TAX');
        $this->assertSame(1, $this->envelope($tax), 'addtax refused the holder of `Create Tax`: ' . $this->body($tax));
        $this->assertSame(1, DB::table('taxes')->where('name', 'CENSUS-GRANTED-TAX')->count());

        /* addshipping */
        $shipping = $this->addShipping($this->staffIn($this->merchant, $this->store, 'Create Shipping Class'), $storeId, 'CENSUS-GRANTED-SHIPPING');
        $this->assertSame(1, $this->envelope($shipping), 'addshipping refused the holder of `Create Shipping Class`: ' . $this->body($shipping));
        $this->assertSame(1, DB::table('shippings')->where('name', 'CENSUS-GRANTED-SHIPPING')->count());

        /* assignOrders */
        $order = $this->makeOrder($this->store);
        $boy = $this->seedDeliveryBoy($this->store);
        $assign = $this->as($this->staffIn($this->merchant, $this->store, 'Manage Order'))
            ->postJson('/api/admin/assignOrders?store_id=' . $storeId, ['order_id' => $order->id, 'delivery_boy' => $boy]);
        $this->assertSame(200, $assign->status(), 'assignOrders refused the holder of `Manage Order`: ' . $this->body($assign));
        $this->assertSame($boy, (int) DB::table('orders')->where('id', $order->id)->value('deliveryboy_id'),
            'assignOrders answered success to the holder of `Manage Order` and the assignment did not land');

        /* order-cancel */
        $cancellable = $this->makeOrder($this->store);
        $cancel = $this->as($this->staffIn($this->merchant, $this->store, 'Manage Order'))
            ->postJson('/api/admin/order-cancel', ['order_id' => $cancellable->id]);
        $this->assertSame(1, $this->envelope($cancel), 'order-cancel refused the holder of `Manage Order`: ' . $this->body($cancel));
        $this->assertSame(2, (int) DB::table('orders')->where('id', $cancellable->id)->value('delivered_status'));

        /* deliveryboyStore */
        $create = $this->as($this->staffIn($this->merchant, $this->store, 'Create DeliveryBoy'))
            ->postJson('/api/admin/deliveryboyStore', [
                'store_id' => $storeId, 'name' => 'CENSUS-GRANTED-DRIVER',
                'email' => 'census-granted-driver@example.test', 'password' => 'census-secret',
            ]);
        $this->assertSame(201, $create->status(), 'deliveryboyStore refused the holder of `Create DeliveryBoy`: ' . $this->body($create));
        $this->assertSame(1, DB::table('delivery_boys')->where('email', 'census-granted-driver@example.test')->count());

        /* deliveryboysReset-password */
        $target = $this->seedDeliveryBoy($this->store);
        $before = (string) DB::table('delivery_boys')->where('id', $target)->value('password');
        $reset = $this->as($this->staffIn($this->merchant, $this->store, 'Reset password Deliveryboy'))
            ->postJson('/api/admin/deliveryboysReset-password/' . $target . '?store_id=' . $storeId, [
                'password' => 'census-granted-pass', 'password_confirmation' => 'census-granted-pass',
            ]);
        $this->assertSame(200, $reset->status(), 'reset-password refused the holder of `Reset password Deliveryboy`: ' . $this->body($reset));
        $this->assertNotSame($before, (string) DB::table('delivery_boys')->where('id', $target)->value('password'),
            'reset-password answered success to the permitted holder and the hash did not move');

        /*
         * THE FOUR SETTINGS WRITERS. The grant must change the answer from the gate's 403 to the
         * MySQL-only statement's 500 — see trap (C): the row write itself cannot be driven here, but
         * "the gate let them through" can, and that is exactly what this census claims.
         */
        foreach (self::theSettingsWriters() as $case) {
            [$route, $payload] = $case;
            $payload['store_id'] = $storeId;

            $granted = $this->as($this->staffIn($this->merchant, $this->store, 'Manage Setting'))
                ->postJson('/api/admin/' . $route, $payload);

            $this->assertNotSame(403, $granted->status(),
                $route . ' refused the holder of `Manage Setting`: ' . $this->body($granted));
            $this->assertStringContainsString('DUPLICATE', (string) $granted->getContent(),
                $route . ': the holder of `Manage Setting` did not reach the persisting statement, so '
                . 'the 403 the census records for unpermissioned staff is not attributable to the '
                . 'permission gate. Body: ' . $this->body($granted));
        }
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  THE CENSUS IS COMPLETE — all 31 routes have a case                                          ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * EVERY ROUTE IN THE ASSIGNED SET IS COVERED BY A CASE ABOVE, and this test is what stops the
     * census from quietly shrinking. A census that silently drops a row is worse than no census: it
     * reports a smaller denominator with the same confidence.
     *
     * The list is the 31 `POST api/admin/*` routes of group 3. Each is matched to the test method that
     * drives it, and the method is required to EXIST on this class — so deleting or renaming a case
     * fails here with the route it abandoned.
     */
    public function test_every_one_of_the_thirty_one_routes_has_a_case(): void
    {
        $census = [
            'addcategory' => 'test_GATED_addcategory',
            'addcoupon' => 'test_GATED_addcoupon',
            'addproduct' => 'test_GATED_addproduct',
            'addproductbrand' => 'test_GATED_addproductbrand',
            'addproductlable' => 'test_GATED_addproductlable',
            'addreview' => 'test_GATED_addreview',
            'addshipping' => 'test_GATED_addshipping',
            'addtax' => 'test_GATED_addtax',
            'addvariant' => 'test_addvariant_is_inert_and_refuses_every_principal',
            'adminlogin' => 'test_GATED_BY_CREDENTIALS_adminlogin',
            'adminlogout' => 'test_GATED_adminlogout_deletes_only_the_callers_own_token',
            'assignOrders' => 'test_GATED_assignOrders',
            'bank-payment' => 'test_GATED_settings_writers',
            'change-profile' => 'test_change_profile_refuses_unpermissioned_staff_a_drivers_login_email',
            'changestore' => 'test_UNGATED_changestore_writes_only_the_callers_own_current_store',
            'cod-payment' => 'test_GATED_settings_writers',
            'createOrderViaApi' => 'test_GATED_createOrderViaApi_but_the_gate_also_refuses_the_bare_owner',
            'createStoreApi' => 'test_GATED_createStoreApi',
            'currency' => 'test_UNGATED_currency_is_a_self_read',
            'dashboard' => 'test_GATED_dashboard_withholds_every_business_figure_from_unpermissioned_staff',
            'delete-user' => 'test_delete_user_refuses_unpermissioned_staff_a_delivery_boy',
            'delivery-login' => 'test_GATED_BY_CREDENTIALS_delivery_login',
            'deliveryboyStore' => 'test_GATED_deliveryboyStore',
            'deliveryboysReset-password/{id}' => 'test_GATED_deliveryboys_reset_password',
            'editprofile' => 'test_editprofile_refuses_unpermissioned_staff_a_colleagues_login_email',
            'emailsetting' => 'test_GATED_settings_writers',
            'generatecode' => 'test_UNGATED_generatecode_returns_a_random_string_and_writes_nothing',
            'logOut' => 'test_logOut_refuses_unpermissioned_staff_another_drivers_tokens',
            'loyalitysetting' => 'test_GATED_settings_writers',
            'notifications/mark-as-read' => 'test_GATED_notifications_mark_as_read_is_driver_only',
            'order-cancel' => 'test_GATED_order_cancel',
        ];

        $this->assertCount(31, $census, 'the census no longer covers 31 routes');

        foreach ($census as $route => $method) {
            $this->assertTrue(method_exists($this, $method),
                'api/admin/' . $route . ' has lost its census case (' . $method . '())');
        }

        /* AND EVERY ROUTE IS REALLY THERE, in the `admin` prefix, taking POST. A census of routes that
           no longer exist is a census of nothing — and `routes/api.php` lets a later declaration
           silently replace an earlier one for the same method+URI. */
        $registered = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('POST', $route->methods(), true))
            ->map(fn ($route) => ltrim((string) $route->uri(), '/'))
            ->all();

        foreach (array_keys($census) as $route) {
            $this->assertContains('api/admin/' . $route, $registered,
                'api/admin/' . $route . ' is no longer a registered POST route, so its census case is '
                . 'measuring something else');
        }
    }
}
