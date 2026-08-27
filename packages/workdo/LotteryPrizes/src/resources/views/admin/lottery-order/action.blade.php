@if ($order->delivered_status == 3 && $order->return_status == 1)
    <a href="#" class="btn btn-sm btn-info me-2 return_request" data-id="{{ $order->id }}" data-status="2">
        <i class="ti ti-check py-1" data-bs-toggle="tooltip" title="{{ __('Return order request approve') }}"></i>
    </a>
    <a href="#" class="btn btn-sm btn-info me-2 return_request" data-id="{{ $order->id }}" data-status="3">
        <i class="ti ti-circle-x py-1" data-bs-toggle="tooltip" title="{{ __('Return order request cancel') }}"></i>
    </a>
@endif
@permission('Show Auction Product Order')
    <a href="{{ route('lottery.order.view', \Illuminate\Support\Facades\Crypt::encrypt($order->id)) }}"
        class="btn btn-sm btn-info me-2">
        <i class="ti ti-pencil py-1" data-bs-toggle="tooltip" title="{{__('Edit')}}"></i>
    </a>
@endpermission

@permission('Delete Auction Product Order')
    {!! Form::open(['method' => 'DELETE', 'route' => ['auctionorder.destroy', $order->id], 'class' => 'd-inline']) !!}
    <button type="button" class="btn btn-sm btn-danger show_confirm">
        <i class="ti ti-trash text-white py-1"></i>
    </button>
    {!! Form::close() !!}
@endpermission
