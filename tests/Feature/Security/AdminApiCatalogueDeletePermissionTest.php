<?php

namespace Tests\Feature\Security;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\StripsSourceComments;

/**
 * THE WITHIN-TENANT PERMISSION AXIS ON THE NINE DOORS THE BEHAVIOURAL CENSUS PINNED AS UNGATED.
 * =================================================================================================
 *
 * WHAT THIS CLOSES, AND WHY IT IS THE SHARPEST ASYMMETRY LEFT ON THIS SURFACE. The change that
 * gated twenty-two `api/admin/*` doors (`AdminApiWithinTenantPermissionTest`) could not touch
 * these: every one of them was pinned UNGATED by `AdminApiRouteBehaviouralCensus1Test`, which was
 * outside that change's file set, so gating them would have left a red pin. The result was that
 * ADD and UPDATE of every catalogue resource were gated while the irreversible DELETE of each was
 * not -- a staff member of the owning store holding no permission at all destroyed a product (with
 * its images on disk), a category, a brand, a label or a public review. There is no soft delete and
 * no audit row on any of those paths. The census pins are inverted in the same change as these
 * gates, which is what this project's process requires.
 *
 * -------------------------------------------------------------------------------------------------
 * THREE LEGS PER DOOR, AND THE SECOND IS WHAT MAKES THE FIRST MEAN ANYTHING.
 *
 *   1. UNPERMISSIONED STAFF OF THE OWNING STORE -- refused with an explicit 403, and the subject of
 *      the door is read back through `DB::table()` to prove the row survived or the field was not
 *      served.
 *   2. A PERMITTED COLLEAGUE -- the same request, the same fixture, the same tenant, differing ONLY
 *      in holding the name the gate asks for. Admitted, AND the delete lands or the withheld field
 *      appears. Without this leg every refusal above is equally satisfied by a gate that admits
 *      nobody, by a 404, by a validator above the gate, or by a fixture that refuses everybody --
 *      which is the mistake caught in the sibling censuses.
 *   3. THE STORE'S OWN OWNER -- admitted. A merchant holds no permission implicitly, so a
 *      permission-only gate locks the owner out of their own store; `callerMayActInStore()` carries
 *      an owner limb for exactly that reason and this family has produced that regression twice.
 *
 * AND A FOURTH LEG THAT MEASURES THE GATE'S POSITION rather than its existence: a merchant of
 * ANOTHER tenant must keep the refusal they already got -- the tenancy refusal, in its own words
 * and its own envelope -- which is only true while the gate sits BELOW the tenancy control. A gate
 * placed above it would answer a foreign caller "you lack a permission", which both leaks that the
 * store exists and changes an answer a shipped client already handles.
 *
 * -------------------------------------------------------------------------------------------------
 * THE TRAPS THIS FILE HAD TO AVOID.
 *
 * (a) `ApiResponser::error()` ANSWERS HTTP 200 with `{status: 0}` and its THIRD argument is the
 *     status code, so a 200 is not evidence of admission and a refusal written through it is
 *     invisible to a status-code assertion. Admission is read from the ENVELOPE here, and every
 *     gate added answers an explicit 403.
 *
 * (b) NEVER 401. The shipped Flutter admin app force-clears its session on any 401
 *     (`lib/network/network.dart`), so a 401 on a path a client calls signs the merchant out of the
 *     whole app instead of telling them they lack a right. Asserted on every refusal.
 *
 * (c) THE GUARDS AND THE SESSION OUTLIVE A REQUEST. `custom.auth` ends by logging the token's owner
 *     in, and that principal resolves a tenant for the NEXT request in the same test method.
 *     `resetAuth()` runs before every leg.
 *
 * (d) EVERY ROW IS READ THROUGH `DB::table()`, which is the only read that is a statement about the
 *     table. 79 of 95 models are `Cachable`, so an Eloquent read can serve the pre-write row for
 *     the rest of the test under `CACHE_STORE=array`, and eleven models really filter by tenant, so
 *     a `Model::find()` answers against whichever tenant happened to resolve.
 *
 * (e) A FIXTURE CAN MANUFACTURE THE REFUSAL. `makeMerchant()` writes `created_by => 1`; with no
 *     operator row the first merchant IS user 1 and becomes their own tenant root by accident.
 *     `setUp()` creates the operator first and asserts they hold id 1, and the premise tests assert
 *     that the staff principal is really inside the tenant and really holds nothing -- so a refusal
 *     below is attributable to the permission and not to tenancy.
 *
 * (f) `DeleteCategory` REFUSES A CATEGORY THAT STILL HOLDS PRODUCTS OR CHILDREN (owner decision
 *     D13, the `products.category_id` cascade). The delete target is therefore an EMPTY category,
 *     or every leg would be measuring that integrity guard instead of the permission axis.
 *
 * -------------------------------------------------------------------------------------------------
 * ONE OF THE NINE IS DELIBERATELY NOT REFUSED, AND THAT IS A FINDING RATHER THAN AN OMISSION.
 *
 * `get-currency-rates` is a GET that SEEDS THIRTEEN ROWS into a money table when the store holds
 * none. Its WRITE side is gated here; its READ is not, and the reason is measured rather than
 * assumed: the web twin of that read, `CurrencyRateController::index()` (`routes/web.php:201`),
 * carries `auth` and NO permission at all, so refusing the read would make the API narrower than
 * the screen a merchant's staff can already open, on a path the shipped app calls uncommented
 * (`flutter-endpoints.json`, `getCurrencyRatesUrl`). Withdrawing a live capability is a separate
 * product decision that must not ride on a security fix. The payload also discloses nothing the
 * storefront does not already show a shopper (rate, symbol, store name). What WAS open is the
 * write, and only the write is closed -- `test_get_currency_rates_*` drives both halves.
 */
class AdminApiCatalogueDeletePermissionTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    /** Distinctive enough that "it was served" / "it was not served" cannot pass by accident. */
    private const MARK = 'CATDELETE-MARK-7171';

    /** The bank detail `get-bank-payment` serves; a literal, so the disclosure is provable. */
    private const BANK_DETAIL = 'IBAN SY00 7171 0001 ACCOUNT HOLDER';

    /** The COD instruction `get-cod-payment` serves. */
    private const COD_DETAIL = 'COD-INSTRUCTION-7171 pay the driver';

    private User $operator;

    private User $merchant;

    private Store $store;

    /** A staff member of $store holding nothing at all. */
    private User $staff;

    /** A merchant of a different tenant, and their store. */
    private User $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        // Trap (e). The operator must be user 1 for `makeMerchant()`'s `created_by => 1` to mean
        // "rooted at the platform" rather than "its own tenant root".
        $this->operator = $this->makePlatformAdmin();
        $this->assertSame(1, (int) User::orderBy('id')->value('id'),
            'premise: the platform operator must hold id 1');

        [$merchant, $store] = $this->makeTenant();
        $this->merchant = $merchant;
        $this->store = $store;

        $this->staff = $this->staffIn($store);

        $foreign = $this->makeMerchant();
        $foreignStore = $this->makeStore($foreign);
        $foreign->current_store = $foreignStore->id;
        $foreign->save();
        $this->foreign = User::find($foreign->id);

        // Several handlers read the platform admin through `Cache::remember('admin_details', ...)`
        // and would otherwise serve the pre-fixture (absent) answer for the rest of the test.
        Cache::forget('admin_details');
    }

    // ═════════════════════════════════════════════════════════════════════════════════ principals

    private function staffIn(Store $store, ?string $permission = null): User
    {
        $staff = $this->makeStaff($this->merchant);
        $staff->current_store = $store->id;
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

    private function operatorInStore(): User
    {
        $operator = User::find($this->operator->id);
        $operator->current_store = $this->store->id;
        $operator->save();

        return User::find($operator->id);
    }

    // ═══════════════════════════════════════════════════════════════════════════════════ fixtures

    private function categoryId(array $overrides = []): int
    {
        $id = (int) DB::table('categories')->insertGetId(array_merge([
            'name' => self::MARK,
            'slug' => 'catdelete-cat-' . $this->uniqueFixtureRef(),
            'parent_id' => 0,
            'trending' => 0,
            'status' => 1,
            'store_id' => $this->store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        (new \App\Models\Category)->flushCache();

        return $id;
    }

    private function productId(array $overrides = []): int
    {
        $id = (int) DB::table('products')->insertGetId(array_merge([
            'name' => 'catdelete-product',
            'slug' => 'catdelete-product-' . $this->uniqueFixtureRef(),
            'category_id' => $this->categoryId(),
            'description' => 'fixture',
            'track_stock' => 0,
            'price' => 100,
            'product_stock' => 50,
            'stock_status' => 'in_stock',
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
            'name' => 'catdelete-brand-' . $this->uniqueFixtureRef(),
            'slug' => 'catdelete-brand-' . $this->uniqueFixtureRef(),
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
            'name' => 'catdelete-label-' . $this->uniqueFixtureRef(),
            'slug' => 'catdelete-label-' . $this->uniqueFixtureRef(),
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

    /**
     * The `settings` rows the two payment readers serve.
     *
     * Raw, not `Setting::create()`: the model's `saving` hook runs `HostClaims` and
     * `StoredPathPolicy`, neither of which is what this file measures. Without these rows both
     * handlers answer "Setting Data not found!", which is indistinguishable from a refusal in the
     * envelope -- the fixture, not the gate, would be withholding the data.
     */
    private function seedPaymentSettings(): void
    {
        foreach ([
            'CURRENCY' => 'USD',
            'CURRENCY_NAME' => 'Dollar',
            'is_cod_enabled' => 'on',
            'cod_info' => self::COD_DETAIL,
            'cod_image' => 'cod.png',
            'is_bank_transfer_enabled' => 'on',
            'bank_transfer' => self::BANK_DETAIL,
            'bank_transfer_image' => 'slip.png',
        ] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name,
                'value' => $value,
                'store_id' => $this->store->id,
                'created_by' => $this->merchant->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        (new \App\Models\Setting)->flushCache();
    }

    // ══════════════════════════════════════════════════════════════════════════ driving the wire

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

    private function as(User $user): self
    {
        $this->resetAuth();

        return $this->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $user->createToken('catalogue_delete')->plainTextToken);
    }

    /** The body, whitespace collapsed and TRUNCATED -- for failure messages only. */
    private function body(TestResponse $response): string
    {
        return substr($this->raw($response), 0, 400);
    }

    /**
     * The WHOLE body, for an assertion about what was or was not served.
     *
     * `body()` truncates, so an `assertStringContainsString` against it would start failing the day
     * a payload grew past 400 bytes before the needle -- a fragile pass, and in the ABSENCE
     * direction a silently vacuous one. Whitespace is collapsed rather than stripped, and
     * `strip_tags()` is not used: it reads a bare `<` as a tag opening and has silently inverted an
     * absence assertion in this repository before.
     */
    private function raw(TestResponse $response): string
    {
        return (string) preg_replace('/\s+/', ' ', (string) $response->getContent());
    }

    /**
     * Admitted, decided from the ENVELOPE and never from the status code alone -- trap (a). The
     * success flag is spelled three ways across these handlers.
     */
    private function admitted(TestResponse $response): bool
    {
        if ($response->status() < 200 || $response->status() >= 300) {
            return false;
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return false;
        }

        return in_array($payload['status'] ?? null, [1, '1', true, 'success'], true);
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

    /**
     * THE GATE IS BELOW THE TENANCY CONTROL, measured by the answer a FOREIGN tenant gets.
     *
     * A foreign caller must keep the refusal they already had, in the tenancy control's own words.
     * The permission gate says "perform this action"; every tenancy refusal on these doors says
     * something else -- "Store not found!", "access this store", "Review not found." -- so the
     * message separates the two readings where the status code cannot (`categorylist` and
     * `get-currency-rates` answer 403 for tenancy as well).
     */
    private function assertRefusedByTheTenancyControl(
        TestResponse $response,
        string $door,
        string $expectedFragment
    ): void {
        $this->assertFalse($this->admitted($response),
            $door . ': a merchant of another tenant was ADMITTED. Body: ' . $this->body($response));

        $this->assertNotSame(401, $response->status(),
            $door . ': the tenancy refusal became a 401. Body: ' . $this->body($response));

        $this->assertStringContainsString($expectedFragment, $this->raw($response),
            $door . ': a merchant of another tenant no longer gets the TENANCY refusal, which '
            . 'means the permission gate was placed ABOVE the tenancy control. Body: '
            . $this->body($response));

        $this->assertStringNotContainsString('perform this action', $this->raw($response),
            $door . ': a foreign tenant is now told they lack a permission. The gate must sit '
            . 'BELOW the tenancy control. Body: ' . $this->body($response));
    }

    private function value(string $table, int $id, string $column)
    {
        return DB::table($table)->where('id', $id)->value($column);
    }

    private function exists(string $table, int $id): bool
    {
        return DB::table($table)->where('id', $id)->exists();
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
            'the staff principal is not in this store');

        $this->assertFalse(\App\Policies\UserPolicy::isOperator($this->staff),
            'the staff principal is the platform operator, whom MerchantPermissions wildcards');
    }

    /**
     * AND THE TENANCY PREDICATE REALLY DOES ADMIT THEM, which is the whole reason this axis
     * existed. Driven on a door this change does NOT gate -- `productdropdown` carries the same
     * tenancy control and no permission limb -- so it measures the predicate rather than a gate.
     */
    public function test_premise_the_tenancy_predicate_admits_this_staff_member(): void
    {
        $this->categoryId();

        $response = $this->as($this->staff)
            ->getJson('/api/admin/productdropdown?store_id=' . $this->store->id);

        $this->assertTrue($this->admitted($response),
            'the tenancy predicate now refuses an unpermissioned staff member of the owning '
            . 'store, so the refusals below are no longer attributable to the permission gates. '
            . 'Body: ' . $this->body($response));
    }

    /**
     * AND EVERY PERMISSION NAME THESE GATES ASK FOR HAS A ROW A MERCHANT CAN GRANT.
     *
     * A permission no row exists for can be held by no role, so gating on it is a permanent 403 --
     * this project has done that once already, to six finance names. Each name below is read from
     * the web twin named beside it and is seeded by `Utility::addNewData()`: in `$arrPermissions`
     * (which creates the row) AND in `$companyNewPermission` (which grants it to the global `admin`
     * role every self-registered merchant holds, so no production merchant loses a door).
     *
     * COMMENTS ARE STRIPPED FIRST, and that is not tidiness. `Utility.php` carries a long prose
     * block about six finance names that were gated and never seeded, so a census that read its
     * comments could be satisfied by a sentence ABOUT a permission instead of the array entry that
     * creates it -- the failure mode this repository has produced five times. `grantPermission()`
     * cannot stand in for this check either: it is `Permission::firstOrCreate`, so it mints the row
     * the gate tests need whether or not production would have one.
     */
    public function test_premise_every_name_these_gates_ask_for_is_seeded_by_the_host(): void
    {
        $source = $this->sourceWithoutComments('app/Models/Utility.php');

        foreach ([
            'Delete Products',          // ProductController::destroy()
            'Delete Product Category',  // CategoryController::destroy()
            'Delete Product Brand',     // ProductBrandController::destroy()
            'Delete Product Label',     // ProductLabelController::destroy()
            'Delete Testimonial',       // TestimonialController::destroy()
            'Manage Product Category',  // CategoryController::index()
            'Manage Setting',           // StoreSettingsAccess::PERMISSION
        ] as $name) {
            $this->assertGreaterThanOrEqual(2, substr_count($source, '"' . $name . '"') + substr_count($source, "'" . $name . "'"),
                'the permission name `' . $name . '` is not seeded twice by Utility::addNewData() '
                . '(once to create the row, once to grant it to the global `admin` role), so '
                . 'gating on it is a permanent 403 for somebody');
        }
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  THE FIVE IRREVERSIBLE DELETES                                                           ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * `deleteproduct` -> `Delete Products`, the name `ProductController::destroy()` uses
     * (`ProductController.php:3066`). The handler destroys the row, its `product_images`, every
     * `carts` and `wishlists` line referencing it, and its image FILES on disk. Irreversible.
     *
     * NO OPERATOR LEG HERE, deliberately: `OperatorCatalogueWrite::refuses()` withdraws this door
     * from the platform operator ABOVE the new gate (F206, an owner decision), so the operator's
     * answer is that decision's and not this gate's. `test_the_platform_operator_keeps_its_own
     * _answer_on_deleteproduct` pins that the ordering did not change.
     */
    public function test_deleteproduct_is_gated_on_delete_products(): void
    {
        $call = fn (User $u, int $product) => $this->as($u)
            ->deleteJson('/api/admin/deleteproduct', [
                'store_id' => $this->store->id,
                'product_id' => $product,
            ]);

        $survivor = $this->productId();
        $this->assertRefusedByThePermissionGate($call($this->staff, $survivor), 'deleteproduct');
        $this->assertTrue($this->exists('products', $survivor),
            'a staff member holding nothing DESTROYED a product');

        $permitted = $this->productId();
        $this->assertAdmitted($call($this->staffWith('Delete Products'), $permitted),
            'staff granted `Delete Products`');
        $this->assertFalse($this->exists('products', $permitted),
            'the permitted caller was admitted and the product survived, so this door cannot '
            . 'delete a product at all and the refusal above measures nothing');

        $owned = $this->productId();
        $this->assertAdmitted($call($this->merchant, $owned), 'the store owner');
        $this->assertFalse($this->exists('products', $owned));

        $foreign = $this->productId();
        $this->assertRefusedByTheTenancyControl(
            $call($this->foreign, $foreign), 'deleteproduct', 'Store not found!'
        );
        $this->assertTrue($this->exists('products', $foreign));
    }

    /**
     * `deletecategory` -> `Delete Product Category` (`CategoryController::destroy():422`).
     *
     * Trap (f): the target is an EMPTY category, because the handler refuses one holding products
     * or children (owner decision D13) and those refusals are not the permission axis.
     */
    public function test_deletecategory_is_gated_on_delete_product_category(): void
    {
        $call = fn (User $u, int $category) => $this->as($u)
            ->deleteJson('/api/admin/deletecategory', [
                'store_id' => $this->store->id,
                'category_id' => $category,
            ]);

        $survivor = $this->categoryId();
        $this->assertRefusedByThePermissionGate($call($this->staff, $survivor), 'deletecategory');
        $this->assertTrue($this->exists('categories', $survivor),
            'a staff member holding nothing DESTROYED a category');

        $permitted = $this->categoryId();
        $this->assertAdmitted($call($this->staffWith('Delete Product Category'), $permitted),
            'staff granted `Delete Product Category`');
        $this->assertFalse($this->exists('categories', $permitted),
            'the permitted caller was admitted and the category survived, so the refusal above '
            . 'measures nothing');

        $owned = $this->categoryId();
        $this->assertAdmitted($call($this->merchant, $owned), 'the store owner');
        $this->assertFalse($this->exists('categories', $owned));

        $foreign = $this->categoryId();
        $this->assertRefusedByTheTenancyControl(
            $call($this->foreign, $foreign), 'deletecategory', 'Store not found!'
        );
        $this->assertTrue($this->exists('categories', $foreign));
    }

    /** `deleteproductbrand` -> `Delete Product Brand` (`ProductBrandController::destroy():284`). */
    public function test_deleteproductbrand_is_gated_on_delete_product_brand(): void
    {
        $call = fn (User $u, int $brand) => $this->as($u)
            ->deleteJson('/api/admin/deleteproductbrand', [
                'store_id' => $this->store->id,
                'productbrand_id' => $brand,
            ]);

        $survivor = $this->brandId();
        $this->assertRefusedByThePermissionGate($call($this->staff, $survivor), 'deleteproductbrand');
        $this->assertTrue($this->exists('product_brands', $survivor),
            'a staff member holding nothing DESTROYED a brand');

        $permitted = $this->brandId();
        $this->assertAdmitted($call($this->staffWith('Delete Product Brand'), $permitted),
            'staff granted `Delete Product Brand`');
        $this->assertFalse($this->exists('product_brands', $permitted),
            'the permitted caller was admitted and the brand survived, so the refusal above '
            . 'measures nothing');

        $owned = $this->brandId();
        $this->assertAdmitted($call($this->merchant, $owned), 'the store owner');
        $this->assertFalse($this->exists('product_brands', $owned));

        $foreign = $this->brandId();
        $this->assertRefusedByTheTenancyControl(
            $call($this->foreign, $foreign), 'deleteproductbrand', 'Store not found!'
        );
        $this->assertTrue($this->exists('product_brands', $foreign));
    }

    /** `deleteproductlable` -> `Delete Product Label` (`ProductLabelController::destroy():187`). */
    public function test_deleteproductlable_is_gated_on_delete_product_label(): void
    {
        $call = fn (User $u, int $label) => $this->as($u)
            ->deleteJson('/api/admin/deleteproductlable', [
                'store_id' => $this->store->id,
                'lable_id' => $label,
            ]);

        $survivor = $this->labelId();
        $this->assertRefusedByThePermissionGate($call($this->staff, $survivor), 'deleteproductlable');
        $this->assertTrue($this->exists('product_labels', $survivor),
            'a staff member holding nothing DESTROYED a product label');

        $permitted = $this->labelId();
        $this->assertAdmitted($call($this->staffWith('Delete Product Label'), $permitted),
            'staff granted `Delete Product Label`');
        $this->assertFalse($this->exists('product_labels', $permitted),
            'the permitted caller was admitted and the label survived, so the refusal above '
            . 'measures nothing');

        $owned = $this->labelId();
        $this->assertAdmitted($call($this->merchant, $owned), 'the store owner');
        $this->assertFalse($this->exists('product_labels', $owned));

        $foreign = $this->labelId();
        $this->assertRefusedByTheTenancyControl(
            $call($this->foreign, $foreign), 'deleteproductlable', 'Store not found!'
        );
        $this->assertTrue($this->exists('product_labels', $foreign));
    }

    /**
     * `deletereview/{id}` -> `Delete Testimonial` (`TestimonialController::destroy():289`).
     *
     * THE STORE COMES FROM THE ROW AND NOT FROM REQUEST INPUT, and that is forced by the shipped
     * app, which sends no `store_id` and no body at all. GAP-242 put the tenancy predicate here by
     * loading the review and asking `resolveOwnedStore($review->store_id)`; the permission gate
     * therefore evaluates the grant against THAT store, which is the store whose public review is
     * about to be destroyed.
     *
     * The docblock on the handler used to record that NO permission gate was added because neither
     * sibling had one. That reason has expired: `UpdateReview` is now gated on `Edit Testimonial`
     * and `AddReview` on `Create Testimonial`, so the deleter was the only review door without a
     * permission axis.
     */
    public function test_deletereview_is_gated_on_delete_testimonial(): void
    {
        $call = fn (User $u, int $review) => $this->as($u)
            ->deleteJson('/api/admin/deletereview/' . $review, []);

        $survivor = $this->reviewId();
        $this->assertRefusedByThePermissionGate($call($this->staff, $survivor), 'deletereview');
        $this->assertTrue($this->exists('testimonials', $survivor),
            'a staff member holding nothing DESTROYED a public review');
        $this->assertSame(self::MARK, (string) $this->value('testimonials', $survivor, 'title'));

        $permitted = $this->reviewId();
        $this->assertAdmitted($call($this->staffWith('Delete Testimonial'), $permitted),
            'staff granted `Delete Testimonial`');
        $this->assertFalse($this->exists('testimonials', $permitted),
            'the permitted caller was admitted and the review survived, so the refusal above '
            . 'measures nothing');

        $owned = $this->reviewId();
        $this->assertAdmitted($call($this->merchant, $owned), 'the store owner');
        $this->assertFalse($this->exists('testimonials', $owned));

        /* The tenancy refusal on this door is `Review not found.` -- deliberately the same body as
           "no such review", so the endpoint is not an existence oracle across tenants. */
        $foreign = $this->reviewId();
        $this->assertRefusedByTheTenancyControl(
            $call($this->foreign, $foreign), 'deletereview', 'Review not found.'
        );
        $this->assertTrue($this->exists('testimonials', $foreign));
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  THE THREE READS                                                                         ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * `categorylist` -> `Manage Product Category`, the name `CategoryController::index():26` uses
     * for the same screen.
     *
     * THIS WAS A GAP IN ONE OF SEVEN LIST HANDLERS AND NOT A DESIGN THE SURFACE TOOK: `ProductList`
     * is gated on `Manage Products`, `CouponList` on `Manage Coupon`, `ShippingList`,
     * `TaxList`, `VariantList` and `ReviewList` all carry a name. Only the category list did not.
     *
     * `callerMayActInStore()` and NOT the `refuseWithoutPermission()` the other list handlers use,
     * because that helper has no owner limb -- which is why the census records `couponlist` and
     * `Top_orders` refusing the store's own BARE owner. Reproducing that asymmetry on a new door
     * would be shipping a known defect on purpose.
     */
    public function test_categorylist_is_gated_on_manage_product_category(): void
    {
        $this->categoryId();

        $call = fn (User $u) => $this->as($u)
            ->getJson('/api/admin/categorylist?store_id=' . $this->store->id);

        $refused = $call($this->staff);
        $this->assertRefusedByThePermissionGate($refused, 'categorylist');
        $this->assertStringNotContainsString(self::MARK, $this->raw($refused),
            'the category tree was served to a staff member holding nothing');

        $permitted = $call($this->staffWith('Manage Product Category'));
        $this->assertAdmitted($permitted, 'staff granted `Manage Product Category`');
        $this->assertStringContainsString(self::MARK, $this->raw($permitted),
            'the permitted caller was admitted and the category list came back WITHOUT the '
            . 'category, so the absence asserted above is the fixture and not the gate');

        $owner = $call($this->merchant);
        $this->assertAdmitted($owner, 'the store owner');
        $this->assertStringContainsString(self::MARK, $this->raw($owner));

        $this->assertRefusedByTheTenancyControl(
            $call($this->foreign), 'categorylist', 'access this store'
        );
    }

    /**
     * `get-bank-payment` -> `refuseUnlessMayAdministerStoreSettings()`, which is operator, tenant
     * owner, or `Manage Setting`.
     *
     * IT SERVES THE STORE'S BANK DETAILS: `bank_transfer` is the free-text account / IBAN block a
     * shopper is told to pay into, plus the uploaded transfer-slip path. Its WRITE twin,
     * `BankPayment`, already uses this exact predicate, and so does the READ twin one resource
     * over, `GetStripePayment` -- so on this one resource a staff member could READ the payment
     * configuration they may not change, while the Stripe equivalent was closed.
     *
     * The assertion names the fixture's bank string, so it fails if the field stops being served
     * as well as if the gate stops firing; a weaker check would pass on an empty payload.
     */
    public function test_get_bank_payment_is_gated_on_the_settings_predicate(): void
    {
        $this->seedPaymentSettings();

        $call = fn (User $u) => $this->as($u)
            ->getJson('/api/admin/get-bank-payment?store_id=' . $this->store->id);

        $refused = $call($this->staff);
        $this->assertRefusedByThePermissionGate($refused, 'get-bank-payment');
        $this->assertStringNotContainsString(self::BANK_DETAIL, $this->raw($refused),
            "LEAK: the store's bank details reached a staff member holding nothing");

        $permitted = $call($this->staffWith('Manage Setting'));
        $this->assertAdmitted($permitted, 'staff granted `Manage Setting`');
        $this->assertStringContainsString(self::BANK_DETAIL, $this->raw($permitted),
            'the permitted caller was admitted and the bank detail was NOT in the payload, so the '
            . 'absence asserted above is the fixture and not the gate');

        $owner = $call($this->merchant);
        $this->assertAdmitted($owner, 'the store owner');
        $this->assertStringContainsString(self::BANK_DETAIL, $this->raw($owner));

        $this->assertRefusedByTheTenancyControl(
            $call($this->foreign), 'get-bank-payment', 'Store not found!'
        );
    }

    /** `get-cod-payment` -> the same predicate; same shape and same asymmetry as the bank reader. */
    public function test_get_cod_payment_is_gated_on_the_settings_predicate(): void
    {
        $this->seedPaymentSettings();

        $call = fn (User $u) => $this->as($u)
            ->getJson('/api/admin/get-cod-payment?store_id=' . $this->store->id);

        $refused = $call($this->staff);
        $this->assertRefusedByThePermissionGate($refused, 'get-cod-payment');
        $this->assertStringNotContainsString(self::COD_DETAIL, $this->raw($refused),
            'the COD payment configuration reached a staff member holding nothing');

        $permitted = $call($this->staffWith('Manage Setting'));
        $this->assertAdmitted($permitted, 'staff granted `Manage Setting`');
        $this->assertStringContainsString(self::COD_DETAIL, $this->raw($permitted),
            'the permitted caller was admitted and the COD instruction was NOT in the payload, so '
            . 'the absence asserted above is the fixture and not the gate');

        $owner = $call($this->merchant);
        $this->assertAdmitted($owner, 'the store owner');
        $this->assertStringContainsString(self::COD_DETAIL, $this->raw($owner));

        $this->assertRefusedByTheTenancyControl(
            $call($this->foreign), 'get-cod-payment', 'Store not found!'
        );
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  THE GET THAT WRITES: ITS WRITE IS GATED AND ITS READ IS NOT                             ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * `get-currency-rates` SEEDS THIRTEEN ROWS INTO A MONEY TABLE when the store holds none --
     * USD, EUR, SYP, LBP, IQD, EGP, JOD, SAR, AED, KWD, QAR, BHD, OMR, each `rate_per_usd = 1` and
     * `is_active = 1`, stamped `created_by` = the caller. `rate_per_usd` is the divisor every
     * displayed price is converted through, so a READ request by a principal holding nothing
     * established the store's FX table with the Syrian pound at parity with the dollar.
     *
     * THE WRITE IS WHAT IS CLOSED. The seeding branch now requires the same predicate its own write
     * twins require (`update-currency-rate`, `toggle-currency-status`, and the web
     * `CurrencyRateController::update()`): an unpermissioned caller reads the table as it stands and
     * establishes nothing.
     *
     * THE READ IS DELIBERATELY NOT REFUSED -- see the class docblock. The web twin of this read
     * carries no permission at all, so a 403 here would be narrower than the screen, on a path the
     * shipped app calls.
     */
    public function test_get_currency_rates_no_longer_lets_an_unpermissioned_caller_seed_the_fx_table(): void
    {
        $this->assertCurrencyRatesIsMigrated();

        $this->assertSame(0, (int) DB::table('currency_rates')->where('store_id', $this->store->id)->count(),
            'premise: the store must start with no rates, or the assertions below are about the '
            . "fixture's rows");

        $call = fn (User $u) => $this->as($u)
            ->getJson('/api/admin/get-currency-rates?store_id=' . $this->store->id);

        /* THE REFUSAL IS OF THE WRITE AND NOT OF THE REQUEST: the envelope is still a success. */
        $unpermissioned = $call($this->staff);
        $this->assertAdmitted($unpermissioned, 'the unpermissioned staff member (the READ is not gated)');
        $this->assertSame(0, (int) DB::table('currency_rates')->where('store_id', $this->store->id)->count(),
            'a staff member holding nothing ESTABLISHED the store\'s FX table on a GET');

        /* AND THE POSITIVE CONTROL: the same request by a principal who may administer the store's
           settings DOES seed it, so the zero above is the gate and not a handler that cannot seed
           at all. */
        $permitted = $call($this->staffWith('Manage Setting'));
        $this->assertAdmitted($permitted, 'staff granted `Manage Setting`');

        $rates = DB::table('currency_rates')->where('store_id', $this->store->id)->get();

        $this->assertCount(13, $rates,
            'the permitted caller was admitted and nothing was seeded, so the zero asserted above '
            . 'measures nothing');
        $this->assertEqualsWithDelta(
            1.0,
            (float) $rates->firstWhere('currency_code', 'SYP')->rate_per_usd,
            0.0001,
            'premise: the seeded Syrian-pound rate really is parity with the dollar'
        );
    }

    /**
     * AND THE READ IS UNCHANGED FOR THE PRINCIPAL THE WRITE GATE REFUSES, which is the half of
     * this door that must NOT move: for every store created through the product the thirteen rows
     * already exist (`StoreController:211` and `:367` seed them at creation), so this is the state
     * a real caller meets.
     */
    public function test_get_currency_rates_still_serves_an_unpermissioned_caller_a_table_that_exists(): void
    {
        $this->assertCurrencyRatesIsMigrated();

        DB::table('currency_rates')->insert([
            'store_id' => $this->store->id,
            'currency_code' => 'SYP',
            'currency_name' => 'Syrian Pound',
            'symbol' => 'ل.س',
            'rate_per_usd' => 14250.5,
            'is_active' => 1,
            'created_by' => $this->merchant->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->as($this->staff)
            ->getJson('/api/admin/get-currency-rates?store_id=' . $this->store->id);

        $this->assertAdmitted($response, 'the unpermissioned staff member reading an existing table');
        $this->assertStringContainsString('14250.5', $this->raw($response),
            'the stored rate was withheld from a principal the web twin admits, so the write gate '
            . 'became a read gate');
        $this->assertSame(1, (int) DB::table('currency_rates')->where('store_id', $this->store->id)->count(),
            'reading an existing table wrote rows');
    }

    // ╔══════════════════════════════════════════════════════════════════════════════════════════╗
    // ║  THE PLATFORM OPERATOR IS NOT LOCKED OUT BY ANY OF THESE GATES                           ║
    // ╚══════════════════════════════════════════════════════════════════════════════════════════╝

    /**
     * THE ORDERING RULE, MEASURED RATHER THAN DESCRIBED. There is no super-admin bypass in this
     * codebase: operator status is a string comparison on `users.type` and `isAbleTo()` alone
     * REFUSES the operator, so a gate built on it is a lockout and not a tightening -- this project
     * has done exactly that once, to six finance names. Every gate added here goes through
     * `MerchantPermissions` (which answers the operator the wildcard) or
     * `StoreSettingsAccess::permits()` (whose first limb is the operator).
     *
     * `deleteproduct` is deliberately absent: F206 withdraws a merchant's catalogue WRITES from the
     * operator above this gate, and the case below pins that the ordering is unchanged.
     */
    public function test_the_platform_operator_is_still_admitted_by_every_gate_shape(): void
    {
        $this->assertCurrencyRatesIsMigrated();
        $this->seedPaymentSettings();
        $operator = $this->operatorInStore();

        // (i) callerMayActInStore -> the MerchantPermissions wildcard, on the four deletes.
        $category = $this->categoryId();
        $this->assertAdmitted(
            $this->as($operator)->deleteJson('/api/admin/deletecategory', [
                'store_id' => $this->store->id, 'category_id' => $category,
            ]),
            'the platform operator at `deletecategory`'
        );
        $this->assertFalse($this->exists('categories', $category));

        $brand = $this->brandId();
        $this->assertAdmitted(
            $this->as($operator)->deleteJson('/api/admin/deleteproductbrand', [
                'store_id' => $this->store->id, 'productbrand_id' => $brand,
            ]),
            'the platform operator at `deleteproductbrand`'
        );
        $this->assertFalse($this->exists('product_brands', $brand));

        $label = $this->labelId();
        $this->assertAdmitted(
            $this->as($operator)->deleteJson('/api/admin/deleteproductlable', [
                'store_id' => $this->store->id, 'lable_id' => $label,
            ]),
            'the platform operator at `deleteproductlable`'
        );
        $this->assertFalse($this->exists('product_labels', $label));

        $review = $this->reviewId();
        $this->assertAdmitted(
            $this->as($operator)->deleteJson('/api/admin/deletereview/' . $review, []),
            'the platform operator at `deletereview`'
        );
        $this->assertFalse($this->exists('testimonials', $review));

        // (ii) callerMayActInStore on a read.
        $this->categoryId();
        $this->assertAdmitted(
            $this->as($operator)->getJson('/api/admin/categorylist?store_id=' . $this->store->id),
            'the platform operator at `categorylist`'
        );

        // (iii) StoreSettingsAccess::permits -> the operator limb is first.
        $this->assertAdmitted(
            $this->as($operator)->getJson('/api/admin/get-bank-payment?store_id=' . $this->store->id),
            'the platform operator at `get-bank-payment`'
        );
        $this->assertAdmitted(
            $this->as($operator)->getJson('/api/admin/get-cod-payment?store_id=' . $this->store->id),
            'the platform operator at `get-cod-payment`'
        );

        // (iv) the same predicate as a boolean on the currency seeder's write side.
        $this->assertAdmitted(
            $this->as($operator)->getJson('/api/admin/get-currency-rates?store_id=' . $this->store->id),
            'the platform operator at `get-currency-rates`'
        );
        $this->assertSame(13, (int) DB::table('currency_rates')->where('store_id', $this->store->id)->count(),
            'the operator was admitted and the FX table was not seeded, so the operator limb of '
            . 'the write gate is not being reached');
    }

    /**
     * AND `deleteproduct` KEEPS F206'S OWN ANSWER FOR THE OPERATOR, which is a refusal written in
     * the `error()` envelope and NOT the new 403. If this flips to a 403 the new gate has been
     * placed above `OperatorCatalogueWrite::refuses()`, which would replace an owner decision's
     * answer with a permission one.
     */
    public function test_the_platform_operator_keeps_its_own_answer_on_deleteproduct(): void
    {
        $operator = $this->operatorInStore();
        $product = $this->productId();

        $response = $this->as($operator)->deleteJson('/api/admin/deleteproduct', [
            'store_id' => $this->store->id,
            'product_id' => $product,
        ]);

        $this->assertFalse($this->admitted($response),
            'the platform operator wrote a merchant\'s catalogue. Body: ' . $this->body($response));
        $this->assertSame(200, $response->status(),
            'F206\'s refusal on this door is the `error()` envelope (HTTP 200). A 403 means the '
            . 'new permission gate now answers the operator instead. Body: ' . $this->body($response));
        $this->assertStringNotContainsString('perform this action', $this->raw($response),
            'the operator is now refused by the permission gate rather than by F206, so the new '
            . 'gate was placed above `OperatorCatalogueWrite::refuses()`');
        $this->assertTrue($this->exists('products', $product));
    }
}
