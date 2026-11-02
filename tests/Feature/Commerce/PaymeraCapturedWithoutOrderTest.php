<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\PaymentController;
use App\Models\Order;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * F186 — A VERIFIED PAYMERA PAYMENT THAT PRODUCED NO ORDER MUST NOT BE MARKED `processed`.
 * =============================================================================================
 *
 * `$pending['processed']` is not a progress marker. `handlePaymeraCallback`'s top guard (:535) and
 * `freshPaymeraPendingOrNull`'s in-lock re-check both key on it and on NOTHING else, so writing it
 * is an assertion that the checkout finished. Both entry points wrote `true` unconditionally,
 * after a writer whose every non-writing exit is a `return` rather than a throw — an empty basket,
 * a stock failure, a quantity or foreign-product refusal, a missing product row, `User not found`.
 * Nothing threw, so the F96 catch never ran, and the record was stamped `processed` with
 * `order_id => null`: the gateway had captured, no order existed, and the record said otherwise.
 *
 * WHAT IT COSTS, AND A CORRECTION THAT MATTERS MORE THAN THE ORIGINAL CLAIM. The first version of
 * this file said the shared writer takes no gateway-return claim on this path, because the Paymera
 * `request_data` carries no `checkout_nonce`. **It carries one.** `PaymentService::process()`
 * stamps it at `:325` -- `stampCheckoutNonce()` merges `self::CHECKOUT_NONCE_KEY` into the request
 * before the gateway switch -- and `processPaymera()` carries it through
 * `$request->except('attachment','payment_receipt')` (`:481`) into the stored `request_data`
 * (`:509`). The wrong version rested on a grep for the LITERAL `checkout_nonce`, which finds
 * nothing in `PaymentService.php` because the key is only ever written as the constant. A census
 * keyed on a literal cannot see a constant, and the two cases that were meant to pin that premise
 * were built from a hand-written array with the key simply left out -- so they were true by
 * construction and could not have failed. They are replaced by the two at the bottom of this file,
 * which derive the state the way production does and assert the claim IS taken.
 *
 * SO THIS FIX DOES NOT RECOVER THE CAPTURE. The claim is taken by the writer's own
 * `claimGatewayReturn($request, 'storefront-return', $requests_data)` call, above every refusal
 * listed above, for 48 hours -- longer than the record's own two, which is only true because
 * **F10** below made the record's deadline absolute; this slice had briefly made it renewable.
 * A retry past the 60-minute
 * `paymera_claim_<reference>` re-enters the writer and meets `gatewayReturnReplayRefusal()`. What
 * the fix buys is an honest record and a `critical` that pages, and it is the prerequisite for any
 * later decision to release that claim. It is also LATENT by default: `config/services.php:264`
 * defaults `require_callback_signature` to true with no signature keys set, so the callback is
 * refused before the writer unless an owner has turned that off.
 *
 * WHAT IS NOT FIXED. The writer's gateway-return claim is not released. For a gateway whose state
 * DOES carry a nonce it is taken above the write and outlives the record, and releasing it is the
 * general undo `releaseGatewayReturnClaim()`'s docblock forbids. That half of F186 is BLOCKED for
 * an owner decision — see PHASE-02-TASK-LEDGER.md. These cases cover only the half that can be
 * proven not to create a second order.
 */
class PaymeraCapturedWithoutOrderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * THE F186 CASE ITSELF. Nothing was written, so the record must stay retryable.
     *
     * This is the one case in this file whose ANSWER changed; the rest pin directions that must
     * not. Returning `false` here is what adds a second pass at the writer, so the no-duplicate
     * property depends on the cases below it -- though not on them alone: the writer's own
     * 48-hour claim refuses that pass first. See the class docblock.
     */
    public function test_a_capture_with_no_order_anywhere_is_not_established(): void
    {
        $mark = $this->highWaterMark(1);

        $this->assertSame(0, $mark, 'the premise: this store has no paymera order yet');
        $this->assertFalse(
            $this->established(null, 1, $mark),
            'the writer returned a refusal without writing and nothing committed, yet the payment'
            . ' would be stamped `processed` — so the record asserts a checkout that does not'
            . ' exist, an operator reconciling it cannot tell that from a lost binding, and the'
            . ' capture is never logged at `critical`'
        );
    }

    /** The normal success path: the session binding named the row. Exact, and unchanged. */
    public function test_the_order_the_binding_named_is_established(): void
    {
        $order = $this->makeOrder(['store_id' => 1, 'payment_type' => 'paymera']);

        $this->assertTrue(
            $this->established($order, 1, $this->highWaterMark(1)),
            'the writer created this order and named it, so marking the payment processed is the'
            . ' correct and duplicate-refusing answer'
        );
    }

    /**
     * THE BINDING LOST THE ORDER BUT AN ORDER EXISTS.
     *
     * `OrderCompleteBinding::remember()` is the LAST of ~110 lines of unguarded post-commit work,
     * so a throw in that tail — or a store_id/payment_type disagreement on the re-read — leaves a
     * committed order that the binding cannot name. Answering `false` there would let the buyer's
     * refresh write a SECOND order for one capture.
     */
    public function test_an_order_the_binding_could_not_name_is_still_established(): void
    {
        $mark = $this->highWaterMark(1);
        $this->makeOrder(['store_id' => 1, 'payment_type' => 'paymera']);

        $this->assertTrue(
            $this->established(null, 1, $mark),
            'an order committed in this store after the mark but the binding named none, and the'
            . ' payment was left retryable — which hands the next replay a second order'
        );
    }

    /**
     * FAIL CLOSED. An unanswerable question marks the payment processed.
     *
     * The direction is deliberate and is the opposite of the release decision in the F96 catch:
     * there `true` keeps the claim, here `true` refuses the retry. Both point the same way —
     * never risk a second order — which is why one imprecise helper can serve both.
     */
    public function test_an_unusable_store_id_fails_closed(): void
    {
        $this->assertTrue(
            $this->established(null, 'not-a-store', 0),
            'without a usable store id there is no query to run, and guessing `false` would let a'
            . ' retry write a second order for a capture that may already have one'
        );
    }

    /**
     * ANOTHER TENANT'S ORDER IS NOT THIS ONE'S.
     *
     * The inherited risk of reusing `paymeraOrderCommittedSince()` for this decision: if another
     * merchant's Paymera order could satisfy it, a busy neighbour would mark THIS store's lost
     * capture as processed and bury it.
     */
    public function test_another_stores_order_does_not_establish_this_one(): void
    {
        $mark = $this->highWaterMark(1);
        $this->makeOrder(['store_id' => 2, 'payment_type' => 'paymera']);

        $this->assertFalse(
            $this->established(null, 1, $mark),
            'a neighbouring tenant\'s order marked this tenant\'s capture processed, which hides a'
            . ' real loss behind an unrelated store\'s traffic'
        );
    }

    /**
     * THE RESIDUAL THIS DECISION BUYS, PINNED RATHER THAN HIDDEN. Found by the mandated review.
     *
     * `paymeraOrderCommittedSince()` is a per-store, per-payment-type high-water-mark existence
     * test. Another buyer's paymera order committing in the SAME store while this writer ran
     * satisfies it, so this capture is marked `processed` with `order_id => null` — F186's own
     * outcome, recreated for this buyer, and routed to the `warning` rather than the `critical`
     * that pages. The cross-store case is refused (the case above); the same-store concurrent one
     * is not, and cannot be with the identifiers available: an `orders` row carries no paymera
     * reference, which is why option (c) in the ledger row is a migration.
     *
     * IT IS STILL THE RIGHT DIRECTION, and the asymmetry is the reason: answering `false` here
     * risks a second paid order, which needs a refund and a merchant conversation to undo;
     * answering `true` risks a reconciliation an operator can complete from the `warning` line and
     * the gateway's own records. This case asserts today's answer so that narrowing it is a
     * deliberate act with a failing test attached, not a silent drift.
     */
    public function test_a_same_store_neighbours_order_is_still_mistaken_for_this_capture(): void
    {
        $mark = $this->highWaterMark(1);

        // Another buyer's checkout, in this store, committing inside this writer's window.
        $this->makeOrder(['store_id' => 1, 'payment_type' => 'paymera']);

        $this->assertTrue(
            $this->established(null, 1, $mark),
            'this is the RECORDED residual, and it reading false means the predicate has been'
            . ' narrowed. That is an improvement — update this case and the ledger row deliberately'
            . ' rather than deleting the assertion.'
        );
    }

    /**
     * THE `critical` IS WRITTEN ONCE PER RECORD, NOT ONCE PER REFRESH.
     *
     * Review's F4: a record left unprocessed no longer short-circuits, and the callback route is
     * anonymous and unthrottled, so a per-pass `critical` would let anyone holding a live
     * `paymera_<uniqid>` reference flood the very channel this line exists to page on. The guard is
     * `ss_unreconciled_capture` on the stored record. Source-asserted because the handler cannot be
     * driven end to end (see the class docblock).
     */
    public function test_the_unreconciled_critical_is_guarded_against_repetition(): void
    {
        $source = $this->executableSource();

        $this->assertSame(
            2,
            substr_count($source, "empty(\$pending['ss_unreconciled_capture'])"),
            'both entry points must guard the critical on the record, or a reference holder can'
            . ' repeat the paging line for the record\'s whole two-hour life'
        );

        $this->assertSame(
            2,
            substr_count($source, "\$pending['ss_unreconciled_capture'] = ! \$ssOrderExists;"),
            'the guard flag must be written in both entry points, or the guard above never arms'
        );
    }

    /**
     * BOTH ENTRY POINTS, AND NEITHER MAY GO BACK TO AN UNCONDITIONAL `true`.
     *
     * Source-asserted because no Feature test can drive `handlePaymeraCallback` end to end — it
     * polls the gateway over HTTP and then runs the full order writer. What a test CAN do is pin
     * that the decision is consulted at all, in both places, and that the literal the defect was
     * made of is gone.
     */
    public function test_neither_entry_point_stamps_processed_unconditionally(): void
    {
        $source = $this->executableSource();

        $this->assertStringNotContainsString(
            "\$pending['processed'] = true;",
            $source,
            'an entry point is back to asserting a finished checkout without asking whether an'
            . ' order exists, which is F186 exactly'
        );

        $this->assertSame(
            2,
            substr_count($source, "\$pending['processed'] = \$ssOrderExists;"),
            'both Paymera entry points must write the decision — the browser callback and the'
            . ' gateway trigger. One of the two has stopped consulting it.'
        );

        $this->assertSame(
            2,
            substr_count($source, '$this->paymeraOrderEstablished('),
            'the decision helper is no longer called from both entry points'
        );
    }

    /**
     * A CAPTURED PAYMENT WITH NO ORDER IS `critical`, NOT `warning`.
     *
     * The pre-fix code logged `warning` for "could not identify the order it created" and said
     * nothing at all about the capture. Money that reached the gateway and produced no order is
     * the one thing on this path an operator must be paged for, and it is now the only record
     * that the reconciliation is owed.
     */
    public function test_both_entry_points_log_the_unreconciled_capture_at_critical(): void
    {
        $source = $this->executableSource();

        foreach ([
            'Paymera payment verified but no order was written; the capture is unreconciled',
            'Paymera trigger verified the payment but wrote no order; the capture is unreconciled',
        ] as $message) {
            $this->assertStringContainsString(
                "\\Log::critical('" . $message . "'",
                $source,
                'the unreconciled capture is no longer logged at critical with this message, so'
                . ' SecurityLogInventoryTest cannot see it and nobody is paged for lost money'
            );
        }
    }

    /**
     * F10 — THE RECORD'S DEADLINE IS ABSOLUTE, BECAUSE F186 MADE A ROLLING ONE RENEWABLE.
     *
     * Found by the mandated review, in this slice's own change. Every put of
     * `paymera_pending_<reference>` wrote `now()->addHours(2)`. That was harmless while the first
     * pass stamped `processed` and later ones short-circuited: the put ran once per record. With
     * `processed` left false, an unprocessed record reaches a put once per 60 minutes — the
     * `paymera_claim_` lifetime — so one anonymous GET an hour kept it alive indefinitely.
     *
     * WHY THAT IS NOT MERELY UNTIDY. Both F186 docblocks reason from *the writer's claim is 48
     * hours, longer than the record's own two*, and that is what makes "the retry meets
     * `gatewayReturnReplayRefusal()`" a permanent answer. A renewable record falsifies it: at
     * T+48h the writer's claim lapses — a refused `Cache::add` does not extend it — while the
     * record is still live, so the next pass clears the claim and enters the writer for real,
     * writing an order two days after the capture and, on the signed-in branch, against the LIVE
     * cart rather than the basket that was paid for.
     */
    public function test_a_record_with_a_deadline_is_not_given_two_more_hours(): void
    {
        $deadline = now()->addMinutes(10);

        $answer = $this->recordDeadline(['ss_expires_at' => $deadline->toIso8601String()]);

        $this->assertLessThanOrEqual(
            60,
            abs($answer->diffInSeconds($deadline)),
            'the stored deadline was ignored and the record got a fresh window, so one request an'
            . ' hour keeps it alive past the writer\'s 48-hour claim'
        );
        $this->assertLessThan(
            now()->addHour()->getTimestamp(),
            $answer->getTimestamp(),
            'the answer is an hour or more away, which means the rolling two hours came back'
        );
    }

    /** A record written before this change has no deadline and must keep working. */
    public function test_a_record_without_a_deadline_keeps_the_old_rolling_window(): void
    {
        $answer = $this->recordDeadline(['payment_id' => 'PAY-1']);

        $this->assertGreaterThan(
            now()->addMinutes(110)->getTimestamp(),
            $answer->getTimestamp(),
            'an old record lost its two-hour window, which would drop a live payment record on'
            . ' deploy — the one failure worse than the renewal this fixes'
        );
    }

    /**
     * AN UNREADABLE DEADLINE FALLS BACK RATHER THAN DROPPING THE RECORD.
     *
     * The direction is deliberate and is the opposite of the fail-closed rule used for the order
     * question: there, closed means refusing a retry; here, closed would mean deleting a live
     * payment record, which loses the buyer's order outright.
     */
    public function test_an_unreadable_deadline_falls_back_instead_of_dropping_the_record(): void
    {
        foreach (['', '   ', 'not-a-date', 0, [], null] as $junk) {
            $answer = $this->recordDeadline(['ss_expires_at' => $junk]);

            $this->assertGreaterThan(
                now()->addMinutes(110)->getTimestamp(),
                $answer->getTimestamp(),
                'an unreadable deadline produced something other than the two-hour fallback, so a'
                . ' corrupt field can delete a live payment record. Value: ' . var_export($junk, true)
            );
        }
    }

    /**
     * BOTH HALVES OF THE MECHANISM, SOURCE-ASSERTED.
     *
     * The live puts must consult the deadline; the two dead twins
     * (`handlePaymeraCallback00`/`handlePaymeraTrigger00`, unrouted and deliberately not
     * maintained in parallel) keep the old form, and that is the count this pins so a fifth live
     * put cannot quietly arrive with a rolling window.
     */
    public function test_every_live_record_write_consults_the_deadline(): void
    {
        $source = $this->executableSource();

        $this->assertSame(
            4,
            substr_count($source, '$this->paymeraRecordDeadline($pending)'),
            'a live put of the paymera record no longer consults the stored deadline, so the record'
            . ' is renewable again'
        );

        $this->assertSame(
            2,
            substr_count($source, "Cache::put('paymera_pending_' . \$orderId, \$pending, now()->addHours(2))"),
            'the rolling two-hour window should survive only in the two unrouted twins. A third'
            . ' occurrence is a live write that renews the record.'
        );
    }

    /** And the deadline has to be stamped at initiation, or there is nothing to honour. */
    public function test_the_initiation_stamps_the_deadline_on_the_record(): void
    {
        $source = $this->executableSource(PaymentService::class);

        $this->assertStringContainsString(
            "'ss_expires_at' => \$ssPaymeraDeadline->toIso8601String(),",
            $source,
            'the Paymera initiation no longer stamps an absolute deadline, so every record falls'
            . ' back to the rolling window and F10 is open again'
        );

        $this->assertStringContainsString(
            '], $ssPaymeraDeadline);',
            $source,
            'the record is stored with a TTL other than the deadline it carries, so the two can'
            . ' disagree from the first write'
        );
    }

    /**
     * THE PREMISE, DERIVED THE WAY PRODUCTION DERIVES IT RATHER THAN HAND-WRITTEN.
     *
     * The two cases this replaces built a Paymera state by hand and omitted `checkout_nonce`, then
     * asserted the claim key set was empty -- true by construction, unfalsifiable, and wrong about
     * production. This one runs the real stamp-and-carry: `PaymentService::stampCheckoutNonce()`
     * merges the key into the request (`process()` calls it before the gateway switch), and
     * `processPaymera()` stores `$request->except('attachment','payment_receipt')` as
     * `request_data`. If a future change stops stamping, or stops carrying, this fails and the
     * recovery analysis in F186 has to be re-derived -- in the direction that would make the
     * capture recoverable.
     */
    public function test_the_paymera_state_really_does_carry_a_checkout_nonce(): void
    {
        $state = $this->statePaymeraWouldStore();

        $this->assertArrayHasKey(
            'checkout_nonce',
            $state,
            'the Paymera stored state no longer carries a checkout nonce, so the shared writer takes'
            . ' NO 48-hour claim and a retry past the 60-minute paymera claim would re-enter the'
            . ' writer. That makes the capture recoverable and changes what F186 is; re-derive it.'
        );

        $this->assertNotSame(
            [],
            $this->claimKeys($state),
            'the state carries a nonce but builds no claim key, so nothing above the write refuses'
            . ' a replay'
        );
    }

    /**
     * AND THE CLAIM IS REALLY TAKEN — asserted behaviourally, by being refused the second time.
     *
     * This is what bounds the retry `processed => false` now permits: the second pass meets
     * `gatewayReturnReplayRefusal()` rather than the writer. It is also the reason this slice does
     * not claim to recover the payment.
     */
    public function test_the_writer_claims_the_paymera_return_and_refuses_the_replay(): void
    {
        $state = $this->statePaymeraWouldStore();

        $this->assertTrue($this->claim($state), 'the premise: the first return proceeds');
        $this->assertFalse(
            $this->claim($state),
            'the replay was NOT refused, so the writer takes no durable claim for this gateway and'
            . ' `processed` is the only thing standing between a captured Paymera payment and a'
            . ' second order'
        );
    }

    /**
     * The `request_data` a Paymera initiation really stores, built the way production builds it.
     *
     * `PaymentService::process()` stamps the checkout nonce into the request and `processPaymera()`
     * stores `$request->except('attachment','payment_receipt')`. Reflection on the private stamp is
     * what keeps this honest: a hand-written array is what made the first version of these cases
     * unfalsifiable.
     */
    private function statePaymeraWouldStore(): array
    {
        $request = Request::create('/checkout', 'POST', [
            'payment_type' => 'paymera',
            'slug' => 'demo-store',
        ]);

        $stamp = new \ReflectionMethod(PaymentService::class, 'stampCheckoutNonce');
        $stamp->setAccessible(true);
        $stamped = $stamp->invoke(app(PaymentService::class), $request);

        $state = $stamped->except('attachment', 'payment_receipt');
        $state['store_id'] = 1;

        return $state;
    }

    /**
     * The controller's source with comments stripped.
     *
     * The ratchets below match strings against the whole file. Review pointed out that a COMMENT
     * carrying the string satisfies them -- comment out the real `\Log::critical(...)` call, or
     * leave one `$pending['processed'] = $ssOrderExists;` inside a comment and delete the live one,
     * and they stay green. They fail on a straight revert, which is most of the value; stripping
     * comments closes the revert-to-comment hole as well.
     */
    private function executableSource(string $class = PaymentController::class): string
    {
        /*
         * `token_get_all()` RATHER THAN A REGEX, and review found the reason. The first cut used a
         * lazy block-comment pattern alternated with a line-comment one, and it also strips inside
         * STRING LITERALS: it turned `'https://egate-t.paymera.cc'` into `'https:`, and any future
         * string containing a block-comment opener would have blanked everything up to the next
         * closer -- silently removing a needle and turning one of these ratchets green, or red, for
         * a reason that has nothing to do with the code it guards. The tokeniser knows the
         * difference.
         *
         * (Writing this comment demonstrated the hazard a second time: quoting that pattern here
         * put a comment TERMINATOR inside a block comment and the file stopped parsing. Hence the
         * prose description rather than the pattern.)
         */
        $out = '';

        foreach (token_get_all(file_get_contents((new \ReflectionClass($class))->getFileName())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    private function recordDeadline(array $pending): \Illuminate\Support\Carbon
    {
        $m = new \ReflectionMethod(PaymentController::class, 'paymeraRecordDeadline');
        $m->setAccessible(true);

        return $m->invoke($this->controller(), $pending);
    }

    private function established(mixed $named, mixed $storeId, int $mark): bool
    {
        $m = new \ReflectionMethod(PaymentController::class, 'paymeraOrderEstablished');
        $m->setAccessible(true);

        return $m->invoke($this->controller(), $named, $storeId, $mark);
    }

    private function highWaterMark(mixed $storeId): int
    {
        $m = new \ReflectionMethod(PaymentController::class, 'paymeraOrderHighWaterMark');
        $m->setAccessible(true);

        return $m->invoke($this->controller(), $storeId);
    }

    private function claimKeys(array $state): array
    {
        $m = new \ReflectionMethod(PaymentController::class, 'gatewayReturnClaimKeys');
        $m->setAccessible(true);

        return $m->invoke($this->controller(), $this->storefrontReturn(), 'storefront-return', $state);
    }

    private function claim(array $state): bool
    {
        $m = new \ReflectionMethod(PaymentController::class, 'claimGatewayReturn');
        $m->setAccessible(true);

        return $m->invoke($this->controller(), $this->storefrontReturn(), 'storefront-return', $state);
    }

    private function storefrontReturn(): Request
    {
        $request = Request::create('/demo-store/get-payment-status', 'GET');
        $request->setLaravelSession(app('session.store'));

        return $request;
    }

    private function makeOrder(array $attributes): Order
    {
        /* `order_id` and `price` were here and are gone: neither is in `Order::$fillable` and
           neither column exists in `orders`, so both were silently dropped and the sequence
           counter driving one of them was inert. Found by review. */
        return Order::create(array_merge([
            'product_order_id' => '0202501010101',
            'payment_status' => 'succeeded',
        ], $attributes));
    }

    private function controller(): PaymentController
    {
        return new PaymentController(app(PaymentService::class));
    }
}
