@if (isset($themeSettings['offer_status']) && $themeSettings['offer_status'] == 1)
    <section class="lg:pb-20 pb-10">
        <div class="container mx-auto px-4">
            <h2 class="text-2xl lg:text-3xl font-bold text-gray-900 lg:mb-8 mb-4 md:text-start text-center">
                {{ $themeSettings['offer_title'] ?? __('Limited Time Offer') }}
            </h2>
            <div class="swiper offer-swiper">
                <div class="swiper-wrapper">
                    @foreach ($all_products as $product)
                        <div class="swiper-slide">
                            <div class="h-full card">
                                <div class="product-content h-full flex flex-col lg:flex-row items-center">
                                    {{-- Product Image --}}
                                    <div class="h-full lg:w-auto w-full flex items-center justify-center bg-gray-100 p-3">
                                        <a href="{{ url($slug . '/product/' . $product->slug) }}"
                                           class="max-w-[250px] w-full block">
                                            <img src="{{ get_file($product->cover_image_path) }}"
                                                 class="object-contain h-full w-full"
                                                 alt="{{ $product->name }}">
                                        </a>
                                    </div>

                                    {{-- Product Content --}}
                                    <div class="flex-1 w-full sm:text-start text-center lg:p-6 p-4">
                                        <h3 class="text-lg font-medium mb-3">
                                            <a href="{{ url($slug . '/product/' . $product->slug) }}">{{ $product->name }}</a>
                                        </h3>

                                        {{-- Rating --}}
                                        <div class="flex sm:justify-start justify-center items-center mb-2">
                                            <div class="flex">
                                                @for ($i = 0; $i < 5; $i++)
                                                    <svg class="w-4 h-4 {{ $i < round($product->average_rating) ? 'text-yellow-400' : 'text-gray-300' }}"
                                                         fill="currentColor" viewBox="0 0 20 20">
                                                        <path
                                                            d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                                    </svg>
                                                @endfor
                                            </div>
                                            <span class="text-xs text-gray-500 ml-1">
                                                {{ number_format($product->average_rating, 1) }} / 5.0
                                            </span>
                                        </div>

                                        {{-- Price --}}
                                        <div class="flex items-center mb-3">
                                            @if ($product->variant_product == 0)
                                                <span class="text-lg font-semibold">{!! \App\Models\Product::getProductPrice($product, $store) !!}</span>
                                            @else
                                                <span class="text-sm text-gray-500 line-through ml-2">{{ __('In Variant') }}</span>
                                            @endif
                                        </div>

                                        {{-- Color Swatches (Optional) --}}
                                        @if (isset($product->colors) && count($product->colors))
                                            <div class="color-variant-swatch mb-3">
                                                <div class="radio-buttons flex flex-wrap sm:justify-start justify-center items-center gap-1">
                                                    @foreach ($product->colors as $color)
                                                        <div class="button p-0.5">
                                                            <label
                                                                class="color-var h-5 w-5 relative border rounded-full block cursor-pointer"
                                                                title="{{ $color['name'] ?? 'Color' }}"
                                                                style="background-color: {{ $color['code'] ?? '#000' }}"></label>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif

                                        {{-- Time Counter --}}
                                        <div class="time-counter flex align-center text-center mb-3">
                                            <div class="deal-timeline inline-flex items-center py-2 px-3 gap-3 rounded bg-primary/10 text-primary">
                                                <div class="counter flex flex-wrap items-center justify-center text-center relative gap-1">
                                                    <span class="count count-days font-medium leading-none pe-4">305</span>
                                                </div>
                                                <div class="counter flex flex-wrap items-center justify-center text-center relative gap-1">
                                                    <span class="count count-hours font-medium leading-none pe-4">00</span>
                                                </div>
                                                <div class="counter flex flex-wrap items-center justify-center text-center relative gap-1">
                                                    <span class="count count-minites font-medium leading-none pe-4">20</span>
                                                </div>
                                                <div class="counter flex flex-wrap items-center justify-center text-center relative gap-1">
                                                    <span class="count count-seconds font-medium leading-none">40</span>
                                                </div>
                                            </div>
                                        </div>

                                        {{-- Add to Cart Button --}}
                                        <div class="offer-btn-wrapper">
                                            <button class="btn-primary addtocart-btn btn addcart-btn-globaly" product_id="{{ $product->id }}">
                                                <span>{{ __('Add to cart') }}</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Navigation Arrows --}}
                <div class="arrow-wrapper">
                    <div class="swiper-button-next offer-arrow"></div>
                    <div class="swiper-button-prev offer-arrow"></div>
                </div>
            </div>
        </div>
    </section>
@endif
