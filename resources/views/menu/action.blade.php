<span class="d-flex gap-1 justify-content-end">
@permission('Edit Menu')
<a aria-label="{{ __('Edit :thing', ['thing' => __('Menu')]) }}" href="{{ route('menus.edit', $menu->id) }}" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" data-bs-toggle="tooltip"
title="{{ __('Edit') }}">
    <i class="ti ti-pencil" aria-hidden="true"></i>
</a>
@endpermission
@permission('Delete Menu')
{!! Form::open(['method' => 'DELETE', 'route' => ['menus.destroy', $menu->id], 'class' => 'd-inline']) !!}
<button aria-label="{{ __('Delete :thing', ['thing' => __('Menu')]) }}" type="button" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet show_confirm" data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
title="{{ __('Delete') }}">
    <i class="ti ti-trash" aria-hidden="true"></i>
</button>
{!! Form::close() !!}
@endpermission
</span>