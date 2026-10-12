<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * NEW-order-payment-status.
 *
 * OrderController::order_payment_status did:
 *
 *   $order = Order::find($request->order_id);
 *   $order->payment_status = $request->payment_status;
 *   $order->save();
 *
 * with no authentication, no permission, no tenant scope and no validation --
 * an ANONYMOUS arbitrary string write into a status column on any order in any
 * store, reachable in two requests.
 *
 * Authentication is closed by the routes/web.php brace move, authorization by
 * OrderPolicy::updatePaymentStatus, and the value vocabulary here. An arbitrary
 * string in a status column is a data-integrity defect independent of the
 * authorization defect.
 */
class UpdateOrderPaymentStatusRequest extends FormRequest
{
    /**
     * The accepted vocabulary, taken verbatim from the validation
     * OrderController::store already applies to the same column
     * ('payment_status' => 'required|in:Paid,Unpaid'), so no new vocabulary is
     * invented here.
     */
    public const PAYMENT_STATUSES = ['Paid', 'Unpaid'];

    /**
     * Authorization is performed by OrderPolicy via an explicit
     * $this->authorize() call in the controller, where it is visible.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'payment_status' => ['required', 'string', Rule::in(self::PAYMENT_STATUSES)],
        ];
    }
}
