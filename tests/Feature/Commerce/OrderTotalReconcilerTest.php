<?php

namespace Tests\Feature\Commerce;

use App\Services\OrderTotalReconciler;
use RuntimeException;
use Tests\TestCase;

/**
 * Exact-decimal reconciliation of an order total against its components.
 *
 * These tests need no database: the reconciler is pure arithmetic on strings.
 * They are the proof that comparison is EXACT rather than `==` on floats, which
 * is the property the whole checkout-integrity fix rests on.
 *
 * @see \App\Services\OrderTotalReconciler
 */
class OrderTotalReconcilerTest extends TestCase
{
    private OrderTotalReconciler $reconciler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reconciler = new OrderTotalReconciler();
    }

    /** A clean checkout: components sum to the total, exactly, as strings. */
    public function test_persisted_total_equals_component_sum(): void
    {
        $result = $this->reconciler->reconcile([
            'product_price' => '10000.00',
            'tax_price' => '1500.00',
            'delivery_price' => '500.00',
            'coupon_price' => '2000.00',
        ], '10000.00');

        $this->assertTrue($result['matches'], $result['reason']);
        $this->assertSame('10000.0000', $result['expected']);
        $this->assertSame('0.0000', $result['difference']);
    }

    /**
     * THE EXACT-DECIMAL PROOF.
     *
     * A claimed total of 12341.0000000000018 against components summing to
     * 12341.00 must be REJECTED. A float comparison would accept it, and a
     * reconciler that rounded the claim to scale before comparing would accept
     * it too — which is why the reconciler treats excess precision as
     * unrepresentable instead of rounding it away.
     */
    public function test_reconciler_rejects_inconsistent_total(): void
    {
        $result = $this->reconciler->reconcile(
            ['product_price' => '12341.00'],
            '12341.0000000000018'
        );

        $this->assertFalse($result['matches']);
        $this->assertSame('claimed_total_not_representable', $result['reason']);
    }

    /** The same, arriving as a PHP float rather than a string. */
    public function test_reconciler_rejects_a_float_artefact_total(): void
    {
        $result = $this->reconciler->reconcile(
            ['product_price' => '0.30'],
            0.1 + 0.2
        );

        $this->assertFalse($result['matches']);
        $this->assertSame('claimed_total_not_representable', $result['reason']);
    }

    /** A one-minor-unit understatement is caught, not absorbed. */
    public function test_reconciler_rejects_a_single_minor_unit_understatement(): void
    {
        $result = $this->reconciler->reconcile(
            ['product_price' => '100.00', 'tax_price' => '15.00'],
            '114.9999'
        );

        $this->assertFalse($result['matches']);
        $this->assertSame('-0.0001', $result['difference']);
    }

    /** coupon_price, reward_discount and return_price SUBTRACT. */
    public function test_subtractive_components_reduce_the_expected_total(): void
    {
        foreach (OrderTotalReconciler::SUBTRACTIVE_COMPONENTS as $component) {
            $result = $this->reconciler->reconcile(
                ['product_price' => '100.00', $component => '10.00'],
                '90.00'
            );

            $this->assertTrue($result['matches'], $component . ' should subtract');
        }
    }

    /** Scale 4, because the seeded currency list includes 3-decimal KWD/BHD/OMR. */
    public function test_three_decimal_currency_amounts_reconcile(): void
    {
        $result = $this->reconciler->reconcile(
            ['product_price' => '12.345', 'tax_price' => '0.617'],
            '12.962'
        );

        $this->assertTrue($result['matches'], $result['reason']);
    }

    /** A ten-year SYP-scale aggregate still reconciles exactly. */
    public function test_syp_scale_aggregate_round_trips_exactly(): void
    {
        $result = $this->reconciler->reconcile(
            ['product_price' => '99999999999999.9999'],
            '99999999999999.9999'
        );

        $this->assertTrue($result['matches'], $result['reason']);
    }

    /** The write path must be able to refuse, loudly. */
    public function test_assert_consistent_throws_on_a_mismatch(): void
    {
        $this->expectException(RuntimeException::class);

        $this->reconciler->assertConsistent(
            ['product_price' => '100.00'],
            '1.00',
            self::class
        );
    }

    /** Pre-cutover rows are reported, not repaired and not rejected. */
    public function test_warn_only_mode_does_not_throw(): void
    {
        $this->assertFalse(
            $this->reconciler->warnIfInconsistent(
                ['product_price' => '100.00'],
                '1.00',
                self::class
            )
        );
    }

    /**
     * Line items sum exactly: 10 x 1234.10 is 12341.0000, not a float artefact.
     */
    public function test_line_items_sum_exactly(): void
    {
        $this->assertSame(
            '12341.0000',
            $this->reconciler->sumLineItems([['price' => '1234.10', 'qty' => 10]])
        );
    }

    /** A fractional quantity is refused rather than silently rounded. */
    public function test_fractional_quantity_is_refused(): void
    {
        $this->assertNull(
            $this->reconciler->sumLineItems([['price' => '1.00', 'qty' => 1.5]])
        );
    }

    /**
     * The merchant-admin defect, as a unit: a total below the line-item sum with
     * `coupon_price` hardcoded to 0 does not reconcile.
     *
     * This is the assertion that OrderController::store needs. That controller
     * belongs to another agent in this window, so the reconciler is proven here
     * and the wiring is reported as a cross-agent dependency.
     */
    public function test_merchant_order_rejects_undeclared_total_mismatch(): void
    {
        $result = $this->reconciler->reconcileLineItems(
            [['price' => '100.00', 'qty' => 2]],
            ['coupon_price' => '0'],
            '150.00'
        );

        $this->assertFalse($result['matches']);
        $this->assertSame('200.0000', $result['expected']);
        $this->assertSame('-50.0000', $result['difference']);
    }

    /** The same discount, DECLARED, is a legitimate workflow and is allowed. */
    public function test_merchant_order_allows_declared_discount(): void
    {
        $result = $this->reconciler->reconcileLineItems(
            [['price' => '100.00', 'qty' => 2]],
            ['coupon_price' => '50.00'],
            '150.00'
        );

        $this->assertTrue($result['matches'], $result['reason']);
    }
}
