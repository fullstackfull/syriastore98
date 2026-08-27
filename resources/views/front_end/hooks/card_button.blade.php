@php
$store = getStore($slug);
@endphp
@if (module_is_active('QuickCheckout'))
    @php
        $enable_quick_checkout =  \App\Models\Utility::GetValueByName('enable_quick_checkout', $store->id);
    @endphp
    @if (isset($enable_quick_checkout) && $enable_quick_checkout == 'on')
        @include('quick-checkout::theme.button', ['product_slug' => $product->slug ?? null, 'slug' => $slug ?? null,$store->theme_id])
    @endif
@endif
@if(module_is_active('SkipCart'))
    @php
        $enable_skip_cart =  \App\Models\Utility::GetValueByName('enable_skip_cart', $store->id);
    @endphp
    @if(isset($enable_skip_cart) && $enable_skip_cart == 'on')
        @include('skip-cart::theme.skip_cart_button', ['product_slug' => $product->slug ?? null, 'slug' => $slug ?? null,$store->theme_id])
    @endif
@endif
@if(module_is_active('PreOrder'))
    @php
        $customer = auth('customers')->user() ?? null;
        $pre_order_detail = \Workdo\PreOrder\app\Models\PreOrder::where('store_id', $store->id)->first();
    @endphp
    @if(isset($customer) && isset($product) && isset($pre_order_detail) && $pre_order_detail->enable_pre_order == 'on')
        @if ($product->variant_product == 0 && $product->product_stock <= 0)
            @include('pre-order::pages.button', ['product' => $product ?? null, 'slug' => $slug ?? null, 'pre_order_detail' => $pre_order_detail ?? null, 'pre_order_button' => false,$store->theme_id])
        @endif
    @endif
@endif

