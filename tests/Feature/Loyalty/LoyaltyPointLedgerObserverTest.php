<?php

namespace Tests\Feature\Loyalty;

use App\Events\AddRewardClubPoint;
use App\Models\Customer;
use App\Support\Loyalty\LoyaltyAttribution;
use App\Support\Loyalty\LoyaltyLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `MKT-005` — «سجل حركات». EVERY MOVEMENT OF A BALANCE IS OBSERVED AND LOGGED.
 * =================================================================================================
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THE CHOKEPOINT IS A `Customer` OBSERVER AND NOT A LISTENER ON THE EVENT
 * -------------------------------------------------------------------------------------------------
 *
 * MEASURED, by reading the vendored writer. `AddRewardClubPointLis::handle()` moves the balance with
 * exactly two statements:
 *
 *     $customer['total_club_point'] = $totalClubPoint;      // :46
 *     $customer->save();                                    // :47
 *
 * — an ordinary Eloquent save on `App\Models\Customer`. Three consequences decided the design, and
 * {@see self::test_premise_the_vendored_writer_moves_the_balance_through_an_eloquent_save} pins the
 * first so that a module update fails this suite instead of silently emptying the log:
 *
 * 1. **An observer sees the real before and after**, so NO vendored arithmetic is re-derived. The
 *    project skill is explicit that a first-party listener beside a vendored one must stay a shape
 *    normalisation and never *"a second copy of a decision the host already makes elsewhere, or the
 *    two drift"*. The award figure is `product_price * order_points / order_point_price` inside the
 *    module; computing it again here would be that second copy, and it would diverge the first time
 *    upstream changed a rounding.
 * 2. **A listener on `AddRewardClubPoint` could not see the result at all.** Module providers are
 *    brought in from `app->booted()`, so every first-party listener runs STRICTLY EARLIER —
 *    `NormaliseRewardRedemptionFlag`'s docblock records that ordering as measured, and it is the
 *    reason that listener can rewrite the flag the vendored one then reads. Earlier is the wrong end
 *    for an observer of record.
 * 3. **An observer catches every writer**, not only the one that exists today. The Khalti redemption
 *    already takes a discount and fires no event (`PHASE-05-OWNER-DECISION-PACKAGE.md` §2.5); any
 *    future first-party or vendored write lands in the log by construction.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THIS SLICE CHANGES ABOUT A BALANCE: NOTHING
 * -------------------------------------------------------------------------------------------------
 *
 * The only write is an INSERT into `loyalty_point_transactions`.
 * {@see self::test_a_store_without_the_vendored_module_behaves_exactly_as_before} and
 * {@see self::test_the_observer_never_writes_the_balance_it_observes} assert both halves of that.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT IS SIMULATED HERE, SAID PLAINLY
 * -------------------------------------------------------------------------------------------------
 *
 * The vendored listener itself does not run in this suite and cannot be made to: `add_on_managers`
 * is empty so the module's provider never registers it, and its body opens with
 * `module_is_active('RewardClubPoint')`, which resolves its principal from `Auth::check()` and
 * answers FALSE from a test body with nobody signed in even when the row is inserted (CLAUDE.md
 * §1). `GAP-191` records that as the reason nothing in this suite can verify the vendored half.
 *
 * So these cases drive **the real mechanism with a simulated caller**: a real Eloquent save on a real
 * `Customer`, which is what the vendored listener's `:46-47` performs, with the schema given the
 * vendored column by the same fixture `VipTierSegmentRuleTest` uses. The fidelity of that simulation
 * is not asserted by assumption — it is the premise case named above, which reads the vendored file.
 *
 * @see \App\Observers\CustomerLoyaltyPointObserver
 * @see \App\Listeners\RecordLoyaltyPointAttribution
 */
final class LoyaltyPointLedgerObserverTest extends TestCase
{
    use RefreshDatabase;

