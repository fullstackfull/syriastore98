@props(['product', 'store', 'checkout_setting', 'button_text', 'button_link'])

@if ($checkout_setting == 'one_click_checkout')
    <button class="addtocart-btn btn checkout-button one-click-checkout cart-btn h-8 w-8 md:h-10 md:w-10 md:text-base text-sm flex items-center justify-center bg-primary hover:bg-primary-dark text-white p-2 rounded-full transition-all duration-200 shadow-sm hover:shadow" tabindex="0" product_id="{{ $product->id }}"
        variant_id="0" qty="1" data-toggle="tooltip"
        title="{{  $button_text ?? 'Add To Cart' }}" data-title="{{ $button_text ?? __('Add To Cart') }}">
         <i class="fas fa-shopping-cart"></i>        
    </button>
@elseif ($checkout_setting == 'one_page_checkout')
    <button class="addtocart-btn btn checkout-button on-page-checkout cart-btn h-8 w-8 md:h-10 md:w-10 md:text-base text-sm flex items-center justify-center bg-primary hover:bg-primary-dark text-white p-2 rounded-full transition-all duration-200 shadow-sm hover:shadow" tabindex="0" product_id="{{ $product->id }}"
        variant_id="0" qty="1" data-url="{{ route('on.page.checkout', $slug) }}" data-toggle="tooltip"
        title="{{ __('Checkout View') }}" data-title="{{ __('Checkout View') }}" >
        <i class="fas fa-shopping-cart"></i>
        <span>{{ __('Add to cart') }}</span>
    </button>
@endif