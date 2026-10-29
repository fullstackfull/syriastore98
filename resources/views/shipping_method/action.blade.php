<span class="d-flex gap-1 justify-content-end">
@if ($shippingMethod['method_name'] == 'Flat Rate')
    <button aria-label="{{ __('Edit Shipping Method') }}" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" data-url="{{ route('shipping-method.edit', $shippingMethod['id']) }}"
        data-size="lg" data-ajax-popup="true" data-title="{{ __('Edit Shipping Method') }}"  data-bs-toggle="tooltip"
        title="{{ __('Edit') }}"> <i class="ti ti-pencil" aria-hidden="true"></i>
    </button>
@elseif($shippingMethod['method_name'] == 'Free shipping')
    <button aria-label="{{ __('Edit Shipping Method') }}" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" data-url="{{ route('free-shipping.edit', $shippingMethod['id']) }}"
        data-size="md" data-ajax-popup="true" data-title="{{ __('Edit Shipping Method') }}"  data-bs-toggle="tooltip"
        title="{{ __('Edit') }}"> <i class="ti ti-pencil" aria-hidden="true"></i>
    </button>
@elseif($shippingMethod['method_name'] == 'Local pickup')
    <button aria-label="{{ __('Edit Shipping Method') }}" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" data-url="{{ route('local-shipping.edit', $shippingMethod['id']) }}"
        data-size="md" data-ajax-popup="true" data-title="{{ __('Edit Shipping Method') }}"  data-bs-toggle="tooltip"
        title="{{ __('Edit') }}"> <i class="ti ti-pencil" aria-hidden="true"></i>
    </button>
@endif
</span>
