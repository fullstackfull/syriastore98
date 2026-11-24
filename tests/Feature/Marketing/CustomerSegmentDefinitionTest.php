<?php

namespace Tests\Feature\Marketing;

use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Models\CustomerSegmentMember;
use App\Support\Segments\SegmentDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * MKT-004 — WHO IS IN A SEGMENT, FOR BOTH KINDS.
 *
 * DRIVEN AGAINST SEEDED ROWS AND ASSERTING THE IDS THAT COME BACK, never against a SQL string. A
 * predicate can be syntactically present and semantically wrong — the MKT-002 slice measured
 * exactly that, where `HAVING COUNT(*) = 1` and `WHERE COUNT(*) = 1` both "contain the count" and
 * only one is the predicate meant. A membership question has the same property: `whereHas(..., '<=',
 * N)` and a count subquery both read as "at most N orders" and they disagree about the customers who
 * have never ordered at all, which are the ones a merchant most wants.
 *
 * EVERY FIXTURE SEEDS A SECOND TENANT WITH THE SAME SHAPE. Neither `customer_segments` nor
 * `customer_segment_members` is in `config/tenancy.php`'s `scoped_models`, and `null_tenant =>
 * 'open'` means a global scope adds NO predicate when no tenant resolves — so a rule that widened
 * the set would return the other store's customers and these assertions would fail. `Customer` IS
 * scoped, which is why the ASSERTIONS go through `withoutGlobalScopes()` where they check absence:
 * a plain `Customer::find()` answers against whichever tenant happens to be resolved, and CLAUDE.md
 * records a real false reading from exactly that.
 */
final class CustomerSegmentDefinitionTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private function customer($store, string $email): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Seg',
            'last_name' => 'Fixture',
            'email' => $email,
            'store_id' => $store->id,
            'status' => 1,
        ]);
    }

    /** An order attributed to a customer (not a guest), in this store. */
    private function orderFor($store, Customer $customer, string $date): void
    {
        $this->makeOrder($store, [
            'customer_id' => $customer->id,
            'is_guest' => 0,
            'order_date' => $date,
        ]);
    }

    private function cartFor($store, Customer $customer): void
    {
        /*
         * `qty`, NOT `quantity` -- read off `create_carts_table`, which declares
         * `customer_id`, `product_id`, `variant_id`, `qty`, `price`, `store_id`. Inventing a column
         * name has cost this session four fixture failures already, so it is checked rather than
         * assumed.
         */
        DB::table('carts')->insert([
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'product_id' => 1,
            'variant_id' => 0,
            'qty' => 1,
            'price' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function dynamic($store, array $rules): CustomerSegment
    {
        $segment = new CustomerSegment();
        $segment->name = 'Rule '.$this->uniqueFixtureRef();
        $segment->kind = CustomerSegment::KIND_DYNAMIC;
        $segment->definition = SegmentDefinition::fromArray($rules)->toStored();
        $segment->store_id = $store->id;
        $segment->created_by = 1;
        $segment->save();

        return $segment;
    }

    /** @return array<int> the ids a segment resolves to, sorted */
    private function idsIn(CustomerSegment $segment): array
    {
        return $segment->customers()->pluck('customers.id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The vocabulary refuses what it does not know
     * ---------------------------------------------------------------------------------------------
     */

    public function test_an_unknown_rule_is_dropped(): void
    {
        $definition = SegmentDefinition::fromArray(['spent_over' => 500, 'min_orders' => 2]);

        $this->assertSame(['min_orders' => 2], $definition->rules(),
            'a rule the vocabulary does not know reached the definition');
    }

    public function test_a_stored_rule_this_version_no_longer_knows_is_dropped_on_read(): void
    {
        /*
         * THE ROW OUTLIVES THE CODE. A definition written by a later version, restored from a dump
         * or edited by hand must not reach a query builder because it is in the table. `SavedView`
         * applies the same discipline to a saved filter and for the same reason.
         */
        $definition = SegmentDefinition::fromStored('{"from_the_future":true,"min_orders":3}');

        $this->assertSame(['min_orders' => 3], $definition->rules());
    }

    public function test_malformed_json_is_an_empty_definition_not_an_error(): void
    {
        foreach (['not json', '[]', 'null', '', '{"min_orders":"many"}'] as $stored) {
            $this->assertTrue(SegmentDefinition::fromStored($stored)->isEmpty(),
                'a stored definition of '.var_export($stored, true).' was not read as empty');
        }
    }

    public function test_a_negative_count_and_an_impossible_date_are_refused(): void
    {
        $this->assertTrue(SegmentDefinition::fromArray(['min_orders' => -1])->isEmpty());
        $this->assertTrue(SegmentDefinition::fromArray(['min_orders' => 100001])->isEmpty());
        $this->assertTrue(SegmentDefinition::fromArray(['ordered_since' => '2026-02-30'])->isEmpty(),
            'Carbon ROLLS 30 February forward to 1 March rather than refusing it, so the round trip '
            .'has to be compared — otherwise a merchant\'s typo silently becomes a different date');
        $this->assertTrue(SegmentDefinition::fromArray(['ordered_since' => '01/06/2026'])->isEmpty());
    }

    public function test_a_count_of_zero_is_a_rule(): void
    {
        $this->assertSame(['max_orders' => 0], SegmentDefinition::fromArray(['max_orders' => 0])->rules(),
            '"has never ordered" is a real rule and 0 is how it is spelled');
    }

    public function test_a_valueless_rule_is_only_on_for_a_true_marker(): void
    {
        $this->assertSame(['has_abandoned_cart' => true],
            SegmentDefinition::fromArray(['has_abandoned_cart' => '1'])->rules());

        foreach (['0', false, 'off', ''] as $off) {
            $this->assertTrue(SegmentDefinition::fromArray(['has_abandoned_cart' => $off])->isEmpty(),
                'a falsy marker turned a rule on, which would silently widen the segment');
        }
    }

    public function test_a_round_trip_cannot_widen_a_segment(): void
    {
        $stored = SegmentDefinition::fromArray([
            'min_orders' => '3',
            'bogus' => 'x',
            'ordered_since' => '2026-06-01',
        ])->toStored();

        $this->assertSame(
            ['min_orders' => 3, 'ordered_since' => '2026-06-01'],
            SegmentDefinition::fromStored($stored)->rules()
        );
    }

    public function test_no_rule_ships_a_threshold(): void
    {
        /*
         * A SOURCE ASSERTION with comments stripped, for the reason CLAUDE.md records four times
         * over: this class's own docblock explains that no rule ships a number, and a census that
         * reads comments can be satisfied by prose about the thing it measures.
         */
        $source = $this->sourceWithoutComments(app_path('Support/Segments/SegmentDefinition.php'));

        preg_match_all('/\?\?\s*([0-9.]+)/', $source, $matches);

        $this->assertSame([], $matches[1],
            'SegmentDefinition gained a numeric fallback: '.implode(', ', $matches[1]));
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * An empty definition matches nothing, never everything
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_dynamic_segment_with_no_rules_matches_nobody(): void
    {
        [, $store] = $this->makeTenant();
        $this->customer($store, 'a'.$this->uniqueFixtureRef().'@example.test');
        $this->customer($store, 'b'.$this->uniqueFixtureRef().'@example.test');

        $segment = new CustomerSegment();
        $segment->name = 'Empty';
        $segment->kind = CustomerSegment::KIND_DYNAMIC;
        $segment->definition = null;
        $segment->store_id = $store->id;
        $segment->created_by = 1;
        $segment->save();

        $this->assertSame([], $this->idsIn($segment),
            'an unconfigured dynamic segment matched the store\'s whole customer base — the '
            .'dangerous reading of "no filter"');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The rules
     * ---------------------------------------------------------------------------------------------
     */

    public function test_min_orders_counts_only_this_stores_attributed_orders(): void
    {
        [, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();

        $twice = $this->customer($mine, 'twice'.$this->uniqueFixtureRef().'@example.test');
        $once = $this->customer($mine, 'once'.$this->uniqueFixtureRef().'@example.test');
        $never = $this->customer($mine, 'never'.$this->uniqueFixtureRef().'@example.test');

        $this->orderFor($mine, $twice, '2026-06-01');
        $this->orderFor($mine, $twice, '2026-06-02');
        $this->orderFor($mine, $once, '2026-06-01');

        // A GUEST order carrying the same customer_id must not count: `orders.customer_id` defaults
        // to 0 and a guest checkout fills it with whatever it has.
        $this->makeOrder($mine, ['customer_id' => $once->id, 'is_guest' => 1, 'order_date' => '2026-06-03']);

        // Another store's order for a customer id that exists in BOTH stores.
        $elsewhere = $this->customer($theirs, 'elsewhere'.$this->uniqueFixtureRef().'@example.test');
        $this->orderFor($theirs, $elsewhere, '2026-06-01');
        $this->makeOrder($theirs, ['customer_id' => $once->id, 'is_guest' => 0, 'order_date' => '2026-06-04']);

        $this->assertSame([(int) $twice->id], $this->idsIn($this->dynamic($mine, ['min_orders' => 2])),
            'the count admitted a guest order, another store\'s order, or missed a real one');

        /*
         * SORTED ON BOTH SIDES. `idsIn()` sorts so that a test cannot pass or fail on the order a
         * query happens to return rows in -- which means the EXPECTATION has to be sorted too.
         * Written unsorted first, and it failed for that reason rather than a wrong answer.
         */
        $expected = [(int) $once->id, (int) $twice->id];
        sort($expected);

        $this->assertSame($expected, $this->idsIn($this->dynamic($mine, ['min_orders' => 1])));

        $this->assertNotContains((int) $never->id, $this->idsIn($this->dynamic($mine, ['min_orders' => 1])));
    }

    public function test_max_orders_includes_the_customers_who_never_ordered(): void
    {
        [, $store] = $this->makeTenant();

        $twice = $this->customer($store, 'twice'.$this->uniqueFixtureRef().'@example.test');
        $never = $this->customer($store, 'never'.$this->uniqueFixtureRef().'@example.test');

        $this->orderFor($store, $twice, '2026-06-01');
        $this->orderFor($store, $twice, '2026-06-02');

        /*
         * THE CASE THAT DECIDES THE IMPLEMENTATION. `whereHas(..., '<=', 1)` requires at least one
         * related row, so it would silently exclude `$never` — the customer a merchant filtering
         * for "at most 1 order" is most likely looking for. A count subquery answers 0 for them.
         */
        $this->assertSame([(int) $never->id], $this->idsIn($this->dynamic($store, ['max_orders' => 1])),
            'a customer with NO orders was left out of "at most 1 order"');

        $this->assertSame([(int) $never->id], $this->idsIn($this->dynamic($store, ['max_orders' => 0])),
            '"has never ordered" did not resolve to the customer who never ordered');
    }

    public function test_not_ordered_since_includes_a_customer_with_no_orders_at_all(): void
    {
        [, $store] = $this->makeTenant();

        $recent = $this->customer($store, 'recent'.$this->uniqueFixtureRef().'@example.test');
        $lapsed = $this->customer($store, 'lapsed'.$this->uniqueFixtureRef().'@example.test');
        $never = $this->customer($store, 'never'.$this->uniqueFixtureRef().'@example.test');

        $this->orderFor($store, $recent, '2026-06-15');
        $this->orderFor($store, $lapsed, '2026-01-10');

        $dormant = $this->idsIn($this->dynamic($store, ['not_ordered_since' => '2026-06-01']));

        /*
         * A `whereDate('order_date', '<', ...)` would need a row to be false about, so it would
         * report `$never` as ACTIVE — the opposite of the truth, and silently.
         */
        $this->assertContains((int) $never->id, $dormant,
            'a customer who has never ordered was reported as having ordered recently');
        $this->assertContains((int) $lapsed->id, $dormant);
        $this->assertNotContains((int) $recent->id, $dormant);
    }

    public function test_ordered_since_is_inclusive_of_the_day_named(): void
    {
        [, $store] = $this->makeTenant();

        $onTheDay = $this->customer($store, 'onday'.$this->uniqueFixtureRef().'@example.test');
        $dayBefore = $this->customer($store, 'before'.$this->uniqueFixtureRef().'@example.test');

        $this->orderFor($store, $onTheDay, '2026-06-01 09:00:00');
        $this->orderFor($store, $dayBefore, '2026-05-31 23:59:00');

        $since = $this->idsIn($this->dynamic($store, ['ordered_since' => '2026-06-01']));

        // A merchant naming a date means that date. An exclusive bound drops a whole day of orders.
        $this->assertSame([(int) $onTheDay->id], $since);
    }

    public function test_has_abandoned_cart_is_this_stores_baskets(): void
    {
        [, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();

        $withCart = $this->customer($mine, 'cart'.$this->uniqueFixtureRef().'@example.test');
        $without = $this->customer($mine, 'nocart'.$this->uniqueFixtureRef().'@example.test');

        $this->cartFor($mine, $withCart);

        // The same customer id with a basket in ANOTHER store must not count here.
        $this->cartFor($theirs, $without);

        $this->assertSame([(int) $withCart->id], $this->idsIn($this->dynamic($mine, ['has_abandoned_cart' => '1'])),
            'another store\'s basket put a customer in this store\'s segment');
    }

    public function test_rules_combine_with_and(): void
    {
        [, $store] = $this->makeTenant();

        $both = $this->customer($store, 'both'.$this->uniqueFixtureRef().'@example.test');
        $onlyOrders = $this->customer($store, 'orders'.$this->uniqueFixtureRef().'@example.test');
        $onlyCart = $this->customer($store, 'cart'.$this->uniqueFixtureRef().'@example.test');

        $this->orderFor($store, $both, '2026-06-01');
        $this->cartFor($store, $both);
        $this->orderFor($store, $onlyOrders, '2026-06-01');
        $this->cartFor($store, $onlyCart);

        $this->assertSame(
            [(int) $both->id],
            $this->idsIn($this->dynamic($store, ['min_orders' => 1, 'has_abandoned_cart' => '1'])),
            'two rules behaved as OR; a merchant reading a list of conditions reads AND'
        );
    }

    /**
     * TWO SEGMENTS THAT DIFFER ONLY IN A NUMBER MUST NOT ANSWER THE SAME.
     *
     * THIS CAUGHT A REAL DEFECT IN THIS FEATURE'S OWN CODE and is kept as its ratchet. Measured:
     * with `Customer`'s model cache live, asking `min_orders >= 2` and then `min_orders >= 1` in one
     * request returned THE SAME ROWS -- the second answered with the first's result, and
     * `(new Customer)->flushCache()` between them changed the answer, which is how the cache was
     * identified as the cause rather than the predicate.
     *
     * `CacheKey::getBindingsSlug()` reads the MODEL's fresh query bindings, not the builder's, so a
     * value bound into an `Expression` column -- which is what a count subquery is -- contributes
     * nothing to the key. In production (`CACHE_STORE=database`) the stale answer outlives the
     * request, so a merchant would read one segment's members under another's name.
     * `CustomerSegment::customers()` therefore calls `disableCache()`, and this is the test that
     * fails if that is ever removed.
     *
     * IT IS A SEPARATE CASE FROM THE RULE TESTS ON PURPOSE. Those assert the predicate; this
     * asserts that two predicates stay distinguishable, which is a different property and was the
     * one that was broken.
     */
    public function test_two_segments_differing_only_in_a_bound_do_not_share_an_answer(): void
    {
        [, $store] = $this->makeTenant();

        $twice = $this->customer($store, 'twice'.$this->uniqueFixtureRef().'@example.test');
        $once = $this->customer($store, 'once'.$this->uniqueFixtureRef().'@example.test');

        $this->orderFor($store, $twice, '2026-06-01');
        $this->orderFor($store, $twice, '2026-06-02');
        $this->orderFor($store, $once, '2026-06-01');

        // The ORDER of these two reads is the whole test: the first warms whatever the second
        // might reuse.
        $atLeastTwo = $this->idsIn($this->dynamic($store, ['min_orders' => 2]));
        $atLeastOne = $this->idsIn($this->dynamic($store, ['min_orders' => 1]));

        $this->assertSame([(int) $twice->id], $atLeastTwo);

        $this->assertNotSame($atLeastTwo, $atLeastOne,
            'two segments whose rules differ only in a number returned identical members, so one '
            .'of them is showing the other\'s list');

        $expected = [(int) $once->id, (int) $twice->id];
        sort($expected);

        $this->assertSame($expected, $atLeastOne);
    }

    public function test_the_segment_query_does_not_go_through_the_model_cache(): void
    {
        /*
         * THE SOURCE SIDE OF THE CASE ABOVE, with comments stripped. The behavioural test can only
         * fail while the fixture happens to produce two different answers; this states the property
         * directly, so a refactor that reintroduces `Customer::query()` here is named rather than
         * left to luck. Stripped because this file's own prose says `disableCache` repeatedly.
         */
        $source = $this->sourceWithoutComments(app_path('Models/CustomerSegment.php'));

        $this->assertStringContainsString('Customer::disableCache()', $source,
            'the segment query reads `Customer` through the model cache, whose key cannot see a '
            .'value bound into a subquery -- measured to return one segment\'s rows for another');

        $this->assertStringNotContainsString('Customer::query()', $source,
            'a cached `Customer` query reappeared in CustomerSegment');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Static membership
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_static_segment_is_its_rows(): void
    {
        [, $store] = $this->makeTenant();

        $in = $this->customer($store, 'in'.$this->uniqueFixtureRef().'@example.test');
        $this->customer($store, 'out'.$this->uniqueFixtureRef().'@example.test');

        $segment = new CustomerSegment();
        $segment->name = 'Curated';
        $segment->kind = CustomerSegment::KIND_STATIC;
        $segment->store_id = $store->id;
        $segment->created_by = 1;
        $segment->save();

        $member = new CustomerSegmentMember();
        $member->segment_id = $segment->id;
        $member->store_id = $store->id;
        $member->customer_id = $in->id;
        $member->save();

        $this->assertSame([(int) $in->id], $this->idsIn($segment));
    }

    public function test_a_membership_row_naming_another_stores_customer_does_not_resolve(): void
    {
        [, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();

        $foreign = $this->customer($theirs, 'foreign'.$this->uniqueFixtureRef().'@example.test');

        $segment = new CustomerSegment();
        $segment->name = 'Curated';
        $segment->kind = CustomerSegment::KIND_STATIC;
        $segment->store_id = $mine->id;
        $segment->created_by = 1;
        $segment->save();

        /*
         * A ROW THAT SHOULD NOT EXIST, written on purpose: a restored dump, a hand-written row or a
         * future bug. `customers.id` values are reused across tenants in a shared database, so the
         * predicate on `customers.store_id` is what stops a membership row resolving to somebody
         * else's customer — and it is applied in ADDITION to the one on the membership row.
         */
        $member = new CustomerSegmentMember();
        $member->segment_id = $segment->id;
        $member->store_id = $mine->id;
        $member->customer_id = $foreign->id;
        $member->save();

        $this->assertSame([], $this->idsIn($segment),
            'a membership row resolved to another tenant\'s customer');
    }

    public function test_another_segments_members_are_not_in_this_one(): void
    {
        [, $store] = $this->makeTenant();

        $one = $this->customer($store, 'one'.$this->uniqueFixtureRef().'@example.test');
        $two = $this->customer($store, 'two'.$this->uniqueFixtureRef().'@example.test');

        $segments = [];

        foreach (['First', 'Second'] as $name) {
            $segment = new CustomerSegment();
            $segment->name = $name;
            $segment->kind = CustomerSegment::KIND_STATIC;
            $segment->store_id = $store->id;
            $segment->created_by = 1;
            $segment->save();
            $segments[$name] = $segment;
        }

        foreach ([['First', $one], ['Second', $two]] as [$name, $customer]) {
            $member = new CustomerSegmentMember();
            $member->segment_id = $segments[$name]->id;
            $member->store_id = $store->id;
            $member->customer_id = $customer->id;
            $member->save();
        }

        $this->assertSame([(int) $one->id], $this->idsIn($segments['First']));
        $this->assertSame([(int) $two->id], $this->idsIn($segments['Second']));
    }

    public function test_the_same_customer_cannot_be_added_twice(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customer($store, 'dup'.$this->uniqueFixtureRef().'@example.test');

        $segment = new CustomerSegment();
        $segment->name = 'Curated';
        $segment->kind = CustomerSegment::KIND_STATIC;
        $segment->store_id = $store->id;
        $segment->created_by = 1;
        $segment->save();

        $add = function () use ($segment, $store, $customer): void {
            $member = new CustomerSegmentMember();
            $member->segment_id = $segment->id;
            $member->store_id = $store->id;
            $member->customer_id = $customer->id;
            $member->save();
        };

        $add();

        /*
         * THE DATABASE REFUSES IT, not just the controller. A double membership makes every count
         * over the segment wrong and would send a paid message twice — a cost the merchant did not
         * ask for — so the unique index is the control and this proves the index exists.
         */
        $this->expectException(\Illuminate\Database\QueryException::class);
        $add();
    }
}
