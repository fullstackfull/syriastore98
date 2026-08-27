<div id="products-container" class="grid-view grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5 xl:gap-6">
                
@foreach ($products as $product)
<x-product-card :store="$store" :product="$product" />
@endforeach
</div>

<div class="flex justify-center md:mt-8 mt-5 pagination-wrapper">
    <div class="flex items-center gap-2">
        {{-- Previous Button --}}
        @if($products->onFirstPage())
            <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-500 cursor-not-allowed">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="h-4 w-4">
                    <path d="m15 18-6-6 6-6" />
                </svg>
            </span>
        @else
            <button onclick="product_page_filter({{ $products->currentPage() - 1 }})"
                class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-500 hover:bg-gray-50">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="h-4 w-4">
                    <path d="m15 18-6-6 6-6" />
                </svg>
            </button>
        @endif

        @php
            $current = $products->currentPage();
            $last = $products->lastPage();
        @endphp

        @if($current <= 2)
            {{-- Show first 3 pages: 1 2 3 ... 15 --}}
            @for($i = 1; $i <= min(3, $last); $i++)
                @if($i == $current)
                    <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-primary bg-primary text-white">{{ $i }}</span>
                @else
                    <button onclick="product_page_filter({{ $i }})" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">{{ $i }}</button>
                @endif
            @endfor
            @if($last > 3)
                <span class="p-1 h-8 w-8 flex items-center justify-center text-gray-500">...</span>
                <button onclick="product_page_filter({{ $last }})" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">{{ $last }}</button>
            @endif
        @elseif($current >= $last - 1)
            {{-- Show last 3 pages: 1 ... 13 14 15 --}}
            <button onclick="product_page_filter(1)" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">1</button>
            @if($last > 3)
                <span class="p-1 h-8 w-8 flex items-center justify-center text-gray-500">...</span>
            @endif
            @for($i = max(1, $last - 2); $i <= $last; $i++)
                @if($i == $current)
                    <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-primary bg-primary text-white">{{ $i }}</span>
                @else
                    <button onclick="product_page_filter({{ $i }})" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">{{ $i }}</button>
                @endif
            @endfor
        @else
            {{-- Show middle pages: 1 ... 7 8 ... 15 --}}
            <button onclick="product_page_filter(1)" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">1</button>
            <span class="p-1 h-8 w-8 flex items-center justify-center text-gray-500">...</span>
            @for($i = $current; $i <= $current + 1; $i++)
                @if($i == $current)
                    <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-primary bg-primary text-white">{{ $i }}</span>
                @else
                    <button onclick="product_page_filter({{ $i }})" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">{{ $i }}</button>
                @endif
            @endfor
            <span class="p-1 h-8 w-8 flex items-center justify-center text-gray-500">...</span>
            <button onclick="product_page_filter({{ $last }})" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">{{ $last }}</button>
        @endif

        {{-- Next Button --}}
        @if($products->hasMorePages())
            <button onclick="product_page_filter({{ $products->currentPage() + 1 }})"
                class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-500 hover:bg-gray-50">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="h-4 w-4">
                    <path d="m9 18 6-6-6-6" />
                </svg>
            </button>
        @else
            <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-500 cursor-not-allowed">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="h-4 w-4">
                    <path d="m9 18 6-6-6-6" />
                </svg>
            </span>
        @endif
    </div>
</div>