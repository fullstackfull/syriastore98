{{-- F153. Same cause as plans/action.blade.php: `payment_type` holds the buyer's language,
     so a receipt row written in any of the sixteen translated locales fell through every branch
     to the `-` at the end -- no invoice link, no slip, no explanation. --}}
<span class="d-flex gap-1 justify-content-center">
    @if ($order->receipt != 'free coupon' && $order->payment_type == 'STRIPE')
        <a href="{{ $order->receipt }}" title="Invoice" target="_blank"
        data-bs-toggle="tooltip" title="{{ __('Invoice') }}" class=""><i class="fas fa-file-invoice"></i> </a>
    @elseif($order->receipt == 'free coupon')
        <p>{{ __('Used') . '100 %' . __('discount coupon code.') }}</p>
    @elseif(\App\Payments\PlanPaymentType::is($order->payment_type, \App\Payments\PlanPaymentType::MANUALLY))
        <p>{{ __('Manually plan upgraded by super admin') }}</p>
    @elseif (\App\Payments\PlanPaymentType::is($order->payment_type, \App\Payments\PlanPaymentType::BANK_TRANSFER))
        <a href="{{ asset($order->receipt) }}" class="btn  btn-outline-primary"
            target="_blank" data-bs-toggle="tooltip" title="{{ __('Invoice') }}">
            <i class="fas fa-file-invoice"></i>
        </a>
    @else
        -
    @endif
</span>