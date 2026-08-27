@php
$is_checkout_login_required = \App\Models\Utility::GetValueByName('is_checkout_login_required', $store->id);
@endphp

<div class="md:container w-full mx-auto px-4">

    <div class="flex flex-col lg:flex-row gap-8">
        <!-- Left Side - Cart Items -->
        <div class="lg:w-2/3">
            <!-- Cart Header (Desktop Only) -->
            <div class="overflow-x-auto border border-gray-200 rounded-md">
                <table class="md:w-full min-w-[650px] border-collapse">
                    <thead>
                        <th class="py-3 px-4 text-left rtl:text-right font-semibold text-white bg-primary rounded-st-md">
                            {{ __('Product') }}</th>
                        @include('front_end.hooks.cart_table_head')
                        <th class="py-3 px-4 text-center font-semibold text-white bg-primary">{{ __('Quantity') }}</th>
                        <th class="py-3 px-4 text-center font-semibold text-white bg-primary">{{ __('Price') }}</th>                            
                        <th class="py-3 px-4 text-center font-semibold text-white bg-primary rounded-et-md">
                                {{ __('Subtotal') }}</th>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @if (!empty($response->data->cart_total_product))
                        @foreach ($response->data->product_list as $product)
                        <tr>
                            <td class="py-4 px-4">
                                <div class="flex items-center gap-4">
                                    <button class="text-gray-500 hover:text-red-500 transition-all duration-300 remove_item_from_cart text-red-600"
                                        title="Remove item" data-id="{{ $product->cart_id }}" data-product-id = "{{ $product->product_id }}"  data-variant-id = "{{ $product->variant_id }}">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                                            <path d="M3 6h18"></path>
                                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"></path>
                                            <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                            <line x1="10" x2="10" y1="11" y2="17"></line>
                                            <line x1="14" x2="14" y1="11" y2="17"></line>
                                        </svg>
                                    </button>
                                    <div class="h-20 w-20 flex-shrink-0 border bg-gray-100 rounded-lg">
                                        <img src="{{ get_file($product->image) }}" alt="cart-image"
                                            class="h-full w-full object-contain rounded-lg" />
                                    </div>
                                    <div>
                                        <h3 class="font-medium mb-1">
                                            <a href="{{ url($slug.'/product/'. getProductSlug($product->product_id)) }}"
                                                class="hover:text-primary transition-all duration-300">{{ $product->name }}</a>
                                        </h3>
                                        <p class="text-sm text-gray-500">
                                            @if ($product->variant_id != 0)
                                            {!! \App\Models\ProductVariant::variantlist($product->variant_id) !!}
                                            @endif
                                        </p>
                                    </div>
                                </div>
                            </td>
                            @include('front_end.hooks.cart_table_body')
                            <td class="py-4 px-4 text-center">
                                <div class="col-span-2 flex items-center justify-between md:justify-center">
                                    <div class="flex items-center border rounded-md text-sm">
                                        <button class="px-2 py-1 text-gray-500 hover:bg-gray-100 text-sm quantity-decrement change-cart-globaly" cart-id="{{ $product->cart_id }}" quantity_type="decrease" data-product-id = "{{ $product->product_id }}" data-variant-id = "{{ $product->variant_id }}">
                                            <i class="fas fa-minus"></i>
                                        </button>
                                        <input type="text" value="{{ $product->qty }}" min="01" id="cart_list_quantity{{ $product->qty }}" class="w-8 text-center p-1 focus:ring-0 border-s border-e outline-none quantity" />
                                        <button class="px-2 py-1 text-gray-500 hover:bg-gray-100 text-sm quantity-increment change-cart-globaly" cart-id="{{ $product->cart_id }}" quantity_type="increase" data-product-id = "{{ $product->product_id }}" data-variant-id = "{{ $product->variant_id }}">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                            </td>
                            {!! \App\Models\Product::ManageCartListPrice($product, $store) !!}
                        </tr>
                        @endforeach
                        @else
                            <tr>
                                <td colspan="100%" class="text-center py-4 text-gray-500">
                                    {{ __('You have no items in your shopping cart.') }}
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>

            <!-- Cart Actions -->
            <div class="flex flex-wrap gap-4 justify-between md:mt-8 mt-6">
                <a href="{{ route('page.product-list', $store->slug) }}"
                    class="inline-flex gap-2 items-center text-primary hover:text-primary-dark continue-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        class="h-4 w-4">
                        <path d="m15 18-6-6 6-6" />
                    </svg>
                    {{ $themeSettings['cart_product_button_text'] ?? __('Continue Shopping') }}
                </a>
                <button class="inline-flex gap-2 items-center text-gray-600 hover:text-red-500 transition-all duration-300 empty_cart">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        class="h-4 w-4">
                        <path d="M3 6h18" />
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
                        <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                    </svg>
                    {{ $themeSettings['cart_clear_button_text'] ?? __('Clear Cart') }}
                </button>
            </div>
        </div>

        <!-- Right Side - Cart Summary -->
        <div class="lg:w-1/3">
            <div class="bg-gray-50 md:p-6 p-4 rounded-md border">
                <h2 class="font-heading font-bold text-xl md:mb-6 mb-4">
                    {{ $themeSettings['cart_summary'] ?? __('Order Summary') }}</h2>

                <!-- Summary Items -->
                <div class="space-y-3 mb-6">
                    <div class="flex justify-between">
                        <span>{{ $themeSettings['cart_subtotal'] ?? __('Subtotal') }}</span>
                        <span class="font-semibold">{{ currency_format_with_sym(($response->data->final_price ?? 0) , $store->id) ?? SetNumberFormat($response->data->final_price ?? ($response->data->sub_total ?? 0)) }}</span>
                    </div>
                    {{-- <div class="flex justify-between">
                        <span>{{ $themeSettings['cart_shipping'] ?? __('Shipping') }}</span>
                        <span class="font-semibold">Free</span>
                    </div> --}}
                    @php
                        $tax_option = \App\Models\TaxOption::where('store_id', $store->id)->pluck('value', 'name')->toArray();
                        $is_inclusive_tax = isset($tax_option['price_type']) && $tax_option['price_type'] == 'inclusive' && isset($tax_option['shop_price']) && $tax_option['shop_price'] == 'including';
                    @endphp
                    @if(($response->data->tax_price ?? 0) > 0 || $is_inclusive_tax)
                    <div class="flex justify-between">
                        <span>{{ $themeSettings['cart_tax'] ?? __('Tax') }}@if($is_inclusive_tax)@endif</span>
                        <span class="font-semibold">{{ currency_format_with_sym(($response->data->tax_price ?? 0) , $store->id) ?? SetNumberFormat($response->data->tax_price) }}</span>
                    </div>
                    @endif
                    @php
                        $final = $response->data->sub_total+$response->data->tax_price;
                    @endphp
                    <div class="pt-3 border-t border-gray-200 flex justify-between font-bold text-lg">
                        <span>{{ $themeSettings['cart_total'] ?? __('Total') }}</span>
                        <span class="text-primary-dark">{{ currency_format_with_sym(($final ?? 0) , $store->id) ?? SetNumberFormat($final) }}</span>
                    </div>
                </div>

                <!-- Coupon Code -->
                {{-- <div class="md:mb-6 mb-4">
                    <label for="coupon"
                        class="block mb-2 font-medium">{{ $themeSettings['cart_coupon_code'] ?? __('Coupon Code') }}</label>
                    <div class="flex">
                        <input type="text" id="coupon" placeholder="Enter coupon code"
                            class="form-input rounded-tr-none rounded-br-none" />
                        <button class="bg-primary hover:bg-primary-dark text-white px-4 py-2 rounded-r-md transition">
                            {{ $themeSettings['cart_apply'] ?? __('Apply') }}
                        </button>
                    </div>
                </div> --}}

                 <!-- Checkout Button -->
                @if($is_checkout_login_required == 'on' && !auth('customers')->user())
                <a href="{{ route('customer.login', $store->slug) }}" class="btn-primary w-full">
                    {{ $themeSettings['cart_checkout'] ?? __('Proceed to Checkout') }}
                </a>
                @else
                <!-- Checkout Button -->
                <a href="{{ route('checkout', $slug) }}" class="btn-primary w-full">
                    {{ $themeSettings['cart_checkout'] ?? __('Proceed to Checkout') }}
                </a>
                @endif
            </div>
        </div>
    </div>
</div>