<span class="d-flex gap-1 justify-content-end">
    @if ($order->delivered_status == 3 && $order->return_status == 1)
    <a href="#" class="btn btn-sm btn-primary return_request" data-id="{{ $order->id }}" data-status="2" data-bs-toggle="tooltip"
        title="{{ __('Approve') }}">
        <i class="ti ti-check"></i>
    </a>
    <a href="#" class="btn btn-sm btn-danger return_request" data-id="{{ $order->id }}" data-status="3" data-bs-toggle="tooltip"
        title="{{ __('Cancel') }}">
        <i class="ti ti-circle-x"></i>
    </a>
    @endif
    <a href="javascript:void(0)"
        data-url="{{ route('order.order_view', \Illuminate\Support\Facades\Crypt::encrypt($order->id)) }}" data-size="lg"
        data-ajax-popup="true" data-title="{{ __('Order') }}    #{{ $order->product_order_id }}"
        class="x-3 btn btn-sm align-items-center btn btn-sm btn-warning" data-bs-toggle="tooltip"
        data-original-title="{{ __('Show') }}" data-bs-toggle="tooltip"
        title="{{ __('Show') }}">
        <i class="ti ti-eye"></i>
    </a>
    <a href="{{ route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($order->id)) }}"
        class="btn btn-sm btn-info" data-bs-toggle="tooltip"
        title="{{ __('Edit') }}">
        <i class="ti ti-pencil"></i>
    </a>
    {!! Form::open(['method' => 'DELETE', 'route' => ['order.destroy', $order->id], 'class' => 'd-inline']) !!}
    <button type="button" class="btn btn-sm btn-danger show_confirm" data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
        data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"
        title="{{ __('Delete') }}">
        <i class="ti ti-trash"></i>
    </button>
    {!! Form::close() !!}
</span>