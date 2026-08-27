<span class="d-flex gap-1 justify-content-end">
<a href="{{ route('taxes.show',$tax->id) }}" class="btn btn-sm btn-warning" data-title="{{ __('Show Tax Class') }}"  data-bs-toggle="tooltip"
title="{{ __('Show') }}">
    <i class="ti ti-eye" data-bs-original-title="{{ __('Show Tax Class') }}" aria-label="{{ __('Show Tax Class') }}"></i>
</a>
<button class="btn btn-sm btn-info" data-url="{{ route('taxes.edit', $tax->id) }}" data-size="md" data-ajax-popup="true" data-title="{{ __('Edit Tax Class') }}"  data-bs-toggle="tooltip"
title="{{ __('Edit') }}">
    <i class="ti ti-pencil"></i>
</button>
{!! Form::open(['method' => 'DELETE', 'route' => ['taxes.destroy', $tax->id]]) !!}
<button type="button" class="btn btn-sm btn-danger show_confirm"  data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
title="{{ __('Delete') }}">
    <i class="ti ti-trash"></i>
</button>
{!! Form::close() !!}
</span>
