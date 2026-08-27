<span class="d-flex gap-1 justify-content-end">
@permission('Delete Newsletter')
{!! Form::open(['method' => 'DELETE', 'route' => ['newsletter.destroy', $newsletter->id], 'class' => 'd-inline']) !!}
<button type="button" class="btn btn-sm btn-danger btn-badge mr-1 show_confirm" data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
title="{{ __('Delete') }}">
    <i class="ti ti-trash"></i>
</button>
{!! Form::close() !!}
@endpermission
</span>
