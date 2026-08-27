@if ($products && $products->isNotEmpty())
<section class="py-12 lg:py-16 bg-gray-50 dark:bg-gray-900">
    <div class="container mx-auto px-4">
        <!-- Header -->
        <div class="flex flex-col md:flex-row items-center justify-between gap-4 mb-8">
            <div class="text-center md:text-left">
                <h2 class="text-2xl lg:text-3xl font-bold text-gray-900 dark:text-white mb-2">
                    {{ __('Digital Products') }}
                </h2>
                <p class="text-gray-600 dark:text-gray-400 text-sm">
                    {{ __('Instant downloads and digital solutions') }}
                </p>
            </div>
            <a href="{{ route('collections', $store->slug) }}" 
               class="inline-flex items-center gap-2 text-[var(--primary-color)] hover:text-[var(--primary-color)]/80 font-medium text-sm transition-colors">
                {{ __('View All Products') }}
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>
            </a>
        </div>

        <!-- Products Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
            @foreach ($products as $product)
                <div class="digital-product-card">
                    <x-product-card :store="$store" :product="$product" />
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif