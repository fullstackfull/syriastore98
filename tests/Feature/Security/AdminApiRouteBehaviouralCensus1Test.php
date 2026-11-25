<?php

namespace Tests\Feature\Security;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\OrderNote;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductVariant;
use App\Models\Shipping;
use App\Models\Store;
use App\Models\Tax;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A BEHAVIOURAL PERMISSION CENSUS OF 31 `api/admin/*` ROUTES — EVERY VERDICT DRIVEN, NONE GREPPED.
 * =================================================================================================
 *
 * WHY THIS IS NOT A TOKEN CENSUS, AND WHY A TOKEN CENSUS WAS ABANDONED. An earlier pass over the
 * same surface counted refusal-shaped helper names in the controller source. Three of its three
 * spot-checks were FALSE POSITIVES, and its headline figure MOVED when the list of token names was
 * widened — because these two controllers spell a refusal more than twenty different ways
 * (`callerMayActInStore`, `refuseWithoutPermission`, `refuseUnlessOperatorOwns`,
 * `refuseWithoutDeliveryPermission`, `refuseUnlessMayAdministerStoreSettings`,
 * `MerchantPermissions::allowsAny`, `OperatorCatalogueWrite::refuses`,
 * `VariantRemovalPolicy::authorize`, a bare `created_by` comparison, a `deliveryboy`-guard read …).
 * A name census cannot tell a gate that fires from a gate that is merely present, and it cannot see
 * a route whose only control is tenancy. Every row below was therefore obtained by SENDING THE
 * REQUEST with a real Sanctum bearer token and reading the answer plus the table.
 *
 * ─────────────────────────────────────────────────────────────────────────────── THE PRINCIPALS
 *
 *   1. AN UNPERMISSIONED STAFF MEMBER OF THE OWNING STORE — `makeStaff($merchant)` with
 *      `current_store` set and NO `grantPermission()` call. This is the principal that decides the
 *      verdict: `creatorId()` resolves them to their employer, so every tenant control in these
 *      controllers admits them BY DESIGN. If a route answers success to this token it is ungated IN
 *      FACT, whatever the source reads like. `test_premise_the_staff_principal_holds_nothing`
 *      proves the token really is empty-handed, so an UNGATED verdict cannot be a fixture artefact.
 *   2. THE STORE'S OWN OWNER — the positive control. Where the owner is refused BARE and served
 *      once granted the handler's own permission name, the route is a permission gate and not a
 *      blanket refusal; both drives are recorded, because "nobody got in" satisfies a one-sided
 *      test.
 *   3. A MERCHANT OF A DIFFERENT TENANT — the tenancy control, which must refuse.
 *
 *   For the three DRIVER routes a fourth principal is driven: a real `DeliveryBoy` token. Those
 *   routes refuse every `User`, owner included, and without the driver drive this file could not
 *   tell a gate from a handler that refuses everyone.
 *
 * ─────────────────────────────────────────────────────── HOW "ADMITTED" IS DECIDED, AND WHY NOT 200
 *
 * `ApiResponser::error()` answers HTTP **200** with `{status: 0}` by default, and its third
 * argument is the status code — so `error([...], 404)` puts `404` in the MESSAGE and still sends
 * 200. **A 200 is therefore not evidence of admission on this surface.** Admission is read from the
 * ENVELOPE (`status` ∈ {1, '1', 'success'}; this API uses all three spellings) and, for every route
 * with a side effect, from a RAW TABLE READ. The write observables go through `DB::table()` because
 * `Customer`, `DeliveryBoy` and `OrderNote` are in `config/tenancy.php`'s `scoped_models` and most
 * models here are `Cachable`: a plain `Model::find()` in an assertion answers against whichever
 * tenant happens to be resolved, or against a pre-write cached instance.
 *
 * ──────────────────────────────────────────────── A FALSE READING THIS FILE PRODUCED AND DISCARDED
 *
 * The first probe reported `api/admin/countries` and `api/admin/categorylist` as reachable with NO
 * token. They are not. `TestCase::withHeader()` writes `$this->defaultHeaders`, which PERSISTS for
 * the rest of the test method — so the "anonymous" requests were still carrying the last
 * principal's bearer token. Re-measured in a test of its own, with no `withHeader()` call anywhere
 * in it, both answer 401. `test_only_base_url_is_reachable_without_a_token` is that measurement,
 * and it is a separate method precisely so the header cannot leak into it.
 *
 * ────────────────────────────────────────────────────────────────────────── WHAT WAS MEASURED (31)
 *
 *   UNGATED — unpermissioned staff admitted (14): deletecategory, deleteproduct, deleteproductbrand,
 *     deleteproductlable, deletereview/{id}, base_url, categorylist, categoryproduct, countries,
 *     createproduct, createreview, get-bank-payment, get-cod-payment, get-currency-rates.
 *     Five of those destroy a row; one of them, a GET, CREATES thirteen rows in `currency_rates`.
 *   GATED — unpermissioned staff refused (17): deletecoupon, deleteorder, deleteshipping, deletetax,
 *     deletevariant, deliveryboysDestroy/{id}, destroyCustomer, orders/notes/delete, Top_orders,
 *     couponlist, customer/orders, customerlist, deliveryboysIndex (permission gates);
 *     deletestore (owner-identity gate); Home, delivery-order-list, delivery-transaction (driver
 *     guard — every `User` is refused, by design).
 *   VALIDATOR_FIRST — none. Every route was driven with complete, valid input, so no verdict here
 *     rests on a validator refusal.
 *   INCONCLUSIVE_FIXTURE — none. Every drive produced a decisive envelope; the fixture below exists
 *     to make that true (a product-free, child-free category for `deletecategory`, `Setting` rows
 *     for the two payment readers, a `Syria` country row for `countries`, a second store for
 *     `deletestore`, `confirm_variant` for `deletevariant`, a `User` in the store for
 *     `createreview`, a product inside the category for `categoryproduct`).
 *
 * ───────────────────────────────────────────────── EVERY PIN WAS CHECKED FOR BEING ABLE TO FAIL
 *
 * A pinned measurement that cannot fail is worth nothing, and nothing in app code was changed here
 * to prove otherwise -- so each class of assertion was inverted IN THIS FILE and re-run. Claiming
 * `deletecategory` is gated, claiming `deletecoupon` is ungated, replacing `deleteproduct`'s write
 * observable with `false`, and claiming the bank detail is withheld each turned its own test RED
 * and nothing else: 4 failed, 28 passed. The expectations were then restored and all 32 pass.
 *
 * ──────────────────────────────────────── THE UNGATED TESTS PIN PRESENT BEHAVIOUR, AWAITING A FIX
 *
 * Every `*_is_ungated_*` method below asserts WHAT HAPPENS TODAY, so this file is green and does
 * not disturb `.github/known-test-outcomes.json`'s declared outcome set. Each one is a MEASUREMENT
 * AND NOT AN ENDORSEMENT: when a gate is added, the assertion fails and names the route, which is
 * the signal to delete the pin and replace it with the gate's own suite. The owner-limb asymmetries
 * recorded on `deletevariant`, `couponlist` and `Top_orders` are FIXTURE-visible only — production
 * hands every self-registered merchant the global `admin` role, which `Utility.php` seeds with
 * `Edit Products`, `Manage Coupon` and `Manage Order` — so they are noted, not filed as defects.
 */
