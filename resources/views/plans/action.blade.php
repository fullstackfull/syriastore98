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
    {!! Form::open(['method' => 'DELETE', 'route' => ['bank_transfer.destroy', $order->id], 'class' => 'd-inline']) !!}
        <button aria-label="{{ __('Delete :thing', ['thing' => __('Plan Order')]) }}" type="button" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet show_confirm" data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
        data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
        title="{{ __('Delete') }}">
            <i class="ti ti-trash" aria-hidden="true" ></i>
        </button>
    {!! Form::close() !!}

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