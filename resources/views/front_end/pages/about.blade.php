@extends('front_end.layouts.app')

@section('page-title')
    {{ __('About Us Page') }}
@endsection

@section('content')
    <main>
        @if ($themeSettings['abouts_us_banner_status'] && $themeSettings['abouts_us_banner_status'] == '1')
            <!-- Common Banner Section -->
            <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center"
                style="background-image: url('{{ get_file($themeSettings['abouts_us_banner_image'] ?? '') }}');">
                <div class="md:container w-full mx-auto px-4">
                    <div class="text-center relative z-[2]">
                        <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">
                            {{ $themeSettings['abouts_us_banner_title'] ?? __('about us') }}</h2>
                    </div>
                </div>
            </section>
        @endif

        @if ($themeSettings['abouts_us_status'] && $themeSettings['abouts_us_status'] == '1')
            <section class="lg:py-20 py-10">
                <div class="md:container w-full mx-auto px-4">
                    <!-- Our Mission -->
                    <div class="lg:grid lg:grid-cols-2 lg:gap-12 gap-6 items-center flex flex-col-reverse lg:pb-20 pb-10">
                        <div>
                            <h2 class="text-2xl lg:text-3xl font-bold text-gray-900 mb-4">
                                {{ $themeSettings['abouts_us_full_title'] ?? __('Our Mission') }}</h2>
                            <p class="text-gray-700 mb-4">
                                {!! $themeSettings['abouts_us_full_description'] ?? __("We believe everyone deserves access to high-quality, cutting-edge technology built with sustainable manufacturing practices. Our mission is to make premium electronics more accessible while supporting ethical manufacturers and reducing environmental impact.") !!}
                            </p>
                        </div>
                        <div class="rounded-lg overflow-hidden relative pt-[60%] w-full">
                            <img src="{{ get_file($themeSettings['abouts_us_full_image'] ?? '') }}"
                                alt="about-image" class="w-full h-full object-cover absolute inset-0" />
                        </div>
                    </div>
                    <div class="lg:grid lg:grid-cols-2 lg:gap-12 gap-6 items-center lg:pb-20 pb-5">
                        <div class="rounded-lg overflow-hidden relative pt-[60%] w-full">
                            <img src="{{ get_file($themeSettings['abouts_us_full_image_second'] ?? '') }}" alt="about-image"
                                class="w-full h-full object-cover absolute inset-0" />
                        </div>
                        <div class="mt-6 lg:mt-0">
                            <h2 class="text-2xl lg:text-3xl font-bold text-gray-900 mb-4">{{ $themeSettings['abouts_us_full_title_second'] ?? __('Driven by Innovation, Guided by Purpose') }}</h2>
                            <p class="text-gray-700 mb-4">{!! $themeSettings['abouts_us_full_description_second'] ?? __("At the heart of everything we do is a passion for creating smarter, more sustainable technology solutions that elevate everyday life. We’re redefining what it means to own electronics by focusing on innovation, accessibility, and environmental responsibility.") !!}
                            </p>
                        </div>
                    </div>

                    <!-- Stats -->
                    @if(isset($themeSettings['abouts_us_counter_status']) && $themeSettings['abouts_us_counter_status'] == 1)
                        @php
                            $counters = isset($themeSettings['abouts_us_counter_repeater']) 
                                ? json_decode($themeSettings['abouts_us_counter_repeater']) 
                                : [];
                        @endphp

                        @if(!empty($counters) && is_array($counters))
                            <div class="grid grid-cols-1 md:grid-cols-4 sm:grid-cols-2 lg:gap-6 gap-4 lg:pb-20 pb-10">
                                @foreach($counters as $abouts_counter)
                                    @php
                                        $count = isset($abouts_counter->small_text) ? preg_replace('/\D/', '', $abouts_counter->small_text) : 310;
                                    @endphp
                                    <div class="bg-primary text-white lg:p-6 p-4 rounded-lg text-center">
                                        <span class="counter block font-heading font-bold lg:text-4xl text-3xl mb-2"
                                            data-target="{{ $count }}"
                                            data-count="{{ $count }}">
                                        </span>
                                        <span class="text-white text-opacity-90">
                                            {!! $abouts_counter->content ?? __("Brand Partners") !!}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    @endif
                    <!-- Our Team -->
                    <div>
                        <h2 class="text-2xl lg:text-3xl font-bold text-gray-900 md:mb-8 mb-4 text-center">{{ $themeSettings['team_title'] ?? __('Meet Our Team') }}</h2>
                        <div class="relative">
                            <div class="swiper team-swiper">
                                <div class="swiper-wrapper">
                                <!-- Team Member 1 -->
                                    @php
                                        $teamMembers = isset($themeSettings['team_repeater']) 
                                            ? json_decode($themeSettings['team_repeater']) 
                                            : [];
                                    @endphp

                                    @if(!empty($teamMembers) && is_array($teamMembers))
                                        @foreach($teamMembers as $abouts_counter)
                                            <div class="swiper-slide">
                                                <div class="text-center h-full">
                                                    <img src="{{ asset($abouts_counter->image) }}" 
                                                        alt="{{ $abouts_counter->big_text }}"
                                                        class="w-48 h-48 rounded-full mx-auto mb-4 object-cover" />
                                                    <h3 class="font-heading font-semibold text-xl mb-1">{{ $abouts_counter->big_text }}</h3>
                                                    <p class="text-primary font-medium mb-3">{{ $abouts_counter->small_text }}</p>
                                                    <p class="text-gray-600 text-sm line-clamp-2">{!! $abouts_counter->content !!}</p>
                                                </div>
                                            </div>
                                        @endforeach
                                    @endif
                                </div>

                                <!-- Add Navigation -->
                                <div class="arrow-wrapper">
                                    <div class="swiper-button-prev team-arrow"></div>
                                    <div class="swiper-button-next team-arrow"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        @endif
    </main>
@endsection
@push('page-script')
    
<script>
    // counter js
    const counters1 = document.querySelectorAll('.counter');
    counters1.forEach(counter => {
        const updateCount = () => {
            const target = +counter.getAttribute('data-target');
            const speed = 500;
            const increment = target / speed;
            let count = +counter.innerText;

            if (count < target) {
                counter.innerText = Math.ceil(count + increment);
                setTimeout(updateCount, 10);
            } else {
                // Final adjustment
                counter.innerText = target >= 1000 ? target.toLocaleString() : target;
                    counter.innerText += '+';
            }
        };

        updateCount();
    });
</script>
@endpush