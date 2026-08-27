@permission('Edit Auction Setting')
    <button class="btn btn-sm btn-info" data-url="{{ route('auctionsetting.edit', $auction->id) }}" data-size="lg"
        data-ajax-popup="true" data-title="{{ __('Edit Auction Setting') }}">
        <i class="ti ti-pencil py-1" data-bs-toggle="tooltip" title="{{__('Edit')}}"></i>
    </button>
@endpermission
@permission('Delete Auction Setting')
    {!! Form::open([
        'method' => 'DELETE',
        'route' => ['auctionsetting.destroy', $auction->id],
        'class' => 'd-inline',
    ]) !!}
    <button type="button" class="btn btn-sm btn-danger show_confirm">
        <i class="ti ti-trash text-white py-1" data-bs-toggle="tooltip" title="{{__('Delete')}}"></i>
    </button>
    {!! Form::close() !!}
@endpermission
