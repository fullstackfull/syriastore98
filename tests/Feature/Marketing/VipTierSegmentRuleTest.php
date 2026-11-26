<?php

namespace Tests\Feature\Marketing;

use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Support\Segments\SegmentDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;

/**
 * `MKT-005` — VIP. THE HALF THE MATRIX NEVER CARRIED, BUILT ON THE ENGINE THAT ALREADY EXISTS.
 * =================================================================================================
 *
 * `MKT-005` is «نقاط ولاء وVIP وإحالات مع سجل حركات» — *loyalty points, VIP and referrals, with a
 * transaction log*. `DECISION-ID-REGISTER.md` §4.3 records it as **half-tracked**: `loyalty` appears
 * 14× in the traceability matrix and **`VIP` and `referral` appear 0×**.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THIS IS A SEGMENT RULE AND NOT A `vip_tiers` TABLE
 * -------------------------------------------------------------------------------------------------
 *
 * **`ARCH-002` forbids a commerce engine parallel to WorkDo's, and the same logic bars a second
 * audience engine beside the approved one.** `MKT-004`'s dynamic customer segments are that engine,
 * they shipped, and `create_customer_segments_tables`' own docblock uses **"VIP customers"** as its
 * worked example of what a shared segment is for. So a VIP tier is a saved predicate over customers,
 * which is precisely what a dynamic segment is.
 *
 * **MEASURED: it could not express one.** The vocabulary was five rules — `min_orders`,
 * `max_orders`, `ordered_since`, `not_ordered_since`, `has_abandoned_cart` — all counts and dates.
 * There was no loyalty rule and no spend rule, so "VIP" in the migration's own example was
 * inexpressible in the thing the example is about.
 *
 * **SPEND IS STILL NOT OFFERED, and that is a prior decision this change does not disturb.**
 * `SegmentDefinition`'s docblock gives the reason: the metric layer refuses a mixed-currency total,
 * but a segment cannot refuse — it would silently EXCLUDE every customer whose orders are in another
 * currency, invisibly. Loyalty points are not money and carry no denomination, so they are free of
 * that defect. `MKT-005` names loyalty points and VIP in the SAME clause, which is why this is the
 * rule the requirement actually asks for rather than the one that was easiest.
 *
 * **NO THRESHOLD IS SHIPPED.** What counts as VIP is marketing policy and the merchant types it, on
 * the same rule the existing five follow and that `ANA-009` is built on.
 *
 * -------------------------------------------------------------------------------------------------
 * THE TRAP THIS SLICE IS REALLY ABOUT, AND IT IS MEASURED, NOT ASSUMED
 * -------------------------------------------------------------------------------------------------
 *
 * `customers.total_club_point` is declared by **`packages/workdo/RewardClubPoint/src/database/
 * migrations/2024_04_17_105551_add_column_reward_club_point_to_customers_table.php`** — a VENDORED
 * MODULE's migration. **Measured in this suite: `Schema::hasColumn('customers',
 * 'total_club_point')` is FALSE**, and no migration under `database/migrations/` declares it. So a
 * rule that named that column unconditionally would throw `SQLSTATE[HY000]: no such column` in every
 * environment where `RewardClubPoint` is not installed — the whole test suite included, and whatever
 * production looks like, which `GAP-202` records as unanswerable from this container because
 * `add_on_managers` has zero rows.
 *
 * So the rule is CAPABILITY-GATED, in the shape `PlanPurchaseIntent::available()` already uses here
 * ("tests the TABLE, not the columns"), and when the capability is absent it **matches NOTHING**.
 * That direction is not a coin toss: `SegmentDefinition`'s own doctrine is *"AN EMPTY DEFINITION
 * MATCHES NOTHING, not everything… the dangerous reading — no filter, so all customers — would
 * silently address a store's entire customer base."* Ignoring an unevaluable rule would widen the
 * segment past what the merchant asked for, which is that same defect wearing a different hat.
 *
 * {@see self::test_the_loyalty_rule_is_not_offered_where_the_vendored_column_does_not_exist} is the
 * REACHABILITY PREMISE for this whole file, and it is the case that would have caught a naive
 * implementation.
 *
 * @see \App\Support\Segments\SegmentDefinition
 * @see \Tests\Feature\Marketing\CustomerSegmentDefinitionTest  the five pre-existing rules
 */
