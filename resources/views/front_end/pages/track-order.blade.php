@extends('front_end.layouts.app')

@section('page-title')
{{ __('Track Order Page') }}
@endsection

@section('content')
 <main>
    @if ($themeSettings['track_banner_status'] && $themeSettings['track_banner_status'] == '1')
    <!-- Common Banner Section -->
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center" style="background-image: url('{{ get_file($themeSettings['track_banner_image'] ?? '') }}');">
      <div class="md:container w-full mx-auto px-4">
        <div class="text-center relative z-[2]">
          <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">{{ $themeSettings['track_banner_title'] ?? '' }}</h2>
        </div>
      </div>
    </section>
    @endif
    
    @if ($themeSettings['track_form_status'] && $themeSettings['track_form_status'] == '1')
    <section class="py-12 md:py-20">
        <div class="md:container w-full mx-auto px-4">
            <div class="max-w-3xl mx-auto">
                <div class="text-center lg:mb-8 mb-5">
                    <h2 class="text-2xl lg:text-3xl font-bold text-gray-900 md:mb-4 mb-2">{{ $themeSettings['track_form_title'] ?? '' }}</h2>
                    <p class="text-gray-600 max-w-2xl mx-auto">{!! $themeSettings['track_form_description'] ?? '' !!}</p>
                </div>

                <div class="bg-gray-50 lg:p-8 p-4 rounded-lg border">
                    <form action="{{ route('order.track', $store->slug) }}" method="POST" enctype="multipart/form-data" id="track-order-form">
                        @csrf
                        <div class="md:mb-6 mb-4">
                            <label for="order-number" class="block mb-2 font-medium md:text-base text-sm">{{ $themeSettings['track_form_number'] ?? '' }} <span
                                    class="text-red-500">*</span></label>
                            <input type="text" name="order_number" id="order-number" placeholder="e.g., FM-12345678" class="form-input"
                                required />
                            <p class="text-sm text-gray-500 mt-1">{{ $themeSettings['track_form_number_note'] ?? '' }}</p>
                        </div>

                        <div class="md:mb-6 mb-4">
                            <label for="email" class="block mb-2 font-medium md:text-base text-sm">{{ $themeSettings['track_form_email'] ?? '' }} <span
                                    class="text-red-500">*</span></label>
                            <input type="email" name="email" id="email" placeholder="The email address used for the order"
                                class="form-input" required />
                        </div>

                        <button type="submit" class="w-full btn-primary">
                           {{ $themeSettings['track_form_button'] ?? '' }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </section>
    @endif
  </main>
@endsection