
<div class="swiper-slide">
    <div
        class="bg-white product-card rounded-lg shadow-lg overflow-hidden transition duration-300 group flex flex-col border border-gray-100 h-full">
        <a href="{{ route('page.product', [$slug, $product->slug]) }}" class="relative">
            <div class="h-60 relative overflow-hidden product-img p-3">
                <img src="{{ asset($product->cover_image_path ?? 'themes/greentic/assets/images/product-01.png') }}" alt="Fresh Organic Onion"
                    class="w-full h-full object-contain group-hover:scale-105 transition duration-500">
                    {!! \App\Models\Product::actionLinks( $store, $product) !!}
                    <div class="absolute top-4 left-4 flex flex-col gap-2">
                    <span
                        class="bg-accent text-white text-xs font-bold px-2 py-1 rounded-md shadow-sm">{{ optional($product->label)->name ?? '' }}</span>
                </div>
            </div>
        </a>
        <div class="p-4 flex flex-col justify-between flex-1 gap-4">
            <div class="flex flex-col">
                @include('front_end.hooks.product_rating')
                <h3
                    class="font-semibold text-gray-800 mb-2 group-hover:text-primary transition-colors text-lg line-clamp-1">
                    <a href="{{ route('page.product', [$slug, $product->slug]) }}" class="hover:text-primary transition">{{ $product->name }}</a>
                </h3>
                <p class="text-sm text-gray-600 line-clamp-2">{!! strip_tags($product->description) !!}</p>
            </div>
            <div
                class="flex justify-between items-center mt-auto border-t border-gray-100 pt-3 gap-1">
                <div class="flex flex-wrap gap-1 items-center">
                    @if($product->variant_product == 0)
                        {!! \App\Models\Product::getProductPrice($product, $store) !!}
                    @else
                        <span class="product_final_price">{{ __('In Variant') }}</span>
                    @endif
                </div>
                <div class="flex gap-2">
                    <button class="wishlist-btn wishbtn-globaly {{ $product->in_whishlist ? 'active' : ''}} h-8 w-8 md:h-10 md:w-10 md:text-base text-sm flex items-center justify-center bg-gray-100 hover:bg-gray-200 text-gray-600 hover:text-rose-500 p-2 rounded-full transition-all duration-200 border border-gray-200" 
                        product_id="{{ $product->id }}"
                        in_wishlist="{{ $product->in_whishlist ? 'remove' : 'add' }}">
                        <i class="fas fa-heart"></i>
                    </button>
                    {!! \App\Models\Product::ProductcardButton($slug, $product) !!}
                    <button class="addtocart-btn btn addcart-btn-globaly cart-btn h-8 w-8 md:h-10 md:w-10 md:text-base text-sm flex items-center justify-center bg-primary hover:bg-primary-dark text-white p-2 rounded-full transition-all duration-200 shadow-sm hover:shadow"
                        product_id="{{ $product->id }}" variant_id="0">
                        <i class="fas fa-shopping-cart"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>