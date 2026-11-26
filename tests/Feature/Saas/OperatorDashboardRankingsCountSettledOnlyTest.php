<?php

namespace Tests\Feature\Saas;

use App\Models\Plan;
use App\Models\PlanCoupon;
use App\Models\PlanOrder;
use App\Models\PlanUserCoupon;
use App\Models\User;
use App\Services\SaasRevenueFigures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-231 — THE OPERATOR'S TWO RANKING CARDS COUNT SETTLED PURCHASES ONLY.
 * =================================================================================================
 *
 * `Popular Plan` and `Popular Coupon` ranked by an UNFILTERED row count: `most_purchese_plan()`
 * grouped every `plan_orders` row and the coupon query grouped every `plan_user_coupons` row. So a
 * plan whose every purchase FAILED could win "Popular Plan", and a coupon redeemed only on failed
 * orders could win "Popular Coupon". That is not a reporting definition — a ranking led by rows
 * representing no money is wrong — which is why it was fixed rather than put to the owner. The
 * question WAS decidable from the repository: `SaasRevenueFigures::SETTLED_PLAN_STATUSES` declares
 * the settled set and `PlanOrderSettledStatusCensusTest` classifies all eight spellings the
 * application writes.
 *
 * (The third card named in `GAP-231`, `Total Plan Orders`, is NOT filtered. "How many purchase
 * attempts exist" is a defensible operational count; it gets a disclosure instead, asserted in
 * `OperatorDashboardAttemptCountDisclosureTest`.)
 *
 * ============================================================================================
 *  WHY THE PREDICATE IS NOT `whereIn('payment_status', SETTLED_PLAN_STATUSES)`.
 * ============================================================================================
 *
 * That constant is LOWER-CASED, and the comparator beside it lower-cases and trims the stored
 * value before comparing (`SaasRevenueFigures::isSettled()`). The application really does store
 * capitalised spellings: a census of its literal writes finds `'success'` (40 sites) and
 * `'succeeded'` (21) but also **`'Succeeded'` (9)** and **`'Approved'` (6)** — and `'Approved'` is
 * what `BankTransferController::orderapprove()` writes when the OPERATOR approves a bank-transfer
 * receipt, which is as settled as a payment gets.
 *
 * A plain `whereIn` against the lower-cased constant therefore means two different things in the
 * two engines this project runs: MySQL's `utf8mb4_unicode_ci` is case-insensitive and would match
 * `'Approved'`, while SQLite's default collation is case-SENSITIVE and would not. The test suite
 * runs on SQLite and production is MySQL, so that predicate would be green here and differently
 * correct in production — CLAUDE.md's standing warning about SQLite hiding MySQL facts, in its
 * collation form rather than its affinity form.
 *
 * So the settled spellings are derived FROM THE DATA — `DISTINCT payment_status`, each classified
 * with the declared comparator — and matched as stored. That is the census inversion this project
 * insists on: enumerate what is really there and classify it, rather than list the spellings
 * somebody thought of. It cannot be short by a casing nobody imagined, and it behaves identically
 * on both engines.
 */
