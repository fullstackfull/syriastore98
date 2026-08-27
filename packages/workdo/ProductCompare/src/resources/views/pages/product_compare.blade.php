<style>
.product-title a {
    display: -webkit-box;
    -webkit-line-clamp: 1;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.description {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>

<div class="p-6">
    @if($compare_list->isEmpty())
        <!-- Empty State -->
        <div class="text-center py-8">
            <div class="w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-balance-scale text-gray-400 text-2xl"></i>
            </div>
            <h3 class="text-lg font-semibold text-gray-900 mb-2">{{ __('No Products to Compare') }}</h3>
            <p class="text-gray-500">{{ __('Add products to compare their features and prices') }}</p>
        </div>
    @else
        <!-- Header -->
        <div class="text-center mb-6">
            <h3 class="text-xl font-bold text-gray-900 mb-2">{{ __('Product Comparison') }}</h3>
            <p class="text-gray-600">{{ __('Compare features and prices side by side') }}</p>
        </div>

        <!-- Products Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-h-96 overflow-y-auto">
            @foreach($compare_list as $compare)
                @php
                    $product = \App\Models\Product::find($compare->product_id);
                @endphp
                <div class="bg-white border border-gray-200 rounded-lg p-4 hover:shadow-md transition-all duration-300">
                    <!-- Product Image -->
                    <div class="relative mb-4">
                        <a href="{{ url($store->slug.'/product/'.$product->slug) }}" class="block">
                            <div class="aspect-w-16 aspect-h-10 bg-gray-100 rounded-lg overflow-hidden">
                                <img alt="{{ $product->name }}" src="{{ get_file($product->cover_image_path) }}" class="w-full h-32 object-contain hover:scale-105 transition-transform duration-300">
                            </div>
                        </a>
                        <!-- Remove Button -->
                        <a href="{{ route('product.compare.remove',[$store->slug ,$compare->id ?? '']) }}" class="absolute top-2 right-2 w-8 h-8 bg-red-500 hover:bg-red-600 text-white rounded-full flex items-center justify-center transition-colors duration-200">
                            <i class="fas fa-times text-sm"></i>
                        </a>
                    </div>

                    <!-- Product Info -->
                    <div class="text-center">
                        <!-- Title -->
                        <h4 class="font-semibold text-gray-900 mb-2">
                            <a href="{{ url($store->slug.'/product/'.$product->slug) }}" class="product-title hover:text-primary transition-colors duration-200">
                                {{ $product->name }}
                            </a>
                        </h4>

                        <!-- Rating -->
                        <div class="flex items-center justify-center gap-2 mb-2">
                            <div class="flex">
                                @for ($i = 0; $i < 5; $i++)
                                    <i class="fas fa-star text-sm {{ $i < $product->average_rating ? 'text-yellow-400' : 'text-gray-300' }}"></i>
                                @endfor
                            </div>
                            <span class="text-sm text-gray-500">({{ $product->average_rating }}.0)</span>
                        </div>

                        <!-- Price -->
                        <div class="text-lg font-bold text-primary mb-2">
                            @if ($product->variant_product == 0)
                                {!! \App\Models\Product::getProductPrice($product, $store) !!}
                            @else
                                {{ __('In Variant') }}
                            @endif
                        </div>

                        <!-- Description -->
                        <p class="text-sm text-gray-600 mb-3 description">
                            {!! $product->description !!}
                        </p>

                        <!-- Stock Status -->
                        <div class="mb-3">
                            @if ($product->variant_product == 1)
                                <span class="inline-block px-3 py-1 bg-blue-100 text-blue-800 text-xs font-medium rounded-full">{{ __('In Variant') }}</span>
                            @else
                                @if ($product->track_stock == 0)
                                    <span class="inline-block px-3 py-1 text-xs font-medium rounded-full {{ $product->stock_status == 'out_of_stock' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                                        {{ $product->stock_status == 'out_of_stock' ? __('Out of stock') : ($product->stock_status == 'on_backorder' ? __('On Backorder') : __('In stock')) }}
                                    </span>
                                @else
                                    <span class="inline-block px-3 py-1 text-xs font-medium rounded-full {{ $product->product_stock <= ($admin['out_of_stock_threshold'] ?? 0) ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                                        {{ $product->product_stock <= ($admin['out_of_stock_threshold'] ?? 0) ? __('Out of stock') : __('In stock') }}
                                    </span>
                                @endif
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
