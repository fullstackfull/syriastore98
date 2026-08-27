@props(['product', 'store', 'checkout_setting', 'button_text', 'button_link'])

@if ($checkout_setting == 'one_click_checkout')
    <button class="w-full btn-primary addtocart-btn btn checkout-button one-click-checkout" tabindex="0" product_id="{{ $product->id }}"
        variant_id="0" qty="1">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z">
            </path>
        </svg>
        <span> {{ $button_text ?? 'Add To Cart' }} </span>
        
    </button>
@elseif ($checkout_setting == 'one_page_checkout')
    <button class="w-full btn-primary addtocart-btn btn checkout-button on-page-checkout" tabindex="0" product_id="{{ $product->id }}"
        variant_id="0" qty="1" data-url="{{ route('on.page.checkout', $slug) }}" data-toggle="tooltip"
        title="{{ __('Checkout View') }}" data-title="{{ __('Checkout View') }}">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z">
            </path>
        </svg>
        <span>{{ __('Add to cart') }}</span>
    </button>
@endif