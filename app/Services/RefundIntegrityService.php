<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * What a "refund" in this platform actually is, and what it is not.
 *
 * NEW-refund-no-gateway-call
 * --------------------------
 * `OrderRefundController::RefundAmonut()` does this and nothing else:
 *
 *     $order_refund->update(['refund_status' => 'Refunded', 'refund_amount' => $amount]);
 *     return response()->json(['status' => 'Refunded', 'message' => 'Refund Amount successfully!']);
 *
 * There is no gateway call anywhere in the refund path — not in that method,
 * not in OrderRefundController at all, and no refund driver exists in
 * PaymentService's 52 payment arms. So the merchant is told "Refund Amount
 * successfully!", the order reads 'Refunded', and NO MONEY HAS MOVED. The
 * customer's card is never credited. The record asserts a settlement that did
 * not happen.
 *
 * A second, independent defect in the same method: the amount is taken raw
 * from the request —
 *
 *     $amount = (float) preg_replace('/[^0-9.]/', '', $request->refund_amount);
 *
 * and is never compared against what the order was for, so a refund larger
 * than the order can be recorded.
 *
 * WHAT THIS SERVICE DOES, AND WHY IT IS NOT A SECOND PAYMENT SYSTEM
 * -----------------------------------------------------------------
 * It introduces NO gateway client, no credential, no endpoint and no refund
 * driver. Inventing any of those would mean inventing Paymera's (or Stripe's,
 * or PayU's) refund API from memory, which is exactly the kind of guess that
 * must not be made about money. It is one seam that answers two questions a
 * refund writer must ask before it stores anything:
 *
 *   1. {@see cappedRefundAmount()} — how much may be recorded? Implementable
 *      now, and implemented: never more than the order total, never negative.
 *
 *   2. {@see resolveRefundStatus()} — may this be recorded as SETTLED?
 *      Only when the money genuinely moved outside this platform.
 *
 * THE LINE THIS DRAWS, which is the whole point
 * ---------------------------------------------
 * For an OFFLINE payment type — cash on delivery, bank transfer, the WhatsApp
 * flow — the merchant hands the money back by hand, out of band. 'Refunded' is
 * then a TRUE statement about the world and is preserved exactly.
 *
 * For an ONLINE gateway payment, nothing happened. Recording 'Refunded' is a
 * false statement, so this returns 'Accept' instead — which is an EXISTING
 * refund status in this codebase (OrderRefundController::updateRefundStatus
 * writes it, and the views already render it), meaning approved but not
 * settled. No new status value is introduced, so nothing downstream has to
 * learn one. The merchant still sees the refund approved; they are simply no
 * longer told that a credit was issued when none was.
 *
 * That is the fail-closed posture: the absence of a gateway refund is made
 * explicit in the stored record instead of being papered over by a status that
 * implies money moved.
 *
 * @see gatewayRequirements() for exactly what each gateway would need before
 *      any of this could become a real refund. That is an owner gate, and it is
 *      recorded rather than guessed.
 */
class RefundIntegrityService
{
    /** Money moved. Only legitimate for an out-of-band settlement. */
    public const STATUS_SETTLED = 'Refunded';

    /**
     * Approved, not settled.
     *
     * An existing value, written today by
     * OrderRefundController::updateRefundStatus(), so the views and the
     * datatable already handle it.
     */
    public const STATUS_APPROVED_NOT_SETTLED = 'Accept';

    /**
     * Payment types the merchant settles by hand, for which 'Refunded' is a
     * true statement without any gateway call.
     *
     * The same list as PaymentVerificationService::OFFLINE_PAYMENT_TYPES, for
     * the same reason, plus the spellings the COD path accepts.
     */
    public const OFFLINE_PAYMENT_TYPES = [
        'cod',
        'cash_on_delivery',
        'cash',
        'bank_transfer',
        'whatsapp',
        'telegram',
        /*
         * 'pos' AND 'lottery product' follow the same two entries in
         * PaymentVerificationService::OFFLINE_PAYMENT_TYPES, and the coupling is asserted by
         * RefundIntegrityTest::test_the_offline_list_agrees_with_the_payment_verification_seam --
         * which is what caught this when only the payment list was updated.
         *
         * The reason is the same in both directions and is the assertion's own message: a type
         * settled off-platform for PAYMENT is settled off-platform for REFUND too. A POS refund is
         * cash handed back at the counter and a lottery settlement is the module's own; neither has
         * a gateway to call, so 'Refunded' is a true statement without one.
         */
        'pos',
        'lottery product',
    ];

    /**
     * Whether a gateway refund driver exists for a payment type.
     *
     * Hardcoded false, and deliberately a method rather than a constant: it is
     * the single place that becomes a real lookup on the day a refund driver is
     * written, and until then it states the truth plainly instead of leaving
     * callers to assume.
     */
    public function hasGatewayRefundDriver(?string $paymentType): bool
    {
        return false;
    }

    /**
     * The refund status that may honestly be stored.
     *
     * @param  mixed  $paymentType  The order's payment type.
     * @param  mixed  $amount       The amount being recorded, for the log.
     * @param  string $site         A `Class::method` marker for the log.
     */
    public function resolveRefundStatus($paymentType, $amount, string $site): string
    {
        $type = strtolower(trim((string) $paymentType));

        if (in_array($type, self::OFFLINE_PAYMENT_TYPES, true)) {
            // The merchant hands the cash back themselves. Nothing to call,
            // and 'Refunded' is accurate. Behaviour preserved exactly.
            return self::STATUS_SETTLED;
        }

        if ($this->hasGatewayRefundDriver($type)) {
            // Unreachable today. Left here so that adding a driver does not
            // also require finding this branch.
            return self::STATUS_SETTLED;
        }

        Log::warning('Refund recorded as approved but NOT settled: no gateway refund was made', [
            'site' => $site,
            'payment_type' => $type !== '' ? $type : null,
            'amount' => (string) $amount,
            'stored_status' => self::STATUS_APPROVED_NOT_SETTLED,
            'reason' => 'There is no refund driver for this payment type anywhere in the '
                . 'platform, so no money has moved. Recording "Refunded" would assert a '
                . 'settlement that did not happen.',
            'owner_action' => 'See RefundIntegrityService::gatewayRequirements().',
        ]);

        return self::STATUS_APPROVED_NOT_SETTLED;
    }

