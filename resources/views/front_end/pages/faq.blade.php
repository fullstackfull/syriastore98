@extends('front_end.layouts.app')

@section('page-title')
{{ __('Faqs Page') }}
@endsection

@section('content')
 <main>
    @if ($themeSettings['faq_banner_status'] && $themeSettings['faq_banner_status'] == '1')
    <!-- Common Banner Section -->
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center"
      style="background-image: url('{{ get_file($themeSettings['faq_banner_image'] ?? '') }}');">
      <div class="md:container w-full mx-auto px-4">
        <div class="text-center relative z-[2]">
          <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">{{ $themeSettings['faq_banner_title'] ?? __('faqs') }}</h2>
          
        </div>
      </div>
    </section>
    @endif
    @if ($themeSettings['faq_list_status'] && $themeSettings['faq_list_status'] == '1')

    <section class="lg:py-20 py-10">
      <div class="md:container w-full mx-auto px-4">
          <div class="max-w-3xl mx-auto">
              <!-- FAQ Group 1: Ordering & Delivery -->
               @foreach ($faqs as $faq)
                @if ($faq && $faq->description)
                    <div class="md:mb-10 mb-6 faq-col">
                        <h2 class="font-bold text-2xl mb-6">{{ $faq->topic }}</h2>

                        <div class="space-y-6">
                            @foreach (json_decode($faq->description) as $detail)
                            
                            <!-- FAQ Item 1 -->
                            <div class="border rounded-lg overflow-hidden faq-button">
                                <button
                                    class="flex items-center justify-between w-full md:px-6 md:py-4 p-4 text-start font-semibold focus:outline-none">
                                    <span class="flex-1">{{ $detail->question }}</span>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round" class="h-5 w-5 text-primary">
                                        <path d="M5 12h14" />
                                        <path d="M12 5v14" />
                                    </svg>
                                </button>
                                <div class="px-6 pb-4 hidden">
                                    <p class="text-gray-600">{!! $detail->answer !!}</p>
                                </div>
                            </div>
                            @endforeach
                        </div>
                    </div>
                @endif
              @endforeach
          </div>
      </div>
    </section>
    @endif
  </main>
@endsection