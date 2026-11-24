<?php

namespace Tests\Feature\Metrics;

use App\Support\Metrics\MetricCaveat;
use App\Support\Metrics\MetricScope;
use App\Support\Metrics\MetricWindow;
use App\Support\Metrics\StoreRevenueMargin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ANA-006(c) / GAP-227 — THE MARGIN FIGURE DOES NOT SUBTRACT PAYMENT FEES AND NEVER SAID SO.
 * =============================================================================================
 *
 * `ANA-006` is the requirement that this product must NOT claim precise profitability when cost or
 * fee data is missing. Its COST half has been disclosed since P5-10. Its FEE half had no
 * disclosure at all, on any surface, and the measurement of 2026-11-22 confirmed the underlying
 * fact is unchanged: `store_order_financials` has no fee column, nothing anywhere records what a
 * gateway charged, and `MetricCaveat` had no constant for it.
 *
 * SO THE FIGURE WAS SILENTLY OVERSTATED FOR EVERY STORE THAT TAKES CARD PAYMENTS, with no way for
 * a merchant to know. `gross_profit` is `(product_amount − discount_amount) − product_cost`; a
 * gateway's percentage comes out of the money actually received and is simply absent from the
 * arithmetic.
 *
 * THIS STATES AN OMISSION. IT DEFINES NOTHING. No rate is invented, no fee is estimated, no
 * gateway is read, and no figure changes value — which is exactly why this half was buildable
 * without an owner decision while the fee DATA question (`D-N9`/`D-N13`) remains the owner's.
 * Writing a fee into the arithmetic would need a source and a rule; saying the arithmetic does not
 * have one needs neither.
 *
 * WHY THE CAVEAT IS UNCONDITIONAL. There is no per-store or per-order signal to condition it on —
 * the absence is total. A caveat shown only "when fees apply" would require knowing when they
 * apply, which is the very thing that is missing.
 */
class MarginDisclosesPaymentFeesTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function marginFor(int $storeId)
    {
        return (new StoreRevenueMargin(true))->for(
            MetricScope::store($storeId),
            MetricWindow::between(now()->subDays(30), now())
        );
    }

    private function paidOrderIn($store, float $amount = 100.0, float $cost = 40.0): void
    {
        $orderId = DB::table('orders')->insertGetId([
            'product_order_id' => 'FEE-' . $this->uniqueFixtureRef(),
            'store_id' => $store->id, 'customer_id' => 0, 'is_guest' => 1,
            'final_price' => $amount, 'order_date' => now()->subDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $walletId = DB::table('store_wallets')->insertGetId([
            // Column set copied from the proven fixture in MetricLayerTest.php:75-80
            // rather than guessed; this table's shape has already cost two wrong attempts here.
            'store_id' => $store->id, 'currency_code' => 'USD',
            'available_balance' => 0, 'pending_balance' => 0,
            'withdrawal_pending_balance' => 0, 'total_income' => 0, 'total_withdrawn' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('store_order_financials')->insert([
            'order_id' => $orderId, 'store_id' => $store->id, 'wallet_id' => $walletId,
            'payment_status' => 'paid', 'paid_at' => now()->subDay(),
            'product_amount' => $amount, 'discount_amount' => 0, 'product_cost' => $cost,
            'currency_code' => 'USD', 'customer_total' => $amount, 'tax_amount' => 0, 'shipping_amount' => 0,
            'gross_profit' => $amount - $cost, 'paid_amount' => $amount, 'remaining_amount' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_vocabulary_has_a_constant_for_the_missing_fee_data(): void
    {
        $this->assertTrue(defined(MetricCaveat::class . '::EXCLUDES_PAYMENT_FEES'),
            'MetricCaveat has no constant for payment fees, so no figure can disclose that it omits them.');

        $message = MetricCaveat::message(MetricCaveat::EXCLUDES_PAYMENT_FEES);

        $this->assertNotSame('', trim((string) $message),
            'The constant exists with no message, so a merchant would be shown nothing.');
        $this->assertStringContainsStringIgnoringCase('fee', (string) $message);
    }

    public function test_the_margin_figure_discloses_that_it_omits_payment_fees(): void
    {
        [, $store] = $this->makeTenant();
        $this->paidOrderIn($store);

        $caveats = $this->marginFor($store->id)->caveats();

        $this->assertContains(MetricCaveat::EXCLUDES_PAYMENT_FEES, $caveats,
            'The margin figure subtracts product cost and not payment fees, and does not say so. '
            . 'ANA-006 is the requirement that it must not claim precise profitability while fee data is missing.');
    }

    /**
     * The caveat it sits beside must survive. `EXCLUDES_SHIPPING_AND_TAX` is unconditional for the
     * same structural reason, and a change that replaced one with the other would read as success.
     */
    public function test_the_shipping_and_tax_caveat_is_not_displaced_by_the_fee_one(): void
    {
        [, $store] = $this->makeTenant();
        $this->paidOrderIn($store);

        $caveats = $this->marginFor($store->id)->caveats();

        $this->assertContains(MetricCaveat::EXCLUDES_SHIPPING_AND_TAX, $caveats);
        $this->assertContains(MetricCaveat::EXCLUDES_PAYMENT_FEES, $caveats);
    }

    /** It is unconditional: a store with no orders at all still omits fees, and still says so. */
    public function test_the_disclosure_does_not_depend_on_there_being_orders(): void
    {
        [, $store] = $this->makeTenant();

        $this->assertContains(MetricCaveat::EXCLUDES_PAYMENT_FEES, $this->marginFor($store->id)->caveats(),
            'The fee disclosure was conditioned on something. There is no per-store or per-order fee signal to condition it on — that absence is the defect.');
    }
}
