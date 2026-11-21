<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\PaymentController;
use App\Services\PaymentService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * THE ORDER-WRITE TRANSACTION BOUNDARY.
 *
 * THE DEFECT. There was no DB::transaction anywhere in either `getProductStatus`
 * writer -- `grep -n 'DB::transaction\|DB::beginTransaction' ` over
 * PaymentController and PaymentService matched nothing in the order-writing
 * range. The Order row, its OrderBillingDetail, its OrderCouponDetail, the
 * UserCoupon consumption row and its OrderTaxDetail rows were each saved
 * separately, so a failure part-way left an order with no billing address, or a
 * coupon consumed with nothing recording what for, or recorded against the order
 * but never marked used -- redeemable a second time.
 *
 * WHY THESE ASSERTIONS ARE STRUCTURAL, stated rather than implied.
 * Demonstrating a rollback behaviourally means making one of the child-row
 * writes fail in the middle of a method that first needs a store, theme, plan,
 * customer, cart, billing, coupon, tax-method and payment-settings fixture. The
 * suite does not have that fixture and does not pretend to. What IS checked here
 * is the property a reviewer would otherwise have to re-derive by reading 400
 * lines, and the property most likely to be silently broken by a later edit:
 * WHERE the boundary sits relative to the writes and, crucially, relative to the
 * EXTERNAL calls.
 *
 * The external-call assertion is the important one. A transaction around an
 * outbound HTTP or SMTP call holds database row locks for the duration of a
 * third party's response time, which is a worse defect than the one being
 * fixed. The stock loop above the boundary calls Utility::sendEmailTemplate and
 * Utility::SendMsgs on its low-stock and back-in-stock branches, and
 * StoreNotificationService::orderCreated() reaches Firebase via
 * $this->firebase->sendToToken() (StoreNotificationService:169). All of them
 * must stay outside, and this proves they do.
 *
 * Comments are stripped before matching, so a comment that merely mentions a
 * symbol cannot satisfy or defeat any assertion below.
 */
class OrderWriteTransactionTest extends TestCase
{
    /**
     * The two order writers that had no transaction. The service-side one is the
     * writer that actually runs for 'cod' and 'bank_transfer'
     * (PaymentService::process() dispatches both to it).
     */
    public static function writers(): array
    {
        return [
            'controller (payment-return writer)' => [PaymentController::class, 'getProductStatus'],
            'service (cod / bank_transfer writer)' => [PaymentService::class, 'getProductStatus'],

            /*
             * THE THIRD WRITER, added in the stabilization phase. It was the one
             * left without a boundary while the two above got theirs, although
             * it writes the Order row plus the same four child tables
             * (OrderBillingDetail, OrderCouponDetail, UserCoupon,
             * OrderTaxDetail) the same way, each saved on its own, plus
             * auto-created City rows.
             *
             * It has no gateway call of its own -- whatsapp() composes a wa.me
             * URL rather than POSTing to anyone -- so there was never a reason
             * for it to be the exception.
             */
            'controller (whatsapp writer)' => [PaymentController::class, 'whatsapp'],

            /*
             * P4-F026 — THE TWO STOREFRONT API WRITERS, added 2026-11-16.
             *
             * `Api\ApiController::place_order` (api/{slug}/place-order and
             * .../place-order-guest, routes/api.php:140-141) and
             * `Api\ApiController::ordersave` (api/{slug}/order-save, routes/api.php:165)
             * write the SAME five rows as the three above -- the Order plus
             * OrderBillingDetail, OrderCouponDetail, UserCoupon and OrderTaxDetail,
             * each `->save()`d on its own -- and NEITHER OPENS A TRANSACTION.
             * MEASURED on d227b7c0: `DB::beginTransaction|DB::commit|DB::rollBack|
             * DB::transaction` matches NOTHING in either method's line range
             * (3459-4426 and 8905-9973).
             *
             * THE CONSEQUENCE IS NOT MERELY AN AUDIT GAP. The write order is
             * OrderCouponDetail (:4240 / :9878) and THEN UserCoupon (:4252 / :9888),
             * so the reachable half-write is "the discount is recorded, the redemption
             * is not" -- and `user_coupons` is the ONLY table either coupon cap counts.
             * `Coupon::usesByCustomer()` (app/Models/Coupon.php:115-136) takes max() of
             * a `user_coupons.user_id` count and an e-mail limb, and that e-mail limb,
             * `PerUsesCouponCount()` (:148-158), ITSELF iterates `user_coupons`;
             * `coupon_uses()` and `UsesCouponCount()` (:139-147) are counts on the same
             * table. A missing UserCoupon row therefore hides the redemption from the
             * per-user cap AND from the total limit while the order keeps its discount.
             *
             * THE IN-CODE NOTE AT :4245-4247 DOES NOT MAKE THIS HARMLESS. It says the
             * handler no longer takes `user_id` from the request; it is about the VALUE
             * of that column, not the EXISTENCE of the row.
             *
             * ADOPTING THESE ROWS IMPORTS THE OTHER CASES' DEMANDS, deliberately:
             *   - `test_no_external_call_is_held_inside_the_transaction` requires the
             *     Firebase/SMTP/SMS calls to sit outside the boundary, so the webhook
             *     and notification calls must move after `DB::commit()`;
             *   - `test_the_stock_loop_is_outside_the_boundary_and_is_compensated_on_failure`
             *     requires a `restoreStockDecrements(...)` AFTER `DB::rollBack()`, which
             *     is correct: once the order rolls back no order claims the stock;
             *   - `test_the_failure_is_rethrown_so_the_response_contract_is_unchanged`
             *     requires the literal `throw $e;` the other three writers already use.
             *     Today these two rethrow as `throw $ssStockGuardError;` from the OUTER
             *     F276/D-13 guard, which is a different catch with a different job, so
             *     that case's message ("must be re-thrown, not swallowed") is literally
             *     imprecise for these two rows while the defect it names is real.
             *
             * `executableSourceOf()` strips comments, so the handlers' own
             * "NO TRANSACTION ON THIS PATH" note (:4143-4147) can neither satisfy nor
             * defeat anything asserted here.
             */
            'storefront api (place_order)' => [ApiController::class, 'place_order'],
            'storefront api (ordersave)' => [ApiController::class, 'ordersave'],
        ];
    }

