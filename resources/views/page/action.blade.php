@if($page->is_default == 0)

<span class="d-flex gap-1 justify-content-end">
@permission('Edit Page')
<button class="btn btn-sm btn-info" data-url="{{ route('pages.edit', $page->id) }}" data-size="lg" data-ajax-popup="true" data-title="{{ __('Edit Page') }}" data-bs-toggle="tooltip"
title="{{ __('Edit') }}">
    <i class="ti ti-pencil"></i>
</button>
@endpermission
@permission('Delete Page')
{!! Form::open(['method' => 'DELETE', 'route' => ['pages.destroy', $page->id], 'class' => 'd-inline']) !!}
<button type="button" class="btn btn-sm btn-danger show_confirm" data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
title="{{ __('Delete') }}">
    <i class="ti ti-trash"></i>
</button>
{!! Form::close() !!}
@endpermission
</span>

@endif
