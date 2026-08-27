@extends('front_end.layouts.app')

@section('page-title')
{{ __('Error') }}
@endsection

@section('content')
<main>
    @if ($themeSettings['terms_condition_banner_status'] && $themeSettings['terms_condition_banner_status'] == '1')
    <!-- Common Banner Section -->
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center"
        style="background-image: url('{{ get_file($themeSettings['terms_condition_banner_image'] ?? '') }}');">
        <div class="md:container w-full mx-auto px-4">
            <div class="text-center relative z-[2]">
                <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">
                    {{ $themeSettings['terms_condition_banner_title'] ?? __('Terms & Conditions') }}</h2>
            </div>
        </div>
    </section>
    @endif

    @if ($themeSettings['terms_condition_policy_status'] && $themeSettings['terms_condition_policy_status'] == '1')
      @if (isset($themeSettings['repeater']))
        @php
          $sliderItems = json_decode($themeSettings['repeater']);
        @endphp

        @if (!empty($sliderItems))
          <section class="lg:py-20 py-10">
            <div class="md:container w-full mx-auto px-4">
              @foreach ($sliderItems as $index => $item)
                <div class="mb-10">
                  <h2 class="font-bold text-xl md:text-3xl mb-5">
                    {{ $index + 1 }}. {{ $item->title ?? 'Untitled Section' }}
                  </h2>
                  <div class="bg-primary/10 ltr:border-l-4 rtl:border-l-4 border-primary p-4 md:pl-6 mb-6 rounded-lg">
                    {!! $item->summernote ?? '' !!}
                  </div>
                </div>
              @endforeach
            </div>
          </section>
        @endif
      @endif
    @endif
</main>
@endsection