<?php

namespace Tests\Feature\Commerce;

use App\Models\Order;
use App\Services\PaymentService;
use Tests\TestCase;

/**
 * The courier is told how much cash to collect.
 *
 * The defect: the Delivery Syria payload sent `'cash_collection' => '0'`
 * unconditionally, so a cash-on-delivery parcel was dispatched with an
 * instruction to collect NOTHING — the goods were handed over and no cash was
 * ever asked for.
 *
 * The amount is computed by a private method, which is exercised here by
 * reflection rather than by sending a real request to the courier: the point of
 * the test is the arithmetic and the payment-type condition, and no outbound
 * call to Delivery Syria is made or wanted.
 *
 * No database is needed; the Order is never persisted.
 */
class CodCashCollectionTest extends TestCase
{
    private \ReflectionMethod $method;
    private PaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PaymentService::class);
        $this->method = new \ReflectionMethod(PaymentService::class, 'codCashCollectionAmount');
        $this->method->setAccessible(true);
    }

    /** A COD order's collectable amount is the order total. */
    public function test_cod_order_collects_the_order_total(): void
    {
        $this->assertSame(
            '1250.5',
            $this->amountFor('cod', 1250.5)
        );
    }

    /** Every spelling of cash on delivery the platform uses. */
    public function test_all_cash_on_delivery_payment_types_collect(): void
    {
        foreach (['cod', 'COD', 'Cash_On_Delivery', 'cash'] as $type) {
            $this->assertSame(
                '400',
                $this->amountFor($type, 400),
                $type . ' must be treated as collect-on-delivery'
            );
        }
    }

    /** An order already settled online must not be collected again. */
    public function test_an_online_payment_collects_nothing(): void
    {
        foreach (['paymera', 'stripe', 'bank_transfer', 'paypal'] as $type) {
            $this->assertSame('0', $this->amountFor($type, 1250.5), $type . ' is already settled');
        }
    }

    /** No payment type at all collects nothing. */
    public function test_an_absent_payment_type_collects_nothing(): void
    {
        $this->assertSame('0', $this->amountFor(null, 1250.5));
        $this->assertSame('0', $this->amountFor('', 1250.5));
    }

    /**
     * A COD order with no collectable total is a data problem. It collects
     * nothing — but loudly, rather than silently shipping '0' as before.
     */
    public function test_a_cod_order_without_a_total_is_reported(): void
    {
        \Illuminate\Support\Facades\Log::shouldReceive('error')
            ->times(3)
            ->withArgs(static fn (string $message): bool => str_contains(
                $message,
                'COD order has no collectable total'
            ));

        $this->assertSame('0', $this->amountFor('cod', 0));
        $this->assertSame('0', $this->amountFor('cod', -10));
        $this->assertSame('0', $this->amountFor('cod', 'not a number'));
    }

    private function amountFor($paymentType, $finalPrice): string
    {
        $order = new Order();
        $order->payment_type = $paymentType;
        $order->final_price = $finalPrice;

        return $this->method->invoke($this->service, $order);
    }
}
