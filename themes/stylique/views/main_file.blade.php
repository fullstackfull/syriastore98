@extends('front_end.layouts.app')

@section('page-title')
    {{ __('Home Page') }}
@endsection

@section('content')
    <main>
        <!-- Hero Slider Section -->
        @if (isset($themeSettings['slider_status']) && $themeSettings['slider_status'] == 1)
            <section class="section-spacing pb-0">
                <div class="md:container mx-auto px-4 w-full">
                    <div class="flex flex-wrap gap-4 banner-wrp">
                        <div class="swiper hero-slider lg:flex-1 rounded-lg overflow-hidden">
                            <div class="swiper-wrapper">
                                    @php
                                        $sliders = json_decode($themeSettings['slider_repeater'] ?? '[]', true);

                                        if (!is_array($sliders)) {
                                            $sliders = [];
                                        }
                                    @endphp

                                    @foreach ($sliders as $slider_repeater)
                                        <div class="swiper-slide h-auto relative">
                                            <img src="{{ get_file($slider_repeater['image']) }}"
                                                width="500"
                                                height="500"
                                                alt="{{ $slider_repeater['big_text'] ?? 'New arrivals' }}"
                                                loading="lazy"
                                                class="w-full h-full object-cover object-left absolute rounded-lg" />

                                            <div class="relative z-10 flex items-center md:py-[130px] py-[60px]">
                                                <div class="text-white px-4 md:px-6 max-w-[500px]">
                                                    <h2 class="text-3xl md:text-5xl text-black font-bold mb-4">
                                                        {!! $slider_repeater['big_text'] ?? __('Step Into a World of Effortless Elegance') !!}
                                                    </h2>
                                                    <p class="md:mb-8 mb-4 text-black max-w-sm">
                                                        {!! $slider_repeater['content'] ?? __('Simple cuts. Solid tones. Elevated basics. Our mens lineup is designed for effortless dressing with a modern edge.') !!}
                                                    </p>
                                                    <a href="{{ url($slider_repeater['button_link'] ?? '/' . $slug . '/product-list') }}" class="btn-primary">
                                                        {{ $slider_repeater['button_text'] ?? __('Shop Now') }}
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                            </div>

                            <!-- Pagination -->
                            <div class="swiper-pagination text-start"></div>
                        </div>
                        @if (isset($themeSettings['more_offer_status']) && $themeSettings['more_offer_status'] == 1)
                            <div class="lg:h-auto h-96 w-full offer-banner overflow-hidden">
                                <a href="{{ $themeSettings['more_offer_button_link'] ?? route('page.product-list', ['storeSlug' => $slug]) }}" tabindex="0">
                                    <img src="{{ get_file($themeSettings['more_offer_image']) }}" width="300" height="400" alt="offer-image"
                                        class="w-full h-full object-cover object-top rounded-lg" loading="lazy">
                                </a>
                            </div>
                        @endif

                    </div>
                </div>
            </section>
        @endif

        <!-- logo Section -->
        @if (!empty($themeSettings['logo_status']) && $themeSettings['logo_status'] == 1)
        <section class="section-spacing">
            <div class="md:container mx-auto px-4 w-full">
                <!-- Swiper container -->
                <div class="swiper partnerSwiper pb-0 relative">
                    <div class="swiper-wrapper">
                        @if (!empty($themeSettings['logo_repeater']))
                        @foreach (json_decode($themeSettings['logo_repeater'],true) as $logo)
                                <div class="swiper-slide">
                                
                                    <div class="partner-logo flex items-center justify-center bg-white rounded-xl h-full">
                                        <img
                                            src="{{ get_file($logo['image']) }}"
                                            width="150"
                                            height="150"
                                            alt="{{ $logo->alt_text ?? 'Partner Logo' }}"
                                            class="h-12 object-contain"
                                        />
                                    </div>
                                </div>
                            @endforeach
                        @endif
                    </div>

                    <div class="arrow-wrapper">
                        <div class="swiper-button-next"></div>
                        <div class="swiper-button-prev"></div>
                    </div>
                </div>
            </div>
        </section>
        @endif

        <!-- Feature Collections Banner -->
        @if (!empty($themeSettings['collection_status']) && $themeSettings['collection_status'] == 1)
            <section class="section-spacing bg-gray-50">
                <div class="md:container mx-auto px-4 w-full">
                    <div class="grid md:grid-cols-2 gap-6">
                    @foreach (collect($MainCategoryList)->reverse()->take(2) as $category)
                                <div class="relative rounded-lg overflow-hidden group">
                                    <img src="{{ get_file($category->image_path)}}"
                                        alt="{{ $category->name }}"
                                        loading="lazy" width="200" height="200"
                                        class="w-full md:h-96 h-64 object-cover transition-transform duration-500 group-hover:scale-105 rtl:scale-x-[-1] rtl:group-hover:[--tw-scale-x:-1.05]"
                                    />
                                    <div
                                        class="absolute inset-0 bg-black bg-opacity-20 group-hover:bg-opacity-30 transition-all duration-300"
                                    ></div>
                                    <div class="absolute inset-0 flex items-end md:p-6 p-4">
                                        <div>
                                            <h3 class="text-white md:text-3xl text-2xl font-bold mb-2">
                                                {{ $category->name }}
                                            </h3>
                                            <p class="text-white mb-4 max-w-[200px] sm:max-w-[300px]">
                                                <!-- Optional description, if available -->
                                                {{ $category->description ?? 'Explore our latest ' . $category->name . ' collection.' }}
                                            </p>
                                            <a href="{{ url($store->slug . '/' . $category->slug) }}" class="btn-primary inline-block">
                                                {{__("Shop Collection")}}
                                            </a>
                                        </div>
                                    </div>
                                </div>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        <!-- Products Section -->
        @if (!empty($themeSettings['product_status']) && $themeSettings['product_status'] == 1)
            <section class="section-spacing">
                <div class="md:container mx-auto px-4 w-full">
                <div class="text-center md:mb-10 mb-6">
                    <h2 class="md:text-4xl text-3xl font-bold mb-4">{{ $themeSettings['product_title'] ?? 'Featured Products' }}</h2>
                    <p class="text-gray-600 max-w-2xl mx-auto">
                    {!! $themeSettings['product_description'] ?? 'Discover our carefully curated selection of premium fashion items' !!}
                    </p>
                </div>
                <div class="swiper featured-products">
                    <div class="swiper-wrapper">
                        @foreach(collect($all_products)->reverse()->take(10) as $product)
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
            </section>
        @endif

        <!-- Category Section -->
        @if (!empty($themeSettings['category_status']) && $themeSettings['category_status'] == 1)
            <section class="section-spacing bg-gray-50">
                <div class="md:container mx-auto px-4 w-full">
                    <div class="text-center md:mb-10 mb-6">
                        <h2 class="md:text-4xl text-3xl font-bold mb-4">
                        {!! $themeSettings['category_title'] ?? '__("Shop by Category")' !!}    </h2>
                        <p class="text-gray-600 max-w-2xl mx-auto">
                            {!! $themeSettings['category_description'] ?? 'Explore our diverse range of categories to find exactly what you need.' !!}
                        </p>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-3 sm:grid-cols-2 gap-4 md:gap-6">
                        @foreach (collect($MainCategoryList)->take(3) as $category)
                            <div class="group relative rounded-lg overflow-hidden transition-transform duration-300 hover:scale-105">
                                <img
                                    src="{{ get_file($category->image_path) }}"
                                    alt="{{ $category->name }}"
                                    width="400" height="400"
                                    loading="lazy"
                                    class="w-full h-64 object-cover object-top rtl:scale-x-[-1]"
                                />
                                <div class="absolute inset-0 bg-black bg-opacity-20 group-hover:bg-opacity-30 transition-all duration-300"></div>
                                <div class="absolute inset-0 flex items-end md:p-6 p-4">
                                    <div>
                                        <h3 class="text-white text-2xl font-bold mb-3">{{ $category->name }}</h3>
                                        <a href="{{ url($store->slug . '/' . $category->slug) }}"
                                        class="btn-primary px-4 py-2 text-sm">
                                           {{ __("Shop Now") }}
                                        </a>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        
        @if (!empty($themeSettings['bestseller_status']) && $themeSettings['bestseller_status'] == 1)
            <section class="section-spacing collection-sec">
                <div class="md:container mx-auto px-4 w-full">
                    <div class="text-center items-center md:mb-10 mb-6 flex flex-wrap gap-3 justify-center lg:justify-between">
                    <h2 class="md:text-4xl text-3xl font-bold">
                        {!! $themeSettings['bestseller_title'] ?? 'Explore Our Products' !!}
                    </h2>
                    <div class="overflow-x-auto">
                        <ul class="flex mx-auto w-fit border-b">
                        <li class="mr-2 whitespace-nowrap">
                            <button class="tab-btn active py-2 px-4 font-medium text-center sm:border-b-2 border-[var(--primary-color)]" data-tab="all">
                        {{__("All Products")}}
                            </button>
                        </li>
                        @foreach(collect($MainCategoryList)->reverse()->take(4) as $category)
                            <li class="mr-2 whitespace-nowrap">
                            <button class="tab-btn py-2 px-4 font-medium text-center sm:border-b-2 border-transparent" data-tab="{{ $category->slug }}">
                                {{ $category->name }}
                            </button>
                            </li>
                        @endforeach
                        </ul>
                    </div>
                    </div>

                    {{-- Tab Content --}}
                    <div class="tab-content-wrapper">
                        {{-- All Products Tab --}}
                        <div class="tab-content active" id="all-tab">
                            <div class="swiper featured-products">
                                <div class="swiper-wrapper">
                                    @foreach(collect($all_products)->reverse()->take(10) as $product)
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
                        {{-- Category Tabs --}}
                        @foreach(collect($MainCategoryList)->reverse()->take(4) as $category)
                            <div class="tab-content hidden" id="{{ $category->slug }}-tab">
                            <div class="swiper featured-products">
                                <div class="swiper-wrapper">
                                @foreach($category->product_details as $product)
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
                        @endforeach
                    </div>
                </div>
            </section>
        @endif
        @include('front_end.hooks.product_list')
        <!-- Testimonials Section -->
        @if (!empty($themeSettings['testimonial_status']) && $themeSettings['testimonial_status'] == 1)
            <section class="section-spacing bg-gray-50">
                <div class="md:container mx-auto px-4 w-full">
                    <!-- Section Title -->
                    <div class="text-center md:mb-10 mb-6">
                        <h2 class="md:text-4xl text-3xl font-bold mb-4">
                            {!! $themeSettings['testimonial_title'] ?? 'What Our Customers Say' !!}
                        </h2>
                        <p class="text-gray-600 max-w-2xl mx-auto">
                            {!! $themeSettings['testimonial_sub_title'] ?? 'Read testimonials from our satisfied customers around the world.' !!}
                        </p>
                    </div>
                    <!-- Testimonials Slider -->
                    <div class="swiper testimonials-slider">
                        <div class="swiper-wrapper">
                            @foreach ($testimonials as $review)
                                <div class="swiper-slide">
                                    <div class="bg-white md:p-6 p-4 rounded-lg shadow-md h-full flex flex-col">
                                        <!-- Star Rating & Review -->
                                        <div class="flex-1">
                                            <div class="text-yellow-400 mb-4">
                                                @php
                                                    $stars = '';
                                                    $fullStars = floor($review->rating_no);
                                                    $halfStar = ($review->rating_no - $fullStars) >= 0.5;

                                                    for ($i = 1; $i <= 5; $i++) {
                                                        if ($i <= $fullStars) {
                                                            $stars .= '<i class="fas fa-star"></i>';
                                                        } elseif ($i === $fullStars + 1 && $halfStar) {
                                                            $stars .= '<i class="fas fa-star-half-alt"></i>';
                                                        } else {
                                                            $stars .= '<i class="far fa-star"></i>';
                                                        }
                                                    }
                                                @endphp
                                                {!! $stars !!}
                                            </div>
                                            <p class="text-gray-600 mb-6">
                                                {!! $review->description ?? 'This customer did not leave a written review.' !!}
                                            </p>
                                        </div>

                                        <!-- Reviewer Info -->
                                        <div class="flex items-center gap-4">
                                            <img
                                            src="{{ get_file($review->avatar) }}"
                                                alt="Customer"
                                                class="w-12 h-12 rounded-full object-cover"
                                            />
                                            <div>
                                                <h4 class="font-medium">
                                                    {{ $review->username ?? 'User' }}
                                                </h4>
                                                <p class="text-gray-500 text-sm">
                                                    {{ $review->productData?->name ?? 'Product Detail not provided' }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <!-- Swiper Navigation -->
                        <div class="arrow-wrapper">
                            <div class="swiper-button-next"></div>
                            <div class="swiper-button-prev"></div>
                        </div>
                    </div>
                </div>
            </section>
        @endif                                         
        <!-- Blog Section -->
        @if (!empty($themeSettings['blog_status']) && $themeSettings['blog_status'] == 1)
            <section class="section-spacing">
            <div class="md:container mx-auto px-4 w-full">
                <div class="text-center md:mb-10 mb-6">
                    <h2 class="md:text-4xl text-3xl font-bold mb-4">{!! $themeSettings['blog_title'] ?? 'Fashion Blog' !!}</h2>
                    <p class="text-gray-600 max-w-2xl mx-auto">
                        {!! $themeSettings['blog_sub_title'] ?? 'Style tips, trend reports, and fashion inspiration' !!}
                    </p>
                </div>
                <div class="blog-slider swiper">
                <div class="swiper-wrapper">
                    @foreach ($blogs as $blog)
                    <div class="swiper-slide">
                     <x-blog-card :store="$store" :blog="$blog" />
                    </div>
                    @endforeach
                </div>
                <div class="arrow-wrapper">
                    <div class="swiper-button-next"></div>
                    <div class="swiper-button-prev"></div>
                </div>
                </div>
            </div>
            </section>
        @endif

        <!-- Service Section -->
        @if (isset($themeSettings['service_status']) && $themeSettings['service_status'] == 1)
            <section class="section-spacing pt-0">
                <div class="md:container mx-auto px-4 w-full">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 md:gap-6 gap-4">  
                        @foreach (json_decode($themeSettings['service_repeater'], true) as $service)
                            <div class="service-card bg-white rounded-xl p-4 border border-gray-100 flex items-center gap-4">
                                <div class="service-icon text-orange-500 floating">
                                    <img src="{{ get_file($service['image']) }}" alt="{{ $service['title'] }}" class="w-12 h-12 object-contain" loading="lazy">
                                </div>
                                <div>
                                    <h3 class="text-xl font-semibold text-gray-800 mb-2">{{ $service['title'] }}</h3>
                                    <p class="text-gray-600">{{ $service['content'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif
    </main>
@endsection

