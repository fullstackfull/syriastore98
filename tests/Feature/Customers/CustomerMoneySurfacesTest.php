<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\User;
use App\Support\Customers\CustomerListFilter;
use App\Support\Customers\CustomerMoneyFigures;
use App\Support\Customers\CustomerMoneyTiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StripsSourceComments;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GAP-156 -- THE FOUR SURFACES, and the filter, which is the half that decides what a merchant
 * never sees.
 *
 * IT SAID THREE UNTIL THE CENSUS WAS RE-RUN. The fourth is `customer/filter.blade.php`, served by
 * `CustomerController::CustomFilterData()` at `GET /customer-filter-data` -- a SECOND copy of the
 * customer list that printed `total_spend` as a bare unlabelled money cell with neither ledger
 * figure beside it. The duplication is why it was missed, and this same pair had already produced
 * a defect for the same reason (P2.5-R-06: three numeric filter faults, in both copies).
 *
 * `CustomerLedgerMoneyTest` and `CustomerMoneyFiguresTest` establish that the figures are right.
 * This file is about what is DONE with them, and the owner's instruction names the two failures
 * worth guarding against by hand:
 *
 *   "Never silently label either one as the other."  -- so the two figures must stay apart and
 *   `Total Spend` must stop reading as money received.
 *
 *   "Verify both what the UI shows AND which customers each financial filter selects."  -- so a
 *   display assertion alone is not evidence. A list that prints one number and selects on another
 *   builds a segment from a rule the screen does not state, and the merchant cannot see it,
 *   because what is wrong with the list is the rows that are NOT in it.
 */
