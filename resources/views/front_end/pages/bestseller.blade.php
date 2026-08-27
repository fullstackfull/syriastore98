@if(isset($themeSettings['bestseller_product_status']) && $themeSettings['bestseller_product_status'] == 1)
    <section class="lg:py-20 py-10 bg-gray-50"
        style="position: relative;@if (isset($option) && $option->is_hide == 1) opacity: 0.5; @else opacity: 1; @endif"
        data-index="{{ $option->order ?? '' }}" data-id="{{ $option->order ?? '' }}" data-value="{{ $option->id ?? '' }}"
        data-hide="{{ $option->is_hide ?? '' }}" data-section="{{ $option->section_name ?? '' }}"
        data-store="{{ $option->store_id ?? '' }}" data-theme="{{ $option->theme_id ?? '' }}">
        <div class="custome_tool_bar"></div>
        <div class="md:container w-full mx-auto px-4">
            <div class="flex flex-col md:flex-row items-center justify-between gap-2 lg:mb-8 mb-4">
                <h2 class="text-2xl lg:text-3xl font-bold text-gray-900"
                    id="{{ $themeSettings['bestseller_product_title'] ?? '' }}_preview">
                    {{ $themeSettings['bestseller_product_title'] ?? __('You Might Also Like') }}
                </h2>
                <a href="{{ route('page.product-list', ['storeSlug' => $slug]) }}"
                    class="text-primary font-medium hover:underline"
                    id="{{ $themeSettings['bestseller_product_button_text'] ?? '' }}_preview">
                    {{ $themeSettings['bestseller_product_button_text'] ?? __('View All products') }}</a>
            </div>
            <div class="swiper product-swiper">
                <div class="swiper-wrapper pb-5">
                    <!-- Product Card 1 -->
                    @foreach ($products as $product)
                        <div class="swiper-slide">
                            <x-product-card :store="$store" :product="$product" />
                        </div>
                    @endforeach
                </div>
                <div class="arrow-wrapper">
                    <div class="swiper-button-next product-arrow"></div>
                    <div class="swiper-button-prev product-arrow"></div>
                </div>
            </div>
        </div>
    </section>
@endif