    private const VENDORED_LISTENER =
        'packages/workdo/RewardClubPoint/src/app/Listeners/AddRewardClubPointLis.php';

    /** @see \Tests\Unit\Loyalty\LoyaltyBalanceTest::giveThisSchemaTheVendoredColumn() */
    private function giveThisSchemaTheVendoredColumn(): void
    {
        $vendored = base_path(
            'packages/workdo/RewardClubPoint/src/database/migrations/'
            . '2024_04_17_105551_add_column_reward_club_point_to_customers_table.php'
        );

        $this->assertFileExists($vendored, 'premise: the vendored migration is still there.');
        $this->assertStringContainsString('total_club_point', (string) file_get_contents($vendored),
            'premise: and it is still the file that declares the column.');

        if (! Schema::hasColumn('customers', 'total_club_point')) {
            Schema::table('customers', function ($table) {
                $table->float('total_club_point', 15, 2)->default(0);
            });
        }
    }

    private function customer(int $storeId, string $email): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Ledger', 'last_name' => 'Fixture',
            'email' => $email, 'store_id' => $storeId, 'status' => 1,
        ]);
    }

    /** @return list<object> raw ledger rows for this customer, oldest first */
    private function ledgerRows(int $storeId, int $customerId): array
    {
        return DB::table('loyalty_point_transactions')
            ->where('store_id', $storeId)
            ->where('customer_id', $customerId)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * What the vendored listener does, done the way it does it.
     *
     * A plain Eloquent assignment and save — see the class docblock, and the premise case that pins
     * those two lines in the vendored file.
     */
    private function moveTheBalanceAsTheVendoredListenerDoes(Customer $customer, float $to): void
    {
        $customer['total_club_point'] = $to;
        $customer->save();
    }

    /*
    |--------------------------------------------------------------------------
    | 1 — premises
    |--------------------------------------------------------------------------
    */

    /**
     * THE FIDELITY PREMISE. If upstream stops writing the balance through an Eloquent save, this
     * observer stops seeing movements — and the log would go quietly empty, which is the one failure
     * mode an audit log must not have. This case is what turns a module update into a red test.
     */
    public function test_premise_the_vendored_writer_moves_the_balance_through_an_eloquent_save(): void
    {
        $path = base_path(self::VENDORED_LISTENER);

        $this->assertFileExists($path, 'premise: the vendored writer is still where it was read.');

        $source = (string) file_get_contents($path);

        $this->assertStringContainsString(
            "\$customer['total_club_point'] = \$totalClubPoint;",
            $source,
            'the vendored listener must still ASSIGN the balance on a Customer instance. If this '
            . 'line changed, re-read the module and re-decide where the chokepoint is.'
        );

        $this->assertStringContainsString(
            '$customer->save();',
            $source,
            'and it must still persist it through an Eloquent save, which is what fires the model '
            . 'events this observer hangs off. A `DB::table(...)->update()` would fire none and the '
            . 'ledger would silently stop recording.'
        );
    }

    public function test_premise_the_vendored_listener_is_not_registered_in_this_suite(): void
    {
        $this->assertSame(
            0,
            DB::table('add_on_managers')->count(),
            'premise: no module is loaded here, so the vendored listener is not registered. These '
            . 'cases drive the real chokepoint with a simulated caller and say so.'
        );

        foreach (Event::getRawListeners()[AddRewardClubPoint::class] ?? [] as $listener) {
            $this->assertStringNotContainsString(
                'AddRewardClubPointLis',
                is_string($listener) ? $listener : '',
                'premise: the vendored listener is not among the registered ones.'
            );
        }

        $this->addToAssertionCount(1);
    }

    /*
    |--------------------------------------------------------------------------
    | 2 — the substrate is absent: today's behaviour, unchanged
    |--------------------------------------------------------------------------
    */

    /**
     * **THE CONSTRAINT THE WHOLE SLICE IS BOUNDED BY.** A store without the vendored module must
     * behave exactly as it does today.
     */
    public function test_a_store_without_the_vendored_module_behaves_exactly_as_before(): void
    {
        $this->assertFalse(
            Schema::hasColumn('customers', 'total_club_point'),
            'premise: this case is about the absent substrate, which is this suite\'s real state.'
        );

        $customer = $this->customer(7, 'nomodule@example.com');

        /* An ordinary customer edit — the thing a merchant does every day. */
        $customer->first_name = 'Renamed';
        $customer->save();

        $this->assertSame(
            'Renamed',
            DB::table('customers')->where('id', $customer->id)->value('first_name'),
            'the save must still work. An observer that threw on an absent column would break '
            . 'every customer edit in every store that has not installed the module.'
        );

        $this->assertSame(
            0,
            DB::table('loyalty_point_transactions')->count(),
            'and nothing may be logged: with no balance column there is no balance, so there is no '
            . 'movement. An `adjust` row here would be the ledger inventing history.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 3 — the substrate is present: movements are logged
    |--------------------------------------------------------------------------
    */

    public function test_an_increase_is_logged_as_an_earn_with_the_balance_it_observed(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $customer = $this->customer(7, 'earn@example.com');
        DB::table('customers')->where('id', $customer->id)->update(['total_club_point' => 100.0]);

        /*
         * Re-read through Eloquent AFTER the raw seed. CLAUDE.md: a raw write leaves `Cachable`
         * Eloquent reads serving the pre-write row, and under `CACHE_STORE=array` that stale answer
         * lives for the rest of the process — so the instance this test then saves must be fetched
         * fresh, or `balance_before` would be observed as the pre-seed figure and this assertion
         * would be measuring the cache.
         */
        (new Customer)->flushCache();
        $fresh = Customer::withoutGlobalScopes()->findOrFail($customer->id);

        $this->assertSame(100.0, (float) $fresh->total_club_point,
            'premise: the instance really holds the seeded balance, so `balance_before` below is '
            . 'the fixture and not the cache.');

        /*
         * ATTRIBUTED, and that is required for the kind to be `earn` rather than `adjust`. The first
         * version of this case omitted the event and asserted `earn`; it failed, and the CONTRACT
         * was right and the test wrong. A balance that moves with nothing to say why is an
         * adjustment — calling it an award would assert a cause the log cannot establish, which is
         * the same "an unmet capability matches nothing, never everything" doctrine the VIP rule
         * follows. `test_an_unattributed_movement_is_logged_as_an_adjustment` holds that half.
         */
        event(new AddRewardClubPoint(
            [],
            ['id' => 900, 'customer_id' => (int) $customer->id, 'store_id' => 7],
            'some-store'
        ));

        $this->moveTheBalanceAsTheVendoredListenerDoes($fresh, 110.5);

        $rows = $this->ledgerRows(7, (int) $customer->id);

        $this->assertCount(1, $rows, 'one movement, one row.');
        $this->assertSame(LoyaltyLedger::EARN, $rows[0]->kind);
        $this->assertSame(10.5, (float) $rows[0]->points_delta);
        $this->assertSame(100.0, (float) $rows[0]->balance_before);
        $this->assertSame(110.5, (float) $rows[0]->balance_after);
    }

    public function test_a_decrease_is_logged_as_a_redemption_with_a_negative_delta(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $customer = $this->customer(7, 'redeem@example.com');
        DB::table('customers')->where('id', $customer->id)->update(['total_club_point' => 500.0]);
        (new Customer)->flushCache();
        $fresh = Customer::withoutGlobalScopes()->findOrFail($customer->id);

        $this->assertSame(500.0, (float) $fresh->total_club_point, 'premise: seeded balance.');

        /*
         * The vendored redemption branch REPLACES the balance with just this order's award — see
         * `NormaliseRewardRedemptionFlag`'s docblock on why that assignment is the correct semantics
         * for that branch and must not be "fixed" to `+=`. So the observable movement is the NET of
         * a full-balance redemption and a fresh award, which is what gets logged.
         */
        event(new AddRewardClubPoint(
            ['club_point_is_active' => 'on'],
            ['id' => 901, 'customer_id' => (int) $customer->id, 'store_id' => 7],
            'some-store'
        ));

        $this->moveTheBalanceAsTheVendoredListenerDoes($fresh, 10.0);

        $rows = $this->ledgerRows(7, (int) $customer->id);

        $this->assertCount(1, $rows);
        $this->assertSame(LoyaltyLedger::REDEEM, $rows[0]->kind);
        $this->assertSame(-490.0, (float) $rows[0]->points_delta,
            'the SIGN is the thing the vendored `reward_club_points` table has no column for.');
        $this->assertSame(500.0, (float) $rows[0]->balance_before);
        $this->assertSame(10.0, (float) $rows[0]->balance_after);
    }

    /**
     * A save that leaves the balance alone logs nothing.
     *
     * Without this case an observer that logged on every `updated` would pass every assertion above,
     * and a merchant renaming a customer would put a zero-delta row in the audit log.
     */
    public function test_a_save_that_does_not_touch_the_balance_logs_nothing(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $customer = $this->customer(7, 'untouched@example.com');
        DB::table('customers')->where('id', $customer->id)->update(['total_club_point' => 100.0]);
        (new Customer)->flushCache();
        $fresh = Customer::withoutGlobalScopes()->findOrFail($customer->id);

        $fresh->first_name = 'Renamed';
        $fresh->save();

        $this->assertSame('Renamed',
            DB::table('customers')->where('id', $customer->id)->value('first_name'),
            'premise: the save really happened, so the empty ledger below is the guard and not a '
            . 'save that never ran.');

        $this->assertSame(0, DB::table('loyalty_point_transactions')->count());
    }

    public function test_rewriting_the_balance_to_the_same_figure_logs_nothing(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $customer = $this->customer(7, 'same@example.com');
        DB::table('customers')->where('id', $customer->id)->update(['total_club_point' => 100.0]);
        (new Customer)->flushCache();
        $fresh = Customer::withoutGlobalScopes()->findOrFail($customer->id);

        $this->moveTheBalanceAsTheVendoredListenerDoes($fresh, 100.0);

        $this->assertSame(
            0,
            DB::table('loyalty_point_transactions')->count(),
            'a zero movement is not a movement. The vendored listener rewrites this column on every '
            . 'qualifying order, so a ledger that logged each rewrite would fill with noise that '
            . 'sums to nothing.'
        );
    }

    /**
     * THE OTHER HALF OF THE BOUNDING CONSTRAINT: a store WITH the module must not have its balances
     * changed by this code.
     */
    public function test_the_observer_never_writes_the_balance_it_observes(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $customer = $this->customer(7, 'untouched-balance@example.com');
        DB::table('customers')->where('id', $customer->id)->update(['total_club_point' => 321.0]);
        (new Customer)->flushCache();
        $fresh = Customer::withoutGlobalScopes()->findOrFail($customer->id);

        $this->moveTheBalanceAsTheVendoredListenerDoes($fresh, 400.0);

        $this->assertSame(
            400.0,
            (float) DB::table('customers')->where('id', $customer->id)->value('total_club_point'),
            'the balance must be exactly what the writer set it to — not rounded, not corrected, '
            . 'not reconciled. Points are money-adjacent and this slice has no authority over them.'
        );

        $this->assertSame(1, DB::table('loyalty_point_transactions')->count(),
            'premise: the observer did run, so the untouched balance above is a choice and not an '
            . 'observer that was never reached.');
    }

    /*
    |--------------------------------------------------------------------------
    | 4 — attribution: what caused the movement
    |--------------------------------------------------------------------------
    */

    /**
     * The real `AddRewardClubPoint` event attributes the next movement to its order.
     *
     * The attribution listener reads only `$event->order` — never the redemption flag's truthiness —
     * so it carries NO dependency on whether it runs before or after `NormaliseRewardRedemptionFlag`.
     * That matters: both are discovered out of `app/Listeners`, and ordering between them is a
     * property of directory iteration, which is not a thing to build on.
     */
    public function test_a_movement_during_an_order_is_attributed_to_that_order(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $customer = $this->customer(7, 'attributed@example.com');
        (new Customer)->flushCache();
        $fresh = Customer::withoutGlobalScopes()->findOrFail($customer->id);

        event(new AddRewardClubPoint(
            ['club_point_is_active' => 'on'],
            ['id' => 412, 'customer_id' => (int) $customer->id, 'store_id' => 7],
            'some-store'
        ));

        $this->assertNotNull(
            app(LoyaltyAttribution::class)->peekFor((int) $customer->id),
            'premise: the event really set an attribution. If the listener is not registered this '
            . 'is null and the case below would silently measure the unattributed path.'
        );

        $this->moveTheBalanceAsTheVendoredListenerDoes($fresh, 25.0);

        $rows = $this->ledgerRows(7, (int) $customer->id);

        $this->assertCount(1, $rows);
        $this->assertSame(412, (int) $rows[0]->order_id);
        $this->assertSame(1, (int) $rows[0]->redemption_claimed,
            'the request claimed a redemption, and that claim is recorded AS a claim. '
            . '`club_point_is_active` is caller-supplied and GAP-214 records the gateway-return '
            . 'handlers as not enforcing it, so it is never used as the movement kind.');
        $this->assertSame(LoyaltyLedger::EARN, $rows[0]->kind,
            'and the KIND still comes from the observed delta, which went up.');
    }

    /**
     * **`'off'` IS NOT A REDEMPTION CLAIM**, and this is the case the whole D7 family turns on.
     *
     * `'off'` is a non-empty string, so it is TRUTHY — and `'off'` is exactly what the shipped
     * checkout posts on every order by a customer who already qualifies to redeem
     * (`clubPointApply.blade.php:25`, traced in `NormaliseRewardRedemptionFlag`'s docblock). So an
     * `!empty()` or a `??`-bound comparison in the attribution listener would stamp
     * `redemption_claimed` on the ordinary earning order of every qualifying shopper, and the audit
     * log would say a redemption was requested on orders where none was. `=== 'on'`, exactly.
     *
     * Written because planning the mutation group found no case covering it: the attributed case
     * above posts `'on'` and asserts the claim is recorded, which a `!empty()` implementation
     * satisfies just as well.
     */
    public function test_the_shipped_checkouts_off_flag_is_not_recorded_as_a_redemption_claim(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $customer = $this->customer(7, 'offflag@example.com');
        (new Customer)->flushCache();
        $fresh = Customer::withoutGlobalScopes()->findOrFail($customer->id);

        event(new AddRewardClubPoint(
            /* What the real checkout posts when the shopper did NOT press Apply. */
            ['club_point_is_active' => 'off'],
            ['id' => 713, 'customer_id' => (int) $customer->id, 'store_id' => 7],
            'some-store'
        ));

        $this->moveTheBalanceAsTheVendoredListenerDoes($fresh, 12.0);

        $rows = $this->ledgerRows(7, (int) $customer->id);

        $this->assertCount(1, $rows);
        $this->assertSame(713, (int) $rows[0]->order_id,
            'premise: the attribution WAS applied, so the flag assertion below is about the flag '
            . 'and not about a listener that never ran.');
        $this->assertSame(
            0,
            (int) $rows[0]->redemption_claimed,
            "`'off'` is truthy, and it is what the shipped form posts. Recording it as a claim "
            . 'would put a false redemption on the ordinary earning order of every shopper who '
            . 'already qualifies to redeem.'
        );
        $this->assertSame(LoyaltyLedger::EARN, $rows[0]->kind);
    }

    /**
     * **THE SAME CLAIM, WITH THE LISTENER DRIVEN DIRECTLY — and this is the case that can fail.**
     *
     * MEASURED: a mutation replacing `$flag === 'on'` with `! empty($flag)` SURVIVED the case above.
     * The reason is worth recording, because it is a fact about this codebase rather than a weak
     * test: `NormaliseRewardRedemptionFlag` is ALSO discovered out of `app/Listeners`, `N` sorts
     * before `R`, so it runs first and REDUCES the flag to `'on'`-or-absent. By the time the
     * attribution listener reads the bag the `'off'` is already gone, so `!empty(null)` is false and
     * the mutant is inert.
     *
     * That is the normaliser protecting a second reader, which is good — and it means the event path
     * cannot demonstrate that THIS listener refuses `'off'` on its own. The ordering it relies on is
     * directory-iteration order, which this slice's own docblock says is not a thing to build on. So
     * the listener is driven directly here, with the bag the checkout really posts, which is the only
     * way to show `=== 'on'` is what refuses it.
     */
    public function test_the_attribution_listener_itself_refuses_the_off_flag_without_the_normaliser(): void
    {
        $event = new AddRewardClubPoint(
            ['club_point_is_active' => 'off'],
            ['id' => 714, 'customer_id' => 4242, 'store_id' => 7],
            'some-store'
        );

        $this->assertSame('off', $event->requests_data['club_point_is_active'],
            'premise: the raw flag really is on the event, so nothing has normalised it away and '
            . 'this case measures the listener\'s own comparison.');

        (new \App\Listeners\RecordLoyaltyPointAttribution)->handle($event);

        $note = app(LoyaltyAttribution::class)->peekFor(4242);

        $this->assertNotNull($note, 'premise: the listener ran and left a note.');
        $this->assertSame(714, $note['order_id']);
        $this->assertFalse(
            $note['redemption_claimed'],
            "`'off'` is a non-empty string and therefore TRUTHY, and it is exactly what the shipped "
            . 'checkout posts. Only an exact `=== \'on\'` refuses it; `!empty()` or a `??`-bound '
            . 'comparison would stamp a false redemption claim on every qualifying order.'
        );
    }

    /**
     * And the normaliser really does strip it on the event path — asserted, because the case above
     * exists only because of that interaction and a silent change to it would make this file's
     * reasoning stale.
     */
    public function test_premise_the_normaliser_strips_the_off_flag_before_the_attribution_listener(): void
    {
        $event = new AddRewardClubPoint(
            ['club_point_is_active' => 'off'],
            ['id' => 715, 'customer_id' => 4343, 'store_id' => 7],
            'some-store'
        );

        event($event);

        $this->assertArrayNotHasKey(
            'club_point_is_active',
            (array) $event->requests_data,
            'NormaliseRewardRedemptionFlag reduces the flag to `on`-or-absent and runs before the '
            . 'attribution listener (both are discovered out of `app/Listeners`; `N` sorts before '
            . '`R`). If this ever stops holding, the attribution listener\'s own `=== \'on\'` is '
            . 'the only thing standing between `off` and a false redemption claim — which is what '
            . 'the case above pins.'
        );
    }

    /**
     * A movement nobody attributed is logged as an `adjust`, not dropped.
     *
     * This is the row that makes an unexplained balance change VISIBLE. A ledger that only recorded
     * movements it could explain would be silent in exactly the case a merchant most needs it.
     */
    public function test_an_unattributed_movement_is_logged_as_an_adjustment(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $customer = $this->customer(7, 'unattributed@example.com');
        (new Customer)->flushCache();
        $fresh = Customer::withoutGlobalScopes()->findOrFail($customer->id);

        $this->moveTheBalanceAsTheVendoredListenerDoes($fresh, 60.0);

        $rows = $this->ledgerRows(7, (int) $customer->id);

        $this->assertCount(1, $rows);
        $this->assertSame(
            LoyaltyLedger::ADJUST,
            $rows[0]->kind,
            'AND NOT `earn`, even though the balance went UP. The sign says which way it moved; it '
            . 'does not say why, and an audit log that guessed would be asserting a cause it cannot '
            . 'establish. This is the case that caught the first draft of the two cases above.'
        );
        $this->assertNull($rows[0]->order_id);
        $this->assertSame(60.0, (float) $rows[0]->points_delta);
        $this->assertSame(0.0, (float) $rows[0]->balance_before,
            'and it still records what it saw, so the row is usable whatever the label says.');
        $this->assertSame(60.0, (float) $rows[0]->balance_after);
    }

    /**
     * An attribution is CONSUMED by the movement it belongs to, so it cannot be applied twice.
     *
     * Without this, one order's attribution would stick to every later balance change in the same
     * request and the log would name the wrong cause — which is worse than naming none.
     */
    public function test_an_attribution_is_used_once_and_not_reapplied(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $customer = $this->customer(7, 'once@example.com');
        (new Customer)->flushCache();
        $fresh = Customer::withoutGlobalScopes()->findOrFail($customer->id);

        event(new AddRewardClubPoint(
            [],
            ['id' => 412, 'customer_id' => (int) $customer->id, 'store_id' => 7],
            'some-store'
        ));

        $this->moveTheBalanceAsTheVendoredListenerDoes($fresh, 10.0);
        $this->moveTheBalanceAsTheVendoredListenerDoes($fresh, 20.0);

        $rows = $this->ledgerRows(7, (int) $customer->id);

        $this->assertCount(2, $rows);
        $this->assertSame(412, (int) $rows[0]->order_id, 'the first movement carries the order.');
        $this->assertNull($rows[1]->order_id,
            'the second does not. A sticky attribution would claim order 412 caused a movement it '
            . 'did not.');
    }

    /**
     * An attribution never crosses to another customer.
     *
     * The attribution is keyed on the customer id precisely so that a leaked one cannot mis-name
     * another customer's movement — which, for a money-adjacent audit log, is the worst outcome
     * available.
     */
    public function test_an_attribution_for_one_customer_does_not_name_anothers_movement(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $theirs = $this->customer(7, 'theirs@example.com');
        $mine = $this->customer(7, 'mine@example.com');
        (new Customer)->flushCache();

        event(new AddRewardClubPoint(
            [],
            ['id' => 412, 'customer_id' => (int) $theirs->id, 'store_id' => 7],
            'some-store'
        ));

        $this->moveTheBalanceAsTheVendoredListenerDoes(
            Customer::withoutGlobalScopes()->findOrFail($mine->id),
            15.0
        );

        $rows = $this->ledgerRows(7, (int) $mine->id);

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->order_id);
        $this->assertSame(LoyaltyLedger::ADJUST, $rows[0]->kind);

        $this->assertNotNull(
            app(LoyaltyAttribution::class)->peekFor((int) $theirs->id),
            'and the rightful owner\'s attribution is still waiting for its own movement, rather '
            . 'than having been consumed by somebody else\'s.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 5 — tenancy
    |--------------------------------------------------------------------------
    */

    /**
     * The movement is logged against the CUSTOMER'S OWN store, never a resolved one.
     *
     * `getCurrentStore()` reads the default (`web`) guard and returns the hardcoded literal `1` for
     * a `super admin` with an empty `current_store`; `null_tenant => 'open'` means the global scope
     * adds no predicate at all when nobody resolves. A ledger that took its `store_id` from either
     * would file a store-7 customer's movement under store 1, where the owning merchant could never
     * see it and another merchant could.
     */
    public function test_the_movement_is_filed_under_the_customers_own_store(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $customer = $this->customer(9, 'tenant@example.com');
        (new Customer)->flushCache();
        $fresh = Customer::withoutGlobalScopes()->findOrFail($customer->id);

        /*
         * A SIGNED-IN PRINCIPAL WHOSE RESOLVED STORE IS SOMEBODY ELSE'S, which is what makes this
         * case able to fail. THE FIRST VERSION HAD NOBODY SIGNED IN and a mutation replacing the
         * customer's own `store_id` with `getCurrentStore() ?? $customer->store_id` SURVIVED it:
         * with no principal the helper answers null, the fallback fires, and the mutant is inert.
         * CLAUDE.md records the same shape ("the store under test was store 1, owned by user 1, so
         * four wrong-folder mutants survived").
         *
         * `current_store` is set to a store id that is neither the customer's nor `1`, so a
         * `getCurrentStore()` predicate lands somewhere provably wrong rather than coincidentally
         * right.
         */
        $operator = \App\Models\User::factory()->create([
            'type' => 'admin', 'created_by' => 1, 'is_active' => 1,
            'current_store' => 777, 'email_verified_at' => now(),
        ]);
        $this->actingAs($operator);

        $this->assertSame(777, (int) getCurrentStore(),
            'premise: the resolved store really is 777, so a mutant that used it would file the '
            . 'movement under a store the customer is not in.');

        $this->moveTheBalanceAsTheVendoredListenerDoes($fresh, 5.0);

        $this->assertSame(
            9,
            (int) DB::table('loyalty_point_transactions')->value('store_id'),
            'the row must name store 9 — the customer\'s own — and NOT 777, the store the '
            . 'signed-in principal happens to be looking at.'
        );
    }

    /**
     * A customer with no store is not logged at all.
     *
     * `where('store_id', null)` becomes `whereNull` in Laravel, so a storeless row would be matched
     * by nothing and visible to nobody while still being in the table. `CustomerController::show()`
     * already refuses such a customer outright for the same reason.
     */
    public function test_a_customer_with_no_store_is_not_logged(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $customer = Customer::forceCreate([
            'first_name' => 'Storeless', 'last_name' => 'Fixture',
            'email' => 'storeless@example.com', 'status' => 1,
        ]);
        (new Customer)->flushCache();
        $fresh = Customer::withoutGlobalScopes()->findOrFail($customer->id);

        $this->assertNull($fresh->store_id, 'premise: the fixture really has no store.');

        $this->moveTheBalanceAsTheVendoredListenerDoes($fresh, 5.0);

        $this->assertSame(
            5.0,
            (float) DB::table('customers')->where('id', $customer->id)->value('total_club_point'),
            'premise: the save still happened, so the empty ledger below is the guard refusing and '
            . 'not an exception swallowing the write.'
        );

        $this->assertSame(0, DB::table('loyalty_point_transactions')->count());
    }

    /*
    |--------------------------------------------------------------------------
    | 6 — it can never fail the request it observes
    |--------------------------------------------------------------------------
    */

    /**
     * With the balance column present and the LEDGER's table gone, the save still succeeds.
     *
     * This observer runs inside payment confirmation. CLAUDE.md records a post-commit insert
     * throwing there and leaving a half-written order at HTTP 500 — final price discounted, balance
     * unchanged, no ledger row, repeatable. A log that can take a payment down is a worse defect
     * than a missing log line.
     */
    public function test_a_ledger_that_cannot_write_does_not_break_the_save(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $customer = $this->customer(7, 'noledger@example.com');
        (new Customer)->flushCache();
        $fresh = Customer::withoutGlobalScopes()->findOrFail($customer->id);

        Schema::drop('loyalty_point_transactions');
        $this->assertFalse(LoyaltyLedger::available(), 'premise: the ledger table is really gone.');

        $this->moveTheBalanceAsTheVendoredListenerDoes($fresh, 77.0);

        $this->assertSame(
            77.0,
            (float) DB::table('customers')->where('id', $customer->id)->value('total_club_point'),
            'the balance movement must have been persisted regardless.'
        );
    }
}
