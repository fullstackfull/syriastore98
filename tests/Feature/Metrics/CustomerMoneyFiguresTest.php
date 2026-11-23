<?php

namespace Tests\Feature\Metrics;

use App\Models\Customer;
use App\Models\User;
use App\Support\Customers\CustomerMoneyFigures;
use App\Support\Metrics\MetricWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GAP-156 -- the resolver, whose whole job is that the ledger figures cover the SAME ORDER SET as
 * the `Total Spend` printed beside them.
 *
 * If they did not, the two numbers would differ for a reason that has nothing to do with payment,
 * and a merchant comparing them would read an attribution difference as a payment one. Every case
 * below therefore checks the new figure against `Customer::total_spend()` on the same fixture --
 * the shipped answer to "which orders are this customer's" -- rather than against a number this
 * file chose.
 */
final class CustomerMoneyFiguresTest extends TestCase
{
    use RefreshDatabase;

    private const STORE_A = 1;

    private const STORE_B = 2;

    private array $wallets = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::STORE_A => 'figs-a', self::STORE_B => 'figs-b'] as $id => $slug) {
            $owner = User::factory()->create([
                'email' => $slug . '-owner@example.test', 'type' => 'admin',
            ]);

            DB::table('stores')->insert([
                'id' => $id, 'name' => 'Store ' . $id, 'slug' => $slug,
                'email' => $slug . '@example.test', 'theme_id' => 'stylique',
                'created_by' => $owner->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $this->wallets[$id] = DB::table('store_wallets')->insertGetId([
                'store_id' => $id, 'currency_code' => 'USD',
                'available_balance' => 0, 'pending_balance' => 0,
                'withdrawal_pending_balance' => 0, 'total_income' => 0, 'total_withdrawn' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function customer(string $name, int $storeId = self::STORE_A): Customer
    {
        $id = DB::table('customers')->insertGetId([
            'first_name' => $name, 'last_name' => 'Shopper',
            'email' => strtolower($name) . '@example.test',
            'password' => bcrypt('secret'),
            'store_id' => $storeId,
            'created_by' => (int) DB::table('stores')->where('id', $storeId)->value('created_by'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Customer::withoutGlobalScopes()->findOrFail($id);
    }

    /**
     * A RAW WRITE PLUS A CACHE FLUSH, because `Customer` is one of the 79 `Cachable` models and a
     * `DB::table()->update()` leaves Eloquent -- `find()` included -- serving the PRE-write row.
     *
     * This is not defensive tidying. Without the flush the storeless case failed outright, and the
     * empty-e-mail case PASSED FOR THE WRONG REASON: the resolver searched for the customer's
     * cached original address, found no billing row carrying it, and returned empty -- which looks
     * exactly like the empty-e-mail guard working. The premise assertion below is what makes the
     * difference visible.
     */
    private function rewrite(Customer $customer, array $attributes): Customer
    {
        DB::table('customers')->where('id', $customer->id)->update($attributes);
        (new Customer)->flushCache();

        $reloaded = Customer::withoutGlobalScopes()->findOrFail($customer->id);

        foreach ($attributes as $column => $value) {
            $this->assertSame($value, $reloaded->{$column},
                "PREMISE: `{$column}` still reads as its pre-write value, so this case is "
                . 'measuring the cached row rather than the one it wrote.');
        }

        return $reloaded;
    }

    private function order(int $customerId, array $attributes = []): int
    {
        return DB::table('orders')->insertGetId(array_merge([
            'product_order_id' => 'ORD-' . uniqid('', true),
            'product_json' => json_encode([]),
            'product_price' => 0.0, 'delivery_price' => 0.0, 'tax_price' => 0.0,
            'coupon_price' => 0.0, 'final_price' => 100.0,
            'currency_code' => 'USD',
            'payment_type' => 'cod', 'payment_status' => 'Unpaid',
            'customer_id' => $customerId,
            'store_id' => self::STORE_A,
            'order_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    private function ledger(int $orderId, array $attributes = []): void
    {
        $storeId = (int) ($attributes['store_id'] ?? self::STORE_A);

        DB::table('store_order_financials')->insert(array_merge([
            'store_id' => $storeId, 'wallet_id' => $this->wallets[$storeId],
            'order_id' => $orderId, 'currency_code' => 'USD',
            'product_amount' => 100.0, 'discount_amount' => 0.0,
            'tax_amount' => 0.0, 'shipping_amount' => 0.0, 'customer_total' => 100.0,
            'product_cost' => 40.0, 'gross_profit' => 60.0,
            'payment_status' => 'unpaid', 'paid_amount' => 0.0, 'remaining_amount' => 100.0,
            'paid_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    private function billing(int $orderId, string $email): void
    {
        DB::table('order_billing_details')->insert([
            'order_id' => $orderId, 'first_name' => 'Guest', 'last_name' => 'Shopper',
            'email' => $email, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------- limb 1: direct

    public function test_a_customer_with_their_own_orders_is_read_by_customer_id(): void
    {
        $alice = $this->customer('Alice');
        $order = $this->order((int) $alice->id);
        $this->ledger($order, ['payment_status' => 'paid', 'paid_amount' => 100.0, 'paid_at' => now()]);

        $figures = CustomerMoneyFigures::forCustomer($alice);

        $this->assertSame('customer_id', $figures['attribution']);
        $this->assertSame(['USD' => 100.0], $figures['collected']->byCurrency());
    }

    // ------------------------------------------------------------- limb 2: the guest fallback

    /**
     * THE CASE THIS RESOLVER EXISTS FOR. A shopper who only ever checked out as a guest has NO
     * order carrying their customer id, so a figure keyed on `customer_id` reads empty -- while
     * the `Total Spend` beside it, which follows `ownStoreOrders()`, shows their real orders. The
     * merchant would read that gap as cancelled money. It is attribution, not payment.
     */
    public function test_a_guest_only_customer_is_read_through_their_billing_email(): void
    {
        $bob = $this->customer('Bob');

        $guestOrder = $this->order(0, ['final_price' => 60.0]);
        $this->billing($guestOrder, (string) $bob->email);
        $this->ledger($guestOrder, [
            'payment_status' => 'paid', 'paid_amount' => 60.0, 'paid_at' => now(),
        ]);

        $figures = CustomerMoneyFigures::forCustomer($bob);

        $this->assertSame('billing_email', $figures['attribution'],
            'The resolver fell back to the customer id for a shopper who has no orders under it.');

        $this->assertSame(['USD' => 60.0], $figures['collected']->byCurrency(),
            'A guest-only shopper\'s collected payments read empty, which beside a populated '
            . 'Total Spend reads to a merchant as money that was cancelled.');
    }

    /**
     * AND THE FIGURE COVERS THE SAME ORDERS THE SHIPPED FIGURE DOES. This is the assertion that
     * makes the whole class worth having: not "the number is 60" but "the number is about the same
     * orders as the number next to it".
     */
    public function test_the_ledger_figure_covers_the_same_orders_as_total_spend(): void
    {
        $bob = $this->customer('Bob');

        foreach ([60.0, 40.0] as $amount) {
            $guestOrder = $this->order(0, ['final_price' => $amount]);
            $this->billing($guestOrder, (string) $bob->email);
            $this->ledger($guestOrder, [
                'payment_status' => 'paid', 'paid_amount' => $amount, 'paid_at' => now(),
            ]);
        }

        $this->assertSame(100.0, (float) $bob->total_spend(),
            'PREMISE: the shipped figure does not see these orders, so the comparison below would '
            . 'pass for the wrong reason.');

        $this->assertSame(
            ['USD' => 100.0],
            CustomerMoneyFigures::forCustomer($bob)['collected']->byCurrency(),
            'The ledger figure and the shipped Total Spend cover different ORDER SETS, so the two '
            . 'numbers on screen would differ for a reason that is not about payment.'
        );
    }

    /**
     * THE FALLBACK IS A FALLBACK, exactly as `ownStoreOrders()` has it: one direct order wins over
     * any number of e-mail matches. Whether that is the ideal rule is a product question; this
     * pins that the ledger figures apply the SAME rule rather than a second one.
     */
    public function test_one_direct_order_beats_every_email_match(): void
    {
        $bob = $this->customer('Bob');

        $direct = $this->order((int) $bob->id, ['final_price' => 5.0]);
        $this->ledger($direct, ['payment_status' => 'paid', 'paid_amount' => 5.0, 'paid_at' => now()]);

        $guestOrder = $this->order(0, ['final_price' => 900.0]);
        $this->billing($guestOrder, (string) $bob->email);
        $this->ledger($guestOrder, [
            'payment_status' => 'paid', 'paid_amount' => 900.0, 'paid_at' => now(),
        ]);

        $figures = CustomerMoneyFigures::forCustomer($bob);

        $this->assertSame('customer_id', $figures['attribution']);
        $this->assertSame(['USD' => 5.0], $figures['collected']->byCurrency(),
            'The resolver applied BOTH limbs. `ownStoreOrders()` takes the direct orders when any '
            . 'exist, and the ledger figure must not cover a wider set than the figure beside it.');
    }

    public function test_a_customer_with_no_email_matches_nothing_rather_than_everything(): void
    {
        $nameless = $this->rewrite($this->customer('Nameless'), ['email' => '']);

        $guestOrder = $this->order(0, ['final_price' => 400.0]);
        $this->billing($guestOrder, '');
        $this->ledger($guestOrder, [
            'payment_status' => 'paid', 'paid_amount' => 400.0, 'paid_at' => now(),
        ]);

        /*
         * THE PREMISE REVIEW FOUND MISSING, AND IT CHANGED THE OUTCOME. This fixture used to prove
         * only that the RESOLVER returns nothing -- while `total_spend()` on the same rows
         * returned 400.00, because `ownStoreOrders()` matched `where email = ''` against every
         * anonymous order in the store. A 400-vs-nothing disagreement, sitting inside the test
         * meant to prove the two agree.
         *
         * `Customer::ownStoreOrders()` now carries the same guard, so this assertion is the one
         * that keeps the two halves honest.
         */
        $this->assertSame(0.0, (float) $nameless->total_spend(),
            'The shipped figure credits a blank-e-mail customer with the store\'s anonymous '
            . 'orders, so it and the ledger figure are about different order sets.');

        $figures = CustomerMoneyFigures::forCustomer($nameless);

        $this->assertSame('none', $figures['attribution']);
        $this->assertSame([], $figures['collected']->byCurrency(),
            'An empty e-mail matched unattributed orders, so one blank-e-mail customer would be '
            . 'credited with every guest order in the store.');
    }

    // ------------------------------------------------------------- tenancy

    public function test_the_guest_fallback_does_not_reach_another_stores_orders(): void
    {
        $bob = $this->customer('Bob');

        $theirs = $this->order(0, ['store_id' => self::STORE_B, 'final_price' => 500.0]);
        $this->billing($theirs, (string) $bob->email);
        $this->ledger($theirs, [
            'store_id' => self::STORE_B, 'payment_status' => 'paid',
            'paid_amount' => 500.0, 'paid_at' => now(),
        ]);

        $figures = CustomerMoneyFigures::forCustomer($bob);

        $this->assertSame([], $figures['collected']->byCurrency(),
            'The billing-e-mail match crossed a tenant boundary. The e-mail table has no store_id, '
            . 'so the predicate has to come from the order.');

        /*
         * AND THE ATTRIBUTION ITSELF, not only the money -- added after a SURVIVING mutation.
         *
         * Dropping the store predicate from the e-mail match left the money assertion green,
         * because `collectedForOrders` scopes AGAIN at the metric and refuses the foreign row.
         * That is defence in depth working, and it is exactly why asserting only the amount is not
         * enough: the resolver would still have claimed this shopper was attributed by billing
         * e-mail and counted another tenant's order toward `orders_attributed`. A merchant would
         * see "attributed by e-mail, 1 order" beside 0.00 and have no way to tell that the one
         * order was somebody else's.
         */
        $this->assertSame('none', $figures['attribution'],
            'The resolver matched a foreign store\'s order by e-mail. The money was still refused '
            . 'downstream, so only the attribution reveals it.');

        $this->assertSame(0, $figures['collected']->notes()['orders_attributed'] ?? null,
            'Another tenant\'s order was counted toward this customer\'s attributed order count.');
    }

    public function test_a_customer_with_no_store_yields_no_figure_rather_than_zero(): void
    {
        $orphan = $this->rewrite($this->customer('Orphan'), ['store_id' => null]);

        $figures = CustomerMoneyFigures::forCustomer($orphan);

        $this->assertSame('unresolvable', $figures['attribution']);
        $this->assertNull(CustomerMoneyFigures::comparable($figures['collected']),
            'A storeless customer produced a comparable number, so a filter would select them.');
    }

    // ------------------------------------------------------------- the bulk path

    public function test_the_bulk_path_agrees_with_the_single_path_for_both_limbs(): void
    {
        $alice = $this->customer('Alice');
        $direct = $this->order((int) $alice->id);
        $this->ledger($direct, ['payment_status' => 'paid', 'paid_amount' => 10.0, 'paid_at' => now()]);

        $bob = $this->customer('Bob');
        $guestOrder = $this->order(0, ['final_price' => 20.0]);
        $this->billing($guestOrder, (string) $bob->email);
        $this->ledger($guestOrder, [
            'payment_status' => 'paid', 'paid_amount' => 20.0, 'paid_at' => now(),
        ]);

        $bulk = CustomerMoneyFigures::forCustomers([$alice, $bob]);

        foreach ([$alice, $bob] as $customer) {
            $single = CustomerMoneyFigures::forCustomer($customer);

            $this->assertSame($single['attribution'], $bulk[$customer->id]['attribution'],
                "The page and the single read disagree about how customer {$customer->id} is "
                . 'attributed.');

            $this->assertSame(
                $single['collected']->byCurrency(),
                $bulk[$customer->id]['collected']->byCurrency(),
                "The page and the single read disagree about customer {$customer->id}'s money."
            );
        }
    }

    /**
     * A PAGE CAN SPAN STORES FOR THE OPERATOR, and a `MetricScope` is a tenant predicate rather
     * than a filter. One scope over every listed store would read every listed store's ledger for
     * every listed customer and lean on the customer id to sort it out.
     */
    public function test_a_page_spanning_two_stores_keeps_each_customers_money_in_their_own_store(): void
    {
        $alice = $this->customer('Alice', self::STORE_A);
        $mine = $this->order((int) $alice->id);
        $this->ledger($mine, ['payment_status' => 'paid', 'paid_amount' => 11.0, 'paid_at' => now()]);

        $rival = $this->customer('Rival', self::STORE_B);
        $theirs = $this->order((int) $rival->id, ['store_id' => self::STORE_B]);
        $this->ledger($theirs, [
            'store_id' => self::STORE_B, 'payment_status' => 'paid',
            'paid_amount' => 22.0, 'paid_at' => now(),
        ]);

        $bulk = CustomerMoneyFigures::forCustomers([$alice, $rival]);

        $this->assertSame(['USD' => 11.0], $bulk[$alice->id]['collected']->byCurrency());
        $this->assertSame(['USD' => 22.0], $bulk[$rival->id]['collected']->byCurrency());
    }

    /**
     * F6: THE PAGE FIXTURE THAT ACTUALLY BITES. The two-store case above passes even with one
     * multi-store scope, because each customer's id appears only on their own store's orders --
     * the GROUP BY separates them either way. Only a customer id that COLLIDES across stores can
     * tell per-store scoping from a single wide scope.
     */
    public function test_a_customer_id_colliding_across_stores_is_not_merged_on_one_page(): void
    {
        $alice = $this->customer('Alice', self::STORE_A);

        $mine = $this->order((int) $alice->id);
        $this->ledger($mine, ['payment_status' => 'paid', 'paid_amount' => 11.0, 'paid_at' => now()]);

        // Another store's order carrying ALICE's customer id. Nothing in the schema forbids it:
        // `orders.customer_id` has no composite key with `store_id`.
        $collision = $this->order((int) $alice->id, ['store_id' => self::STORE_B]);
        $this->ledger($collision, [
            'store_id' => self::STORE_B, 'payment_status' => 'paid',
            'paid_amount' => 999.0, 'paid_at' => now(),
        ]);

        $rival = $this->customer('Rival', self::STORE_B);
        $theirs = $this->order((int) $rival->id, ['store_id' => self::STORE_B]);
        $this->ledger($theirs, [
            'store_id' => self::STORE_B, 'payment_status' => 'paid',
            'paid_amount' => 22.0, 'paid_at' => now(),
        ]);

        $bulk = CustomerMoneyFigures::forCustomers([$alice, $rival]);

        $this->assertSame(['USD' => 11.0], $bulk[$alice->id]['collected']->byCurrency(),
            'Store B\'s order carrying Alice\'s customer id was merged into her figure, which is '
            . 'what one scope spanning both stores would do: the grouping has no store dimension '
            . 'to separate them by.');

        $this->assertSame(['USD' => 22.0], $bulk[$rival->id]['collected']->byCurrency());
    }

    /**
     * F6: TWO DIRECT-LIMB CUSTOMERS IN ONE STORE. Without this, `$direct[$storeId][] = $id` could
     * become `= [$id]` and every test still passes -- while all but the last customer per store
     * vanish from the returned map, which a caller papers over with `?? 0` and turns "no record"
     * into "spent nothing".
     */
    public function test_every_direct_customer_on_a_page_gets_their_own_figure(): void
    {
        $alice = $this->customer('Alice');
        $bob = $this->customer('Bob');

        foreach ([[$alice, 10.0], [$bob, 20.0]] as [$customer, $amount]) {
            $order = $this->order((int) $customer->id);
            $this->ledger($order, [
                'payment_status' => 'paid', 'paid_amount' => $amount, 'paid_at' => now(),
            ]);
        }

        $bulk = CustomerMoneyFigures::forCustomers([$alice, $bob]);

        $this->assertArrayHasKey($alice->id, $bulk, 'The first customer fell out of the page.');
        $this->assertArrayHasKey($bob->id, $bulk, 'The second customer fell out of the page.');

        $this->assertSame(['USD' => 10.0], $bulk[$alice->id]['collected']->byCurrency());
        $this->assertSame(['USD' => 20.0], $bulk[$bob->id]['collected']->byCurrency());
    }

    /**
     * The direct probe is store-scoped. Without that predicate a customer whose id appears on
     * ANOTHER store's order takes the direct limb, reports nothing, and diverges from
     * `ownStoreOrders()` -- which would take the guest limb and show real money.
     */
    public function test_an_order_in_another_store_does_not_make_the_direct_limb_fire(): void
    {
        $bob = $this->customer('Bob');

        $elsewhere = $this->order((int) $bob->id, ['store_id' => self::STORE_B]);
        $this->ledger($elsewhere, [
            'store_id' => self::STORE_B, 'payment_status' => 'paid',
            'paid_amount' => 500.0, 'paid_at' => now(),
        ]);

        $guestOrder = $this->order(0, ['final_price' => 30.0]);
        $this->billing($guestOrder, (string) $bob->email);
        $this->ledger($guestOrder, [
            'payment_status' => 'paid', 'paid_amount' => 30.0, 'paid_at' => now(),
        ]);

        $figures = CustomerMoneyFigures::forCustomer($bob);

        $this->assertSame('billing_email', $figures['attribution'],
            'The direct probe saw another store\'s order, so the resolver took the direct limb '
            . 'and reported nothing while the shipped figure takes the guest limb and shows 30.');

        $this->assertSame(['USD' => 30.0], $figures['collected']->byCurrency());
        $this->assertSame(30.0, (float) $bob->total_spend(), 'PREMISE: the two limbs disagree.');
    }

    /** The bulk path has its own storeless branch, and only the single path had a case. */
    public function test_the_bulk_path_handles_a_storeless_customer(): void
    {
        $alice = $this->customer('Alice');
        $order = $this->order((int) $alice->id);
        $this->ledger($order, ['payment_status' => 'paid', 'paid_amount' => 9.0, 'paid_at' => now()]);

        $orphan = $this->rewrite($this->customer('Orphan'), ['store_id' => null]);

        $bulk = CustomerMoneyFigures::forCustomers([$alice, $orphan]);

        $this->assertSame('unresolvable', $bulk[$orphan->id]['attribution']);
        $this->assertTrue($bulk[$orphan->id]['collected']->notes()['scope_unresolved'] ?? false,
            'A figure with no tenant did not say so, so its scope string names store 1 -- a real '
            . 'tenant that was never read.');

        $this->assertSame(['USD' => 9.0], $bulk[$alice->id]['collected']->byCurrency(),
            'One unresolvable customer disturbed the rest of the page.');
    }

    /** The bulk path returns BOTH figures; only `collected` was ever compared. */
    public function test_the_bulk_path_returns_the_retained_figure_too(): void
    {
        $alice = $this->customer('Alice');
        $order = $this->order((int) $alice->id);
        $this->ledger($order, ['payment_status' => 'paid', 'paid_amount' => 15.0, 'paid_at' => now()]);

        DB::table('store_wallet_transactions')->insert([
            'store_id' => self::STORE_A, 'wallet_id' => $this->wallets[self::STORE_A],
            'currency_code' => 'USD', 'type' => 'order_paid', 'direction' => 'credit',
            'amount' => 15.0, 'balance_before' => 0, 'balance_after' => 15.0,
            'reference_type' => 'order', 'reference_id' => $order, 'status' => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $bulk = CustomerMoneyFigures::forCustomers([$alice]);

        $this->assertSame(['USD' => 15.0], $bulk[$alice->id]['retained']->byCurrency());
    }

    // ------------------------------------------------------------- the comparable scalar

    public function test_a_customer_with_nothing_compares_as_null_not_zero(): void
    {
        $alice = $this->customer('Alice');

        $this->assertNull(
            CustomerMoneyFigures::comparable(CustomerMoneyFigures::forCustomer($alice)['collected']),
            'A customer with no ledger money compared as 0.0, so "Total Spend less than 10" would '
            . 'select them -- a segment built out of an absence.'
        );
    }

    public function test_money_in_two_currencies_compares_as_null_rather_than_a_total(): void
    {
        $alice = $this->customer('Alice');

        $usd = $this->order((int) $alice->id);
        $this->ledger($usd, ['payment_status' => 'paid', 'paid_amount' => 100.0, 'paid_at' => now()]);

        $syp = $this->order((int) $alice->id, ['currency_code' => 'SYP']);
        $this->ledger($syp, [
            'currency_code' => 'SYP', 'payment_status' => 'paid',
            'paid_amount' => 250.0, 'paid_at' => now(),
        ]);

        $this->assertNull(
            CustomerMoneyFigures::comparable(CustomerMoneyFigures::forCustomer($alice)['collected']),
            'Two currencies were added into one comparable number, which is money of no currency.'
        );
    }

    public function test_a_single_currency_figure_compares_as_its_own_amount(): void
    {
        $alice = $this->customer('Alice');
        $order = $this->order((int) $alice->id);
        $this->ledger($order, ['payment_status' => 'paid', 'paid_amount' => 42.5, 'paid_at' => now()]);

        $this->assertSame(42.5,
            CustomerMoneyFigures::comparable(CustomerMoneyFigures::forCustomer($alice)['collected']));
    }

    // ------------------------------------------------------------- the distinction, end to end

    public function test_a_guest_customers_cancelled_payment_is_collected_but_not_retained(): void
    {
        $bob = $this->customer('Bob');

        $guestOrder = $this->order(0, ['final_price' => 80.0]);
        $this->billing($guestOrder, (string) $bob->email);
        $this->ledger($guestOrder, [
            'payment_status' => 'cancelled', 'paid_amount' => 80.0, 'paid_at' => now(),
        ]);

        DB::table('store_wallet_transactions')->insert([
            ['store_id' => self::STORE_A, 'wallet_id' => $this->wallets[self::STORE_A],
             'currency_code' => 'USD', 'type' => 'order_paid', 'direction' => 'credit',
             'amount' => 80.0, 'balance_before' => 0, 'balance_after' => 80.0,
             'reference_type' => 'order', 'reference_id' => $guestOrder, 'status' => 'completed',
             'created_at' => now(), 'updated_at' => now()],
            ['store_id' => self::STORE_A, 'wallet_id' => $this->wallets[self::STORE_A],
             'currency_code' => 'USD', 'type' => 'order_cancelled', 'direction' => 'debit',
             'amount' => 80.0, 'balance_before' => 80.0, 'balance_after' => 0,
             'reference_type' => 'order', 'reference_id' => $guestOrder, 'status' => 'completed',
             'created_at' => now(), 'updated_at' => now()],
        ]);

        $figures = CustomerMoneyFigures::forCustomer($bob);

        $this->assertSame(['USD' => 80.0], $figures['collected']->byCurrency(),
            'The shopper really paid; cancellation does not un-make that.');

        $this->assertSame(['USD' => 0.0], $figures['retained']->byCurrency(),
            'The merchant did not keep it, and the retained figure must say so -- through the '
            . 'order-set path as well as the customer-id one.');
    }

    // ------------------------------------------------------------- the window

    public function test_the_window_reaches_the_order_set_path(): void
    {
        $bob = $this->customer('Bob');

        foreach ([['old', 30, 70.0], ['new', 1, 5.0]] as [$label, $daysAgo, $amount]) {
            $guestOrder = $this->order(0, ['final_price' => $amount]);
            $this->billing($guestOrder, (string) $bob->email);
            $this->ledger($guestOrder, [
                'payment_status' => 'paid', 'paid_amount' => $amount,
                'paid_at' => now()->subDays($daysAgo),
            ]);
        }

        $figures = CustomerMoneyFigures::forCustomer(
            $bob, MetricWindow::between(now()->subDays(7), now())
        );

        $this->assertSame(['USD' => 5.0], $figures['collected']->byCurrency(),
            'The window never reached the order-set path, so a bounded period silently returned '
            . 'all time for every guest-attributed customer.');
    }
}
