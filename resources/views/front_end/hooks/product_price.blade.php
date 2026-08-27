@if($product->variant_product == 0)
    @if (isset($product->is_sale_enable) && $product->is_sale_enable == true)
        <span class="product_final_price"> {!! currency_format_with_sym(\App\Models\Product::ProductPrice($slug, $product->id,$product->variant_id,($product->sale_price ?? $product->price)), $store->id)  !!}</span>
        @if (!empty($product->sale_price))
            <span class="product_orignal_price"> {!! currency_format_with_sym(\App\Models\Product::ProductPrice($slug, $product->id,$product->variant_id,$product->price), $store->id)  !!}</span>
        @endif
    @else 
        @if (!empty($product->sale_price) && $product->sale_price < $product->price)
            <span class="product_final_price"> {!! currency_format_with_sym(\App\Models\Product::ProductPrice($slug, $product->id,$product->variant_id,$product->sale_price), $store->id)  !!}</span>
            <span class="product_orignal_price"> {!! currency_format_with_sym(\App\Models\Product::ProductPrice($slug, $product->id,$product->variant_id,$product->price), $store->id)  !!}</span>
        @else
            <span class="product_final_price"> {!! currency_format_with_sym(\App\Models\Product::ProductPrice($slug, $product->id,$product->variant_id,$product->price), $store->id)  !!}</span>
        @endif
    @endif
@else
<span class="product_final_price"> {!! currency_format_with_sym(0, $store->id)  !!}</span>
@endif