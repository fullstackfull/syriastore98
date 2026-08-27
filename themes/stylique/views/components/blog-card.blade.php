@props(['blog', 'store', 'themeSettings'])

<div class="h-full flex flex-col bg-white rounded-lg overflow-hidden shadow-md hover:shadow-lg transition-shadow duration-300">
    <div class="relative">
        <a href="{{ route('page.article', [$store->slug, $blog->slug]) }}">
            <img src="{{ get_file($blog->cover_image_path) ?? asset('assets/images/blog-image1.png') }}"
            width="400" height="400" alt="Blog post image" class="w-full h-60 object-cover object-top"/>
        </a>
    </div>
    <div class="h-full flex flex-col">
    <div class="p-4 pb-0 flex-1">
        <div class="flex items-center text-gray-500 text-sm mb-2">
        <i class="far fa-calendar-alt ltr:mr-2 rtl:ml-2"></i>
        <span>{{ \Carbon\Carbon::parse($blog->created_at)->format('M d, Y') }}</span>
        <span class="mx-2">•</span>
        <span>{{ $blog->category->name ?? '' }}</span>
        </div>
        <h3 class="font-bold text-xl mb-2">{{ $blog->title }}</h3>
        <p class="text-gray-600 mb-4 line-clamp-2">
        {!! $blog->short_description !!}
        </p>
    </div>
    <div class="p-4 pt-0">
        <a href="{{ route('page.article', [$store->slug, $blog->slug]) }}" class="text-primary font-medium hover:underline">
    {{ $themeSettings['blog_button_text'] ?? __('Read More') }}
        </a>
    </div>
    </div>
</div>