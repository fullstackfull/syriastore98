<button class="btn btn-sm btn-primary me-2 "
    data-url="{{ route('customer.payment', [$slug, Crypt::encrypt($order->id)]) }}" data-size="lg"
    data-ajax-popup="true" data-title="{{ __('Pay Now') }}">
    <i class="ti ti-coin text-white py-1"></i>
</button>
