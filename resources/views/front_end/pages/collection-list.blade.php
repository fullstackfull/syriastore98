@extends('front_end.layouts.app')

@section('page-title')
{{ __('Collections Page') }}
@endsection

@section('content')
<main>
    @if ($themeSettings['collection_banner_status'] && $themeSettings['collection_banner_status'] == '1')
    <!-- Common Banner Section -->
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center"
        style="background-image: url('{{ get_file($themeSettings['collection_banner_image'] ?? '') }}');">
        <div class="md:container w-full mx-auto px-4">
            <div class="text-center relative z-[2]">
                <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">
                    {{ $themeSettings['collection_banner_title'] ?? '' }}</h2>
            </div>
        </div>
    </section>
    @endif

    <section class="collection-list-sec pb-10 lg:pb-20">
      @if ($themeSettings['collection_featured_status'] && $themeSettings['collection_featured_status'] == '1')
      <!-- Featured Collections -->
      <div class="bg-white py-10 lg:py-20">
        <div class="md:container w-full mx-auto px-4">
          <h2 class="font-bold text-2xl md:text-3xl lg:mb-8 mb-4">{{ $themeSettings['collection_featured_title'] ?? '' }}</h2>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            @foreach($topCategories as $category)
            <div class="relative group overflow-hidden rounded-xl collection-card">
              <img src="{{ get_file($category->image_path) }}" alt="{{ $category->name }}"
                class="w-full h-80 object-cover transition duration-500 transform group-hover:scale-105" />
              <div
                class="absolute inset-0 bg-gradient-to-t from-black/80 to-transparent flex flex-col justify-end sm:p-6 p-4 text-white">
                <div class="transform transition duration-300 group-hover:-translate-y-2">
                  <h3 class="font-bold text-xl md:text-2xl mb-2">{{ $category->name }}</h3>
                  <p class="max-w-md mb-4">{{ $category->name }}</p>
                  <a href="{{ url($store->slug . '/' . $category->slug) }}" class="btn-primary">
                    {{ $themeSettings['collection_featured_button_text'] ?? '' }}
                    <i class="fas fa-arrow-right text-sm"></i>
                  </a>
                </div>
              </div>
            </div>
            @endforeach
          </div>
        </div>
      </div>
      @endif
      @if ($themeSettings['collection_all_status'] && $themeSettings['collection_all_status'] == '1')
      <div class="md:container w-full mx-auto px-4">
        <!-- All Collections Grid -->
        <div>
          <h2 class="font-bold text-2xl md:text-3xl lg:mb-8 mb-4">{{ $themeSettings['collection_all_title'] ?? '' }}</h2>

          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 md:gap-8 gap-6">
            @foreach($categories as $category)
            <!-- Collection 1 -->
            <a href="{{ url($store->slug . '/' . $category->slug) }}" class="group border shadow-md rounded-xl overflow-hidden relative">
              <div class="relative overflow-hidden collection-card">
                <div class="overflow-hidden">
                  <img src="{{ get_file($category->image_path) }}" alt="{{ $category->name }}"
                    class="w-full h-60 object-cover transition duration-500 transform group-hover:scale-105" />
                </div>
              </div>
              <div class="p-4 relative">
                <div class="absolute right-2 w-8 h-8  rounded-full top-2 bg-primary/10"></div>
                <div class=" absolute right-10 w-4 h-4 rounded-full top-8 bg-primary/10"></div>
                <h3 class="font-bold md:text-xl text-lg text-black text-center">{{ $category->name }}</h3>
              </div>
            </a>
            @endforeach
          </div>
        </div>
      </div>
      @endif
    </section>
</main>
@endsection