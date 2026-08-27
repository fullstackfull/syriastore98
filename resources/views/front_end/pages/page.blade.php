@extends('front_end.layouts.app')

@section('page-title')
{{ $page->page_name ?? __('Faqs Page') }}
@endsection

@section('content')
 <main>
    @if ($themeSettings['cms_page_banner_status'] && $themeSettings['cms_page_banner_status'] == '1')
    <!-- Common Banner Section -->
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center"
      style="background-image: url('{{ get_file($themeSettings['cms_page_banner_image'] ?? '') }}');">
      <div class="md:container w-full mx-auto px-4">
        <div class="text-center relative z-[2]">
          <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">{{ $page->page_name ?? __('Page') }}</h2>
          
        </div>
      </div>
    </section>
    @endif
    @if ($themeSettings['cms_page_status'] && $themeSettings['cms_page_status'] == '1')

    <section class="lg:py-20 py-10">
      <div class="md:container w-full mx-auto px-4">
          <div class="max-w-3xl mx-auto">
             {!! $page->page_content !!}
          </div>
      </div>
    </section>
    @endif
  </main>
@endsection