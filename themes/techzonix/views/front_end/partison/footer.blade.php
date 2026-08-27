@if ($themeSettings['footer_status'] && $themeSettings['footer_status'] == '1')
  <footer class="site-footer bg-gray-900 text-white lg:pt-20 pt-10 pb-5">
    <div class="container mx-auto px-4">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 lg:gap-8 gap-5">
        
        {{-- Logo + Description + Social --}}
        <div class="lg:col-span-2">
          <div class="footer-logo mb-3 max-w-[150px]">
            @if (!empty($themeSettings['footer_logo']))
              <a href="{{ url('/') }}">
                <img src="{{ get_file($themeSettings['footer_logo']) }}" alt="logo" loading="lazy" class="w-full h-auto">
              </a>
            @endif
          </div>
          <p class="text-gray-400 mb-4">
            {!! $themeSettings['footer_description'] ?? '' !!}
          </p>
          <div class="flex gap-4">
            @foreach (json_decode($themeSettings['footer_repeater']) ?? [] as $social)
              <a href="{{ $social->link ?? '#' }}" class="text-gray-400 hover:text-white transition-colors" aria-label="Social">
                <i class="{{ $social->icon ?? '' }} md:text-xl text-lg"></i>
              </a>
            @endforeach
          </div>
        </div>

        {{-- Footer Menus --}}
        @if(isset($themeSettings['footer_menu_status']) && $themeSettings['footer_menu_status'] == 1)
          @foreach(json_decode($themeSettings['footer_menu_repeater']) ?? [] as $footer_menu)
            <div>
              <h4 class="text-lg font-semibold mb-4">{{ $footer_menu->title ?? '' }}</h4>
              <ul class="space-y-2">
                @php
                  $menuItems = getNavMenu($footer_menu->menu ?? '');
                @endphp
                @foreach ($menuItems ?? [] as $menu)
                  <li>
                    <a href="{{ $menu['url'] ?? '#' }}" class="text-gray-400 hover:text-white transition-colors">
                      {{ $menu['title'] ?? '' }}
                    </a>
                  </li>
                @endforeach
              </ul>
            </div>
          @endforeach
        @endif

        {{-- Contact Info --}}
        @if ($themeSettings['footer_contact_status'] && $themeSettings['footer_contact_status'] == '1')
          <div>
            <h4 class="text-lg font-semibold mb-4">{{ $themeSettings['footer_contact_title'] ?? '' }}</h4>
            <ul class="space-y-2 text-gray-400">
              <li class="flex items-center gap-2">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                {{ $themeSettings['footer_contact_address'] ?? '' }}
              </li>
              <li class="flex items-center gap-2">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                </svg>
                <a href="tel:+{{ $themeSettings['footer_contact_phone'] ?? '' }}" class="text-gray-400">
                  {{ $themeSettings['footer_contact_phone'] ?? '' }}
                </a>
              </li>
              <li class="flex items-center gap-2">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
                <a href="mailto:{{ $themeSettings['footer_contact_email'] ?? '' }}" class="text-gray-400">
                  {{ $themeSettings['footer_contact_email'] ?? '' }}
                </a>
              </li>
            </ul>
          </div>
        @endif
      </div>

      {{-- Copyright --}}
      @if ($themeSettings['copy_right_status'] && $themeSettings['copy_right_status'] == '1')
        <div class="border-t border-gray-800 mt-8 pt-5 text-center text-gray-400">
          <p>{{ $themeSettings['copy_right_description'] ?? '' }}</p>
        </div>
      @endif
    </div>
  </footer>
@endif
