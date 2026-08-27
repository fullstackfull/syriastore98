@if (module_is_active('ProductPricing') && isset($item->sale_price))
   <div class="font-semibold text-right">{{ currency_format_with_sym($item->sale_price, $store->id) ?? SetNumberFormat($item->sale_price) }}
   </div>
@else
   <div class="font-semibold text-right">{{ currency_format_with_sym($item->final_price, $store->id) ?? SetNumberFormat($item->final_price) }}
   </div>
@endif
