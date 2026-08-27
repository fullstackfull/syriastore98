@permission('Edit Bundle Product')
    <button class="btn btn-sm btn-info" data-url="{{ route('bundle-product.edit', $product->id) }}" data-size="md"
        data-ajax-popup="true" data-title="{{ __('Edit Bundle Product') }}">
        <i class="ti ti-pencil py-1" data-bs-toggle="tooltip" title="{{ __('Edit') }}"></i>
    </button>
@endpermission
@permission('Delete Bundle Product')
    {!! Form::open([
        'method' => 'DELETE',
        'route' => ['bundle-product.destroy', $product->id],
        'class' => 'd-inline',
    ]) !!}
    <button type="button" class="btn btn-sm btn-danger show_confirm" data-confirm="{{ __('Are You Sure?') }}"
    data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" >
        <i class="ti ti-trash text-white py-1" data-bs-toggle="tooltip" title="{{__('Delete')}}"></i>
    </button>
    {!! Form::close() !!}
@endpermission
