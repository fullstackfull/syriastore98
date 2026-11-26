<?php

namespace Tests\Feature\Saas;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\PlanOrder;
use App\Models\PlanRequest;
use App\Models\Product;
use App\Models\Store;
use App\Models\SupportTicket;
use App\Models\User;
use App\Scopes\StoreScope;
use App\Support\Operator\StoreThreeSixty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ADM-002 / GAP-232 — STORE 360° READS ONE STORE, AND IT IS THE ONE IT WAS ASKED FOR.
 * =================================================================================================
 *
 * THE FIXTURE IS TWO TENANTS, ALWAYS, AND THAT IS NOT BELT AND BRACES. The operator is a
 * cross-tenant principal, and `StoreScope::apply()` has an explicit PLATFORM-OPERATOR BYPASS
 * (`MODE_SUPER_ADMIN`): for a `super admin` it adds NO predicate at all. So a single-tenant
 * fixture would be green for a class with no store predicate anywhere in it — every count would be
 * right because every count would be the whole table, and the whole table would be one store's.
 * Two stores, with DIFFERENT counts in each, is the only shape that can tell the two apart.
 *
 * EVERY ASSERTION ABOUT WHAT IS IN THE DATABASE GOES THROUGH `withoutGlobalScope(StoreScope::class)`.
 * CLAUDE.md:
 *
 *   > a plain `Customer::find()`, `Cart::find()` or `SupportTicket::find()` in a test answers
 *   > against whichever tenant happens to be resolved, not against the table, so an "it is gone"
 *   > assertion passes whether or not the fix works. That cost a real false reading.
 *
 * Those three models are among the ELEVEN that really filter. `Product` and `Category` are NOT in
 * `config/tenancy.php`'s `scoped_models`, so they add no predicate for ANY principal — which makes
 * the explicit `where('store_id', ...)` inside the class under test the ONLY thing standing
 * between store A's screen and store B's catalogue.
 *
 * NOBODY IS SIGNED IN FOR THE MEASUREMENT CASES. `StoreThreeSixty` takes a `Store` and must not
 * care who is asking — the authorization question is `StoreThreeSixtyOperatorGateTest`'s. With no
 * auth container the scope's tenant is UNRESOLVED and `null_tenant` is `'open'`, so it adds no
 * predicate either: a class that leaned on the scope would read every tenant here and be caught.
 */
final class StoreThreeSixtyFactsTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $ownerA;
    private User $ownerB;
    private Store $a;
    private Store $b;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->ownerA, $this->a] = $this->makeTenant();
        [$this->ownerB, $this->b] = $this->makeTenant();

        /* DIFFERENT COUNTS PER STORE, ON PURPOSE: equal counts cannot distinguish "read store A"
           from "read the table and divide by two". */
        $this->makeOrder($this->a, ['delivered_status' => 0, 'final_price' => 10, 'currency_code' => 'USD']);
        $this->makeOrder($this->a, ['delivered_status' => 1, 'final_price' => 20, 'currency_code' => 'USD']);
        $this->makeOrder($this->a, ['delivered_status' => 1, 'final_price' => 30, 'currency_code' => 'SYP']);
        $this->makeOrder($this->b, ['delivered_status' => 2, 'final_price' => 999, 'currency_code' => 'USD']);

        $this->makeProduct($this->a, ['is_active' => 1, 'track_stock' => 1, 'product_stock' => 0]);
        $this->makeProduct($this->a, ['is_active' => 1, 'track_stock' => 1, 'product_stock' => 5]);
        $this->makeProduct($this->a, ['is_active' => 0, 'track_stock' => 0, 'product_stock' => 0]);
        $this->makeProduct($this->b, ['is_active' => 1, 'track_stock' => 1, 'product_stock' => 0]);

        $this->makeCategory($this->a);
        $this->makeCategory($this->b);
        $this->makeCategory($this->b);

        $this->makeShopper($this->a);
        $this->makeShopper($this->a);
        $this->makeShopper($this->b);

        Cart::create(['customer_id' => 0, 'product_id' => 1, 'qty' => 1, 'price' => 5, 'store_id' => $this->a->id]);
        Cart::create(['customer_id' => 0, 'product_id' => 1, 'qty' => 1, 'price' => 5, 'store_id' => $this->b->id]);
        Cart::create(['customer_id' => 0, 'product_id' => 1, 'qty' => 1, 'price' => 5, 'store_id' => $this->b->id]);

        $this->makeTicket($this->a, 'open');
        $this->makeTicket($this->a, 'Solved');
        $this->makeTicket($this->b, 'open');

        /* Nobody signed in: see the class docblock. */
        auth()->forgetGuards();
        $this->app['auth']->forgetUser();
    }

    #[Test]
    public function test_the_catalogue_block_counts_only_this_stores_products_and_categories(): void
    {
        $a = StoreThreeSixty::forStore($this->a)['catalogue'];
        $b = StoreThreeSixty::forStore($this->b)['catalogue'];

        $this->assertSame(['products' => 3, 'active' => 2, 'out_of_stock' => 1, 'categories' => 1], $a);
        $this->assertSame(['products' => 1, 'active' => 1, 'out_of_stock' => 1, 'categories' => 2], $b);

        /* THE TABLE REALLY HOLDS BOTH STORES' ROWS, so the figures above are a predicate doing its
           job and not an empty table. `Product` and `Category` carry no effective scope, so these
           two reads see everything. */
        $this->assertSame(4, Product::query()->count());
        $this->assertSame(3, Category::query()->count());
    }

    #[Test]
    public function test_the_orders_block_counts_only_this_stores_orders_and_buckets_them_by_the_declared_states(): void
    {
        $a = StoreThreeSixty::forStore($this->a)['orders'];
        $b = StoreThreeSixty::forStore($this->b)['orders'];

        $this->assertSame(3, $a['total']);
        $this->assertSame(1, $b['total']);

        $this->assertSame(0, $a['unclassified']);
        $this->assertSame(0, $b['unclassified']);

        $this->assertSame(
            ['pending' => 1, 'delivered' => 2, 'cancelled' => 0],
            $this->statusSlice($a['by_status'], ['pending', 'delivered', 'cancelled'])
        );
        $this->assertSame(
            ['pending' => 0, 'delivered' => 0, 'cancelled' => 1],
            $this->statusSlice($b['by_status'], ['pending', 'delivered', 'cancelled'])
        );

        /* The buckets are the API contract's nine, so the screen cannot invent a tenth. */
        $this->assertCount(9, $a['by_status']);
    }

    /**
     * THE `unclassified` BUCKET IS THE CENSUS RULE, AND IT IS DRIVEN RATHER THAN DESCRIBED.
     *
     * A breakdown keyed on the statuses somebody thought of silently drops the one nobody did —
     * the buckets sum to less than the total and nothing says so. Here an order is written with a
     * `delivered_status` the contract does not declare, and the bucket has to name it.
     *
     * THE WRITE IS THROUGH ELOQUENT, NOT `DB::table()->update()`. `Order` is `Cachable`; a raw
     * write leaves Eloquent reads serving the pre-write row for the rest of the test under
     * `CACHE_STORE=array`, which CLAUDE.md records as three measured false readings.
     */
    #[Test]
    public function test_an_order_in_an_undeclared_state_is_counted_and_named_rather_than_dropped(): void
    {
        $this->makeOrder($this->a, ['delivered_status' => 42]);

        $a = StoreThreeSixty::forStore($this->a)['orders'];

        $this->assertSame(4, $a['total']);
        $this->assertSame(1, $a['unclassified'],
            'an order whose status the contract does not declare vanished from the breakdown.');
        $this->assertSame(
            3,
            array_sum(array_column($a['by_status'], 'orders')),
            'the declared buckets absorbed the undeclared status, which is the failure this bucket exists to prevent.'
        );
    }

    #[Test]
    public function test_the_shopper_block_counts_only_this_stores_customers_and_carts(): void
    {
        $a = StoreThreeSixty::forStore($this->a)['shoppers'];
        $b = StoreThreeSixty::forStore($this->b)['shoppers'];

        $this->assertSame(['customers' => 2, 'open_carts' => 1], $a);
        $this->assertSame(['customers' => 1, 'open_carts' => 2], $b);

        /* THROUGH `withoutGlobalScope`, because `Customer` and `Cart` are two of the eleven that
           really filter and a plain count here would answer for whichever tenant resolved. */
        $this->assertSame(3, Customer::query()->withoutGlobalScope(StoreScope::class)->count());
        $this->assertSame(3, Cart::query()->withoutGlobalScope(StoreScope::class)->count());
    }

    /**
     * A SOFT-DELETED SHOPPER IS NOT COUNTED, which is why the class drops the tenant scope BY NAME
     * rather than calling `withoutGlobalScopes()`. The plural form would also strip
     * `Customer`'s `SoftDeletingScope` and start counting deleted shoppers — a change nothing on
     * screen would announce.
     */
    #[Test]
    public function test_a_deleted_shopper_leaves_the_count(): void
    {
        $this->assertSame(2, StoreThreeSixty::forStore($this->a)['shoppers']['customers']);

        Customer::query()->withoutGlobalScope(StoreScope::class)
            ->where('store_id', $this->a->id)
            ->first()
            ->delete();

        $this->assertSame(1, StoreThreeSixty::forStore($this->a)['shoppers']['customers'],
            'the tenant scope was dropped with withoutGlobalScopes(), which took soft deletes with it.');
    }

    #[Test]
    public function test_the_support_block_counts_only_this_stores_tickets_on_the_declared_statuses(): void
    {
        $a = StoreThreeSixty::forStore($this->a)['support'];
        $b = StoreThreeSixty::forStore($this->b)['support'];

        $this->assertSame(2, $a['tickets']);
        $this->assertSame(1, $b['tickets']);
        $this->assertSame(0, $a['unclassified']);

        $this->assertSame(
            ['open' => 1, 'In Progress' => 0, 'Solved' => 1],
            collect($a['by_status'])->pluck('tickets', 'status')->all()
        );

        /* The buckets are the model's own declared set, so the screen and the writer cannot
           disagree about what a status is. */
        $this->assertSame(SupportTicket::STATUSES, array_column($a['by_status'], 'status'));

        $this->assertSame(
            3,
            SupportTicket::query()->withoutGlobalScope(StoreScope::class)->count()
        );
    }

    #[Test]
    public function test_a_ticket_in_an_undeclared_state_is_counted_and_named_rather_than_dropped(): void
    {
        $this->makeTicket($this->a, 'Escalated to legal');

        $a = StoreThreeSixty::forStore($this->a)['support'];

        $this->assertSame(3, $a['tickets']);
        $this->assertSame(1, $a['unclassified']);
    }

    /**
     * MONEY IS PER CURRENCY AND IS NEVER SUMMED. Store A has 10 + 20 USD and 30 SYP; the right
     * answer is TWO ROWS, not one 60. A class that totalled them would be wrong by construction —
     * the schema records no exchange rate for an order's own date.
     */
    #[Test]
    public function test_order_value_is_one_row_per_currency_for_this_store_only(): void
    {
        $a = StoreThreeSixty::forStore($this->a)['money'];

        $this->assertCount(2, $a['rows'], 'the per-currency rows were folded together.');

        $byCurrency = collect($a['rows'])->pluck('amount', 'currency')->all();

        $this->assertArrayHasKey('USD', $byCurrency);
        $this->assertArrayHasKey('SYP', $byCurrency);
        $this->assertStringContainsString('30.00', $byCurrency['USD']);
        $this->assertStringContainsString('30.00', $byCurrency['SYP']);

        /* Store B's 999 is in neither row. */
        foreach ($byCurrency as $amount) {
            $this->assertStringNotContainsString('999', $amount,
                "another store's order value appeared in this store's money block.");
        }

        /* THE METRIC'S OWN CAVEATS TRAVEL WITH THE FIGURE. An amount with no disclosure beside it
           is how a precision or coverage limit gets read as exact. */
        $this->assertNotSame([], $a['caveats'],
            'the money block states no caveat at all, so GrossMerchandiseValue\'s own disclosures '
            . 'are being dropped between the metric and the screen.');
    }

    /**
     * SUBSCRIPTION HANGS OFF THE OWNER, AND `plan_requests` HAS NO `store_id`. Both are facts
     * about this schema rather than simplifications, and both are asserted so a later "fix" that
     * invents a per-store predicate for plan requests is caught.
     */
    #[Test]
    public function test_the_subscription_block_reads_the_owner_and_predicates_plan_orders_by_store(): void
    {
        /* `plans.themes` and `plans.storage_limit` are NOT NULL with no default -- measured from
           the migration, which is why a three-key fixture failed on insert. */
        $plan = Plan::create([
            'name' => 'Growth ' . $this->uniqueFixtureRef(),
            'price' => 49,
            'duration' => 'Month',
            'themes' => '[]',
            'storage_limit' => '100',
        ]);

        $this->ownerA->plan_id = $plan->id;
        $this->ownerA->plan_expire_date = '2030-01-01';
        $this->ownerA->save();
        (new User)->flushCache();

        PlanOrder::create(['order_id' => 'PO-' . $this->uniqueFixtureRef(), 'plan_id' => $plan->id, 'plan_name' => $plan->name, 'price' => 49, 'user_id' => $this->ownerA->id, 'store_id' => $this->a->id, 'payment_status' => 'succeeded', 'price_currency' => 'USD', 'txn_id' => 'TX-' . $this->uniqueFixtureRef()]);
        PlanOrder::create(['order_id' => 'PO-' . $this->uniqueFixtureRef(), 'plan_id' => $plan->id, 'plan_name' => $plan->name, 'price' => 49, 'user_id' => $this->ownerB->id, 'store_id' => $this->b->id, 'payment_status' => 'succeeded', 'price_currency' => 'USD', 'txn_id' => 'TX-' . $this->uniqueFixtureRef()]);

        PlanRequest::create(['user_id' => $this->ownerA->id, 'plan_id' => $plan->id, 'duration' => 'Month']);

        $a = StoreThreeSixty::forStore($this->a->fresh())['subscription'];

        $this->assertSame($plan->name, $a['plan_name']);
        $this->assertSame(1, $a['plan_orders'], "another store's plan order was counted.");
        $this->assertSame(0, $a['plan_orders_refunded']);
        $this->assertSame(1, $a['owner_plan_requests']);

        $b = StoreThreeSixty::forStore($this->b->fresh())['subscription'];
        $this->assertSame(1, $b['plan_orders']);
        $this->assertSame(0, $b['owner_plan_requests']);
    }

    /**
     * THE DOORS ARE THE `من أنظمته الأصلية` HALF. Each one is an EXISTING route, so the assertion
     * is that the route exists and the url points at the OWNER of THIS store.
     */
    #[Test]
    public function test_every_door_names_a_registered_route_and_this_stores_own_owner(): void
    {
        $doors = StoreThreeSixty::forStore($this->a)['doors'];

        $this->assertNotSame([], $doors, 'a store with an owner offered no way into its own systems.');

        foreach ($doors as $door) {
            $this->assertTrue(\Illuminate\Support\Facades\Route::has($door['name']),
                $door['name'] . ' is not a registered route, so the screen would throw inside the view.');

            $this->assertNotSame('', $door['label']);
            $this->assertNotSame('', $door['hint']);
            $this->assertStringContainsString('http', $door['url']);
        }

        $this->assertSame(
            ['login.with.admin', 'plan.upgrade', 'user.info', 'stores.link', 'stores.reset.password'],
            array_column($doors, 'name')
        );
    }

    /**
     * THE "NO OWNER" STATE IS UNREACHABLE WHILE THE FOREIGN KEY HOLDS, AND SAYING SO IS THE POINT
     * OF THIS CASE.
     *
     * `StoreThreeSixty` and both views carry a branch for a store whose owner cannot be resolved.
     * Measured from the booted schema rather than assumed from the model:
     * `2023_12_14_090259_create_stores_table.php` declares
     * `$table->unsignedBigInteger('created_by')` -- NOT NULL, no default -- with
     * `->foreign('created_by')->references('id')->on('users')->onDelete('cascade')`. So all three
     * routes into that state are closed: a NULL is refused, a dangling id is refused, and deleting
     * the owner CASCADES the store away rather than orphaning it.
     *
     * The branch is therefore DECLARED DEAD CODE, not a tested path, and this case is what keeps
     * the two statements honest: if the constraint is ever dropped, these assertions fail and
     * somebody decides deliberately whether the branch is now live. That is better than deleting
     * null-safety on the strength of a migration file, because CLAUDE.md records that the
     * migrations are not the schema.
     */
    #[Test]
    public function test_premise_the_schema_refuses_a_store_with_no_resolvable_owner(): void
    {
        $base = [
            'name' => 'Orphan',
            'email' => 'orphan' . $this->uniqueFixtureRef() . '@example.test',
            'slug' => 'orphan-' . $this->uniqueFixtureRef(),
            'theme_id' => 'stylique',
            'default_language' => 'en',
        ];

        $refusals = 0;

        try {
            Store::create($base + ['created_by' => null]);
        } catch (\Illuminate\Database\QueryException $e) {
            $refusals++;
        }

        try {
            Store::create($base + ['created_by' => User::query()->max('id') + 500]);
        } catch (\Illuminate\Database\QueryException $e) {
            $refusals++;
        }

        $this->assertSame(2, $refusals,
            '`stores.created_by` now accepts a NULL or a dangling id. The "owner record missing" '
            . 'branch in StoreThreeSixty and in both views is no longer dead code -- decide '
            . 'deliberately whether it is now a state the operator can really meet.');
    }

    /**
     * THE DEAD BRANCH IS STILL CORRECT, asserted by calling `doors()` directly with the null the
     * schema will not let a fixture produce. Driving it through `forStore()` is impossible for the
     * reason the premise above establishes, so the unit seam is the only honest instrument.
     */
    #[Test]
    public function test_the_doors_list_is_empty_when_no_owner_can_be_resolved(): void
    {
        $this->assertSame([], StoreThreeSixty::doors($this->a, null));

        /* CONTROL: the same store WITH its owner offers five, so the empty answer above is the
           null doing the work and not a broken method. */
        $this->assertCount(5, StoreThreeSixty::doors($this->a, $this->ownerA));
    }

    #[Test]
    public function test_the_roster_lists_every_store_with_its_owner(): void
    {
        $roster = StoreThreeSixty::roster();

        $ids = array_column($roster, 'id');

        $this->assertContains($this->a->id, $ids);
        $this->assertContains($this->b->id, $ids);

        $rowA = collect($roster)->firstWhere('id', $this->a->id);

        $this->assertSame($this->ownerA->name, $rowA['owner_name']);
        $this->assertSame($this->ownerA->email, $rowA['owner_email']);
        $this->assertFalse($rowA['owner_missing']);
    }

    /**
     * @param list<array<string, mixed>> $buckets
     * @param list<string> $keys
     * @return array<string, int>
     */
    private function statusSlice(array $buckets, array $keys): array
    {
        $byKey = collect($buckets)->pluck('orders', 'key')->all();

        $out = [];

        foreach ($keys as $key) {
            $out[$key] = $byKey[$key] ?? null;
        }

        return $out;
    }

    /**
     * PROPERTY ASSIGNMENT RATHER THAN `Product::create()`, and the reason is measured rather than
     * stylistic: `created_by` and `is_active` are NOT in `Product::$fillable` (the array lists
     * `store_id` and stops), so a mass assignment DROPS them silently and the insert fails on
     * `products.created_by NOT NULL`. The write still goes through Eloquent, which is what
     * flushes the `Cachable` model cache -- a raw `DB::table()->insert()` would leave the counts
     * under test reading the pre-write table for the rest of the case.
     */
    private function makeProduct(Store $store, array $attributes = []): Product
    {
        $product = new Product();
        $product->name = 'P-' . $this->uniqueFixtureRef();
        $product->slug = 'p-' . $this->uniqueFixtureRef();
        $product->price = 10;
        $product->store_id = $store->id;
        $product->created_by = $store->created_by;
        $product->is_active = 1;

        foreach ($attributes as $column => $value) {
            $product->{$column} = $value;
        }

        $product->save();

        return $product;
    }

    /** Same reason as `makeProduct()`: not every column a NOT NULL constraint needs is fillable. */
    private function makeCategory(Store $store): Category
    {
        $category = new Category();
        $category->name = 'C-' . $this->uniqueFixtureRef();
        $category->slug = 'c-' . $this->uniqueFixtureRef();
        $category->store_id = $store->id;
        /* `categories` HAS NO `created_by` COLUMN -- measured from the booted schema, not read off
           the model, which happily accepts the attribute and then fails on insert. */
        $category->save();

        return $category;
    }

    private function makeShopper(Store $store): Customer
    {
        return Customer::create([
            'name' => 'Shopper',
            'email' => 'shopper' . $this->uniqueFixtureRef() . '@example.test',
            'password' => bcrypt('secret-probe'),
            'store_id' => $store->id,
            'created_by' => $store->created_by,
            'type' => 'customer',
        ]);
    }

    private function makeTicket(Store $store, string $status): SupportTicket
    {
        $ticket = new SupportTicket();
        $ticket->ticket_id = 'TK-' . $this->uniqueFixtureRef();
        $ticket->title = 'T';
        $ticket->description = 'D';
        $ticket->status = $status;
        $ticket->store_id = $store->id;
        $ticket->created_by = $store->created_by;
        $ticket->save();

        return $ticket;
    }
}
