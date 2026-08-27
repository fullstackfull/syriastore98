@props(['blog', 'store', 'themeSettings'])

<div class="card flex flex-col h-full">
    <div class="relative h-48 bg-gray-100">
        <a href="{{ route('page.article', [$store->slug, $blog->slug]) }}">
            <img src="{{ get_file($blog->cover_image_path) }}" alt="5G Technology" class="w-full h-full object-cover">
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