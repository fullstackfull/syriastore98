@extends('front_end.layouts.app')

@section('page-title')
{{ __('Article Page') }}
@endsection

@section('content')
 <main>
    @if ($themeSettings['article_banner_status'] && $themeSettings['article_banner_status'] == '1')
    <!-- Common Banner Section -->
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center" style="background-image: url('{{ get_file($themeSettings['article_banner_image'] ?? '') }}');">
      <div class="md:container w-full mx-auto px-4">
        <div class="text-center relative z-[2]">
          <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">{{ $themeSettings['article_banner_title'] ?? __('Article') }}</h2>
        </div>
      </div>
    </section>
    @endif
    
    @if ($themeSettings['article_status'] && $themeSettings['article_status'] == '1')
    <section class="py-10 lg:py-20">
        <div class="w-full max-w-6xl mx-auto px-4">
            <!-- Article Header -->
            <div class="lg:mb-10 mb-6">
              <span
                class="inline-block bg-primary/10 text-primary px-3 py-1 rounded text-sm font-medium mb-5">
                {{ optional($blog->category)->name ?? __('Tech Tips')}}
              </span>
              <h2 class="text-2xl lg:text-3xl font-bold mb-6">{{ $blog->title ?? '5 Essential Tips for Smart Grocery Shopping and Food Storage' }}</h2>
              <div class="flex flex-wrap items-center text-gray-500 mb-6 sm:gap-5 gap-3">
                <div class="flex items-center gap-3">
                  <img
                    src="{{ asset('avatar.png') }}"
                    alt="Emma Wilson" class="w-10 h-10 rounded-full" />
                  <span>{{ ucwords($user->name ?? 'Emma Wilson') }}</span>
                </div>
                <div class="flex flex-wrap items-center sm:gap-5 gap-3">
                  <div class="flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                      class="h-4 w-4 mr-1">
                      <rect width="18" height="18" x="3" y="4" rx="2" ry="2" />
                      <line x1="16" x2="16" y1="2" y2="6" />
                      <line x1="8" x2="8" y1="2" y2="6" />
                      <line x1="3" x2="21" y1="10" y2="10" />
                    </svg>
                    <span>{{ $blog->created_at->format('M d, Y')}}</span>
                  </div>
                </div>
              </div>
              <!-- Featured Image -->
              <div class="rounded-xl overflow-hidden max-h-[450px] h-full mb-6">
                <img
                  src="{{get_file($blog->cover_image_path) ?? 'assets/images/article-banner-1.png' }}"
                  alt="article-banner"
                  class="w-full h-full object-cover" />
              </div>
            </div>
  
            <!-- Article Body -->
            <div>
              <p class="mb-4">{!! $blog->short_description ?? "It is a long established fact that a reader will be distracted by the readable content of a
                    page when looking at its layout." !!}</p>
              <p> {!! $blog->content !!} </p>
  
            <!-- Share Buttons -->
            <div class="mt-8 flex flex-wrap gap-4 items-center justify-between border-t border-b py-6">
              <h3 class="font-heading font-medium">{{ $themeSettings['article_social_title'] ?? __('Share this article:') }}</h3>
              <div class="flex flex-wrap md:gap-4 gap-3">
                @foreach (json_decode($themeSettings['article_repeater']) as $social)
                  <a href="{{ $social->link ?? '#' }}" class="text-gray-400 hover:text-primary transition-all duration-300">
                    <i class="{{ $social->icon ?? '#' }}"></i>
                  </a>
                @endforeach
                {{-- <a href="javascript:void(0)" class="bg-[#1877F2] text-white p-2 rounded-full hover:opacity-90 transition">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"
                    class="h-5 w-5">
                    <path
                      d="M9 8h-3v4h3v12h5v-12h3.642l.358-4h-4v-1.667c0-.955.192-1.333 1.115-1.333h2.885v-5h-3.808c-3.596 0-5.192 1.583-5.192 4.615v3.385z" />
                  </svg>
                </a>
                <a href="javascript:void(0)" class="bg-[#1DA1F2] text-white p-2 rounded-full hover:opacity-90 transition">
                    <svg class="svg-inline--fa fa-x-twitter h-5 w-5" aria-hidden="true" focusable="false" data-prefix="fab" data-icon="x-twitter" role="img" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" data-fa-i2svg=""><path fill="currentColor" d="M389.2 48h70.6L305.6 224.2 487 464H345L233.7 318.6 106.5 464H35.8L200.7 275.5 26.8 48H172.4L272.9 180.9 389.2 48zM364.4 421.8h39.1L151.1 88h-42L364.4 421.8z"></path></svg>
                  
                </a>
                <a href="javascript:void(0)" class="bg-[#0A66C2] text-white p-2 rounded-full hover:opacity-90 transition">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"
                    class="h-5 w-5">
                    <path
                      d="M4.98 3.5c0 1.381-1.11 2.5-2.48 2.5s-2.48-1.119-2.48-2.5c0-1.38 1.11-2.5 2.48-2.5s2.48 1.12 2.48 2.5zm.02 4.5h-5v16h5v-16zm7.982 0h-4.968v16h4.969v-8.399c0-4.67 6.029-5.052 6.029 0v8.399h4.988v-10.131c0-7.88-8.922-7.593-11.018-3.714v-2.155z" />
                  </svg>
                </a>
                <a href="javascript:void(0)" class="bg-[#25D366] text-white p-2 rounded-full hover:opacity-90 transition">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"
                    class="h-5 w-5">
                    <path
                      d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z" />
                  </svg>
                </a>
                <a href="javascript:void(0)" class="bg-[#E60023] text-white p-2 rounded-full hover:opacity-90 transition">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"
                    class="h-5 w-5">
                    <path
                      d="M12 0c-6.627 0-12 5.372-12 12 0 5.084 3.163 9.426 7.627 11.174-.105-.949-.2-2.405.042-3.441.218-.937 1.407-5.965 1.407-5.965s-.359-.719-.359-1.782c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738.098.119.112.224.083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.631-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146 1.124.347 2.317.535 3.554.535 6.627 0 12-5.373 12-12 0-6.628-5.373-12-12-12z"
                      fill-rule="evenodd" clip-rule="evenodd" />
                  </svg>
                </a>
                <a href="javascript:void(0)" class="bg-gray-700 text-white p-2 rounded-full hover:opacity-90 transition">
                  <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                    <rect width="20" height="16" x="2" y="4" rx="2" />
                    <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
                  </svg>
                </a> --}}
              </div>
            </div>
          <!-- Related Articles -->
          <div class="lg:mt-12 mt-6">
            <h2 class="font-bold text-2xl mb-6">{{ $themeSettings['article_blog_title'] ?? __('You Might Also Like') }}</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
              
              <!-- Related Article 1 -->
               @foreach ($blogs as $blog)
               <a href="{{ route('page.article', [$store->slug, $blog->slug]) }}" class="group">
                 <div class="mb-3 overflow-hidden rounded-lg">
                   <img src="{{ get_file($blog->cover_image_path ?? 'assets/images/article-1.png') }}" alt="article-image" class="w-full h-48 object-cover transition duration-300 transform group-hover:scale-105">
                 </div>
                 <span class="text-sm text-primary font-medium">{{$blog->title ?? 'Food Storage'}}</span>
                 <h3 class="font-semibold text-lg mt-2">{!! $blog->short_description ?? 'Beginner\'s Guide to Keeping Fruits and Veggies Fresh' !!}</h3>
               </a>
               @endforeach
            </div>
          </div>
        </div>
    </section>
    @endif
  </main>
@endsection