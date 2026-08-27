<span class="d-flex gap-1 justify-content-end">
<a href="{{ route('shipping-zone.show',$shippingZone->id) }}" class="btn btn-sm btn-warning" data-title="{{__('Show Shipping Zone')}}">
    <i class="ti ti-eye" data-bs-toggle="tooltip" title="" data-bs-original-title="{{ __('Show')}}" aria-label="Show Shipping Zone"></i>
</a>
<button class="btn btn-sm btn-info" data-url="{{ route('shipping-zone.edit', $shippingZone->id) }}" data-size="md"
    data-ajax-popup="true" data-title="{{ __('Edit') }}"  data-bs-toggle="tooltip"
    title="{{ __('Edit') }}">
    <i class="ti ti-pencil"></i>
</button>
@if ($shippingZone->zone_name != 'Locations not covered by your other zones')
    {!! Form::open([
        'method' => 'DELETE',
        'route' => ['shipping-zone.destroy', $shippingZone->id],
        'class' => 'd-inline',
    ]) !!}
    <button type="button" class="btn btn-sm btn-danger btn-badge mr-1 show_confirm"  data-confirm="{{ __('Are You Sure?') }}"
    data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"  data-bs-toggle="tooltip"
    title="{{ __('Delete') }}">
        <i class="ti ti-trash"></i>
    </button>
    {!! Form::close() !!}
@endif
</span>