class AdminApiRouteBehaviouralCensus1Test extends SecurityTestCase
{
    use RefreshDatabase;

    /** The bank detail `get-bank-payment` serves; a literal so the disclosure is provable. */
    private const BANK_DETAIL = 'IBAN SY00 1234 5678 ACCOUNT HOLDER';

    // ══════════════════════════════════════════════════════════════════════════════════ FIXTURE

    /**
     * One tenant with a row for every route in the census, plus the two other principals.
     *
     * Built FRESH for each principal of each route, so a destructive drive by one principal cannot
     * be the reason a later one reports "nothing happened".
     */
    private function ctx(): object
    {
        [$merchant, $store] = $this->makeTenant();
        $store2 = $this->makeStore($merchant);

        $category = \App\Models\Category::forceCreate([
            'name' => 'Cat ' . $this->uniqueFixtureRef(),
            'slug' => 'cat-' . $this->uniqueFixtureRef(),
            'parent_id' => 0, 'trending' => 0, 'status' => 1, 'store_id' => $store->id,
        ]);

        /* `DeleteCategory` refuses a category that still holds products (the `products.category_id`
           cascade, D13/P1-INV-12) or child categories, so the delete target must be EMPTY or the
           drive would measure that guard instead of the permission axis. */
        $emptyCategory = \App\Models\Category::forceCreate([
            'name' => 'Empty ' . $this->uniqueFixtureRef(),
            'slug' => 'empty-' . $this->uniqueFixtureRef(),
            'parent_id' => 0, 'trending' => 0, 'status' => 1, 'store_id' => $store->id,
        ]);

        $product = Product::forceCreate([
            'name' => 'Prod ' . $this->uniqueFixtureRef(),
            'slug' => 'prod-' . $this->uniqueFixtureRef(),
            'description' => 'x', 'price' => 10, 'track_stock' => 0, 'trending' => 0,
            'stock_status' => 'in_stock', 'product_stock' => 5, 'product_type' => null,
            'status' => 1, 'variant_product' => 0,
            'category_id' => $category->id,
            'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);

        $variant = ProductVariant::forceCreate([
            'product_id' => $product->id,
            'variant' => 'Red', 'sku' => 'SKU-' . $this->uniqueFixtureRef(),
            'price' => 10, 'stock' => 3, 'store_id' => $store->id,
        ]);

        /* forceCreate throughout: Coupon, Shipping, Testimonial and the rest declare a non-empty
           inherited `$fillable` that omits `store_id`, so `create()` drops the discriminator
           silently and the row lands unowned -- the trap SecurityTestCase::makeRole() documents. */
        $coupon = Coupon::forceCreate([
            'coupon_name' => 'C', 'coupon_code' => 'C' . $this->uniqueFixtureRef(),
            'coupon_type' => 'flat', 'discount_amount' => 5, 'coupon_limit' => 10,
            'store_id' => $store->id,
        ]);

        $shipping = Shipping::forceCreate([
            'name' => 'Ship', 'slug' => 'ship-' . $this->uniqueFixtureRef(),
            'description' => 'd', 'store_id' => $store->id,
        ]);

        $tax = Tax::forceCreate(['name' => 'VAT', 'store_id' => $store->id]);

        $brand = ProductBrand::forceCreate([
            'name' => 'Brand', 'slug' => 'brand-' . $this->uniqueFixtureRef(),
            'status' => 1, 'is_popular' => 0,
            'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);

        $label = \App\Models\ProductLabel::forceCreate([
            'name' => 'Hot', 'slug' => 'hot-' . $this->uniqueFixtureRef(),
            'status' => 1, 'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);

        $review = Testimonial::forceCreate([
            'category_id' => null, 'product_id' => null, 'rating_no' => 5,
            'title' => 'Five', 'description' => 'd', 'username' => 'u',
            'status' => 1, 'store_id' => $store->id,
        ]);

        $customer = Customer::forceCreate([
            'first_name' => 'Shop', 'last_name' => 'Per',
            'email' => 'shopper-' . $this->uniqueFixtureRef() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-credential'), 'status' => 1,
            'store_id' => $store->id, 'register_type' => 'email',
        ]);

        $order = $this->makeOrder($store, ['customer_id' => $customer->id, 'is_guest' => 0]);

        $note = OrderNote::forceCreate([
            'order_id' => $order->id, 'customer_id' => $customer->id,
            'notes' => 'note text', 'note_type' => 'to_customer', 'status' => 'active',
            'store_id' => $store->id,
        ]);

        $driver = DeliveryBoy::forceCreate([
            'name' => 'Driver', 'email' => 'driver-' . $this->uniqueFixtureRef() . '@example.test',
            'password' => bcrypt('not-a-real-credential'), 'contact' => '0100000000',
            'profile_image' => 'uploads/profile/avatar.png', 'type' => 'deliveryboy',
            'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);

        /* `GetCodPayment` and `GetBankPayment` answer "Setting Data not found!" for a store with no
           `settings` rows, which is indistinguishable from a refusal in the envelope. The rows make
           the drive conclusive AND make the disclosure provable: BANK_DETAIL is asserted by name. */
        foreach ([
            'CURRENCY' => 'USD', 'CURRENCY_NAME' => 'Dollar',
            'is_cod_enabled' => 'on', 'cod_info' => 'pay the driver', 'cod_image' => 'x.png',
            'is_bank_transfer_enabled' => 'on',
            'bank_transfer' => self::BANK_DETAIL,
            'bank_transfer_image' => 'slip.png',
        ] as $name => $value) {
            \App\Models\Setting::forceCreate([
                'name' => $name, 'value' => $value,
                'created_by' => (string) $merchant->id, 'store_id' => $store->id,
            ]);
        }

        /* `getCountries()` filters on the literal name and 404s when it finds nothing. */
        if (! \App\Models\Country::where('name', 'Syria')->exists()) {
            \App\Models\Country::forceCreate(['name' => 'Syria']);
        }

        $staff = $this->makeStaff($merchant);
        $staff->current_store = $store->id;
        $staff->save();
        $staff = User::find($staff->id);

        $foreign = $this->makeMerchant();
        $foreignStore = $this->makeStore($foreign);
        $foreign->current_store = $foreignStore->id;
        $foreign->save();
        $foreign = User::find($foreign->id);

        return (object) compact(
            'merchant', 'store', 'store2', 'category', 'emptyCategory', 'product', 'variant',
            'coupon', 'shipping', 'tax', 'brand', 'label', 'review', 'customer', 'order',
            'note', 'driver', 'staff', 'foreign', 'foreignStore'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════════ THE CATALOGUE

    /**
     * Each route: the verb, the call to make, the write observable, and the permission name the
     * handler itself asks for (used only for the owner's positive control).
     *
     * @return array<string, array{0:string,1:callable,2:?callable,3:?string}>
     */
    private function catalogue(): array
    {
        return [
            'deletecategory' => ['delete',
                fn ($c) => ['/api/admin/deletecategory', ['store_id' => $c->store->id, 'category_id' => $c->emptyCategory->id]],
                fn ($c) => DB::table('categories')->where('id', $c->emptyCategory->id)->doesntExist(),
                'Manage Product Category'],

            'deletecoupon' => ['delete',
                fn ($c) => ['/api/admin/deletecoupon', ['store_id' => $c->store->id, 'coupon_id' => $c->coupon->id]],
                fn ($c) => DB::table('coupons')->where('id', $c->coupon->id)->doesntExist(),
                'Delete Coupon'],

            'deleteorder' => ['delete',
                fn ($c) => ['/api/admin/deleteorder', ['store_id' => $c->store->id, 'order_id' => $c->order->id]],
                fn ($c) => DB::table('orders')->where('id', $c->order->id)->doesntExist(),
                'Delete Order'],

            'deleteproduct' => ['delete',
                fn ($c) => ['/api/admin/deleteproduct', ['store_id' => $c->store->id, 'product_id' => $c->product->id]],
                fn ($c) => DB::table('products')->where('id', $c->product->id)->doesntExist(),
                'Delete Products'],

            'deleteproductbrand' => ['delete',
                fn ($c) => ['/api/admin/deleteproductbrand', ['store_id' => $c->store->id, 'productbrand_id' => $c->brand->id]],
                fn ($c) => DB::table('product_brands')->where('id', $c->brand->id)->doesntExist(),
                'Manage Product Brand'],

            'deleteproductlable' => ['delete',
                fn ($c) => ['/api/admin/deleteproductlable', ['store_id' => $c->store->id, 'lable_id' => $c->label->id]],
                fn ($c) => DB::table('product_labels')->where('id', $c->label->id)->doesntExist(),
                'Manage Product Label'],

            'deletereview' => ['delete',
                fn ($c) => ['/api/admin/deletereview/' . $c->review->id, []],
                fn ($c) => DB::table('testimonials')->where('id', $c->review->id)->doesntExist(),
                'Manage Review'],

            'deleteshipping' => ['delete',
                fn ($c) => ['/api/admin/deleteshipping', ['store_id' => $c->store->id, 'shipping_id' => $c->shipping->id]],
                fn ($c) => DB::table('shippings')->where('id', $c->shipping->id)->doesntExist(),
                'Delete Shipping Class'],

            /* store2, not store: the handler refuses a caller's LAST store, so the target must be
               the second one. The owner's drive really does destroy it, which is the point. */
            'deletestore' => ['delete',
                fn ($c) => ['/api/admin/deletestore', ['store_id' => $c->store2->id]],
                fn ($c) => DB::table('stores')->where('id', $c->store2->id)->doesntExist(),
                null],

            'deletetax' => ['delete',
                fn ($c) => ['/api/admin/deletetax', ['store_id' => $c->store->id, 'tax_id' => $c->tax->id]],
                fn ($c) => DB::table('taxes')->where('id', $c->tax->id)->doesntExist(),
                'Delete Tax'],

            /* `confirm_variant` echoes the variant's own name: F184's verifiable consent signal.
               Sent on every drive so a refusal is the GATE's and never the consent check's. */
            'deletevariant' => ['delete',
                fn ($c) => ['/api/admin/deletevariant', ['store_id' => $c->store->id, 'variant_id' => $c->variant->id, 'confirm_variant' => 'Red']],
                fn ($c) => DB::table('product_variants')->where('id', $c->variant->id)->doesntExist(),
                'Edit Products'],

            'deliveryboysDestroy' => ['delete',
                fn ($c) => ['/api/admin/deliveryboysDestroy/' . $c->driver->id . '?store_id=' . $c->store->id, []],
                fn ($c) => DB::table('delivery_boys')->where('id', $c->driver->id)->doesntExist(),
                'Delete Deliveryboy'],

            /* `customers` soft-deletes, so the observable is `deleted_at`, not the row's absence. */
            'destroyCustomer' => ['delete',
                fn ($c) => ['/api/admin/destroyCustomer?customer_id=' . $c->customer->id . '&store_id=' . $c->store->id, []],
                fn ($c) => DB::table('customers')->where('id', $c->customer->id)->whereNull('deleted_at')->doesntExist(),
                'Manage Customer'],

            'ordersNotesDelete' => ['delete',
                fn ($c) => ['/api/admin/orders/notes/delete', ['store_id' => $c->store->id, 'order_id' => $c->order->id, 'note_id' => $c->note->id]],
                fn ($c) => DB::table('order_notes')->where('id', $c->note->id)->doesntExist(),
                'Delete Order Note'],

            'Home' => ['get', fn ($c) => ['/api/admin/Home', []], null, null],

            'Top_orders' => ['get',
                fn ($c) => ['/api/admin/Top_orders?store_id=' . $c->store->id, []], null, 'Manage Order'],

            'base_url' => ['get', fn ($c) => ['/api/admin/base_url', []], null, null],

            'categorylist' => ['get',
                fn ($c) => ['/api/admin/categorylist?store_id=' . $c->store->id, []], null, 'Manage Product Category'],

            'categoryproduct' => ['get',
                fn ($c) => ['/api/admin/categoryproduct?store_id=' . $c->store->id . '&category_id=' . $c->category->id, []],
                null, 'Manage Products'],

            'countries' => ['get', fn ($c) => ['/api/admin/countries', []], null, null],

            'couponlist' => ['get',
                fn ($c) => ['/api/admin/couponlist?store_id=' . $c->store->id, []], null, 'Manage Coupon'],

            'createproduct' => ['get',
                fn ($c) => ['/api/admin/createproduct?store_id=' . $c->store->id, []], null, 'Create Products'],

            'createreview' => ['get',
                fn ($c) => ['/api/admin/createreview?store_id=' . $c->store->id, []], null, 'Manage Review'],

            'customerOrders' => ['get',
                fn ($c) => ['/api/admin/customer/orders?id=' . $c->customer->id . '&store_id=' . $c->store->id, []],
                null, 'Show Customer'],

            'customerlist' => ['get',
                fn ($c) => ['/api/admin/customerlist?store_id=' . $c->store->id, []], null, 'Manage Customer'],

            'deliveryOrderList' => ['get',
                fn ($c) => ['/api/admin/delivery-order-list?type=pending', []], null, null],

            'deliveryTransaction' => ['get', fn ($c) => ['/api/admin/delivery-transaction', []], null, null],

            'deliveryboysIndex' => ['get',
                fn ($c) => ['/api/admin/deliveryboysIndex?store_id=' . $c->store->id, []], null, 'Manage Deliveryboy'],

            'getBankPayment' => ['get',
                fn ($c) => ['/api/admin/get-bank-payment?store_id=' . $c->store->id, []], null, 'Manage Setting'],

            'getCodPayment' => ['get',
                fn ($c) => ['/api/admin/get-cod-payment?store_id=' . $c->store->id, []], null, 'Manage Setting'],

            /* The only GET in the census with a write observable, and it is a MONEY table. */
            'getCurrencyRates' => ['get',
                fn ($c) => ['/api/admin/get-currency-rates?store_id=' . $c->store->id, []],
                fn ($c) => DB::table('currency_rates')->where('store_id', $c->store->id)->exists(),
                null],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════════ THE HARNESS

    private function bearer(string $token)
    {
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    private function tokenFor($principal): string
    {
        return $principal->createToken('auth_token')->plainTextToken;
    }

    /**
     * Drive one route as one principal against a FRESH tenant, and report what happened.
     *
     * @return object{code:int, status:mixed, admitted:bool, wrote:?bool, body:string, ctx:object}
     */
    private function drive(string $route, string $who): object
    {
        /*
         * GUARD STATE DOES NOT CARRY FROM ONE DRIVE TO THE NEXT, AND THIS LINE IS NOT DEFENSIVE
         * TIDYING -- IT REPAIRS A MEASURED FALSE READING.
         *
         * Laravel keeps ONE application instance for a whole test method, so the
         * `auth(<guard>)->login($tokenable)` that `custom.auth` performs during one request is
         * still sitting in the container when the next request of the same method arrives.
         * `StoreScope::resolveTenant()` reads the DEFAULT (`web`) guard, and `DeliveryBoy` is in
         * `config/tenancy.php`'s `scoped_models` -- so on the driver drive below,
         * `$accessToken->tokenable` was narrowed by the PREVIOUS caller's store and resolved to
         * null, and `custom.auth` answered "Unauthenticated." A real driver token therefore read
         * as REFUSED by a route that serves it, which would have been recorded as the driver
         * routes refusing everyone.
         *
         * Measured, not supposed: driven alone the same driver token is SERVED (`status: 1`); driven
         * after one `User` request in the same method it reads `tokenable = NULL` and 401.
         * `User` is absent from `scoped_models`, which is why only the driver drive was corrupted
         * and why the other verdicts in this file are unaffected -- for a `User` token the morph
         * lookup carries no predicate, and `custom.auth` re-logs the right principal in before the
         * handler runs.
         *
         * BOTH LINES ARE NEEDED. `forgetGuards()` alone does not work: `auth()->login()` wrote the
         * principal into the SESSION, which also persists across requests in a test method, so a
         * freshly built `web` SessionGuard simply read it back -- measured, `auth('web')->user()`
         * still answered the previous caller's id. Flushing the session is what makes each drive
         * look like what it is meant to represent: a bare HTTP request carrying one bearer token.
         */
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        [$verb, $call, $observe] = $this->catalogue()[$route];
        $c = $this->ctx();
        $permission = $this->catalogue()[$route][3];

        $token = match ($who) {
            'staff' => $this->tokenFor($c->staff),
            'owner' => $this->tokenFor($c->merchant),
            'foreign' => $this->tokenFor($c->foreign),
            'driver' => $this->tokenFor($c->driver),
            'ownerPermitted' => (function () use ($c, $permission) {
                $this->assertNotNull($permission,
                    'ownerPermitted was asked for on a route whose handler names no permission');
                $this->grantPermission(User::find($c->merchant->id), $permission);

                return $this->tokenFor(User::find($c->merchant->id));
            })(),
        };

        [$uri, $payload] = ($call)($c);

        $response = $verb === 'get'
            ? $this->bearer($token)->getJson($uri)
            : $this->bearer($token)->deleteJson($uri, $payload);

        $status = $response->json('status');

        return (object) [
            'code' => $response->getStatusCode(),
            'status' => $status,
            // {1, '1', 'success'} -- this API spells success all three ways, and `error()` answers
            // HTTP 200 with `status: 0`, so the envelope and not the code decides.
            'admitted' => in_array($status, [1, '1', 'success'], true),
            'wrote' => $observe === null ? null : (bool) ($observe)($c),
            'body' => (string) $response->getContent(),
            'ctx' => $c,
        ];
    }

    /** The route let this principal through, and (where there is one) its side effect happened. */
    private function assertAdmitted(object $r, string $route, string $who): void
    {
        $this->assertTrue($r->admitted,
            "api/admin/{$route} refused the {$who}: " . substr($r->body, 0, 300));

        if ($r->wrote !== null) {
            $this->assertTrue($r->wrote,
                "api/admin/{$route} answered success to the {$who} but its side effect did not "
                . 'happen, so this drive proves nothing about admission');
        }
    }

    /**
     * The route refused this principal AND nothing was written.
     *
     * Both halves, because a refusal envelope over a completed write is the failure mode that
     * matters, and `assertNotSame(403)`-shaped assertions are satisfied by a 500.
     */
    private function assertRefused(object $r, string $route, string $who): void
    {
        $this->assertFalse($r->admitted,
            "api/admin/{$route} ADMITTED the {$who}: " . substr($r->body, 0, 300));

        if ($r->wrote !== null) {
            $this->assertFalse($r->wrote,
                "api/admin/{$route} refused the {$who} in the envelope but the write LANDED");
        }

        // 401 force-clears the session in the shipped Flutter app (repo-B network.dart:285-288),
        // so no authorization refusal on this surface may be one.
        $this->assertNotSame(401, $r->code,
            "api/admin/{$route} answered 401 to the {$who}, which logs merchants out of the app");

        // And it must not be a crash dressed up as a refusal.
        $this->assertLessThan(500, $r->code,
            "api/admin/{$route} answered {$r->code} to the {$who} -- that is a crash, not a gate");
    }

    /** UNGATED: the unpermissioned staff member of the owning store got in. */
    private function assertUngatedForUnpermissionedStaff(string $route): object
    {
        $r = $this->drive($route, 'staff');
        $this->assertAdmitted($r, $route, 'unpermissioned staff member');

        return $r;
    }

    /** GATED: the unpermissioned staff member was refused, and the permitted owner was not. */
    private function assertGatedForUnpermissionedStaff(string $route): object
    {
        $r = $this->drive($route, 'staff');
        $this->assertRefused($r, $route, 'unpermissioned staff member');

        return $r;
    }

    // ═══════════════════════════════════════════════════════════════════════════════════ PREMISES

    /**
     * THE PREMISE EVERY `UNGATED` VERDICT RESTS ON: the staff token really holds nothing.
     *
     * Without this, an UNGATED row could be a fixture artefact -- a principal who was accidentally
     * granted something, or a token that did not authenticate at all. All three are checked.
     */
    public function test_premise_the_staff_principal_holds_nothing(): void
    {
        $c = $this->ctx();

        $this->assertSame([], $c->staff->roles->pluck('name')->all(),
            'the census staff member holds a role, so no UNGATED verdict below means anything');

        $this->assertSame([], \App\Services\MerchantPermissions::names($c->staff, $c->store->id),
            'the census staff member carries permission names in the store being driven');

        $this->assertFalse($c->staff->isAbleTo('Manage Products'),
            'the census staff member is able to manage products');

        /* AND THE TOKEN IS A REAL, RECOGNISED TENANT PRINCIPAL, not a stranger whose refusals would
           all be tenancy. `deletecoupon` resolves the store through `creatorId()` and then refuses
           on the PERMISSION axis with a 403 -- a reply only a principal inside the tenant gets. */
        $refusal = $this->bearer($this->tokenFor($c->staff))
            ->deleteJson('/api/admin/deletecoupon', [
                'store_id' => $c->store->id, 'coupon_id' => $c->coupon->id,
            ]);

        $refusal->assertStatus(403);
        $this->assertSame('You do not have permission to perform this action.',
            $refusal->json('message'),
            'the staff token was not refused on the permission axis, so it may not be resolving '
            . 'into the tenant at all and every verdict in this file would be about tenancy');
    }

    /**
     * ONE of these 31 routes is reachable with NO Authorization header: `base_url`, whose
     * declaration carries `AdminApiLog` alone (routes/api.php:214). The other 30 all carry
     * `custom.auth`, which refuses a missing bearer token with 401 before the handler runs.
     *
     * NO `withHeader()` CALL APPEARS IN THIS METHOD, deliberately: `defaultHeaders` persists for a
     * whole test method, and the first version of this census measured four "anonymous" requests
     * that were still carrying the previous principal's token. Two of them read as 200.
     */
    public function test_only_base_url_is_reachable_without_a_token(): void
    {
        $this->assertSame(1, $this->getJson('/api/admin/base_url')->json('status'),
            'api/admin/base_url no longer answers an anonymous caller -- if it has been placed '
            . 'behind custom.auth, delete this pin rather than re-opening the route');

        foreach ([
            '/api/admin/countries',
            '/api/admin/categorylist?store_id=1',
            '/api/admin/Home',
            '/api/admin/get-bank-payment?store_id=1',
        ] as $uri) {
            $this->getJson($uri)->assertStatus(401);
        }
    }

    // ════════════════════════════════════════════════════════ UNGATED (14) -- MEASURED, NOT WANTED

    /**
     * UNGATED. An unpermissioned staff member of the owning store DELETES a category.
     *
     * The only control is `resolveOwnedStore($request->store_id)`, which is TENANCY and admits
     * every employee of the merchant through `creatorId()`. Two integrity guards do fire -- the
     * handler refuses a category holding products (the `products.category_id` cascade) or child
     * categories -- so the loss here is bounded; it is still an un-permissioned irreversible delete,
     * and `categories` has no soft delete.
     *
     * A MEASUREMENT AWAITING A FIX, pinned so the file stays green. The web twin,
     * `CategoryController::destroy()`, gates on `Delete Product Category`.
     */
    public function test_deletecategory_is_ungated_for_unpermissioned_staff(): void
    {
        $r = $this->assertUngatedForUnpermissionedStaff('deletecategory');
        $this->assertTrue($r->wrote, 'the category row survived, so nothing was measured');

        $this->assertAdmitted($this->drive('deletecategory', 'owner'), 'deletecategory', 'owner');
        $this->assertRefused($this->drive('deletecategory', 'foreign'), 'deletecategory', 'other tenant');
    }

    /**
     * UNGATED, AND THE WORST ROW IN THIS GROUP. An unpermissioned staff member DESTROYS a product:
     * the row, its `product_images`, every `carts` and `wishlists` line referencing it, and its
     * image files on disk. Irreversible, no soft delete, no audit row.
     *
     * The handler's only checks are `OperatorCatalogueWrite::refuses()` (which withdraws this door
     * from the PLATFORM OPERATOR -- the opposite axis) and `resolveOwnedStore()`, which is tenancy.
     * Its web twin gates on `Delete Products`.
     */
    public function test_deleteproduct_is_ungated_for_unpermissioned_staff(): void
    {
        $r = $this->assertUngatedForUnpermissionedStaff('deleteproduct');
        $this->assertTrue($r->wrote, 'the product row survived, so nothing was measured');

        $this->assertAdmitted($this->drive('deleteproduct', 'owner'), 'deleteproduct', 'owner');
        $this->assertRefused($this->drive('deleteproduct', 'foreign'), 'deleteproduct', 'other tenant');
    }

    /** UNGATED. Tenant-only (`resolveOwnedStore()` + `ownedStoreIds()`); the brand row is destroyed. */
    public function test_deleteproductbrand_is_ungated_for_unpermissioned_staff(): void
    {
        $r = $this->assertUngatedForUnpermissionedStaff('deleteproductbrand');
        $this->assertTrue($r->wrote, 'the brand row survived, so nothing was measured');

        $this->assertAdmitted($this->drive('deleteproductbrand', 'owner'), 'deleteproductbrand', 'owner');
        $this->assertRefused($this->drive('deleteproductbrand', 'foreign'), 'deleteproductbrand', 'other tenant');
    }

    /** UNGATED. Same shape as the brand deleter; the label row is destroyed. */
    public function test_deleteproductlable_is_ungated_for_unpermissioned_staff(): void
    {
        $r = $this->assertUngatedForUnpermissionedStaff('deleteproductlable');
        $this->assertTrue($r->wrote, 'the label row survived, so nothing was measured');

        $this->assertAdmitted($this->drive('deleteproductlable', 'owner'), 'deleteproductlable', 'owner');
        $this->assertRefused($this->drive('deleteproductlable', 'foreign'), 'deleteproductlable', 'other tenant');
    }

    /**
     * UNGATED. `DeleteReview($id)` takes the id from the PATH, loads the `Testimonial`, and derives
     * the store FROM THE ROW -- so the tenancy repair (GAP-242) holds and another tenant is refused.
     * There is no permission axis at all: any employee destroys any of their employer's reviews.
     * `testimonials` has no soft delete.
     */
    public function test_deletereview_is_ungated_for_unpermissioned_staff(): void
    {
        $r = $this->assertUngatedForUnpermissionedStaff('deletereview');
        $this->assertTrue($r->wrote, 'the review row survived, so nothing was measured');

        $this->assertAdmitted($this->drive('deletereview', 'owner'), 'deletereview', 'owner');
        $this->assertRefused($this->drive('deletereview', 'foreign'), 'deletereview', 'other tenant');
    }

    /**
     * UNGATED, and the only route of the 31 with no `custom.auth` either -- see
     * `test_only_base_url_is_reachable_without_a_token`. It discloses the upload base URL and the
     * application URL and nothing tenant-specific, which is why it is last in the ranking rather
     * than first.
     */
    public function test_base_url_is_ungated_for_unpermissioned_staff(): void
    {
        $this->assertUngatedForUnpermissionedStaff('base_url');
        $this->assertAdmitted($this->drive('base_url', 'owner'), 'base_url', 'owner');

        /* No tenancy assertion: the handler reads no store, so "another tenant" is not a question
           it can answer. Recorded rather than asserted, so the absence is deliberate. */
        $this->assertTrue($this->drive('base_url', 'foreign')->admitted,
            'premise: base_url is store-independent and serves every authenticated caller');
    }

    /**
     * UNGATED. The store's whole category tree, paginated. Tenancy holds (another tenant gets 403
     * through `storeAccessDenied()`), and there is no permission axis -- while the SIBLING list
     * endpoints in the same controller have one: `ProductList` gates on `Manage Products` and
     * `CouponList` on `Manage Coupon`, both through `refuseWithoutPermission()`. So this is a gap in
     * one of seven list handlers, not a design the surface took.
     */
    public function test_categorylist_is_ungated_for_unpermissioned_staff(): void
    {
        $this->assertUngatedForUnpermissionedStaff('categorylist');
        $this->assertAdmitted($this->drive('categorylist', 'owner'), 'categorylist', 'owner');
        $this->assertRefused($this->drive('categorylist', 'foreign'), 'categorylist', 'other tenant');
    }

    /** UNGATED. Every product id and name in a category. Tenant-only. */
    public function test_categoryproduct_is_ungated_for_unpermissioned_staff(): void
    {
        $this->assertUngatedForUnpermissionedStaff('categoryproduct');
        $this->assertAdmitted($this->drive('categoryproduct', 'owner'), 'categoryproduct', 'owner');
        $this->assertRefused($this->drive('categoryproduct', 'foreign'), 'categoryproduct', 'other tenant');
    }

    /**
     * UNGATED BY DESIGN, and recorded so the census is complete rather than because it is a finding.
     * `getCountries()` takes no store and no id: it returns the `Syria` row of a reference table.
     * A token is still required (`custom.auth`).
     */
    public function test_countries_is_ungated_for_unpermissioned_staff(): void
    {
        $this->assertUngatedForUnpermissionedStaff('countries');
        $this->assertAdmitted($this->drive('countries', 'owner'), 'countries', 'owner');
        $this->assertTrue($this->drive('countries', 'foreign')->admitted,
            'premise: countries is reference data and serves every authenticated caller');
    }

    /** UNGATED. The category and variant pickers for the product-create form. Tenant-only. */
    public function test_createproduct_is_ungated_for_unpermissioned_staff(): void
    {
        $this->assertUngatedForUnpermissionedStaff('createproduct');
        $this->assertAdmitted($this->drive('createproduct', 'owner'), 'createproduct', 'owner');
        $this->assertRefused($this->drive('createproduct', 'foreign'), 'createproduct', 'other tenant');
    }

    /**
     * UNGATED. `CreateReview` returns the id and name of every `User` whose `current_store` is this
     * store -- the merchant and their whole staff roster. Not shopper PII (no `customers` row is
     * read and no address or e-mail is served), so it is a roster disclosure, not a PII one.
     */
    public function test_createreview_is_ungated_for_unpermissioned_staff(): void
    {
        $r = $this->assertUngatedForUnpermissionedStaff('createreview');

        $this->assertNotEmpty(json_decode($r->body, true)['data']['users'] ?? [],
            'premise: the payload really does carry the store user roster');

        $this->assertAdmitted($this->drive('createreview', 'owner'), 'createreview', 'owner');
        $this->assertRefused($this->drive('createreview', 'foreign'), 'createreview', 'other tenant');
    }

    /**
     * UNGATED, AND IT SERVES THE STORE'S BANK DETAILS. `GetBankPayment` returns `bank_transfer` --
     * the free-text account / IBAN block a shopper is told to pay into -- plus the uploaded
     * transfer-slip image path, to any employee of the tenant. Its WRITE twin, `BankPayment`, is
     * gated by `refuseUnlessMayAdministerStoreSettings()` (operator, owner, or `Manage Setting`), so
     * on this one resource a staff member may READ the payment configuration they may not change.
     *
     * The assertion names the fixture's bank string, so the pin fails if the field stops being
     * served as well as if a gate is added -- a weaker check would pass on an empty payload.
     */
    public function test_get_bank_payment_is_ungated_for_unpermissioned_staff(): void
    {
        $r = $this->assertUngatedForUnpermissionedStaff('getBankPayment');

        $this->assertStringContainsString(self::BANK_DETAIL, $r->body,
            'the bank detail was not in the payload, so this drive did not measure the disclosure');

        $this->assertAdmitted($this->drive('getBankPayment', 'owner'), 'getBankPayment', 'owner');
        $this->assertRefused($this->drive('getBankPayment', 'foreign'), 'getBankPayment', 'other tenant');
    }

    /** UNGATED. The COD payment configuration, same shape and same asymmetry as the bank reader. */
    public function test_get_cod_payment_is_ungated_for_unpermissioned_staff(): void
    {
        $this->assertUngatedForUnpermissionedStaff('getCodPayment');
        $this->assertAdmitted($this->drive('getCodPayment', 'owner'), 'getCodPayment', 'owner');
        $this->assertRefused($this->drive('getCodPayment', 'foreign'), 'getCodPayment', 'other tenant');
    }

    /**
     * UNGATED, AND A GET THAT WRITES THIRTEEN ROWS INTO A MONEY TABLE.
     * =============================================================================================
     *
     * `getCurrencyRatesApi` has a tenancy gate and no permission axis. When the store holds no
     * `currency_rates` rows it SEEDS THIRTEEN -- USD, EUR, SYP, LBP, IQD, EGP, JOD, SAR, AED, KWD,
     * QAR, BHD, OMR -- each with `rate_per_usd = 1` and `is_active = 1`, stamped `created_by` =
     * the caller. `rate_per_usd` is the divisor every displayed price is converted through, so a
     * read request by a principal holding nothing establishes the store's FX table, with the
     * Syrian pound at parity with the dollar.
     *
     * It only fires on an EMPTY table -- an existing rate is never overwritten -- so this is a
     * first-touch write, not a repricing. The premise below proves the table was empty first;
     * without it the assertion would pass on rows the fixture had created.
     */
    public function test_get_currency_rates_is_ungated_and_unpermissioned_staff_seeds_the_fx_table(): void
    {
        $this->assertCurrencyRatesIsMigrated();

        [$verb, $call, $observe] = $this->catalogue()['getCurrencyRates'];
        $c = $this->ctx();

        $this->assertSame(0,
            DB::table('currency_rates')->where('store_id', $c->store->id)->count(),
            'premise: the store must start with no rates, or the write below is the fixture\'s');

        [$uri] = ($call)($c);
        $response = $this->bearer($this->tokenFor($c->staff))->getJson($uri);

        $this->assertSame(1, $response->json('status'),
            'api/admin/get-currency-rates refused the unpermissioned staff member: '
            . substr((string) $response->getContent(), 0, 300));

        $rates = DB::table('currency_rates')->where('store_id', $c->store->id)->get();

        $this->assertCount(13, $rates,
            'the unpermissioned GET no longer seeds the FX table -- if a gate was added, delete '
            . 'this pin; if the seeding moved, re-measure it');

        $this->assertEqualsWithDelta(1.0, (float) $rates->firstWhere('currency_code', 'SYP')->rate_per_usd, 0.0001,
            'premise: the seeded Syrian-pound rate really is parity with the dollar');

        $this->assertAdmitted($this->drive('getCurrencyRates', 'owner'), 'getCurrencyRates', 'owner');
        $this->assertRefused($this->drive('getCurrencyRates', 'foreign'), 'getCurrencyRates', 'other tenant');
    }

    // ═════════════════════════════════════════════════════════════════════════ GATED (17)

    /** GATED. `callerMayActInStore(['Delete Coupon','Manage Coupon'])` -> 403, above the row lookup. */
    public function test_deletecoupon_is_gated(): void
    {
        $r = $this->assertGatedForUnpermissionedStaff('deletecoupon');
        $this->assertSame(403, $r->code, 'the coupon refusal is no longer a 403');

        $this->assertAdmitted($this->drive('deletecoupon', 'owner'), 'deletecoupon', 'owner');
        $this->assertAdmitted($this->drive('deletecoupon', 'ownerPermitted'), 'deletecoupon', 'permitted owner');
        $this->assertRefused($this->drive('deletecoupon', 'foreign'), 'deletecoupon', 'other tenant');
    }

    /** GATED. `Delete Order` through `MerchantPermissions::allowsAny()`, with an owner limb -> 403. */
    public function test_deleteorder_is_gated(): void
    {
        $r = $this->assertGatedForUnpermissionedStaff('deleteorder');
        $this->assertSame(403, $r->code, 'the order-delete refusal is no longer a 403');

        $this->assertAdmitted($this->drive('deleteorder', 'owner'), 'deleteorder', 'owner');
        $this->assertAdmitted($this->drive('deleteorder', 'ownerPermitted'), 'deleteorder', 'permitted owner');
        $this->assertRefused($this->drive('deleteorder', 'foreign'), 'deleteorder', 'other tenant');
    }

    /** GATED. `Delete Shipping Class` / `Manage Shipping Class` -> 403. */
    public function test_deleteshipping_is_gated(): void
    {
        $r = $this->assertGatedForUnpermissionedStaff('deleteshipping');
        $this->assertSame(403, $r->code, 'the shipping refusal is no longer a 403');

        $this->assertAdmitted($this->drive('deleteshipping', 'owner'), 'deleteshipping', 'owner');
        $this->assertAdmitted($this->drive('deleteshipping', 'ownerPermitted'), 'deleteshipping', 'permitted owner');
        $this->assertRefused($this->drive('deleteshipping', 'foreign'), 'deleteshipping', 'other tenant');
    }

    /**
     * GATED. `Delete Tax` / `Manage Tax` -> 403, and this is the money one in the tax family:
     * `tax_methods.tax_id` is ON DELETE CASCADE against `taxes.id`, so the delete would destroy
     * every `tax_rate` beneath the application.
     */
    public function test_deletetax_is_gated(): void
    {
        $r = $this->assertGatedForUnpermissionedStaff('deletetax');
        $this->assertSame(403, $r->code, 'the tax refusal is no longer a 403');

        $this->assertAdmitted($this->drive('deletetax', 'owner'), 'deletetax', 'owner');
        $this->assertAdmitted($this->drive('deletetax', 'ownerPermitted'), 'deletetax', 'permitted owner');
        $this->assertRefused($this->drive('deletetax', 'foreign'), 'deletetax', 'other tenant');
    }

    /**
     * GATED, BY A PERMISSION-ONLY PREDICATE WITH NO OWNER LIMB, which is worth recording because it
     * is the one shape in this census that refuses the store's own owner.
     *
     * `MerchantPermissions::allowsAny($user, ['Edit Products'], $store->id)` with no
     * `callerIsStoreOwner()` first, so a BARE owner is refused too -- measured, and asserted below
     * so the asymmetry cannot drift silently. NOT a production defect: `Utility.php` seeds
     * `Edit Products` onto the global `admin` role every self-registered merchant holds; it is
     * visible here only because `makeMerchant()` creates a user with no role at all.
     *
     * The refusal is HTTP 200 with `{status: 0, data.message: "Permission denied."}` -- `error()`,
     * not a 403 -- so a client that reads the status code alone sees success.
     */
    public function test_deletevariant_is_gated_and_also_refuses_the_bare_owner(): void
    {
        $r = $this->assertGatedForUnpermissionedStaff('deletevariant');

        $this->assertSame(200, $r->code,
            'the variant refusal is no longer the HTTP-200 `error()` envelope; re-read the handler');
        $this->assertStringContainsString('Permission denied.', $r->body);

        $bare = $this->drive('deletevariant', 'owner');
        $this->assertRefused($bare, 'deletevariant', 'bare store owner');

        $this->assertAdmitted($this->drive('deletevariant', 'ownerPermitted'), 'deletevariant', 'permitted owner');
        $this->assertRefused($this->drive('deletevariant', 'foreign'), 'deletevariant', 'other tenant');
    }

    /** GATED. `refuseUnlessOperatorOwns()` then `Delete Deliveryboy` -> 403. */
    public function test_deliveryboys_destroy_is_gated(): void
    {
        $r = $this->assertGatedForUnpermissionedStaff('deliveryboysDestroy');
        $this->assertSame(403, $r->code, 'the driver-delete refusal is no longer a 403');

        $this->assertAdmitted($this->drive('deliveryboysDestroy', 'owner'), 'deliveryboysDestroy', 'owner');
        $this->assertAdmitted($this->drive('deliveryboysDestroy', 'ownerPermitted'), 'deliveryboysDestroy', 'permitted owner');
        $this->assertRefused($this->drive('deliveryboysDestroy', 'foreign'), 'deliveryboysDestroy', 'other tenant');
    }

    /**
     * GATED. `Manage Customer` -> 403, above the customer lookup so the 404 branch is not an
     * existence oracle. The highest-value PII write on this surface, and it is closed.
     */
    public function test_destroy_customer_is_gated(): void
    {
        $r = $this->assertGatedForUnpermissionedStaff('destroyCustomer');
        $this->assertSame(403, $r->code, 'the customer-delete refusal is no longer a 403');

        $this->assertAdmitted($this->drive('destroyCustomer', 'owner'), 'destroyCustomer', 'owner');
        $this->assertAdmitted($this->drive('destroyCustomer', 'ownerPermitted'), 'destroyCustomer', 'permitted owner');
        $this->assertRefused($this->drive('destroyCustomer', 'foreign'), 'destroyCustomer', 'other tenant');
    }

    /** GATED. `Delete Order Note` -> 403, above both lookups. */
    public function test_orders_notes_delete_is_gated(): void
    {
        $r = $this->assertGatedForUnpermissionedStaff('ordersNotesDelete');
        $this->assertSame(403, $r->code, 'the note-delete refusal is no longer a 403');

        $this->assertAdmitted($this->drive('ordersNotesDelete', 'owner'), 'ordersNotesDelete', 'owner');
        $this->assertAdmitted($this->drive('ordersNotesDelete', 'ownerPermitted'), 'ordersNotesDelete', 'permitted owner');
        $this->assertRefused($this->drive('ordersNotesDelete', 'foreign'), 'ordersNotesDelete', 'other tenant');
    }

    /**
     * GATED, BY OWNER IDENTITY RATHER THAN BY A PERMISSION, and the distinction matters: the
     * handler's own predicate is `stores.created_by == $user->id`, so a staff member resolves NO
     * store and is refused with the same "Store not found or you are not authorized to delete it!"
     * envelope another tenant gets. There is no permission name anywhere on this door -- which is
     * why there is no `ownerPermitted` drive here.
     *
     * The owner's drive really does destroy the store (and its pages, orders, coupons, categories
     * and products), which is what makes the staff refusal meaningful.
     */
    public function test_deletestore_is_gated_by_owner_identity(): void
    {
        $r = $this->assertGatedForUnpermissionedStaff('deletestore');
        $this->assertSame(200, $r->code,
            'the store-delete refusal is no longer the HTTP-200 `error()` envelope');

        $this->assertAdmitted($this->drive('deletestore', 'owner'), 'deletestore', 'owner');
        $this->assertRefused($this->drive('deletestore', 'foreign'), 'deletestore', 'other tenant');
    }

    /**
     * GATED. `refuseWithoutPermission(['Manage Order','Show Order'])` -> 403. No owner limb, so the
     * bare owner is refused as well; in production the global `admin` role carries `Manage Order`.
     */
    public function test_top_orders_is_gated(): void
    {
        $r = $this->assertGatedForUnpermissionedStaff('Top_orders');
        $this->assertSame(403, $r->code, 'the Top_orders refusal is no longer a 403');

        $this->assertRefused($this->drive('Top_orders', 'owner'), 'Top_orders', 'bare store owner');
        $this->assertAdmitted($this->drive('Top_orders', 'ownerPermitted'), 'Top_orders', 'permitted owner');
        $this->assertRefused($this->drive('Top_orders', 'foreign'), 'Top_orders', 'other tenant');
    }

    /** GATED. `refuseWithoutPermission(['Manage Coupon','Show Coupon'])` -> 403; no owner limb. */
    public function test_couponlist_is_gated(): void
    {
        $r = $this->assertGatedForUnpermissionedStaff('couponlist');
        $this->assertSame(403, $r->code, 'the couponlist refusal is no longer a 403');

        $this->assertRefused($this->drive('couponlist', 'owner'), 'couponlist', 'bare store owner');
        $this->assertAdmitted($this->drive('couponlist', 'ownerPermitted'), 'couponlist', 'permitted owner');
        $this->assertRefused($this->drive('couponlist', 'foreign'), 'couponlist', 'other tenant');
    }

    /** GATED. `Manage Customer` / `Show Customer` -> 403. Serves a full delivery address. */
    public function test_customer_orders_is_gated(): void
    {
        $r = $this->assertGatedForUnpermissionedStaff('customerOrders');
        $this->assertSame(403, $r->code, 'the customer-orders refusal is no longer a 403');

        $this->assertAdmitted($this->drive('customerOrders', 'owner'), 'customerOrders', 'owner');
        $this->assertAdmitted($this->drive('customerOrders', 'ownerPermitted'), 'customerOrders', 'permitted owner');
        $this->assertRefused($this->drive('customerOrders', 'foreign'), 'customerOrders', 'other tenant');
    }

    /** GATED. `Manage Customer` alone -> 403. The whole customer book with money columns. */
    public function test_customerlist_is_gated(): void
    {
        $r = $this->assertGatedForUnpermissionedStaff('customerlist');
        $this->assertSame(403, $r->code, 'the customerlist refusal is no longer a 403');

        $this->assertAdmitted($this->drive('customerlist', 'owner'), 'customerlist', 'owner');
        $this->assertAdmitted($this->drive('customerlist', 'ownerPermitted'), 'customerlist', 'permitted owner');
        $this->assertRefused($this->drive('customerlist', 'foreign'), 'customerlist', 'other tenant');
    }

    /** GATED. `Manage Deliveryboy` -> 403; the roster carries every driver's login e-mail. */
    public function test_deliveryboys_index_is_gated(): void
    {
        $r = $this->assertGatedForUnpermissionedStaff('deliveryboysIndex');
        $this->assertSame(403, $r->code, 'the driver-roster refusal is no longer a 403');

        $this->assertAdmitted($this->drive('deliveryboysIndex', 'owner'), 'deliveryboysIndex', 'owner');
        $this->assertAdmitted($this->drive('deliveryboysIndex', 'ownerPermitted'), 'deliveryboysIndex', 'permitted owner');
        $this->assertRefused($this->drive('deliveryboysIndex', 'foreign'), 'deliveryboysIndex', 'other tenant');
    }

    /**
     * THE THREE DRIVER ROUTES: GATED BY THE GUARD, AND THEY REFUSE EVERY `User`.
     * =============================================================================================
     *
     * `deliveryHome`, `DeliveryBoyOrderList` and `deliveryTransaction` each begin
     * `auth('deliveryboy')->user()` and refuse when it is null. `custom.auth` logs a token's owner
     * into the guard matching its TOKENABLE CLASS, so a `User` token -- staff, owner or another
     * tenant's merchant alike -- leaves that guard empty and is refused. The merchant refusal is
     * therefore BY DESIGN and not a defect: these are the driver app's own screens.
     *
     * A REAL `DeliveryBoy` TOKEN IS DRIVEN TOO, and that is what makes this a gate rather than a
     * handler that refuses everyone -- the one-sided reading these three would otherwise get.
     *
     * The refusal is HTTP 200 with `{status: 0}`: `deliveryHome` and `deliveryTransaction` pass
     * `401` as `error()`'s SECOND argument, so it lands in `message` and the wire stays 200. Pinned
     * as measured -- and a genuine 401 here would force a logout in the shipped app.
     */
    public function test_the_three_driver_routes_refuse_every_user_and_serve_the_driver(): void
    {
        foreach (['Home', 'deliveryOrderList', 'deliveryTransaction'] as $route) {
            foreach (['staff', 'owner', 'foreign'] as $who) {
                $r = $this->drive($route, $who);

                $this->assertRefused($r, $route, $who);
                $this->assertSame(200, $r->code,
                    "api/admin/{$route} changed its refusal code for a {$who}; a 401 here forces a "
                    . 'logout in the shipped Flutter app');
            }

            $this->assertAdmitted($this->drive($route, 'driver'), $route, 'assigned delivery driver');
        }
    }

    // ═════════════════════════════════════════════════════════════════════ THE CENSUS IS COMPLETE

    /**
     * ALL 31 ROUTES ARE ACCOUNTED FOR, AND EACH APPEARS EXACTLY ONCE.
     *
     * A census that quietly drops a row is the failure this guards against: the catalogue is the
     * single source the drives come from, so if an entry is deleted the verdict tests above stop
     * running and nothing else would notice.
     */
    public function test_the_census_covers_exactly_the_thirty_one_assigned_routes(): void
    {
        $ungated = [
            'deletecategory', 'deleteproduct', 'deleteproductbrand', 'deleteproductlable',
            'deletereview', 'base_url', 'categorylist', 'categoryproduct', 'countries',
            'createproduct', 'createreview', 'getBankPayment', 'getCodPayment', 'getCurrencyRates',
        ];

        $gated = [
            'deletecoupon', 'deleteorder', 'deleteshipping', 'deletetax', 'deletevariant',
            'deliveryboysDestroy', 'destroyCustomer', 'ordersNotesDelete', 'deletestore',
            'Top_orders', 'couponlist', 'customerOrders', 'customerlist', 'deliveryboysIndex',
            'Home', 'deliveryOrderList', 'deliveryTransaction',
        ];

        $this->assertCount(14, $ungated);
        $this->assertCount(17, $gated);

        $verdicts = array_merge($ungated, $gated);

        $this->assertSame([], array_diff($verdicts, array_keys($this->catalogue())),
            'a verdict names a route the catalogue does not drive');
        $this->assertSame([], array_diff(array_keys($this->catalogue()), $verdicts),
            'the catalogue drives a route that carries no verdict');
        $this->assertCount(31, $this->catalogue(),
            'the census no longer holds exactly the 31 assigned routes');
    }
}
