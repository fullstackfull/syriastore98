<span class="d-flex gap-1 justify-content-end">
<a aria-label="{{ __('Show Tax Class') }}" href="{{ route('taxes.show',$tax->id) }}" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" data-title="{{ __('Show Tax Class') }}"  data-bs-toggle="tooltip"
title="{{ __('Show') }}">
    <i class="ti ti-eye" aria-hidden="true" data-bs-original-title="{{ __('Show Tax Class') }}" aria-label="{{ __('Show Tax Class') }}"></i>
</a>
<button aria-label="{{ __('Edit Tax Class') }}" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" data-url="{{ route('taxes.edit', $tax->id) }}" data-size="md" data-ajax-popup="true" data-title="{{ __('Edit Tax Class') }}"  data-bs-toggle="tooltip"
title="{{ __('Edit') }}">
    <i class="ti ti-pencil" aria-hidden="true"></i>
</button>
{!! Form::open(['method' => 'DELETE', 'route' => ['taxes.destroy', $tax->id]]) !!}
<button aria-label="{{ __('Delete :thing', ['thing' => __('Tax Class')]) }}" type="button" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet show_confirm"  data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
title="{{ __('Delete') }}">
    <i class="ti ti-trash" aria-hidden="true"></i>
</button>
{!! Form::close() !!}
</span>
