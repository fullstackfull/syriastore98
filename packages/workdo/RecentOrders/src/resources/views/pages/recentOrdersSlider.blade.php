<section class="py-12 lg:py-16 relative">
    <div class="container mx-auto px-4">
        <div class="flex items-center justify-between mb-8">
            <h2 class="text-2xl md:text-3xl font-bold text-gray-900 dark:text-white">{{ __('Recent Orders') }}</h2>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6 recent-order-slider">
            @foreach ($ProductData as $item)
                <x-product-card :product="$item" :store="$store" />
            @endforeach
        </div>
    </div>
</section>