    /**
     * Whether a stored status would be claiming that money moved.
     *
     * For a caller that wants to branch rather than take the status.
     */
    public function mayRecordAsSettled($paymentType): bool
    {
        return $this->resolveRefundStatus($paymentType, 0, __METHOD__) === self::STATUS_SETTLED;
    }

    /**
     * The amount that may be recorded, capped at what the order was for.
     *
     * The request value is never trusted: `RefundAmonut()` takes it raw and
     * compares it against nothing, so a refund larger than the order could be
     * stored. Negative and non-numeric values collapse to 0 rather than
     * becoming a charge.
     *
     * @param  mixed $requested  The amount asked for.
     * @param  mixed $orderTotal The order's final_price.
     * @return array{amount: float, capped: bool, requested: float, limit: float}
     */
    public function cappedRefundAmount($requested, $orderTotal, string $site = __CLASS__): array
    {
        $requestedAmount = $this->toAmount($requested);
        $limit = $this->toAmount($orderTotal);

        $amount = $requestedAmount;
        $capped = false;

        if ($amount > $limit) {
            $amount = $limit;
            $capped = true;

            Log::error('Refund amount exceeded the order total and was capped', [
                'site' => $site,
                'requested' => (string) $requestedAmount,
                'order_total' => (string) $limit,
            ]);
        }

        return [
            'amount' => $amount,
            'capped' => $capped,
            'requested' => $requestedAmount,
            'limit' => $limit,
        ];
    }

    /**
     * Coerce to a non-negative amount; anything unusable becomes 0.
     *
     * The controller normalises with `preg_replace('/[^0-9.]/', '', ...)`,
     * which STRIPS A MINUS SIGN: '-500' becomes '500', so a request for a
     * negative amount is silently reinterpreted as a positive refund of the
     * same size. This deliberately does NOT reproduce that. A signed request is
     * ambiguous about what the caller meant, and the safe reading of an
     * ambiguous money instruction is zero, so a sign anywhere in the string
     * yields 0 and says so.
     *
     * This is the one place this service diverges from the controller's own
     * arithmetic, and it diverges in the refusing direction.
     */
    private function toAmount($value): float
    {
        if (is_string($value)) {
            if (str_contains($value, '-')) {
                Log::error('Refund amount request carried a sign and was refused', [
                    'note' => 'The controller regex strips a minus sign, which would turn a '
                        . 'negative request into a positive refund of the same size.',
                ]);

                return 0.0;
            }

            // Otherwise the controller's own normalisation, so the cap is
            // applied to the same number the controller would have stored.
            $value = preg_replace('/[^0-9.]/', '', $value);
        }

        if (!is_numeric($value)) {
            return 0.0;
        }

        $amount = (float) $value;

        return $amount > 0 ? $amount : 0.0;
    }

    /**
     * EXACTLY what each gateway would need before a refund could move money.
     *
     * Recorded, not guessed. No endpoint path, no parameter name and no
     * credential below is invented: each entry names the gateway's own
     * integration document as the source, because that is where the answer
     * genuinely is, and the gateways were not and must not be contacted to find
     * out. An item marked `in_repo` already exists in this codebase and is
     * named by config key, never by value.
     *
     * This is the owner gate for the refund limb, in machine-readable form so a
     * later driver can be checked against it rather than against prose.
     *
     * @return array<string, array<string, mixed>>
     */
    public function gatewayRequirements(): array
    {
        return [
            'paymera' => [
                'status' => 'blocked_on_owner',
                'needs' => [
                    'refund endpoint URL (no refund endpoint appears anywhere in this repo)',
                    'request field names for the original payment reference and the amount',
                    'the amount unit — the capture path sends the total through '
                        . "str_replace([' ', ',', '.'], ''), so 1250.50 goes out as 125050 "
                        . 'while 1250 goes out as 1250; the refund unit is unresolved for the '
                        . 'same reason',
                    'an authenticated credential',
                ],
                'in_repo' => [
                    'config key services.paymera.username (read by '
                        . 'PaymentController::pollPaymentStatus; NOT currently defined anywhere '
                        . 'under config/)',
                ],
                'source' => "Paymera's own integration document",
                'note' => 'The only gateway with any server-to-server call in this platform, '
                    . 'and even that one is a status poll, not a refund.',
            ],
            'all_other_gateways' => [
                'status' => 'blocked_on_owner',
                'applies_to' => 'the remaining payment arms in PaymentService::process()',
                'needs' => [
                    'whether each gateway is contractually in use at all, before any refund '
                        . 'driver is written for it',
                    'per gateway: refund endpoint, request/response field names, amount unit, '
                        . 'idempotency key semantics, and partial-refund support',
                    'what the platform should do when a gateway accepts a refund '
                        . 'asynchronously, since there is no refund webhook route in routes/',
                ],
                'source' => 'each gateway\'s own integration document',
            ],
            'offline' => [
                'status' => 'no_gateway_needed',
                'applies_to' => self::OFFLINE_PAYMENT_TYPES,
                'note' => 'The merchant returns the money by hand, so "Refunded" is already a '
                    . 'true statement and this path is preserved unchanged.',
            ],
        ];
    }
}
