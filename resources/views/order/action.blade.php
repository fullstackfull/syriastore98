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
    {{--
        P2-04-5. THE QUICK PREVIEW, ADDED BESIDE THE EXISTING MODAL RATHER THAN
        REPLACING IT. Workstream F's rule is to preserve existing AJAX modal
        contracts during migration, and `data-ajax-popup` is driven by the host
        theme's own script on ~65 screens -- removing one caller would not
        simplify anything and would drop a surface merchants use today.

        The panel opens from the list and fetches `order.preview`, a read-only
        fragment on its own uri registered in OrderDocumentAccessGuard::TARGETS.
        The id is raw: authorization is the guard's, not the ciphertext's.
    --}}
    <button type="button"
        data-ss-panel-open="ss-order-preview-panel"
        data-ss-panel-url="{{ route('order.preview', $order->id) }}"
        class="btn btn-sm btn-light-primary"
        data-bs-toggle="tooltip"
        title="{{ __('Quick preview') }}"
        aria-label="{{ __('Quick preview of order :id', ['id' => $order->product_order_id]) }}">
        <i class="ti ti-layout-sidebar-right-expand" aria-hidden="true"></i>
    </button>

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