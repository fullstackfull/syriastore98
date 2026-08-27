@if(isset($themeSettings['announce_bar_status']) && $themeSettings['announce_bar_status'] == 1)
<!-- Top Header -->
  <div class="announcement-bar bg-white py-2 border-b border-gray-200 text-sm lg:flex hidden relative z-20 rtl:space-x-reverse">
    <div class="md:container w-full mx-auto px-4">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between">
        <!-- Contact Info -->
        <div class="flex justify-center md:justify-start space-x-4 mb-2 md:mb-0 rtl:space-x-reverse">
          <a href="tel:+{{ $themeSettings['announce_bar_contact_no'] ?? '' }}" class="text-gray-600 hover:text-primary-dark flex items-center">
            <i class="fas fa-phone rtl:ml-2 ltr:mr-2"></i>
            <span>{{ $themeSettings['announce_bar_contact_no'] ?? '' }}</span>
          </a>
          <a href="mailto:{{ $themeSettings['announce_bar_email'] ?? '' }}" class="text-gray-600 hover:text-primary-dark flex items-center">
            <i class="fas fa-envelope rtl:ml-2 ltr:mr-2"></i>
            <span>{{ $themeSettings['announce_bar_email'] ?? '' }}</span>
          </a>
        </div>
        <div class="flex items-center gap-3">
          <div class="relative inline-block ltr:text-left rtl:text-right text-sm">
            <button data-dropdown-toggle="language" type="button"
              class="flex items-center gap-2 px-4 py-2 border rounded-md">
              <span>{{ $languages[$currantLang] ?? 'English'}}</span>
              <i class="fas fa-chevron-down text-sm"></i>
            </button>
            <div data-dropdown-menu="language" class="absolute rtl:left-0 ltr:right-0 mt-2 py-2 min-w-28 bg-white border border-gray-200 rounded-lg shadow-lg hidden max-h-[200px] overflow-y-auto">
              @foreach($languages as $key => $value)
              <a href="{{ route('change.languagestore', $key) }}" class="block px-4 py-2 text-gray-700 hover:bg-gray-100 @if ($currantLang == $key) text-primary @endif">{{ $value }}</a>
              @endforeach
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
@endif