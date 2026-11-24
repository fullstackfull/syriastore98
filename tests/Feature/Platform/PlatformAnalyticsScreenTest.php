<?php

namespace Tests\Feature\Platform;

use App\Support\Metrics\PlatformAnalyticsFigures;
use Carbon\CarbonImmutable;
use Shetabit\Visitor\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P5-7 — THE PLATFORM OPERATOR'S CROSS-TENANT ANALYTICS SCREEN.
 *
 * TWO HALVES, AND THE FIRST IS THE IMPORTANT ONE. This screen reads every tenant's money, so the
 * door is the whole control: there is no row-level fallback behind it, and `Store` is not in
 * `config/tenancy.php`'s `scoped_models`, so nothing narrows the read for a principal who gets in.
 *
 * THE DIFFERENTIATING TEST IS `test_a_staff_member_holding_manage_reports_is_still_refused`. Every
 * other principal in this file would also be refused by a careless gate, so none of them would
 * catch the mistake this slice is most likely to make: reusing `ReportPermission`, which is right
 * for `/analytics` and wrong here because its second and third limbs admit the tenant OWNER and
 * any holder of `Manage Reports` -- both merchants. A merchant creates their own roles, so a
 * permission name is substantially self-assignable; identity is not.
 *
 * `config(['app.debug' => false])` before any body is read, as ReportAuthorizationTest does: an
 * APP_DEBUG query log was once nearly mis-filed as a disclosure in this repository.
 */
final class PlatformAnalyticsScreenTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const URI = '/platform/analytics';

    private const PERMISSION = 'Manage Reports';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    /**
     * A PAID order with an explicit currency, which is what the money figure reads.
     *
     * The financial row is UPDATED rather than inserted: `OrderObserver::created()` already writes
     * one for every order inserted through Eloquent, so an insert here would give the order two
     * ledger rows and the sum would double-count it.
     */
    private function paidOrder($store, string $currency, float $amount, ?CarbonImmutable $paidAt = null): void
    {
        $order = $this->makeOrder($store, ['order_date' => $paidAt ?? CarbonImmutable::now()->subDay()]);

        DB::table('store_order_financials')->where('order_id', $order->id)->update([
            'payment_status' => 'paid',
            'currency_code' => $currency,
            'paid_amount' => $amount,
            'remaining_amount' => 0.00,
            'paid_at' => $paidAt ?? CarbonImmutable::now()->subDay(),
        ]);
    }

    /** Figures are wrapped in bidi isolates for mixed-script rendering; compare without them. */
    private function plain(?string $text): string
    {
        return str_replace(["\u{2066}", "\u{2069}"], '', (string) $text);
    }

    /** A shopper of one store. Mirrors MerchantAnalyticsFiguresTest's own helper. */
    private function customerIn(int $storeId): \App\Models\Customer
    {
        return \App\Models\Customer::forceCreate([
            'first_name' => 'Nour',
            'last_name' => 'Saleh',
            'email' => 'shopper-'.$this->uniqueFixtureRef().'@example.test',
            'password' => bcrypt('x'),
            'store_id' => $storeId,
        ]);
    }

    /** An order the ledger cannot see: the observer's row is created and then removed. */
    private function orderTheLedgerCannotSee($store, array $extra = []): void
    {
        $order = $this->makeOrder($store, $extra);

        DB::table('store_order_financials')->where('order_id', $order->id)->delete();
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The door
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_signed_out_visitor_cannot_open_it(): void
    {
        $response = $this->get(self::URI);

        $this->assertTrue($response->isRedirect(),
            'the cross-tenant screen was served to a guest (status '.$response->status().')');
        $this->assertNotSame(401, $response->status(),
            'a 401 forces a logout in the shipped Flutter client; `auth` runs first and redirects');
    }

    public function test_a_merchant_cannot_open_it(): void
    {
        [$merchant] = $this->makeTenant();

        $response = $this->actingAs($merchant->fresh())->get(self::URI);

        $response->assertStatus(403);
    }

    public function test_a_staff_member_cannot_open_it(): void
    {
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);

        $response = $this->actingAs($staff->fresh())->get(self::URI);

        $response->assertStatus(403);
    }

    /**
     * THE TEST THIS FILE EXISTS FOR.
     *
     * `Manage Reports` opens `/analytics` for this very principal -- asserted here as a premise, so
     * the refusal below cannot be passing for the unrelated reason that the permission was never
     * granted. The same permission must NOT open the cross-tenant screen.
     */
    public function test_a_staff_member_holding_manage_reports_is_still_refused(): void
    {
        [$merchant] = $this->makeTenant();
        $holder = $this->makeStaff($merchant);
        $this->grantPermission($holder, self::PERMISSION);

        $holder = $holder->fresh();

        $this->assertTrue($holder->isAbleTo(self::PERMISSION),
            'Premise: this principal really does hold '.self::PERMISSION.'.');
        $this->actingAs($holder)->get('/analytics')->assertStatus(200);

        $response = $this->actingAs($holder)->get(self::URI);

        $response->assertStatus(403);
    }

    /**
     * REQUEST INPUT IS IGNORED, asserted rather than read off the current code.
     *
     * Neither handler reads anything from the request today, which is the design: CLAUDE.md records
     * that a tenant predicate built from caller-supplied input is not a tenant check and has
     * produced four separate defects here. A probe makes that an asserted property, so a later edit
     * that starts honouring a `store_id` reddens instead of quietly narrowing a cross-tenant screen
     * to one tenant -- or widening a merchant one.
     */
    public function test_a_store_id_in_the_query_string_changes_nothing(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $this->paidOrder($storeA, 'USD', 100.00);
        $this->paidOrder($storeB, 'SYP', 250.00);

        $operator = $this->makePlatformAdmin();

        $plain = $this->actingAs($operator->fresh())->get(self::URI)->getContent();
        $probed = $this->actingAs($operator->fresh())->get(self::URI.'?store_id='.$storeA->id)->getContent();

        $this->assertSame(
            substr_count($plain, 'SYP'),
            substr_count($probed, 'SYP'),
            'naming one store in the query string changed what the cross-tenant screen reported'
        );
        $this->assertStringContainsString('SYP', $probed,
            'the other tenant\'s currency must still be reported when a store_id is supplied');
    }

    public function test_the_platform_operator_can_open_it(): void
    {
        $operator = $this->makePlatformAdmin();

        $response = $this->actingAs($operator->fresh())->get(self::URI);

        $response->assertStatus(200);
    }

    /**
     * The operator needs no store selected, which is where this screen differs from `/analytics`.
     * `getCurrentStore()` returns the hardcoded literal 1 for a `super admin`, so the merchant
     * screen must refuse an operator who has chosen nothing rather than present one tenant's
     * figures as the platform's. This screen never asks.
     */
    public function test_the_operator_needs_no_store_selected(): void
    {
        $operator = $this->makePlatformAdmin(['current_store' => null]);

        $this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200);
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The figures
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * TWO CURRENCIES ARE TWO ROWS AND NEVER ONE TOTAL.
     *
     * Converting them would need the rate on each order's own date and this schema records none, so
     * a single platform revenue figure -- the one an operator most wants -- is the figure the data
     * cannot support. Both amounts must appear, and no row may hold their sum.
     */
    public function test_money_is_reported_per_currency_and_never_summed(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $this->paidOrder($storeA, 'USD', 100.00);
        $this->paidOrder($storeB, 'SYP', 250.00);

        $figures = PlatformAnalyticsFigures::forPlatform();
        $rows = collect($figures['payments']['rows']);

        $this->assertSame(['SYP', 'USD'], $rows->pluck('currency')->sort()->values()->all(),
            'both currencies must be reported, each on its own row');

        $amounts = $rows->pluck('amount')->map(fn ($a) => preg_replace('/[^0-9.]/', '', $a))->all();

        $this->assertContains('100.00', $amounts);
        $this->assertContains('250.00', $amounts);
        $this->assertNotContains('350.00', $amounts,
            'the two currencies were added together; no exchange rate exists to justify that');
    }

    /**
     * A STORE THE LEDGER CANNOT VOUCH FOR IS EXCLUDED AND COUNTED, never counted as zero. A zero
     * would read as "that tenant sold nothing"; the truth is that we cannot say.
     */
    public function test_a_store_with_no_readable_ledger_is_excluded_and_disclosed(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $this->paidOrder($storeA, 'USD', 100.00);
        $this->orderTheLedgerCannotSee($storeB, ['order_date' => null]);

        $figures = PlatformAnalyticsFigures::forPlatform();

        $this->assertNotContains($storeB->id, $figures['coverage']['included'],
            'a store whose ledger can vouch for no period was included in the money figures');
        $this->assertContains($storeB->id, $figures['coverage']['excluded']);

        /*
         * The RENDERED form, not a bare '1'. `assertStringContainsString('1', ...)` against a long
         * sentence would also pass for 11 or 21, or for an unrelated digit -- flagged by review.
         * The figures class wraps every number in bidi isolates, so this pins the exact token.
         */
        $one = "\u{2066}1\u{2069}";

        $this->assertStringContainsString($one, $figures['payments']['hint'],
            'the excluded store must be disclosed by count beside the figure');
        $this->assertStringContainsString($one, $figures['stores']['hint']);
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The three defects an independent review found in the first version of this screen
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * (3a) A STORE THE LEDGER CANNOT VOUCH FOR STILL CONTRIBUTES ITS BUYERS.
     *
     * The first version computed this tile on `CustomerRetention`'s DEFAULT basis, which is
     * settled-only: an INNER JOIN to `store_order_financials` on `payment_status = 'paid'`. That
     * made a headcount into a paid-only figure and contributed a SILENT ZERO for every store the
     * ledger cannot see — the same "that tenant sold nothing" misreading this screen refuses
     * elsewhere by excluding and counting such stores instead. It also disagreed with
     * `/analytics`'s own `Buyers` tile, which counts distinct `customer_id` on `orders` with no
     * payment predicate at all. Two tiles with one label must mean one thing.
     */
    public function test_buyers_counts_a_customer_whose_order_the_ledger_cannot_see(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $buyer = $this->customerIn((int) $store->id);
        $order = $this->makeOrder($store, [
            'customer_id' => $buyer->id,
            'is_guest' => 0,
            'order_date' => CarbonImmutable::now()->subDay(),
        ]);
        DB::table('store_order_financials')->where('order_id', $order->id)->delete();

        $figures = PlatformAnalyticsFigures::forPlatform();

        $this->assertSame("\u{2066}1\u{2069}", $figures['buyers']['value'],
            'a buyer whose only order has no financial row was counted as nobody');
    }

    /**
     * (3b) "LAST 30 DAYS" IS 30 DAYS, AND IS BOUNDED AT BOTH ENDS.
     *
     * The first version used `MetricWindow::since($now->subDays(30))`. `MetricWindow::applyTo()`
     * widens `from` with `startOfDay()` and applies NO upper bound when `to` is null, so that
     * window spanned THIRTY-ONE calendar days under a "last 30 days" label and admitted
     * future-dated orders — which `LedgerCoverage` records can legitimately exist, because
     * `order_date` may be stamped up to 5.5 hours ahead. The same store's order count therefore
     * differed between `/analytics` and `/platform/analytics`.
     *
     * Three orders, one assertion: only the in-window one may be counted.
     */
    public function test_the_recent_window_is_thirty_days_and_excludes_the_future(): void
    {
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');

        [$merchant, $store] = $this->makeTenant();

        $this->makeOrder($store, ['order_date' => $now->subDays(29)]);   // in
        $this->makeOrder($store, ['order_date' => $now->subDays(30)]);   // one day too old
        $this->makeOrder($store, ['order_date' => $now->addDay()]);      // future-dated

        $figures = PlatformAnalyticsFigures::forPlatform($now);

        $this->assertSame("\u{2066}1\u{2069}", $figures['orders']['value'],
            'the 30-day window counted an order outside it (31st day, or the future)');
    }

    /**
     * (3c) NO "LATEST START" SENTENCE WHEN NO START DATE IS SHOWN.
     *
     * A store can be included with `isComplete()` false and `verifiedFrom()` null: the state where
     * every order the ledger cannot see is UNDATED, for which `LedgerCoverage::window()` returns
     * all-time. The first version then printed the period as "all time" AND appended "the latest
     * start among the stores included" — a sentence about a date the reader cannot see, attached to
     * a figure claiming coverage it does not have. Those undated orders were also disclosed
     * nowhere; now they are, by count.
     */
    public function test_no_start_date_sentence_is_printed_when_the_period_is_all_time(): void
    {
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');

        [$merchant, $store] = $this->makeTenant();

        // One order the ledger CAN see, so the store is included rather than excluded...
        $this->paidOrder($store, 'USD', 100.00, $now->subDay());
        // ...and one it cannot, with NO date, so verifiedFrom stays null while isComplete is false.
        $this->orderTheLedgerCannotSee($store, ['order_date' => null]);

        $figures = PlatformAnalyticsFigures::forPlatform($now);

        $this->assertNull($figures['coverage']['verifiedFrom'], 'Premise: no verified start exists.');
        $this->assertFalse($figures['coverage']['allComplete'], 'Premise: the store is incomplete.');

        $this->assertStringNotContainsString('latest start', $figures['payments']['hint'],
            'a sentence about the latest start was printed while the period read "all time"');
        $this->assertStringContainsString("\u{2066}1\u{2069}", $figures['payments']['hint'],
            'the undated order the ledger cannot see must be disclosed by count');
    }

    /**
     * THE PERIOD IS THE LATEST START AMONG THE STORES INCLUDED, not the earliest.
     *
     * A sum across stores is only as complete as its least complete contributor. Taking the
     * earliest would claim coverage the ledger does not have for the other store -- which is the
     * mistake that makes a cross-tenant total look better documented than it is.
     */
    public function test_the_period_is_the_latest_start_among_the_included_stores(): void
    {
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');

        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        /*
         * Each store needs BOTH an order the ledger cannot see and one it can. `hasNothingVerified()`
         * is `orders > 0 && withoutRow === orders`, so a store whose every order lacks a row has no
         * verified period at all and is EXCLUDED rather than dated -- which is what the first draft
         * of this fixture produced, giving a null platform start and proving nothing about which
         * date is chosen.
         */
        $this->orderTheLedgerCannotSee($storeA, ['order_date' => $now->subDays(10)]);
        $this->paidOrder($storeA, 'USD', 10.00, $now->subDays(1));

        $this->orderTheLedgerCannotSee($storeB, ['order_date' => $now->subDays(3)]);
        $this->paidOrder($storeB, 'USD', 10.00, $now->subDays(1));

        $figures = PlatformAnalyticsFigures::forPlatform($now);

        $this->assertNotNull($figures['coverage']['verifiedFrom']);
        $this->assertSame(
            $now->subDays(2)->toDateString(),
            $figures['coverage']['verifiedFrom']->toDateString(),
            'the platform-wide verified start must be the day after the LATEST order the ledger '
            . 'cannot see, which is store B\'s, not store A\'s'
        );
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * P5-R13 — repeat purchase, the first of the nine figures the operator screen was missing
     * ---------------------------------------------------------------------------------------------
     */

    /** A paid order for a NAMED customer, so the retention metric can recognise them again. */
    private function paidOrderFor($store, \App\Models\Customer $customer, ?CarbonImmutable $paidAt = null): void
    {
        $order = $this->makeOrder($store, [
            'order_date' => $paidAt ?? CarbonImmutable::now()->subDay(),
            'customer_id' => $customer->id,
            'is_guest' => 0,
        ]);

        DB::table('store_order_financials')->where('order_id', $order->id)->update([
            'payment_status' => 'paid',
            'currency_code' => 'USD',
            'paid_amount' => 10.00,
            'remaining_amount' => 0.00,
            'paid_at' => $paidAt ?? CarbonImmutable::now()->subDay(),
        ]);
    }

    /**
     * A ledgered but UNPAID order for a named customer. The ledger row stays (so the store still
     * vouches for a period) and only `payment_status` differs, which is the single thing the
     * settled-only basis turns on.
     */
    private function unpaidOrderFor($store, \App\Models\Customer $customer): void
    {
        $order = $this->makeOrder($store, [
            'order_date' => CarbonImmutable::now()->subDay(),
            'customer_id' => $customer->id,
            'is_guest' => 0,
        ]);

        DB::table('store_order_financials')->where('order_id', $order->id)->update([
            'payment_status' => 'unpaid',
            'currency_code' => 'USD',
            'paid_amount' => 0.00,
            'paid_at' => null,
        ]);
    }

    /**
     * THE BASIS IS PAID ORDERS, and this is the case that can tell. R2 of the mutation run —
     * swapping `CustomerRetention()` for `CustomerRetention(false)` — SURVIVED the refusal case
     * above, because that one returns before the metric is ever constructed. A fixture where the
     * two bases give the SAME rate cannot catch it either, which ruled out the obvious ones.
     *
     * One buyer with a single PAID order, one with two UNPAID ones:
     *   settled-only (shipped): identified {paid buyer}, repeat {} -> 0.00%
     *   all orders  (mutation): identified {both},       repeat {unpaid buyer} -> 50.00%
     */
    /**
     * THE WINDOW IS THE INTERSECTION, AND THIS IS THE CASE THAT CAN TELL. Replacing it with
     * all-time SURVIVED my first mutation run, because the mutation was pointed at a PRE-EXISTING
     * test about the payments tile which does not touch retention at all — my error in choosing
     * the filter, not a gap in the code.
     *
     * The fixture makes the two answers differ. `verifiedFrom` is the day after the latest order
     * the ledger cannot see, per the payments case below, so:
     *   store A unseen at -10d, paid orders for ONE customer at -9d and -1d  -> A starts at -9d
     *   store B unseen at  -3d, a guest paid order at -1d                    -> B starts at -2d
     * The intersection is the LATEST start, -2d, which hides that customer's -9d order:
     *   intersection (shipped): identified {X}, repeat {}  -> 0.00%
     *   all time    (mutation): identified {X}, repeat {X} -> 100.00%
     */
    /**
     * AN EXCLUDED STORE IS ABSENT FROM THE FIGURE, not merely mentioned beneath it.
     *
     * `test_repeat_purchase_counts_the_stores_it_left_out` asserts the SENTENCE, and a mutation
     * merging the excluded stores back into the scope SURVIVED it — the disclosure stayed while
     * the data came back, which is the "the caveat is present and the figure is still wrong" shape
     * this project keeps finding. This case asserts the FIGURE.
     *
     * It needs the one excluded state where the difference is observable. A store excluded by
     * `hasNothingVerified()` has NO ledger row on any order, and the settled-only basis INNER JOINs
     * `store_order_financials` on `payment_status = 'paid'` (CustomerRetention:68-71), so merging
     * such a store in changes nothing and the mutation is genuinely equivalent there. The other
     * excluded state is `startsInTheFuture()` — `verifiedFrom > now` — where the store DOES have
     * paid rows, so including it would move the rate:
     *   store A: one buyer with two paid orders
     *   store B: an unseen order dated in the FUTURE (so B is excluded) plus a buyer with ONE
     *   shipped:  identified {A's buyer},            repeat {A's buyer} -> 100.00%
     *   mutation: identified {A's buyer, B's buyer}, repeat {A's buyer} ->  50.00%
     */
    public function test_an_excluded_store_is_absent_from_the_rate_and_not_only_disclosed(): void
    {
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');

        [, $a] = $this->makeTenant();
        [, $b] = $this->makeTenant();

        $returning = $this->customerIn((int) $a->id);
        $this->paidOrderFor($a, $returning, $now->subDays(2));
        $this->paidOrderFor($a, $returning, $now->subDay());

        // B's latest unseen order is in the FUTURE, so its verified period starts after today.
        $this->orderTheLedgerCannotSee($b, ['order_date' => $now->addDays(5)]);
        $this->paidOrderFor($b, $this->customerIn((int) $b->id), $now->subDay());

        $figures = PlatformAnalyticsFigures::forPlatform($now);

        $this->assertContains((int) $b->id, $figures['coverage']['excluded'],
            'PREMISE: store B is not actually excluded, so this case cannot tell the difference.');

        $this->assertSame('100.00%', $this->plain($figures['retention']['value']),
            'A store the ledger cannot vouch for contributed buyers to the platform repeat rate '
            . 'while being listed as left out. Being disclosed is not being excluded.');
    }

    public function test_repeat_purchase_uses_the_intersection_and_not_all_time(): void
    {
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');

        [, $a] = $this->makeTenant();
        [, $b] = $this->makeTenant();

        $this->orderTheLedgerCannotSee($a, ['order_date' => $now->subDays(10)]);
        $this->orderTheLedgerCannotSee($b, ['order_date' => $now->subDays(3)]);

        $straddling = $this->customerIn((int) $a->id);
        $this->paidOrderFor($a, $straddling, $now->subDays(9));
        $this->paidOrderFor($a, $straddling, $now->subDays(1));

        // A guest order in B, so B contributes a start date without adding an identified buyer.
        $this->paidOrder($b, 'USD', 10.00, $now->subDays(1));

        $figures = PlatformAnalyticsFigures::forPlatform($now);

        $this->assertSame($now->subDays(2)->toDateString(), $figures['coverage']['verifiedFrom']->toDateString(),
            'PREMISE: the intersection is not where this case needs it, so it proves nothing.');

        $this->assertSame('0.00%', $this->plain($figures['retention']['value']),
            'The repeat rate counted an order from BEFORE the platform-wide verified start. A rate '
            . 'is only as complete as its least complete contributor, and reading all time here '
            . 'claims coverage the ledger does not have for every store in the figure.');
    }

    public function test_repeat_purchase_counts_paid_orders_only(): void
    {
        [, $a] = $this->makeTenant();

        $this->paidOrderFor($a, $this->customerIn((int) $a->id));

        $unpaidRepeater = $this->customerIn((int) $a->id);
        $this->unpaidOrderFor($a, $unpaidRepeater);
        $this->unpaidOrderFor($a, $unpaidRepeater);

        $this->assertSame('0.00%', $this->plain(PlatformAnalyticsFigures::forPlatform()['retention']['value']),
            'A buyer whose two orders nobody has confirmed were paid is being counted as a repeat '
            . 'customer. The metric definition is explicit: "a repeat rate computed over orders '
            . 'nobody has confirmed were paid is not a retention figure".');
    }

    public function test_repeat_purchase_counts_buyers_across_every_store(): void
    {
        [, $a] = $this->makeTenant();
        [, $b] = $this->makeTenant();

        // Store A: one buyer who came back, one who did not.
        $returning = $this->customerIn((int) $a->id);
        $this->paidOrderFor($a, $returning);
        $this->paidOrderFor($a, $returning);
        $this->paidOrderFor($a, $this->customerIn((int) $a->id));

        // Store B: one buyer who did not come back. 1 of 3 across the platform.
        $this->paidOrderFor($b, $this->customerIn((int) $b->id));

        $figures = PlatformAnalyticsFigures::forPlatform();

        $this->assertArrayHasKey('retention', $figures,
            'The operator screen still carries no repeat-purchase figure, so P05-14\'s "the same '
            . 'metrics" is still unmet for it.');

        $this->assertSame('33.33%', $this->plain($figures['retention']['value']),
            'The cross-tenant repeat rate is not 1 of 3 identified buyers. A rate computed per '
            . 'store and then averaged, or one that stopped at the first store, would differ.');
    }

    /**
     * THE CAVEAT WITHOUT WHICH THE FIGURE WOULD BE READ AS SOMETHING IT IS NOT. Nothing links one
     * person's accounts in two stores, so this is a rate over store-customer pairs.
     */
    public function test_repeat_purchase_says_it_counts_a_person_once_per_store(): void
    {
        [, $a] = $this->makeTenant();
        $this->paidOrderFor($a, $this->customerIn((int) $a->id));

        $hint = (string) PlatformAnalyticsFigures::forPlatform()['retention']['hint'];

        $this->assertStringContainsString('counted in each', $hint,
            'The platform repeat rate does not say that one person with accounts in two stores is '
            . 'counted in each. "35% of buyers came back" otherwise reads as a statement about '
            . 'people rather than about customers of a store.');
    }

    /** A store the ledger cannot vouch for at all is EXCLUDED and COUNTED, never silently dropped. */
    public function test_repeat_purchase_counts_the_stores_it_left_out(): void
    {
        [, $a] = $this->makeTenant();
        [, $b] = $this->makeTenant();

        $returning = $this->customerIn((int) $a->id);
        $this->paidOrderFor($a, $returning);
        $this->paidOrderFor($a, $returning);

        // Store B has an order and NO ledger row for it, so it can vouch for no period at all.
        $this->orderTheLedgerCannotSee($b);

        $hint = (string) PlatformAnalyticsFigures::forPlatform()['retention']['hint'];

        $this->assertStringContainsString('Stores left out', $hint,
            'A store whose financial record can vouch for nothing was dropped from the repeat rate '
            . 'without being counted, which reads as "that tenant had no repeat buyers".');
    }

    /** No store the ledger can vouch for: the figure refuses rather than printing a zero. */
    public function test_repeat_purchase_refuses_when_no_store_has_a_financial_record(): void
    {
        [, $a] = $this->makeTenant();
        $this->orderTheLedgerCannotSee($a);

        $figures = PlatformAnalyticsFigures::forPlatform();

        $this->assertNull($figures['retention']['value'],
            'A zero per cent was printed for a platform whose ledger can vouch for nothing. Zero '
            . 'reads as "nobody came back"; the truth is "we cannot say".');
        $this->assertStringContainsString('Nothing is reconstructed', (string) $figures['retention']['hint']);
    }

    /** The screen renders it, with its OWN period rather than the recent window beside it. */
    public function test_the_screen_shows_the_repeat_purchase_tile(): void
    {
        [, $a] = $this->makeTenant();
        $returning = $this->customerIn((int) $a->id);
        $this->paidOrderFor($a, $returning);
        $this->paidOrderFor($a, $returning);

        // A DISTINCTIVE RATE: 1 repeat of 3 identified. "33.33%" is not a prefix or suffix of any
        // other figure on this screen, which the label was -- see the comment below.
        $this->paidOrderFor($a, $this->customerIn((int) $a->id));
        $this->paidOrderFor($a, $this->customerIn((int) $a->id));

        $operator = $this->makePlatformAdmin();
        \Illuminate\Support\Facades\Cache::forget('admin_details');

        $body = $this->plain((string) $this->actingAs($operator->fresh())->get('/platform/analytics')
            ->assertStatus(200)->getContent());

        /*
         * ASSERTED ON THE RENDERED VALUE, NOT ONLY THE LABEL. The first version of this case
         * asserted the label alone, and a mutation renaming it to `Repeat purchaseX` SURVIVED --
         * the old label is a SUBSTRING of the new one. The value is what proves the figure reached
         * the page; the label is asserted too, so a tile that renders a bare number still fails.
         */
        $this->assertSame('33.33%', $this->plain(PlatformAnalyticsFigures::forPlatform()['retention']['value']),
            'PREMISE: the fixture no longer produces the distinctive rate this case asserts on.');

        $this->assertStringContainsString('33.33%', $body,
            'The repeat-purchase figure is computed but never reaches the page.');
        /*
         * THE DELIMITED FORM, because the bare one cannot tell a rename from the real thing: a
         * mutation changing the label to `Repeat purchaseX` SURVIVED `assertStringContainsString`,
         * since the old label is a SUBSTRING of the new one. `<x-ss.stat>` prints the label as the
         * entire text of a `ss-stat__label` span (stat.blade.php:95-99), so the whitespace-trimmed
         * span content is an exact comparison.
         */
        $labels = [];
        preg_match_all('/class="ss-stat__label">\s*(.*?)\s*<\/span>/s', $body, $m);
        foreach ($m[1] as $raw) {
            $labels[] = trim(strip_tags($raw));
        }

        $this->assertContains(__('Repeat purchase'), $labels,
            'No stat label on the operator screen is exactly "Repeat purchase". A label that merely '
            . 'STARTS with it would satisfy a substring assertion, which is why this compares the '
            . 'span\'s whole content. Labels found: '.implode(' | ', $labels));
    }

    /*
     * =============================================================================================
     *  DEVICES, ACROSS TENANTS -- GAP-162 / P05-14, the first of the four counterparts a mapping
     *  pass found buildable without an owner decision.
     *
     *  NO TEST IN THIS FILE BUILT A VISIT ROW BEFORE THESE. Every figure here was order- or
     *  ledger-derived, so the visit log had no fixture at all on the operator side -- which is
     *  worth stating because it means the tenancy assertion below is the FIRST one covering a
     *  cross-tenant read of `shetabit_visits` from this screen.
     * =============================================================================================
     */

    /** A visit row whose own date is the point, so Eloquent must not stamp it with "now". */
    private function logVisit(int $storeId, CarbonImmutable $when, ?string $device): void
    {
        $visit = new Visit();
        $visit->timestamps = false;
        $visit->store_id = $storeId;
        $visit->device = $device;
        $visit->created_at = $when;
        $visit->updated_at = $when;
        $visit->save();
    }

    public function test_devices_counts_page_views_from_every_store(): void
    {
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');

        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        // THE ASSERTION THAT MATTERS FOR A CROSS-TENANT FIGURE: two stores, one bucket, and the
        // count is the SUM. A per-store read would report 2 and look plausible.
        $this->logVisit((int) $storeA->id, $now->subDays(2), 'Mobile');
        $this->logVisit((int) $storeA->id, $now->subDays(2), 'Mobile');
        $this->logVisit((int) $storeB->id, $now->subDays(3), 'Mobile');

        $devices = PlatformAnalyticsFigures::forPlatform($now)['devices'];

        $this->assertCount(1, $devices['rows'],
            'one device across two stores must be ONE row, not one per store');
        $this->assertSame('Mobile', $devices['rows'][0]['label']);
        $this->assertStringContainsString('3', $devices['rows'][0]['count'],
            'the operator figure counted one store rather than every store');
    }

    public function test_devices_orders_the_busiest_first_and_counts_each_separately(): void
    {
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');

        [, $store] = $this->makeTenant();

        $this->logVisit((int) $store->id, $now->subDays(1), 'Desktop');
        $this->logVisit((int) $store->id, $now->subDays(1), 'Mobile');
        $this->logVisit((int) $store->id, $now->subDays(1), 'Mobile');

        $devices = PlatformAnalyticsFigures::forPlatform($now)['devices'];

        $this->assertCount(2, $devices['rows']);
        $this->assertSame('Mobile', $devices['rows'][0]['label'],
            'the rows are not ordered by count, so the busiest device is not first');
        $this->assertSame('Desktop', $devices['rows'][1]['label']);
    }

    public function test_devices_excludes_page_views_outside_the_window(): void
    {
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');

        [, $store] = $this->makeTenant();

        $this->logVisit((int) $store->id, $now->subDays(2), 'Mobile');
        // Outside "the last 30 days", which this screen defines in ONE place (`recentBounds()`)
        // so the breakdown cannot drift from the tiles beside it.
        $this->logVisit((int) $store->id, $now->subDays(40), 'Mobile');

        $devices = PlatformAnalyticsFigures::forPlatform($now)['devices'];

        $this->assertStringContainsString('1', $devices['rows'][0]['count'],
            'a page view from outside the window was counted');
    }

    public function test_an_unrecorded_device_is_one_row_and_not_two(): void
    {
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');

        [, $store] = $this->makeTenant();

        /*
         * THE FOLD, AND IT IS A MEASURED DEFECT ON THE MERCHANT SIDE RATHER THAN A HYPOTHETICAL.
         * SQL `GROUP BY device` treats NULL and '' as TWO groups, so a naive loop emits two rows
         * both labelled "Device not recorded" with a count each -- a reader takes one absence for
         * two devices and neither count for the real one. `MerchantAnalyticsFigures::devices()`
         * records that measurement; this proves the operator side folds them too.
         */
        $this->logVisit((int) $store->id, $now->subDays(1), null);
        $this->logVisit((int) $store->id, $now->subDays(1), '');

        $devices = PlatformAnalyticsFigures::forPlatform($now)['devices'];

        $this->assertCount(1, $devices['rows'],
            'NULL and the empty string were emitted as two rows with the same label');
        $this->assertStringContainsString('2', $devices['rows'][0]['count'],
            'the folded row does not carry the combined count');
    }

    /**
     * THE STORE PREDICATE, AND IT WAS UNPINNED UNTIL THIS TEST EXISTED.
     *
     * Measured: deleting `->whereIn('store_id', $storeIds)` from the producer SURVIVED the whole
     * 27-test suite. It survives because `$storeIds` IS every store, so for any ordinary fixture
     * the predicate changes nothing -- which is exactly how a tenancy predicate on a cross-tenant
     * read ends up with no test at all.
     *
     * The predicate does work in one case, and this is it: a visit row whose `store_id` is NOT in
     * the list `forPlatform()` derived from `Store`. That is reachable -- `StoreController::
     * destroy()` deletes a store and this product has a recorded lead about rows left pointing at
     * one -- and `Visit` carries no global scope, so without the predicate such a row is counted
     * into a platform figure under a store that no longer exists.
     */
    public function test_devices_ignores_a_page_view_belonging_to_no_existing_store(): void
    {
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');

        [, $store] = $this->makeTenant();

        $this->logVisit((int) $store->id, $now->subDays(1), 'Mobile');

        // A store id no `stores` row has. `forPlatform()` reads its ids from `Store`, so this one
        // is outside the scope by construction.
        $orphan = ((int) \App\Models\Store::query()->max('id')) + 99;
        $this->logVisit($orphan, $now->subDays(1), 'Mobile');

        $this->assertSame(0, \App\Models\Store::query()->whereKey($orphan)->count(),
            'premise: the orphan store id must not exist, or this test proves nothing');

        $devices = PlatformAnalyticsFigures::forPlatform($now)['devices'];

        $this->assertStringContainsString('1', $devices['rows'][0]['count'],
            'a page view belonging to no existing store was counted into the platform figure, so '
            . 'the store predicate is doing nothing.');
    }

    /**
     * THE RE-SORT AFTER THE FOLD, which a mutation proved was also unpinned.
     *
     * Deleting `arsort($buckets)` SURVIVED the ordering test above, because the SQL already
     * returns `ORDER BY total DESC` and PHP preserves insertion order -- so for a fixture whose
     * buckets need no merging the sort is invisible.
     *
     * It is NOT redundant: the fold merges NULL and '' into one bucket AFTER the ordering, and the
     * merged count can exceed a bucket that arrived earlier. This fixture makes exactly that
     * shape -- Desktop 5, NULL 3, '' 3, so the folded "not recorded" bucket is 6 and must rank
     * FIRST. Without the re-sort it renders second, under a smaller number.
     */
    public function test_a_folded_bucket_is_ranked_by_its_combined_count(): void
    {
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');

        [, $store] = $this->makeTenant();

        for ($i = 0; $i < 5; $i++) {
            $this->logVisit((int) $store->id, $now->subDays(1), 'Desktop');
        }

        for ($i = 0; $i < 3; $i++) {
            $this->logVisit((int) $store->id, $now->subDays(1), null);
        }

        for ($i = 0; $i < 3; $i++) {
            $this->logVisit((int) $store->id, $now->subDays(1), '');
        }

        $devices = PlatformAnalyticsFigures::forPlatform($now)['devices'];

        $this->assertCount(2, $devices['rows'],
            'premise: NULL and the empty string must fold into one bucket for this test to be '
            . 'about ranking at all');
        $this->assertStringContainsString('6', $devices['rows'][0]['count'],
            'the folded bucket (3 + 3) is not ranked above the 5 it exceeds, so the re-sort after '
            . 'the fold is not happening');
        $this->assertSame('Desktop', $devices['rows'][1]['label']);
    }

    public function test_devices_renders_a_breakdown_shape_when_no_store_exists(): void
    {
        /*
         * THE EMPTY-SCOPE GUARD, which a mapping pass omitted from all four of its proposals and
         * which is the one omission that would have shipped red. `MetricScope`'s constructor
         * THROWS on an empty store list, and `nothingYet()` -- the helper every TILE on this
         * screen uses for this case -- returns no `rows` key, so a consumer looping
         * `$figure['rows']` would fatal on it. A breakdown needs a breakdown-shaped empty.
         */
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');

        $devices = PlatformAnalyticsFigures::forPlatform($now)['devices'];

        $this->assertArrayHasKey('rows', $devices,
            'the empty case does not carry a `rows` key, so every consumer that loops it fatals');
        $this->assertSame([], $devices['rows']);
        $this->assertNotSame('', trim((string) $devices['hint']),
            'an empty breakdown with no explanation reads as a broken page');
    }

    public function test_the_screen_shows_the_devices_breakdown(): void
    {
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');
        $operator = $this->makePlatformAdmin();

        [, $store] = $this->makeTenant();
        $this->logVisit((int) $store->id, $now->subDays(1), 'Tablet');

        CarbonImmutable::setTestNow($now);

        try {
            $html = $this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200)->getContent();
        } finally {
            CarbonImmutable::setTestNow();
        }

        $this->assertStringContainsString('Tablet', (string) $html,
            'the figure is computed and the screen does not render it -- the "computed but '
            . 'unreachable" shape this screen has already shipped three times');
        $this->assertStringContainsString('Devices', (string) $html);
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * ESTIMATED GROSS PROFIT, ACROSS TENANTS -- GAP-162 / P05-14
     *
     * The definition is NOT re-decided here: it is `StoreRevenueMargin(true)`, the same metric and
     * the same settled-only flag `/finance` and `/analytics` already use. These tests pin the two
     * things that are new -- the scope is every store, and the figure must still never combine
     * currencies -- plus the three disclosures that make a cross-tenant money figure honest.
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * A SETTLED ORDER WITH A MARGIN ON RECORD.
     *
     * `paidOrder()` above leaves `gross_profit` and `product_cost` at their column defaults of
     * 0.00, which is a real state -- it is the one the COST_UNKNOWN caveat exists for -- but it
     * cannot produce a profit to measure. This writes both, so a test can state the margin it
     * expects. Like `paidOrder()` it UPDATES the observer's row rather than inserting a second.
     */
    private function settledProfit(
        $store,
        string $currency,
        float $grossProfit,
        float $productCost = 10.00,
        ?CarbonImmutable $paidAt = null
    ): void {
        $order = $this->makeOrder($store, ['order_date' => $paidAt ?? CarbonImmutable::now()->subDay()]);

        DB::table('store_order_financials')->where('order_id', $order->id)->update([
            'payment_status' => 'paid',
            'currency_code' => $currency,
            'paid_amount' => $grossProfit + $productCost,
            'remaining_amount' => 0.00,
            'product_cost' => $productCost,
            'gross_profit' => $grossProfit,
            'paid_at' => $paidAt ?? CarbonImmutable::now()->subDay(),
        ]);
    }

    /** The numeric part of a rendered amount, with the bidi isolates and separators removed. */
    private function bareAmount(string $amount): string
    {
        return preg_replace('/[^0-9.\-]/', '', $this->plain($amount));
    }

    /**
     * ONE SECTION OF THE PAGE, FROM ITS HEADING TO THE NEXT ONE.
     *
     * A WHOLE-PAGE `assertStringContainsString` ANSWERS "DOES THIS APPEAR ANYWHERE", and on this
     * screen that is almost always yes. Measured: the mutation that deleted profit's "Not
     * available" line SURVIVED a whole-page assertion, because the `devices` breakdown prints the
     * same words two sections further down and the fixture leaves it empty too. Every assertion
     * about one figure's markup is scoped through here.
     *
     * GIVE IT AN `<h2>` HEADING'S TEXT AND NOTHING ELSE. The slice runs to the next `<h2`, so for
     * an anchor that is not a section heading -- a stat tile's LABEL, say -- the span silently
     * covers everything up to the next real heading, which is far wider than "its section" and was
     * wide enough to make a tile test pass before its tile existed. Stat tiles are asserted through
     * `statTiles()`, which pairs each label with its own value; this is for the table sections.
     */
    private function section(string $html, string $heading): string
    {
        $start = strpos($html, $heading);

        $this->assertNotFalse($start,
            "premise failed: the page has no section headed '{$heading}', so scoping an assertion "
            . 'to it would assert against an empty string.');

        $rest = substr($html, $start + strlen($heading));
        $next = strpos($rest, '<h2');

        return $next === false ? $rest : substr($rest, 0, $next);
    }

    /**
     * TWO CURRENCIES ARE TWO ROWS AND NEVER ONE TOTAL -- the same invariant as `payments`, asserted
     * separately because it is a separate query object and a separate producer. A platform profit
     * figure is the number an operator most wants and the one this schema cannot support: no
     * historical exchange rate is recorded anywhere, so the sum of 60 USD and 150 SYP has no
     * defensible value. `total()` is therefore never called; if it were it would throw, which is
     * the metric layer doing its job but not an answer a screen can render.
     */
    public function test_profit_is_reported_per_currency_and_never_summed(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->settledProfit($storeA, 'USD', 60.00);
        $this->settledProfit($storeB, 'SYP', 150.00);

        $rows = collect(PlatformAnalyticsFigures::forPlatform()['profit']['rows']);

        $this->assertSame(['SYP', 'USD'], $rows->pluck('currency')->sort()->values()->all(),
            'both currencies must be reported, each on its own row');

        $amounts = $rows->pluck('amount')->map(fn ($a) => $this->bareAmount((string) $a))->all();

        $this->assertContains('60.00', $amounts);
        $this->assertContains('150.00', $amounts);
        $this->assertNotContains('210.00', $amounts,
            'the two currencies were added together; no exchange rate exists to justify that');
    }

    /**
     * A NEGATIVE PROFIT KEEPS ITS BIDI ISOLATE. Measured and recorded in `FinanceLedgerFigures`:
     * in an Arabic page an unisolated negative renders as "USD 20.00-", with the minus sign after
     * the number, because the Unicode bidi algorithm reorders a leading `-` in an RTL paragraph.
     * A loss shown as a gain with a trailing dash is the worst reading a money figure can have, so
     * the isolate is asserted on the character and not on the rendered page.
     */
    public function test_a_loss_is_isolated_so_its_minus_sign_cannot_move(): void
    {
        [, $store] = $this->makeTenant();

        $this->settledProfit($store, 'USD', -20.00);

        $rows = PlatformAnalyticsFigures::forPlatform()['profit']['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('-20.00', $this->bareAmount((string) $rows[0]['amount']),
            'premise failed: the fixture did not produce a loss, so the isolate assertion below '
            . 'would hold for a positive number and prove nothing');
        $this->assertStringStartsWith("\u{2066}", (string) $rows[0]['amount'],
            'a negative amount must be wrapped in a bidi isolate or its minus sign moves to the '
            . 'end of the number in an Arabic page');
        $this->assertStringEndsWith("\u{2069}", (string) $rows[0]['amount']);
    }

    /**
     * ONLY PAID ORDERS. `settledOnly` is the flag `/finance` passes and the reason this figure can
     * be called profit at all: an order nobody has paid for has a margin on paper and no money
     * behind it. An unsettled row also has no `paid_at`, so it has no date to be placed in a
     * window by -- counting it would put an undated amount inside a dated period.
     */
    public function test_profit_counts_settled_orders_only(): void
    {
        [, $store] = $this->makeTenant();

        $this->settledProfit($store, 'USD', 40.00);

        $unsettled = $this->makeOrder($store, ['order_date' => CarbonImmutable::now()->subDay()]);

        DB::table('store_order_financials')->where('order_id', $unsettled->id)->update([
            'payment_status' => 'unpaid',
            'currency_code' => 'USD',
            'product_cost' => 10.00,
            'gross_profit' => 500.00,
            'paid_at' => null,
        ]);

        $rows = PlatformAnalyticsFigures::forPlatform()['profit']['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('40.00', $this->bareAmount((string) $rows[0]['amount']),
            'an unpaid order contributed its paper margin to a figure named profit');
    }

    /**
     * A CURRENCY WHOSE NET PROFIT ROUNDS TO ZERO IS STILL ITS OWN ROW.
     *
     * THIS IS THE ONE PLACE THIS FIGURE DIFFERS FROM ITS MERCHANT TWIN, deliberately. The merchant
     * tiles run `byCurrency()` through `MoneyText::buckets(..., true)`, which drops any bucket
     * rounding to zero -- right for a single store, where a currency nothing was paid in should
     * not make a tile look mixed. Across tenants a currency whose margin nets to approximately
     * zero is a STATEMENT, not an artefact: that group of stores broke even. Dropping the row
     * would make the screen silently narrower than the data, and the reader could not tell the
     * difference between "broke even in SYP" and "no SYP trade at all".
     */
    public function test_a_currency_that_broke_even_is_still_its_own_row(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->settledProfit($storeA, 'USD', 100.00);
        $this->settledProfit($storeB, 'SYP', 25.00);
        $this->settledProfit($storeB, 'SYP', -25.00);

        $rows = collect(PlatformAnalyticsFigures::forPlatform()['profit']['rows']);

        $this->assertSame(['SYP', 'USD'], $rows->pluck('currency')->sort()->values()->all(),
            'a currency whose net margin is zero was dropped; across tenants that reads as "no '
            . 'trade in this currency", which is a different claim');

        $syp = $rows->firstWhere('currency', 'SYP');

        $this->assertSame('0.00', $this->bareAmount((string) $syp['amount']));
    }

    /**
     * THE COST CAVEAT IS A SECOND, DIFFERENT COVERAGE QUESTION. `LedgerCoverage` answers "does the
     * ledger have a row for this order"; it says nothing about whether that row carries a cost.
     * Where `product_cost` is 0 the column reports the whole sale as profit -- a 100% margin on
     * goods that cost something -- so the count has to be on the screen. It is a COUNT OF ORDERS
     * and names no money, because the overstatement's size is exactly what is not known.
     */
    public function test_paid_orders_with_no_cost_on_record_are_counted_in_the_hint(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        // Two orders with no cost, in two different stores: the count is scope-wide.
        $this->settledProfit($storeA, 'USD', 30.00, 0.00);
        $this->settledProfit($storeB, 'USD', 30.00, 0.00);
        // And one that does carry a cost, which must not be counted.
        $this->settledProfit($storeA, 'USD', 30.00, 12.00);

        $hint = $this->plain(PlatformAnalyticsFigures::forPlatform()['profit']['hint']);

        $this->assertStringContainsString('At least 2 paid orders', $hint,
            'the number of paid orders with no cost on record must be disclosed, and only those '
            . 'orders counted');
    }

    /**
     * NO COST PROBLEM, NO SENTENCE. Attaching a warning to answers that do not have the problem is
     * how a warning becomes furniture -- `StoreRevenueMargin`'s own docblock says so, which is why
     * `COST_UNKNOWN` is conditional in the metric. The producer must keep it conditional too.
     */
    public function test_no_cost_sentence_is_printed_when_every_paid_order_carries_a_cost(): void
    {
        [, $store] = $this->makeTenant();

        $this->settledProfit($store, 'USD', 30.00, 12.00);

        $hint = $this->plain(PlatformAnalyticsFigures::forPlatform()['profit']['hint']);

        $this->assertStringNotContainsString('paid orders across all stores have no product cost', $hint,
            'the cost caveat was printed for a scope where every paid order carries a cost');
    }

    /**
     * A STORE THE LEDGER CANNOT VOUCH FOR IS EXCLUDED AND COUNTED, never counted as zero. For
     * profit the zero reading is worse than for payments: "that tenant made no profit" is a
     * statement about their business, and the truth is that we cannot say.
     */
    public function test_a_store_with_no_readable_ledger_is_excluded_from_profit_and_counted(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->settledProfit($storeA, 'USD', 75.00);
        $this->orderTheLedgerCannotSee($storeB);

        $figures = PlatformAnalyticsFigures::forPlatform();
        $hint = $this->plain($figures['profit']['hint']);

        $this->assertStringContainsString('Stores left out', $hint,
            'a store whose financial record can vouch for no period must be disclosed, not '
            . 'silently folded in as a zero');
        $this->assertStringContainsString('1', $hint);

        $rows = collect($figures['profit']['rows']);
        $this->assertSame(['USD'], $rows->pluck('currency')->all());
        $this->assertSame('75.00', $this->bareAmount((string) $rows->first()['amount']));
    }

    /**
     * A STORE THE COVERAGE INTERSECTION EXCLUDED CONTRIBUTES NO MONEY, and this is the test the
     * independent review found missing. `profit()` passes `$coverage['included']`; substituting the
     * full store list survived every other case in this file, because the only excluded store those
     * fixtures build has its ledger row DELETED and so has no settled row to contribute either way.
     * The predicate reads as tested and was not.
     *
     * THE EXCLUDING STATE USED HERE IS A REAL ONE. `LedgerCoverage::window()` returns null -- which
     * is what `coverageAcrossStores()` excludes on -- when the verified period
     * `startsInTheFuture()`, and `verifiedFrom` is the day AFTER the latest DATED order the ledger
     * cannot see. So a store with one future-dated order outside the ledger is excluded while its
     * other orders still carry settled rows. `order_date` is writable ahead of the clock in this
     * product (`LedgerCoverage`'s own notes record stamps up to 5.5 hours out), so the state is not
     * invented for the test; the fixture only makes the gap large enough to read.
     */
    public function test_a_store_the_coverage_intersection_excluded_contributes_no_profit(): void
    {
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');

        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->settledProfit($storeA, 'USD', 75.00, 10.00, $now->subDays(3));

        $this->settledProfit($storeB, 'USD', 500.00, 10.00, $now->subDays(3));
        $this->orderTheLedgerCannotSee($storeB, ['order_date' => $now->addDays(10)]);

        $figures = PlatformAnalyticsFigures::forPlatform($now);

        $this->assertContains((int) $storeB->id, $figures['coverage']['excluded'],
            'premise failed: store B is not excluded by the coverage intersection, so this test is '
            . 'not measuring what an excluded store contributes.');
        $this->assertContains((int) $storeA->id, $figures['coverage']['included'],
            'premise failed: store A is not included, so there is nothing to compare against.');

        $rows = collect($figures['profit']['rows']);
        $amounts = $rows->pluck('amount')->map(fn ($a) => $this->bareAmount((string) $a))->all();

        $this->assertSame(['75.00'], $amounts,
            'the excluded store\'s settled margin was counted into the platform figure anyway, so '
            . 'the figure claims a period that store\'s financial record cannot vouch for');
    }

    /**
     * THE FIGURE IS BOUNDED BY THE VERIFIED PERIOD, which is the second gap the review named: every
     * other profit fixture here leaves the ledger complete, so `verifiedFrom` is null,
     * `MetricWindow::allTime()` is always the branch taken, and replacing `since(...)` with
     * `allTime()` failed nothing.
     *
     * WHY THAT MATTERS RATHER THAN BEING TIDINESS: the money before a store's verified start is
     * money the ledger cannot account for. Counting it would put an unverifiable amount under a
     * period label that says the figure IS verified from a date -- which is the exact shape D1
     * exists to prevent, and worse on this screen than on the merchant's, because the operator has
     * no second source to check it against.
     */
    public function test_profit_counts_only_the_period_the_ledger_can_vouch_for(): void
    {
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');

        [, $store] = $this->makeTenant();

        // Settled 60 days ago -- BEFORE the verified start the undated gap below creates.
        $this->settledProfit($store, 'USD', 500.00, 10.00, $now->subDays(60));

        // The order the ledger cannot see, dated 30 days ago: `verifiedFrom` becomes the day after.
        $this->orderTheLedgerCannotSee($store, ['order_date' => $now->subDays(30)]);

        // And one inside the verified period.
        $this->settledProfit($store, 'USD', 75.00, 10.00, $now->subDays(10));

        $figures = PlatformAnalyticsFigures::forPlatform($now);

        $this->assertNotNull($figures['coverage']['verifiedFrom'],
            'premise failed: the fixture produced no verified start, so the window under test is '
            . 'all-time and this case cannot tell the two branches apart.');

        $amounts = collect($figures['profit']['rows'])
            ->pluck('amount')->map(fn ($a) => $this->bareAmount((string) $a))->all();

        $this->assertSame(['75.00'], $amounts,
            'margin settled before the verified start was counted, so the figure reports money the '
            . 'financial record cannot account for under a period label claiming it can');

        $this->assertStringContainsString('latest start among the stores included',
            $this->plain($figures['profit']['hint']),
            'the period is narrowed and the screen does not say why');
    }

    /**
     * THE "CURRENCY NOT RECORDED" ROW IS DISCLOSED, because it is the one row that really does add
     * two different currencies -- which this slice's own docblock denied until an independent
     * financial review drove it.
     *
     * `MetricResult::make()` re-keys every bucket through `CurrencySymbol::code()`, which returns
     * three uppercase letters or an empty string, so EVERY unparseable value collapses into one
     * key and is summed. That is ordinary data rather than a corner case: `FIN-SYM` measured
     * symbols in the per-store currency setting that `store_order_financials.currency_code` is
     * written from, and owner decision `D-N34` leaves the rows already written alone.
     *
     * THE FIXTURE IS THE REVIEW'S OWN: a gain recorded under `$` and a larger loss recorded under
     * a Syrian-pound symbol. One row, a negative total, and -- before this -- a caption directly
     * above it saying the rows are not added together.
     */
    public function test_the_unrecorded_currency_row_says_it_adds_different_currencies(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->settledProfit($storeA, '$', 1000.00);
        $this->settledProfit($storeB, 'ل.س', -2000.00);

        $figures = PlatformAnalyticsFigures::forPlatform();
        $rows = collect($figures['profit']['rows']);

        $this->assertCount(1, $rows,
            'premise failed: the two unparseable currency values did not collapse into one bucket, '
            . 'so there is no mixed row for this test to be about.');
        $this->assertSame('-1000.00', $this->bareAmount((string) $rows->first()['amount']),
            'premise failed: the fixture did not produce the measured sign-reversing sum.');

        $hint = $this->plain($figures['profit']['hint']);

        $this->assertStringContainsString('not a figure to rely on', $hint,
            'the row that really does add two currencies is printed with no disclosure at all, '
            . 'under a caption saying the rows are not added together');
        $this->assertStringContainsString('can show a loss where one currency gained', $hint,
            'the sign-reversal a margin can suffer is specific to this figure and must be said');
    }

    /**
     * AND THE SAME ROW ON THE PAYMENTS TABLE, which had been silent about it for longer. Money
     * collected is never negative, so there the row is inflated rather than sign-reversed -- the
     * base sentence is shared and the extra one is not.
     */
    public function test_the_payments_table_discloses_the_unrecorded_currency_row_too(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->paidOrder($storeA, '$', 100.00);
        $this->paidOrder($storeB, 'ل.س', 250.00);

        $figures = PlatformAnalyticsFigures::forPlatform();

        $this->assertCount(1, $figures['payments']['rows'],
            'premise failed: the two unparseable values did not collapse into one bucket.');

        $hint = $this->plain($figures['payments']['hint']);

        $this->assertStringContainsString('not a figure to rely on', $hint);
        $this->assertStringNotContainsString('can show a loss where one currency gained', $hint,
            'the margin-only sentence was printed on a figure that cannot be negative');
    }

    /**
     * NO DISCLOSURE WHEN THERE IS NO SUCH ROW. The same reasoning as the cost caveat: a warning
     * attached to answers that do not have the problem becomes furniture.
     */
    public function test_no_unrecorded_currency_sentence_when_every_record_names_its_currency(): void
    {
        [, $store] = $this->makeTenant();

        $this->settledProfit($store, 'USD', 60.00);

        $hint = $this->plain(PlatformAnalyticsFigures::forPlatform()['profit']['hint']);

        $this->assertStringNotContainsString('not a figure to rely on', $hint);
    }

    /**
     * THE TAX CLAUSE, which the merchant twin carries and the first version of this figure dropped.
     *
     * `tests/Support/Security/bin/verify-metric-layer-on-mysql.php` already asserts the merchant
     * hint carries it, under the check name "and says what it leaves out TRUTHFULLY"; this is the
     * operator half. It matters MORE here, because `TaxOption` is per store, so a single currency
     * row can combine a tax-inclusive store's margin with a tax-exclusive one's.
     */
    public function test_profit_says_tax_may_be_inside_the_sales_value(): void
    {
        [, $store] = $this->makeTenant();

        $this->settledProfit($store, 'USD', 60.00);

        $hint = $this->plain(PlatformAnalyticsFigures::forPlatform()['profit']['hint']);

        $this->assertStringContainsString('prices with tax included', $hint,
            'the merchant tile states that an inclusive-tax store has the tax inside this figure; '
            . 'the cross-tenant version can mix both kinds in one row and said nothing');
        $this->assertStringContainsString('Shipping is not included', $hint);
        $this->assertStringContainsString('exchange rate on each order', $hint,
            'the reason the rows cannot be converted travels with the payments table and was '
            . 'missing from this one, so the exported profit rows said less than the payments rows '
            . 'about the same prohibition');
    }

    /**
     * ALPHABETICAL, SO THE TABLE DOES NOT SHUFFLE.
     *
     * WHAT THIS TEST CAN AND CANNOT PROVE, stated because the limit is the interesting part.
     * `byCurrency()`'s order is the order the raw `GROUP BY` result set was iterated in. MySQL
     * guarantees NO ordering for `GROUP BY`; SQLite, which this suite runs on, happens to return
     * these sorted. So DELETING the `ksort()` is invisible here and the honest evidence for that
     * direction is a MySQL harness, not a Feature test. What this does catch is a sort that is
     * present and wrong -- `krsort`, or a sort by amount -- which is the likelier edit.
     */
    public function test_the_currency_rows_are_in_a_fixed_order(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        [, $storeC] = $this->makeTenant();

        // Inserted deliberately out of order, and with amounts whose descending order differs from
        // the alphabetical one, so a sort by amount cannot masquerade as this.
        $this->settledProfit($storeA, 'ZAR', 10.00);
        $this->settledProfit($storeB, 'AED', 900.00);
        $this->settledProfit($storeC, 'MYR', 500.00);

        $rows = collect(PlatformAnalyticsFigures::forPlatform()['profit']['rows']);

        $this->assertSame(['AED', 'MYR', 'ZAR'], $rows->pluck('currency')->all(),
            'the currency rows are not in a fixed order, so the table can reorder between loads');
    }

    /**
     * THE DISCLOSURE REACHES THE PAGE, and nothing asserted that until a review pointed it out.
     *
     * Every other hint assertion in this file reads the PRODUCER's array. The hint is rendered by a
     * standalone `<p class="ss-stat__hint">` beside the table rather than by `<x-ss.stat>`, so it
     * is invisible to `StatHintIsVisibleTest`'s census BY CONSTRUCTION -- that file says so in its
     * own note. Deleting the paragraph from the Blade therefore failed nothing: the amounts
     * rendered and their caveats silently stopped. For a cross-tenant per-currency margin that is
     * the whole slice, because the uncaveated number is exactly the one an operator will add up.
     */
    public function test_the_profit_disclosure_is_rendered_beside_the_figure(): void
    {
        $operator = $this->makePlatformAdmin();

        [, $store] = $this->makeTenant();
        $this->settledProfit($store, 'USD', 60.00, 0.00);

        $html = $this->plain($this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200)->getContent());
        $block = $this->section($html, 'Estimated gross profit');

        $this->assertStringContainsString('Shipping is not included', $block,
            'the profit disclosure is computed and never rendered');
        $this->assertStringContainsString('prices with tax included', $block);
        $this->assertStringContainsString('At least 1 paid orders', $block,
            'the cost caveat reaches the producer and not the page');
    }

    /**
     * THE ZERO-STORE BRANCH, which all four mapped proposals for this screen omitted.
     * `MetricScope`'s constructor THROWS on an empty store list -- "an empty scope would compile
     * to 0 = 1" -- so a producer that reaches the metric layer with no included store answers
     * HTTP 500. It must also answer in the BREAKDOWN-ADJACENT shape its template reads, with
     * `rows`, or the Blade raises an undefined-key error instead.
     */
    public function test_profit_answers_safely_when_no_store_has_a_readable_ledger(): void
    {
        $figures = PlatformAnalyticsFigures::forPlatform();

        $this->assertArrayHasKey('profit', $figures);
        $this->assertArrayHasKey('rows', $figures['profit']);
        $this->assertSame([], $figures['profit']['rows']);
        $this->assertNotSame('', trim((string) $figures['profit']['hint']),
            'an empty money figure with no explanation reads as a broken page');
    }

    /**
     * AND THE SCREEN HAS TO RENDER IT. "Computed but unreachable" is a shape this page has already
     * shipped three times: the producer is correct, the figure is in the array, and no template
     * prints it.
     */
    public function test_the_screen_shows_the_profit_rows(): void
    {
        $operator = $this->makePlatformAdmin();

        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->settledProfit($storeA, 'USD', 60.00);
        $this->settledProfit($storeB, 'SYP', 150.00);

        $html = $this->plain($this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200)->getContent());
        $block = $this->section($html, 'Estimated gross profit');

        $this->assertStringContainsString('60.00', $block);
        $this->assertStringContainsString('150.00', $block);
        $this->assertStringNotContainsString('210.00', $block,
            'the profit section printed the sum of two currencies');
        $this->assertStringNotContainsString('210.00', $html,
            'some section of the screen printed the sum of two currencies');
    }

    /**
     * A STORE WITH A READABLE LEDGER AND NO SETTLED ORDER YET gets an empty table and the sentence,
     * not a blank area. The coverage intersection can include a store while the margin query
     * answers nothing -- the ledger vouches for the period, there is simply no paid order in it.
     */
    public function test_an_included_store_with_no_settled_order_shows_the_empty_table(): void
    {
        $operator = $this->makePlatformAdmin();

        [, $store] = $this->makeTenant();
        $this->paidOrder($store, 'USD', 0.00);

        DB::table('store_order_financials')->update(['payment_status' => 'unpaid', 'paid_at' => null]);

        $figures = PlatformAnalyticsFigures::forPlatform();

        $this->assertSame([], $figures['profit']['rows'],
            'premise failed: the fixture produced a settled row, so the empty-table branch below '
            . 'is not the one being rendered');

        $html = $this->plain($this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200)->getContent());

        $this->assertStringContainsString('Not available', $this->section($html, 'Estimated gross profit'),
            'an included store with nothing settled yet must say so inside the profit section; a '
            . 'whole-page assertion here is satisfied by the empty devices breakdown below it, '
            . 'which is how the first version of this test let a mutation through');
    }

    /**
     * BILINGUAL, AND DRIVEN THE WAY THE APPLICATION SETS THE LOCALE.
     *
     * `app()->setLocale('ar')` in a test does NOT survive the request: `SetLocate` runs inside it
     * and resolves the locale again from session, cookie, then `auth()->user()->language` -- so the
     * page comes back in English and a `__()` call AFTER the response reads the English catalogue
     * too, which makes a bilingual test pass while rendering English on both sides.
     * `MerchantAnalyticsScreenTest` records a premise assertion catching exactly that. The locale
     * therefore goes on the operator's `language` column, and the expected strings are the ARABIC
     * LITERALS rather than `__()` output.
     *
     * AND THE NUMBERS MUST KEEP THEIR ISOLATES IN THE PAGE ITSELF. The unit-level assertion above
     * reads the producer's return value; this reads the rendered HTML, which is where a Blade that
     * re-formatted or re-wrapped the amount would drop them.
     */
    public function test_the_profit_section_renders_in_arabic_with_its_amounts_isolated(): void
    {
        $operator = $this->makePlatformAdmin();
        $operator->language = 'ar';
        $operator->save();

        [, $store] = $this->makeTenant();
        $this->settledProfit($store, 'USD', -20.00);

        $html = $this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200)->getContent();

        $this->assertStringContainsString('إجمالي الربح التقديري', $html,
            'the profit heading rendered in English for an Arabic operator');

        /*
         * SCOPED, because the payments table two sections above has a `Currency` column of its
         * own. Measured: a mutation hard-coding THIS table's header in English survived a
         * whole-page assertion for "العملة", which the payments header satisfied on its behalf.
         */
        $block = $this->section($html, 'إجمالي الربح التقديري');

        $this->assertStringContainsString('<th scope="col">العملة</th>', $block,
            'the currency column header of the profit table rendered in English for an Arabic '
            . 'operator');

        /*
         * THE LOSS, STILL WRAPPED, IN THE PAGE. `dir="rtl"` on the document is exactly what makes
         * the bare form dangerous: in an RTL paragraph the Unicode bidi algorithm moves a leading
         * `-` to the end, so "-20.00" is painted "20.00-" and a loss reads as a gain.
         */
        $this->assertStringContainsString("\u{2066}-20.00\u{2069}", $html,
            'the amount reached the page without its bidi isolate, so in this RTL document the '
            . 'minus sign moves to the end of the number and a loss is painted as a gain');
        $this->assertStringContainsString('dir="rtl"', $html,
            'premise failed: the page is not in RTL, so the isolate assertion above is not being '
            . 'checked under the condition it exists for');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * PAGE VIEWS, ACROSS TENANTS -- GAP-162 / P05-14, the third counterpart.
     *
     * A COUNT IS NOT THE CONTESTED RATIO. `conversion` is blocked on `D-N32` because the owner has
     * never ruled on its basis and withdrawing the tile is a registered option; the page-view
     * COUNT it would divide by is not in question and is what the merchant screen already shows.
     * So this builds the count and not the rate.
     *
     * TWO COVERAGE QUESTIONS, BOTH OF THEM NEW ON THIS SIDE. Visit rows carried no `store_id` at
     * all until the attribution fix of 2026-11-20 and nothing backfills them, so each store has
     * its own start date -- and across tenants the honest start is the LATEST of them, exactly as
     * `verifiedFrom` is for money. A store that has NEVER logged one has no start at all, and its
     * zero is indistinguishable from "not recorded", so it is counted and disclosed rather than
     * folded in.
     * ---------------------------------------------------------------------------------------------
     */

    public function test_visits_counts_page_views_from_every_store(): void
    {
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');

        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->logVisit((int) $storeA->id, $now->subDays(2), 'Desktop');
        $this->logVisit((int) $storeA->id, $now->subDays(3), 'Desktop');
        $this->logVisit((int) $storeB->id, $now->subDays(4), 'iPhone');

        $figures = PlatformAnalyticsFigures::forPlatform($now);

        $this->assertSame('3', $this->plain($figures['visits']['value']),
            'a per-store read would answer 2 or 1 here and look plausible; this figure is every '
            . 'store');
    }

    public function test_visits_excludes_page_views_outside_the_window(): void
    {
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');

        [, $store] = $this->makeTenant();

        $this->logVisit((int) $store->id, $now->subDays(2), 'Desktop');
        $this->logVisit((int) $store->id, $now->subDays(60), 'Desktop');

        $figures = PlatformAnalyticsFigures::forPlatform($now);

        $this->assertSame('1', $this->plain($figures['visits']['value']));
    }

    /**
     * A VISIT ROW POINTING AT A STORE THAT NO LONGER EXISTS must not be counted. `Visit` carries no
     * global scope, `StoreController::destroy()` leaves rows behind, and without an explicit
     * `whereIn` such a row is counted into a platform figure under a store nobody can open. This is
     * the mutation that survived the whole suite on the `devices` slice, so it is driven here too.
     */
    public function test_visits_ignores_a_page_view_belonging_to_no_existing_store(): void
    {
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');

        [, $store] = $this->makeTenant();

        $orphan = ((int) \App\Models\Store::query()->max('id')) + 991;

        $this->assertNull(\App\Models\Store::query()->withoutGlobalScopes()->find($orphan),
            'premise failed: the orphan id exists, so this test is not measuring an orphan row.');

        $this->logVisit((int) $store->id, $now->subDays(2), 'Desktop');
        $this->logVisit($orphan, $now->subDays(2), 'Desktop');

        $figures = PlatformAnalyticsFigures::forPlatform($now);

        $this->assertSame('1', $this->plain($figures['visits']['value']),
            'a page view attributed to no existing store was counted into a platform figure');
    }

    public function test_visits_says_when_attribution_began_later_than_the_window(): void
    {
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');

        [, $store] = $this->makeTenant();

        $this->logVisit((int) $store->id, $now->subDays(10), 'Desktop');

        $hint = $this->plain(PlatformAnalyticsFigures::forPlatform($now)['visits']['hint']);

        $this->assertStringContainsString('10 Nov 2026', $hint,
            'the window reaches back 30 days and attribution began 10 days ago, so the figure is '
            . 'short by the difference and the screen must say from when it is complete');
    }

    public function test_no_attribution_sentence_when_every_store_was_recorded_before_the_window(): void
    {
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');

        [, $store] = $this->makeTenant();

        $this->logVisit((int) $store->id, $now->subDays(60), 'Desktop');
        $this->logVisit((int) $store->id, $now->subDays(2), 'Desktop');

        $hint = $this->plain(PlatformAnalyticsFigures::forPlatform($now)['visits']['hint']);

        $this->assertStringNotContainsString('only partly covered', $hint,
            'a permanent "may be incomplete" note on every render is wallpaper, not a disclosure');

        /*
         * AND NEITHER CONDITIONAL SENTENCE FIRES. The only store on the platform has logged a page
         * view, so the never-logged count is zero. Asserted here because without it an off-by-one
         * in that subtraction -- counting every store rather than the ones with no rows -- prints
         * "Stores that have never logged a page view: 1" beside a figure that just counted one,
         * and no other case in this file looks at it.
         */
        $this->assertStringNotContainsString('never logged a page view', $hint,
            'the never-logged sentence fired for a platform where every store has logged one');
    }

    /**
     * THE LATEST START AMONG THE STORES THAT HAVE LOGGED ONE, for the same reason `verifiedFrom`
     * is the latest among the included stores: a total across stores is only as covered as its
     * least-covered contributor. Taking the EARLIEST would claim coverage the newest store does
     * not have.
     */
    public function test_the_attribution_start_is_the_latest_among_the_stores_that_have_logged_one(): void
    {
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');

        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->logVisit((int) $storeA->id, $now->subDays(25), 'Desktop');
        $this->logVisit((int) $storeB->id, $now->subDays(5), 'iPhone');

        $hint = $this->plain(PlatformAnalyticsFigures::forPlatform($now)['visits']['hint']);

        $this->assertStringContainsString('15 Nov 2026', $hint,
            'the later of the two starts is the honest one');
        $this->assertStringNotContainsString('26 Oct 2026', $hint,
            'the earlier start was used, which claims coverage the newer store does not have');
    }

    public function test_stores_that_have_never_logged_a_page_view_are_counted_and_disclosed(): void
    {
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');

        [, $storeA] = $this->makeTenant();
        $this->makeTenant();
        $this->makeTenant();

        $this->logVisit((int) $storeA->id, $now->subDays(2), 'Desktop');

        $hint = $this->plain(PlatformAnalyticsFigures::forPlatform($now)['visits']['hint']);

        $this->assertStringContainsString('never logged a page view', $hint);
        $this->assertStringContainsString('2', $hint,
            'two of the three stores have never logged one, and a zero from them is '
            . 'indistinguishable from "not recorded"');
    }

    public function test_visits_answers_safely_when_no_store_exists(): void
    {
        $figures = PlatformAnalyticsFigures::forPlatform(CarbonImmutable::parse('2026-11-20 12:00:00'));

        $this->assertArrayHasKey('visits', $figures);
        $this->assertNull($figures['visits']['value']);
        $this->assertNotSame('', trim((string) $figures['visits']['hint']));
    }

    /**
     * NO VISIT ROW IS EVER LOADED INTO PHP, which is a standing constraint on this screen rather
     * than a nicety: `Most Popular Store` used to read the store id of EVERY row of the visit log
     * into memory and was rewritten for exactly this reason (`P5-R3b`). The guard is on the SQL,
     * because a bounded-looking `get()` is only bounded by today's data.
     */
    public function test_no_query_reads_visit_rows_into_php(): void
    {
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');

        [, $store] = $this->makeTenant();
        $this->logVisit((int) $store->id, $now->subDays(2), 'Desktop');

        $table = config('visitor.table_name');
        $seen = [];

        DB::listen(function ($query) use (&$seen, $table) {
            if (str_contains(strtolower($query->sql), strtolower((string) $table))) {
                $seen[] = strtolower($query->sql);
            }
        });

        PlatformAnalyticsFigures::forPlatform($now);

        /*
         * THE PREMISE NAMES VISITS' OWN TWO STATEMENTS, because "some query touched the visit log"
         * was satisfied by the `devices` breakdown on its own -- an independent review measured
         * this method green BEFORE `visits()` existed. The count is a windowed `count(*)`; the
         * attribution scan is a `min(created_at)` grouped by store. If either disappears the
         * premise fails rather than the loop passing over a shorter list.
         */
        $this->assertNotEmpty($seen,
            'premise failed: no query touched the visit log at all, so this assertion checks '
            . 'nothing.');

        $this->assertNotEmpty(array_filter($seen, fn ($sql) => str_contains($sql, 'count(*)') && str_contains($sql, 'between')),
            'premise failed: no windowed count against the visit log was issued, so the page-view '
            . 'count is not among the queries this assertion is scanning.');

        $this->assertNotEmpty(array_filter($seen, fn ($sql) => str_contains($sql, 'min(')),
            'premise failed: no MIN(created_at) scan was issued, so the attribution-start query is '
            . 'not among the queries this assertion is scanning.');

        foreach ($seen as $sql) {
            $this->assertTrue(
                str_contains($sql, 'count(') || str_contains($sql, 'min(') || str_contains($sql, 'max(') || str_contains($sql, 'sum('),
                'a query against the visit log selects rows rather than an aggregate, so its cost '
                . "grows with the whole platform's traffic: {$sql}"
            );
        }
    }

    /**
     * EACH LABEL PAIRED WITH ITS OWN VALUE, not either one found loose on the page.
     *
     * THE FIRST VERSION OF THIS TEST PASSED BEFORE THE TILE EXISTED. It asserted that the page
     * contained "Page views" and a "1" -- and both were already there, the words inside the
     * `devices` breakdown's own hint and the digit anywhere at all. `<x-ss.stat>` renders the
     * label span immediately before the value span inside one `ss-stat` div
     * (`components/ss/stat.blade.php`), so reading them as PAIRS is what makes the assertion about
     * this tile rather than about the page. The exact-label comparison also catches the rename a
     * substring check cannot: on the *Repeat purchase* tile a mutation to `Repeat purchaseX`
     * SURVIVED `assertStringContainsString`, because the old label is a substring of the new one.
     *
     * @return array<string, string>
     */
    private function statTiles(string $html): array
    {
        preg_match_all(
            '/ss-stat__label">\s*(.*?)\s*<\/span>.*?ss-stat__value"[^>]*>\s*(.*?)\s*<\/span>/s',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        $tiles = [];

        foreach ($matches as $match) {
            $tiles[trim(strip_tags($match[1]))] = trim(strip_tags($match[2]));
        }

        return $tiles;
    }

    public function test_the_screen_shows_the_visits_tile(): void
    {
        $now = CarbonImmutable::parse('2026-11-20 12:00:00');
        $operator = $this->makePlatformAdmin();

        [, $store] = $this->makeTenant();

        // A DISTINCTIVE COUNT. Seventeen is not the value of any other tile on this screen with
        // this fixture, and it is not a prefix of one either.
        for ($i = 0; $i < 17; $i++) {
            $this->logVisit((int) $store->id, $now->subDays(2), 'Desktop');
        }

        CarbonImmutable::setTestNow($now);

        try {
            $html = $this->plain($this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200)->getContent());
        } finally {
            CarbonImmutable::setTestNow();
        }

        $tiles = $this->statTiles($html);

        $this->assertArrayHasKey('Page views', $tiles,
            'no stat tile on the operator screen is labelled exactly "Page views", so the figure '
            . 'is computed and never reaches the page -- the "computed but unreachable" shape this '
            . 'screen has already shipped three times. Labels found: '
            . implode(' | ', array_keys($tiles)));

        $this->assertSame('17', $tiles['Page views'],
            'the Page views tile does not carry the counted figure');
    }
}