    #[DataProvider('writers')]
    public function test_the_order_and_its_child_rows_are_written_inside_one_transaction(
        string $class,
        string $method
    ): void {
        $source = $this->executableSourceOf($class, $method);

        $begin = strpos($source, 'DB::beginTransaction()');
        $commit = strpos($source, 'DB::commit()');
        $rollback = strpos($source, 'DB::rollBack()');

        $this->assertNotFalse($begin, 'the order write must open a transaction');
        $this->assertNotFalse($commit, 'and commit it');
        $this->assertNotFalse($rollback, 'and roll it back on failure');
        $this->assertGreaterThan($begin, $commit, 'the commit must follow the begin');

        /*
         * Every one of these writes must land between begin and commit.
         *
         * THE TWO COUPON ROWS ARE NAMED BY THEIR WRITER, NOT BY THEIR `save()`, and that is a
         * correction forced by P4-F134/F135 rather than a loosening. Those two `save()` calls used
         * to be inline here; they now live in `App\Services\CouponRedemptionRecorder`, which is the
         * whole point of that fix — one writer, resolving the coupon server-side, instead of two
         * copies taking their values from the request. A source scan of the handler can no longer
         * see them, so scanning for `$OrderCouponDetail->save()` would assert the ABSENCE of a
         * refactor rather than the presence of a property.
         *
         * The property is unchanged and is still asserted: the thing that writes both rows must sit
         * between begin and commit. `CouponRedemptionRecorder` deliberately opens no boundary of its
         * own (its docblock says so, and a nested one would let a caller's `rollBack()` unwind to the
         * wrong level), so it inherits this one — which is exactly what this assertion pins.
         *
         * BOTH ROWS STILL STAND OR FALL TOGETHER, and that is proved by execution elsewhere rather
         * than by this scan: `StorefrontOrderWriteAtomicityTest` injects a failure at the redemption
         * write and asserts no discount survives without it.
         */
        /*
         * WHICH WRITER WRITES THE COUPON ROWS HOW, stated per writer rather than assumed uniform.
         * P4-F134/F135 moved the two STOREFRONT API writers onto the shared recorder; the three
         * gateway/COD writers still save both rows inline and were not in that fix's scope. Keeping
         * both spellings in one list would let a writer satisfy the assertion by having EITHER, which
         * is weaker than naming what each one must actually contain — and if a later change moves the
         * remaining three onto the recorder, this fails and somebody updates it deliberately.
         */
        $usesTheRecorder = $class === ApiController::class
            && in_array($method, ['place_order', 'ordersave'], true);

        $couponWrites = $usesTheRecorder
            ? ['CouponRedemptionRecorder::class)->record(' => 'the coupon detail and consumption rows']
            : [
                '$OrderCouponDetail->save()' => 'the coupon detail',
                '$UserCoupon->save()' => 'the coupon consumption row',
            ];

        foreach ([
            '$order->save()' => 'the order row',
            '$OrderBillingDetail->save()' => 'the billing detail',
            ...$couponWrites,
            '$OrderTaxDetail->save()' => 'the tax detail',
        ] as $needle => $what) {
            $at = strpos($source, $needle);

            $this->assertNotFalse($at, $what . ' write must still be present');
            $this->assertGreaterThan($begin, $at, $what . ' must be inside the transaction');
            $this->assertLessThan($commit, $at, $what . ' must be inside the transaction');
        }

        /*
         * AND THE RECORDER MUST NOT HAVE GROWN A BOUNDARY OF ITS OWN while nobody was looking. If it
         * ever opens one, a caller's `rollBack()` in the catch below computes the wrong level and can
         * unwind work that already committed — the hazard `ordersave` documents around
         * `$ssTransactionDepth`. Asserted here because this file is where the boundary rules live.
         */
        $recorder = $this->executableSourceOf(\App\Services\CouponRedemptionRecorder::class, 'record');

        foreach (['DB::beginTransaction', 'DB::transaction', 'DB::commit', 'DB::rollBack'] as $forbidden) {
            $this->assertFalse(strpos($recorder, $forbidden),
                'CouponRedemptionRecorder::record() must not manage a transaction: both callers are '
                . 'already inside one, and a nested boundary lets their rollBack() unwind to the '
                . 'wrong level. Found ' . $forbidden . '.');
        }
    }

