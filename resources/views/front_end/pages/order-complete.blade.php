
@extends('front_end.layouts.app')
@section('page-title')
    {{ __('Order Page') }}
@endsection
@section('content')
<main>
    @if ($themeSettings['order_cmp_banner_status'] && $themeSettings['order_cmp_banner_status'] == '1')
    <!-- Common Banner Section -->
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center"
      style="background-image: url('{{ get_file($themeSettings['order_cmp_banner_image'] ?? '') }}');">
      <div class="md:container w-full mx-auto px-4">
        <div class="text-center relative z-[2]">
          <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">{{ $themeSettings['order_cmp_banner_title'] ?? __('Order Complete') }}</h2>
          
        </div>
      </div>
    </section>
    @endif
    @if ($themeSettings['order_cmp_status'] && $themeSettings['order_cmp_status'] == '1')
    <section class="py-10 lg:py-20">
      <div class="md:container w-full mx-auto px-4">
     
        <div
          class="bg-white rounded-lg shadow-[0_0_12px_rgba(0,0,0,0.10)] max-w-2xl w-full mx-auto md:p-8 p-4 text-center border border-white/20 relative overflow-hidden">
          <div class="md:mb-6 mb-4 relative z-10">
            <div
              class="w-16 h-16 bg-primary-light rounded-full flex items-center justify-center mx-auto shadow-md">
              <i class="fas fa-check-circle text-primary text-4xl"></i>
            </div>
          </div>
          <!-- Main Heading -->
          <div class="md:mb-4 mb-2">
            <h2 class="md:text-3xl text-[22px] font-bold text-gray-800 mb-2">{{ $themeSettings['order_cmp_title'] ?? __('Your order') }}
            <span
              class="text-primary">
              #{{ $order->product_order_id }}
            </span></h2>
            <h3 class="md:text-2xl text-xl font-bold text-gray-800">
              {{ $themeSettings['order_cmp_short_msg'] ?? __('has been placed!') }}</h3>
          </div>
          <!-- Confirmation Message -->
          <div class="md:mb-6 mb-4">
            <p class="text-lg font-semibold text-gray-700 mb-3 flex items-center justify-center gap-2">
              {{ $themeSettings['order_cmp_message'] ?? __('Order confirmed successfully!') }}
            </p>
            <p class="text-gray-600 leading-relaxed font-medium">
              {!! $themeSettings['order_cmp_description'] ?? __("Your order has been successfully processed! We've sent you an email confirmation. Our team will reach out if we need any additional information.") !!}
            </p>
            <div class="mt-4 flex justify-center gap-2">
              <span class="w-3 h-3 rounded-full bg-blue-400 animate-bounce"></span>
              <span class="w-3 h-3 rounded-full bg-purple-400 animate-bounce delay-75"></span>
              <span class="w-3 h-3 rounded-full bg-pink-400 animate-bounce delay-100"></span>
            </div>
          </div>
          <!-- Order Link -->
          <div class="md:mb-7 mb-4">
            <div
              class="bg-gray-50 rounded-lg p-4 py-3 flex items-center justify-between border border-gray-200/80 shadow-inner">
              <span class="text-sm text-gray-600 truncate flex-1 ltr:mr-3 rtl:ml-3">
               {{ $url }}
              </span>
              <button
                class="bg-yellow-400 hover:bg-yellow-500 text-gray-800 sm:px-4 px-2 py-2 rounded-md text-sm font-medium whitespace-nowrap transition-colors">
                {{ $themeSettings['order_cmp_copy_btn'] ?? __('Copy Link') }}
              </button>
            </div>
          </div>
          <!-- Back to Dashboard Button -->
          <div class="relative group">
            <a href="{{ route('page.product-list', $store->slug) }}"
              class="btn-primary continue-btn">
                <i class="fas fa-arrow-left"></i>
               {{ $themeSettings['order_cmp_back_btn'] ?? __('Back to shop') }}
            </a>
          </div>
        </div>
      </div>
    </section>
    @endif
</main>
@endsection
@push('page-script')

<script>
    document.querySelector('button[class*="bg-yellow-400"]').addEventListener('click', function () {
      const link = document.querySelector('.text-gray-600.truncate').textContent;
      navigator.clipboard.writeText(link).then(function () {
        // Temporarily change button text
        const button = this;
        const originalText = button.textContent;
        button.textContent = 'Copied!';
        button.classList.add('bg-green-400');
        button.classList.remove('bg-yellow-400');
        setTimeout(function () {
          button.textContent = originalText;
          button.classList.remove('bg-green-400');
          button.classList.add('bg-yellow-400');
        }, 2000);
      }.bind(this));
    });
  </script>

@endpush

