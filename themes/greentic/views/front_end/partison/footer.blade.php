<!-- Footer -->
@if ($themeSettings['footer_status'] && $themeSettings['footer_status'] == '1')
  <footer class="bg-gray-900 text-white md:pt-16 pt-10 md:pb-8 pb-4">
    <div class="md:container w-full mx-auto px-4">
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 md:gap-8 gap-5 md:mb-10 mb-6 md:text-base text-sm">
        <div>
          <div class="footer-logo md:max-w-[150px] max-w-[110px] mb-4">
            <img src="{{ get_file($themeSettings['footer_logo'] ?? '') }}" alt="logo" class="w-full h-full object-cover" loading="lazy">
          </div>
          <p class="text-gray-400 mb-4"> {!! $themeSettings['footer_description'] ?? '' !!}
          </p>
          <div class="flex gap-4">
             @foreach (json_decode($themeSettings['footer_repeater']) as $social)
              <a href="{{ $social->link ?? '#' }}" class="text-gray-400 hover:text-white" aria-label="SocialLink">
                <i class="{{ $social->icon ?? '#' }}"></i>
              </a>
              @endforeach
          </div>
        </div>
        @if(isset($themeSettings['footer_menu_status']) && $themeSettings['footer_menu_status'] == 1)
          @foreach(json_decode($themeSettings['footer_menu_repeater']) as $key => $footer_menu)
          <div>
            <h4 class="font-semibold text-lg mb-4">{{ $footer_menu->title }}</h4>
            <ul class="space-y-2">
              @php
                $menuItems = getNavMenu($footer_menu->menu ?? '');
              @endphp
              @if (!empty($menuItems))
                @foreach ($menuItems as $menu)
                <li><a href="{{ $menu['url'] ?? '#' }}"  class="text-gray-400 hover:text-white">{{ $menu['title'] ?? '' }}</a></li>
                @endforeach
              @endif
            </ul>
          </div>
          @endforeach
        @endif
        @if ($themeSettings['footer_contact_status'] && $themeSettings['footer_contact_status'] == '1')
        <div>
          <h4 class="font-semibold text-lg mb-4">{{ $themeSettings['footer_contact_title'] ?? '' }}</h4>
          <ul class="space-y-2">
            <li class="flex items-center text-gray-400 gap-2">
              <i class="fas fa-map-marker-alt"></i>  {{ $themeSettings['footer_contact_address'] ?? '' }}
            </li>
            <li class="flex items-center text-gray-400 gap-2">
              <i class="fas fa-phone"></i><a href="tel:+{{ $themeSettings['footer_contact_phone'] ?? '' }}" class="text-gray-400">{{ $themeSettings['footer_contact_phone'] ?? '' }}</a>
            </li>
            <li class="flex items-center text-gray-400 gap-2">
              <i class="fas fa-envelope"></i>
              <a href="mailto:{{ $themeSettings['footer_contact_email'] ?? '' }}" class="text-gray-400">{{ $themeSettings['footer_contact_email'] ?? '' }}</a>
            </li>
          </ul>
        </div>
        @endif
      </div>
      @if ($themeSettings['footer_copy_right_status'] && $themeSettings['footer_copy_right_status'] == '1')
      <div class="border-t border-gray-800 pt-4 md:pt-8 text-center">
        <p class="text-gray-400">{{ $themeSettings['footer_copy_right_description'] ?? '' }}</p>
      </div>
      @endif
    </div>
  </footer>
@endif 