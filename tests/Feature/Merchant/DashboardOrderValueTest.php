<?php

namespace Tests\Feature\Merchant;

use App\Http\Controllers\HomeController;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * WS C — "Order value": what the dashboard's one money figure actually counts.
 * =================================================================================================
 *
 * PRD-001 is the reason this file exists rather than a contrast check. The master prompt's
 * Workstream C says, in its own words, *"Do not invent financial metrics. Do not display unverified
 * profitability. Preserve differences between sales, payments, settlements, and profit."* The
 * dashboard shows exactly one money figure, labelled **Order value** and qualified **"all time,
 * gross"**, with the hint *"The total value of orders placed. This is not payments received, and
 * not profit."* Every word of that is a claim, and until this file nothing measured any of them.
 *
 * WHAT IT IS. `HomeController::handleRegularUser()`:
 *
 *     $total_revenues = (clone $orderQuery)->where(function ($query) {
 *         $query->where(function ($subquery) {
 *             $subquery->where('delivered_status', '!=', 2)
 *                 ->where('delivered_status', '!=', 3);
 *         })->orWhere('return_status', '!=', 2);
 *     })->sum('final_price');
 *
 * — `SUM(orders.final_price)` over orders in this store, with a filter that LOOKS like it excludes
 * cancellations and returns.
 *
 * ------------------------------------------------------------------------------------------------
 * F257. THAT FILTER EXCLUDES ALMOST NOTHING, AND THIS FILE PINS WHAT IT REALLY DOES.
 *
 * The two conditions are joined by `orWhere`, so an order is counted when EITHER holds. A
 * cancelled order has `delivered_status = 2`, which fails the first group — and then
 * `return_status != 2` is asked. `orders.return_status` defaults to `0` (`0 none, 1 request,
 * 2 approve, 3 cancel`), and `0 != 2` is TRUE, so the cancelled order is counted anyway. The only
 * orders the filter can exclude are those whose return has been APPROVED, and only when they are
 * also cancelled or returned by fulfilment status.
 *
 * So the figure is, in practice, every order's `final_price` — cancellations included.
 *
 * WHETHER THAT IS WRONG IS NOT AN ENGINEERING CALL, which is why this file MEASURES rather than
 * changes. Two defensible readings, and they disagree about a number the merchant reads:
 *   - the label is "the total value of orders PLACED", and a cancelled order was placed, so
 *     including it is correct and the filter is dead weight that should go; or
 *   - the filter's shape says somebody meant to exclude cancellations, and the `orWhere` is a
 *     defect that has been inflating the figure.
 * Changing it changes a reported business figure, so it is recorded as **F257** for the owner.
 * ------------------------------------------------------------------------------------------------
 *
 * WHAT IS ASSERTED HERE IS THE CONTRACT AS IT STANDS: the figure is gross order value, it is
 * store-scoped, it is NOT payments received, and it is gated on `Manage Order`. If somebody later
 * takes the owner's decision and narrows it, these tests fail and say exactly which property
 * changed — which is the point of pinning a figure you have not been authorised to change.
 */
class DashboardOrderValueTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        $this->grantPermission(User::find($this->merchant->id), 'Manage Order');

        $this->merchant = User::find($this->merchant->id);
    }

    private function dashboard(?User $as = null): string
    {
        $response = $this->actingAs($as ?? $this->merchant)->get('/dashboard');
        $response->assertStatus(200);

        return (string) $response->getContent();
    }

    /** The rendered "Order value" figure, read from its own stat rather than from the page. */
    private function renderedOrderValue(?User $as = null): ?string
    {
        $body = $this->dashboard($as);

        if (! preg_match(
            '/Order value.*?<(?:p|span|div)[^>]*class="[^"]*ss-stat__value[^"]*"[^>]*>\s*([^<]+?)\s*</s',
            $body,
            $m
        )) {
            return null;
        }

        return trim($m[1]);
    }

    private function expectedFormat(float $amount): string
    {
        return currency_format_with_sym($amount, $this->store->id);
    }

    // ---------------------------------------------------------------------------------------
    //  WHAT THE FIGURE IS
    // ---------------------------------------------------------------------------------------

    public function test_the_figure_is_the_sum_of_this_stores_order_totals(): void
    {
        $this->makeOrder($this->store, ['final_price' => 1200.00, 'delivered_status' => HomeController::ORDER_STATUS_DELIVERED]);
        $this->makeOrder($this->store, ['final_price' => 300.50, 'delivered_status' => HomeController::ORDER_STATUS_PENDING]);
        (new Order)->flushCache();

        $value = $this->renderedOrderValue();

        $this->assertNotNull($value, 'the Order value stat did not render, so nothing was measured');
        $this->assertSame($this->expectedFormat(1500.50), $value);
    }

    /**
     * `Order` IS NOT IN `config/tenancy.php`'s `scoped_models`, so the handler's own
     * `where('store_id')` is the only thing keeping another tenant's money out of this number.
     */
    public function test_another_tenants_orders_are_not_in_it(): void
    {
        $this->makeOrder($this->store, ['final_price' => 1000.00]);

        $other = $this->makeMerchant();
        $otherStore = $this->makeStore($other, ['default_language' => 'en']);
        $this->makeOrder($otherStore, ['final_price' => 9999.00]);
        (new Order)->flushCache();

        $this->assertSame($this->expectedFormat(1000.00), $this->renderedOrderValue());
    }

    /**
     * [2026-11-01] F257 IS DECIDED, AND THIS CASE IS INVERTED. The owner's decision: the dashboard's
     * actionable order-value metric EXCLUDES cancelled orders.
     *
     * **THIS TEST USED TO ASSERT THE OPPOSITE, ON PURPOSE.** It read
     * `test_a_cancelled_order_is_counted_because_the_or_limb_admits_it`, and its failure message
     * said: *"the cancelled order is no longer counted — which may be an improvement, but it is a
     * change to a reported business figure and belongs to the owner (F257), not to a refactor."*
     * That was the right thing to assert while the decision was open: it held the shipped figure so
     * no refactor could change a business number by accident. The decision has been taken, so the
     * pin comes off.
     *
     * The old predicate excluded almost nothing. `where(delivered_status != 2 AND != 3) OR
     * (return_status != 2)` — a cancelled order fails the first group and `return_status` defaults
     * to 0, so `0 != 2` admits it through the second. It is now one AND-ed predicate.
     */
    public function test_a_cancelled_order_is_excluded(): void
    {
        $this->makeOrder($this->store, ['final_price' => 400.00, 'delivered_status' => HomeController::ORDER_STATUS_DELIVERED]);
        $this->makeOrder($this->store, [
            'final_price' => 600.00,
            'delivered_status' => HomeController::ORDER_STATUS_CANCEL,
            'return_status' => 0,
        ]);
        (new Order)->flushCache();

        $this->assertSame($this->expectedFormat(400.00), $this->renderedOrderValue(),
            'the cancelled order is still counted. 1000.00 means the OR limb is back; the owner '
            . 'decided (F257) that this figure excludes cancellations'
        );
    }

    /**
     * A CANCELLED ORDER IS EXCLUDED WHATEVER ITS RETURN STATUS, because the predicate no longer
     * consults `return_status` at all. Both values are driven so a reintroduced OR limb fails here
     * too rather than only in the case above.
     */
    public function test_a_cancelled_order_is_excluded_for_every_return_status(): void
    {
        $this->makeOrder($this->store, ['final_price' => 400.00, 'delivered_status' => HomeController::ORDER_STATUS_DELIVERED]);

        foreach ([0, 1, 2] as $returnStatus) {
            $this->makeOrder($this->store, [
                'final_price' => 600.00,
                'delivered_status' => HomeController::ORDER_STATUS_CANCEL,
                'return_status' => $returnStatus,
            ]);
        }

        (new Order)->flushCache();

        $this->assertSame($this->expectedFormat(400.00), $this->renderedOrderValue(),
            'a cancelled order was counted for some return_status, so the figure still depends on '
            . 'a column the decision does not mention');
    }

    /**
     * RETURNED ORDERS ARE STILL INCLUDED, and this pins that as a NON-decision rather than an
     * oversight — the same service the inverted case above used to perform.
     *
     * The dead first group of the old predicate shows its author meant to exclude status 3 as well,
     * and there is a case for it: a returned order's money came back just as a cancelled one's never
     * arrived. But the owner's F257 decision names CANCELLED orders and nothing else, so excluding
     * returns here would be inventing the next decision. If the owner wants it, this test is the one
     * that flips.
     */
    public function test_a_returned_order_is_still_counted_pending_a_separate_decision(): void
    {
        $this->makeOrder($this->store, ['final_price' => 400.00, 'delivered_status' => HomeController::ORDER_STATUS_DELIVERED]);
        $this->makeOrder($this->store, [
            'final_price' => 600.00,
            'delivered_status' => HomeController::ORDER_STATUS_RETURN,
        ]);
        (new Order)->flushCache();

        $this->assertSame($this->expectedFormat(1000.00), $this->renderedOrderValue(),
            'returned orders stopped being counted. That may well be right, but F257 decided '
            . 'CANCELLED orders only — so this is a second reported-figure change and belongs to '
            . 'the owner, not to a slice that was asked for the first one'
        );
    }

    /**
     * NOT PAYMENTS RECEIVED, which is the distinction PRD-001 names by itself. Phase 01's
     * P1-PAY-04 established that Delivered is not Paid; the figure must not start tracking
     * `payment_status`.
     */
    public function test_an_unpaid_order_is_still_in_the_figure_because_it_is_order_value(): void
    {
        $this->makeOrder($this->store, ['final_price' => 750.00, 'payment_status' => 'Unpaid']);
        (new Order)->flushCache();

        $this->assertSame($this->expectedFormat(750.00), $this->renderedOrderValue(),
            'the figure started excluding unpaid orders, which turns "order value" into '
            . '"payments received" — a different number under the same label'
        );
    }

    // ---------------------------------------------------------------------------------------
    //  WHAT THE PAGE SAYS ABOUT IT
    // ---------------------------------------------------------------------------------------

    public function test_the_figure_states_its_period_and_that_it_is_gross(): void
    {
        $this->makeOrder($this->store, ['final_price' => 100.00]);
        (new Order)->flushCache();

        $body = $this->dashboard();

        $this->assertStringContainsString('Order value', $body);
        $this->assertStringContainsString('all time, gross', $body,
            'the figure no longer states its period and that it is gross — a count with no '
            . 'period is the commonest way a dashboard misleads'
        );
        $this->assertStringContainsString('This is not payments received, and not profit', $body);

        /*
         * F257 ASKED THE LABEL TO STATE FOUR THINGS: the meaning, the period, the currency and the
         * exclusion. The first three were already there; the exclusion is new, and it has to be
         * VISIBLE rather than only in the hint — a hint is hover-only, and a phone has no hover, so
         * an exclusion a merchant cannot see is one they will not know about.
         */
        $this->assertStringContainsString('excluding cancelled', $body,
            'the label does not say that cancelled orders are excluded, so a merchant comparing '
            . 'this figure against their own order list has no way to reconcile the difference');

        $this->assertMatchesRegularExpression(
            '/all time, gross, excluding cancelled/',
            $body,
            'the exclusion is not on the visible period line'
        );

        /* THE CURRENCY. `currency_format_with_sym()` prints the STORE's symbol, so a merchant never
           has to guess which currency a dashboard figure is in. Asserted against the store's own
           formatter rather than a hardcoded symbol, so a store configured in another currency does
           not fail this. */
        $this->assertStringContainsString($this->expectedFormat(100.00), $body,
            'the figure is not rendered through the store currency formatter, so it carries no '
            . 'currency at all');
    }

    /**
     * PRD-001 BY ITS OWN WORDS: no invented financial metric. The controller computes no margin,
     * no profit and no settlement figure, and the dashboard must not start labelling this one as
     * any of them.
     */
    public function test_the_dashboard_claims_no_revenue_profit_or_margin(): void
    {
        $this->makeOrder($this->store, ['final_price' => 100.00]);
        (new Order)->flushCache();

        $body = $this->dashboard();

        foreach (['Profit', 'Margin', 'Net revenue', 'Settlement'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body,
                "the dashboard is labelling a figure \"{$forbidden}\", which this codebase "
                . 'computes nowhere'
            );
        }
    }

    // ---------------------------------------------------------------------------------------
    //  WHO MAY SEE IT
    // ---------------------------------------------------------------------------------------

    public function test_a_principal_without_manage_order_is_not_shown_the_figure(): void
    {
        $this->makeOrder($this->store, ['final_price' => 4242.00]);
        (new Order)->flushCache();

        $staff = $this->makeStaff($this->merchant, ['current_store' => $this->store->id]);
        $this->grantPermission(User::find($staff->id), 'Manage Blog');

        $body = $this->dashboard(User::find($staff->id));

        $this->assertStringNotContainsString('Order value', $body);
        $this->assertStringNotContainsString($this->expectedFormat(4242.00), $body,
            'the money figure itself is on the page for a principal without Manage Order, even '
            . 'though its label is not'
        );
    }

    /**
     * AND THE GATE IS NOT A DELETION. A figure hidden from everybody passes the test above for
     * the wrong reason.
     */
    public function test_a_principal_with_manage_order_is_shown_it(): void
    {
        $this->makeOrder($this->store, ['final_price' => 4242.00]);
        (new Order)->flushCache();

        $body = $this->dashboard();

        $this->assertStringContainsString('Order value', $body);
        $this->assertStringContainsString($this->expectedFormat(4242.00), $body);
    }
}
