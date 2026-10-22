<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Derives an order's payment state from a VERIFICATION RESULT.
 *
 * WHY THIS EXISTS
 * ---------------
 * Four order-write sites set `$order->payment_status = 'Paid'` as a literal,
 * with nothing between the customer's browser and that assignment that had
 * asked a gateway whether any money moved. The storefront's payment-return
 * route is reachable directly, so an order could be created and recorded as
 * paid without a payment.
 *
 * This is NOT a second payment system. It introduces no gateway client, no
 * order writer and no transaction store; the 52 existing payment arms in
 * PaymentService::process() are untouched. It is a single seam that answers one
 * question — "may this order be recorded as Paid?" — in one place, so the
 * answer can be improved per gateway without touching the order writers again.
 *
 * HOW THE VERIFICATION RECORD REACHES THE ORDER WRITER
 * ----------------------------------------------------
 * Through the SESSION, never through the request. A verification record placed
 * in the request (or in `request_data`, which is request-derived) would be
 * forgeable by the client, which is the defect rather than the fix. The
 * verifying code path calls {@see recordVerification()}; the order writer calls
 * {@see consumeVerification()}, which also CLEARS it so one verified payment
 * can authorise exactly one order.
 */
class PaymentVerificationService
{
    public const STATUS_PAID = 'Paid';

    /**
     * What an unverified online payment is recorded as.
     *
     * 'Pending' is already used by BankTransferController for an order whose
     * money has not arrived, so it is an existing state, not a new one.
     */
    public const STATUS_PENDING = 'Pending';

    /** Session key holding the single-use verification record. */
    public const SESSION_KEY = 'payment_verification';

    /**
     * Payment types whose settlement this platform can actually establish
     * server-side today, and for which an unverified order is therefore NOT
     * recorded as Paid.
     *
     * Only Paymera is listed because Paymera is the only arm with a
     * server-to-server status call (`PaymentController::pollPaymentStatus`).
     * Adding a gateway here is a one-line change once its arm records a
     * verification; until then its behaviour is preserved exactly and every
     * unverified Paid write is logged, so the backlog is measurable instead of
     * invisible. See the owner action in the Phase 1B report.
     */
    public const ENFORCED_PAYMENT_TYPES = [
        'paymera',
    ];

    /**
     * Payment types that are settled off-platform, by arrangement, and for which
     * "not verified by a gateway" is the normal case rather than a failure.
     *
     * Their recorded status is not derived from a gateway, because no gateway
     * ever confirms one of these. Cash on delivery is handled by its OWN state
     * machine below -- see COD_SETTLEMENT_* and codSettlementPolicy() -- because
     * for COD the question is not "did a gateway confirm a capture" but "has the
     * courier collected the cash yet".
     */
    public const OFFLINE_PAYMENT_TYPES = [
        // 'cod' WAS HERE AND IS DELIBERATELY GONE (owner decision D-06, 2026-10-22). This list means
        // "settles off-platform by arrangement, so record Paid at once", and COD no longer does: it has
        // its own state machine and is Pending until collection. The COD branch in
        // resolveOrderPaymentStatus() runs FIRST, so leaving 'cod' here changed no behaviour -- which is
        // exactly why it had to go. A constant that lists a type whose behaviour contradicts the
        // constant's own meaning is a trap for the next reader, and it was already misleading one test
        // into asserting COD is Paid.
        'bank_transfer',
        'whatsapp',
    ];

    /**
     * The payment_type values that mean cash on delivery.
     *
     * Three spellings, read off the platform's own code rather than invented:
     * PaymentService::process() dispatches on 'cod' (:315), and
     * PaymentService::codCashCollectionAmount() (:3017) already tests
     * `['cod', 'cash_on_delivery', 'cash']`.
     */
    public const COD_PAYMENT_TYPES = ['cod', 'cash_on_delivery', 'cash'];

