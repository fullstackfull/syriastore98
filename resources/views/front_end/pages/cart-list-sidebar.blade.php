<div class="flex flex-col h-full">
    <div class="flex justify-between items-center p-4 border-b">
        <h2 class=" font-bold text-xl">{{ __('Your Cart') }} ({{ $response->data->cart_total_product }})</h2>
        <button id="cart-close" class="text-2xl hover:text-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                class="h-6 w-6">
                <path d="M18 6 6 18" />
                <path d="m6 6 12 12" />
            </svg>
        </button>
    </div>
    <div class="overflow-y-auto flex-grow p-4 space-y-4">
        @if (!empty($response->data->cart_total_product))
            @foreach ($response->data->product_list as $product)
        <div class="flex gap-3 border-b pb-4">
            <a href="{{ url($slug.'/product/'. getProductSlug($product->product_id)) }}" class="w-20 h-24 rounded-md overflow-hidden bg-gray-100 border p-3">
                <img src="{{ get_file($product->image) }}" alt="Artisan Sourdough Bread"
                    class="object-contain h-full w-full" />
            </a>
            <div class="flex-1">
                <div class="flex items-center justify-between gap-3 mb-1">
                    <h4 class="font-semibold">{{ $product->name }}</h4>
                    <button class="remove_item remove_item_from_cart" type="button" data-id="{{ $product->cart_id }}" data-product-id = "{{ $product->product_id }}" data-variant-id = "{{ $product->variant_id }}">
                        <i class="fa-solid fa-trash text-red-600"></i>
                    </button>
                </div>
                <p class="text-sm text-gray-600">@if ($product->variant_id != 0)
                        {!! \App\Models\ProductVariant::variantlist($product->variant_id) !!}
                    @endif</p>
                <div class="flex justify-between items-center mt-2 gap-2 flex-wrap">
                    <div class="qty-spinner flex items-center border rounded-md max-w-[90px] w-full">
                        <button
                            class="quantity-decrement flex-1 px-2 py-1 text-gray-500 hover:text-primary outline-none change-cart-globaly" cart-id="{{ $product->cart_id }}" quantity_type="decrease" data-product-id = "{{ $product->product_id }}" data-variant-id = "{{ $product->variant_id }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round" class="h-3 w-3">
                                <path d="M5 12h14" />
                            </svg>
                        </button>
                        <input type="text" class="quantity text-sm p-2 flex-1 form-input border-none leading-none text-center" name="quantity" data-cke-saved-name="quantity" value="{{ $product->qty ?? '1' }}" min="1" max="100" value="{{ $product->qty }}" min="01" id="cart_list_sidebar_quantity_{{ $product->variant_id ?? '-' }}_{{ $product->qty }}">
                        <button
                            class="quantity-increment flex-1 px-2 py-1 text-gray-500 hover:text-primary outline-none change-cart-globaly"  cart-id="{{ $product->cart_id }}" quantity_type="increase" data-product-id = "{{ $product->product_id }}" data-variant-id = "{{ $product->variant_id }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round" class="h-3 w-3">
                                <path d="M5 12h14" />
                                <path d="M12 5v14" />
                            </svg>
                        </button>
                    </div>
                    <span class=" font-bold text-primary-dark flex gap-2">
                        {!! \App\Models\Product::ManageCartPrice($product, $store) !!}
                    </span>
                </div>
            </div>
        </div>
        @endforeach
        @endif
       
    </div>
    <div class="border-t p-4">
        <div class="flex justify-between pb-2">
            <span>{{ __('Subtotal') }}</span>
            <span class=" font-bold">{{ currency_format_with_sym(($response->data->final_price ?? 0), $store->id) ?? SetNumberFormat($response->data->final_price ?? ($response->data->sub_total ?? 0)) }}</span>
        </div>
        <div class="flex justify-between pb-2">
            <span>{{ __('Tax') }}</span>
            <span class=" font-bold">{{ currency_format_with_sym(($response->data->tax_price ?? 0), $store->id) ?? SetNumberFormat($response->data->tax_price) }}</span>
        </div>
        @php
            $final = ($response->data->sub_total + $response->data->tax_price) ?? 0;
        @endphp
        <div class="flex justify-between pb-3 text-lg font-bold">
            <span class="">{{ __('Total') }}</span>
            <span class=" text-primary-dark">{{ currency_format_with_sym($final, $store->id) ?? SetNumberFormat($final) }}</span>
        </div>
        <a href="{{ route('page.cart',$slug) }}" class="btn-primary w-full mb-3">
            {{ __('Proceed to Checkout') }}
        </a>
        <a href="{{ route('page.product-list',$slug) }}" id="continue-shopping" class="btn-outline w-full">
            {{ __('Continue Shopping') }}
        </a>
    </div>
</div>