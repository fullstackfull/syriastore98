<span class="d-flex gap-1 justify-content-end">
    @permission('Manage Coupon')
    <a class="btn btn-sm btn-warning btn-badge"
            href="{{ route('plan-coupon.show', $coupon->id) }}" data-bs-toggle="tooltip"
            title="{{ __('Show') }}">
            <i class="ti ti-eye"></i>
    </a>
    @endpermission

    {{-- Edit --}}
    @permission('Edit Coupon')
        <button class="btn btn-sm btn-badge btn-info"
            data-url="{{ route('plan-coupon.edit', $coupon->id) }}" data-size="md"
            data-ajax-popup="true" data-title="{{ __('Edit Coupon') }}" data-bs-toggle="tooltip"
            title="{{ __('Edit') }}">
            <i class="ti ti-pencil" ></i>
        </button>
    @endpermission

    {{-- Delete --}}
    @permission('Delete Coupon')
        {!! Form::open(['method' => 'DELETE', 'route' => ['plan-coupon.destroy', $coupon->id], 'class' => 'd-inline']) !!}
        <button type="button" class="btn btn-sm btn-danger btn-badge show_confirm" data-bs-toggle="tooltip"  data-confirm="{{ __('Are You Sure?') }}"
        data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
        title="{{ __('Delete') }}">
            <i class="ti ti-trash"></i>
        </button>
        {!! Form::close() !!}
    @endpermission
</span>