final class CustomerMoneySurfacesTest extends TestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private const STORE_A = 1;

    private const STORE_B = 2;

    /** @var array<int, int> */
    private array $wallets = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::STORE_A => 'surf-a', self::STORE_B => 'surf-b'] as $id => $slug) {
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

    private function storeCurrency(int $storeId, string $code): void
    {
        DB::table('settings')->insert([
            'store_id' => $storeId, 'name' => 'defult_currancy', 'value' => $code,
            'created_by' => (int) DB::table('stores')->where('id', $storeId)->value('created_by'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
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

    private function movement(int $orderId, string $direction, float $amount, int $storeId = self::STORE_A): void
    {
        DB::table('store_wallet_transactions')->insert([
            'store_id' => $storeId, 'wallet_id' => $this->wallets[$storeId],
            'reference_type' => 'order', 'reference_id' => $orderId,
            'direction' => $direction, 'amount' => $amount, 'currency_code' => 'USD',
            'status' => 'completed', 'type' => $direction === 'credit' ? 'sale' : 'refund',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A paid order plus its financial row, which is the ordinary case. */
    private function paidOrder(int $customerId, float $amount, array $ledger = []): int
    {
        $orderId = $this->order($customerId, ['final_price' => $amount]);

        $this->ledger($orderId, array_merge([
            'payment_status' => 'paid', 'paid_amount' => $amount,
            'customer_total' => $amount, 'remaining_amount' => 0.0,
            'paid_at' => now(),
        ], $ledger));

        return $orderId;
    }

    // ------------------------------------------------------------------ the tiles

    public function test_a_collected_payment_is_shown_in_the_customers_own_currency(): void
    {
        $this->storeCurrency(self::STORE_A, 'USD');
        $customer = $this->customer('Mona');
        $this->paidOrder($customer->id, 250.0);

        $tiles = CustomerMoneyTiles::forCustomer($customer);

        $this->assertNotNull($tiles['collected']['value']);
        $this->assertStringContainsString('250', $tiles['collected']['value']);
        $this->assertStringContainsString('USD', $tiles['collected']['value']);
    }

    public function test_the_two_figures_are_different_numbers_when_something_was_refunded(): void
    {
        /*
         * THE OWNER'S DISTINCTION, AS ONE FIXTURE. The payment really happened and the reversal
         * really happened. Collected must still show the money that was handed over; retained must
         * not. A surface that showed one number here would be labelling one of them as the other,
         * which is the thing the instruction forbids by name.
         */
        $this->storeCurrency(self::STORE_A, 'USD');
        $customer = $this->customer('Rania');
        $orderId = $this->paidOrder($customer->id, 300.0, ['payment_status' => 'refunded']);

        DB::table('store_wallet_transactions')->insert([
            'store_id' => self::STORE_A, 'wallet_id' => $this->wallets[self::STORE_A],
            'reference_type' => 'order', 'reference_id' => $orderId,
            'direction' => 'credit', 'amount' => 300.0, 'currency_code' => 'USD',
            'status' => 'completed', 'type' => 'sale',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('store_wallet_transactions')->insert([
            'store_id' => self::STORE_A, 'wallet_id' => $this->wallets[self::STORE_A],
            'reference_type' => 'order', 'reference_id' => $orderId,
            'direction' => 'debit', 'amount' => 120.0, 'currency_code' => 'USD',
            'status' => 'completed', 'type' => 'refund',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $tiles = CustomerMoneyTiles::forCustomer($customer);

        $this->assertStringContainsString('300', $tiles['collected']['value'],
            'A refunded payment is still money that was collected; §15 includes it.');
        $this->assertStringContainsString('180', $tiles['retained']['value'],
            '300 credited less 120 debited. Retained is net of the completed reversal.');
        $this->assertNotSame($tiles['collected']['value'], $tiles['retained']['value'],
            'The two figures must not render as one number on a fixture where they differ.');
    }

    public function test_an_unpaid_order_reads_as_zero_rather_than_as_unavailable(): void
    {
        /* The record COVERS this order and says nothing was paid. That is a real zero and must be
           shown as one -- withholding it would be as misleading as inventing a number. */
        $this->storeCurrency(self::STORE_A, 'USD');
        $customer = $this->customer('Samir');
        $orderId = $this->order($customer->id, ['final_price' => 90.0]);
        $this->ledger($orderId);

        $tiles = CustomerMoneyTiles::forCustomer($customer);

        $this->assertNotNull($tiles['collected']['value']);
        $this->assertStringContainsString('0.00', $tiles['collected']['value']);
    }

    public function test_an_order_the_record_cannot_see_withholds_the_figure_and_says_how_many(): void
    {
        /*
         * THE FAILURE MODE §15 NAMES. `store_order_financials` was never backfilled, so a
         * long-standing shopper can have orders no financial row covers. Printing 0.00 would state
         * that they have never paid. The tile must refuse and say why.
         */
        $this->storeCurrency(self::STORE_A, 'USD');
        $customer = $this->customer('Layla');
        $this->paidOrder($customer->id, 400.0);
        $this->order($customer->id, ['final_price' => 75.0]); // no ledger row at all

        $tiles = CustomerMoneyTiles::forCustomer($customer);

        $this->assertNull($tiles['collected']['value'],
            'One uncovered order makes the rest a floor, not the figure.');
        $this->assertNull($tiles['retained']['value'],
            'The retained figure covers the same order set and is equally unreadable.');
        $this->assertStringContainsString('1', $tiles['collected']['hint']);
        $this->assertStringContainsString('no financial record', $tiles['collected']['hint']);
    }

    public function test_two_currencies_are_listed_and_never_added(): void
    {
        $this->storeCurrency(self::STORE_A, 'USD');
        $customer = $this->customer('Nadia');
        $this->paidOrder($customer->id, 100.0, ['currency_code' => 'USD']);
        $this->paidOrder($customer->id, 200.0, ['currency_code' => 'EUR']);

        $tiles = CustomerMoneyTiles::forCustomer($customer);

        $this->assertNull($tiles['collected']['value'],
            'Adding 100 USD to 200 EUR would invent an exchange rate.');
        $this->assertStringContainsString('USD', $tiles['collected']['hint']);
        $this->assertStringContainsString('EUR', $tiles['collected']['hint']);
        $this->assertStringNotContainsString('300', $tiles['collected']['value'] ?? '');
    }

    public function test_a_customer_with_no_store_is_refused_rather_than_read_from_store_one(): void
    {
        $customer = $this->customer('Orphan');
        DB::table('customers')->where('id', $customer->id)->update(['store_id' => null]);
        (new Customer)->flushCache();
        $reloaded = Customer::withoutGlobalScopes()->findOrFail($customer->id);

        $this->assertNull($reloaded->store_id, 'PREMISE: the cached row would hide this.');

        $tiles = CustomerMoneyTiles::forCustomer($reloaded);

        $this->assertSame('unresolvable', $tiles['attribution']);
        $this->assertNull($tiles['collected']['value']);
        $this->assertNull($tiles['retained']['value']);
    }

    public function test_total_spend_now_carries_the_line_saying_it_is_not_money_received(): void
    {
        $hint = CustomerMoneyTiles::spendHint();

        $this->assertStringContainsString('not money received', $hint);
        foreach (['Cancelled', 'refunded', 'unpaid'] as $word) {
            $this->assertStringContainsString($word, $hint,
                "The caveat must name {$word} orders explicitly: each counts in full.");
        }
    }

    public function test_the_currency_is_the_customers_store_and_not_the_viewers(): void
    {
        /*
         * THE IDENTITY BUG THIS SLICE FIXES, isolated. The timeline formatted with
         * `getCurrentStore()` while summing from the customer's own store, so a store-B shopper's
         * money was printed with store A's currency. The amount was right and the currency was a
         * fabrication -- the worse of the two, because a right number in the wrong currency does
         * not invite doubt.
         */
        $this->storeCurrency(self::STORE_A, 'USD');
        $this->storeCurrency(self::STORE_B, 'EUR');

        $customer = $this->customer('Farid', self::STORE_B);
        $orderId = $this->order($customer->id, [
            'store_id' => self::STORE_B, 'final_price' => 60.0, 'currency_code' => null,
        ]);
        $this->ledger($orderId, [
            'store_id' => self::STORE_B, 'payment_status' => 'paid', 'paid_amount' => 60.0,
            'customer_total' => 60.0, 'remaining_amount' => 0.0, 'paid_at' => now(),
            'currency_code' => null,
        ]);

        $tiles = CustomerMoneyTiles::forCustomer($customer);

        $this->assertStringContainsString('EUR', $tiles['collected']['value'],
            "An unmarked row is labelled with the CUSTOMER's store currency, not the viewer's.");
        $this->assertStringNotContainsString('USD', $tiles['collected']['value']);
    }

    public function test_a_payment_the_wallet_never_recorded_withholds_the_retained_figure(): void
    {
        /*
         * FOUND BY A TEST OF MINE FAILING FOR THE RIGHT REASON. The first draft asserted that a
         * paid order yields a retained figure; it yielded 0.00, because the two figures read two
         * different tables and this fixture had written only one of them. The product, not the
         * test, was wrong: "collected 125.00, retained 0.00" reads as "every penny was refunded",
         * which is a claim about this customer that nothing in the data supports.
         *
         * It is not an artificial fixture either. `syncFromOrder()`'s early return blocks a
         * late-collection wallet credit (AUD-3), so a real store can hold a paid financial row
         * with no movement behind it.
         */
        $this->storeCurrency(self::STORE_A, 'USD');
        $customer = $this->customer('Unsynced');
        $this->paidOrder($customer->id, 125.0); // financial row, no wallet movement

        $tiles = CustomerMoneyTiles::forCustomer($customer);

        $this->assertStringContainsString('125', $tiles['collected']['value'],
            'The collected side is readable and must still be shown.');
        $this->assertNull($tiles['retained']['value'],
            'The wallet recorded nothing, so what was kept cannot be worked out.');
        $this->assertStringContainsString('no matching movement', $tiles['retained']['hint']);
    }

    public function test_a_customer_refunded_to_nothing_still_reads_as_a_real_zero(): void
    {
        /*
         * THE DISCRIMINATOR for the rule above, and the reason it counts ROWS rather than looking
         * at the amount. This customer's retained figure is 0.00 too -- but it got there by a
         * credit and an equal debit, both recorded. That is a fact about them and must be shown.
         * A rule written against the amount would hide this one and the two cases are opposite.
         */
        $this->storeCurrency(self::STORE_A, 'USD');
        $customer = $this->customer('Returned');
        $orderId = $this->paidOrder($customer->id, 200.0, ['payment_status' => 'refunded']);
        $this->movement($orderId, 'credit', 200.0);
        $this->movement($orderId, 'debit', 200.0);

        $tiles = CustomerMoneyTiles::forCustomer($customer);

        $this->assertStringContainsString('200', $tiles['collected']['value']);
        $this->assertNotNull($tiles['retained']['value'],
            'Recorded movements that net to nothing are a real zero, not an absence.');
        $this->assertStringContainsString('0.00', $tiles['retained']['value']);
    }

    // ------------------------------------------------------- which customers the filter selects

    /** @return list<string> the first names the filter returns, sorted */
    private function filtered(string $field, string $operator, string $value): array
    {
        $names = CustomerListFilter::apply(
            Customer::withoutGlobalScopes()->where('store_id', self::STORE_A),
            $field,
            $operator,
            $value,
        )->pluck('first_name')->all();

        sort($names);

        return $names;
    }

    public function test_the_collected_filter_selects_on_the_figure_the_column_prints(): void
    {
        $this->storeCurrency(self::STORE_A, 'USD');

        $big = $this->customer('Big');
        $this->paidOrder($big->id, 500.0);

        $small = $this->customer('Small');
        $this->paidOrder($small->id, 50.0);

        $this->assertSame(['Small'], $this->filtered('Payments Collected', 'Less Than', '100'));
        $this->assertSame(['Big'], $this->filtered('Payments Collected', 'More Than', '100'));
    }

    public function test_a_customer_who_has_paid_nothing_is_still_returned_by_less_than(): void
    {
        /*
         * THE DECISION THAT WOULD HAVE BEEN A SILENT REGRESSION. `comparable()` answers null for
         * an empty ledger and `applyNumeric` skips a null for EVERY operator, so the obvious
         * implementation would have dropped exactly the customers a re-engagement segment is
         * looking for -- and an absence from a list is the hardest thing in a product to notice.
         *
         * `filterValue()` separates "the record covers these orders and the answer is nothing"
         * from "the record cannot see these orders". This customer is the first kind.
         */
        $this->storeCurrency(self::STORE_A, 'USD');

        $never = $this->customer('Never');
        $orderId = $this->order($never->id, ['final_price' => 80.0]);
        $this->ledger($orderId); // covered, unpaid

        $this->assertSame(['Never'], $this->filtered('Payments Collected', 'Less Than', '100'));
    }

    public function test_a_customer_with_no_orders_at_all_is_returned_by_less_than(): void
    {
        $this->storeCurrency(self::STORE_A, 'USD');
        $this->customer('Browser');

        $this->assertSame(['Browser'], $this->filtered('Payments Collected', 'Less Than', '100'));
    }

    public function test_a_customer_whose_orders_the_record_cannot_see_is_excluded_both_ways(): void
    {
        /* Unknown is not zero and not large. Including them in either direction would assert a
           figure nobody has. */
        $this->storeCurrency(self::STORE_A, 'USD');

        $hidden = $this->customer('Hidden');
        $this->order($hidden->id, ['final_price' => 900.0]); // no ledger row

        $this->assertSame([], $this->filtered('Payments Collected', 'Less Than', '100'));
        $this->assertSame([], $this->filtered('Payments Collected', 'More Than', '100'));
    }

    public function test_the_net_retained_filter_selects_on_the_retained_figure_not_the_collected_one(): void
    {
        $this->storeCurrency(self::STORE_A, 'USD');

        $refunded = $this->customer('Refunded');
        $orderId = $this->paidOrder($refunded->id, 500.0, ['payment_status' => 'refunded']);

        foreach ([['credit', 500.0], ['debit', 450.0]] as [$direction, $amount]) {
            DB::table('store_wallet_transactions')->insert([
                'store_id' => self::STORE_A, 'wallet_id' => $this->wallets[self::STORE_A],
                'reference_type' => 'order', 'reference_id' => $orderId,
                'direction' => $direction, 'amount' => $amount, 'currency_code' => 'USD',
                'status' => 'completed', 'type' => $direction === 'credit' ? 'sale' : 'refund',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Collected is 500, retained is 50. The two filters must disagree on this customer --
        // that disagreement IS the feature.
        $this->assertSame([], $this->filtered('Payments Collected', 'Less Than', '100'));
        $this->assertSame(['Refunded'], $this->filtered('Net Retained', 'Less Than', '100'));
    }

    public function test_the_retained_filter_refuses_a_customer_whose_wallet_recorded_nothing(): void
    {
        /* The filter must refuse exactly what the tile refuses. If it selected here, the list
           would build a segment on a number it declines to print. */
        $this->storeCurrency(self::STORE_A, 'USD');

        $unsynced = $this->customer('Unsynced');
        $this->paidOrder($unsynced->id, 125.0); // no wallet movement

        $this->assertSame([], $this->filtered('Net Retained', 'Less Than', '1000'));
        $this->assertSame([], $this->filtered('Net Retained', 'More Than', '1'));
        $this->assertSame(['Unsynced'], $this->filtered('Payments Collected', 'More Than', '1'),
            'The collected side is readable and must still select.');
    }

    public function test_another_tenants_customer_is_never_selected_by_a_money_filter(): void
    {
        $this->storeCurrency(self::STORE_A, 'USD');
        $this->storeCurrency(self::STORE_B, 'USD');

        $mine = $this->customer('Mine', self::STORE_A);
        $this->paidOrder($mine->id, 40.0);

        $theirs = $this->customer('Theirs', self::STORE_B);
        $theirOrder = $this->order($theirs->id, ['store_id' => self::STORE_B, 'final_price' => 40.0]);
        $this->ledger($theirOrder, [
            'store_id' => self::STORE_B, 'payment_status' => 'paid', 'paid_amount' => 40.0,
            'customer_total' => 40.0, 'remaining_amount' => 0.0, 'paid_at' => now(),
        ]);

        $this->assertSame(['Mine'], $this->filtered('Payments Collected', 'Less Than', '100'));
    }

    public function test_total_spend_still_selects_exactly_what_it_always_did(): void
    {
        /*
         * THE NON-REGRESSION THAT JUSTIFIES ADDING FIELDS RATHER THAN REPOINTING ONE. `Total
         * Spend` sums `final_price` with no payment predicate, so this customer -- who has paid
         * nothing -- still counts as having spent 800. A merchant's existing saved segment keeps
         * returning the same rows.
         */
        $this->storeCurrency(self::STORE_A, 'USD');

        $unpaid = $this->customer('Unpaid');
        $orderId = $this->order($unpaid->id, ['final_price' => 800.0]);
        $this->ledger($orderId);

        $this->assertSame(['Unpaid'], $this->filtered('Total Spend', 'More Than', '100'));
        $this->assertSame([], $this->filtered('Payments Collected', 'More Than', '100'),
            'The ledger field answers the money question and must NOT agree here.');
    }

    public function test_the_screen_can_actually_produce_an_operator_for_each_ledger_field(): void
    {
        /*
         * THE DEFECT THE INDEPENDENT REVIEW FOUND IN THIS VERY CHANGE, and it would have shipped
         * green. `CustomFilter()` supplies the operator list for the chosen field, and its numeric
         * branch named the three original fields one by one. The two new ones fell to the `else`,
         * which returns an EMPTY list -- and both screens hide the Condition and Value controls
         * when that list is empty (customer/index.blade.php:297, customer/grid.blade.php:469).
         *
         * So the Field select offered "Payments Collected" and choosing it did nothing at all:
         * fault class 3 from `CustomerListFilter`'s own docblock, "an operator the screen offers,
         * which silently does nothing", reintroduced by the change that fixed it.
         *
         * The sibling test asserting the dropdown offers the field names is what let this pass --
         * it pins the key, not the reachability. Both are needed.
         */
        $controller = new \App\Http\Controllers\CustomerController();

        foreach (CustomerListFilter::LEDGER_FIELDS as $field) {
            $payload = $controller->CustomFilter(
                \Illuminate\Http\Request::create('/customer-filter', 'GET', ['customer_field' => $field])
            )->getData(true);

            $this->assertNotEmpty($payload['condition'] ?? [],
                "'{$field}' is offered in the Field select but the screen can produce no operator "
                . 'for it, so the Condition and Value controls stay hidden and the field does '
                . 'nothing.');

            $this->assertSame(array_values(Customer::$fields_status2), array_values($payload['condition']),
                "'{$field}' is numeric and must offer the numeric operators.");
        }
    }

    public function test_a_page_spanning_two_stores_reads_each_customer_against_their_own_tenant(): void
    {
        /*
         * WHAT THE CROSS-TENANT FILTER TEST ABOVE DOES **NOT** PROVE, named by the review and
         * worth having separately.
         *
         * That test scopes its own query to store A, so the store-B customer never reaches the
         * resolver -- it pins the CALLER's predicate, which was never in doubt. This one hands
         * `forCustomers()` a collection that really does span two stores, which is the operator's
         * case, and checks each customer is read against their OWN store rather than against one
         * scope spanning both.
         *
         * The fixture is built so a single wide scope would be VISIBLE: each customer's order is
         * in their own store, and each has a different amount. A resolver reading both stores at
         * once would still isolate by `customer_id` and so still look right -- which is why the
         * discriminating assertion is on the SCOPE each result declares, not only on the amount.
         */
        $this->storeCurrency(self::STORE_A, 'USD');
        $this->storeCurrency(self::STORE_B, 'USD');

        $a = $this->customer('Ayla', self::STORE_A);
        $this->paidOrder($a->id, 70.0);

        $b = $this->customer('Bashir', self::STORE_B);
        $bOrder = $this->order($b->id, ['store_id' => self::STORE_B, 'final_price' => 310.0]);
        $this->ledger($bOrder, [
            'store_id' => self::STORE_B, 'payment_status' => 'paid', 'paid_amount' => 310.0,
            'customer_total' => 310.0, 'remaining_amount' => 0.0, 'paid_at' => now(),
        ]);

        $figures = CustomerMoneyFigures::forCustomers(collect([$a, $b]));

        $this->assertSame([self::STORE_A], $figures[$a->id]['collected']->scope()->storeIds(),
            "Store A's customer must be read against store A alone.");
        $this->assertSame([self::STORE_B], $figures[$b->id]['collected']->scope()->storeIds(),
            "Store B's customer must be read against store B alone, not a scope spanning both.");

        $this->assertSame(['USD' => 70.0], $figures[$a->id]['collected']->byCurrency());
        $this->assertSame(['USD' => 310.0], $figures[$b->id]['collected']->byCurrency());
    }

    public function test_the_two_money_columns_stay_outside_rawColumns_so_yajra_escapes_them(): void
    {
        /*
         * A RATCHET, ADDED BECAUSE A MUTATION PROVED NOTHING WOULD CATCH THIS.
         *
         * `moneyCell()` deliberately does NOT call `e()`: yajra ships `'escape' => '*'` and this
         * app publishes no override, so every column absent from `rawColumns()` is escaped for
         * us, and escaping here as well produced `&amp;amp;` for a merchant whose currency symbol
         * contains `&`. That leaves the safety resting on an absence, and an absence is the one
         * thing a later edit can undo without looking like it changed anything.
         *
         * The value is attacker-influenced in the way this codebase keeps finding: `settings`
         * `defult_currancy_symbol` is free text a merchant sets for their own store, and the
         * platform operator reads that store's customer list through this same DataTable. Adding
         * either column to `rawColumns()` without restoring an `e()` would make that a stored
         * cross-principal injection -- so the list is asserted, not assumed.
         *
         * Comments are stripped first: this very explanation names both columns and the word
         * rawColumns, and a census that reads prose about the thing it measures is one CLAUDE.md
         * records being hit four times in a single day.
         */
        $source = $this->sourceWithoutComments('app/DataTables/CustomerDataTable.php');

        $this->assertMatchesRegularExpression('/->rawColumns\(\[[^\]]*\]\)/', $source,
            'PREMISE: the rawColumns() call is still written as one literal array.');

        preg_match('/->rawColumns\(\[([^\]]*)\]\)/', $source, $m);

        foreach (['collected', 'retained'] as $column) {
            $this->assertStringNotContainsString("'{$column}'", $m[1],
                "`{$column}` is in rawColumns(), so yajra no longer escapes it -- and moneyCell() "
                . 'does not escape either. A merchant-set currency symbol would reach the '
                . "operator's browser as markup.");
        }

        $this->assertStringNotContainsString('return e($tiles', $source,
            'Escaping here as well double-escapes; yajra already escapes these two columns.');
    }

    // ------------------------------------------------------- what leaves the system, for Flutter

    /** The owning merchant's bearer token for a store this fixture created. */
    private function merchantToken(int $storeId = self::STORE_A): string
    {
        $ownerId = (int) DB::table('stores')->where('id', $storeId)->value('created_by');

        return User::findOrFail($ownerId)->createToken('auth_token')->plainTextToken;
    }

    public function test_the_admin_customer_list_carries_both_figures_and_their_notes(): void
    {
        $this->storeCurrency(self::STORE_A, 'USD');
        $customer = $this->customer('Appy');
        $orderId = $this->paidOrder($customer->id, 125.0);
        $this->movement($orderId, 'credit', 125.0);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->merchantToken())
            ->getJson('/api/admin/customerlist?store_id=' . self::STORE_A);

        $response->assertOk();

        $row = collect($response->json('data.customers') ?? $response->json('data') ?? [])
            ->first(fn ($r) => is_array($r) && ($r['id'] ?? null) === $customer->id);

        $this->assertIsArray($row, 'The list did not contain the fixture customer.');
        $this->assertArrayHasKey('ledger', $row);
        $this->assertTrue($row['ledger']['available']);
        $this->assertStringContainsString('125', $row['ledger']['payments_collected']);
        $this->assertStringContainsString('125', $row['ledger']['net_retained']);
        $this->assertNotSame('', $row['ledger']['payments_collected_note']);
        $this->assertStringContainsString('not money received', $row['ledger']['order_value_note']);
    }

    public function test_the_shipped_total_spend_key_still_means_what_it_always_meant(): void
    {
        /*
         * THE BACKWARD-COMPATIBILITY ASSERTION THAT MAKES THE NEW KEYS SAFE. The shipped Flutter
         * app reads `total_spend`. This customer has an 800 order and has paid NOTHING, so the
         * order-value key must still say 800 while the money keys say zero. If a later change
         * repointed `total_spend` at the ledger, this reddens -- which is the point, because that
         * change would otherwise be invisible until a merchant noticed their numbers had moved.
         */
        $this->storeCurrency(self::STORE_A, 'USD');
        $customer = $this->customer('Owing');
        $orderId = $this->order($customer->id, ['final_price' => 800.0]);
        $this->ledger($orderId);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->merchantToken())
            ->getJson('/api/admin/customerlist?store_id=' . self::STORE_A);

        $response->assertOk();

        $row = collect($response->json('data.customers') ?? $response->json('data') ?? [])
            ->first(fn ($r) => is_array($r) && ($r['id'] ?? null) === $customer->id);

        $this->assertIsArray($row);
        $this->assertStringContainsString('800', (string) $row['total_spend']);
        $this->assertStringContainsString('0.00', (string) $row['ledger']['payments_collected']);
    }

    public function test_an_unreadable_figure_leaves_as_null_and_never_as_a_zero(): void
    {
        $this->storeCurrency(self::STORE_A, 'USD');
        $customer = $this->customer('Ancient');
        $this->order($customer->id, ['final_price' => 300.0]); // predates the record

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->merchantToken())
            ->getJson('/api/admin/customerlist?store_id=' . self::STORE_A);

        $response->assertOk();

        $row = collect($response->json('data.customers') ?? $response->json('data') ?? [])
            ->first(fn ($r) => is_array($r) && ($r['id'] ?? null) === $customer->id);

        $this->assertIsArray($row);
        $this->assertFalse($row['ledger']['available']);
        $this->assertNull($row['ledger']['payments_collected'],
            'A figure the record cannot produce must be absent, not 0.00 -- the app would print '
            . 'the zero as a fact about this shopper.');
        $this->assertNull($row['ledger']['net_retained']);
        $this->assertStringContainsString('no financial record', $row['ledger']['payments_collected_note']);
    }

    public function test_the_list_never_carries_another_tenants_customer(): void
    {
        $this->storeCurrency(self::STORE_A, 'USD');
        $this->storeCurrency(self::STORE_B, 'USD');

        $mine = $this->customer('Ours', self::STORE_A);
        $theirs = $this->customer('Theirs', self::STORE_B);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->merchantToken(self::STORE_A))
            ->getJson('/api/admin/customerlist?store_id=' . self::STORE_A);

        $response->assertOk();

        $ids = collect($response->json('data.customers') ?? $response->json('data') ?? [])
            ->filter(fn ($r) => is_array($r))->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_a_merchant_cannot_ask_this_endpoint_for_another_tenants_store(): void
    {
        /* The money block is attached to rows this endpoint returns, so the endpoint's own
           ownership gate is part of what makes the figures safe to expose. Pinned here rather than
           assumed. */
        $this->storeCurrency(self::STORE_B, 'USD');
        $this->customer('Theirs', self::STORE_B);

        $this->withHeader('Authorization', 'Bearer ' . $this->merchantToken(self::STORE_A))
            ->getJson('/api/admin/customerlist?store_id=' . self::STORE_B)
            ->assertStatus(403);
    }

    public function test_the_dropdown_offers_exactly_the_field_names_the_filter_compares(): void
    {
        /*
         * `in_array(..., true)` IS EXACT, and a one-character difference sends the field down the
         * text branch where it silently matches nothing. That is not hypothetical: the
         * `Last active` / `Last Active` mismatch (P2.5-R-03) shipped and never matched.
         */
        $offered = array_keys(Customer::customer_field());

        foreach (CustomerListFilter::LEDGER_FIELDS as $field) {
            $this->assertContains($field, $offered,
                "The filter compares '{$field}' but the dropdown never offers it.");
            $this->assertContains($field, CustomerListFilter::NUMERIC_FIELDS,
                "'{$field}' must take the numeric branch, not the text one.");
        }
    }

    // ------------------------------------------ the fourth surface: the filter fragment

    /**
     * GAP-156, THE SURFACE THE FIRST THREE MISSED. `customer/filter.blade.php` is a SECOND copy
     * of the customer list -- `<tr>` rows rendered by `CustomerController::CustomFilterData()` and
     * returned from `GET /customer-filter-data` -- and it printed `total_spend()` as a bare money
     * cell with no title, no note and neither ledger figure beside it.
     *
     * THE DUPLICATION IS THE REASON IT WAS MISSED, and this exact pair has produced a defect
     * before: P2.5-R-06 found all three numeric filter faults in BOTH `CustomerDataTable::query()`
     * and `CustomFilterData()`, six instances of three bugs, because a reader who repaired one copy
     * had not repaired the screen. CLAUDE.md records the same rule for prints -- "a test that
     * drives one copy of a print that is written twice leaves the other open".
     *
     * REACHABLE, not theoretical: the route is live behind `Manage Customer`, and
     * `CustomerNumericFilterOperatorsTest` already drives it over HTTP to read the filtered set out
     * of this very markup.
     */
    private function merchantWhoMayReadCustomers(int $storeId = self::STORE_A): User
    {
        $owner = User::findOrFail((int) DB::table('stores')->where('id', $storeId)->value('created_by'));
        $owner->current_store = $storeId;
        $owner->save();

        /* Laratrust, not spatie: `permissions_as_gates => false`, so the handler asks
           `isAbleTo()` and a Gate grant would be invisible to it. A permission row that no role
           holds can be held by nobody, so the row, the role and the assignment are all made. */
        $permission = \App\Models\Permission::firstOrCreate(
            ['name' => 'Manage Customer'],
            ['display_name' => 'Manage Customer'],
        );

        $role = \App\Models\Role::forceCreate([
            'name' => 'fragment-reader-' . $owner->id,
            'display_name' => 'Fragment Reader',
            'guard_name' => 'web',
            'created_by' => $owner->creatorId(),
            'store_id' => $storeId,
        ]);

        $role->givePermission($permission);
        $owner->addRole($role);
        $owner->flushCache();

        return User::findOrFail($owner->id);
    }

    /**
     * The fragment's markup, as the screen would receive it.
     *
     * NOT `strip_tags()`. CLAUDE.md records that it reads a bare `<` comparison as a tag opening
     * and silently discards everything to the next `>` -- measured at 80,007 bytes becoming
     * 18,679 on `/finance` -- which inverts an absence assertion. Whitespace-collapsed raw body
     * instead.
     */
    private function fragmentMarkup(): string
    {
        $merchant = $this->merchantWhoMayReadCustomers();

        $response = $this->actingAs($merchant)->get('/customer-filter-data');

        /* Three premises, each of which would otherwise satisfy every assertion below on its own:
           a route that does not exist (404), a handler that threw (500), and the permission
           refusal (302 to the previous page). */
        $this->assertNotSame(404, $response->status(), 'premise: /customer-filter-data is not routed.');
        $this->assertNotSame(302, $response->status(), 'premise: the merchant was refused by the permission gate.');
        $this->assertSame(200, $response->status(),
            'premise: the fragment answered ' . $response->status() . ' rather than rendering.');

        return (string) preg_replace('/\s+/', ' ', (string) $response->json());
    }

    public function test_the_filter_fragment_carries_both_ledger_figures_beside_the_order_value(): void
    {
        /*
         * ONE CUSTOMER, THREE ORDERS, THREE DIFFERENT ANSWERS -- the fixture is built so that no
         * two of the figures are the same number, because a surface printing one of them under
         * another's heading is exactly what §15 forbids and a fixture where they coincide cannot
         * see it.
         *
         *   240 paid and kept          -> order value 940, collected 290, retained 240
         *    50 paid then refunded     -> collected counts it, retained does not
         *   650 never paid at all      -> order value counts it, neither money figure does
         */
        $this->storeCurrency(self::STORE_A, 'USD');

        $customer = $this->customer('Fragmenta');

        $kept = $this->paidOrder($customer->id, 240.0);
        $this->movement($kept, 'credit', 240.0);

        $refunded = $this->paidOrder($customer->id, 50.0, ['payment_status' => 'refunded']);
        $this->movement($refunded, 'credit', 50.0);
        $this->movement($refunded, 'debit', 50.0);

        $neverPaid = $this->order($customer->id, ['final_price' => 650.0]);
        $this->ledger($neverPaid, ['customer_total' => 650.0, 'remaining_amount' => 650.0]);

        $markup = $this->fragmentMarkup();

        $this->assertStringContainsString('Fragmenta', $markup,
            'premise: the fixture customer is not on the fragment at all.');
        $this->assertStringContainsString('940', $markup,
            'premise: the order-value cell still sums every order, cancellations and all.');

        $this->assertStringContainsString('290.00', $markup,
            'The fragment must print payments collected: 240 kept plus 50 that was paid and later '
            . 'refunded. §15 includes a reversed-but-captured payment -- the shopper did pay.');
        $this->assertStringContainsString('240.00', $markup,
            'The fragment must print net retained: the 50 refund subtracts itself, and the 650 '
            . 'nobody paid was never in it.');
    }

    public function test_the_filter_fragments_order_value_cell_says_it_is_not_money_received(): void
    {
        /*
         * THE DEFECT IN ONE ASSERTION. A money-looking number in an unlabelled cell reads as what
         * the shopper paid. On this fixture nobody has paid anything and the cell says 700, so
         * without the sentence the fragment asserts a payment that never happened.
         */
        $this->storeCurrency(self::STORE_A, 'USD');

        $customer = $this->customer('Owingtoo');
        $orderId = $this->order($customer->id, ['final_price' => 700.0]);
        $this->ledger($orderId, ['customer_total' => 700.0, 'remaining_amount' => 700.0]);

        $markup = $this->fragmentMarkup();

        $this->assertStringContainsString('700', $markup,
            'premise: the order-value figure is on the fragment.');
        $this->assertStringContainsString('not money received', $markup,
            'The order-value cell carries no statement that it is not what the customer paid. '
            . 'CustomerMoneyTiles::spendHint() is the one sentence every surface shares.');
    }

    public function test_the_filter_fragment_withholds_a_figure_the_record_cannot_produce(): void
    {
        /*
         * `store_order_financials` was never backfilled, so a long-standing shopper's orders can
         * be invisible to the ledger. 0.00 in a money column is a claim that they have never paid;
         * the fragment must refuse the same way the tiles and the list do.
         */
        $this->storeCurrency(self::STORE_A, 'USD');

        $customer = $this->customer('Ancienta');
        $this->paidOrder($customer->id, 410.0);
        $this->order($customer->id, ['final_price' => 90.0]); // no ledger row at all

        /*
         * THE POSITIVE CONTROL, ON THE SAME FRAGMENT, and it is what makes this case worth
         * anything. Without it "Not available appears and 410.00 does not" is satisfied by a
         * fragment that prints Not available for EVERY customer -- which is exactly what the view
         * renders if the controller stops passing the figures, because `??` swallows an undefined
         * variable as readily as a missing key. Measured: with the controller half reverted and
         * the view half left in place, this case passed. The control fails in that state.
         */
        $readable = $this->customer('Readabla');

        /*
         * TWO ORDERS OF 256, NOT ONE OF 512, and the difference is the whole value of the control.
         * With a single order this customer's AOV is `512 / 1`, which `number_format(..., 2)`
         * prints as the same `512.00` string the collected cell would -- so the control passed in
         * the half-reverted state it was written to catch. Two orders make the AOV `256.00` and
         * leave `512.00` reachable only from a money cell. CLAUDE.md's rule 9: when a mutation
         * survives, ask what input makes the two readings disagree.
         */
        foreach ([256.0, 256.0] as $amount) {
            $this->movement($this->paidOrder($readable->id, $amount), 'credit', $amount);
        }

        $markup = $this->fragmentMarkup();

        $this->assertStringContainsString('Ancienta', $markup, 'premise: the row rendered.');
        $this->assertStringContainsString('512.00', $markup,
            'CONTROL: the fragment can print a readable money figure at all. If this fails, the '
            . 'absence assertions below are measuring a broken surface rather than a withheld '
            . 'figure.');
        $this->assertStringContainsString(__('Not available'), $markup,
            'An uncovered order makes the money figures unreadable; the cell must say so.');
        $this->assertStringNotContainsString('410.00', $markup,
            '410 is a FLOOR, not the figure: one order the record cannot see means the rest is '
            . 'not the answer. Printing it would understate by exactly the invisible order.');
    }

    public function test_the_fragment_and_the_list_resolve_their_money_through_the_same_layer(): void
    {
        /*
         * THE SOURCE-LEVEL HALF, because the behaviour above can be satisfied by a second,
         * drifting implementation -- which is literally what happened to this pair's FILTER.
         * Comment-stripped: CLAUDE.md records a census satisfied by prose about the thing it
         * measures being hit eight times in this repository, and the paragraphs above this method
         * name every identifier it looks for.
         */
        $controller = $this->sourceWithoutComments('app/Http/Controllers/CustomerController.php');
        $view = $this->sourceWithoutComments('resources/views/customer/filter.blade.php');

        $this->assertStringContainsString('CustomerMoneyFigures::forCustomers', $controller,
            'The fragment holds the whole collection, so it must use the BULK resolver rather '
            . 'than resolving per row or growing its own query.');
        $this->assertStringNotContainsString("sum('final_price')", $view,
            'The fragment must not compute money of its own.');

        foreach (['collected', 'retained'] as $figure) {
            $this->assertMatchesRegularExpression(
                '/\$customerMoney\[[^\]]*\]\[[\'"]' . $figure . '[\'"]\]/',
                $view,
                "The fragment must read its `{$figure}` cell from the resolved tiles, so it "
                . 'cannot disagree with the list or with the filter that selects on it.',
            );
        }
    }
}
