<section class="section-spacing collection-sec">
                <div class="md:container mx-auto px-4 w-full">
                    <div class="text-center items-center md:mb-10 mb-6 flex flex-wrap gap-3 justify-center lg:justify-between">
                    <h2 class="md:text-4xl text-3xl font-bold">
                        {!! $themeSettings['bestseller_title'] ?? 'Explore Our Products' !!}
                    </h2>

                </div>

                    {{-- Tab Content --}}
                    <div class="tab-content-wrapper">
                        {{-- All Products Tab --}}
                        <div class="tab-content active" id="all-tab">
                            <div class="swiper featured-products">
                                <div class="swiper-wrapper">
                                    @foreach(collect($products)->reverse()->take(10) as $product)
                                        <div class="swiper-slide">
                                            <x-product-card :store="$store" :product="$product" />
                                        </div>
                                    @endforeach
                                </div>
                                <div class="arrow-wrapper">
                                    <div class="swiper-button-next"></div>
                                    <div class="swiper-button-prev"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>