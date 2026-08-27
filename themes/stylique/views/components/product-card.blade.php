@props(['product', 'store'])

<div class="flex product-card flex-col h-full bg-white rounded-lg overflow-hidden shadow-md hover:shadow-lg transition-shadow duration-300">
        {!! \App\Models\Product::productSalesPage( $store->slug, $product->id, false) !!}
        <div class="relative">
            <a href="{{ url($store->slug . '/product/' . $product->slug) }}">
                <img src="{{ get_file($product->cover_image_path)}}"
                width="400" height="400"
                alt="{{ $product->name }}"
                loading="lazy"
                class="w-full h-80 object-cover object-top product-image" />
            </a>
        
            <div class="absolute top-4 right-4">
                <button class="wishlist-btn wishbtn-globaly {{ $product->in_whishlist ? 'active' : ''}} relative flex bg-white rounded p-2 border" 
                        product_id="{{ $product->id }}"
                        in_wishlist="{{ $product->in_whishlist ? 'remove' : 'add' }}">
                        <i class="far fa-heart text-gray-600"></i>
                    </button>
                    {!! \App\Models\Product::actionLinks( $store, $product) !!}
                </div>
            </div>
        <div class="h-full flex flex-col">
            <div class="p-4 pb-0 flex-1">
                <h3 class="font-medium mb-1 text-lg">
                <a href="{{ url($store->slug . '/product/' . $product->slug) }}">{{ $product->name }}</a>
                </h3>
                <p class="text-gray-500 text-sm mb-2 line-clamp-2">
                {{ Str::limit(strip_tags($product->description), 100) }}
                </p>
            </div>
            <div class="p-4 pt-0 flex justify-between items-center">
                @if ($product->variant_product == 0)
                <div>
                    <span class="font-bold text-lg">
                    {!! \App\Models\Product::getProductPrice($product, $store) !!}
                    </span>
                </div>
                @else
                <span class="font-bold text-lg">
                    <ins>{{ __('In Variant') }}</ins>
                </span>
                @endif
                <button class="addtocart-btn btn addcart-btn-globaly bg-[var(--primary-color)] text-white px-3 py-1 rounded hover:bg-opacity-90 transition-colors duration-300"
                tabindex="0"
                product_id="{{ $product->id }}"
                variant_id="0"
                qty="1">
                <span>{{ __('Add to cart') }}</span>
                </button>
                {!! \App\Models\Product::ProductcardButton($store->slug, $product) !!}
            </div>
        </div>
</div>