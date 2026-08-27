@extends('front_end.layouts.app')

@section('page-title')
    {{ __('Blog Page') }}
@endsection

@section('content')
    <main>
        @if ($themeSettings['blog_banner_status'] && $themeSettings['blog_banner_status'] == '1')
            <!-- Common Banner Section -->
            <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center"
                style="background-image: url('{{ get_file($themeSettings['blog_banner_image'] ?? '') }}');">
                <div class="md:container w-full mx-auto px-4">
                    <div class="text-center relative z-[2]">
                        <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">
                            {{ $themeSettings['blog_banner_title'] ?? __('Blog') }}
                        </h2>
                    </div>
                </div>
            </section>
        @endif

        @if ($themeSettings['blog_status'] && $themeSettings['blog_status'] == '1')
            <section class="lg:py-20 py-10">
                <div class="md:container w-full mx-auto px-4">
                    <!-- Blog Posts Grid -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                        @foreach ($blogs as $key => $blog)
                            <x-blog-card :store="$store" :blog="$blog" :themeSettings="$themeSettings" />
                        @endforeach
                    </div>
                    
                    <!-- Pagination -->
                    <div class="flex justify-center md:mt-8 mt-5 pagination-wrapper">
                        <div class="flex items-center gap-2">
                            @if($blogs->onFirstPage())
                                <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-500 cursor-not-allowed">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round" class="h-4 w-4">
                                        <path d="m15 18-6-6 6-6" />
                                    </svg>
                                </span>
                            @else
                                <a href="{{ $blogs->previousPageUrl() }}"
                                    class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-500 hover:bg-gray-50">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round" class="h-4 w-4">
                                        <path d="m15 18-6-6 6-6" />
                                    </svg>
                                </a>
                            @endif

                            @php
                                $current = $blogs->currentPage();
                                $last = $blogs->lastPage();
                            @endphp

                            @if($current <= 2)
                                @for($i = 1; $i <= min(3, $last); $i++)
                                    @if($i == $current)
                                        <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-primary bg-primary text-white">{{ $i }}</span>
                                    @else
                                        <a href="{{ $blogs->url($i) }}" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">{{ $i }}</a>
                                    @endif
                                @endfor
                                @if($last > 3)
                                    <span class="p-1 h-8 w-8 flex items-center justify-center text-gray-500">...</span>
                                    <a href="{{ $blogs->url($last) }}" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">{{ $last }}</a>
                                @endif
                            @elseif($current >= $last - 1)
                                <a href="{{ $blogs->url(1) }}" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">1</a>
                                @if($last > 3)
                                    <span class="p-1 h-8 w-8 flex items-center justify-center text-gray-500">...</span>
                                @endif
                                @for($i = max(1, $last - 2); $i <= $last; $i++)
                                    @if($i == $current)
                                        <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-primary bg-primary text-white">{{ $i }}</span>
                                    @else
                                        <a href="{{ $blogs->url($i) }}" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">{{ $i }}</a>
                                    @endif
                                @endfor
                            @else
                                <a href="{{ $blogs->url(1) }}" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">1</a>
                                <span class="p-1 h-8 w-8 flex items-center justify-center text-gray-500">...</span>
                                @for($i = $current; $i <= $current + 1; $i++)
                                    @if($i == $current)
                                        <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-primary bg-primary text-white">{{ $i }}</span>
                                    @else
                                        <a href="{{ $blogs->url($i) }}" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">{{ $i }}</a>
                                    @endif
                                @endfor
                                <span class="p-1 h-8 w-8 flex items-center justify-center text-gray-500">...</span>
                                <a href="{{ $blogs->url($last) }}" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">{{ $last }}</a>
                            @endif

                            @if($blogs->hasMorePages())
                                <a href="{{ $blogs->nextPageUrl() }}"
                                    class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-500 hover:bg-gray-50">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round" class="h-4 w-4">
                                        <path d="m9 18 6-6-6-6" />
                                    </svg>
                                </a>
                            @else
                                <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-500 cursor-not-allowed">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round" class="h-4 w-4">
                                        <path d="m9 18 6-6-6-6" />
                                    </svg>
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            </section>
        @endif
    </main>
@endsection
