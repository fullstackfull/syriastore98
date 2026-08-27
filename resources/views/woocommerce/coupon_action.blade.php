<span class="d-flex gap-1 justify-content-end">
    @if ( in_array($data->id,$upddata))
    @permission('Edit Woocommerce Coupon')
        <a href="{{ route('woocom_coupon.edit', $data->id) }}"  class="btn btn-sm btn-info"
            data-title="{{ __('Sync Again') }}" data-bs-toggle="tooltip"
            title="{{ __('Sync Again') }}">
            <i class="ti ti-refresh "></i>
        </a>
    @endpermission
    @else
    @permission('Create Woocommerce Coupon')
        <a href="{{ route('woocom_coupon.show', $data->id) }}" class="btn btn-sm btn-primary"
            data-title="{{__('Add Coupon')}}"
            data-bs-toggle="tooltip" title="{{ __('Add Coupon') }}">
            <i class="ti ti-plus"></i>
        </a>
    @endpermission
    @endif
</span>