final class VipTierSegmentRuleTest extends SecurityTestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | fixture
    |--------------------------------------------------------------------------
    */

    private function customer($store, string $email): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Vip', 'last_name' => 'Fixture',
            'email' => $email, 'store_id' => $store->id, 'status' => 1,
        ]);
    }

    private function dynamic($store, array $rules): CustomerSegment
    {
        $segment = new CustomerSegment();
        $segment->name = 'Vip ' . $this->uniqueFixtureRef();
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
        return $segment->customers()->pluck('customers.id')
            ->map(fn ($id): int => (int) $id)->sort()->values()->all();
    }

    /**
     * Give this schema the vendored column, so the EVALUATION cases can run at all.
     *
     * The suite's schema does not have it (see the class docblock), and the module's own migration
     * cannot be run here — `packages/workdo/` migrations only load with the module. So the column is
     * added with the SAME type the vendored migration declares, read from that file rather than
     * guessed, and the assertion below makes the read a premise rather than a hope.
     *
     * `float(15, 2)` EMITS A MySQL `double` and `CLAUDE.md` §1 records a float-precision caveat
     * being attached and WITHDRAWN on exactly that reasoning, so nothing here claims a precision
     * defect from the migration's wording. The rule compares a merchant-typed WHOLE NUMBER with
     * `>=`, which is well-defined on a double at these magnitudes either way.
     */
    private function giveThisSchemaTheVendoredColumn(): void
    {
        $vendored = base_path(
            'packages/workdo/RewardClubPoint/src/database/migrations/'
            . '2024_04_17_105551_add_column_reward_club_point_to_customers_table.php'
        );

        $this->assertFileExists($vendored,
            'premise: the vendored migration that declares `customers.total_club_point` is still '
            . 'where this fixture read its type from. If it moved, re-read the type.');

        $this->assertStringContainsString('total_club_point', (string) file_get_contents($vendored),
            'premise: and it is still the file that declares the column.');

        if (! Schema::hasColumn('customers', 'total_club_point')) {
            Schema::table('customers', function ($table) {
                $table->float('total_club_point', 15, 2)->nullable();
            });
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 1 — the reachability premise: the capability gate
    |--------------------------------------------------------------------------
    */

    /**
     * **THE PREMISE FOR THE WHOLE FILE, and the case a naive implementation fails.**
     *
     * With `RewardClubPoint` not installed, the column does not exist. The rule must then be absent
     * from what a form may offer, and the five currency-free rules must still be offered — a
     * capability gate that took the whole vocabulary down with it would be worse than no gate.
     */
    public function test_the_loyalty_rule_is_not_offered_where_the_vendored_column_does_not_exist(): void
    {
        $this->assertFalse(
            Schema::hasColumn('customers', 'total_club_point'),
            'PREMISE: the suite\'s schema has no `customers.total_club_point`, because the column '
            . 'is declared by a VENDORED module migration under packages/workdo/RewardClubPoint. '
            . 'If this ever becomes true, this case is measuring nothing and the gate is untested.'
        );

        $this->assertArrayNotHasKey(
            'min_loyalty_points',
            SegmentDefinition::availableRules(),
            'a rule whose column does not exist must not be offered: a merchant would save a '
            . 'segment that silently matches nobody and have no way to find out why.'
        );

        foreach (['min_orders', 'max_orders', 'ordered_since', 'not_ordered_since', 'has_abandoned_cart'] as $unaffected) {
            $this->assertArrayHasKey($unaffected, SegmentDefinition::availableRules(), sprintf(
                'the capability gate must not take `%s` down with it — those five rules are '
                . 'currency-free and depend on no module.', $unaffected
            ));
        }
    }

    /** It IS in the full vocabulary, so a stored rule survives until the module arrives. */
    public function test_the_rule_stays_in_the_vocabulary_so_a_stored_definition_round_trips(): void
    {
        $this->assertArrayHasKey('min_loyalty_points', SegmentDefinition::RULES,
            'the full vocabulary is what SANITISES a stored row. Dropping the rule from it would '
            . 'silently delete a merchant\'s saved threshold the first time the segment was opened '
            . 'in an environment without the module, and installing it later would not bring it '
            . 'back.');

        $stored = SegmentDefinition::fromArray(['min_loyalty_points' => 500])->toStored();

        $this->assertSame(
            ['min_loyalty_points' => 500],
            SegmentDefinition::fromStored($stored)->rules(),
            'a round trip must preserve the threshold even where the column is absent.'
        );
    }

    /**
     * **AND WITH THE CAPABILITY ABSENT IT MATCHES NOTHING — NEVER EVERYTHING.**
     *
     * The dangerous direction is to IGNORE an unevaluable rule, which widens the segment to whatever
     * the other rules allow: a merchant who asked for "at least 2 orders AND at least 500 points"
     * would be shown, and could message, every two-order customer in the store.
     */
    public function test_with_the_column_absent_the_rule_matches_nobody_rather_than_everybody(): void
    {
        [, $store] = $this->makeTenant();

        $this->assertFalse(Schema::hasColumn('customers', 'total_club_point'),
            'premise: the capability really is absent in this case.');

        $a = $this->customer($store, 'vip-absent-a-' . uniqid() . '@e.test');
        $b = $this->customer($store, 'vip-absent-b-' . uniqid() . '@e.test');

        $this->assertNotSame([], Customer::withoutGlobalScopes()
            ->where('store_id', $store->id)->pluck('id')->all(),
            'premise: the store HAS customers, so an empty result is the gate and not an empty table.');

        $this->assertSame([], $this->idsIn($this->dynamic($store, ['min_loyalty_points' => 500])),
            'an unevaluable rule must narrow to nothing.');

        /* AND IN COMBINATION, which is where "ignore it" would do the damage. */
        $this->makeOrder($store, ['customer_id' => $a->id, 'is_guest' => 0]);
        $this->makeOrder($store, ['customer_id' => $a->id, 'is_guest' => 0]);

        $this->assertSame(
            [(int) $a->id],
            $this->idsIn($this->dynamic($store, ['min_orders' => 2])),
            'premise: `min_orders => 2` on its own really does select customer A.'
        );
        $this->assertSame(
            [],
            $this->idsIn($this->dynamic($store, ['min_orders' => 2, 'min_loyalty_points' => 500])),
            'adding an unevaluable rule must NARROW the result, not leave it as it was. Ignoring it '
            . 'would hand the merchant A as a VIP on no evidence at all.'
        );

        unset($b);
    }

    /*
    |--------------------------------------------------------------------------
    | 2 — the rule itself, with the capability present
    |--------------------------------------------------------------------------
    */

    /** The threshold selects on `>=`, and the rule becomes available once the column exists. */
    public function test_the_rule_selects_customers_at_or_above_the_threshold(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        [, $store] = $this->makeTenant();

        $this->assertArrayHasKey('min_loyalty_points', SegmentDefinition::availableRules(),
            'with the column present the rule must be offered — otherwise the gate is a one-way '
            . 'door and installing the module changes nothing.');

        $below = $this->customer($store, 'vip-below-' . uniqid() . '@e.test');
        $exactly = $this->customer($store, 'vip-exact-' . uniqid() . '@e.test');
        $above = $this->customer($store, 'vip-above-' . uniqid() . '@e.test');
        $never = $this->customer($store, 'vip-never-' . uniqid() . '@e.test');

        /*
         * Written through Eloquent, NOT `DB::table()->update()`. `Customer` is `Cachable`, and
         * CLAUDE.md records three measured false readings from a raw write leaving Eloquent reads
         * (route-model binding included) serving the pre-write row for the rest of the test.
         */
        foreach ([[$below, 499], [$exactly, 500], [$above, 501]] as [$customer, $points]) {
            $customer->total_club_point = $points;
            $customer->save();
        }

        $this->assertSame(
            [(int) $exactly->id, (int) $above->id],
            $this->idsIn($this->dynamic($store, ['min_loyalty_points' => 500])),
            'at-or-above, so the threshold itself is IN. 499 is out, 501 is in, and a customer who '
            . 'has never earned a point is out.'
        );

        unset($never);
    }

    /**
     * A customer who has NEVER earned a point has NULL, and `NULL >= 0` is NULL in SQL — so a
     * threshold of 0 would exclude exactly the people "at least zero points" plainly includes.
     *
     * This is the `max_orders` lesson in another column: `whereHas(..., '<=', N)` needs a related
     * row to be false about and so skips the customers a merchant most wants. Asserted rather than
     * reasoned, because the two readings are indistinguishable in the source.
     */
    public function test_a_threshold_of_zero_includes_the_customers_who_never_earned_a_point(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        [, $store] = $this->makeTenant();

        $earner = $this->customer($store, 'vip-zero-earner-' . uniqid() . '@e.test');
        $neverEarned = $this->customer($store, 'vip-zero-never-' . uniqid() . '@e.test');

        $earner->total_club_point = 10;
        $earner->save();

        $this->assertNull(
            Customer::withoutGlobalScopes()->find($neverEarned->id)->total_club_point,
            'premise: a customer who never earned really does hold NULL rather than 0, which is '
            . 'what makes this case different from a `>= 0` comparison on a number.'
        );

        $this->assertSame(
            [(int) $earner->id, (int) $neverEarned->id],
            $this->idsIn($this->dynamic($store, ['min_loyalty_points' => 0])),
            '"at least 0 points" is everyone, NULL balances included.'
        );
    }

    /**
     * **TENANCY.** Both segment tables are absent from `config/tenancy.php`'s `scoped_models` and
     * `null_tenant => 'open'` adds no predicate, so the store id is applied by `applyTo()` itself.
     * A loyalty rule that forgot it would return the other store's VIPs.
     */
    public function test_the_rule_does_not_reach_another_stores_customers(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $mine = $this->customer($storeA, 'vip-mine-' . uniqid() . '@e.test');
        $theirs = $this->customer($storeB, 'vip-theirs-' . uniqid() . '@e.test');

        foreach ([$mine, $theirs] as $customer) {
            $customer->total_club_point = 900;
            $customer->save();
        }

        $this->assertSame(
            [(int) $mine->id],
            $this->idsIn($this->dynamic($storeA, ['min_loyalty_points' => 500])),
            'store B\'s 900-point customer is not store A\'s VIP.'
        );
        $this->assertSame(
            [(int) $theirs->id],
            $this->idsIn($this->dynamic($storeB, ['min_loyalty_points' => 500])),
            'premise: and the other direction holds too, so the case above is not satisfied by an '
            . 'empty fixture.'
        );
    }

    /**
     * **THE MERCHANT'S FORM MUST NOT OFFER A RULE THAT CANNOT BE EVALUATED.**
     *
     * Written because a mutation survived without it: reverting the controller to
     * `SegmentDefinition::RULES` left all eight cases of this file green, because every one of them
     * calls the class directly. A capability gate nothing drives through the screen is a gate on
     * paper — the merchant would still be offered the rule, save it, and see an empty segment with
     * nothing on screen to explain why.
     *
     * Driven through the real route with a real permission, the pattern `CustomerSegmentScreenTest`
     * uses. `Manage Customer` is the controller's permission and is seeded; `current_store` is set
     * because `getCurrentStore()` reads the DEFAULT guard and the handler scopes on it.
     */
    public function test_the_segment_screen_does_not_offer_a_rule_whose_column_is_absent(): void
    {
        $this->assertFalse(Schema::hasColumn('customers', 'total_club_point'),
            'premise: the capability is absent, so the screen has something to hide.');

        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Customer');

        $merchant->current_store = $store->id;
        $merchant->save();

        $response = $this->actingAs($merchant->fresh())->get('/customer-segments');

        $response->assertOk();

        $rules = $response->original->getData()['rules'] ?? null;

        $this->assertIsArray($rules,
            'premise: the screen really does hand the view a rule list. If this is null the '
            . 'assertion below passes vacuously.');
        $this->assertArrayHasKey('min_orders', $rules,
            'premise: and it is the vocabulary list, not something else.');

        $this->assertArrayNotHasKey('min_loyalty_points', $rules,
            'the screen offered a rule that cannot be evaluated in this environment. A merchant '
            . 'would save a VIP threshold and get an empty segment with no explanation.');
    }

    /**
     * `applyTo()` CARRIES ITS OWN STORE PREDICATE, asserted DIRECTLY rather than through the model.
     *
     * Recorded because a mutation exposed a claim this file would otherwise have made falsely.
     * Deleting `applyTo()`'s `where('customers.store_id', …)` changes NO answer in any other case
     * here, or in `CustomerSegmentDefinitionTest` — because `CustomerSegment::customers()` applies
     * the same predicate itself *before* handing the builder over, so through that path the two are
     * equivalent. `applyTo()` is public and its docblock states the predicate is deliberate ("THE
     * STORE IS A PARAMETER AND NOT `getCurrentStore()`"), so the next caller may not come through
     * the model — and `Customer`'s global scope is no backstop, since `null_tenant => 'open'` adds
     * nothing when no tenant resolves, which is the case here and for every console command.
     */
    public function test_apply_to_scopes_the_store_itself_and_not_only_through_the_model(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $mine = $this->customer($storeA, 'vip-direct-mine-' . uniqid() . '@e.test');
        $theirs = $this->customer($storeB, 'vip-direct-theirs-' . uniqid() . '@e.test');

        foreach ([$mine, $theirs] as $customer) {
            $customer->total_club_point = 900;
            $customer->save();
        }

        /* An UNPREDICATED builder, so `applyTo()`'s own predicate is the only control in the way. */
        $ids = SegmentDefinition::fromArray(['min_loyalty_points' => 500])
            ->applyTo(Customer::withoutGlobalScopes()->disableCache(), (int) $storeA->id)
            ->pluck('customers.id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

        $this->assertSame([(int) $mine->id], $ids,
            'applyTo() must scope the store itself. Given a builder with no predicate at all, it '
            . 'returned another tenant\'s 900-point customer.');
    }

    /**
     * **A LIVE DEFECT FOUND ON THE WAY TO THE VIP RULE: THE SEGMENT SCREEN WAS HTTP 500 FOR EVERY
     * DYNAMIC SEGMENT, SO A MERCHANT COULD CREATE A VIP TIER AND NEVER OPEN IT.**
     *
     * `customer_segments/show.blade.php` called `$segment->definition()`. **There is no such
     * method** — `App\Models\CustomerSegment` declares `rule()`. Eloquent forwards an unknown method
     * to the query builder, so the call ended in
     * `BadMethodCallException: Call to undefined method App\Models\CustomerSegment::definition()`.
     * Executed, not read: `GET /customer-segments/{id}` on a merchant's OWN dynamic segment,
     * `APP_DEBUG` off, **HTTP 500**.
     *
     * **IT IS THE EXACT TRAP THAT MODEL'S OWN DOCBLOCK DOCUMENTS**, which is what makes it worth
     * this many words: *"NAMED `rule()` AND NOT `definition()`, which is the column's name. Eloquent's
     * `getAttribute()` treats a method whose name matches no attribute as a RELATIONSHIP … so on any
     * instance where `definition` was never assigned, reading `$this->definition` would have been a
     * 500 on a merchant screen."* The model was renamed and **the Blade kept calling the old name**.
     *
     * **WHY IT SURVIVED ITS OWN SCREEN TEST.** `CustomerSegmentScreenTest` drives the show route
     * exactly once, at `URI.'/'.$foreign->id` — a segment belonging to ANOTHER store, which the
     * handler refuses before it renders anything. So the suite opened the screen only on the path
     * that never reaches the view, and the 500 was invisible to a green test named for the screen.
     *
     * Kept HERE rather than in that file because this is where the finding was made and MKT-005's
     * VIP tier is the thing it blocks: a dynamic segment nobody can open is not a usable tier.
     *
     * `APP_DEBUG` IS FORCED OFF, because with it on Laravel renders the exception page and the
     * status is still 500 but the body differs — and `CLAUDE.md` records measuring with the debug
     * bar on producing findings that are not in the product.
     */
    public function test_a_merchant_can_open_their_own_dynamic_segment(): void
    {
        config(['app.debug' => false]);

        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Customer');

        $merchant->current_store = $store->id;
        $merchant->save();

        $segment = $this->dynamic($store, ['min_orders' => 1]);

        $this->assertFalse($segment->rule()->isEmpty(),
            'premise: the segment really carries a rule, so the view reaches the branch that '
            . 'renders one. An empty definition skips it and the case would pass vacuously.');

        $response = $this->actingAs($merchant->fresh())->get('/customer-segments/' . $segment->id);

        $this->assertSame(200, $response->status(), sprintf(
            'the segment screen must open. A 500 here means the view is calling a method the model '
            . 'does not have — a merchant can create a VIP tier and never look at it. Status: %d',
            $response->status()
        ));

        /* And the rule is rendered by its LABEL, not by its raw key. */
        $response->assertSee(__(SegmentDefinition::RULES['min_orders']['label']), false);
    }

    /**
     * **THE SHOW SCREEN NEEDS EVERY LABEL, NOT ONLY THE AVAILABLE ONES.**
     *
     * `show.blade.php` looks a stored rule's label up as `$rules[$ruleKey]['label'] ?? $ruleKey`, so
     * handing it the capability-filtered list would print the raw key `min_loyalty_points` at a
     * merchant. That is a real path, not a hypothetical: a store installs `RewardClubPoint`, builds
     * a VIP tier, and the module is later removed.
     *
     * So the two call sites differ ON PURPOSE — `index()` renders the CREATE FORM and offers only
     * what can be evaluated; `show()` DISPLAYS a stored definition and needs the whole vocabulary.
     */
    public function test_the_show_screen_can_name_a_stored_rule_whose_capability_is_absent(): void
    {
        config(['app.debug' => false]);

        $this->assertFalse(Schema::hasColumn('customers', 'total_club_point'),
            'premise: the capability is absent, which is the situation this case is about.');

        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Customer');

        $merchant->current_store = $store->id;
        $merchant->save();

        $segment = $this->dynamic($store, ['min_loyalty_points' => 500]);

        $this->assertSame(['min_loyalty_points' => 500], $segment->rule()->rules(),
            'premise: the stored rule survived sanitising, which is what `RULES` is for.');

        $response = $this->actingAs($merchant->fresh())->get('/customer-segments/' . $segment->id);

        $response->assertOk();
        $response->assertSee(__(SegmentDefinition::RULES['min_loyalty_points']['label']), false);
        $response->assertDontSee('min_loyalty_points', false);
    }

    /** The sanitiser treats the threshold exactly as the other integer rules do. */
    public function test_the_threshold_is_sanitised_like_every_other_integer_rule(): void
    {
        foreach ([-1, 'abc', '', null, []] as $bad) {
            $this->assertTrue(
                SegmentDefinition::fromArray(['min_loyalty_points' => $bad])->isEmpty(),
                sprintf('a threshold of %s must be dropped, not stored.', var_export($bad, true))
            );
        }

        $this->assertSame(
            ['min_loyalty_points' => 500],
            SegmentDefinition::fromArray(['min_loyalty_points' => '500'])->rules(),
            'a numeric string from a form posts as a string and must coerce to the integer.'
        );
    }

    /**
     * NOTHING IN THE RULE REACHES SQL AS TEXT.
     *
     * `SegmentDefinition`'s docblock states the posture: *"There is no column name, no operator and
     * no fragment taken from the row."* `GAP-338`/`GAP-341` record a dynamic-raw-SQL census that
     * reported one site where there were fifteen, after a live cross-tenant injection had been found
     * among them — so the stored definition reaching a query builder is worth an explicit case.
     */
    public function test_a_hostile_stored_threshold_cannot_reach_the_query(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        [, $store] = $this->makeTenant();
        $customer = $this->customer($store, 'vip-inj-' . uniqid() . '@e.test');
        $customer->total_club_point = 900;
        $customer->save();

        $hostile = '{"min_loyalty_points":"0 OR 1=1 --"}';

        $definition = SegmentDefinition::fromStored($hostile);

        $this->assertTrue($definition->isEmpty(),
            'a non-integer threshold is dropped by the sanitiser before it can reach a builder.');

        /* And the query still runs and still answers nothing, rather than throwing or matching all. */
        $segment = new CustomerSegment();
        $segment->name = 'Vip hostile ' . $this->uniqueFixtureRef();
        $segment->kind = CustomerSegment::KIND_DYNAMIC;
        $segment->store_id = $store->id;
        $segment->created_by = 1;
        $segment->save();

        DB::table('customer_segments')->where('id', $segment->id)->update(['definition' => $hostile]);
        $segment = CustomerSegment::withoutGlobalScopes()->find($segment->id);

        $this->assertSame([], $this->idsIn($segment),
            'an empty definition matches NOTHING, which is what stops a dropped rule from becoming '
            . '"no filter, so all customers".');
    }
}
