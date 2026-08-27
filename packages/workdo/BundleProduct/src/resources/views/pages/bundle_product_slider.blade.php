    @if (count($bundle_products_data) > 0)
        <section class="lg:py-20 py-10 bg-white"
            style="position: relative;@if (isset($option) && $option->is_hide == 1) opacity: 0.5; @else opacity: 1; @endif"
            data-index="{{ $option->order ?? '' }}" data-id="{{ $option->order ?? '' }}"
            data-value="{{ $option->id ?? '' }}" data-hide="{{ $option->is_hide ?? '' }}"
            data-section="{{ $option->section_name ?? '' }}" data-store="{{ $option->store_id ?? '' }}"
            data-theme="{{ $option->theme_id ?? '' }}">
            <div class="custome_tool_bar"></div>
            <div class="md:container w-full mx-auto px-4">
                <div class="flex flex-col md:flex-row items-center justify-between gap-2 lg:mb-8 mb-4">
                    <h2 class="text-2xl lg:text-3xl font-bold text-gray-900"
                        id="bundle_products_preview">
                        {{ __('Bundle Products') }}
                    </h2>
                    <a href="{{ route('page.product-list', ['storeSlug' => $slug]) }}"
                        class="text-primary font-medium hover:underline"
                        id="bundle_product_button_preview">
                        {{ __('View All Products') }}
                    </a>
                </div>

                <div class="swiper bundle-product-swiper">
                    <div class="swiper-wrapper pb-5">
                        @foreach ($bundle_products_data as $product)
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
    @once
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                new Swiper(".bundle-product-swiper", {
                    slidesPerView: 1.2,
                    spaceBetween: 16,
                    breakpoints: {
                        640: { slidesPerView: 2.2 },
                        768: { slidesPerView: 3 },
                        1024: { slidesPerView: 4 },
                    },
                    navigation: {
                        nextEl: ".swiper-button-next.product-arrow",
                        prevEl: ".swiper-button-prev.product-arrow",
                    },
                });
            });
        </script>
    @endonce