    /**
     * THE SAFETY ASSERTION. No outbound call may sit inside the boundary.
     *
     */
    #[DataProvider('writers')]
    public function test_no_external_call_is_held_inside_the_transaction(
        string $class,
        string $method
    ): void {
        $source = $this->executableSourceOf($class, $method);

        $begin = strpos($source, 'DB::beginTransaction()');
        $commit = strpos($source, 'DB::commit()');

        $this->assertNotFalse($begin);
        $this->assertNotFalse($commit);

        $inside = substr($source, $begin, $commit - $begin);

        /*
         * THE NEEDLES ARE CALL-SITE SPELLINGS, so a call that reaches the network through a
         * HELPER is invisible to this case unless the helper's own name is listed.
         *
         * `Http::` was the only network needle until an adversarial read of this file asked what
         * it would actually catch in the two storefront writers -- and the answer was nothing.
         * `ApiController::ordersave` reaches the merchant's configured webhook URL at :4168 as
         * `Utility::paymentWebhook($order)`, and the curl is two frames down in
         * `Utility::WebhookCall()` (app/Models/Utility.php:2207, :2291). There is no literal
         * `Http::` anywhere in that file's writers, so the boundary could have been drawn around
         * an outbound POST to a third-party URL and this case would have passed.
         *
         * That is the worst version of the lock-holding problem this case exists to prevent: a
         * merchant-SUPPLIED endpoint, so its response time is not merely a third party's, it is
         * an attacker-chooseable one. `WebhookCall` is listed too, for a writer that one day
         * calls it directly.
         *
         * MEASURED, not assumed: `paymentWebhook` appears twice in PaymentController, once in
         * PaymentService and once in ApiController, and the three writers that already hold a
         * boundary keep it outside -- so adding these needles pins existing correct behaviour
         * rather than describing an aspiration.
         */
        foreach ([
            'sendEmailTemplate' => 'an SMTP send',
            'SendMsgs' => 'an SMS send',
            'StoreNotificationService' => 'the Firebase push',
            'Http::' => 'an outbound HTTP call',
            'paymentWebhook' => "a POST to the merchant's configured webhook URL",
            'WebhookCall' => 'a direct webhook POST',
            'curl_init' => 'a raw curl call',
        ] as $needle => $what) {
            $this->assertStringNotContainsString($needle, $inside,
                $what . ' must not be held inside the order-write transaction: '
                . 'it would hold row locks for a third party\'s response time');
        }
    }

