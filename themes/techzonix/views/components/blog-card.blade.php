@props(['blog', 'store', 'themeSettings'])

<div class="card flex flex-col h-full">
    <div class="relative h-48 bg-gray-100">
        <a href="{{ route('page.article', [$store->slug, $blog->slug]) }}">
            {{-- GAP-274: this file sits outside the trees the markup half's census reads, which is
                 how it kept the browser default AND a shipped demo alt that announced every article
                 in the blog as one named "5G Technology" -- to a screen reader and a crawler alike.
                 A blog card is always in a listing or a slider, never the first paint. --}}
            <img src="{{ storefront_image($blog->cover_image_path) }}" alt="{{ $blog->title ?? '' }}"
                loading="lazy" decoding="async" class="w-full h-full object-cover">
        </a>
    </div>
    <div class="flex flex-col h-full p-4">
        <div class="flex-1">
            <div class="flex items-center gap-3 text-sm text-gray-500 mb-2">
                <span><i class="far fa-calendar-alt ltr:mr-1 rtl:ml-1"></i> {{ $blog->created_at }}</span>
            </div>
            <h3 class="text-lg font-medium text-gray-900 mb-2 line-clamp-1">
                <a href="{{ route('page.article', [$store->slug, $blog->slug]) }}">{{ $blog->title }}</a>
            </h3>
            <p class="text-gray-600 mb-4 line-clamp-2">{!! $blog->short_description !!}</p>
        </div>
        <a href="{{ route('page.article', [$store->slug, $blog->slug]) }}"
            class="inline-flex items-center text-primary hover:text-primary-dark font-medium gap-2">
            {!! $themeSettings['blog_button_text'] ?? __('Read More') !!} <i class="fas fa-arrow-right text-sm"></i>
        </a>
    </div>
</div>