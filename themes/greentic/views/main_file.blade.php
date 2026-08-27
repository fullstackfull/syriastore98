@extends('front_end.layouts.app')

@section('page-title')
{{ __('Home Page') }}
@endsection

@section('content')

<main>
    @if (isset($themeSettings['slider_status']) && $themeSettings['slider_status'] == '1')
    <section class="pt-10 lg:pt-16 home-banner-sec bg-gray-50">
        <div class="md:container w-full mx-auto px-4">
            <div class="swiper p-0 m-0 home-banner-slider rounded-lg">
                <div class="swiper-wrapper">
                      @foreach (json_decode($themeSettings['slider_repeater'],true) as $slider_repeater)
                    <div class="swiper-slide flex">
                        <div class="home-banner-img relative w-full">
                            <img src="{{ get_file($slider_repeater['image']) }}" alt="banner-image"
                                class="w-full h-full absolute inset-0 object-cover object-left rtl:scale-x-[-1]" loading="lazy">
                            <div
                                class="banner-content-wrapper md:max-w-[500px] max-w-[400px] w-full relative lg:py-32 md:py-20 py-20 pt-10 ltr:md:pl-10 rtl:md:pr-10 ltr:md:pr-0 rtl:md:pl-0 px-4">
                                <span class="text-gray-900 font-semibold mb-4 inline-block text-sm uppercase">{!! $slider_repeater['small_text'] ?? '' !!}</span>
                                <h2
                                    class="text-gray-900 font-bold text-2xl md:text-3xl lg:text-5xl lg:mb-6 mb-4 leading-tight">
                                    {!!  $slider_repeater['big_text'] ?? '' !!}
                                </h2>
                                <p class="text-dark md:text-lg text-base lg:mb-7 mb-5">
                                    {!! $slider_repeater['content'] ?? '' !!}
                                </p>
                                <a href="{{ url($slider_repeater['button_link'] ?? '/' . $slug . '/product-list') }}" class="btn-primary">
                                    {{ $slider_repeater['button_text'] ?? __('Shop Now') }}
                                </a>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
                <div class="home-arrow-wrapper absolute flex ltr:flex-row rtl:flex-row-reverse gap-3 lg:bottom-7 bottom-5 ltr:md:left-10 rtl:md:right-10 ltr:left-4 rtl:right-4">
                    <!-- Prev Arrow -->
                    <div class="swiper-button-prev"></div>
                    <!-- Next Arrow -->
                    <div class="swiper-button-next"></div>
                </div>
            </div>
        </div>
    </section>
    @endif

    @if (isset($themeSettings['category_status']) && $themeSettings['category_status'] == '1')
    <section class="lg:py-20 py-10 bg-gray-50">
        <div class="md:container w-full mx-auto px-4">
            <div class="flex flex-col md:flex-row items-center justify-between gap-2 lg:mb-6 mb-2">
                <h2 class="text-2xl lg:text-3xl font-bold text-gray-900">{!! $themeSettings['category_title'] ?? __('Product Categories') !!}</h2>
                <a href="{{ route('collections', $store->slug) }}" class="text-primary font-medium flex items-center gap-2">{{ $themeSettings['category_button_text'] ?? __('View All Categories') }} <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="swiper category-swiper py-2">
                <div class="swiper-wrapper">
                    @foreach ($categories as $category)
                        <div class="swiper-slide">
                            <div class="card text-center p-4 cursor-pointer flex flex-col items-center justify-center gap-3 transition-all duration-300 rounded-md hover:bg-gray-100 hover:scale-105">
                                <a href="{{ url($store->slug . '/' . $category->slug) }}" class="flex items-center flex-col gap-2 w-full">
                                    <div class="category-img md:w-14 md:h-14 h-10 w-10">
                                        <img src="{{ get_file($category->icon_path) }}" alt="Vegetables"
                                            class="w-full h-full object-cover" loading="lazy">
                                    </div>
                                    <h3 class="font-semibold">{{ $category->name }}</h3>
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="swiper-button-prev"></div>
                <div class="swiper-button-next"></div>
            </div>
        </div>
    </section>
    @endif

    @if (isset($themeSettings['bestseller_product_status']) && $themeSettings['bestseller_product_status'] == '1')
    <section class="lg:pb-16 pb-5 bg-gray-50">
        <div class="md:container w-full mx-auto px-4">
            <div class="flex flex-col md:flex-row items-center justify-between gap-2 lg:mb-8 mb-4">
                <h2 class="text-2xl lg:text-3xl font-bold text-gray-900"> {!! $themeSettings['bestseller_product_title'] ?? __('bestseller product') !!}</h2>
                <a href="{{ route('page.product-list', $store->slug) }}" class="text-primary font-medium flex items-center gap-2">{{ $themeSettings['bestseller_product_button_text'] ?? __('View All products') }} <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="swiper product-swiper">
                <div class="swiper-wrapper pb-5">
                    @foreach ($bestseller_products as $product)
                        <div class="swiper-slide">
                        <x-product-card :store="$store" :product="$product" />
                        </div>
                    @endforeach
                </div>
                <div class="flex justify-center">
                    <div class="swiper-button-prev"></div>
                    <div class="swiper-button-next"></div>
                </div>
            </div>
        </div>
    </section>
    @endif

    @if (isset($themeSettings['top_category_status']) && $themeSettings['top_category_status'] == '1')
    <section class="lg:pb-20 pb-10 bg-gray-50">
        <div class="md:container w-full mx-auto px-4">
            <div class="flex flex-col md:flex-row items-center justify-between gap-2 lg:mb-8 mb-4">
                <h2 class="text-2xl lg:text-3xl font-bold text-gray-900">{!! $themeSettings['top_category_title'] ?? __('Shop Our Collections') !!}</h2>
                <a href="{{ route('collections', $store->slug) }}" class="text-primary font-medium flex items-center gap-2">
                    {{ $themeSettings['top_category_button_text'] ?? __('View All Collections') }} <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                @foreach ($top_categories as $category)
                <div class="collection-card group relative overflow-hidden rounded-lg p-4 md:pt-[20%] pt-[10%]">
                    <img src="{{ get_file($category->image_path) }}" alt="Organic Pantry Staples"
                        class="absolute inset-0 w-full h-full object-cover z-0 transition-transform duration-500 rtl:scale-x-[-1]" loading="lazy">
                    <div class="relative flex flex-col justify-end gap-4 h-full max-w-[220px] w-full">
                        <h3 class="text-lg font-semibold text-white line-clamp-2 !leading-[1.4]">{{ $category->name }}</h3>
                        <a href="{{ url($store->slug . '/' . $category->slug) }}" class="btn-primary w-fit px-3 py-2 gap-2">
                            {{ $themeSettings['top_category_card_btn'] ?? __('Shop Now') }} <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
                @endforeach
            </div>

        </div>
    </section>
    @endif

    @if (isset($themeSettings['logos_status']) && $themeSettings['logos_status'] == '1')
    <section class="lg:pb-20 pb-10 bg-gray-50">
        <div class="md:container w-full mx-auto px-4">
            <h2 class="text-2xl lg:text-3xl font-bold text-gray-900 lg:mb-8 mb-4 ltr:md:text-start rtl:md:text-end text-center">{!! $themeSettings['logos_title'] ?? __('Trusted By Leading Brands') !!}</h2>
            <div class="swiper logo-swiper">
                <div class="swiper-wrapper">
                    @foreach ($brands as $brand)
                    <div class="swiper-slide">
                        <a href="javascript:void(0)" class="block group transition-all duration-300"
                            aria-label="Visit Apple website">
                            <div
                                class="card bg-white rounded-lg p-4 md:h-28 h-20 flex items-center justify-center border transition-all duration-300 hover:border-primary">
                                <div class="md:h-20 md:w-20 h-12 w-12 mx-auto">
                                    <img src="{{ get_file($brand->logo) }}" alt="partner-logo"
                                        class="object-contain w-full h-full" loading="lazy">
                                </div>
                            </div>
                        </a>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
    @endif

    @if (isset($themeSettings['featured_product_status']) && $themeSettings['featured_product_status'] == '1')
    <section id="product-tabs-sec" class="lg:pb-16 pb-5 bg-gray-50 product-tabs-sec">
        <div class="md:container w-full mx-auto px-4">
            <div class="flex flex-col md:flex-row items-center justify-between gap-3 lg:mb-8 mb-4">
                <h2 class="text-2xl lg:text-3xl font-bold text-gray-900">{!! $themeSettings['featured_product_title'] ?? __('Featured Product') !!}</h2>
                <div class="md:w-auto w-full overflow-x-auto">
                    <div
                        class="flex gap-2 sm:gap-4 text-sm font-medium whitespace-nowrap sm:justify-center sm:pb-0 pb-2">
                        <button data-tab="all-category-product" class="tab-btn px-4 py-2 outline-none rounded-md bg-primary border-primary border text-white shrink-0">
                            {{ __('All Products')}}</button>
                        @foreach ($top_categories as $category)
                        <button data-tab="{{ $category->name.'-category-product-'. $category->id }}" class="tab-btn px-4 py-2 outline-none rounded-md bg-white border-primary border text-primary shrink-0">{{ $category->name }}</button>
                        @endforeach
                    </div>
                </div>
            </div>
            <!-- Tabs -->
            <!-- Tabs Content -->
            <div class="tab-content" id="all-category-product">
                <div class="swiper product-tab-swiper">
                    <div class="swiper-wrapper pb-5">

                        @foreach ($all_products as $product)
                            <div class="swiper-slide">
                            <x-product-card :store="$store" :product="$product" />
                            </div>
                        @endforeach
                    </div>
                    <!-- Swiper Nav Buttons -->
                    <div class="flex justify-center">
                        <div class="swiper-button-prev"></div>
                        <div class="swiper-button-next"></div>
                    </div>
                </div>
            </div>
            @foreach ($top_categories as $category)
            <div class="tab-content" id="{{ $category->name.'-category-product-'. $category->id }}">
                <div class="swiper product-tab-swiper">
                    <div class="swiper-wrapper pb-5">                        
                        @foreach ($category->product_details->take(10) as $product)
                            <div class="swiper-slide">
                            <x-product-card :store="$store" :product="$product" />
                            </div>
                        @endforeach
                    </div>
                    <!-- Swiper Nav Buttons -->
                    <div class="flex justify-center">
                        <div class="swiper-button-prev"></div>
                        <div class="swiper-button-next"></div>
                    </div>
                </div>
            </div>
            @endforeach
    </section>
    @endif

    @if (isset($themeSettings['more_offer_status']) && $themeSettings['more_offer_status'] == '1')
    <section class="pb-10 lg:pb-20 bg-gray-50">
        <div class="md:container w-full mx-auto px-4 relative rounded-xl overflow-hidden">
            <div class="relative z-[1] lg:py-24 md:py-16 py-6 md:px-10 px-4 rounded-lg offer-content overflow-hidden">
                <img src="{{ get_file($themeSettings['more_offer_image'] ?? '') }}" alt="Offer Background"
                    class="w-full h-full object-cover absolute inset-0 z-[-1] ltr:object-left rtl:object-right rtl:scale-x-[-1]" loading="lazy">
                <div class="text-white max-w-[600px] w-full relative z[-1]">
                    <span class="text-base md:text-lg font-semibold mb-3 block text-gray-900">{{ $themeSettings['more_offer_title'] ?? __('Save Up To 30% OFF') }}</span>
                    <h2 class="text-xl md:text-2xl lg:text-[42px] font-semibold md:mb-5 mb-4 text-gray-900 max-w-[420px] w-full">
                        {!! $themeSettings['more_offer_sub_title'] ?? __('Big Deals trending of Week & fresh products') !!}
                    </h2>
                    <a href="{{ $themeSettings['more_offer_button_link'] ?? route('page.product-list', ['storeSlug' => $store->slug]) }}" class="btn-primary">{{ $themeSettings['more_offer_button_text'] ?? __('Shop Now') }}</a>
                </div>
            </div>
        </div>
    </section>
    @endif
    @include('front_end.hooks.product_list')
    @if (isset($themeSettings['testimonial_status']) && $themeSettings['testimonial_status'] == '1')
    <section class="bg-gray-50 lg:pb-20 pb-10">
        <div class="md:container w-full mx-auto px-4">
            <h2 class="text-2xl lg:text-3xl font-bold text-gray-900 md:mb-8 mb-4 ltr:md:text-start rtl:md:text-end text-center">{!! $themeSettings['testimonial_title'] ?? __('What Our Customers Say') !!}</h2>
            <div class="swiper testimonial-swiper pb-10">
                <div class="swiper-wrapper">
                    @foreach ($testimonials as $testimonial)
                    <div class="swiper-slide">
                        <div class="card p-4 h-full">
                            <div class="flex items-center mb-4">
                                <div class="w-12 h-12 rounded-full bg-gray-200 overflow-hidden ltr:mr-4 rtl:ml-4">
                                    <img src="{{ get_file( $testimonial->avatar ?? 'themes/greentic/assets/images/testimonial-1.jpg') }}" alt="Customer"
                                        class="w-full h-full object-cover" loading="lazy">
                                </div>
                                <div>
                                    <h4 class="font-semibold mb-2">{{ $testimonial->username ?? __('Client') }}</h4>
                                    <div class="flex text-yellow-400">
                                       @for ($i = 1; $i <= 5; $i++)
                                            @if ($i <= $testimonial->rating_no)
                                                <i class="fas fa-star"></i>
                                            @elseif ($i - 0.5 <= $testimonial->rating_no)
                                                <i class="fas fa-star-half-alt"></i>
                                            @else
                                                <i class="far fa-star"></i>
                                            @endif
                                        @endfor
                                    </div>
                                </div>
                            </div>
                            <p class="text-gray-600 line-clamp-3">{{ $testimonial->description }}</p>
                        </div>
                    </div>
                   @endforeach
                </div>
                <div class="swiper-pagination"></div>
            </div>
        </div>
    </section>
    @endif
    
    @if (isset($themeSettings['article_status']) && $themeSettings['article_status'] == '1')
    <section class="lg:pb-20 pb-10 bg-gray-50">
        <div class="md:container w-full mx-auto px-4">
            <div class="flex flex-col md:flex-row items-center justify-between gap-2 lg:mb-8 mb-4">
                <h2 class="text-2xl lg:text-3xl font-bold text-gray-900">{!! $themeSettings['article_title'] ?? __('Latest from Our Blog') !!}</h2>
                <a href="{{ route('page.blog', $store->slug) ?? '#' }}" class="text-primary font-medium flex items-center gap-2">{{ $themeSettings['article_button_text'] ?? __('View All blog') }} <i class="fas fa-arrow-right"></i></a>
            </div>
            <!-- Swiper Slider -->
            <div class="swiper blog-swiper">
                <div class="swiper-wrapper">
                    @foreach ($blogs as $blog)
                    <div class="swiper-slide">
                        <x-blog-card :store="$store" :blog="$blog" />                        
                    </div>
                    @endforeach
                </div>
                <!-- Add navigation -->
                <div class="flex justify-center">
                    <div class="swiper-button-prev blog-arrow"></div>
                    <div class="swiper-button-next blog-arrow"></div>
                </div>
            </div>
        </div>
    </section>
    @endif

    @include('front_end.common.subscribe')
</main>

@endsection