    /**
     * The stock loop stays OUTSIDE the boundary, and its decrements are
     * compensated on failure instead of rolled back.
     *
     * This is the deliberate limit of the fix: the loop cannot be brought inside
     * because it makes SMTP and SMS calls, so inventory is given back in the
     * catch using the accumulator the loop already maintains.
     *
     */
    #[DataProvider('writers')]
    public function test_the_stock_loop_is_outside_the_boundary_and_is_compensated_on_failure(
        string $class,
        string $method
    ): void {
        $source = $this->executableSourceOf($class, $method);

        $begin = strpos($source, 'DB::beginTransaction()');
        $firstDecrement = strpos($source, '$this->decrementStockOrFail(');

        $this->assertNotFalse($firstDecrement, 'the stock loop must still be there');
        $this->assertLessThan($begin, $firstDecrement,
            'the stock loop runs before the boundary; it cannot be inside it because '
            . 'it makes SMTP and SMS calls');

        // ...so the catch must give the inventory back.
        $rollback = strpos($source, 'DB::rollBack()');

        /*
         * SEARCHED FROM THE ROLLBACK, NOT FROM THE START, AND THE OFFSET IS THE WHOLE POINT.
         *
         * This assertion means "the compensation inside the transaction's catch comes after the
         * rollback". It was written when `restoreStockDecrements(` appeared exactly once, so
         * position zero was good enough. F192 added a SECOND give-back to two of these three
         * writers -- in a catch that covers the window between the stock loop and
         * `beginTransaction()`, which is necessarily BEFORE the rollback -- and a search from zero
         * then finds that one and reads it as the compensation being in the wrong place.
         *
         * So the offset is not a relaxation. Without it this case reports a defect that is not
         * there; with it, it still fails if the transaction's own catch loses its give-back.
         */
        $restore = strpos($source, '$this->restoreStockDecrements(', (int) $rollback);

        $this->assertNotFalse($rollback, 'the transaction must still roll back on failure');
        $this->assertNotFalse($restore,
            'a rolled-back order write must give back the stock it consumed. A give-back BEFORE'
            . ' the boundary (F192) does not satisfy this: the rolled-back lines are a different'
            . ' set from the ones a pre-transaction throw leaves behind.');
        $this->assertGreaterThan($rollback, $restore,
            'the compensation belongs in the catch, after the rollback');
    }

    /**
     * The exception is re-thrown, so the failure response the caller sees is
     * exactly what it was before. The transaction adds atomicity and
     * compensation; it does not change the contract.
     *
     */
    #[DataProvider('writers')]
    public function test_the_failure_is_rethrown_so_the_response_contract_is_unchanged(
        string $class,
        string $method
    ): void {
        $source = $this->executableSourceOf($class, $method);

        $rollback = strpos($source, 'DB::rollBack()');
        $rethrow = strpos($source, 'throw $e;');

        /*
         * BOTH OFFSETS ARE ASSERTED NOT-FALSE BEFORE THEY ARE COMPARED, and the missing half of
         * that was a real vacuity in this case rather than defensive noise.
         *
         * `strpos()` returns `false` when the needle is absent, and PHP's `<` compares `false` by
         * juggling it to `0`, so `assertGreaterThan(false, 412)` is TRUE. This case therefore
         * PASSED on a writer that had no transaction at all -- no `beginTransaction()`, no
         * `rollBack()` -- as long as some stray `throw $e;` existed anywhere in the method, which
         * every one of these three writers already had before the boundary was added. It would
         * have certified a no-op fix as green.
         *
         * Found by an adversarial read of this file before the boundary landed, not by running
         * it: a vacuous assertion is invisible to every run in which the code happens to be
         * right.
         */
        $this->assertNotFalse($rethrow, 'the exception must be re-thrown, not swallowed');
        $this->assertNotFalse($rollback,
            'there is no DB::rollBack() in this writer, so there is no transaction for the '
            . 're-throw to be positioned against -- this case must not pass by comparing an '
            . 'offset against strpos()\'s false');
        $this->assertGreaterThan($rollback, $rethrow);
    }

    /**
     * The Firebase push moved to AFTER the commit in the controller writer.
     *
     * Notifying about an order that may still roll back is wrong independently
     * of the lock-holding problem, so this is asserted as its own property.
     */
    public function test_the_order_notification_is_sent_only_after_the_commit(): void
    {
        $source = $this->executableSourceOf(PaymentController::class, 'getProductStatus');

        $commit = strpos($source, 'DB::commit()');
        $notify = strpos($source, 'StoreNotificationService');

        $this->assertNotFalse($notify, 'the order notification must still be sent');
        $this->assertGreaterThan($commit, $notify,
            'an order notification must not be sent for an order that may still roll back');
    }

    /**
     * A method's EXECUTABLE source, with comments removed.
     *
     * Plain source matching is not good enough here in either direction: a
     * comment quoting the defect it replaced would satisfy a "must contain"
     * check, and a comment quoting removed code would defeat a "must not
     * contain" one. Stripping comments makes every assertion above a statement
     * about the code.
     */
    private function executableSourceOf(string $class, string $method): string
    {
        $m = new \ReflectionMethod($class, $method);

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

        return $code;
    }
}
