{{-- F153. `payment_type` HOLDS THE BUYER'S LANGUAGE, not an identifier.

     `BankTransferController:103` writes `__('Bank Transfer')`, so a merchant who submitted in
     Arabic stored `تحويل مصرفي`. Comparing that against the English literal was false, so the
     Payment Status control -- the one a platform operator uses to approve the transfer -- never
     rendered, and the order sat paid, pending and invisible to the only screen that can advance
     it. SIXTEEN locale files translate the string, so English was the only language this worked
     in.

     `payment_status` is NOT affected: :104 writes the plain literal `'Pending'`.

     `PlanPaymentType::is()` recognises every spelling this application has shipped. It decides
     whether the button is DRAWN and nothing else -- `plan.order.show` is still gated by
     `PlanOrderViewGate`, and approve/reject are still gated on their own routes. --}}
<span class="d-flex gap-1 justify-content-start">
    @if ($order->payment_status == 'Pending' && \App\Payments\PlanPaymentType::is($order->payment_type, \App\Payments\PlanPaymentType::BANK_TRANSFER))
        <button aria-label="{{ __('Payment Status') }}" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost"
            data-url="{{ route('plan.order.show', $order->id) }}"
            data-size="lg" data-ajax-popup="true"
            data-title="{{ __('Payment Status') }}"
            title="{{ __('Details') }}"  data-bs-toggle="tooltip"
            title="{{ __('Change Status') }}">
            <i class="ti ti-caret-right" aria-hidden="true"></i>
        </button>
    @endif
    {{--
        The delete affordance for a subscription payment record was REMOVED, not hidden behind a
        permission.

        Owner instruction, 2026-11-11: "Treat financial-record deletion as prohibited pending an
        approved retention and correction policy." `BankTransferController::destroy()` now refuses
        every caller, operator and record owner included, so this button could only ever produce a
        "Deleting a payment record is not permitted." toast. Leaving it would be a control that
        looks available and never works, and the next person to meet it would most likely re-admit
        a caller in the controller to make it function -- which is the one thing the instruction
        forbids.

        A @permission or @if guard would be the wrong shape here too: the action is prohibited for
        everyone, not reserved for someone. When a retention and correction policy is approved,
        add a CORRECTION affordance (a reversing entry, or a soft delete that writes an audit row)
        rather than restoring this one.
    --}}

    @foreach ($userOrders as $userOrder)
        @if ($user->plan_id == $order->plan_id && $order->order_id == $userOrder->order_id && $order->is_refund == 0)
            <div>
                <a aria-label="{{ __('Refund :thing', ['thing' => __('Plan Order')]) }}" href="{{ route('plan.order.refund', [$order->id, $order->user_id]) }}"
                    class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" data-bs-toggle="tooltip"
                    title="{{ __('Refund') }}"
                    data-original-title="{{ __('Refund') }}">
                    <span><svg xmlns="http://www.w3.org/2000/svg"
                            width="24" height="24" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round"
                            class="icon icon-tabler icons-tabler-outline icon-tabler-receipt-refund">
                            <path stroke="none" d="M0 0h24v24H0z"
                                fill="none" />
                            <path
                                d="M5 21v-16a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v16l-3 -2l-2 2l-2 -2l-2 2l-2 -2l-3 2" />
                            <path
                                d="M15 14v-2a2 2 0 0 0 -2 -2h-4l2 -2m0 4l-2 -2" />
                        </svg></span>
                </a>
            </div>
        @endif
    @endforeach
</span>