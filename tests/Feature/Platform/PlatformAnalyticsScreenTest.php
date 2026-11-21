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
}
