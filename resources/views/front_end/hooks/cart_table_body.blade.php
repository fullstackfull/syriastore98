@if(module_is_active('ProductBarCode'))
@php
    $enable_product_barcode = \App\Models\Utility::GetValueByName('enable_product_barcode', $store->id);
@endphp
    @if (isset($enable_product_barcode) && $enable_product_barcode == 'on')
        @include('product-bar-code::pages.qrcode', ['product_id' => $product->product_id ?? null ,'slug' => $slug ?? null])
    @endif
@endif
