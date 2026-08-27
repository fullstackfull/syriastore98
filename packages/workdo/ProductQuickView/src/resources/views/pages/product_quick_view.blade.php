@if (isset($product))
    <style>
        .current-image { display: block; }
        .hidden-image { display: none; }
    </style>

    @php
        $latestSales = \App\Models\Product::productSalesTag($store->slug, $product->id);
        $images = $product->Sub_image($product->id)['data'];
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 p-6 max-h-[80vh] overflow-y-auto">
        <!-- Image Section -->
        <div class="relative">
            <!-- Main Image -->
            <div class="relative bg-gray-100 rounded-lg overflow-hidden aspect-square">
                @if (!empty($latestSales))
                    <div class="absolute top-3 left-3 bg-red-500 text-white px-3 py-1 text-xs font-semibold rounded-full z-10">
                        {{ __('Sale!') }}
                    </div>
                @endif
                @if(count($images) > 0)
                    @foreach ($images as $index => $item)
                        <img src="{{ asset($item->image_path) }}" alt="{{ $product->name }}" 
                             class="w-full h-full object-contain {{ $index === 0 ? 'current-image' : 'hidden-image' }}" 
                             data-index="{{ $index }}">
                    @endforeach
                @endif
            </div>
            
            <!-- Navigation Arrows -->
            @if(count($images) > 1)
                <button class="absolute left-2 top-1/2 transform -translate-y-1/2 w-10 h-10 bg-white bg-opacity-80 hover:bg-opacity-100 rounded-full flex items-center justify-center shadow-md transition-all duration-200" onclick="previousImage()">
                    <i class="fas fa-chevron-left text-gray-600"></i>
                </button>
                <button class="absolute right-2 top-1/2 transform -translate-y-1/2 w-10 h-10 bg-white bg-opacity-80 hover:bg-opacity-100 rounded-full flex items-center justify-center shadow-md transition-all duration-200" onclick="nextImage()">
                    <i class="fas fa-chevron-right text-gray-600"></i>
                </button>
            @endif
            
            <!-- Thumbnails -->
            @if(count($images) > 1)
                <div class="flex gap-2 mt-4 overflow-x-auto">
                    @foreach ($images as $index => $item)
                        <button class="flex-shrink-0 w-16 h-16 bg-gray-100 rounded-lg overflow-hidden border-2 {{ $index === 0 ? 'border-primary' : 'border-transparent' }}" 
                                onclick="showImage({{ $index }})" data-thumb="{{ $index }}">
                            <img src="{{ asset($item->image_path) }}" alt="{{ $product->name }}" class="w-full h-full object-contain">
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- Product Info -->
        <div class="space-y-4">
            <!-- Category -->
            @if($product->ProductData)
                <div class="text-sm font-medium text-primary uppercase tracking-wide">
                    {{ $product->ProductData->name }}
                </div>
            @endif
            
            <!-- Title -->
            <h2 class="text-2xl font-bold text-gray-900">{{ $product->name }}</h2>
            
            <!-- Price -->
            <div class="flex items-center gap-3">
                <span class="text-2xl font-bold text-primary">{{ currency_format_with_sym($product->sale_price, $store->id) }}</span>
                @if($product->price > $product->sale_price)
                    <span class="text-lg text-gray-500 line-through">{{ currency_format_with_sym($product->price, $store->id) }}</span>
                @endif
            </div>
            
            <!-- Description -->
            <div class="text-gray-600 leading-relaxed">
                {{ strip_tags($product->description) }}
            </div>

            @if (!($product->track_stock == 0 && $product->stock_status == 'out_of_stock'))
                <!-- Quantity -->
                <div class="flex items-center justify-between">
                    <span class="font-medium text-gray-700">{{ __('Quantity') }}</span>
                    <div class="flex items-center border border-gray-300 rounded-lg overflow-hidden">
                        <button class="quantity-decrease px-3 py-2 bg-gray-100 hover:bg-gray-200 transition-colors duration-200" type="button">
                            <i class="fas fa-minus text-sm"></i>
                        </button>
                        <input type="number" class="quantity-input w-16 px-3 py-2 text-center border-0 focus:ring-0" value="1" min="1" max="100">
                        <button class="quantity-increase px-3 py-2 bg-gray-100 hover:bg-gray-200 transition-colors duration-200" type="button">
                            <i class="fas fa-plus text-sm"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Add to Cart -->
                <button class="w-full bg-primary hover:bg-primary-dark text-white py-3 px-6 rounded-lg font-semibold transition-all duration-300 flex items-center justify-center gap-2 addtocart-btn addcart-btn-globaly" product_id="{{ $product->id }}">
                    <i class="fas fa-shopping-cart"></i>
                    <span>{{ __('Add to cart') }}</span>
                </button>
            @else
                <div class="bg-yellow-100 border border-yellow-400 text-yellow-800 px-4 py-3 rounded-lg">
                    {{ __('This product is currently out of stock.') }}
                </div>
            @endif
        </div>
    </div>

    <script>
        let currentImageIndex = 0;
        const totalImages = {{ count($images) }};
        
        function showImage(index) {
            // Hide all images
            document.querySelectorAll('[data-index]').forEach(img => {
                img.classList.add('hidden-image');
                img.classList.remove('current-image');
            });
            
            // Show selected image
            const selectedImg = document.querySelector(`[data-index="${index}"]`);
            if (selectedImg) {
                selectedImg.classList.remove('hidden-image');
                selectedImg.classList.add('current-image');
            }
            
            // Update thumbnails
            document.querySelectorAll('[data-thumb]').forEach(thumb => {
                thumb.classList.remove('border-primary');
                thumb.classList.add('border-transparent');
            });
            
            const selectedThumb = document.querySelector(`[data-thumb="${index}"]`);
            if (selectedThumb) {
                selectedThumb.classList.add('border-primary');
                selectedThumb.classList.remove('border-transparent');
            }
            
            currentImageIndex = index;
        }
        
        function nextImage() {
            const nextIndex = (currentImageIndex + 1) % totalImages;
            showImage(nextIndex);
        }
        
        function previousImage() {
            const prevIndex = (currentImageIndex - 1 + totalImages) % totalImages;
            showImage(prevIndex);
        }
        
        $(document).ready(function () {
            $('.quantity-decrease').click(function () {
                let input = $(this).siblings('.quantity-input');
                let val = parseInt(input.val());
                if (val > 1) {
                    input.val(val - 1);
                    $('.addtocart-btn').attr('qty', val - 1);
                }
            });
            
            $('.quantity-increase').click(function () {
                let input = $(this).siblings('.quantity-input');
                let val = parseInt(input.val());
                if (val < 100) {
                    input.val(val + 1);
                    $('.addtocart-btn').attr('qty', val + 1);
                }
            });
            
            $('.quantity-input').on('input', function () {
                let val = Math.max(1, Math.min(100, parseInt($(this).val()) || 1));
                $(this).val(val);
                $('.addtocart-btn').attr('qty', val);
            });
        });
    </script>
@else
    <div class="text-center py-12">
        <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fas fa-exclamation-triangle text-gray-400 text-xl"></i>
        </div>
        <h4 class="text-lg font-semibold text-gray-900 mb-2">{{ __('Product Not Found!') }}</h4>
        <p class="text-gray-500">{{ __('The requested product could not be found.') }}</p>
    </div>
@endif
