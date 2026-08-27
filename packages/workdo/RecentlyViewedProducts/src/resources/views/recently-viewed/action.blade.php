@permission('Edit Recently Viewed Product')
    <button class="btn btn-sm btn-info" data-url="{{ route('recently-view.edit', $product->id) }}" data-size="md"
        data-ajax-popup="true" data-title="{{ __('Edit Recently Viewed') }}">
        <i class="ti ti-pencil py-1" data-bs-toggle="tooltip" title="{{ __('Edit')}}"></i>
    </button>
@endpermission
@permission('Delete Recently Viewed Product')
    {!! Form::open([
        'method' => 'DELETE',
        'route' => ['recently-view.destroy', $product->id],
        'class' => 'd-inline',
    ]) !!}
    <button type="button" class="btn btn-sm btn-danger show_confirm">
        <i class="ti ti-trash text-white py-1" data-bs-toggle="tooltip" title="{{ __('Delete')}}"></i>
    </button>
    {!! Form::close() !!}
@endpermission