final class OperatorDashboardRankingsCountSettledOnlyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->operator = $this->makePlatformAdmin();
        $this->makeStore($this->operator, ['default_language' => 'en']);
        (new User)->flushCache();
        Cache::forget('admin_details');
    }

    private function makePlan(string $name): Plan
    {
        /* `plans.themes` and `plans.storage_limit` are NOT NULL with no default. */
        return Plan::create([
            'name' => $name . '-' . $this->uniqueFixtureRef(),
            'price' => 49,
            'duration' => 'Month',
            'themes' => '[]',
            'storage_limit' => '100',
        ]);
    }

    private function makePlanOrder(Plan $plan, string $paymentStatus, ?string $orderId = null): PlanOrder
    {
        return PlanOrder::create([
            'order_id' => $orderId ?? ('PO-' . $this->uniqueFixtureRef()),
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'price' => 49,
            'price_currency' => 'USD',
            'txn_id' => 'TX-' . $this->uniqueFixtureRef(),
            'user_id' => $this->operator->id,
            'store_id' => $this->operator->current_store ?? 1,
            'payment_status' => $paymentStatus,
        ]);
    }

    /**
     * PREMISE. The capitalised spellings are really in the application, so the engine-dependence
     * this file is built around is measured rather than theoretical. If the writers are ever
     * normalised, this premise fails and the derivation can be simplified.
     */
    #[Test]
    public function test_premise_the_application_writes_capitalised_settled_spellings(): void
    {
        $found = [];

        foreach (['app', 'packages/workdo'] as $root) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($root), \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                if (! str_contains($source, 'payment_status')) {
                    continue;
                }

                foreach (["'Succeeded'", "'Approved'"] as $spelling) {
                    if (str_contains($source, $spelling)) {
                        $found[$spelling] = true;
                    }
                }
            }
        }

        $this->assertArrayHasKey("'Approved'", $found,
            'no writer stores a capitalised `Approved` any more. If the writers were normalised, '
            . 'the data-derived spelling list in SettledPlanOrders can be simplified — but check '
            . 'the LIVE table before simplifying, because old rows keep their old spelling.');

        $this->assertArrayHasKey("'Succeeded'", $found);

        /* And the constant they must be compared against really is lower-cased, which is what
           makes a plain `whereIn` engine-dependent. */
        $this->assertSame(
            SaasRevenueFigures::SETTLED_PLAN_STATUSES,
            array_map('strtolower', SaasRevenueFigures::SETTLED_PLAN_STATUSES),
            'SETTLED_PLAN_STATUSES is no longer lower-cased, so the derivation needs re-deriving.'
        );
    }

    /**
     * THE CASE GAP-231 NAMES: a plan whose every purchase failed must not win.
     *
     * The loser gets THREE unsettled orders and the winner ONE settled order, so an unfiltered
     * count ranks them the wrong way round and the assertion can only pass with a predicate.
     */
    #[Test]
    public function test_a_plan_whose_purchases_all_failed_does_not_win_popular_plan(): void
    {
        $loser = $this->makePlan('Loser');
        $winner = $this->makePlan('Winner');

        foreach (['pending', 'failed', 'pandding'] as $status) {
            $this->makePlanOrder($loser, $status);
        }

        $this->makePlanOrder($winner, 'succeeded');

        $this->assertSame($winner->name, Plan::most_purchese_plan(),
            'Popular Plan is ranked by unfiltered row count, so a plan whose every purchase failed '
            . 'outranks a plan that was actually bought.');
    }

    /**
     * THE CASE A LOWER-CASED `whereIn` WOULD FAIL ON SQLITE AND PASS ON MySQL. `'Approved'` is the
     * operator's own bank-transfer approval, so it is settled and must win.
     */
    #[Test]
    public function test_a_plan_bought_by_bank_transfer_approval_wins_despite_its_capitalised_status(): void
    {
        $loser = $this->makePlan('Loser');
        $winner = $this->makePlan('Winner');

        foreach (['pending', 'rejected'] as $status) {
            $this->makePlanOrder($loser, $status);
        }

        $this->makePlanOrder($winner, 'Approved');

        $this->assertSame($winner->name, Plan::most_purchese_plan(),
            "a capitalised `Approved` — what BankTransferController::orderapprove() writes — was "
            . 'not counted as settled. A `whereIn` against the lower-cased constant does this on '
            . 'SQLite and not on MySQL.');
    }

    /** Also the capitalised `Succeeded`, which nine writers store. */
    #[Test]
    public function test_a_plan_with_a_capitalised_succeeded_is_counted_too(): void
    {
        $loser = $this->makePlan('Loser');
        $winner = $this->makePlan('Winner');

        $this->makePlanOrder($loser, 'failed');
        $this->makePlanOrder($winner, 'Succeeded');

        $this->assertSame($winner->name, Plan::most_purchese_plan());
    }

    /** Nothing settled anywhere is an empty answer, not the least-bad loser. */
    #[Test]
    public function test_no_settled_purchase_anywhere_ranks_nothing(): void
    {
        $plan = $this->makePlan('Attempted');

        foreach (['pending', 'failed'] as $status) {
            $this->makePlanOrder($plan, $status);
        }

        $this->assertSame('', Plan::most_purchese_plan(),
            'a plan nobody successfully bought was presented as the Popular Plan.');
    }

    /**
     * THE TIE-BREAK SURVIVES THE PREDICATE. A ranking with no stable tie-break is a card that
     * flaps between two values on identical data, which is worse than either answer.
     */
    #[Test]
    public function test_a_tie_is_broken_stably_by_the_earliest_order(): void
    {
        $first = $this->makePlan('First');
        $second = $this->makePlan('Second');

        $this->makePlanOrder($first, 'succeeded');
        $this->makePlanOrder($second, 'succeeded');

        $answers = [];

        for ($i = 0; $i < 4; $i++) {
            $answers[] = Plan::most_purchese_plan();
        }

        $this->assertSame([$first->name, $first->name, $first->name, $first->name], $answers,
            'the tie-break is gone: equal settled counts no longer resolve to the earliest order, '
            . 'so the card flaps on identical data.');
    }

    /* ======================================================================= the coupon ranking */

    private function makeCoupon(string $name): PlanCoupon
    {
        return PlanCoupon::create([
            'name' => $name . '-' . $this->uniqueFixtureRef(),
            'code' => strtoupper(substr($this->uniqueFixtureRef(), 0, 8)),
            'discount' => 10,
            'limits' => 100,
            'is_active' => 1,
            'created_by' => $this->operator->id,
        ]);
    }

    private function redeem(PlanCoupon $coupon, string $orderId): PlanUserCoupon
    {
        return PlanUserCoupon::create([
            'user_id' => $this->operator->id,
            'coupon_id' => $coupon->id,
            'order' => $orderId,
        ]);
    }

    private function popularCoupon(): string
    {
        $body = (string) $this->actingAs(User::find($this->operator->id))->get('/dashboard')->getContent();

        $this->assertNotSame('', $body, 'the operator dashboard did not render');

        return $body;
    }

    /**
     * A coupon redeemed only on orders that never paid must not win. The loser gets three
     * redemptions against unsettled orders, the winner one against a settled order.
     */
    #[Test]
    public function test_a_coupon_redeemed_only_on_unpaid_orders_does_not_win_popular_coupon(): void
    {
        $plan = $this->makePlan('P');
        $loser = $this->makeCoupon('Loser');
        $winner = $this->makeCoupon('Winner');

        foreach (['pending', 'failed', 'rejected'] as $i => $status) {
            $orderId = 'UNSETTLED-' . $i . '-' . $this->uniqueFixtureRef();
            $this->makePlanOrder($plan, $status, $orderId);
            $this->redeem($loser, $orderId);
        }

        $settledOrderId = 'SETTLED-' . $this->uniqueFixtureRef();
        $this->makePlanOrder($plan, 'succeeded', $settledOrderId);
        $this->redeem($winner, $settledOrderId);

        $body = $this->popularCoupon();

        $this->assertStringContainsString($winner->name, $body,
            'the coupon redeemed on a paid order is not the Popular Coupon.');

        $this->assertStringNotContainsString($loser->name, $body,
            'Popular Coupon is ranked by unfiltered redemption count, so a coupon redeemed only on '
            . 'orders that never paid outranks one redeemed on an order that did.');
    }

    /**
     * A REDEMPTION WHOSE ORDER CANNOT BE FOUND IS NOT SETTLED. `plan_user_coupons.order` is a
     * STRING against `plan_orders.order_id` with no foreign key, so a redemption can name an order
     * that does not exist — and it must not be counted as paid on the strength of being
     * unmatchable.
     */
    #[Test]
    public function test_a_redemption_naming_no_order_at_all_is_not_counted_as_settled(): void
    {
        $plan = $this->makePlan('P');
        $dangling = $this->makeCoupon('Dangling');
        $real = $this->makeCoupon('Real');

        for ($i = 0; $i < 3; $i++) {
            $this->redeem($dangling, 'NO-SUCH-ORDER-' . $i . '-' . $this->uniqueFixtureRef());
        }

        $settledOrderId = 'SETTLED-' . $this->uniqueFixtureRef();
        $this->makePlanOrder($plan, 'succeeded', $settledOrderId);
        $this->redeem($real, $settledOrderId);

        $body = $this->popularCoupon();

        $this->assertStringContainsString($real->name, $body);
        $this->assertStringNotContainsString($dangling->name, $body,
            'a coupon whose redemptions name no existing order won the ranking.');
    }
}
