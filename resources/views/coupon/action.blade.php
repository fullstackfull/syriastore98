<span class="d-flex gap-1 justify-content-end">
@permission('Show Coupon')
<a class="btn btn-sm btn-warning btn-badge" href="{{ route('coupon.show', $coupon->id) }}"  data-bs-toggle="tooltip"
title="{{ __('Show') }}">
    <i class="ti ti-eye"></i>
</a>
@endpermission
@permission('Edit Coupon')
<button class="btn btn-sm btn-info btn-badge"
    data-url="{{ route('coupon.edit', $coupon->id) }}"
    data-size="lg" data-ajax-popup="true"
    data-title="{{ __('Edit Coupon') }}"  data-bs-toggle="tooltip"
    title="{{ __('Edit') }}">
    <i class="ti ti-pencil"></i>
</button>
@endpermission
@permission('Delete Coupon')
{!! Form::open(['method' => 'DELETE', 'route' => ['coupon.destroy', $coupon->id], 'class' => 'd-inline']) !!}
<button type="button" class="btn btn-sm btn-danger show_confirm btn-badge mr-1"  data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"  title="{{ __('Delete') }}">
    <i class="ti ti-trash"></i>
</button>
{!! Form::close() !!}
@endpermission
</span>
