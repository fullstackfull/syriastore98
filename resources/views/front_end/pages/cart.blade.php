@extends('front_end.layouts.app')

@section('page-title')
{{ __('Cart Page') }}
@endsection

@section('content')
 <main>
    @if ($themeSettings['cart_banner_status'] && $themeSettings['cart_banner_status'] == '1')
    <!-- Common Banner Section -->
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center" style="background-image: url('{{ get_file($themeSettings['cart_banner_image'] ?? '') }}');">
      <div class="md:container w-full mx-auto px-4">
        <div class="text-center relative z-[2]">
          <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">{{ $themeSettings['cart_banner_title'] ?? __('Cart Details') }}</h2>
        </div>
      </div>
    </section>
    @endif
    
    @if ($themeSettings['cart_status'] && $themeSettings['cart_status'] == '1')
    <section class="lg:py-20 py-10 cart-page-section">
      
    </section>
    @endif
  </main>
@endsection