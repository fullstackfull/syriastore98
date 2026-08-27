@if (!empty($product->custom_field))
@php
    $customFieldData = json_decode($product->custom_field, true);
    $customFieldData = is_array($customFieldData) ? $customFieldData : [];
@endphp

@foreach ($customFieldData as $item)
    @if (!is_null($item['custom_field']) && !is_null($item['custom_value']))
        <div class="flex justify-between gap-3 border-b p-3 pdp-detail">
            <span class="font-medium">{{ $item['custom_field'] }}</span>
            <span class="text-end lbl">{{ $item['custom_value'] }}</span>
        </div>
    @endif
@endforeach
@endif
