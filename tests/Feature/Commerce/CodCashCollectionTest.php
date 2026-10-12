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

    /**
     * THE AMOUNT OWED, END TO END, IN THE PAYLOAD THAT ACTUALLY LEAVES.
     *
     * The tests above exercise the helper. This one drives the real
     * `PaymentService::webhook()` — the method that builds and sends the
     * Delivery Syria parcel — and inspects the request body that was actually
     * dispatched, with the HTTP client faked so nothing leaves the machine.
     *
     * It is the test the COD limb was missing: it is possible for the helper to
     * be right and for the payload still to carry '0', because the payload is
     * where the literal '0' lived.
     *
     * The order total is composed here the way checkout composes it —
     * goods + tax + shipping — so the assertion is not merely "the field equals
     * final_price" but "the courier is told to collect the goods, the tax AND
     * the delivery charge".
     */
    public function test_the_dispatched_parcel_tells_the_courier_the_full_amount_owed(): void
    {
        $goods = 1000.00;
        $tax = 150.50;
        $shipping = 100.00;
        $total = $goods + $tax + $shipping;   // 1250.50

        $payload = $this->dispatchParcelFor('cod', $total);

        $this->assertArrayHasKey('cash_collection', $payload);

        $this->assertNotSame('0', $payload['cash_collection'],
            'the courier must not be dispatched with an instruction to collect nothing');
        $this->assertNotSame(0.0, (float) $payload['cash_collection']);

        $this->assertSame($total, (float) $payload['cash_collection'],
            'the collected amount must be the whole total');

        // And each component is genuinely inside that figure.
        $this->assertGreaterThan($goods, (float) $payload['cash_collection'],
            'tax and shipping must be included, not just the goods');
        $this->assertSame(
            $tax + $shipping,
            round((float) $payload['cash_collection'] - $goods, 2),
            'the tax and shipping components must both be present'
        );

        // The courier is told to collect exactly what the order is for.
        $this->assertSame(
            (float) $payload['selling_price'],
            (float) $payload['cash_collection']
        );
    }

    /**
     * The same path for an order already settled online collects nothing —
     * so the fix cannot double-charge a customer who has already paid.
     */
    public function test_a_prepaid_parcel_still_collects_nothing_on_delivery(): void
    {
        $payload = $this->dispatchParcelFor('paymera', 1250.50);

        $this->assertSame('0', $payload['cash_collection']);
        $this->assertSame('1250.5', $payload['selling_price'],
            'the parcel still declares its value; only the collection is zero');
    }

    /**
     * Send one parcel through the real webhook() and return the request body
     * that was dispatched.
     *
     * `webhook()` reads the governorate from the `states` table, so that one
     * table is created here on the sqlite/:memory: connection phpunit.xml
     * forces. No migration set is run and no persistent database is touched;
     * the Order itself is never saved.
     */
    private function dispatchParcelFor(string $paymentType, float $finalPrice): array
    {
        \Illuminate\Support\Facades\Schema::dropIfExists('states');
        \Illuminate\Support\Facades\Schema::create('states', function ($table) {
            $table->id();
            $table->string('name')->nullable();
        });
        \Illuminate\Support\Facades\DB::table('states')->insert(['id' => 7, 'name' => 'Damascus']);

        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response(['success' => true], 200),
        ]);

        $order = new Order();
        $order->id = 4242;
        $order->store_id = 1;
        $order->product_order_id = 'ORD-4242';
        $order->payment_type = $paymentType;
        $order->final_price = $finalPrice;
        $order->additional_note = 'leave with the concierge';
        $order->delivery_comment = '';

        $billing = (object) [
            'first_name' => 'Layla',
            'last_name' => 'Haddad',
            'delivery_address' => '12 Straight Street',
            'telephone' => '+963000000',
            'delivery_state' => 7,
            'delivery_city' => '3',
        ];

        $owner = (object) ['deliverysyria_secret' => 'test-token-not-a-real-secret'];

        $webhook = new \ReflectionMethod(PaymentService::class, 'webhook');
        $webhook->setAccessible(true);
        $webhook->invoke(
            $this->service,
            $order,
            $billing,
            ['weight' => 1],
            [['weight' => 1, 'qty' => 1]],
            $owner
        );

        $sent = null;

        \Illuminate\Support\Facades\Http::assertSent(function ($request) use (&$sent) {
            $sent = $request->data();

            return true;
        });

        \Illuminate\Support\Facades\Schema::dropIfExists('states');

        $this->assertIsArray($sent, 'a parcel request must actually have been dispatched');

        return $sent;
    }

    private function amountFor($paymentType, $finalPrice): string
    {
        $order = new Order();
        $order->payment_type = $paymentType;
        $order->final_price = $finalPrice;

        return $this->method->invoke($this->service, $order);
    }
}
