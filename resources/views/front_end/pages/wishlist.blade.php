@extends('front_end.layouts.app')

@section('page-title')
{{ __('Wishlist Page') }}
@endsection

@section('content')
 <main>
    @if ($themeSettings['wishlist_banner_status'] && $themeSettings['wishlist_banner_status'] == '1')
    <!-- Common Banner Section -->
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center" style="background-image: url('{{ get_file($themeSettings['wishlist_banner_image'] ?? '') }}');">
      <div class="md:container w-full mx-auto px-4">
        <div class="text-center relative z-[2]">
          <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">{{ $themeSettings['wishlist_banner_title'] ?? __('Wishlist') }}</h2>
        </div>
      </div>
    </section>
    @endif
    
    @if ($themeSettings['wishlist_status'] && $themeSettings['wishlist_status'] == '1')
    <section class="py-10 lg:py-20">
      <div class="md:container w-full mx-auto px-4">
          <div class="overflow-x-auto border border-gray-200 rounded-md wishlist-table-section">
              
          </div>

          <!-- Wishlist Actions -->
              <a href="{{ route('page.product-list', $store->slug) }}" class="inline-flex items-center text-primary lg:mt-8 mt-6 gap-2 continue-btn">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                      class="h-4 w-4">
                      <path d="m15 18-6-6 6-6" />
                  </svg>
                  {{ $themeSettings['wishlist_button'] ?? __('Continue Shopping') }}
              </a>
      </div>
    </section>
    @endif
  </main>
@endsection