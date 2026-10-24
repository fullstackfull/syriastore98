<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WHAT A SHOPPER STILL OWES IS A SERVER FACT.  P1-ORD-02 criterion 2, the `pending_payment` limb.
 * =================================================================================================
 *
 * THE BRANCH. `PaymentController::prepareFinalRequestData()` prices a PartialPayments checkout
 * through the module's own server-side `ManageDeposit()` on every path but one:
 *
 *     } else {
 *         $new_array['cartlist_final_price'] = $request->payment_amount ?? 0;
 *     }
 *
 * reached when the body carries `partial_payment_type = pending_payment` -- the form the module
 * prints for a shopper settling the OUTSTANDING BALANCE of an earlier order
 * (packages/workdo/PartialPayments/src/resources/views/theme/payment-view.blade.php:110-117). The
 * amount owed is recorded: it is the `payment_amount` of the `order_partial_payments` row the module
 * wrote when the deposit was taken, and the view prints that figure into a hidden field. The handler
 * read the hidden field back.
 *
 * AND THAT KEY IS THE CHARGE, not a label on it. Every gateway arm in `App\Services\PaymentService`
 * takes the amount it hands the provider from `cartlist_final_price` (:447, :554, :841, :963, :1037
 * and twenty more), and `GuardsGatewayReturn::serverPayableAmount()` reads the same key to decide
 * what AuthorizeNet captures. MEASURED on `POST {slug}/process-order`, signed in as a real customer
 * of the store, against a server-recorded outstanding balance of 40.00: posting
 * `payment_amount = 1.00` stored and charged 1.00.
 *
 * THE IDENTITY WAS NOT CHECKED EITHER, and it is the same question. `partial_payment_id` travels with
 * the body into the stored checkout state and, on the way back, into
 * `PartialPayments::ManagePartialPayment()` (PaymentController:2183, PaymentService:2189), whose
 * whole body is `OrderPartialPayments::find($request_data['partial_payment_id'])` followed by
 * `payment_status = 'On Hold'` -- no `store_id` predicate, no `customer_id` predicate, and the model
 * is in no `config/tenancy.php` `scoped_models` entry, so no global scope narrows it. Deriving the
 * amount from the row means finding the right row, so both are answered here.
 *
 * WHAT THIS TRAIT IS. A read of the one row that states the balance, refusing unless it is THIS
 * store's, THIS signed-in customer's, still awaiting payment, and carries a positive amount. It
 * returns NULL rather than a fallback: there is no safe default payable for a balance that cannot be
 * identified, and charging the full cart instead would be a different wrong number.
 *
 * WHY A RAW READ AND NOT THE MODULE'S MODEL. `Workdo\PartialPayments\app\Models\OrderPartialPayments`
 * is `Cachable`, and this read has to answer against the table at the moment of the charge -- the
 * same read happens again on the gateway return, after `ManagePartialPayment()` has written the row.
 * A raw read also keeps the host app from depending on a vendored class being loaded on a path where
 * the module may not be.
 */
trait ResolvesPendingPartialPayment
{
    /**
     * The literal the module writes for an unpaid balance.
     *
     * `OrderPartialPayments::OrderPartialPayments()` inserts the second row with
     * `'payment_status' => 'Pending payment'`, and `customerpayment()` selects on the same string.
     */
    private const PENDING_PAYMENT_STATUS = 'Pending payment';

    /**
     * Payment types whose gateway return CANNOT settle a pending balance, so a pending-balance
     * checkout must never be started on one.
     *
     * FOUND BY THE READ-ONLY REVIEW OF THIS CHANGE, and verified before being acted on. Eight
     * handlers in `app/` read the stored checkout state and write an order; seven carry the settle
     * branch and `KhaltiPaymnetController::getOrderPaymentStatus` carries NO `partial_payment`
     * reference at all (grepped: zero matches in the file). So a khalti `pending_payment` checkout
     * was priced at the outstanding balance by `prepareFinalRequestData()`, charged that figure
     * through `resources/views/payment/khalti.blade.php:16`, and then -- on the way back -- written
     * as a FULL-PRICE order from the cart, with the balance left unsettled. The payable was a
     * server fact and it priced the wrong thing.
     *
     * REFUSED AT INITIATION, NOT AT THE RETURN, and that ordering is the whole point: by the time
     * the return runs the card has already been charged, so refusing there would take the money and
     * record nothing. Refusing before the gateway is reached costs the shopper one message and
     * nothing else.
     *
     * A SETTLE BRANCH IS NOT ADDED TO KHALTI HERE. That would be a new money path on a gateway this
     * environment cannot drive, which is a worse trade than refusing. It is recorded instead.
     *
     * THE LIST IS RATCHETED, not trusted: PendingPaymentGatewayCensusTest derives the settling set
     * from the source and fails if a ninth stored-state order writer appears, or if khalti gains a
     * settle branch and this entry is not removed.
     */
    private const PENDING_PAYMENT_UNSUPPORTED_TYPES = ['khalti'];

