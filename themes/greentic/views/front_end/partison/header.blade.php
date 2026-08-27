<!-- Header -->
 @if ($themeSettings['header_status'] && $themeSettings['header_status'] == '1')
  <header class="site-header bg-white shadow-sm main-nav sticky top-0 z-10">
    <div class="md:container w-full mx-auto px-4">
      <div class="flex items-center justify-between py-4 gap-3">
        @if(isset($themeSettings['header_logo']) && !empty($themeSettings['header_logo']))
        <!-- Logo and Mobile Menu Toggle -->
        <div class="flex items-center">
          <h1 class="logo">
            <a href="{{ route('landing_page', $slug) }}" class="block md:max-w-[150px] max-w-[110px] w-full">
              <img src="{{ get_file($themeSettings['header_logo'] ?? '') }}" alt="logo" class="logo-img" loading="lazy">
            </a>
          </h1>
        </div>
        @endif
        @if(isset($themeSettings['header_search_status']) && $themeSettings['header_search_status'] == 1)
        <!-- Search Bar (Hidden on Mobile) -->
        <div class="hidden md:block mx-4 flex-1 max-w-[400px] w-full">
          <form id="desktop-search-form">
          <div class="search-bar">
            <input type="text" placeholder="{{ __('Search...') }}" id="desktop-search-input" aria-label="Search" list="products" name="search_product" id="product" class="ltr:pl-4 rtl:pr-4 rtl:text-left">
            <datalist id="products">
                @foreach ($search_products as $pro_id => $pros)
                    <option value="{{ $pros }}"></option>
                @endforeach
            </datalist>

            <button type="submit" class="btn-primary ltr:ml-2 rtl:mr-2">{{ $themeSettings['header_search_title'] ?? __('Search') }}</button>
          </div>
          </form>
        </div>
        @endif
        @if(isset($themeSettings['menu_bar_status']) && $themeSettings['menu_bar_status'] == 1)
        <!-- Desktop Navigation -->
        <ul class="main-nav hidden lg:flex xl:space-x-8 space-x-5 rtl:space-x-reverse">
          @include('front_end.hooks.header_button')
          @php
            $menuItems = getNavMenu($themeSettings['menu_bar_menu'] ?? '');
          @endphp
          @if (!empty($menuItems))
            @foreach ($menuItems as $key => $menu)
              @include('front_end.common.menu', ['item' => $menu, 'key' => $key, 'class' => 'text-gray-700 hover:text-primary font-medium transition-all duration-300 ltr:pe-4 rtl:ps-4'])
            @endforeach
          @endif        
        </ul>
        @endif

        <!-- Header Icons -->
        <div class="flex items-center md:gap-4 gap-2 header-icons rtl:space-x-reverse">
          @if(isset($themeSettings['header_search_status']) && $themeSettings['header_search_status'] == 1)
          <button id="search-toggle" class="text-gray-600 hover:text-primary-dark transition md:hidden ltr:ml-2 rtl:mr-2"
            aria-label="Search">
            <svg width="25" height="25" viewBox="0 0 25 25" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path
                d="M24.7633 23.6399L18.3087 17.2884C19.9989 15.452 21.0375 13.0234 21.0375 10.3509C21.0367 4.63387 16.3278 0 10.5186 0C4.7094 0 0.000488281 4.63387 0.000488281 10.3509C0.000488281 16.0678 4.7094 20.7017 10.5186 20.7017C13.0285 20.7017 15.3306 19.8335 17.1389 18.3902L23.6185 24.7667C23.9342 25.0777 24.4468 25.0777 24.7625 24.7667C25.079 24.4557 25.079 23.9509 24.7633 23.6399ZM10.5186 19.1092C5.60338 19.1092 1.61884 15.1879 1.61884 10.3509C1.61884 5.51376 5.60338 1.59254 10.5186 1.59254C15.4338 1.59254 19.4183 5.51376 19.4183 10.3509C19.4183 15.1879 15.4338 19.1092 10.5186 19.1092Z"
                fill="black" />
            </svg>
          </button>
          @endif
          @stack('marketPlaceListButton')
          @auth('customers')
          @if(isset($themeSettings['header_wishlist_status']) && $themeSettings['header_wishlist_status'] == 1)          
          <a href="{{ route('wishlist', $slug) }}" class="text-gray-600 hover:text-primary-dark transition relative ltr:ml-2 rtl:mr-2"
            aria-label="Wishlist">
            <svg width="25" height="25" viewBox="0 0 25 25" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path
                d="M12.4999 22.5319C12.2999 22.5319 12.0999 22.4554 11.9476 22.303L3.55537 13.9108C2.28662 12.6421 1.5874 10.9561 1.5874 9.16239C1.5874 7.36864 2.28662 5.68192 3.55537 4.41396C5.99756 1.97333 9.86631 1.80849 12.4999 3.91864C15.1335 1.80771 19.003 1.97255 21.4444 4.41396C22.7132 5.68271 23.4124 7.36864 23.4124 9.16239C23.4124 10.9561 22.7132 12.6429 21.4444 13.9108L13.0522 22.303C12.8999 22.4554 12.6999 22.5319 12.4999 22.5319ZM8.30381 4.01161C6.98428 4.01161 5.66475 4.51396 4.66006 5.51786C3.68662 6.4913 3.1499 7.78505 3.1499 9.16161C3.1499 10.5382 3.68662 11.8319 4.66006 12.8054L12.4999 20.6452L20.3397 12.8054C21.3132 11.8319 21.8499 10.5382 21.8499 9.16161C21.8499 7.78505 21.3132 6.4913 20.3397 5.51786C18.3304 3.50927 15.0616 3.50927 13.0522 5.51786C12.7468 5.82333 12.253 5.82333 11.9476 5.51786C10.9429 4.51317 9.62334 4.01161 8.30381 4.01161Z"
                fill="black" />
            </svg>
            <span
              class="absolute -top-1 -right-1 bg-accent-dark text-white text-xs rounded-full w-4 h-4 flex items-center justify-center bg-red-500 wishlist-count">0</span>
          </a>
          @endif
          @endauth
          @if(isset($themeSettings['header_cart_status']) && $themeSettings['header_cart_status'] == 1)
          <button id="cart-toggle" class="text-gray-600 hover:text-primary-dark transition relative ltr:ml-2 rtl:mr-2" aria-label="Cart">
            <svg width="25" height="25" viewBox="0 0 25 25" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path
                d="M8.05473 16.1623H8.05587C8.05683 16.1623 8.05778 16.1621 8.05873 16.1621H21.3379C21.6648 16.1621 21.9522 15.9452 22.0421 15.6309L24.9718 5.377C25.0349 5.15594 24.9906 4.91829 24.8524 4.7348C24.7139 4.55131 24.4974 4.44336 24.2676 4.44336H6.36558L5.84201 2.08721C5.76744 1.75209 5.47027 1.51367 5.12695 1.51367H0.732421C0.327873 1.51367 0 1.84154 0 2.24609C0 2.65064 0.327873 2.97851 0.732421 2.97851H4.53949C4.63218 3.39603 7.04498 14.2538 7.18383 14.8785C6.40545 15.2168 5.85937 15.9929 5.85937 16.8945C5.85937 18.1061 6.84509 19.0918 8.05664 19.0918H21.3379C21.7424 19.0918 22.0703 18.7639 22.0703 18.3594C22.0703 17.9548 21.7424 17.6269 21.3379 17.6269H8.05664C7.65285 17.6269 7.32421 17.2983 7.32421 16.8945C7.32421 16.4913 7.65171 16.1632 8.05473 16.1623ZM23.2965 5.9082L20.7853 14.6973H8.6441L6.69098 5.9082H23.2965Z"
                fill="black" />
              <path
                d="M7.32422 21.2891C7.32422 22.5006 8.30994 23.4863 9.52148 23.4863C10.733 23.4863 11.7187 22.5006 11.7187 21.2891C11.7187 20.0775 10.733 19.0918 9.52148 19.0918C8.30994 19.0918 7.32422 20.0775 7.32422 21.2891ZM9.52148 20.5566C9.92527 20.5566 10.2539 20.8853 10.2539 21.2891C10.2539 21.6928 9.92527 22.0215 9.52148 22.0215C9.1177 22.0215 8.78906 21.6928 8.78906 21.2891C8.78906 20.8853 9.1177 20.5566 9.52148 20.5566Z"
                fill="black" />
              <path
                d="M17.6758 21.2891C17.6758 22.5006 18.6615 23.4863 19.873 23.4863C21.0846 23.4863 22.0703 22.5006 22.0703 21.2891C22.0703 20.0775 21.0846 19.0918 19.873 19.0918C18.6615 19.0918 17.6758 20.0775 17.6758 21.2891ZM19.873 20.5566C20.2768 20.5566 20.6055 20.8853 20.6055 21.2891C20.6055 21.6928 20.2768 22.0215 19.873 22.0215C19.4693 22.0215 19.1406 21.6928 19.1406 21.2891C19.1406 20.8853 19.4693 20.5566 19.873 20.5566Z"
                fill="black" />
            </svg>
            <span id="cart-count"
              class="cart-count absolute -top-1 -right-1 bg-accent-dark text-white text-xs rounded-full w-4 h-4 flex items-center justify-center bg-secondary">0</span>
          </button>
          @endif
          @auth('customers')
          <a href="{{ route('my-account.index', $slug) }}" class="text-gray-600 hover:text-primary-dark transition ltr:ml-2 rtl:mr-2" aria-label="Account">
            <svg width="25" height="25" viewBox="0 0 25 25" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path
                d="M12.5 0C8.86528 0 5.9082 2.95708 5.9082 6.5918C5.9082 10.2265 8.86528 13.1836 12.5 13.1836C16.1347 13.1836 19.0918 10.2265 19.0918 6.5918C19.0918 2.95708 16.1347 0 12.5 0ZM12.5 11.7188C9.673 11.7188 7.37305 9.4188 7.37305 6.5918C7.37305 3.7648 9.673 1.46484 12.5 1.46484C15.327 1.46484 17.627 3.7648 17.627 6.5918C17.627 9.4188 15.327 11.7188 12.5 11.7188Z"
                fill="black" />
              <path
                d="M20.7015 17.49C18.8968 15.6576 16.5043 14.6484 13.9648 14.6484H11.0352C8.4957 14.6484 6.10322 15.6576 4.29853 17.49C2.50269 19.3134 1.51367 21.7204 1.51367 24.2676C1.51367 24.6721 1.8416 25 2.24609 25H22.7539C23.1584 25 23.4863 24.6721 23.4863 24.2676C23.4863 21.7204 22.4973 19.3134 20.7015 17.49ZM3.01074 23.5352C3.37769 19.3806 6.83647 16.1133 11.0352 16.1133H13.9648C18.1635 16.1133 21.6223 19.3806 21.9893 23.5352H3.01074Z"
                fill="black" />
            </svg>
          </a>
          <form method="POST" action="{{ route('customer.logout',$slug) }}" id="form_logout" class="flex">
          <a href="#" onclick="event.preventDefault(); this.closest('form').submit();" class="text-gray-600 hover:text-primary-dark transition ltr:ml-2 rtl:mr-2" aria-label="Account">
             @csrf
             <svg width="25" height="25" viewBox="0 0 25 25" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M10.8394 3.46729C11.2536 3.46729 11.5894 3.13149 11.5894 2.71729C11.5894 2.30307 11.2536 1.96729 10.8394 1.96729H5.09327C3.29832 1.96729 1.84326 3.42236 1.84326 5.21729V19.7831C1.84326 21.578 3.29832 23.0331 5.09325 23.0331H10.8394C11.2536 23.0331 11.5894 22.6973 11.5894 22.2831C11.5894 21.8689 11.2536 21.5331 10.8394 21.5331H5.09325C4.12677 21.5331 3.34325 20.7496 3.34325 19.7831L3.34327 5.21729C3.34327 4.25079 4.12677 3.46729 5.09327 3.46729H10.8394Z" fill="black"></path>
              <path d="M18.0054 6.71089C17.7125 6.41799 17.2377 6.41799 16.9448 6.71089C16.6519 7.00379 16.6519 7.47864 16.9448 7.77154L20.6574 11.4841H6.99927C6.58507 11.4841 6.24927 11.8199 6.24927 12.2341C6.24927 12.6483 6.58507 12.9841 6.99927 12.9841H20.6056L16.9449 16.6448C16.652 16.9377 16.652 17.4126 16.9449 17.7055C17.2378 17.9984 17.7127 17.9984 18.0056 17.7055L22.9368 12.7742C23.0833 12.6277 23.1566 12.4357 23.1565 12.2437L23.1566 12.2341C23.1566 12.223 23.1563 12.2118 23.1558 12.2008C23.1634 11.9995 23.0903 11.7958 22.9367 11.6421L18.0054 6.71089Z" fill="black"></path>
             </svg>
          </a>
          </form>
          @endauth
          @guest('customers')
          @if(isset($themeSettings['header_login_status']) && $themeSettings['header_login_status'] == 1)
          <a href="{{ route('customer.login', $slug) }}" class="text-gray-600 hover:text-primary-dark transition ltr:ml-2 rtl:mr-2" aria-label="Account">
            <svg width="25" height="25" viewBox="0 0 25 25" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path
                d="M12.5 0C8.86528 0 5.9082 2.95708 5.9082 6.5918C5.9082 10.2265 8.86528 13.1836 12.5 13.1836C16.1347 13.1836 19.0918 10.2265 19.0918 6.5918C19.0918 2.95708 16.1347 0 12.5 0ZM12.5 11.7188C9.673 11.7188 7.37305 9.4188 7.37305 6.5918C7.37305 3.7648 9.673 1.46484 12.5 1.46484C15.327 1.46484 17.627 3.7648 17.627 6.5918C17.627 9.4188 15.327 11.7188 12.5 11.7188Z"
                fill="black" />
              <path
                d="M20.7015 17.49C18.8968 15.6576 16.5043 14.6484 13.9648 14.6484H11.0352C8.4957 14.6484 6.10322 15.6576 4.29853 17.49C2.50269 19.3134 1.51367 21.7204 1.51367 24.2676C1.51367 24.6721 1.8416 25 2.24609 25H22.7539C23.1584 25 23.4863 24.6721 23.4863 24.2676C23.4863 21.7204 22.4973 19.3134 20.7015 17.49ZM3.01074 23.5352C3.37769 19.3806 6.83647 16.1133 11.0352 16.1133H13.9648C18.1635 16.1133 21.6223 19.3806 21.9893 23.5352H3.01074Z"
                fill="black" />
            </svg>
          </a>
           @endif
          @endauth
          <button id="mobile-menu-toggle" class="text-gray-600 hover:text-primary-dark lg:hidden" aria-label="Menu">
            <svg width="25" height="20" viewBox="0 0 25 20" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path
                d="M24.005 1.99005H0.870647C0.373134 1.99005 0 1.61692 0 0.995025C0 0.373134 0.373135 0 0.995025 0H24.005C24.5025 0 25 0.373134 25 0.995025C25 1.61692 24.5025 1.99005 24.005 1.99005Z"
                fill="black" />
              <path
                d="M24.005 10.6961H0.870647C0.373134 10.6961 0 10.323 0 9.70108C0 9.07919 0.373135 8.70605 0.995025 8.70605H24.005C24.5025 8.70605 25 9.07919 25 9.70108C25 10.323 24.5025 10.6961 24.005 10.6961Z"
                fill="black" />
              <path
                d="M24.005 19.4031H0.870647C0.373134 19.4031 0 19.03 0 18.4081C0 17.7862 0.373135 17.4131 0.995025 17.4131H24.005C24.5025 17.4131 25 17.7862 25 18.4081C25 19.03 24.5025 19.4031 24.005 19.4031Z"
                fill="black" />
            </svg>
          </button>
        </div>
      </div>
    </div>
    @if(isset($themeSettings['header_search_status']) && $themeSettings['header_search_status'] == 1)
    <!-- Mobile Search (Hidden by default) -->
    <div id="mobile-search" class="md:hidden bg-white px-4 py-2 hidden">
      <div class="search-bar relative">
        <input type="text" placeholder="Search for organic products..."
          class="w-full ltr:pl-10 rtl:pr-10 py-2 border border-gray-300 rounded-full">
        <i class="fas fa-search absolute ltr:left-4 rtl:right-4 top-3 text-gray-400"></i>
      </div>
    </div>
    @endif
  </header>
@endif