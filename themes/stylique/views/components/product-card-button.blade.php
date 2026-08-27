@props(['product', 'store', 'checkout_setting', 'button_text', 'button_link'])

@if ($checkout_setting == 'one_click_checkout')
    <button class="addtocart-btn btn one-click-checkout  bg-[var(--primary-color)] text-white px-3 py-1 rounded hover:bg-opacity-90 transition-colors duration-300" tabindex="0" product_id="{{ $product->id }}"
        variant_id="0" qty="1">
        <span> {{ $button_text ?? 'Add To Cart' }} </span>
        
    </button>
@elseif ($checkout_setting == 'one_page_checkout')
    <button class="addtocart-btn btn on-page-checkout  bg-[var(--primary-color)] text-white px-3 py-1 rounded hover:bg-opacity-90 transition-colors duration-300" tabindex="0" product_id="{{ $product->id }}"
        variant_id="0" qty="1" data-url="{{ route('on.page.checkout', $slug) }}" data-toggle="tooltip"
        title="{{ __('Checkout View') }}" data-title="{{ __('Checkout View') }}">
        <span>{{ __('Add to cart') }}</span>
    </button>
@endif