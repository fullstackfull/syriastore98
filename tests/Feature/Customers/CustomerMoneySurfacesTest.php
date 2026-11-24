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
 * GAP-156 -- THE THREE SURFACES, and the filter, which is the half that decides what a merchant
 * never sees.
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
}
