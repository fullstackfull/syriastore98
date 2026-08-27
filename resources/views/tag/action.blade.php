<span class="d-flex gap-1 justify-content-end">
{{-- Edit --}}
@permission('Edit Tag')
    <button class="btn btn-sm btn-badge btn-info"
        data-url="{{ route('tag.edit', $tag->id) }}" data-size="md"
        data-ajax-popup="true" data-title="{{ __('Edit Tag') }}"  data-bs-toggle="tooltip"
        title="{{ __('Edit') }}">
        <i class="ti ti-pencil"></i>
    </button>
@endpermission

{{-- Delete --}}
@permission('Delete Tag')
    {!! Form::open(['method' => 'DELETE', 'route' => ['tag.destroy', $tag->id], 'class' => 'd-inline']) !!}
    <button type="button" class="btn btn-sm btn-danger btn-badge mr-1 show_confirm"  data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
    data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
    title="{{ __('Delete') }}">
        <i class="ti ti-trash"></i>
    </button>
    {!! Form::close() !!}
@endpermission
</span>
