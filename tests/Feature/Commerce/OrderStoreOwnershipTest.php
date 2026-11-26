<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\OrderController;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * OrderController::store() -- PRODUCT/VARIANT OWNERSHIP AND ATOMIC STOCK.
 *
 * THREE DEFECTS, all reachable from one ordinary authenticated admin form post.
 *
 *  1. NO STORE PREDICATE. `Product::find($item['product_id'])` and
 *     `ProductVariant::find($item['variant_id'])` read whatever id was posted.
 *     `products.*.product_id` is validated with `exists:products,id`, which is
 *     GLOBAL -- it is satisfied by any store's product. So store A could post
 *     store B's product id and this writer would read B's row, DECREMENT B's
 *     stock, copy B's name and price onto A's order line, and save the order
 *     under A's store_id.
 *
 *     Nothing else covers this. config/tenancy.php:104-150 enforces six models
 *     and Product is not one of them (config/tenancy.php:46 names it DEFERRED),
 *     and app/Scopes/StoreScope.php:155 keeps the all-models flip off.
 *
 *  2. NO PRODUCT PREDICATE ON THE VARIANT. Even within one store, a posted
 *     `variant_id` belonging to a DIFFERENT product was accepted, and the
 *     decrement then took stock off that other product's variant while the
 *     order line recorded this product. A wrong-row inventory write.
 *
 *  3. READ-MODIFY-WRITE STOCK. `$variant->stock -= $qty; $variant->save();`
 *     behind a `if ($variant->stock < $qty)` test -- check-then-act, so two
 *     concurrent writers both pass the test and the second `save()` overwrites
 *     the first. ProductStockService's conditional UPDATE replaces it.
 *
 * IT USED TO BUILD ITS OWN SCHEMA, AND THE REASON IT GAVE WAS STALE.
 * ------------------------------------------------------------------------------------
 * The paragraph that stood here said RefreshDatabase could not be used because
 * `database/migrations/2024_05_16_054044_add_id_to_currency_table.php` cannot run on sqlite
 * ("Cannot add a PRIMARY KEY column"). That was true and is not: that migration carries an
 * explicit sqlite branch which rebuilds the table instead of altering it (its own docblock
 * records the fix), so `migrate` completes on sqlite. Measured 2026-11-26 on a fresh
 * in-memory migrate, and ~90 other suites in this directory already use the trait. The
 * blocker was removed and the fourteen hand-built tables outlived it.
 *
 * WHAT THE CONVERSION MEASURED, column by column, before anything was changed:
 *
 *  1. `product_variants.name` DOES NOT EXIST. The real migration
 *     (2024_01_01_104858_create_product_variants_table.php) declares `variant` and `sku`,
 *     both NOT NULL, plus `price` NOT NULL. The label now goes into `variant`.
 *
 *  2. `order_billing_details.store_id` DOES NOT EXIST, and the product documents that in
 *     three places -- Customer::ownStoreOrders(), CustomerMoneyFigures, and LynomiaChat's
 *     CommerceReader all say so by name and join through `orders` instead. The hand-built
 *     table declared it anyway, contradicting the product's own measured fact. Inert in
 *     both directions: `OrderController::store()` does pass `'store_id' => $storeId` to
 *     `OrderBillingDetail::create()`, but `store_id` is not in that model's `$fillable`, so
 *     mass assignment drops it and the column was never written even when it existed.
 *     Nothing reads it. REPORTED, not worked around.
 *
 *  3. `stores.created_by` is a FOREIGN KEY to `users.id` in the migration and the
 *     hand-built table declared no key at all. `DB_FOREIGN_KEYS` is unset so sqlite
 *     enforces it, and the fixture created both stores with `created_by = 7` BEFORE
 *     inserting user 7 -- an order the migrated schema refuses. The two inserts are now in
 *     the order the key requires; no value changed.
 *
 *  4. `roles.guard_name` is NOT NULL default 'web' in the migration and nullable with no
 *     default in the hand-built table; `permissions.name` is UNIQUE in the migration and
 *     was not. Both inert here -- the fixture supplies 'web' and inserts one permission.
 *
 *  5. `products.variant_product` defaults to 1 in the migration and 0 in the hand-built
 *     table. Inert only because makeProduct() always supplies it.
 *
 * The connection is the sqlite/:memory: one phpunit.xml forces; the assertion in
 * setUp() refuses to run against anything else.
 */
class OrderStoreOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private const STORE_A = 11;
    private const STORE_B = 22;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            ':memory:',
            config('database.connections.sqlite.database'),
            'this suite must only ever run against sqlite :memory:'
        );

        /*
         * THE PREMISES OF DIVERGENCES 1 AND 2, ASSERTED RATHER THAN ASSUMED. Without these a
         * rename of `variant` throws from inside a fixture and reads as a defect in the
         * ownership predicate, and a later migration quietly ADDING
         * `order_billing_details.store_id` would make the three production comments that say
         * it does not exist wrong with nothing to say so.
         */
        foreach (['variant', 'sku', 'price', 'stock'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('product_variants', $column),
                "product_variants.{$column} must exist in the migrated schema "
                    . '(2024_01_01_104858_create_product_variants_table.php); `name` never did'
            );
        }

        $this->assertFalse(
            Schema::hasColumn('order_billing_details', 'store_id'),
            'order_billing_details has no store_id, and Customer::ownStoreOrders(), '
                . 'CustomerMoneyFigures and LynomiaChat\'s CommerceReader all join through '
                . '`orders` because of it. If this column now exists, those three are stale.'
        );

        $this->loginMerchantOfStoreA();
        $this->customerTheOrdersArePlacedFor();
    }

    protected function tearDown(): void
    {
        Auth::logout();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | DEFECT 1 -- cross-tenant product
    |--------------------------------------------------------------------------
    */

    /**
     * Store A cannot order store B's product, and B's stock is untouched.
     *
     * The stock assertion is the one that matters: a refusal that had already
     * decremented would still be an inventory write.
     */
    public function test_a_product_from_another_store_is_refused_and_its_stock_is_untouched(): void
    {
        $foreign = $this->makeProduct(self::STORE_B, ['product_stock' => 40]);

        $response = $this->callStore([$this->line($foreign->id, 3, 10.0)]);

        $this->assertSame(
            40,
            (int) Product::withoutGlobalScopes()->find($foreign->id)->product_stock,
            "store B's stock must not have moved"
        );

        $this->assertRefused($response, 'المنتج غير موجود');
    }

    /** A product id that exists nowhere is still refused, as before. */
    public function test_an_unknown_product_id_is_refused(): void
    {
        $this->makeProduct(self::STORE_A, ['product_stock' => 5]);

        $response = $this->callStore([$this->line(999999, 1, 10.0)]);

        // Caught by the validator's exists: rule, before the loop.
        $this->assertTrue(
            $response->isRedirect(),
            'an unknown product id must not produce an order'
        );
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | DEFECT 2 -- variant belonging to another product / another store
    |--------------------------------------------------------------------------
    */

    /**
     * A variant of a DIFFERENT product in the SAME store is refused.
     *
     * This is the wrong-row write: without the `product_id` predicate the
     * decrement landed on the other product's variant.
     */
    public function test_a_variant_of_a_different_product_is_refused_and_its_stock_is_untouched(): void
    {
        $ordered = $this->makeProduct(self::STORE_A, ['product_stock' => 10, 'variant_product' => 1]);
        $other = $this->makeProduct(self::STORE_A, ['product_stock' => 10, 'variant_product' => 1]);

        $foreignVariant = $this->makeVariant($other->id, self::STORE_A, 30);

        $response = $this->callStore([
            $this->line($ordered->id, 2, 10.0) + ['variant_id' => $foreignVariant->id],
        ]);

        $this->assertSame(
            30,
            (int) ProductVariant::withoutGlobalScopes()->find($foreignVariant->id)->stock,
            "the other product's variant stock must not have moved"
        );

        $this->assertRefused($response, 'الخيار/المتغير غير موجود');
    }

    /** A variant row belonging to another STORE is refused too. */
    public function test_a_variant_from_another_store_is_refused(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 10, 'variant_product' => 1]);

        // Same product_id, but the variant row is tagged to store B. Only the
        // store predicate can reject this one.
        $foreignVariant = $this->makeVariant($product->id, self::STORE_B, 25);

        $response = $this->callStore([
            $this->line($product->id, 1, 10.0) + ['variant_id' => $foreignVariant->id],
        ]);

        $this->assertSame(
            25,
            (int) ProductVariant::withoutGlobalScopes()->find($foreignVariant->id)->stock,
            "the other store's variant stock must not have moved"
        );

        $this->assertRefused($response, 'الخيار/المتغير غير موجود');
    }

    /*
    |--------------------------------------------------------------------------
    | P4-F025 -- cross-tenant CUSTOMER on the very same form
    |--------------------------------------------------------------------------
    |
    | The PRODUCT and VARIANT predicates above were ported from the API twin.
    | The CUSTOMER predicate was not.
    |
    | `customer_id` is validated with `exists:customers,id`
    | (OrderController.php:110). `exists:` is a RAW QUERY-BUILDER rule, so it
    | bypasses Eloquent entirely and is satisfied by any store's customer. The
    | id then travels verbatim into `Order::create` (OrderController.php:363)
    | and `OrderNote::create` (:438) with no store test anywhere between them.
    |
    | WHY THE TENANT SCOPE DOES NOT COVER IT -- and why that is the trap rather
    | than the mitigation. `Customer` is one of the few models whose StoreScope
    | ACTUALLY FILTERS (config/tenancy.php:273; CLAUDE.md's scoped-models table
    | lists it as the canonical example). A reader who knows that assumes the
    | scope answers this question. The scope never sees the query: `exists:`
    | builds its own query-builder SELECT, and nothing here ever loads a
    | `Customer` model at all.
    |
    | THE IDENTICAL DEFECT IS ALREADY CLOSED ON THE API TWIN, at
    | Api/DashboardController.php:6500-6512, and pinned by
    | tests/Feature/Security/ForeignProductCartChainTest.php:1307-1333
    | (`test_the_admin_order_api_refuses_another_stores_customer`). That test
    | drives `POST /api/admin/createOrderViaApi` ONLY, so it confirms this gap
    | rather than covering it.
    |
    | AND THE SAME SCREEN'S GET ALREADY CARRIES THE PREDICATE:
    | OrderController::create() reads the preselected customer at :488-491 and
    | the whole picker list at :493, both `->where('store_id', getCurrentStore())`.
    | So the form offers a store-scoped list and the POST then accepts an id
    | from outside it.
    |
    | GRADED AS CROSS-TENANT DATA LINKAGE, NOT THEFT, and the grade is load
    | bearing for what these tests assert. The order lands in the ATTACKER'S
    | own store, so the wallet follows the attacker's store
    | (OrderFinancialService.php:21) and the victim's own order list and
    | timeline stay store-scoped. What is actually written is a durable
    | association between store A's order rows and store B's customer, in
    | `orders.customer_id` and `order_notes.customer_id` -- which is why the
    | assertions below are row counts on those two columns and not a money
    | assertion.
    |
    | EVERY READ BELOW IS AGAINST THE RAW TABLE. `Customer` and `OrderNote` are
    | both in `scoped_models` and `Order` is `Cachable`, so an Eloquent
    | "it is not there" assertion answers against whichever tenant happens to be
    | resolved, or against a cached row -- the two false readings CLAUDE.md
    | records. `DB::table()` can do neither.
    */

    /**
     * PREMISE 1 -- the fixture really straddles two stores, and store()'s own
     * `customer_id` rule really does accept the far side of it.
     *
     * Without this, the case below could pass after a fix for the wrong
     * reason: if the validator were what stopped the request, the test would
     * prove nothing about the writer. This asserts the opposite -- that the
     * rule as written lets the foreign id through to the handler body.
     *
     * The rule string is store()'s, copied from OrderController.php:110. It is
     * asserted BEHAVIOURALLY rather than by matching the controller source, so
     * that a future fix which tightens the rule itself does not redden a
     * premise test about today's mechanism.
     */
    public function test_premise_the_global_exists_rule_accepts_another_stores_customer(): void
    {
        $foreignCustomerId = $this->makeCustomer(self::STORE_B, 'victim@example.test');

        $foreignStore = (int) \Illuminate\Support\Facades\DB::table('customers')
            ->where('id', $foreignCustomerId)
            ->value('store_id');

        $this->assertSame(
            self::STORE_B,
            $foreignStore,
            'the fixture customer must be tagged to store B'
        );

        $this->assertNotSame(
            (int) getCurrentStore(),
            $foreignStore,
            'the fixture must straddle two stores, or the case below is vacuous'
        );

        $this->assertTrue(
            \Illuminate\Support\Facades\Validator::make(
                ['customer_id' => $foreignCustomerId],
                ['customer_id' => 'required|exists:customers,id']
            )->passes(),
            "store()'s own customer_id rule must ACCEPT the foreign id. If it refuses, the "
                . 'case below is stopped by the validator and says nothing about the writer.'
        );
    }

    /**
     * PREMISE 2, AND THE NEGATIVE REGRESSION -- the identical call for a
     * customer of THIS store is written, and is written with that customer.
     *
     * Two jobs in one case, both necessary:
     *
     *  - AS A PREMISE it proves the whole fixture reaches the code under test:
     *    the `Manage Order` gate passes, the validator passes, the product loop
     *    finds a product, the reconciler agrees with the posted total, and the
     *    insert commits. Every one of those has silently swallowed a case in
     *    this repository before. If this is red, nothing below means anything.
     *
     *  - AS A REGRESSION it pins the half a fix must NOT break. The customer
     *    here is deliberately NOT the schema builder's default id 1; it is a
     *    second store-A row created at runtime, so a fix that accidentally
     *    hard-codes the fixture customer, or that refuses any id the picker did
     *    not render, fails here.
     */
    public function test_the_stores_own_customer_is_accepted_and_recorded_on_the_order(): void
    {
        $ownCustomerId = $this->makeCustomer(self::STORE_A, 'second-buyer@example.test');
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 10]);

        $response = $this->callStore(
            [$this->line($product->id, 2, 10.0)],
            ['customer_id' => $ownCustomerId]
        );

        $this->assertSame(
            '',
            $this->errorFrom($response),
            "the store's own customer must not be refused"
        );

        $orders = \Illuminate\Support\Facades\DB::table('orders')->get();

        $this->assertCount(
            1,
            $orders,
            'the control order must be written, or every refusal assertion in this section '
                . 'passes for want of a working fixture rather than for want of a defect'
        );

        $this->assertSame(
            $ownCustomerId,
            (int) $orders->first()->customer_id,
            'the order must record the customer the merchant actually chose'
        );

        $this->assertSame(
            self::STORE_A,
            (int) $orders->first()->store_id
        );

        $this->assertSame(
            1,
            (int) \Illuminate\Support\Facades\DB::table('order_notes')
                ->where('customer_id', $ownCustomerId)->count(),
            "the note must name the store's own customer"
        );

        $this->assertSame(
            8,
            (int) \Illuminate\Support\Facades\DB::table('products')
                ->where('id', $product->id)->value('product_stock'),
            'the control order must have taken its stock'
        );
    }

    /**
     * THE DEFECT -- store A bills store B's customer, and nothing refuses it.
     *
     * The merchant is a legitimate, authenticated, `Manage Order`-holding
     * merchant of store A ordering store A's OWN product. The single hostile
     * value is `customer_id`, which the form's GET would never have offered
     * and which the POST never checks.
     */
    public function test_a_customer_from_another_store_is_refused_and_no_order_is_written(): void
    {
        $foreignCustomerId = $this->makeCustomer(self::STORE_B, 'victim@example.test');
        $own = $this->makeProduct(self::STORE_A, ['product_stock' => 10]);

        $ordersBefore = (int) \Illuminate\Support\Facades\DB::table('orders')->count();

        $response = $this->callStore(
            [$this->line($own->id, 2, 10.0)],
            ['customer_id' => $foreignCustomerId]
        );

        // DATA FIRST, message second -- the file's own convention (see
        // assertRefused()'s docblock): when the predicate is absent the row IS
        // written, and a message assertion firing first reports only
        // "expected string, got null", which tells a reader nothing.
        $this->assertSame(
            $ordersBefore,
            (int) \Illuminate\Support\Facades\DB::table('orders')->count(),
            "an order was written under store A naming store B's customer (id "
                . $foreignCustomerId . ')'
        );

        $this->assertSame(
            0,
            (int) \Illuminate\Support\Facades\DB::table('order_notes')
                ->where('customer_id', $foreignCustomerId)->count(),
            "an order note was written naming store B's customer"
        );

        $this->assertSame(
            10,
            (int) \Illuminate\Support\Facades\DB::table('products')
                ->where('id', $own->id)->value('product_stock'),
            'stock moved for an order that must be refused -- a refusal that has already '
                . 'decremented is still an inventory write'
        );

        /*
         * AND THE MERCHANT MUST BE TOLD. Asserted in a fix-shape-agnostic way
         * ON PURPOSE. store() redirects on success and on failure alike, so the
         * HTTP status cannot discriminate; and two legitimate fix shapes report
         * differently -- a `throw` inside the try (the shape the product and
         * variant predicates use) lands in the catch and flashes `error`, while
         * a tightened validator rule flashes an `errors` bag instead. Pinning
         * one of those would redden this test for the other.
         *
         * What IS pinned: the success path was not taken, and something was
         * reported.
         */
        $session = $response->getSession();

        $this->assertNull(
            $session?->get('success'),
            'store() took its success path for an order it must refuse'
        );

        $this->assertTrue(
            $this->errorFrom($response) !== '' || $session?->get('errors') !== null,
            'the merchant was redirected with no explanation at all'
        );
    }

    /**
     * FALSIFICATION -- the assertion block above is SATISFIABLE by this
     * handler, so its redness is the missing predicate and not a test that
     * could never go green.
     *
     * Same four assertions, same helper, same fixture -- and one extra hostile
     * value: the product is store B's too. That arm DOES have a predicate
     * (OrderController.php:184-191), so store() throws, the catch rolls back,
     * and all four properties hold. GREEN TODAY.
     *
     * This is the control that distinguishes "the customer predicate is
     * absent" from "these assertions can never pass", which is the failure
     * mode this project has actually suffered -- a test that is red or green
     * for a reason other than the one it names.
     *
     * It also stays meaningful after the fix: a request hostile in BOTH
     * dimensions must still be refused exactly once, with nothing written.
     */
    public function test_falsification_the_same_assertions_pass_when_a_predicate_does_refuse(): void
    {
        $foreignCustomerId = $this->makeCustomer(self::STORE_B, 'victim@example.test');
        $foreignProduct = $this->makeProduct(self::STORE_B, ['product_stock' => 10]);

        $ordersBefore = (int) \Illuminate\Support\Facades\DB::table('orders')->count();

        $response = $this->callStore(
            [$this->line($foreignProduct->id, 2, 10.0)],
            ['customer_id' => $foreignCustomerId]
        );

        $this->assertSame(
            $ordersBefore,
            (int) \Illuminate\Support\Facades\DB::table('orders')->count()
        );

        $this->assertSame(
            0,
            (int) \Illuminate\Support\Facades\DB::table('order_notes')
                ->where('customer_id', $foreignCustomerId)->count()
        );

        $this->assertSame(
            10,
            (int) \Illuminate\Support\Facades\DB::table('products')
                ->where('id', $foreignProduct->id)->value('product_stock')
        );

        $session = $response->getSession();

        $this->assertNull($session?->get('success'));

        $this->assertTrue(
            $this->errorFrom($response) !== '' || $session?->get('errors') !== null
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DEFECT 2b -- the predicate must FAIL CLOSED when no store resolves
    |--------------------------------------------------------------------------
    |
    | THESE TWO TESTS EXIST BECAUSE THE FIRST DRAFT OF THE FIX WAS WRONG, and an
    | independent review caught it before it was committed. That draft read
    |
    |     $storeId = getCurrentStore();
    |     Customer::...->where('store_id', $storeId)->exists()
    |
    | which is the obvious spelling and fails OPEN in both of the cases below:
    |
    |   - `getCurrentStore()` returns NULL for a principal whose `current_store`
    |     is unset and who owns no store of their own, and Laravel compiles
    |     `where('store_id', null)` to `store_id IS NULL`. So the draft ACCEPTED
    |     every customer row carrying a null store_id -- and `customers.store_id`
    |     is nullable, so those rows exist.
    |
    |   - `getCurrentStore()` hands back whatever `users.current_store` holds
    |     without asking whether that store exists, and the operator branch
    |     returns the literal `1` on a deployment whose stores do not start at 1.
    |     So the draft ACCEPTED a customer whose store_id matched a store that is
    |     not there.
    |
    | The committed fix calls this file's own `resolvedCurrentStoreId()`, which
    | asks both questions, and refuses before the transaction opens.
    |
    | Neither case is exotic: the first is an ordinary staff member who has not
    | picked a store yet, and both are reachable from the same authenticated form
    | post as the rest of this file.
    */

    /**
     * A principal for whom NO store resolves may not write an order at all --
     * and specifically may not reach the null-matches-null hole.
     *
     * The customer here carries `store_id = NULL`, which is the row the first
     * draft would have accepted. Nothing about this request is otherwise
     * hostile: the product is store A's and the quantity is in stock.
     */
    public function test_a_principal_with_no_resolvable_store_is_refused(): void
    {
        $this->loginStaffWithNoStore();

        $nullStoreCustomerId = (int) \Illuminate\Support\Facades\DB::table('customers')
            ->insertGetId([
                'first_name' => 'Orphan',
                'last_name' => 'Row',
                'email' => 'orphan@example.test',
                'status' => 1,
                'store_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $this->assertNull(
            getCurrentStore(),
            'premise: this principal must resolve no store, or the test proves nothing'
        );

        $own = $this->makeProduct(self::STORE_A, ['product_stock' => 10]);
        $ordersBefore = (int) \Illuminate\Support\Facades\DB::table('orders')->count();

        $response = $this->callStore(
            [$this->line($own->id, 2, 10.0)],
            ['customer_id' => $nullStoreCustomerId]
        );

        $this->assertSame(
            $ordersBefore,
            (int) \Illuminate\Support\Facades\DB::table('orders')->count(),
            'an order was written for a principal whose store does not resolve -- the '
                . 'null-matches-null hole the first draft of this guard left open'
        );

        $this->assertSame(
            10,
            (int) \Illuminate\Support\Facades\DB::table('products')
                ->where('id', $own->id)->value('product_stock'),
            'stock moved for an order that must be refused'
        );

        $this->assertSame(
            'No store is selected.',
            $this->errorFrom($response),
            'the principal must be told why, and told it BEFORE the transaction opens'
        );
    }

    /**
     * `users.current_store` pointing at a store that does not exist is refused
     * too -- even when the posted customer's `store_id` matches that same dead
     * id, which is precisely when a bare `where('store_id', $storeId)` passes.
     */
    public function test_a_current_store_that_does_not_exist_is_refused(): void
    {
        $deadStoreId = 404;

        $this->assertNull(
            getStoreById($deadStoreId),
            'premise: store ' . $deadStoreId . ' must not exist'
        );

        $merchant = User::withoutGlobalScopes()->find(7);
        $merchant->current_store = $deadStoreId;
        $merchant->save();
        Auth::login(User::withoutGlobalScopes()->find(7));

        $this->assertSame(
            $deadStoreId,
            (int) getCurrentStore(),
            'premise: the helper must hand back the dead id unchecked -- that is the defect '
                . 'resolvedCurrentStoreId() exists to absorb'
        );

        $matchingCustomerId = $this->makeCustomer($deadStoreId, 'ghost@example.test');
        $own = $this->makeProduct(self::STORE_A, ['product_stock' => 10]);
        $ordersBefore = (int) \Illuminate\Support\Facades\DB::table('orders')->count();

        $response = $this->callStore(
            [$this->line($own->id, 2, 10.0)],
            ['customer_id' => $matchingCustomerId]
        );

        $this->assertSame(
            $ordersBefore,
            (int) \Illuminate\Support\Facades\DB::table('orders')->count(),
            'an order was written against a store that does not exist'
        );

        $this->assertSame(
            'No store is selected.',
            $this->errorFrom($response),
            'a dead current_store must be refused, not used as a tenant predicate'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DEFECT 3 -- the decrement is atomic and still refuses a shortfall
    |--------------------------------------------------------------------------
    */

    /** An order for more than exists is refused and nothing is written. */
    public function test_a_shortfall_is_refused_and_stock_is_unchanged(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 2]);

        $response = $this->callStore([$this->line($product->id, 5, 10.0)]);

        $this->assertSame(
            2,
            (int) Product::withoutGlobalScopes()->find($product->id)->product_stock
        );

        $this->assertRefused($response, 'المخزون غير كافٍ');
    }

    /**
     * The shortfall refusal holds even when the product permits a BACKORDER.
     *
     * This is why `requireAvailability: true` is passed. ProductStockService
     * only applies its `>=` predicate when `stock_order_status === 'not_allow'`,
     * but store()'s original code refused on `stock < qty` regardless. Had the
     * service's default governed here, this manual order would newly have been
     * ACCEPTED -- a loosening, not a fix. This test is what pins that.
     */
    public function test_a_shortfall_is_refused_even_when_the_product_allows_backorder(): void
    {
        $product = $this->makeProduct(self::STORE_A, [
            'product_stock' => 2,
            'stock_order_status' => 'notify_customer',
        ]);

        $response = $this->callStore([$this->line($product->id, 5, 10.0)]);

        $this->assertSame(
            2,
            (int) Product::withoutGlobalScopes()->find($product->id)->product_stock,
            'a backorder-enabled product must still not be decremented by this writer'
        );

        $this->assertRefused($response, 'المخزون غير كافٍ');
    }

    /**
     * THE STRUCTURAL ASSERTION, and why it is structural rather than behavioural.
     *
     * HONESTY NOTE. This test replaces one I first wrote and then DISPROVED.
     * That test ("two concurrent orders cannot both take the same stock")
     * called store() twice in sequence and asserted the resulting stock. It
     * passed with the read-modify-write deliberately restored -- measured, not
     * assumed:
     *
     *     $ # $product->product_stock -= $qty; $product->save();  reinstated
     *     $ vendor/bin/phpunit tests/Feature/Commerce/OrderStoreOwnershipTest.php
     *     OK (10 tests, 55 assertions)
     *
     * It was vacuous, because two SEQUENTIAL calls each re-read the row, so the
     * second sees the first's committed write and refuses on its own `<` test.
     * A lost update needs two reads to straddle one write, which one PHP process
     * driving one sqlite connection cannot stage.
     *
     * So the lost-update property is proven where it CAN be proven -- against
     * the service, by OrderStockConcurrencyTest, which holds two stale model
     * instances and decrements through both -- and what is proven HERE is the
     * thing that would silently undo it: that this writer delegates to that
     * service instead of doing its own arithmetic. Comments are stripped before
     * matching, so a comment mentioning a symbol can neither satisfy nor defeat
     * any assertion below.
     */
    public function test_store_delegates_stock_to_the_service_and_keeps_no_read_modify_write(): void
    {
        $source = $this->executableSourceOf(OrderController::class, 'store');

        $this->assertStringContainsString(
            'decrementProductStock(',
            $source,
            'the product branch must decrement through ProductStockService'
        );
        $this->assertStringContainsString(
            'decrementVariantStock(',
            $source,
            'the variant branch must decrement through ProductStockService'
        );

        // The exact read-modify-write pattern that was here. Whitespace is
        // normalised so a reformat cannot hide it.
        $normalised = preg_replace('/\s+/', '', $source);

        foreach ([
            '$product->product_stock-=' => 'the product read-modify-write',
            '$variant->stock-=' => 'the variant read-modify-write',
            '$product->product_stock=$product->product_stock-' => 'the expanded product form',
            '$variant->stock=$variant->stock-' => 'the expanded variant form',
        ] as $needle => $what) {
            $this->assertStringNotContainsString(
                preg_replace('/\s+/', '', $needle),
                $normalised,
                $what . ' must not come back'
            );
        }
    }

    /**
     * Two orders that together exceed stock: the second is refused.
     *
     * SEQUENTIAL, and named so. This does not demonstrate a lost update (see the
     * note above); it demonstrates that the conditional UPDATE still refuses an
     * over-draw and that the first order is not rolled back by the second's
     * failure.
     */
    public function test_a_second_order_cannot_take_stock_the_first_already_took(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 10]);

        $first = $this->callStore([$this->line($product->id, 6, 10.0)]);

        // Read the flash NOW: both calls share one session store, so the
        // second call's error would otherwise be read back off the first.
        $firstError = $this->errorFrom($first);

        $second = $this->callStore([$this->line($product->id, 6, 10.0)]);

        $this->assertSame(
            4,
            (int) Product::withoutGlobalScopes()->find($product->id)->product_stock,
            'exactly one of the two orders may have taken stock'
        );

        $this->assertSame(
            1,
            Order::withoutGlobalScopes()->count(),
            'the second order must have been refused, not oversold'
        );

        $this->assertSame('', $firstError, 'the first order must succeed');
        $this->assertStringContainsString(
            'المخزون غير كافٍ',
            $this->errorFrom($second),
            'the second order must have been refused for want of stock'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | THE LEGITIMATE WORKFLOW -- a control that breaks this is a defect
    |--------------------------------------------------------------------------
    */

    /**
     * A merchant's own in-store order still succeeds, decrements, and writes its
     * child rows. This is the positive proof that the predicates above did not
     * break the feature they guard.
     */
    public function test_a_merchants_own_order_still_succeeds_and_decrements_stock(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 10]);

        $response = $this->callStore([$this->line($product->id, 3, 25.0)], [
            'delivery_price' => 5,
            'tax_price' => 2,
            'final_price' => 82,          // 3 * 25 + 5 + 2
        ]);

        $this->assertSame(
            '',
            $this->errorFrom($response),
            'the merchant\'s own order must not error'
        );

        $order = Order::withoutGlobalScopes()->first();

        $this->assertNotNull($order, 'the order row must exist');
        $this->assertSame(self::STORE_A, (int) $order->store_id);
        $this->assertSame('Paid', $order->payment_status);
        $this->assertEqualsWithDelta(82.0, (float) $order->final_price, 0.001);

        $this->assertSame(
            7,
            (int) Product::withoutGlobalScopes()->find($product->id)->product_stock,
            'stock must have been decremented by exactly the ordered quantity'
        );

        $this->assertSame(
            1,
            (int) \Illuminate\Support\Facades\DB::table('order_billing_details')
                ->where('order_id', $order->id)->count(),
            'the billing detail must have been written in the same transaction'
        );
    }

    /** The variant workflow succeeds when the variant really is this product's. */
    public function test_a_merchants_own_variant_order_succeeds_and_decrements_the_variant(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 10, 'variant_product' => 1]);
        $variant = $this->makeVariant($product->id, self::STORE_A, 8);

        $response = $this->callStore([
            $this->line($product->id, 3, 25.0) + ['variant_id' => $variant->id],
        ], [
            'final_price' => 75,
        ]);

        $this->assertSame('', $this->errorFrom($response));

        $this->assertSame(
            5,
            (int) ProductVariant::withoutGlobalScopes()->find($variant->id)->stock,
            'the variant must have been decremented'
        );

        $this->assertSame(
            10,
            (int) Product::withoutGlobalScopes()->find($product->id)->product_stock,
            "the parent product's own stock is not touched on a variant line"
        );
    }

    /**
     * THE ORDER NOTE IS NO LONGER BLANK.
     *
     * store() wrote `'changeble_status'` and `'note'`, neither of which is a
     * column (`order_notes` has `notes` and `note_type`) nor in
     * OrderNote::$fillable, so mass assignment dropped both silently and every
     * manually created order got a note row with no text.
     */
    public function test_the_order_note_records_its_text(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 10]);

        $this->callStore([$this->line($product->id, 1, 10.0)]);

        $note = OrderNote::withoutGlobalScopes()->first();

        $this->assertNotNull($note, 'a note row must be written');
        $this->assertSame(
            'Order created manually by admin',
            $note->notes,
            'the note body must be persisted, not silently discarded'
        );
        $this->assertSame('created', $note->note_type);
    }

    /*
    |--------------------------------------------------------------------------
    | Fixture
    |--------------------------------------------------------------------------
    */

    /**
     * Invoke store() directly.
     *
     * Directly rather than over HTTP so the assertions are about this writer and
     * not about the admin middleware stack, which other suites cover. The
     * controller is constructed exactly as the container would.
     */
    private function callStore(array $products, array $overrides = [])
    {
        $payload = array_merge([
            'customer_id' => 1,
            'products' => $products,
            /*
             * 'cod' -> 'POS' for P4-F126. This payload asserts `Paid` AT CREATION, and that is
             * now refused for cash on delivery: a parcel awaiting collection has not been paid
             * for. It was always an immediate cash sale in intent -- every test here is about
             * tenancy and stock, not payment -- and POS is this platform's existing type for
             * exactly that, already listed in `OFFLINE_PAYMENT_TYPES`. The assertions are
             * unchanged; only the fixture names what it always meant.
             */
            'payment_type' => 'POS',
            'payment_status' => 'Paid',
            'delivery_price' => 0,
            'tax_price' => 0,
            'final_price' => array_sum(array_map(
                static fn ($line) => $line['price'] * $line['quantity'],
                $products
            )),
            'first_name' => 'Merchant',
            'last_name' => 'Tester',
            'email' => 'buyer@example.test',
            'telephone' => '0100000000',
            'address' => '1 Test Street',
            'city' => 'Damascus',
            'state' => 'Damascus',
            'country' => 'SY',
            'postcode' => '0000',
        ], $overrides);

        $request = Request::create('/order', 'POST', $payload);
        $request->setLaravelSession(app('session.store'));

        return (new OrderController())->store($request);
    }

    private function line($productId, int $qty, float $price): array
    {
        return [
            'product_id' => $productId,
            'quantity' => $qty,
            'price' => $price,
        ];
    }

    /**
     * The flash 'error' store() sets on its failure path, or null on success.
     *
     * store() answers `redirect()->back()->with('error', ...)` on failure and
     * `redirect()->route('order.index')->with('success', ...)` on success, so the
     * presence of 'error' is the discriminator.
     */
    private function errorFrom($response): string
    {
        $session = $response->getSession();

        if ($session === null) {
            return '';
        }

        $error = $session->get('error');

        return is_string($error) ? $error : '';
    }

    /**
     * The refusal names the product, it is flashed, and the layout's toast writes a flashed error with innerHTML: a product's name is text somebody typed, so the message must not carry it
     * as markup.  P1-SEC-80, round 3 (C-4).
     */
    public function test_a_shortfall_message_does_not_carry_the_products_name_as_markup(): void
    {
        $product = $this->makeProduct(self::STORE_A, ['product_stock' => 2, 'name' => '<img src=x onerror=XSS-7>']);

        $response = $this->callStore([$this->line($product->id, 5, 10.0)]);

        $message = $this->errorFrom($response);
        $this->assertStringContainsString('XSS-7', $message, 'premise: the refusal names the product, in some form');
        $this->assertStringNotContainsString('<img', $message, 'the refusal carries the product\'s name as markup');
    }

    /**
     * Assert the write was refused, and say so usefully when it was not.
     *
     * Deliberately asserts the DATA first and the message second. When the
     * ownership predicate is removed the order IS written, and a message
     * assertion firing first reports only "expected string, got null", which
     * tells a reader nothing about what actually happened. Order count and
     * stock are the properties under test; the message is a courtesy.
     */
    private function assertRefused($response, string $expectedMessageFragment): void
    {
        $orders = Order::withoutGlobalScopes()->get();

        $this->assertCount(
            0,
            $orders,
            'the write must have been refused, but an order row was created: '
                . $orders->pluck('product_order_id')->implode(', ')
        );

        $this->assertStringContainsString(
            $expectedMessageFragment,
            $this->errorFrom($response),
            'the refusal must be reported to the merchant'
        );
    }

    private function loginMerchantOfStoreA(): void
    {
        /*
         * BOTH STORES EXIST. Store A is the merchant's own; store B is the foreign tenant whose
         * product, variant and customer the attack cases post. Store B is inserted for the same
         * reason the foreign product is: a test whose foreign tenant does not exist would pass
         * for the wrong reason, because a missing row is refused by `exists:` validation long
         * before the ownership predicate is reached.
         *
         * WRITTEN THROUGH ELOQUENT, not `DB::table()`. `Store` is `Cachable`, and this suite runs
         * under `CACHE_STORE=array`, where a raw insert leaves `Store::where('id', 11)->first()`
         * serving the pre-insert miss for the rest of the process -- the exact reading this
         * project's CLAUDE.md records as having produced three false test results. Eloquent
         * flushes.
         */
        /*
         * THE MERCHANT FIRST, AND THE ORDER MATTERS NOW. DIVERGENCE 3: the migrated `stores`
         * carries a foreign key from `created_by` to `users.id`, which sqlite enforces here, so
         * a store owned by user 7 cannot be inserted before user 7 exists. The hand-built
         * `stores` declared no key and this insert used to come after the two stores. Only the
         * order changed; every value is the one that was here.
         */
        \Illuminate\Support\Facades\DB::table('users')->insert([
            'id' => 7,
            'name' => 'Merchant A',
            'email' => 'merchant-a@example.test',
            'type' => 'company',
            'password' => bcrypt('secret'),
            'current_store' => self::STORE_A,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([self::STORE_A => 'Store A', self::STORE_B => 'Store B'] as $storeId => $storeName) {
            /*
             * `forceFill()` AND NOT `Store::create()`. `Store::$fillable` does not list `id`, so
             * `create(['id' => 11, ...])` DROPS the id silently and sqlite autoincrements it to 1
             * and 2 instead. The guard then read `getStoreById(11) === null`, refused the
             * merchant's own customer with "No store is selected." and failed the positive
             * control -- a fixture bug wearing the costume of a product defect, which is exactly
             * what a positive control is for.
             */
            $store = new \App\Models\Store();
            $store->forceFill([
                'id' => $storeId,
                'name' => $storeName,
                'email' => 'store-' . $storeId . '@example.test',
                'theme_id' => '1',
                'slug' => 'store-' . $storeId,
                'created_by' => 7,
            ])->save();

            $this->assertNotNull(
                getStoreById($storeId),
                'store ' . $storeId . ' must resolve, or every ownership assertion below is vacuous'
            );
        }

        /*
         * AND THE PERMISSION THE GATE NOW REQUIRES. A real merchant holds the shared global
         * `admin` role, which `Utility::addNewData()` grants `Manage Order` among ~190 others;
         * these four rows are the part of that this suite depends on. GRANTED RATHER THAN
         * BYPASSED -- the alternative was to make this principal the platform operator, which
         * short-circuits the gate before it queries anything -- so the suite keeps driving the
         * realistic principal AND exercises the gate instead of stepping around it.
         */
        \Illuminate\Support\Facades\DB::table('roles')->insert([
            'id' => 3, 'name' => 'admin', 'display_name' => 'admin', 'guard_name' => 'web',
            'store_id' => null, 'created_by' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('permissions')->insert([
            'id' => 4, 'name' => 'Manage Order', 'display_name' => 'Manage Order',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('permission_role')->insert([
            'permission_id' => 4, 'role_id' => 3,
        ]);

        \Illuminate\Support\Facades\DB::table('role_user')->insert([
            'role_id' => 3, 'user_id' => 7, 'user_type' => \App\Models\User::class,
        ]);

        Auth::login(User::withoutGlobalScopes()->find(7));

        $this->assertSame(
            self::STORE_A,
            (int) getCurrentStore(),
            'the fixture must resolve to store A, or every ownership assertion below is vacuous'
        );
    }

    /**
     * A customer row in a named store, written RAW.
     *
     * Raw rather than through the model on purpose, and the reason is the same
     * fact the P4-F025 section is about: `Customer` is `Cachable` AND its
     * StoreScope really filters (it also carries SoftDeletes, and `customers.deleted_at`
     * now really exists -- the hand-built table had no such column, so soft-delete
     * filtering was silently absent from every read here). Creating a store-B row
     * through Eloquent while store A is the resolved tenant is exactly the
     * shape that has produced false readings here. `DB::table()` writes the row
     * that is asked for, in the store that is asked for, with no scope and no
     * cache in the way -- and every assertion against it is raw too.
     *
     * No `flushCache()` is needed because nothing in this section reads a
     * `Customer` through Eloquent.
     */
    /**
     * A SECOND, REALISTIC PRINCIPAL: an ordinary staff member of the merchant who has not picked
     * a store yet.
     *
     * `getCurrentStore()` resolves nothing for this user by THREE independent steps, and all
     * three have to hold or the premise assertion in the test catches it: `current_store` is
     * null, `type` is not `super admin` so the hardcoded `1` branch is not taken, and no row in
     * `stores` carries `created_by = 8` so the owner fallback finds nothing either.
     *
     * It holds `Manage Order` on purpose. The point of the test is the tenant predicate, not the
     * permission gate -- a principal refused at the gate would never reach the line under test
     * and the test would pass for the wrong reason.
     */
    private function loginStaffWithNoStore(): void
    {
        \Illuminate\Support\Facades\DB::table('users')->insert([
            'id' => 8,
            'name' => 'Staff With No Store',
            'email' => 'staff-no-store@example.test',
            'type' => 'company',
            'password' => bcrypt('secret'),
            'current_store' => null,
            'created_by' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('role_user')->insert([
            'role_id' => 3, 'user_id' => 8, 'user_type' => \App\Models\User::class,
        ]);

        Auth::login(User::withoutGlobalScopes()->find(8));
    }

    private function makeCustomer(int $storeId, string $email): int
    {
        return (int) \Illuminate\Support\Facades\DB::table('customers')->insertGetId([
            'first_name' => 'Buyer',
            'last_name' => 'Of ' . $storeId,
            'email' => $email,
            'status' => 1,
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeProduct(int $storeId, array $attributes = []): Product
    {
        $product = new Product();
        $product->forceFill(array_merge([
            'name' => 'Product ' . uniqid('', false),
            'track_stock' => 1,
            'stock_order_status' => 'not_allow',
            'price' => 10,
            'product_stock' => 0,
            'variant_product' => 0,
            'store_id' => $storeId,
            'created_by' => 7,
        ], $attributes));
        $product->save();

        return $product;
    }

    /**
     * DIVERGENCE 1. The label goes into `variant`, the column the migration declares for it;
     * `sku` and `price` are NOT NULL with no default, so they are supplied rather than
     * omitted. The label's value is unchanged from the hand-built fixture's.
     */
    private function makeVariant(int $productId, int $storeId, int $stock): ProductVariant
    {
        $variant = new ProductVariant();
        $variant->forceFill([
            'product_id' => $productId,
            'variant' => 'Size M',
            'sku' => 'sku-' . uniqid('', false),
            'price' => 10,
            'stock' => $stock,
            'stock_order_status' => 'not_allow',
            'store_id' => $storeId,
        ]);
        $variant->save();

        return $variant;
    }

    /**
     * The customer the order is placed for. `customer_id` is validated with
     * `exists:customers,id`, so it must be real or every test stops at the validator
     * instead of reaching the loop under test.
     *
     * This is all that is left of the suite's fourteen-table hand-built schema: the tables
     * now come from the migrations (see the class docblock), and the only thing that was
     * ever a FIXTURE rather than a schema is this row.
     */
    private function customerTheOrdersArePlacedFor(): void
    {
        \Illuminate\Support\Facades\DB::table('customers')->insert([
            'id' => 1,
            'first_name' => 'Buyer',
            'last_name' => 'One',
            'email' => 'buyer@example.test',
            'status' => 1,
            'store_id' => self::STORE_A,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A method's source with comments stripped, so a comment cannot satisfy or
     * defeat a structural assertion. Same technique as
     * OrderWriteTransactionTest::executableSourceOf().
     */
    private function executableSourceOf(string $class, string $method): string
    {
        $m = new \ReflectionMethod($class, $method);

        $source = implode('', array_slice(
            file($m->getFileName()),
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1
        ));

        $code = '';

        foreach (token_get_all('<?php ' . $source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }
}
