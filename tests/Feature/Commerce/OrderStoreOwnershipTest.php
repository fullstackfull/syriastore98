<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\OrderController;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
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
 * WHY THIS SUITE BUILDS ITS OWN SCHEMA. The same reason the rest of
 * tests/Feature/Commerce does: RefreshDatabase runs the whole migration set and
 * `database/migrations/2024_05_16_054044_add_id_to_currency_table.php` cannot
 * run on sqlite ("Cannot add a PRIMARY KEY column"), so the suite would die
 * during migration without reaching an assertion. The tables below carry the
 * columns the real migrations declare for the rows this writer actually touches.
 *
 * The connection is the sqlite/:memory: one phpunit.xml forces; the assertion in
 * setUp() refuses to run against anything else.
 */
class OrderStoreOwnershipTest extends TestCase
{
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

        $this->createCommerceSchema();
        $this->loginMerchantOfStoreA();
    }

    protected function tearDown(): void
    {
        Auth::logout();

        foreach ([
            'order_notes',
            'order_billing_details',
            'orders',
            'product_variants',
            'products',
            'customers',
            'settings',
            // P2-04-10: `store()` now asks whether the principal may create an order, through
            // `mayUseOrderScreen('Manage Order')` -> Laratrust's `isAbleTo()`. So the Laratrust
            // tables are tables "this writer actually touches", which is this suite's own stated
            // rule for what it builds.
            'permission_user',
            'permission_role',
            'role_user',
            'permissions',
            'roles',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

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
            'payment_type' => 'cod',
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

    private function makeVariant(int $productId, int $storeId, int $stock): ProductVariant
    {
        $variant = new ProductVariant();
        $variant->forceFill([
            'product_id' => $productId,
            'name' => 'Size M',
            'stock' => $stock,
            'stock_order_status' => 'not_allow',
            'store_id' => $storeId,
        ]);
        $variant->save();

        return $variant;
    }

    /**
     * Only the tables store() writes or reads, with the real migrations'
     * nullability where it matters (`products.created_by` and
     * `products.track_stock` are NOT NULL in
     * 2023_12_26_092822_create_products_table.php, and are reproduced as such so
     * a column the production fixture must supply cannot be skipped here).
     *
     * `orders.currency_code`, `orders.currency_symbol` and `orders.created_by`
     * come from 2026_10_12_000102_add_currency_and_created_by_to_orders_table.php.
     */
    private function createCommerceSchema(): void
    {
        foreach ([
            'order_notes',
            'order_billing_details',
            'orders',
            'product_variants',
            'products',
            'customers',
            'settings',
            // P2-04-10: `store()` now asks whether the principal may create an order, through
            // `mayUseOrderScreen('Manage Order')` -> Laratrust's `isAbleTo()`. So the Laratrust
            // tables are tables "this writer actually touches", which is this suite's own stated
            // rule for what it builds.
            'permission_user',
            'permission_role',
            'role_user',
            'permissions',
            'roles',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('type')->default('admin');
            $table->string('password');
            $table->unsignedBigInteger('current_store')->nullable()->index();
            $table->string('is_active')->default('1');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        /*
         * THE LARATRUST TABLES, because `OrderController::store()` now asks whether the principal
         * may create an order.
         *
         * P2-04-10 gated that handler -- it previously inserted an order with no permission check
         * at all, so a principal with no role created orders in their employer's store. The gate
         * runs `mayUseOrderScreen('Manage Order')`, which tests the platform operator first and
         * otherwise calls Laratrust's `isAbleTo()`. This suite's merchant is `type => 'company'`,
         * so it is NOT the operator and the permission query runs.
         *
         * WITHOUT THESE TABLES THE WHOLE SUITE ERRORED -- ten tests, `no such table: permissions`,
         * and ERRORS rather than failures, which the known-outcome gate holds at a zero baseline
         * because a test that could not run is a worse thing than one whose assertion failed.
         *
         * Columns are those `2023_12_08_100022_laratrust_setup_tables.php` declares, plus the
         * `store_id` / `created_by` that `2024_03_21_121945_add_module_coumns_in_roles_table.php`
         * adds to `roles` -- the same rule the rest of this builder follows.
         */
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->string('description')->nullable();
            $table->string('guard_name')->nullable();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('user_id');
            $table->string('user_type');
            $table->primary(['user_id', 'role_id', 'user_type']);
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('permission_user', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('user_id');
            $table->string('user_type');
            $table->primary(['user_id', 'permission_id', 'user_type']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->longText('value')->nullable();
            $table->string('created_by');
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email');
            $table->string('type')->default('cutsomer');
            $table->string('password')->nullable();
            $table->integer('status')->default(1);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('track_stock');              // NOT NULL in the real migration
            $table->string('stock_order_status')->nullable();
            $table->float('price')->default(0);
            $table->float('sale_price')->default(0);
            $table->integer('product_stock')->default(0);
            $table->integer('low_stock_threshold')->default(0);
            $table->string('stock_status')->default(0);
            $table->boolean('variant_product')->default(0);
            $table->double('average_rating', 8, 2)->default(0);
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->integer('created_by');               // NOT NULL in the real migration
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('name')->nullable();
            $table->integer('stock')->default(0);
            $table->string('stock_order_status')->nullable();
            $table->string('variation_option')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('product_order_id')->nullable();
            $table->dateTime('order_date')->nullable();
            $table->integer('customer_id')->default(0);
            $table->integer('is_guest')->default(0);
            $table->text('product_json')->nullable();
            $table->string('product_id')->default(0);
            $table->float('product_price')->nullable()->default(0);
            $table->float('coupon_price')->nullable()->default(0);
            $table->float('delivery_price')->nullable()->default(0);
            $table->float('tax_price')->nullable()->default(0);
            $table->float('final_price')->nullable()->default(0);
            $table->float('return_price')->nullable()->default(0);
            $table->text('payment_comment')->nullable();
            $table->string('payment_type')->nullable();
            $table->string('payment_status')->nullable();
            $table->string('product_order_status')->nullable();
            $table->integer('delivery_id')->default(0);
            $table->text('delivery_comment')->nullable();
            $table->integer('delivered_status')->default(0);
            $table->integer('return_status')->default(0);
            $table->float('reward_points', 8, 2)->default(0);
            $table->string('additional_note')->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->string('currency_symbol', 8)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('order_billing_details', function (Blueprint $table) {
            $table->id();
            $table->integer('order_id')->default(0);
            $table->string('product_order_id')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('telephone')->nullable();
            $table->text('address')->nullable();
            $table->string('postcode')->nullable();
            $table->string('country')->nullable();
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('order_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('notes')->nullable();
            $table->string('note_type')->nullable();
            $table->string('status')->nullable();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->timestamps();
        });

        // The customer the order is placed for. `customer_id` is validated with
        // `exists:customers,id`, so it must be real or every test stops at the
        // validator instead of reaching the loop under test.
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
