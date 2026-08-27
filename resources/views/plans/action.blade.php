<span class="d-flex gap-1 justify-content-start">
    @if ($order->payment_status == 'Pending' && $order->payment_type == 'Bank Transfer')
        <button class="btn btn-sm btn-primary btn-badge"
            data-url="{{ route('plan.order.show', $order->id) }}"
            data-size="lg" data-ajax-popup="true"
            data-title="{{ __('Payment Status') }}"
            title="{{ __('Details') }}"  data-bs-toggle="tooltip"
            title="{{ __('Change Status') }}">
            <i class="ti ti-caret-right"></i>
        </button>
    @endif
    {!! Form::open(['method' => 'DELETE', 'route' => ['bank_transfer.destroy', $order->id], 'class' => 'd-inline']) !!}
        <button type="button" class="btn btn-sm btn-danger show_confirm btn-badge" data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
        data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
        title="{{ __('Delete') }}">
            <i class="ti ti-trash" ></i>
        </button>
    {!! Form::close() !!}

    @foreach ($userOrders as $userOrder)
        @if ($user->plan_id == $order->plan_id && $order->order_id == $userOrder->order_id && $order->is_refund == 0)
            <div class="btn btn-sm btn-success">
                <a href="{{ route('plan.order.refund', [$order->id, $order->user_id]) }}"
                    class="btn-badge" data-bs-toggle="tooltip"
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