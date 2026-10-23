<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * THE ORDER TOTAL OF A PARTIAL-PAYMENT CHECKOUT IS DERIVED, NEVER BELIEVED.  P1-ORD-02 criterion 2.
 * =================================================================================================
 *
 * WHAT WAS WRONG, and it is a repair that was already made and then missed in six places. Commit
 * 569494d2 ("PartialPayments: recompute the order total server-side") recorded the defect in its own
 * words -- "With that module active, the order TOTAL ITSELF was read from the request" -- and fixed
 * TWO sites: `PaymentService::getProductStatus()` and `PaymentController`. Six host gateway
 * controllers still carried the pre-repair line verbatim:
 *
 *     $order->final_price = $requests_data['cartlist_final_price'] ?? $total_sub_price;
 *
 * `$requests_data` IS SERVER-STORED, WHICH IS NOT THE SAME AS SERVER-DERIVED, and that distinction is
 * the whole defect. `PaymentService` stores the checkout state as
 * `$request->except('attachment', 'payment_receipt')` (:479, :680, :703, :720, :894) -- the whole POST
 * BODY of the checkout request, verbatim -- so every key the shopper posts at initiation is in the
 * array the gateway return reads back. The storage laundered nothing.
 *
 * MEASURED, not inferred (tests/Feature/Commerce/PartialPaymentDepositSourceTest.php): with the module
 * active and a 100.00 basket, a checkout posting `cartlist_final_price=1.00` wrote an order with
 * `product_price = 100.00` and `final_price = 1.00` on cinetpay, easebuzz and paiementpro.
 *
 * AND IN FIVE OF THE SIX `$total_sub_price` WAS NEVER ASSIGNED AT ALL -- grepped, zero assignments per
 * file -- so the `??` limb was an undefined variable, i.e. an ErrorException and a 500 rather than a
 * safe fallback. The fallback here is the SERVER's own cart subtotal, which is what the two repaired
 * sites already use.
 *
 * WHAT THIS DOES. It calls the module's OWN `ManageDeposit()` -- which derives the deposit from
 * `Product` rows and the store's deposit settings -- with the SERVER-derived components merged into
 * the request first, so neither the deposit nor its inputs come from the caller. Nothing inside
 * `packages/workdo/` is modified; the existing module method is called, exactly as
 * `PaymentService::resolvePartialPaymentDeposit()` does.
 *
 * WHY THE COMPONENTS MUST BE MERGED FIRST. `ManageDeposit()` reads `$request['total_sub_price']`,
 * `$request['tax_price']`, `$request['shipping_price']` and `$request['coupon_price']` before falling
 * back to its own cart response (PartialPaymentsController.php:552-554, :596). Handing it the request
 * untouched would let the caller's posted figures back in through the module instead of through the
 * controller -- the same defect one level down.
 */
trait ResolvesPartialPaymentDeposit
{
    /**
     * The server-side deposit amount for a PartialPayments order.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $slug
     * @param  array<string, mixed>  $serverComponents  Server-derived component amounts.
     * @param  mixed  $fallbackTotal  The server total to fall back to. NEVER caller input.
     * @return mixed
     */
    protected function partialPaymentDeposit($request, $slug, array $serverComponents, $fallbackTotal)
    {
        $request->merge($serverComponents);

        try {
            $updated = $this->askModuleForDeposit($request, $slug);
        } catch (\Throwable $e) {
            // FAILS TO THE SERVER TOTAL, never to the caller's figure, and says so in the log. A module
            // that cannot price the deposit is a reason to charge the full amount, not a reason to
            // accept whatever the browser asked to pay.
            Log::error('PartialPayments deposit recomputation failed; using the server total', [
                'site' => static::class . '::' . __FUNCTION__,
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return $fallbackTotal;
        }

        return $this->depositFromModuleAnswer($updated, $fallbackTotal);
    }

    /**
     * The one call into the vendored module, behind a named method.
     *
     * A SEAM WITH A REASON, not a test convenience. The catch limb below cannot be driven end to end:
     * the only way to make `ManageDeposit()` throw from a real request is a store configured with
     * `deposit_selection = pay_deposit` and no `deposit_type` row -- and MEASURED, that configuration
     * crashes the handler EARLIER, at the unguarded
     * `PartialPayments::ManagePartialPayment()` call these controllers make around line 300, with
     * `Undefined array key "deposit_type"` (PartialPaymentsController.php:623). So the fallback that
     * exists precisely for a module failure would have shipped with nothing asserting it.
     *
     * One overridable method is a smaller price than an untested fallback on a money path, and it
     * also names the boundary: everything below this line is ours, everything beyond it is vendored
     * and may do anything, including return NULL -- which it has been observed doing.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $slug
     * @return mixed
     */
    protected function askModuleForDeposit($request, $slug)
    {
        return \Workdo\PartialPayments\app\Http\Controllers\PartialPaymentsController::ManageDeposit(
            $request,
            $slug
        );
    }

    /**
     * What to take from the module's answer, and when to keep the server total instead.
     *
     * SEPARATED FROM THE CALL so it can be driven directly. A removal check recorded the `is_numeric`
     * guard as SURVIVING every integration test -- correctly, because the module always answers with a
     * `SetNumber()`-formatted figure, so no end-to-end fixture can produce the shape the guard exists
     * for. That left a type check on a MONEY field, across a vendored boundary whose contract is not
     * ours, with nothing asserting it. Removing it was not the right answer; making it testable was.
     *
     * @param  mixed  $answer  Whatever `ManageDeposit()` returned. It is documented nowhere and has
     *                         already been observed returning NULL (when the basket it re-reads has
     *                         been emptied), so nothing about its shape is assumed.
     * @param  mixed  $fallbackTotal  The server total. NEVER caller input.
     * @return mixed
     */
    protected function depositFromModuleAnswer($answer, $fallbackTotal)
    {
        if (! is_array($answer) || ! isset($answer['deposite_amount'])) {
            return $fallbackTotal;
        }

        // `is_numeric`, not a cast: `(float) 'N/A'` is 0.00, which would make an unreadable answer look
        // like a free order rather than like an error.
        return is_numeric($answer['deposite_amount']) ? $answer['deposite_amount'] : $fallbackTotal;
    }
}
