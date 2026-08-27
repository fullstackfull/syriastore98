@extends('front_end.layouts.app')

@section('page-title')
{{ __('Error') }}
@endsection

@section('content')
<main>
    @if ($themeSettings['error_banner_status'] && $themeSettings['error_banner_status'] == '1')
    <!-- Common Banner Section -->
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center"
        style="background-image: url('{{ get_file($themeSettings['error_banner_image'] ?? '') }}');">
        <div class="md:container w-full mx-auto px-4">
            <div class="text-center relative z-[2]">
                <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">
                    {{ $themeSettings['error_banner_title'] ?? __('Page Not Found') }}</h2>
            </div>
        </div>
    </section>
    @endif

    @if ($themeSettings['error_status'] && $themeSettings['error_status'] == '1')
     <section class="py-10 lg:py-20">
        <div class="md:container w-full mx-auto px-4">
            <div class="max-w-3xl mx-auto text-center">
                <!-- Error Image/Illustration -->
                <h2 class="text-7xl md:text-9xl font-bold text-black mb-4 md:mb-6">{{ $themeSettings['error_code'] ?? '404'}}</h2>
                <h3 class="text-2xl lg:text-3xl font-bold text-gray-900 mb-4">{{ $themeSettings['error_title'] ?? __('Oops! Page Not Found') }}</h3>
                <p class="text-gray-600 md:mb-8 mb-6 max-w-xl mx-auto">{!! $themeSettings['error_description'] ?? __("We couldn't find the page you're looking for. The page may have moved, or it might no longer be available.") !!}</p>

                <div class="flex flex-wrap flex-row gap-4 justify-center">
                    <a href="{{ route('landing_page', $store->slug) }}" class="btn-primary">
                        {{ $themeSettings['error_home_button'] ?? __('Go to Homepage') }}
                    </a>
                    <a href="{{ route('page.product-list', $store->slug) }}" class="btn-outline">
                        {{ $themeSettings['error_product_button'] ?? __('Browse Products') }}
                    </a>
                </div>
            </div>
        </div>
    </section>
    @endif
</main>
@endsection