@if (module_is_active('ProductPricing') && isset($item->sale_price))
    <ins class="no-underline">{{ currency_format_with_sym($item->sale_price, $store->id) }}
    </ins>
    @if ($item->total_orignal_price > $item->sale_price)
    <del class="text-gray-500">{{ currency_format_with_sym($item->total_orignal_price, $store->id) ?? SetNumberFormat($item->total_orignal_price) }}</del>
    @endif
@else
    @if ($item->final_price == $item->total_orignal_price)
    <ins class="no-underline">{{ currency_format_with_sym($item->final_price, $store->id) }}
    </ins>
    @else 
    <ins class="no-underline">{{ currency_format_with_sym($item->final_price, $store->id) }}
    </ins>
        @if ($item->total_orignal_price > $item->final_price)
        <del class="text-gray-500">{{ currency_format_with_sym($item->total_orignal_price, $store->id) }}</del>
        @endif
    @endif
@endif
