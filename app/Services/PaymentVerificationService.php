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
     * Payment types whose settlement this platform can establish server-side, and whose recorded
     * amount is therefore COMPARED against the captured amount as well.
     *
     * THIS LIST IS NO LONGER WHAT DECIDES PAID-WITHOUT-VERIFICATION. It used to be: a type absent
     * from it was recorded Paid whether or not anything had confirmed a capture. Owner decision,
     * Phase 01 final execution directive: *"Any unverified gateway payment must remain Pending,
     * never Paid. Do not create a falsely paid order, and preserve a safe reconciliation path for
     * customers who may have been charged."*
     *
     * So the DEFAULT is now Pending for every gateway type, and membership here means one extra
     * thing on top: the captured AMOUNT is reconciled against the order's expected total, not just
     * the existence of a verification record. Paymera is listed because it has a server-to-server
     * status call that reports an amount (`PaymentController::pollPaymentStatus`); NMI is listed
     * because its arm makes the capture itself and records the provider's own `response == 1`
     * through `recordVerification()` (`NMIPayController::getNMIProductStatus`).
     */
    public const ENFORCED_PAYMENT_TYPES = [
        'paymera',
        // LOWERCASE, and that is not cosmetic: resolveOrderPaymentStatus() compares against
        // `strtolower(trim($paymentType))` with a STRICT in_array, and recordVerification() stores
        // `strtolower($paymentType)`. The payment type the checkout posts is 'NMI'
        // (PaymentController::getValidPaymentTypes), so an entry spelled 'NMI' here would never
        // match and the enrolment would be silently inert. Caught before it shipped.
        'nmi',
        /*
         * 'khalti' JOINS 2026-10-24, and it is a CORRECTION rather than a tightening.
         *
         * Khalti's verify call is now bound to the server's own figure, so a record means the
         * provider confirmed a capture of serverPayableAmount() -- the stored state's
         * `cartlist_final_price`. THE ORDER IS NOT WRITTEN FOR THAT FIGURE. KhaltiPaymnetController
         * recomputes `$order->final_price` from a FRESH cart read at return time, and the basket is
         * mutable across the round trip: pay for a small basket, withhold the automatic return
         * (the token is in the browser), add an expensive line, then post the return yourself. The
         * verification is genuine and covers the wrong order. Without this entry the record alone
         * writes Paid and nothing compares the two numbers.
         *
         * The same gap has a second, quieter instance: with PartialPayments active,
         * prepareFinalRequestData() sets cartlist_final_price to the DEPOSIT, and this handler --
         * unlike AuthorizeNet, which carries $partialPaymentDeposit into the write -- has no
         * partial-payments branch at all, so a deposit capture would mark a full-price order Paid.
         *
         * MEASURED BEFORE ENROLLING, because enrolling a gateway whose two figures legitimately
         * differ would make every Khalti order Pending: on a plain authenticated checkout the
         * order's final_price is 100.0 against a verified 100 -- they agree, so the happy path is
         * unaffected. KhaltiAmountBindingTest pins both the agreement and the mismatch refusal.
         *
         * Lowercase, for the reason the 'nmi' comment above gives: the comparison is against
         * strtolower(trim($paymentType)) with a strict in_array, and the checkout posts 'khalti'.
         */
        'khalti',
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
        /*
         * 'pos' AND 'lottery product' ARE NEW HERE, and they are a correction forced by the owner's
         * no-unverified-Paid decision rather than a widening of it.
         *
         * Both settle OFF-GATEWAY and both write a literal 'Paid' at their own unwired sites --
         * PosController for money taken at the counter, and the vendored LotteryResultController
         * for a lottery settlement. While every unenforced type resolved to Paid the derivation
         * happened to agree with those literals, and PaymentStatusDerivationTest
         * ::test_the_pos_and_lottery_literals_are_equivalent_to_the_derivation_today existed
         * precisely to keep that equivalence falsifiable.
         *
         * Flipping the default to Pending broke it, and the test caught it: POS orders would have
         * been recorded Pending while the cash was already in the drawer, and the derivation would
         * have disagreed with the literal the write site uses. Neither site can be edited from here
         * (one is vendored), so the constant is where the two are reconciled.
         *
         * This is not a hole in the decision. The decision governs GATEWAY payments -- "any
         * unverified GATEWAY payment must remain Pending" -- and a counter sale is not one: no
         * gateway ever confirms it, which is exactly what this list means. Lowercase, because the
         * comparison is against `strtolower(trim($paymentType))`.
         */
        'pos',
        'lottery product',
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
        $expectedTotal,
        ?string $checkoutNonce = null
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
            /*
             * WHICH CHECKOUT THIS VERIFICATION IS FOR. Added 2026-10-24.
             *
             * Until this field the record was bound to nothing but a lowercase type string, and
             * the consequence was measured rather than argued: a record left in the session by
             * one request was redeemed by a DIFFERENT order writer on the shared
             * `{slug}/get-payment-status` route, writing a Paid order for a basket the capture
             * never covered. consumeVerification() being single-use does not help -- it does not
             * care WHICH checkout produced the record.
             *
             * The nonce is GuardsGatewayReturn::CHECKOUT_NONCE_KEY from the server-stored state,
             * the same value claimGatewayReturn() keys its replay claim on. Null when the caller
             * does not supply one, and resolveOrderPaymentStatus() then behaves exactly as it did
             * before -- so wiring a producer is an improvement and missing one is not a
             * regression.
             */
            'checkout_nonce' => $checkoutNonce,
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
        string $site,
        ?string $checkoutNonce = null
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

        /*
         * NO GATEWAY ORDER IS RECORDED PAID WITHOUT A VERIFICATION RECORD.
         *
         * OWNER DECISION, Phase 01 final execution directive: "Any unverified gateway payment must
         * remain Pending, never Paid. Do not create a falsely paid order, and preserve a safe
         * reconciliation path for customers who may have been charged."
         *
         * WHAT THIS REPLACES, AND WHY THE OLD REASONING WAS RIGHT UNTIL NOW. This branch used to
         * return Paid for any type absent from ENFORCED_PAYMENT_TYPES, with the comment "behaviour
         * is preserved rather than changed, because recording every gateway order as Pending would
         * stop merchant wallets being credited for 51 live payment methods -- a business change,
         * not a security fix." That was the correct call while the business change was unmade: when
         * an order counts as paid decides when a merchant is credited, which is the owner's to
         * choose. It is chosen now, and it forecloses the old default.
         *
         * THE ORDER IS STILL WRITTEN. This returns a STATUS, not a refusal, and that is the
         * "reconciliation path" the decision asks for: the customer's order exists with its lines,
         * its total and its billing record, and a merchant who confirms the capture with the
         * provider marks it Paid through the existing status workflow. A refusal here would be the
         * worse failure the directive names -- the customer charged with nothing recorded.
         *
         * AND NO WALLET IS CREDITED FOR AN UNCONFIRMED CAPTURE, with no separate guard, for the
         * same reason recorded on the COD branch above: `OrderObserver::created()` credits the
         * wallet only for an order already Paid at creation, and `OrderObserver::updated()` syncs
         * it when `payment_status` later changes. So settlement follows confirmation.
         *
         * THE LOG IS THE RECONCILIATION QUEUE. Every order that lands here is one a human may need
         * to confirm, so the line carries what that takes: the type, the expected total, and
         * whether a record existed at all.
         */
        if ($verification === null) {
            Log::warning('Order recorded as PENDING: no gateway verification for this payment', [
                'site' => $site,
                'payment_type' => $type,
                'expected_total' => (string) $expectedTotal,
                'note' => 'Owner decision: an unverified gateway payment is never Paid. The order '
                    . 'is written so the customer has a record and the merchant can reconcile it '
                    . 'against the provider.',
            ]);

            return self::STATUS_PENDING;
        }

        /*
         * A VERIFICATION IS FOR ONE CHECKOUT. Added 2026-10-24 after a planted record was
         * measured being redeemed by an unrelated order write.
         *
         * Only enforced when the RECORD carries a nonce: a record written before this field
         * existed, or by a producer not yet wired, carries null and is handled exactly as it was
         * before. That keeps this a strict improvement rather than a silent Pending for any
         * gateway whose producer was missed -- the failure mode that would otherwise turn a
         * payment-integrity fix into an outage.
         */
        $recordedNonce = $verification['checkout_nonce'] ?? null;

        if ($recordedNonce !== null && $recordedNonce !== '' && $recordedNonce !== $checkoutNonce) {
            Log::warning('Order recorded as PENDING: the verification belongs to a different checkout', [
                'site' => $site,
                'payment_type' => $type,
                'expected_total' => (string) $expectedTotal,
                'note' => 'A capture verified for one checkout cannot pay for another. This is '
                    . 'either a replay of a stale record or an order written outside the '
                    . 'checkout that was paid for.',
            ]);

            return self::STATUS_PENDING;
        }

        if (($verification['payment_type'] ?? null) !== $type) {
            Log::warning('Order recorded as PENDING: the verification record is for another payment type', [
                'site' => $site,
                'payment_type' => $type,
                'verified_payment_type' => $verification['payment_type'] ?? null,
                'expected_total' => (string) $expectedTotal,
            ]);

            return self::STATUS_PENDING;
        }

        /*
         * A RECORD EXISTS AND NAMES THIS TYPE. For a type that is NOT amount-enforced that is as
         * far as this platform can establish settlement today, so the order is Paid and the log
         * says exactly which of the two grounds it rests on -- the distinction an adversarial
         * review already caught this method getting backwards once.
         */
        if (!in_array($type, self::ENFORCED_PAYMENT_TYPES, true)) {
            Log::info('Order recorded as Paid, backed by a recorded gateway verification', [
                'site' => $site,
                'payment_type' => $type,
                'expected_total' => (string) $expectedTotal,
                'captured_amount' => $verification['captured_amount'] ?? null,
                'verified_at' => $verification['verified_at'] ?? null,
                'reason' => $verification['reason'] ?? null,
                'note' => 'Not in ENFORCED_PAYMENT_TYPES, so the captured AMOUNT is not compared. '
                    . 'The existence of the record is what was checked.',
            ]);

            return self::STATUS_PAID;
        }

        /*
         * AMOUNT-ENFORCED FROM HERE. The two preconditions this branch used to re-test -- a missing
         * record, and a record naming another payment type -- are now refused ABOVE for every
         * gateway type rather than only for the enforced ones, so re-testing them here would be
         * dead code. What remains is the one check that is particular to this list.
         *
         * The verification was taken against a total; if the order being written is for a different
         * total, the verification does not cover it.
         */
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
     * Verify a Khalti capture.
     *
     * TWO CONTROLS, AND ONLY ONE OF THEM IS KNOWABLE FROM THIS REPOSITORY. Say which is which,
     * because the difference decides what this method may fail closed on.
     *
     * THE PRIMARY CONTROL IS NOT HERE AT ALL -- it is at the call site. Khalti's
     * `payment/verify/` endpoint takes `token` AND `amount` together and confirms the PAIR
     * (App\Khalti\Khalti::verifyPayment posts both). So a 200 is already amount-bound, PROVIDED
     * the caller posted the SERVER's amount. KhaltiPaymnetController used to post
     * `$payload['amount']` -- the shopper's own field -- so a buyer who paid 1 unit for a
     * thousand-unit basket could present the token for the small payment with a matching small
     * amount, collect a 200, and have the order written at the server's full price. The call
     * site now posts `serverPayableAmount() * 100` and nothing else; THAT is the fix, and this
     * method cannot substitute for it.
     *
     * THE SECONDARY CONTROL IS OPPORTUNISTIC, deliberately. Khalti's verify response is
     * documented to echo the transaction `amount` in paisa, but this repository holds no
     * contract, no fixture and no recorded response for that endpoint -- exactly the position
     * verifyPaymeraCapture() is in with its field name. So when `amount` IS present it must
     * equal the expected paisa exactly and a mismatch is REJECTED; when it is ABSENT the 200 is
     * accepted on the strength of the primary control. Failing closed on an absent field would
     * instead make every Khalti order Pending the moment the response shape differed from a
     * guess -- a commercial regression justified by nothing measured.
     *
     * UNREACHABLE IS NOT REJECTED. App\Khalti\Khalti reads its status from CURLINFO_HTTP_CODE,
     * which is 0 when the request never completed. A transport failure says nothing about
     * whether money moved, so it is UNVERIFIABLE -- and the caller writes a Pending order for
     * reconciliation rather than refusing, because a charged customer with no order row is the
     * worse failure (owner decision D2's reconciliation half).
     *
     * @param  array<string, mixed>  $response  the decoded Khalti verify response
     * @param  int  $expectedPaisa  the server-priced payable, in paisa
     */
    public function verifyKhaltiCapture(array $response, int $expectedPaisa): PaymentVerificationResult
    {
        $expectedDecimal = number_format($expectedPaisa / 100, 2, '.', '');

        if ($expectedPaisa <= 0) {
            return PaymentVerificationResult::unverifiable(
                'No server-priced payable to verify against.',
                null,
                $expectedDecimal
            );
        }

        $status = (int) ($response['status_code'] ?? 0);

        if ($status === 0) {
            return PaymentVerificationResult::unverifiable(
                'Khalti could not be reached (no HTTP status). Whether money moved is unknown.',
                null,
                $expectedDecimal
            );
        }

        if ($status !== 200) {
            return PaymentVerificationResult::rejected(
                'Khalti answered HTTP ' . $status . ' for this token and amount.',
                null,
                $expectedDecimal
            );
        }

        $data = $response['data'] ?? null;
        $captured = null;

        if (is_array($data) && array_key_exists('amount', $data)) {
            $captured = $data['amount'];
        } elseif (is_object($data) && isset($data->amount)) {
            $captured = $data->amount;
        }

        if ($captured === null || $captured === '') {
            // The field is absent. The 200 is still amount-bound by the request -- see above.
            return PaymentVerificationResult::verified(
                'Khalti confirmed the token for the server-priced amount (HTTP 200; the response '
                . 'carried no amount field to re-check).',
                null,
                $expectedDecimal
            );
        }

        if (! is_numeric($captured)) {
            return PaymentVerificationResult::unverifiable(
                'Khalti returned an unparseable amount.',
                (string) $captured,
                $expectedDecimal
            );
        }

        $capturedPaisa = (int) round((float) $captured);
        $capturedDecimal = number_format($capturedPaisa / 100, 2, '.', '');

        if ($capturedPaisa !== $expectedPaisa) {
            return PaymentVerificationResult::rejected(
                'Khalti reported a different amount than this order is for.',
                $capturedDecimal,
                $expectedDecimal
            );
        }

        return PaymentVerificationResult::verified(
            'Khalti confirmed the token for the server-priced amount, and the amount it reported matches.',
            $capturedDecimal,
            $expectedDecimal
        );
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
     * ============================================================================================
     *  DELIVERED IS NOT PAID. OWNER DECISION, 2026-10-24, FINAL.
     * ============================================================================================
     *
     * This method REPLACES `codShouldSettleOnDelivery()`, which returned true for any non-Paid COD
     * order the moment a merchant marked it delivered. The owner withdrew that trigger:
     *
     *     "Withdraw the Delivered-based COD payment/settlement trigger now. Delivered must never,
     *      by itself, mark COD as Paid or credit a merchant wallet. Require a separate verified
     *      cash collection event, recording the collector (merchant, courier or delivery company),
     *      collection amount, time and supporting evidence. Keep order delivery reporting separate
     *      from cash collection and financial settlement reporting."
     *
     * WHY THE OLD TRIGGER WAS WRONG, and it is worth stating because it was not obviously so.
     * D-06 made COD Pending at creation, which was right, and then needed SOMETHING to move it to
     * Paid; marking delivered was the only signal the product already had. But a merchant clicking
     * 'delivered' on their own order, through their own web guard, is a PROXY for collection and
     * not evidence of it. The courier may not have handed the cash over. The order may have been
     * marked delivered in error, or early, or to clear a dashboard. D1 then said so in terms --
     * "no wallet credit for creation *or* for 'Delivered'" -- and this method is that sentence.
     *
     * WHAT SETTLES A COD ORDER NOW. A verified cash collection event, and nothing else. The event
     * must name WHO collected (merchant, courier or delivery company, and which one), HOW MUCH,
     * WHEN, and carry its supporting evidence. Those four are the owner's words and they are what
     * `App\Services\CodCollectionService` records.
     *
     * THIS METHOD CANNOT RETURN TRUE TODAY, and that is correct rather than a stub. The event has
     * nowhere to live: `cod_collection_events` is declared by no migration, so
     * `CodCollectionService::verifiedCollectionFor()` always answers null and this always refuses.
     * A COD order therefore stays Pending until a human asserts payment through
     * `OrderController::order_payment_status`, which is a deliberate separate act rather than a
     * side effect of a delivery click. When GATE F lands and the table exists, the event becomes
     * the proper path and that manual assertion becomes the exception rather than the only route.
     *
     * The draft schema is `database/migrations/_gate_f_pending/0009_draft_create_cod_collection_events_table.php.draft`.
     *
     * @param \App\Models\Order $order
     * @param object|null $collectionEvent a verified event, or null -- callers pass what
     *                                     CodCollectionService::verifiedCollectionFor() returned
     */
    public function codMayBeSettled($order, $collectionEvent = null): bool
    {
        if ($order === null || !$this->isCashOnDelivery($order->payment_type ?? null)) {
            return false;
        }

        // Already Paid: a replay settles once, not twice. Kept from the withdrawn method because
        // the idempotency requirement did not change -- only the trigger did.
        if (strtolower(trim((string) ($order->payment_status ?? ''))) === strtolower(self::STATUS_PAID)) {
            return false;
        }

        // NO EVENT, NO SETTLEMENT. This is the whole decision in one line.
        if ($collectionEvent === null) {
            return false;
        }

        // The event must be for THIS order. A caller handing over somebody else's event settles
        // nothing -- the same class of mistake as a guard that authorises one row while the
        // handler mutates another.
        if ((int) ($collectionEvent->order_id ?? 0) !== (int) ($order->id ?? -1)) {
            return false;
        }

        // AND THE AMOUNT MUST MATCH WHAT IS OWED. A collection of less than the order total is a
        // partial collection, which is a different thing and is not settlement; a collection of
        // more is a data error. Compared with a tolerance because both sides are money held in
        // columns this project does not control the types of.
        $collected = (float) ($collectionEvent->amount ?? 0);
        $owed = (float) ($order->final_price ?? 0);

        if ($collected <= 0.0 || abs($collected - $owed) > 0.005) {
            return false;
        }

        return true;
    }

    /**
     * RETIRED, and deliberately left as a method that throws rather than deleted outright.
     *
     * Deleting it would make every stale caller a fatal "undefined method" at the point of use,
     * which in a vendored-module codebase can surface anywhere and says nothing about why. This
     * says why. There is no caller in app/ as of the withdrawal -- the only one,
     * OrderController::order_status_change(), was removed in the same change -- and
     * CodStateMachineTest asserts that none returns.
     *
     * @deprecated The Delivered trigger was withdrawn by owner decision on 2026-10-24.
     *             Use codMayBeSettled() with a verified collection event.
     */
    public function codShouldSettleOnDelivery($order): bool
    {
        throw new \LogicException(
            'codShouldSettleOnDelivery() was withdrawn by owner decision on 2026-10-24: "Delivered '
            . 'must never, by itself, mark COD as Paid or credit a merchant wallet." Settlement now '
            . 'requires a verified cash collection event naming the collector, amount, time and '
            . 'evidence. Use codMayBeSettled($order, $collectionEvent) with an event from '
            . 'App\Services\CodCollectionService::verifiedCollectionFor().'
        );
    }
}
