<span class="d-flex gap-1 justify-content-center">
    @if ($order->receipt != 'free coupon' && $order->payment_type == 'STRIPE')
        <a href="{{ $order->receipt }}" title="Invoice" target="_blank"
        data-bs-toggle="tooltip" title="{{ __('Invoice') }}" class=""><i class="fas fa-file-invoice"></i> </a>
    @elseif($order->receipt == 'free coupon')
        <p>{{ __('Used') . '100 %' . __('discount coupon code.') }}</p>
    @elseif($order->payment_type == 'Manually')
        <p>{{ __('Manually plan upgraded by super admin') }}</p>
    @elseif ($order->payment_type == 'Bank Transfer')
        <a href="{{ asset($order->receipt) }}" class="btn  btn-outline-primary"
            target="_blank" data-bs-toggle="tooltip" title="{{ __('Invoice') }}">
            <i class="fas fa-file-invoice"></i>
        </a>
    @else
        -
    @endif
</span>