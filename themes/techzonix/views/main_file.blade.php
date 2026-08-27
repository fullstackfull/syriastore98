@extends('front_end.layouts.app')
@section('page-title')
    {{ __('Home Page') }}
@endsection

@section('content')
    @if (isset($themeSettings['slider_status']) && $themeSettings['slider_status'] == '1')
    <section class="home-banner-sec py-10 lg:py-24 bg-cover bg-primary/10 relative z-[1] overflow-hidden">
        @if (isset($themeSettings['slider_left_image']) && !empty($themeSettings['slider_left_image']))
        <img src="{{  get_file($themeSettings['slider_left_image'] ?? '') }}" class="absolute left-0 top-10 hidden lg:block">
        @endif
        @if (isset($themeSettings['slider_center_image']) && !empty($themeSettings['slider_center_image']))
        <img src="{{  get_file($themeSettings['slider_center_image'] ?? '') }}" class="absolute right-0 bottom-10 hidden lg:block">
        @endif
        @if (isset($themeSettings['slider_right_image']) && !empty($themeSettings['slider_right_image']))
        <img src="{{  get_file($themeSettings['slider_right_image'] ?? '') }}" class="absolute right-[50%] -bottom-10 hidden lg:block">
        @endif
        <div class="swiper home-swiper">
            <div class="swiper-wrapper lg:pb-10 pb-16">
                @foreach (json_decode($themeSettings['slider_repeater'],true) as $slider_repeater)
                    <div class="swiper-slide">
                        <div class="container mx-auto px-4">
                            <div class="flex flex-col lg:flex-row items-center">
                                <div class="lg:w-1/2 w-full ltr:xl:pr-12 ltr:lg:pr-8 rtl:xl:pl-12 rtl:lg:pl-8 mb-8 lg:mb-0 text-center lg:text-start">
                                    <h2 class="text-3xl sm:text-4xl lg:text-5xl xl:text-6xl font-medium tracking-tight mb-4 text-gray-900">
                                        {!! $slider_repeater['big_text'] ?? '' !!}
                                    </h2>
                                    <p class="xl:text-xl lg:text-lg text-gray-600 mb-6">
                                        {!! $slider_repeater['content'] ?? '' !!}
                                    </p>
                                    <div class="flex justify-center lg:justify-start gap-4">
                                         <a href="{{ url($slider_repeater['button_link'] ?? '/' . $slug . '/product-list') }}" class="btn-primary">
                                                        {{ $slider_repeater['button_text'] ?? __('Shop Now') }}
                                                    </a>
                                    </div>
                                </div>
                                <div class="lg:w-1/2 w-full">
                                    <div class="lg:max-w-[70%] sm:max-w-[60%] max-w-[90%] w-full mx-auto relative z-[1] p-8">
                                        <img src="{{ get_file($slider_repeater['background_image'] ?? '') }}"
                                            class="absolute -z-[1] top-[50%] left-[50%]  translate-x-[-50%] translate-y-[-50%] h-full w-full">
                                        <div class="relative pt-[100%]">
                                            <img src="{{ get_file($slider_repeater['image'] ?? '') }}"
                                                class="h-full w-full object-contain absolute top-0 left-0"
                                                alt="main-banner-image">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            <div
                class="container px-4 arrow-wrapper flex lg:justify-start justify-center gap-3 absolute z-[1] left-[50%] bottom-0 translate-x-[-50%]">
                <div class="swiper-button-prev home-arrow !shadow-none"></div>
                <div class="swiper-button-next home-arrow !shadow-none"></div>
            </div>
        </div>
    </section>
    @endif
    
    @if ($themeSettings['category_status'] && $themeSettings['category_status'] == 1)
    <section class="lg:py-20 py-10">
        <div class="container mx-auto px-4">
            <div class="flex flex-col md:flex-row items-center justify-between gap-2 lg:mb-8 mb-4">
                <h2 class="text-2xl lg:text-3xl font-bold text-gray-900">
                {!! $themeSettings['category_title'] ?? __('Product Categories') !!}
                </h2>
                <a href="{{route('page.product-list', ['storeSlug' => $store->slug])}}" class="text-primary font-medium hover:underline">
                    {{ $themeSettings['category_button_text'] ?? __('View All Categories') }}
                </a>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
                @foreach(collect($categories)->reverse()->take(5) as $category)
                    <div class="card category-item bg-white shadow-sm rounded-lg hover:shadow-md transition-all duration-200">
                        <a href="{{ url($store->slug . '/' . $category->slug) }}"
                        class="p-6 flex flex-col items-center justify-center text-center h-full">
                            <div class="h-16 w-16 rounded-full bg-primary/10 flex items-center justify-center mb-4 p-3">
                                @if($category->icon_path)
                                    <img src="{{ get_file($category->icon_path) }}" alt="{{ $category->name }}"
                                        class="h-full w-full object-contain">                            
                                @endif
                            </div>
                            <h3 class="font-medium text-gray-900 text-base">{{ $category->name }}</h3>
                        </a>
                    </div>  
                @endforeach
            </div>
        </div>
    </section>
    @endif


    @if ($themeSettings['collections_status'] && $themeSettings['collections_status'] == 1)
    <section class="lg:pb-20 pb-10">
        <div class="container mx-auto px-4">
            <div class="flex flex-col md:flex-row items-center justify-between gap-2 lg:mb-8 mb-4">
                <h2 class="text-2xl lg:text-3xl font-bold text-gray-900">
                    {!! $themeSettings['collections_title'] ?? __('Our collections') !!}
                </h2>
                <a href="{{route('page.product-list', ['storeSlug' => $store->slug])}}"
                    class="text-primary font-medium hover:underline">
                    {{ $themeSettings['collections_button_text'] ?? __('View All Collection') }}
                </a>
            </div>

            <div class="grid lg:grid-cols-3 sm:grid-cols-2 grid-cols-1 gap-4">
                @foreach ($categories as $index => $category)
                    @if ($index < 5)
                        @php
                            $extraClasses = $index === 1 ? 'lg:row-span-2 lg:col-span-1 sm:pt-24 pt-20' : 'lg:pt-24 pt-20';
                        @endphp
                        <div class="collection-box flex items-end relative z-[1] h-full w-full p-4 {{ $extraClasses }} rounded-lg overflow-hidden">
                            <img src="{{ get_file($category->image_path) ?? asset('assets/images/default-category.png')}}"
                                class="absolute top-0 left-0 h-full w-full z-[-1] object-cover rtl:scale-x-[-1]" alt="collection-image">
                            <div class="content-box">
                                <h3 class="font-medium text-xl mb-3">{{ $category->name }}</h3>
                                <a href="{{ url($store->slug . '/' . $category->slug) }}" class="btn-primary">{{ __('Shop Now') }}</a>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    </section>
     @endif
    <!-- Partners Section -->
    @if (!empty($themeSettings['logo_status']) && $themeSettings['logo_status'] == 1)
    <section class="lg:pb-20 pb-10">
        <div class="container mx-auto px-4">
            <h2 class="text-2xl lg:text-3xl font-bold text-gray-900 lg:mb-8 mb-4 md:text-start text-center">
                {!! $themeSettings['logo_title'] ?? __('Trusted By Leading Brands') !!}
            </h2>

            <div class="swiper logo-swiper">
                <div class="swiper-wrapper">
                    @if (!empty($themeSettings['logo_repeater']))
                        @foreach (json_decode($themeSettings['logo_repeater'], true) as $logo)
                            <div class="swiper-slide">
                                <a href="#" class="block group transition-all duration-300"
                                   aria-label="Partner Logo">
                                    <div
                                        class="card bg-white rounded-lg sm:p-6 p-4 h-24 flex items-center justify-center border transition-all duration-300 hover:border-primary">
                                        <div class="w-full h-full relative">
                                            <img
                                                src="{{ get_file($logo['image']) ?? asset('assets/images/partner-logo-1.png') }}"
                                                alt="{{ $logo['alt_text'] ?? 'Partner Logo' }}"
                                                class="object-contain w-full h-full" />
                                        </div>
                                    </div>
                                </a>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>
    </section>
    @endif

    @if ($themeSettings['product_status'] && $themeSettings['product_status'] == 1)
    <section class="lg:pb-20 pb-10">
        <div class="container mx-auto px-4">
            <div class="flex flex-col md:flex-row items-center justify-between gap-2 lg:mb-8 mb-4">
                <h2 class="text-2xl lg:text-3xl font-bold text-gray-900"
                    id="{{ $themeSettings['product_title'] ?? '' }}_preview">
                    {!! $themeSettings['product_title'] ?? __('New Arrivals') !!}
                </h2>
                <a href="{{ route('page.product-list', ['storeSlug' => $slug]) }}"
                    class="text-primary font-medium hover:underline"
                    id="{{ $themeSettings['product_button_text'] ?? '' }}_preview">
                    {{ $themeSettings['product_button_text'] ?? __('View All Product') }}
                </a>
            </div>
            <div class="swiper product-swiper">
                <div class="swiper-wrapper">
                    @foreach ($all_products as $product)
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
    @include('front_end.common.sale')
    @include('front_end.common.offer')
    @include('front_end.common.service')
    @include('front_end.hooks.product_list')
    @if (isset($themeSettings['testimonial_status']) && $themeSettings['testimonial_status'] == 1)
    <section class="lg:pb-20 pb-10">
        <div class="container">
            <h2 class="text-2xl lg:text-3xl font-bold text-gray-900 lg:mb-8 mb-4 md:text-start text-center"
                id="{{$themeSettings['testimonial_title'] ?? '' }}_preview">
                {!! $themeSettings['testimonial_title'] ?? __('What Our Clients Say Sarah Johnson') !!}</h2>

            <div class="swiper testimonial-swiper">
                <div class="swiper-wrapper">
                    @foreach ($testimonials as $testimonial)

                        <div class="swiper-slide">
                            <div class="card lg:p-6 p-4 relative bg-gray-50 h-full">
                                <i class="fas fa-quote-right text-purple-500 quote-icon"></i>

                                <div class="flex items-center mb-4">
                                  
                                    <img src="{{ get_file($testimonial->avatar ?? 'avatar.png') }}"
                                        alt="User Avatar"
                                        class="w-16 h-16 rounded-full object-cover border-4 border-primary/10">

                                    <div class="ltr:ml-4 rtl:mr-4 flex-1">
                                        <h4 class="font-bold text-gray-800 mb-1">
                                        {{ $testimonial->username ?? 'Anonymous' }}
                                    </h4>
                                        <p class="text-primary">
                                        <a href="{{ url($slug . '/product/' . optional($testimonial->ProductData)->slug ?? '#') }}"
                                        class="text-primary hover:underline">
                                            {{ $testimonial->ProductData->name ?? 'Product' }}
                                        </a>    
                                     </p>
                                    </div>
                                </div>
                                <p class="text-gray-600 mb-4">"{!! $testimonial->description !!}"</p>

                                <div class="flex text-yellow-400">
                                    @for ($i = 0; $i < 5; $i++)
                                        <i
                                            class="fas fa-star {{ $i < $testimonial->rating_no ? 'text-yellow-400' : 'text-gray-300' }}"></i>
                                    @endfor
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="arrow-wrapper">
                    <div class="swiper-button-next testimonial-arrow"></div>
                    <div class="swiper-button-prev testimonial-arrow"></div>
                </div>
            </div>
        </div>
    </section>
    @endif
    @if (isset($themeSettings['article_status']) && $themeSettings['article_status'] == 1)
    <section class="lg:pb-20 pb-10">
        <div class="container mx-auto px-4">
            <div class="flex flex-col md:flex-row items-center justify-between gap-2 lg:mb-8 mb-4">
                <h2 class="text-2xl lg:text-3xl font-bold text-gray-900"
                    id="{{ $themeSettings['article_title'] ?? '' }}_preview">{{ $themeSettings['article_title'] ?? __('Latest Blog Posts') }}</h2>
                <a href="{{ route('page.blog', $store->slug) ?? '#' }}" class="text-primary font-medium hover:underline">{{ $themeSettings['article_button_text'] ?? __('Latest Blog Posts') }}</a>
            </div>
            <div class="swiper blog-swiper">
                <div class="swiper-wrapper">
                    @foreach ($blogs as $blog)
                    <div class="swiper-slide">
                        <x-blog-card :store="$store" :blog="$blog" />
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
    @endif
    @include('front_end.common.subscribe')
@endsection