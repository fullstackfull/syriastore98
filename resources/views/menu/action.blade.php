<span class="d-flex gap-1 justify-content-end">
@permission('Edit Menu')
<a href="{{ route('menus.edit', $menu->id) }}" class="btn btn-sm btn-info" data-bs-toggle="tooltip"
title="{{ __('Edit') }}">
    <i class="ti ti-pencil"></i>
</a>
@endpermission
@permission('Delete Menu')
{!! Form::open(['method' => 'DELETE', 'route' => ['menus.destroy', $menu->id], 'class' => 'd-inline']) !!}
<button type="button" class="btn btn-sm btn-danger show_confirm" data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
title="{{ __('Delete') }}">
    <i class="ti ti-trash"></i>
</button>
{!! Form::close() !!}
@endpermission
</span>