    /**
     * Can a pending balance be settled through this payment type at all?
     *
     * @param  mixed  $paymentType
     */
    protected function pendingPaymentTypeCanSettle($paymentType): bool
    {
        return ! in_array(strtolower(trim((string) $paymentType)), self::PENDING_PAYMENT_UNSUPPORTED_TYPES, true);
    }

    /**
     * The `order_partial_payments` row that states what this customer still owes this store.
     *
     * @param  mixed  $partialPaymentId  Caller-supplied; parsed strictly.
     * @param  mixed  $storeId           The store resolved from the route, never from the body.
     * @param  mixed  $customerId        The AUTHENTICATED customer, never from the body.
     * @return object|null
     */
    protected function pendingPartialPaymentRow($partialPaymentId, $storeId, $customerId)
    {
        /*
         * FILTER_VALIDATE_INT, not is_numeric. `is_numeric('5abc')` is false and `is_numeric('5.9')`
         * is true, while MySQL coerces `'5abc'` to 5 in a `WHERE id = ?` comparison -- so is_numeric
         * refuses a value the database would have accepted and accepts one it would have truncated.
         * The recorded identifier-parsing trap.
         */
        $id = filter_var($partialPaymentId, FILTER_VALIDATE_INT);
        $store = filter_var($storeId, FILTER_VALIDATE_INT);
        $customer = filter_var($customerId, FILTER_VALIDATE_INT);

        if ($id === false || $id <= 0 || $store === false || $store <= 0 || $customer === false || $customer <= 0) {
            return null;
        }

        try {
            $row = DB::table('order_partial_payments')
                ->where('id', $id)
                ->where('store_id', $store)
                ->where('payment_status', self::PENDING_PAYMENT_STATUS)
                ->first();
        } catch (\Throwable $e) {
            /*
             * FAILS CLOSED. The module's tables are created by its own migrations, so a store whose
             * module was never migrated reaches here with no table. There is no payable to derive in
             * that case, and the alternative -- letting the exception out -- is a 500 on a money path.
             */
            Log::warning('Pending partial payment lookup failed; the payment is refused.', [
                'site' => static::class . '::' . __FUNCTION__,
                'store_id' => $store,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $row) {
            return null;
        }

        /*
         * THE CUSTOMER COMPARISON IS MADE IN PHP, and that is not a style choice.
         *
         * The module's migration declares `customer_id` as `string()` -- a VARCHAR -- while
         * `OrderPartialPayments::OrderPartialPayments()` inserts an integer into it. Laravel binds
         * an integer as PDO::PARAM_INT (Illuminate\Database\Connection::bindValues), so MySQL
         * converts the COLUMN to a number for the comparison and `->where('customer_id', 7)` admits
         * every string it can read a 7 out of. MEASURED on MySQL 8.0.46 against the module's own
         * schema (tests/Support/Security/bin/verify-pending-partial-payment-lookup-on-mysql.php):
         * rows holding '7', '7abc', ' 7' and '07' ALL matched. On SQLite the same predicate matched
         * only '7' -- so a SQL-side ownership check is unsound on the engine that matters and
         * well-behaved on the engine the suite runs, which is the worst combination available.
         *
         * Reading the value out and parsing it the same way as the input gives one answer on both.
         */
        if (filter_var($row->customer_id, FILTER_VALIDATE_INT) !== $customer) {
            return null;
        }

        return $row;
    }

    /**
     * What this customer still owes, or NULL if no row says.
     *
     * @return float|null
     */
    protected function pendingPartialPaymentPayable($partialPaymentId, $storeId, $customerId): ?float
    {
        $row = $this->pendingPartialPaymentRow($partialPaymentId, $storeId, $customerId);

        if (! $row || ! isset($row->payment_amount) || ! is_numeric($row->payment_amount)) {
            return null;
        }

        $amount = round((float) $row->payment_amount, 2);

        // A balance of zero or less is not something to charge for. The module's own view already
        // filters those rows out (`whereNot('payment_amount', 0)`), so a request naming one did not
        // come from the page.
        return $amount > 0 ? $amount : null;
    }

    /**
     * Does the figure the browser posted disagree with the one the server holds?
     *
     * REJECTED RATHER THAN SILENTLY CORRECTED, which is the owner's instruction and is also the
     * kinder behaviour: the module's view posts the row's own `payment_amount`, so an honest body
     * always agrees. A disagreement is tampering or a page left open while the balance changed, and
     * charging a different amount from the one on screen would be a surprise in either case.
     *
     * A body that posts NOTHING is not a disagreement -- there is simply nothing to compare, and the
     * server figure stands.
     *
     * @param  mixed  $posted
     */
    protected function pendingPaymentAmountDisagrees($posted, float $payable): bool
    {
        if ($posted === null || $posted === '' || $posted === []) {
            return false;
        }

        if (! is_numeric($posted)) {
            return true;
        }

        // Half a cent, so '40' and '40.00' agree and 39.99 does not.
        return abs(round((float) $posted, 2) - $payable) > 0.005;
    }
}
