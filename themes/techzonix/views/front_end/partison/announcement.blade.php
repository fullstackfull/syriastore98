@if (isset($themeSettings['announce_bar_status']) && $themeSettings['announce_bar_status'] == 1)
<div class="announcement-bar bg-primary py-2 text-white relative z-20 hidden lg:block">
    <div class="container mx-auto px-4">
        <div class="flex items-center justify-between gap-3">
            <p>
                <i class="fas fa-truck ltr:mr-2 rtl:ml-2"></i>{{ $themeSettings['announce_bar_support_title'] ?? __('Free shipping on orders over $50!') }} 
                <a href="{{ route('page.product-list',$slug) }}">{{ $themeSettings['announce_bar_button_text'] ?? __('Shop now') }}</a>
            </p>
            <div class="flex items-center gap-3">
                <div class="relative inline-block text-left text-sm">
                    <button data-dropdown-toggle="language" type="button"
                        class="flex items-center gap-2 px-4 py-2 border rounded-md">
                        <span>{{ Str::upper($currantLang) }}</span>
                        <i class="fas fa-chevron-down text-sm"></i>
                    </button>

                    <div data-dropdown-menu="language"
                        class="absolute right-0 mt-2 py-2 min-w-28 bg-white border border-gray-200 rounded-lg shadow-lg hidden max-h-[200px] overflow-y-auto">
                        @foreach ($languages as $code => $language)
                            <a href="{{ route('change.languagestore', [$code]) }}"
                                class="block px-4 py-2 text-gray-700 hover:bg-gray-100 @if ($language == $currantLang) active-language text-primary @endif">{{  ucFirst($language) }}
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endif