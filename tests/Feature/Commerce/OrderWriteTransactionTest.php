<?php

namespace Tests\Feature\Commerce;

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

        // Every one of these writes must land between begin and commit.
        foreach ([
            '$order->save()' => 'the order row',
            '$OrderBillingDetail->save()' => 'the billing detail',
            '$OrderCouponDetail->save()' => 'the coupon detail',
            '$UserCoupon->save()' => 'the coupon consumption row',
            '$OrderTaxDetail->save()' => 'the tax detail',
        ] as $needle => $what) {
            $at = strpos($source, $needle);

            $this->assertNotFalse($at, $what . ' write must still be present');
            $this->assertGreaterThan($begin, $at, $what . ' must be inside the transaction');
            $this->assertLessThan($commit, $at, $what . ' must be inside the transaction');
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

        foreach ([
            'sendEmailTemplate' => 'an SMTP send',
            'SendMsgs' => 'an SMS send',
            'StoreNotificationService' => 'the Firebase push',
            'Http::' => 'an outbound HTTP call',
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
        $restore = strpos($source, '$this->restoreStockDecrements(');

        $this->assertNotFalse($restore,
            'a rolled-back order write must give back the stock it consumed');
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

        $this->assertNotFalse($rethrow, 'the exception must be re-thrown, not swallowed');
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
