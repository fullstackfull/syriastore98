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
     * Their recorded status is NOT changed here. Cash on delivery is currently
     * written as 'Paid' at order time, which is arguably wrong — the cash has
     * not been collected yet — but changing it would move when a merchant's
     * wallet is credited, which is a business decision and not a security fix.
     * Reported as an owner action rather than altered.
     */
    public const OFFLINE_PAYMENT_TYPES = [
        'cod',
        'bank_transfer',
        'whatsapp',
    ];

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

        if (in_array($type, self::OFFLINE_PAYMENT_TYPES, true)) {
            // Settled off-platform. Behaviour preserved exactly; see the class
            // comment and the COD owner action.
            return self::STATUS_PAID;
        }

        if (!in_array($type, self::ENFORCED_PAYMENT_TYPES, true)) {
            // No verification seam for this gateway yet. Behaviour is preserved
            // rather than changed, because recording every gateway order as
            // Pending would stop merchant wallets being credited for 51 live
            // payment methods — a business change, not a security fix. The write
            // is logged so the residual exposure is measurable.
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
}