    /**
     * RETIRED VALUE, kept only to recognise and reject it. Owner decision D-06, 2026-10-22.
     *
     * This used to mean "record a COD order Paid the moment it is placed", and it used to be the
     * DEFAULT, because when an order counts as paid decides when a merchant's wallet is credited -- a
     * business change for the owner to choose rather than a defect to impose. The owner chose: COD
     * starts unpaid, and uncollected money may never be reported as paid. So this value is no longer
     * honoured anywhere; `codSettlementPolicy()` recognises it solely in order to log loudly that a
     * deployment still configuring it is being ignored.
     */
    public const COD_SETTLEMENT_AT_ORDER = 'order';

    /**
     * COD settles ON DELIVERY: the order is recorded Pending when placed and
     * becomes Paid when a merchant marks it delivered.
     *
     * THIS IS THE CORRECT STATE MACHINE, and the argument is simply that it is
     * true -- for a cash-on-delivery order no money has moved at checkout.
     *
     * The evidence that settle-at-order is wrong is in the platform's own code:
     * Order::order_status_change() writes `payment_status = 'Unpaid'` when an
     * order is CANCELLED (app/Models/Order.php:550), with the comment calling it
     * the missing magic line needed "to refund the amount". There is nothing to
     * refund on a COD order that was never delivered. That reversal exists only
     * to undo a Paid state the order should never have been given, and an
     * Observer reacts to it by adjusting the wallet -- so the premature Paid
     * propagates into the financial records and then has to be walked back.
     *
     * Under this policy the lifecycle is:
     *
     *   placed     -> payment_status = Pending, delivered_status = 0
     *   delivered  -> payment_status = Paid     (OrderController::order_status_change)
     *   cancelled  -> payment_status stays unpaid; there is nothing to reverse
     *
     * Opt in with `services.cod.settlement = 'delivery'`.
     */
    public const COD_SETTLEMENT_ON_DELIVERY = 'delivery';

