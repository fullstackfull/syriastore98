<div class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm sticky top-[20px]">
    <h2 class="font-bold text-xl mb-6">{{ __('Order Summary') }}: <span class="checkout-cartcount">[{{ $response->data->cart_total_product }}]</span></h2>

    <!-- Order Items -->
    <div class="space-y-4 mb-6">
        @if (!empty($response->data->cart_total_product))
            @foreach ($response->data->product_list as $product)
                <div class="flex items-start gap-3 pb-3 border-b">
                    <input type="hidden" id="product_id" value="{{ $product->product_id }}" >
                    <input type="hidden" id="product_qty" value="{{ $product->qty }}">
                    <div class="h-20 w-20 flex-shrink-0 border bg-gray-100 rounded-lg">
                        <a href="{{ route('page.product', [$slug, getProductSlug($product->product_id)]) }}">
                            <img src="{{ get_file($product->image) }}" alt="cart-image" class="h-full w-full object-contain rounded-lg">
                        </a>
                    </div>
                    <div class="flex-grow">
                        <h3 class="font-medium mb-1"><a href="{{ route('page.product', [$slug, getProductSlug($product->product_id)]) }}">{{ $product->name }}</a></h3>
                        <p class="text-sm text-gray-500">
                            @if ($product->variant_id != 0)
                            {!! \App\Models\ProductVariant::variantlist($product->variant_id) !!}
                            @endif
                        </p>
                        <p class="text-sm text-gray-500">{{ $product->qty . ' x ' . currency_format_with_sym($product->final_price / $product->qty, $store->id) }}</p>
                    </div>
                    <div class="price">
                        {!! \App\Models\Product::ManageCheckoutPrice($product, $store) !!}
                    </div>
                </div>
            @endforeach
        @else
            <div class="flex items-start gap-3 pb-3 border-b">
                {{ __('You have no items in your shopping cart.') }}
            </div>
        @endif
    </div>

    <!-- Shipping -->
    @if(isset($plan) && $plan->shipping_method == 'on')
        <div class="pb-4 mb-4 border-b">
            <div class="flex">
                {{-- @if(auth('customers')->user())
                    <div class="chnage_address">
                        <a href="{{ route('my-account.index',$slug) }}">{{ __('Change Address')}}</a>
                    </div>
                @endif --}}

                <div class="shiping-type">
                    <h5 id="shipping_lable" class="d-none font-semibold mb-4 text-lg">{{__('Select Shipping')}}</h5>
                    <div class="radio-group change_shipping space-y-3" id="shipping_location_content" >

                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Coupon Code -->
    <div class="pb-4 mb-4 border-b">
        <div class="flex">
            <input class="p-2 form-input ltr:rounded-tr-none rtl:rounded-tl-none  ltr:rounded-br-none rtl:rounded-bl-none coupon_code" placeholder="{{ __('Enter coupon code') }}" name="coupon" type="text" value="">
            <a class="bg-primary hover:bg-primary-dark text-white px-4 py-2 ltr:rounded-r-md rtl:rounded-l-md transition checkout-btn apply_coupon">
                {{ __('Apply') }}
            </a>
        </div>
    </div>

    <!-- Order Totals -->
    <div class="space-y-2 mb-6">
        <div class="flex justify-between">
            <span>{{ __('Subtotal') }}</span>
            <input type="hidden" value="{{  $response->data->final_price ?? ($response->data->sub_total ?? 0) }}" id="subtotal">
            <span class="font-semibold subtotal">{{  currency_format_with_sym(($response->data->final_price ?? ($response->data->sub_total ?? 0)), $store->id) ?? SetNumberFormat($response->data->final_price ?? ($response->data->sub_total ?? 0)) }}</span>
        </div>
        @if(isset($plan) && $plan->shipping_method == 'on')
            <div class="flex justify-between">
                <span>{{ __('Shipping') }}</span>
                <span class="CURRENCY"></span> <span class="font-semibold shipping_final_price shipping_test_price"> {{ currency_format_with_sym(0, $store->id) ?? SetNumberFormat(0) }} </span>
            </div>
        @endif
        @stack('clubPointPriceShow')
        <input type="hidden" value="{{ $response->data->total_coupon_price ?? 0 }}" id="coupon_amount">
        <div class="flex justify-between">
            <span>{{ __('Coupon') }}</span>
            <span class="font-semibold discount_amount_currency"> - {{ currency_format_with_sym(($response->data->total_coupon_price ?? 0), $store->id) ?? SetNumberFormat($response->data->total_coupon_price ?? 0) }} </span>
        </div>
        <div class="flex justify-between tax-line">
            <span>{{ __('Tax') }}</span>
            <span @if (isset($plan) && $plan->shipping_method == 'on') class="CURRENCY" @else class="" @endif></span>
            <span class="font-semibold final_tax_price"> {{ currency_format_with_sym(($response->data->tax_price ?? 0), $store->id) ?? SetNumberFormat($response->data->tax_price ?? 0) }} </span>
        </div>
        <div class="flex justify-between text-lg font-bold pt-2 border-t">
            <span>{{ __('Total') }}</span>
            <span class="text-primary-dark final_amount_currency shipping_total_price" final_total="{{ $response->data->total_sub_price  }}">{{ currency_format_with_sym(($response->data->total_sub_price ?? 0), $store->id) ?? SetNumberFormat($response->data->total_sub_price) }}</span>
        </div>
    </div>

    @include('front_end.hooks.checkout_list')
    <!-- Terms -->
    {{-- <div class="md:mb-6 mb-4">
        <div class="checkbox flex items-start gap-2">
            <input type="checkbox" id="terms"
                class="rounded border-gray-300 text-primary focus:ring-primary mt-1"
                required />
            <label for="terms" class="flex-1 text-s                        m">
                I agree to the <a href="terms-condition.html"
                    class="text-primary hover:underline">Terms & Conditions</a>, <a
                    href="privacy-policy.html" class="text-primary hover:underline">Privacy
                    Policy</a>, and <a href="shipping-return.html"
                    class="text-primary hover:underline">Refund Policy</a>
            </label>
        </div>
    </div> --}}

    <!-- Checkout Button -->
    {{-- <input type="hidden" class="payment_type payment_types" id="payment_type" name="payment_type" value="{{ old('payment_type') }}"> --}}
    <input type="hidden" class="method_id" id="method_id" name="method_id" value="{{ old('method_id') }}">
    <button class="btn-primary w-full continue-btn place_order_submit payfast_form" id="payfast_form" type="submit">
        {{ $themeSettings['checkout_button'] ?? __('Complete Order') }}
    </button>

    <!-- Security Information -->
    {{-- <div class="flex items-center justify-center mt-4 text-sm text-gray-500">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
            stroke-linejoin="round" class="h-4 w-4 mr-2 flex-shrink-0">
            <rect width="18" height="11" x="3" y="11" rx="2" ry="2" />
            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
        </svg>
        Secure checkout - Your data is protected
    </div> --}}
</div>