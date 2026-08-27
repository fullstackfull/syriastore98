@permission('Edit Auction Product')
    <a href="{{ route('auctionproduct.edit', $product->id) }}" class="btn btn-sm btn-info"
        data-title="{{ __('Edit Product') }}">
        <i class="ti ti-pencil py-1" data-bs-toggle="tooltip" title="{{__('Edit')}}"></i>
    </a>
@endpermission
@permission('Delete Auction Product')
    {!! Form::open([
        'method' => 'DELETE',
        'route' => ['auctionproduct.destroy', $product->id],
        'class' => 'd-inline',
    ]) !!}
    <button type="button" class="btn btn-sm btn-danger show_confirm" data-confirm="{{ __('Are You Sure?') }}"
    data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" >
        <i class="ti ti-trash text-white py-1" data-bs-toggle="tooltip" title="{{__('Delete')}}"></i>
    </button>
    {!! Form::close() !!}
@endpermission
