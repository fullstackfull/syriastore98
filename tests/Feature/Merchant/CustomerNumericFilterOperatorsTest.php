<?php

namespace Tests\Feature\Merchant;

use App\DataTables\CustomerDataTable;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2.5-R-06 — the three numeric customer filters returned WRONG ANSWERS, in both copies.
 * =================================================================================================
 *
 * A POST-CLOSURE PHASE 02 DEFECT, repaired under owner decision D-40 (2026-11-03) and the owner's
 * task #88 instruction. These are not cosmetic: the merchant asked a question about their own
 * customers and the screen answered with the wrong set of people.
 *
 * THREE FAULTS, AND EACH ONE EXISTS TWICE — once in `CustomerDataTable::query()` (the list) and
 * again, byte-for-byte, in `CustomerController::CustomFilterData()` (the fragment the grid asks
 * for). Six instances. That duplication is itself the root cause of the third fault surviving: a
 * reader who fixes one copy has not fixed the screen.
 *
 *   1. `'Equal' && $AOV = $text_field_float` — a single `=`. AN ASSIGNMENT, NOT A COMPARISON.
 *      PHP evaluates the assignment to the assigned value, which is truthy for every non-zero
 *      input, so the whole condition reduces to "the operator is Equal". **"AOV Equal 150"
 *      therefore matched every customer who had any computable AOV at all.** It also silently
 *      overwrote $AOV inside the loop. The one input that did NOT misbehave is 0, because 0.0 is
 *      falsy — so the only value a casual tester would try by accident is the only one that looks
 *      right.
 *
 *   2. `'No. of Orders'` had TWO branches both keyed on `'Less Than'`, the second testing `>`.
 *      Because they are `else if`, "Less Than 3" matched `< 3` from the first branch and `> 3`
 *      from the second: **everything except the customers who had exactly 3.** The inverse of a
 *      filter, with the boundary punched out.
 *
 *   3. Neither `'No. of Orders'` nor `'Total Spend'` had a `'More Than'` branch AT ALL, while
 *      `Customer::$fields_status2` offers that operator and `CustomFilter()` renders it. So the
 *      merchant could select it, the request went out, `$filteredCustomers` stayed empty, and
 *      `whereIn('id', [])` returned **nothing**. An empty screen reads as "you have no such
 *      customers", which is a wrong answer dressed as a true one.
 *
 * WHY IT SURVIVED. `CustomerListFilterApplyTest` has 13 tests and drives only `'Includes'` (4x)
 * and `'After'` (3x). Measured before this file existed:
 *
 *     grep -o "'Less Than'\|'More Than'\|'Equal'" tests/Feature/Merchant/CustomerListFilterApplyTest.php
 *       -> no matches
 *
 * Not one numeric operator was ever driven. The faults were also DOCUMENTED and deferred — that
 * test file's own header names fault 1 as ledger `P2.5-F-09` and says "both change behaviour if
 * touched, which the owner's decision excludes". Task #88 is the instruction that lifts that.
 *
 * WHAT THIS FILE ASSERTS, AND WHY IT IS SHAPED THIS WAY
 * -------------------------------------------------------------------------------------------------
 *
 * Every numeric operator is driven against a fixture whose per-customer figures are stated, so the
 * expected set is arithmetic rather than opinion. Each field is tested through BOTH call sites,
 * because a one-copy fix is the failure mode this defect already demonstrated.
 *
 * THE PRESERVED OPERATORS ARE ASSERTED TOO (`AOV Less Than`, `AOV More Than`, `Orders Equal`,
 * `Spend Equal`). Those three already worked; a repair that breaks them trades one wrong answer
 * for another, and nothing else in the suite would notice.
 *
 * POSITIVE AND NEGATIVE CONTROLS, which D-40 requires by name. Every cross-tenant assertion names
 * a store-A customer that MUST be present alongside the store-B customer that must not — because
 * an "absent" assertion passes just as well when the query returned nothing at all, and that has
 * produced false readings on this branch before. Store B's customer is given a profile IDENTICAL
 * to a store-A customer's, so only the tenant predicate can separate them.
 *
 * ONE SEMANTIC INVARIANT IS DELIBERATELY PRESERVED, NOT "FIXED". A customer with no orders has
 * `total_spend() == 0` and `Ordercount() == 0`, and the existing code skips them in the AOV branch
 * entirely: no orders means no average, not an average of zero. Changing that would alter which
 * customers a money filter returns, and D-40 forbids changing financial calculations. It is
 * asserted here as an invariant so a later reader cannot change it by accident.
 */
class CustomerNumericFilterOperatorsTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;
    private Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->grantPermission(User::find($this->merchant->id), 'Manage Customer');
        $this->merchant = User::find($this->merchant->id);

        $otherMerchant = $this->makeMerchant();
        $this->otherStore = $this->makeStore($otherMerchant);
    }

    // =============================================================================================
    //  FIXTURE — every figure below is stated, so each expected set is arithmetic
    // =============================================================================================

    /**
     * |  customer | orders | each |  spend |  AOV |
     * |-----------|-------:|-----:|-------:|-----:|
     * | Amal      |      1 |  100 |    100 |  100 |
     * | Bilal     |      2 |  150 |    300 |  150 |
     * | Carla     |      5 |  100 |    500 |  100 |
     * | Dalia     |      3 |  200 |    600 |  200 |
     * | Eyad      |      0 |    - |      0 |  n/a |
     *
     * Store B's Zahra is 2 x 150 — Bilal's profile exactly — so every cross-tenant assertion is
     * separated by the tenant predicate alone and by nothing about the numbers.
     */
    private function seedCustomers(): void
    {
        $this->customerWithOrders($this->store, 'Amal', 1, 100);
        $this->customerWithOrders($this->store, 'Bilal', 2, 150);
        $this->customerWithOrders($this->store, 'Carla', 5, 100);
        $this->customerWithOrders($this->store, 'Dalia', 3, 200);
        $this->customerWithOrders($this->store, 'Eyad', 0, 0);

        $this->customerWithOrders($this->otherStore, 'Zahra', 2, 150);
    }

    private function customerWithOrders(Store $store, string $first, int $count, float $each): Customer
    {
        $customer = Customer::forceCreate([
            'first_name' => $first,
            'last_name' => 'Tester',
            'email' => strtolower($first) . '@example.test',
            'password' => bcrypt('x'),
            'store_id' => $store->id,
            'created_by' => $store->created_by,
            'status' => 1,
        ]);

        for ($i = 0; $i < $count; $i++) {
            // Through Eloquent, not DB::table(): Order is Cachable, and a raw write leaves
            // Ordercount()/total_spend() reading the pre-write rows for the rest of the test.
            $this->makeOrder($store, [
                'customer_id' => $customer->id,
                'is_guest' => 0,
                'final_price' => $each,
            ]);
        }

        return $customer;
    }

    /**
     * The list's answer, as first names, sorted so the assertion is about the SET and not about
     * whatever order the database happened to return.
     */
    private function listNames(string $field, string $operator, string $value): array
    {
        // actingAs FIRST, and it is load-bearing rather than ceremony. `CustomerDataTable::query()`
        // scopes on `getCurrentStore()`, which reads `auth()->user()->current_store`
        // (app/Helper/helper.php:758-771). With no authenticated principal the helper returns null,
        // the predicate becomes `store_id IS NULL`, and EVERY assertion in this file passes or
        // fails for that reason instead of the one it names. Measured: without this line the
        // positive controls failed while the premise test still passed, because the premise asserts
        // through withoutGlobalScopes() and never consults the helper at all.
        $this->actingAs($this->merchant);

        $request = Request::create('/customer', 'GET', [
            'field_name' => $field,
            'selected_name' => $operator,
            'text_field' => $value,
        ]);
        $this->app->instance('request', $request);

        $names = (new CustomerDataTable())->query(new Customer(), $request)
            ->pluck('first_name')->all();

        sort($names);

        return $names;
    }

    /**
     * The fragment's answer. `CustomFilterData` renders `customer/filter.blade.php` and returns it
     * as JSON, so the names are read back out of the rendered markup — which is also the only
     * honest way to assert on what the screen would actually show.
     */
    private function fragmentNames(string $field, string $operator, string $value): array
    {
        $response = $this->actingAs($this->merchant)->get('/customer-filter-data?' . http_build_query([
            'customer_field' => $field,
            'selected_name' => $operator,
            'text_field' => $value,
        ]));

        $this->assertSame(200, $response->status(),
            'premise: the filter fragment answered ' . $response->status() . ' rather than rendering');

        $html = (string) $response->json();

        $found = [];
        foreach (['Amal', 'Bilal', 'Carla', 'Dalia', 'Eyad', 'Zahra'] as $name) {
            if (str_contains($html, $name)) {
                $found[] = $name;
            }
        }
        sort($found);

        return $found;
    }

    // =============================================================================================
    //  PREMISE — the fixture really produces the figures the tables above claim
    // =============================================================================================

    /**
     * If this fails, every expectation below is measuring the wrong thing and the whole file is
     * worthless. Asserted through withoutGlobalScopes(), because Customer IS tenant-scoped and a
     * plain Customer::where() answers against whichever tenant happens to be resolved.
     */
    public function test_premise_the_fixture_produces_the_stated_order_counts_and_spends(): void
    {
        $this->seedCustomers();

        $expected = [
            'Amal' => [1, 100.0, 100.0],
            'Bilal' => [2, 300.0, 150.0],
            'Carla' => [5, 500.0, 100.0],
            'Dalia' => [3, 600.0, 200.0],
            'Eyad' => [0, 0.0, 0.0],
        ];

        foreach ($expected as $name => [$orders, $spend, $aov]) {
            $customer = Customer::withoutGlobalScopes()
                ->where('store_id', $this->store->id)
                ->where('first_name', $name)
                ->firstOrFail();

            $this->assertSame($orders, $customer->Ordercount(), "premise: {$name}'s order count");
            $this->assertEquals($spend, (float) $customer->total_spend(), "premise: {$name}'s total spend");

            $computed = $orders > 0 ? round($spend / $orders, 2) : 0.0;
            $this->assertEquals($aov, $computed, "premise: {$name}'s AOV");
        }

        $zahra = Customer::withoutGlobalScopes()
            ->where('store_id', $this->otherStore->id)
            ->where('first_name', 'Zahra')
            ->firstOrFail();

        $this->assertSame(2, $zahra->Ordercount(),
            "premise: store B's Zahra must carry Bilal's exact profile, or the cross-tenant "
            . 'assertions are separated by their numbers rather than by the tenant predicate');
        $this->assertEquals(300.0, (float) $zahra->total_spend(), "premise: Zahra's total spend");
    }

    // =============================================================================================
    //  FAULT 1 — AOV 'Equal' was an ASSIGNMENT, so it matched everyone
    // =============================================================================================

    public function test_aov_equal_matches_only_the_customer_with_that_average_in_the_list(): void
    {
        $this->seedCustomers();

        $this->assertSame(['Bilal'], $this->listNames('AOV', 'Equal', '150'),
            "'AOV Equal 150' is still the assignment `\$AOV = \$text_field_float`, which is truthy "
            . 'for any non-zero input, so every customer with a computable AOV came back');
    }

    public function test_aov_equal_matches_both_customers_that_share_an_average_in_the_list(): void
    {
        $this->seedCustomers();

        $this->assertSame(['Amal', 'Carla'], $this->listNames('AOV', 'Equal', '100'),
            "'AOV Equal 100' must return exactly the two customers whose average is 100");
    }

    public function test_aov_equal_matches_only_the_customer_with_that_average_in_the_fragment(): void
    {
        $this->seedCustomers();

        $this->assertSame(['Bilal'], $this->fragmentNames('AOV', 'Equal', '150'),
            'the SECOND copy of the assignment, in CustomerController::CustomFilterData(). A fix '
            . 'applied to the DataTable alone leaves the grid answering wrongly');
    }

    // =============================================================================================
    //  FAULT 2 — 'Less Than' was duplicated, the second branch testing '>'
    // =============================================================================================

    public function test_orders_less_than_excludes_the_customers_above_the_threshold(): void
    {
        $this->seedCustomers();

        $this->assertSame(['Amal', 'Bilal', 'Eyad'], $this->listNames('No. of Orders', 'Less Than', '3'),
            "'No. of Orders Less Than 3' still has a second branch keyed on 'Less Than' testing "
            . '`>`, so Carla (5 orders) came back from a LESS-THAN filter');
    }

    public function test_spend_less_than_excludes_the_customers_above_the_threshold(): void
    {
        $this->seedCustomers();

        $this->assertSame(['Amal', 'Eyad'], $this->listNames('Total Spend', 'Less Than', '300'),
            "'Total Spend Less Than 300' still returns Carla (500) and Dalia (600) from the "
            . 'duplicated branch — everything except the customer who matched exactly');
    }

    public function test_orders_less_than_excludes_the_customers_above_the_threshold_in_the_fragment(): void
    {
        $this->seedCustomers();

        $this->assertSame(['Amal', 'Bilal', 'Eyad'], $this->fragmentNames('No. of Orders', 'Less Than', '3'),
            'the second copy of the duplicated branch, in CustomFilterData()');
    }

    public function test_spend_less_than_excludes_the_customers_above_the_threshold_in_the_fragment(): void
    {
        $this->seedCustomers();

        $this->assertSame(['Amal', 'Eyad'], $this->fragmentNames('Total Spend', 'Less Than', '300'),
            'the second copy of the duplicated branch, in CustomFilterData()');
    }

    // =============================================================================================
    //  FAULT 3 — 'More Than' had no branch at all, so it answered with an empty screen
    // =============================================================================================

    public function test_orders_more_than_returns_the_customers_above_the_threshold(): void
    {
        $this->seedCustomers();

        $this->assertSame(['Carla', 'Dalia'], $this->listNames('No. of Orders', 'More Than', '2'),
            "'No. of Orders More Than' has no branch, so \$filteredCustomers stayed empty and "
            . 'whereIn(id, []) returned nothing — an empty list reading as "no such customers"');
    }

    public function test_spend_more_than_returns_the_customers_above_the_threshold(): void
    {
        $this->seedCustomers();

        $this->assertSame(['Carla', 'Dalia'], $this->listNames('Total Spend', 'More Than', '300'),
            "'Total Spend More Than' has no branch either");
    }

    public function test_orders_more_than_returns_the_customers_above_the_threshold_in_the_fragment(): void
    {
        $this->seedCustomers();

        $this->assertSame(['Carla', 'Dalia'], $this->fragmentNames('No. of Orders', 'More Than', '2'),
            'the second copy of the missing branch, in CustomFilterData()');
    }

    public function test_spend_more_than_returns_the_customers_above_the_threshold_in_the_fragment(): void
    {
        $this->seedCustomers();

        $this->assertSame(['Carla', 'Dalia'], $this->fragmentNames('Total Spend', 'More Than', '300'),
            'the second copy of the missing branch, in CustomFilterData()');
    }

    // =============================================================================================
    //  THE OPERATORS THAT ALREADY WORKED — asserted so the repair cannot trade one fault for another
    // =============================================================================================

    public function test_aov_less_than_still_returns_the_customers_below_the_threshold(): void
    {
        $this->seedCustomers();

        $this->assertSame(['Amal', 'Carla'], $this->listNames('AOV', 'Less Than', '150'),
            'AOV Less Than already worked and must keep working');
    }

    public function test_aov_more_than_still_returns_the_customers_above_the_threshold(): void
    {
        $this->seedCustomers();

        $this->assertSame(['Dalia'], $this->listNames('AOV', 'More Than', '150'),
            'AOV More Than already worked and must keep working');
    }

    public function test_orders_equal_still_returns_only_the_exact_match(): void
    {
        $this->seedCustomers();

        $this->assertSame(['Dalia'], $this->listNames('No. of Orders', 'Equal', '3'),
            'No. of Orders Equal already worked and must keep working');
    }

    public function test_spend_equal_still_returns_only_the_exact_match(): void
    {
        $this->seedCustomers();

        $this->assertSame(['Bilal'], $this->listNames('Total Spend', 'Equal', '300'),
            'Total Spend Equal already worked and must keep working');
    }

    // =============================================================================================
    //  THE PRESERVED SEMANTIC INVARIANT — no orders is not an average of zero
    // =============================================================================================

    /**
     * Eyad has no orders. He appears in the ORDER-COUNT and TOTAL-SPEND filters, where 0 is a real
     * figure, and is absent from every AOV filter, where it is not: an average over zero orders is
     * undefined, not zero. The existing code already behaves this way and D-40 forbids changing a
     * financial calculation, so this is pinned rather than altered.
     */
    public function test_a_customer_with_no_orders_is_absent_from_every_aov_operator(): void
    {
        $this->seedCustomers();

        foreach ([['Less Than', '1000'], ['More Than', '0'], ['Equal', '0']] as [$operator, $value]) {
            $this->assertNotContains('Eyad', $this->listNames('AOV', $operator, $value),
                "a customer with no orders has no average, so 'AOV {$operator} {$value}' must not "
                . 'return Eyad — an average over zero orders is undefined, not zero');
        }
    }

    public function test_a_customer_with_no_orders_is_present_in_the_count_and_spend_filters(): void
    {
        $this->seedCustomers();

        $this->assertContains('Eyad', $this->listNames('No. of Orders', 'Less Than', '1'),
            'zero IS a real order count, so a customer with no orders belongs in "fewer than 1"');
        $this->assertContains('Eyad', $this->listNames('Total Spend', 'Less Than', '1'),
            'zero IS a real total spend, so a customer who has spent nothing belongs in "under 1"');
    }

    // =============================================================================================
    //  FAULT 4 — `empty("0")` is TRUE, so a threshold of zero was discarded and NOTHING filtered
    // =============================================================================================

    /**
     * "Which of my customers have never ordered?" is an ordinary merchant question and the screen
     * offers the controls to ask it. Both original copies guarded every branch with
     * `!empty($text_field)`, and PHP's `empty()` is true for the string "0", so the filter was
     * skipped entirely and the answer was EVERY CUSTOMER IN THE STORE.
     *
     * Found while bite-testing the other three faults, by an assertion that was written for a
     * different reason — `AOV More Than 0` returned the customer with no orders at all, which the
     * AOV branch is supposed to skip under any operator.
     */
    public function test_an_order_count_of_zero_is_a_usable_threshold(): void
    {
        $this->seedCustomers();

        $this->assertSame(['Eyad'], $this->listNames('No. of Orders', 'Equal', '0'),
            'a threshold of "0" is still being discarded by `empty()`, so "No. of Orders Equal 0" '
            . 'applied no filter and returned the whole store rather than the one customer who '
            . 'has never ordered');
    }

    public function test_a_total_spend_of_zero_is_a_usable_threshold(): void
    {
        $this->seedCustomers();

        $this->assertSame(['Eyad'], $this->listNames('Total Spend', 'Equal', '0'),
            '"Total Spend Equal 0" applied no filter and returned the whole store');
    }

    public function test_an_order_count_of_zero_is_a_usable_threshold_in_the_fragment(): void
    {
        $this->seedCustomers();

        $this->assertSame(['Eyad'], $this->fragmentNames('No. of Orders', 'Equal', '0'),
            'the second copy of the empty("0") guard, in CustomFilterData()');
    }

    /**
     * A BLANK value still means "no filter", which is the behaviour the unfiltered screen already
     * shows and which both original copies intended. Only the string "0" changes meaning.
     */
    public function test_a_blank_value_still_applies_no_filter(): void
    {
        $this->seedCustomers();

        $this->assertSame(['Amal', 'Bilal', 'Carla', 'Dalia', 'Eyad'],
            $this->listNames('No. of Orders', 'Equal', ''),
            'a blank threshold must mean "no filter", not "match nothing"');
    }

    /**
     * A MISSING OPERATOR likewise. The field select can be chosen before the operator control has
     * been injected, and that intermediate state must not narrow anything.
     */
    public function test_a_missing_operator_applies_no_filter(): void
    {
        $this->seedCustomers();

        $this->assertSame(['Amal', 'Bilal', 'Carla', 'Dalia', 'Eyad'],
            $this->listNames('No. of Orders', '', '3'),
            'a missing operator must mean "no filter", not "match nothing"');
    }

    /**
     * AN UNRECOGNISED OPERATOR matches nothing, deliberately and by stated default rather than as
     * the consequence of a branch somebody forgot to write — which is what fault 3 was.
     */
    public function test_an_unrecognised_operator_matches_nothing_rather_than_everything(): void
    {
        $this->seedCustomers();

        $this->assertSame([], $this->listNames('No. of Orders', 'Roughly', '3'),
            'an operator the screen does not offer must not fall through to an unfiltered list');
    }

    // =============================================================================================
    //  TENANCY — every assertion carries a positive control, because "absent" passes on an
    //  empty result set just as well as on a correct one
    // =============================================================================================

    public function test_no_numeric_operator_reaches_another_stores_customer_in_the_list(): void
    {
        $this->seedCustomers();

        $cases = [
            ['AOV', 'Equal', '150', 'Bilal'],
            ['AOV', 'Less Than', '200', 'Amal'],
            ['AOV', 'More Than', '120', 'Bilal'],
            ['No. of Orders', 'Equal', '2', 'Bilal'],
            ['No. of Orders', 'Less Than', '3', 'Bilal'],
            ['No. of Orders', 'More Than', '1', 'Bilal'],
            ['Total Spend', 'Equal', '300', 'Bilal'],
            ['Total Spend', 'Less Than', '400', 'Bilal'],
            ['Total Spend', 'More Than', '200', 'Bilal'],
        ];

        foreach ($cases as [$field, $operator, $value, $mustBePresent]) {
            $names = $this->listNames($field, $operator, $value);

            // THE POSITIVE CONTROL FIRST. Store B's Zahra shares Bilal's profile exactly, so if
            // Bilal is missing the filter returned nothing and the absence below proves nothing.
            $this->assertContains($mustBePresent, $names,
                "positive control: '{$field} {$operator} {$value}' must return this store's "
                . "{$mustBePresent}, or the cross-tenant assertion that follows is vacuous");

            $this->assertNotContains('Zahra', $names,
                "'{$field} {$operator} {$value}' reached store B's customer");
        }
    }

    public function test_no_numeric_operator_reaches_another_stores_customer_in_the_fragment(): void
    {
        $this->seedCustomers();

        $cases = [
            ['AOV', 'Equal', '150', 'Bilal'],
            ['No. of Orders', 'More Than', '1', 'Bilal'],
            ['No. of Orders', 'Less Than', '3', 'Bilal'],
            ['Total Spend', 'More Than', '200', 'Bilal'],
            ['Total Spend', 'Less Than', '400', 'Bilal'],
        ];

        foreach ($cases as [$field, $operator, $value, $mustBePresent]) {
            $names = $this->fragmentNames($field, $operator, $value);

            $this->assertContains($mustBePresent, $names,
                "positive control: the fragment must return this store's {$mustBePresent} for "
                . "'{$field} {$operator} {$value}'");

            $this->assertNotContains('Zahra', $names,
                "the fragment's '{$field} {$operator} {$value}' reached store B's customer");
        }
    }

    // =============================================================================================
    //  BOUNDARIES — the value itself, which is where the duplicated branch punched a hole
    // =============================================================================================

    public function test_the_threshold_itself_is_excluded_from_less_than_and_more_than(): void
    {
        $this->seedCustomers();

        // Bilal has exactly 2 orders and exactly 300 spend; his AOV is exactly 150.
        $this->assertNotContains('Bilal', $this->listNames('No. of Orders', 'Less Than', '2'),
            'a customer with exactly 2 orders is not "fewer than 2"');
        $this->assertNotContains('Bilal', $this->listNames('No. of Orders', 'More Than', '2'),
            'a customer with exactly 2 orders is not "more than 2"');
        $this->assertNotContains('Bilal', $this->listNames('Total Spend', 'Less Than', '300'),
            'a customer who spent exactly 300 is not "under 300"');
        $this->assertNotContains('Bilal', $this->listNames('Total Spend', 'More Than', '300'),
            'a customer who spent exactly 300 is not "over 300"');
        $this->assertNotContains('Bilal', $this->listNames('AOV', 'Less Than', '150'),
            'an average of exactly 150 is not "under 150"');
        $this->assertNotContains('Bilal', $this->listNames('AOV', 'More Than', '150'),
            'an average of exactly 150 is not "over 150"');

        // ...and the positive control for all six: he IS returned by the matching Equal.
        $this->assertContains('Bilal', $this->listNames('No. of Orders', 'Equal', '2'),
            'positive control: Bilal exists and the filter runs, so the six absences above are real');
    }
}
