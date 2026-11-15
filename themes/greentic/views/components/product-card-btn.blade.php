@props(['product', 'store'])

<button aria-label="{{ $product->in_whishlist ? __('Remove from Wishlist') : __('Add to Wishlist') }}" class="wishlist-btn wishbtn-globaly {{ $product->in_whishlist ? 'active' : ''}} h-8 w-8 md:h-10 md:w-10 md:text-base text-sm flex items-center justify-center bg-gray-100 hover:bg-gray-200 text-gray-600 hover:text-rose-500 p-2 rounded-full transition-all duration-200 border border-gray-200" product_id="{{ $product->id }}"  in_wishlist="{{ $product->in_whishlist ? 'remove' : 'add' }}"> <i class="fas fa-heart"></i>
</button>