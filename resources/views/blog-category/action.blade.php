<span class="d-flex gap-1 justify-content-end">
@permission('Edit Blog Category')
    <button class="btn btn-sm btn-info"
        data-url="{{ route('blog-category.edit', $category->id) }}"
        data-size="md" data-ajax-popup="true"
        data-title="{{ __('Edit Blog Category') }}" data-bs-toggle="tooltip"
        title="{{ __('Edit') }}">
        <i class="ti ti-pencil"></i>
    </button>
@endpermission
@permission('Delete Blog Category')
    {!! Form::open(['method' => 'DELETE', 'route' => ['blog-category.destroy', $category->id], 'class' => 'd-inline']) !!}
    <button type="button" class="btn btn-sm btn-danger show_confirm" data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
    title="{{ __('Delete') }}"><i class="ti ti-trash"></i></button>
    {!! Form::close() !!}
@endpermission
</span>