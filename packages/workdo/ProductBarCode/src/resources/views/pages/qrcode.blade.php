
@php
    $store = \App\Models\Store::find(getCurrenctStoreId($slug));
    $product = \App\Models\Product::find($product_id);
    $settings = \App\Models\Setting::where('store_id', $store->id)
                   ->pluck('value', 'name')
                   ->toArray();

        $barcode_type = isset($settings['barcode_type']) ? $settings['barcode_type'] : 'PHARMA';
        $barcode_color = isset($settings['barcode_color']) ? $settings['barcode_color'] : 'black';
@endphp
<td class="py-4 px-4" data-label="qrcode">
    @php
        if ($barcode_type == 'UPCA') {
            $code = str_pad($product->product_code, 8, '0', STR_PAD_LEFT);
        } else {
            $code = $product->product_code ?? null;
        }
    @endphp
    @if($code)
    {!! DNS1D::getBarcodeHTML($code, $barcode_type, 1, 30, $barcode_color) !!}
    @endif
</td>