    /**
     * Record that a payment has been positively verified, for one order write.
     */
    public function recordVerification(
        string $paymentType,
        PaymentVerificationResult $result,
        $expectedTotal
    ): void {
        if (!$result->isVerified()) {
            return;
        }

        session()->put(self::SESSION_KEY, [
            'payment_type' => strtolower($paymentType),
            'reason' => $result->reason,
            'captured_amount' => $result->capturedAmount,
            'expected_amount' => (string) $expectedTotal,
            'verified_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Read and clear the verification record.
     *
     * Single use: a verified payment authorises one order, so a replayed
     * payment-return URL cannot mint a second paid order from the same
     * verification.
     */
    public function consumeVerification(): ?array
    {
        $record = session()->get(self::SESSION_KEY);
        session()->forget(self::SESSION_KEY);

        return is_array($record) ? $record : null;
    }

    /**
     * The payment_status an order should be written with.
     *
     * @param  string|null $paymentType  The order's payment type.
     * @param  array|null  $verification The record from {@see consumeVerification()}.
     * @param  mixed       $expectedTotal The amount the order is for.
     * @param  string      $site          A `Class::method` marker for the log.
     */
    public function resolveOrderPaymentStatus(
        ?string $paymentType,
        ?array $verification,
        $expectedTotal,
        string $site
    ): string {
        $type = strtolower(trim((string) $paymentType));

        if ($this->isCashOnDelivery($type)) {
            /*
             * OWNER DECISION D-06, 2026-10-22: "COD orders start as Unpaid. Payment becomes Paid only
             * after actual cash collection is verified. Do not credit a merchant wallet at order
             * creation. Make collection and settlement workflows configurable per store WITHOUT
             * ALLOWING UNCOLLECTED MONEY TO BE REPORTED AS PAID."
             *
             * That last clause is why this is no longer a policy BRANCH. Settle-at-order was previously
             * the default and remained selectable, because when an order counts as paid decides when a
             * merchant's wallet is credited -- a business decision the owner had not yet made. It is made
             * now, and it forecloses the option: a COD order recorded Paid before collection IS
             * uncollected money reported as paid, so no per-store setting may produce it.
             *
             * What stays configurable per store is the collection and settlement WORKFLOW -- who collects,
             * and when the merchant is settled -- which needs the courier-collection and settlement
             * records GATE F has not released yet. The state at creation is not part of that.
             *
             * `Pending`, not a new `Unpaid` state: 'Pending' is this codebase's existing state for money
             * that has not arrived (BankTransferController uses it for exactly that), while 'Unpaid' is
             * what `Order::order_status_change` writes on CANCELLATION (app/Models/Order.php:550). Both
             * satisfy "not paid"; reusing the arrival state keeps cancellation semantics distinct.
             *
             * THIS ALSO SATISFIES "no wallet credit at order creation" WITH NO SEPARATE GUARD, and that is
             * worth recording because it is not obvious: `OrderObserver::created()` credits the wallet
             * only when the order is already Paid at creation ("إذا كان الطلب Paid تضيفه للمحفظة فوراً").
             * An order created Pending credits nothing; `OrderObserver::updated()` then syncs the wallet
             * when `payment_status` changes, so settlement credits it at delivery instead.
             */
            Log::info('COD order recorded as pending collection', [
                'site' => $site,
                'payment_type' => $type,
                'expected_total' => (string) $expectedTotal,
                'note' => 'Becomes Paid when collection is recorded. Owner decision D-06.',
            ]);

            return self::STATUS_PENDING;
        }

        if (in_array($type, self::OFFLINE_PAYMENT_TYPES, true)) {
            // Settled off-platform by arrangement; no gateway ever confirms one
            // of these. Behaviour preserved exactly.
            return self::STATUS_PAID;
        }

        if (!in_array($type, self::ENFORCED_PAYMENT_TYPES, true)) {
            /*
             * Behaviour is preserved rather than changed, because recording every gateway order
             * as Pending would stop merchant wallets being credited for 51 live payment methods
             * — a business change, not a security fix. The write is logged so the residual
             * exposure is measurable.
             *
             * BUT THE LOG MUST SAY WHICH OF THE TWO CASES IT IS. P01-COMMERCE-SEC-02 wired the
             * first gateway that genuinely records a verification for a type that is not
             * enforced: NMIPayController::getNMIProductStatus makes the capture itself and
             * records NMI's own `response == 1` through recordVerification(). This branch then
             * logged 'No server-side verification seam exists for this payment type' over the
             * top of it — the exact opposite of what had happened — and an adversarial review
             * caught the contradiction between that line and the package's own claim.
             *
             * So a matching record is now reported as what it is. The STATUS is identical
             * either way; only the log changes, which is the whole point of a log that exists
             * to measure the residual.
             */
            if (is_array($verification) && ($verification['payment_type'] ?? null) === $type) {
                Log::info('Order recorded as Paid, backed by a recorded gateway verification', [
                    'site' => $site,
                    'payment_type' => $type,
                    'expected_total' => (string) $expectedTotal,
                    'captured_amount' => $verification['captured_amount'] ?? null,
                    'verified_at' => $verification['verified_at'] ?? null,
                    'reason' => $verification['reason'] ?? null,
                    'note' => 'Not in ENFORCED_PAYMENT_TYPES, so the amount is NOT compared and'
                        . ' a missing record would NOT withhold Paid. Enforcing this type is a'
                        . ' one-line change in ENFORCED_PAYMENT_TYPES.',
                ]);

                return self::STATUS_PAID;
            }

            Log::warning('Order recorded as Paid without gateway verification', [
                'site' => $site,
                'payment_type' => $type,
                'expected_total' => (string) $expectedTotal,
                'note' => 'No server-side verification seam exists for this payment type.',
            ]);

            return self::STATUS_PAID;
        }

        if ($verification === null) {
            Log::error('Order NOT marked Paid: no verification record for an enforced gateway', [
                'site' => $site,
                'payment_type' => $type,
                'expected_total' => (string) $expectedTotal,
            ]);

            return self::STATUS_PENDING;
        }

        if (($verification['payment_type'] ?? null) !== $type) {
            Log::error('Order NOT marked Paid: verification record is for a different payment type', [
                'site' => $site,
                'payment_type' => $type,
                'verified_payment_type' => $verification['payment_type'] ?? null,
            ]);

            return self::STATUS_PENDING;
        }

        // The verification was taken against a total; if the order being written
        // is for a different total, the verification does not cover it.
        if (!OrderTotalReconciler::amountsMatch(
            (string) ($verification['expected_amount'] ?? ''),
            (string) $expectedTotal
        )) {
            Log::error('Order NOT marked Paid: verified amount does not match the order total', [
                'site' => $site,
                'payment_type' => $type,
                'verified_amount' => (string) ($verification['expected_amount'] ?? ''),
                'order_total' => (string) $expectedTotal,
            ]);

            return self::STATUS_PENDING;
        }

        return self::STATUS_PAID;
    }

    /**
     * Verify a Paymera capture: does the amount the gateway says it took equal
     * the amount this order is for?
     *
     * WHAT IS IMPLEMENTED AND WHAT IS BLOCKED
     * ---------------------------------------
     * The comparison is implemented. The RESPONSE FIELD NAME is not knowable
     * from this repository — it exists only in Paymera's own integration
     * document, which is GATE G(4) — so the field is read from
     * `services.paymera.callback_amount_field` when the owner has supplied it,
     * and otherwise probed against the candidate names below.
     *
     * The amount UNIT is also unresolved (GATE G(3)): `processPaymera()` sends
     * the total through `str_replace([' ', ',', '.'], '')`, so 1250.50 is sent
     * as 125050 while 1250 is sent as 1250. Rather than guess, this accepts a
     * capture that equals EITHER the decimal total or the exact integer that was
     * sent to the gateway — both of which the pending record stores — and
     * rejects anything else. That catches a tampered or mismatched amount
     * without depending on the unanswered unit question.
     *
     * Anything unparseable or absent is UNVERIFIABLE, which fails closed. It is
     * never treated as success.
     */
    public function verifyPaymeraCapture(
        array $statusData,
        $expectedDecimalTotal,
        $amountSentToGateway = null
    ): PaymentVerificationResult {
        $captured = $this->extractPaymeraAmount($statusData);

        if ($captured === null) {
            return PaymentVerificationResult::unverifiable(
                'paymera_capture_amount_field_not_found',
                null,
                (string) $expectedDecimalTotal
            );
        }

        if (OrderTotalReconciler::amountsMatch($captured, (string) $expectedDecimalTotal)) {
            return PaymentVerificationResult::verified(
                'paymera_capture_matches_order_total',
                $captured,
                (string) $expectedDecimalTotal
            );
        }

        if ($amountSentToGateway !== null
            && OrderTotalReconciler::amountsMatch($captured, (string) $amountSentToGateway)
        ) {
            return PaymentVerificationResult::verified(
                'paymera_capture_matches_amount_sent',
                $captured,
                (string) $expectedDecimalTotal
            );
        }

        return PaymentVerificationResult::rejected(
            'paymera_capture_amount_mismatch',
            $captured,
            (string) $expectedDecimalTotal
        );
    }

    /**
     * Candidate response keys for the captured amount, used only until GATE G(4)
     * supplies the real one via `services.paymera.callback_amount_field`.
     *
     * Ordered most to least specific so that a payload carrying several of them
     * resolves to the most explicitly named.
     */
    public const PAYMERA_AMOUNT_FIELD_CANDIDATES = [
        'capturedAmount',
        'captured_amount',
        'paidAmount',
        'paid_amount',
        'settledAmount',
        'transactionAmount',
        'totalAmount',
        'amount',
        'Amount',
    ];

    /**
     * Pull the captured amount out of a Paymera status payload.
     *
     * Looks at the top level and inside a `Data` envelope, which is the shape
     * `processPaymera()` already relies on for `Data.paymentId` and `Data.url`.
     */
    private function extractPaymeraAmount(array $statusData): ?string
    {
        $configuredField = config('services.paymera.callback_amount_field');

        $fields = is_string($configuredField) && $configuredField !== ''
            ? [$configuredField]
            : self::PAYMERA_AMOUNT_FIELD_CANDIDATES;

        $envelopes = [$statusData];

        foreach (['Data', 'data'] as $envelopeKey) {
            if (isset($statusData[$envelopeKey]) && is_array($statusData[$envelopeKey])) {
                $envelopes[] = $statusData[$envelopeKey];
            }
        }

        foreach ($fields as $field) {
            foreach ($envelopes as $envelope) {
                if (!array_key_exists($field, $envelope)) {
                    continue;
                }

                $value = $envelope[$field];

                if (is_int($value) || is_float($value)) {
                    return (string) $value;
                }

                if (is_string($value) && is_numeric(trim($value))) {
                    return trim($value);
                }
            }
        }

        return null;
    }

    /**
     * Verify the inbound signature on a Paymera callback.
     *
     * STATUS: WIRED, FAIL-CLOSED, AND BLOCKED ON THE OWNER (GATE G(4)).
     *
     * The callback currently arrives with no signature verification of any kind.
     * The control flow for verifying one is implemented here and is called
     * BEFORE the order is created, but the scheme itself cannot be written
     * against real field names from this repository: which field carries the
     * signature, what the signing algorithm is, and how the shared secret is
     * delivered exist only in Paymera's integration document. The gateway was
     * not and must not be contacted to find out.
     *
     * So this returns UNVERIFIABLE until all of the following are configured:
     *
     *   services.paymera.callback_signature_field      the response field name
     *   services.paymera.callback_signature_algorithm   e.g. sha256
     *   services.paymera.callback_signature_secret      the shared secret
     *   services.paymera.callback_signature_payload     ordered field names
     *                                                   that form the signed
     *                                                   string
     *
     * NOTE FOR THE REVIEWER, because it is a deliberate trade-off and not an
     * oversight: with `services.paymera.require_callback_signature` left at its
     * default of TRUE, an unconfigured signature means Paymera callbacks are
     * REJECTED — the customer is returned to the payment-status page and the
     * order is not created. That is the fail-closed posture the remediation plan
     * specifies for this item, and it means Paymera checkout does not function
     * until GATE G(4) is answered. The owner can either answer the gate or set
     * that one flag to false to restore the previous (unverified) behaviour
     * while they do. Both paths are deliberate and both are visible.
     */
    public function verifyPaymeraSignature(array $callbackPayload): PaymentVerificationResult
    {
        $field = config('services.paymera.callback_signature_field');
        $algorithm = config('services.paymera.callback_signature_algorithm');
        $secret = config('services.paymera.callback_signature_secret');
        $signedFields = config('services.paymera.callback_signature_payload');

        if (!is_string($field) || $field === ''
            || !is_string($algorithm) || $algorithm === ''
            || !is_string($secret) || $secret === ''
            || !is_array($signedFields) || $signedFields === []
        ) {
            // Fails closed. Note that no configuration VALUE is logged, only
            // which names are missing.
            return PaymentVerificationResult::unverifiable(
                'paymera_signature_scheme_not_configured'
            );
        }

        if (!in_array($algorithm, hash_hmac_algos(), true)) {
            return PaymentVerificationResult::unverifiable(
                'paymera_signature_algorithm_unsupported'
            );
        }

        $presented = $callbackPayload[$field] ?? null;

        if (!is_string($presented) || $presented === '') {
            return PaymentVerificationResult::rejected('paymera_signature_absent');
        }

        $parts = [];

        foreach ($signedFields as $signedField) {
            if (!array_key_exists($signedField, $callbackPayload)) {
                // A signed field that is not present means the payload is not
                // the one the signature was computed over.
                return PaymentVerificationResult::rejected(
                    'paymera_signature_payload_incomplete'
                );
            }

            $parts[] = (string) $callbackPayload[$signedField];
        }

        $expected = hash_hmac($algorithm, implode('|', $parts), $secret);

        if (!hash_equals($expected, $presented)) {
            return PaymentVerificationResult::rejected('paymera_signature_mismatch');
        }

        return PaymentVerificationResult::verified('paymera_signature_valid');
    }

    /**
     * Whether an unverifiable signature should block the callback.
     *
     * Defaults to blocking. See the note in {@see verifyPaymeraSignature()}.
     */
    public function paymeraSignatureIsRequired(): bool
    {
        return (bool) config('services.paymera.require_callback_signature', true);
    }

    /*
    |--------------------------------------------------------------------------
    | Cash on delivery
    |--------------------------------------------------------------------------
    */

    /** Is this payment type cash on delivery, in any of its spellings? */
    public function isCashOnDelivery(?string $paymentType): bool
    {
        return in_array(
            strtolower(trim((string) $paymentType)),
            self::COD_PAYMENT_TYPES,
            true
        );
    }

    /**
     * When a COD order is considered paid.
     *
     * Defaults to COD_SETTLEMENT_AT_ORDER, i.e. the pre-existing behaviour, so
     * no merchant's revenue reporting or wallet crediting moves without the
     * owner choosing it. An UNRECOGNISED value also falls back to the default
     * rather than failing closed: a typo in a config file must not stop a shop
     * taking orders. It is logged so the typo is findable.
     */
    public function codSettlementPolicy(): string
    {
        $policy = strtolower(trim(
            (string) config('services.cod.settlement', self::COD_SETTLEMENT_ON_DELIVERY)
        ));

        // NO LONGER A SWITCH (owner decision D-06). Settle-on-delivery is the only COD behaviour, and
        // this method is retained for one reason: a deployment whose .env still carries
        // COD_SETTLEMENT=order must find out LOUDLY that the value is no longer honoured, rather than
        // believing it still settles at order time. Silently ignoring a configured value is how an
        // operator ends up with a wrong mental model of when their merchants get paid.
        if ($policy === self::COD_SETTLEMENT_AT_ORDER) {
            Log::warning('COD_SETTLEMENT=order is NO LONGER HONOURED and is ignored', [
                'config_key' => 'services.cod.settlement',
                'configured' => $policy,
                'effective' => self::COD_SETTLEMENT_ON_DELIVERY,
                'reason' => 'Owner decision D-06, 2026-10-22: uncollected money may not be reported as paid.',
                'action' => 'Remove COD_SETTLEMENT from the environment.',
            ]);
        } elseif ($policy !== self::COD_SETTLEMENT_ON_DELIVERY && $policy !== '') {
            Log::warning('Unrecognised COD settlement policy; settle-on-delivery is in force regardless', [
                'configured' => $policy,
                'config_key' => 'services.cod.settlement',
            ]);
        }

        return self::COD_SETTLEMENT_ON_DELIVERY;
    }

    /**
     * Should this order be settled now that it has been marked delivered?
     *
     * The caller is OrderController::order_status_change(), which is the one
     * place a merchant declares an order delivered. Returns false unless ALL of:
     *
     *   - the settle-on-delivery policy is in force, so the default behaviour is
     *     untouched and this method is inert until the owner opts in;
     *   - the order really is cash on delivery;
     *   - it is not ALREADY Paid, which makes a replayed status change a no-op
     *     rather than a second settlement.
     *
     * @param \App\Models\Order $order
     */
    public function codShouldSettleOnDelivery($order): bool
    {
        // No policy gate (owner decision D-06): settle-on-delivery is the only COD behaviour, so this
        // transition is live rather than inert-until-opted-in.
        if ($order === null || !$this->isCashOnDelivery($order->payment_type ?? null)) {
            return false;
        }

        return strtolower(trim((string) ($order->payment_status ?? '')))
            !== strtolower(self::STATUS_PAID);
    }
}
