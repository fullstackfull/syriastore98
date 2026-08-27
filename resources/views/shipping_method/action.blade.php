<span class="d-flex gap-1 justify-content-end">
@if ($shippingMethod['method_name'] == 'Flat Rate')
    <button class="btn btn-sm btn-info btn-badge mr-1" data-url="{{ route('shipping-method.edit', $shippingMethod['id']) }}"
        data-size="lg" data-ajax-popup="true" data-title="{{ __('Edit Shipping Method') }}"  data-bs-toggle="tooltip"
        title="{{ __('Edit') }}"> <i class="ti ti-pencil"></i>
    </button>
@elseif($shippingMethod['method_name'] == 'Free shipping')
    <button class="btn btn-sm btn-info btn-badge mr-1" data-url="{{ route('free-shipping.edit', $shippingMethod['id']) }}"
        data-size="md" data-ajax-popup="true" data-title="{{ __('Edit Shipping Method') }}"  data-bs-toggle="tooltip"
        title="{{ __('Edit') }}"> <i
            class="ti ti-pencil"></i>
    </button>
@elseif($shippingMethod['method_name'] == 'Local pickup')
    <button class="btn btn-sm btn-badge btn-info mr-1" data-url="{{ route('local-shipping.edit', $shippingMethod['id']) }}"
        data-size="md" data-ajax-popup="true" data-title="{{ __('Edit Shipping Method') }}"  data-bs-toggle="tooltip"
        title="{{ __('Edit') }}"> <i
            class="ti ti-pencil"></i>
    </button>
@endif
</span>
