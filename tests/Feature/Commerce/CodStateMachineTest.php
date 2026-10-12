<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\OrderController;
use App\Models\Order;
use App\Services\PaymentVerificationService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * THE CASH-ON-DELIVERY STATE MACHINE.
 *
 * THE DEFECT. PaymentService::process() routes 'cod' straight to
 * getProductStatus() (:315) and the order writer recorded it 'Paid' at once, so
 * a cash-on-delivery order counted as paid before the courier had collected
 * anything. An order placed and never delivered stayed Paid forever, so revenue
 * figures counted cash nobody handed over.
 *
 * THE EVIDENCE THAT THIS IS WRONG IS IN THE PLATFORM'S OWN CODE, not in an
 * opinion: Order::order_status_change() writes `payment_status = 'Unpaid'` when
 * an order is CANCELLED (app/Models/Order.php:550), with a comment calling it
 * the missing magic line needed "to refund the amount". There is nothing to
 * refund on a COD order that was never delivered. That reversal exists purely to
 * undo a Paid state the order should never have had -- and an Observer reacts to
 * it by adjusting the wallet, so the premature Paid propagates into the
 * financial records and then has to be walked back.
 *
 * WHAT IS IMPLEMENTED. Both halves of the correct machine:
 *
 *   placed    -> Pending   (PaymentVerificationService::resolveOrderPaymentStatus)
 *   delivered -> Paid      (OrderController::order_status_change)
 *   cancelled -> stays unpaid; nothing to reverse
 *
 * AND IT IS OFF BY DEFAULT. `services.cod.settlement` defaults to 'order', the
 * pre-existing behaviour, because when an order counts as paid decides when a
 * merchant's wallet is credited -- a business decision for the owner, not a fix
 * to impose on a live shop. Half of this suite exists to prove the default is
 * genuinely unchanged, which is the property a merchant relying on today's
 * behaviour actually cares about.
 */
