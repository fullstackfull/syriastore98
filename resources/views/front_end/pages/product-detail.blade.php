@extends('front_end.layouts.app')
@section('page-title')
    {{ __('Products') }}
@endsection

@section('content')

    @if ($themeSettings['product_page_status'] && $themeSettings['product_page_status'] == '1')

    <section class="lg:pt-20 pt-10 bg-gray-50">
        <div class="md:container w-full mx-auto px-4">
            <div class="bg-white rounded-lg shadow-sm overflow-hidden">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 p-4 md:p-8">
                    <!-- Product Images -->
                     <div>
                        <!-- Main Image -->
                        <div class="swiper main-image-slider mb-4">
                            <div class="swiper-wrapper">
                                @if (!empty($product->Sub_image($product->id)['data']))
                                    @foreach ($product->Sub_image($product->id)['data'] as $item)
                                    <div class="swiper-slide">
                                        <div class="relative pt-[65%] border bg-gray-100 rounded-lg overflow-hidden">
                                        <img src="{{ get_file($item->image_path) }}" alt="Organic Vegetable Box"
                                            class="w-full h-full absolute top-0 left-0 object-contain" />
                                        </div>
                                    </div>
                                    @endforeach
                                @else
                                    <div class="swiper-slide">
                                        <div class="relative pt-[65%] border bg-gray-100 rounded-lg overflow-hidden">
                                        <img src="{{ get_file($product->cover_image_path) }}" alt="Organic Vegetable Box"
                                            class="w-full h-full absolute top-0 left-0 object-contain" />
                                        </div>
                                    </div>
                                @endif
                            </div>
                            <!-- Add Navigation -->
                            <div class="arrow-wrapper">
                            <div class="swiper-button-next pdp-arrow">
                            </div>
                            <div class="swiper-button-prev pdp-arrow">
                            </div>
                            </div>
                        </div>
                        <!-- Thumbnail Images -->
                        <div class="swiper thumbnail-slider">
                            <div class="swiper-wrapper">
                                @if (!empty($product->Sub_image($product->id)['data']))
                                    @foreach ($product->Sub_image($product->id)['data'] as $item)
                                    <div class="swiper-slide">
                                        <div
                                        class="relative pt-[90%] thumb-image rounded-lg overflow-hidden border bg-gray-100 cursor-pointer">
                                        <img src="{{ get_file($item->image_path) }}" alt="Organic Vegetable Box - View 1"
                                            class="absolute top-0 left-0 w-full h-full object-contain" />
                                        </div>
                                    </div>
                                    @endforeach
                                @else
                                <div class="swiper-slide">
                                    <div class="relative pt-[90%] thumb-image rounded-lg overflow-hidden border bg-gray-100 cursor-pointer">
                                    <img src="{{ get_file($product->cover_image_path) }}" alt="Organic Vegetable Box - View 2"
                                        class="absolute top-0 left-0 w-full h-full object-contain" />
                                    </div>
                                </div>
                                @endif
                            
                            
                            </div>
                        </div>
                    </div>
                    <!-- Product Info -->
                    <div>
                        @if(isset($product->label) && !empty($product->label))
                            <div class="flex items-center mb-3">
                                <span class="bg-primary/10 text-primary text-xs font-bold px-2 py-1 rounded-md mr-2">
                                    {{ ucfirst(optional($product->label)->name ?? '') }}
                                </span>
                            </div>
                        @endif
                        <h2 class="font-bold text-2xl md:text-3xl mb-2">{{ $product->name }}</h2>

                        {{-- <div class="flex flex-wrap items-center gap-2 mb-4">
                            <div class="flex text-yellow-400">
                                @for ($i = 0; $i < 5; $i++)
                                    <i class="fas fa-star {{ $i < $product->average_rating ? 'text-warning' : '' }}"></i>
                                @endfor
                            </div>
                            <span class="text-gray-600 text-sm">{{ $product->average_rating }}.0 /<span>{{ __('5.0') }}</span></span>
                        </div> --}}

                        <div class="md:mb-6 mb-4">
                            <div class="flex items-baseline md:mb-4 mb-2">
                                <span class="font-bold sm:text-3xl text-xl text-primary-dark mr-3 product-price-amount">
                                    {!! \App\Models\Product::getProductPrice($product, $store) !!}
                                </span>
                            </div>
                             @if ($product->track_stock == 0 && $product->stock_status == 'out_of_stock')
                                <p class="stock_status_color text-red-600 flex items-center mb-1" product_id="{{ $product->id }}"
                                    variant_id="{{ $product->default_variant_id }}" qty="1">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round" class="h-4 w-4 mr-1">
                                        <path d="M22 11.08V12a10 10 0 border-t border-b py-6 mb-6 1-5.93-9.14" />
                                        <path d="m9 11 3 3L22 4" />
                                    </svg>
                                    <span class="stock_status">{{ __('Out of Stock') }}</span>
                                </p>
                             @else
                                <p class="stock_status_color text-green-600 flex items-center" product_id="{{ $product->id }}"
                                    variant_id="{{ $product->default_variant_id }}" qty="1">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round" class="h-4 w-4 mr-1">
                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                                        <path d="m9 11 3 3L22 4" />
                                    </svg>
                                    <span class="stock_status">{{ __('In Stock') }}</span>
                                </p>
                            @endif
                        </div>

                        <div class="border-t border-b md:py-6 py-4 md:mb-6 mb-4">
                            {!! $product->description !!}
                        </div>

                        <div class="md:mb-6 mb-4">
                            <form class="variant_form">
                                <div class="mb-5 quantity-wrp quantity-select flex gap-3 items-center">
                                    <h3 class="font-semibold">{{ __('quantity:') }}</h3>
                                    <div class="flex items-center">
                                        <button type="button"
                                            class="quantity-decrement change_price w-10 h-10 bg-gray-100 flex items-center justify-center ltr:rounded-l-md rtl:rounded-r-md border ltr:border-r-0 rtl:border-l-0 border-gray-300 hover:bg-gray-200" data-product="{{ $product->id}}">
                                            <i class="fas fa-minus"></i>
                                        </button>
                                        <input type="text" class="quantity w-14 h-10 border border-gray-300 text-center product-quantity" data-cke-saved-name="quantity"
                                            name="quantity" value="1" min="1" max="10" data-product="{{ $product->id}}">
                                        <button type="button"
                                            class="quantity-increment change_price w-10 h-10 bg-gray-100 flex items-center justify-center ltr:rounded-r-md rtl:rounded-l-md border ltr:border-l-0 rtl:border-r-0 border-gray-300 hover:bg-gray-200" data-product="{{ $product->id}}">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                    @include('front_end.common.product.variant')
                            </form>
                            @if ($product->track_stock == 0 && $product->stock_status == 'out_of_stock')
                            @else
                            <button class="btn-primary max-w-sm w-full addtocart-btn btn addcart-btn  addcart-btn-globaly price-wise-btn product_var_option" product_id="{{ $product->id }}" variant_id="{{ $product->default_variant_id }}" qty="1">
                                    <i class="fa-solid fa-cart-shopping"></i>
                                   {{ __('Add to cart') }}
                            </button>
                          
                                {!! \App\Models\Product::ProductcardButton($slug, $product) !!}
                            @endif
                        </div>
                        <div class="product-hook">
                            @include('front_end.hooks.product_detail_info_button')
                        </div>
                        <div class="flex flex-col sm:flex-row items-start sm:items-center gap-4 text-sm text-gray-600">
                            @include('front_end.common.product.sale_counter')
                        </div>
                    </div>
                </div>

                <!-- Product Tabs -->
                @include('front_end.theme_common_table')
                
            </div>
        </div>
    </section>

    @endif
     @include('front_end.hooks.product_detail_slider')
    {{-- tab section. --}}
    @include('front_end.pages.bestseller')
@endsection

