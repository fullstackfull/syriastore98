<span class="d-flex gap-1 justify-content-end">
    @if ( in_array($customer->id,$upddata))
        @permission('Edit Woocommerce Customer')
            <a href="{{ route('woocom_customer.edit', $customer->id) }}"  class="btn btn-sm btn-info"
                data-title="{{ __('Sync Again') }}" data-bs-toggle="tooltip"
                title="{{ __('Sync Again') }}">
                <i class="ti ti-refresh "></i>
            </a>
            @endpermission
        @else
        @permission('Create Woocommerce Customer')
            <a href="{{ route('woocom_customer.show', $customer->id) }}" class="btn btn-sm btn-primary"
                data-title="{{__('Add Customer')}}"
                data-bs-toggle="tooltip" title="{{ __('Create Customer') }}">
                <i class="ti ti-plus"></i>
            </a>
        @endpermission
    @endif
</span>