<span class="d-flex gap-1 justify-content-end">
    @permission('Manage Coupon')
    <a aria-label="{{ __('Show :thing', ['thing' => __('Coupon')]) }}" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost"
            href="{{ route('plan-coupon.show', $coupon->id) }}" data-bs-toggle="tooltip"
            title="{{ __('Show') }}">
            <i class="ti ti-eye" aria-hidden="true"></i>
    </a>
    @endpermission

    {{-- Edit --}}
    @permission('Edit Coupon')
        <button aria-label="{{ __('Edit Coupon') }}" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost"
            data-url="{{ route('plan-coupon.edit', $coupon->id) }}" data-size="md"
            data-ajax-popup="true" data-title="{{ __('Edit Coupon') }}" data-bs-toggle="tooltip"
            title="{{ __('Edit') }}">
            <i class="ti ti-pencil" aria-hidden="true" ></i>
        </button>
    @endpermission

    {{-- Delete --}}
    @permission('Delete Coupon')
        {!! Form::open(['method' => 'DELETE', 'route' => ['plan-coupon.destroy', $coupon->id], 'class' => 'd-inline']) !!}
        <button aria-label="{{ __('Delete :thing', ['thing' => __('Coupon')]) }}" type="button" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet show_confirm" data-bs-toggle="tooltip"  data-confirm="{{ __('Are You Sure?') }}"
        data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
        title="{{ __('Delete') }}">
            <i class="ti ti-trash" aria-hidden="true"></i>
        </button>
        {!! Form::close() !!}
    @endpermission
</span>