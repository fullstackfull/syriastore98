<span class="d-flex gap-1 justify-content-end">
<a aria-label="{{__('Show Shipping Zone')}}" href="{{ route('shipping-zone.show',$shippingZone->id) }}" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" data-title="{{__('Show Shipping Zone')}}">
    <i class="ti ti-eye" aria-hidden="true" data-bs-toggle="tooltip" title="" data-bs-original-title="{{ __('Show')}}" aria-label="Show Shipping Zone"></i>
</a>
<button aria-label="{{ __('Edit :thing', ['thing' => __('Shipping Zone')]) }}" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost" data-url="{{ route('shipping-zone.edit', $shippingZone->id) }}" data-size="md"
    data-ajax-popup="true" data-title="{{ __('Edit') }}"  data-bs-toggle="tooltip"
    title="{{ __('Edit') }}">
    <i class="ti ti-pencil" aria-hidden="true"></i>
</button>
@if ($shippingZone->zone_name != 'Locations not covered by your other zones')
    {!! Form::open([
        'method' => 'DELETE',
        'route' => ['shipping-zone.destroy', $shippingZone->id],
        'class' => 'd-inline',
    ]) !!}
    <button aria-label="{{ __('Delete :thing', ['thing' => __('Shipping Zone')]) }}" type="button" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet show_confirm"  data-confirm="{{ __('Are You Sure?') }}"
    data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"  data-bs-toggle="tooltip"
    title="{{ __('Delete') }}">
        <i class="ti ti-trash" aria-hidden="true"></i>
    </button>
    {!! Form::close() !!}
@endif
</span>
