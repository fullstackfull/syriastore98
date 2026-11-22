<?php

namespace Tests\Feature\Platform;

use App\Support\Metrics\PlatformAnalyticsFigures;
use Carbon\CarbonImmutable;
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
}