class CodStateMachineTest extends TestCase
{
    private PaymentVerificationService $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verifier = new PaymentVerificationService();
    }

    /*
    |--------------------------------------------------------------------------
    | THE DEFAULT IS UNCHANGED -- the preservation half
    |--------------------------------------------------------------------------
    */

    /**
     * With nothing configured, the policy is settle-at-order.
     *
     * BOTH defaults are pinned, deliberately. `config/services.php` declares
     * `'settlement' => env('COD_SETTLEMENT', 'order')`, so the CONFIG FILE's
     * default is what actually governs and the PHP fallback in
     * codSettlementPolicy() is never reached while the key is declared. A test
     * that only exercised the helper would stay green if the config file were
     * changed to 'delivery' -- measured, not assumed: flipping the helper's PHP
     * fallback to COD_SETTLEMENT_ON_DELIVERY produced NO failure at all, which
     * is how this gap was found. So the config value is asserted directly.
     */
    public function test_the_default_policy_is_settle_at_order(): void
    {
        $this->assertSame(
            PaymentVerificationService::COD_SETTLEMENT_AT_ORDER,
            config('services.cod.settlement'),
            'config/services.php must default COD to settle-at-order, because that '
                . 'declared value is what governs -- not the helper fallback'
        );

        $this->assertSame(
            PaymentVerificationService::COD_SETTLEMENT_AT_ORDER,
            $this->verifier->codSettlementPolicy(),
            'the resolved policy must be the pre-existing behaviour'
        );
    }

    /**
     * The helper's own fallback, exercised by removing the declared key.
     *
     * This is the path a deployment with an older config/services.php takes, and
     * it must also land on the pre-existing behaviour.
     */
    public function test_the_helper_falls_back_to_settle_at_order_when_the_key_is_absent(): void
    {
        config(['services.cod' => null]);

        $this->assertSame(
            PaymentVerificationService::COD_SETTLEMENT_AT_ORDER,
            $this->verifier->codSettlementPolicy(),
            'an undeclared key must still mean the pre-existing behaviour'
        );
    }

    /** With the default policy a COD order is still recorded Paid at once. */
    #[DataProvider('codSpellings')]
    public function test_cod_is_still_paid_at_order_time_by_default(string $spelling): void
    {
        $this->assertSame(
            PaymentVerificationService::STATUS_PAID,
            $this->verifier->resolveOrderPaymentStatus($spelling, null, '100.00', __METHOD__),
            'default COD behaviour must be byte-for-byte what it was'
        );
    }

    /** And the delivery-side transition is inert under the default policy. */
    public function test_the_delivery_transition_is_inert_by_default(): void
    {
        $order = $this->codOrder('Paid');

        $this->assertFalse(
            $this->verifier->codShouldSettleOnDelivery($order),
            'nothing may settle on delivery while the default policy is in force'
        );

        $this->assertFalse(
            $this->verifier->codShouldSettleOnDelivery($this->codOrder('Unpaid')),
            'not even an unpaid COD order, while the policy is settle-at-order'
        );
    }

    /** The other offline types are untouched by any of this. */
    #[DataProvider('otherOfflineTypes')]
    public function test_other_offline_payment_types_are_unaffected(string $type): void
    {
        config(['services.cod.settlement' => PaymentVerificationService::COD_SETTLEMENT_ON_DELIVERY]);

        $this->assertSame(
            PaymentVerificationService::STATUS_PAID,
            $this->verifier->resolveOrderPaymentStatus($type, null, '100.00', __METHOD__),
            'the COD policy must not change bank transfer or whatsapp'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | THE CORRECT MACHINE, when the owner opts in
    |--------------------------------------------------------------------------
    */

    /** Opted in, a COD order is born Pending rather than Paid. */
    #[DataProvider('codSpellings')]
    public function test_an_opted_in_cod_order_is_pending_when_placed(string $spelling): void
    {
        config(['services.cod.settlement' => PaymentVerificationService::COD_SETTLEMENT_ON_DELIVERY]);

        $this->assertSame(
            PaymentVerificationService::STATUS_PENDING,
            $this->verifier->resolveOrderPaymentStatus($spelling, null, '100.00', __METHOD__),
            'no money has moved at checkout on a cash-on-delivery order'
        );
    }

    /** Opted in, an unpaid COD order settles on delivery. */
    public function test_an_opted_in_unpaid_cod_order_settles_on_delivery(): void
    {
        config(['services.cod.settlement' => PaymentVerificationService::COD_SETTLEMENT_ON_DELIVERY]);

        $this->assertTrue(
            $this->verifier->codShouldSettleOnDelivery($this->codOrder('Pending'))
        );
        $this->assertTrue(
            $this->verifier->codShouldSettleOnDelivery($this->codOrder('Unpaid'))
        );
    }

    /**
     * IDEMPOTENT. A COD order that is already Paid does not settle again, so a
     * replayed 'delivered' status change cannot credit the merchant twice.
     */
    public function test_an_already_paid_cod_order_does_not_settle_again(): void
    {
        config(['services.cod.settlement' => PaymentVerificationService::COD_SETTLEMENT_ON_DELIVERY]);

        $this->assertFalse(
            $this->verifier->codShouldSettleOnDelivery($this->codOrder('Paid')),
            'a replayed delivery must not settle a second time'
        );

        // Case-insensitively, because payment_status is a free-text string
        // column and 'paid' is written by OrderFinancialService:353.
        $this->assertFalse(
            $this->verifier->codShouldSettleOnDelivery($this->codOrder('paid')),
            'the already-paid guard must not be defeated by letter case'
        );
    }

    /** A non-COD order never settles through the COD transition. */
    public function test_a_non_cod_order_never_settles_on_delivery(): void
    {
        config(['services.cod.settlement' => PaymentVerificationService::COD_SETTLEMENT_ON_DELIVERY]);

        foreach (['stripe', 'paymera', 'bank_transfer', 'whatsapp', '', null] as $type) {
            $order = new Order();
            $order->forceFill(['payment_type' => $type, 'payment_status' => 'Pending']);

            $this->assertFalse(
                $this->verifier->codShouldSettleOnDelivery($order),
                'only cash on delivery may settle on delivery'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Robustness
    |--------------------------------------------------------------------------
    */

    /**
     * A typo in the config falls back to the default rather than failing closed.
     *
     * Failing closed here would mean a misconfigured key stops a shop recording
     * orders correctly, which is worse than the thing being guarded.
     */
    public function test_an_unrecognised_policy_falls_back_to_the_default(): void
    {
        config(['services.cod.settlement' => 'on-delivery-ish']);

        $this->assertSame(
            PaymentVerificationService::COD_SETTLEMENT_AT_ORDER,
            $this->verifier->codSettlementPolicy()
        );
        $this->assertSame(
            PaymentVerificationService::STATUS_PAID,
            $this->verifier->resolveOrderPaymentStatus('cod', null, '100.00', __METHOD__)
        );
    }

    /** The policy value is read case- and whitespace-insensitively. */
    public function test_the_policy_value_tolerates_case_and_whitespace(): void
    {
        config(['services.cod.settlement' => '  DELIVERY  ']);

        $this->assertSame(
            PaymentVerificationService::COD_SETTLEMENT_ON_DELIVERY,
            $this->verifier->codSettlementPolicy()
        );
    }

    /** The spellings are recognised, and nothing else is. */
    public function test_cod_spellings_are_recognised_and_others_are_not(): void
    {
        foreach (PaymentVerificationService::COD_PAYMENT_TYPES as $type) {
            $this->assertTrue($this->verifier->isCashOnDelivery($type));
            $this->assertTrue($this->verifier->isCashOnDelivery(strtoupper($type)));
        }

        foreach (['cods', 'cash-on-delivery', 'bank_transfer', 'stripe', '', null] as $type) {
            $this->assertFalse(
                $this->verifier->isCashOnDelivery($type),
                var_export($type, true) . ' must not be treated as cash on delivery'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The controller half -- structural, and why
    |--------------------------------------------------------------------------
    */

    /**
     * The settlement sits in order_status_change(), is gated on BOTH 'delivered'
     * and the policy helper, and fires the OrderPaymentStatus event.
     *
     * STRUCTURAL, and the limit is stated rather than implied: driving
     * order_status_change() behaviourally needs an order, a store, a store owner,
     * a mail template and an SMS gateway, because the method sends an email and
     * an SMS before it returns. This suite does not have that fixture and does
     * not pretend to. What is asserted is what a later edit would most easily
     * break: that the transition is gated on the policy helper (so it stays
     * inert by default) and that it fires the event the wallet and notification
     * listeners hang off, rather than writing payment_status silently.
     *
     * Comments are stripped before matching, so a comment cannot satisfy or
     * defeat any assertion here.
     */
    public function test_the_delivery_settlement_lives_in_the_status_change_writer(): void
    {
        $m = new \ReflectionMethod(OrderController::class, 'order_status_change');

        $source = implode('', array_slice(
            file($m->getFileName()),
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1
        ));

        $code = '';

        foreach (token_get_all('<?php ' . $source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        $this->assertStringContainsString(
            'codShouldSettleOnDelivery(',
            $code,
            'the settlement must be gated on the policy helper, so it stays inert by default'
        );
        $this->assertStringContainsString(
            "'delivered'",
            $code,
            'and gated on the delivered status specifically'
        );
        $this->assertStringContainsString(
            'OrderPaymentStatus(',
            $code,
            'the wallet and notification listeners hang off this event; writing '
                . 'payment_status without firing it would bypass them'
        );

        $gate = strpos($code, 'codShouldSettleOnDelivery(');
        $write = strpos($code, 'payment_status =');

        $this->assertNotFalse($write, 'the payment_status write must be present');
        $this->assertLessThan(
            $write,
            $gate,
            'the write must be INSIDE the gate, not before it'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Providers / fixture
    |--------------------------------------------------------------------------
    */

    public static function codSpellings(): array
    {
        return [
            'cod' => ['cod'],
            'cash_on_delivery' => ['cash_on_delivery'],
            'cash' => ['cash'],
            'upper case' => ['COD'],
            'padded' => ['  cod  '],
        ];
    }

    public static function otherOfflineTypes(): array
    {
        return [
            'bank_transfer' => ['bank_transfer'],
            'whatsapp' => ['whatsapp'],
        ];
    }

    /** An unsaved Order; nothing here needs a database. */
    private function codOrder(string $paymentStatus): Order
    {
        $order = new Order();
        $order->forceFill([
            'id' => 1,
            'payment_type' => 'cod',
            'payment_status' => $paymentStatus,
            'store_id' => 11,
        ]);

        return $order;
    }
}
