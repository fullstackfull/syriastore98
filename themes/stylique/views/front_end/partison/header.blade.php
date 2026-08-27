<header class="bg-white sticky-header site-header">
     <!-- Middle Section -->
    <div class="py-4 border-b border-gray-200">
      <div class="md:container mx-auto px-4 w-full">
        <div class="flex items-center justify-between gap-2">
            @if(isset($themeSettings['header_logo']) && !empty($themeSettings['header_logo']))
              <!-- Logo -->
              <div class="logo-col">
                <h1>
                    <a href="{{ route('landing_page', $slug) }}">
                      <img src="{{ get_file($themeSettings['header_logo'] ?? '') }}" alt="Logo">
                    </a>
                </h1>
              </div>
            @endif
            @if(isset($themeSettings['header_search_status']) && $themeSettings['header_search_status'] == 1)
              <!-- Search Bar (Hidden on mobile) -->
              <div class="hidden lg:flex flex-1 max-w-xl mx-4">
                <form class="w-full flex" id="desktop-search-form">
                    <div class="relative">
                            <select class="border border-gray-300 rounded-l-lg rtl:rounded-r-lg rtl:rounded-l-none">
                                    <option>{{__("All Categories")}}</option>
                                @foreach ($MainCategoryList as $category)
                                    <option>{{$category->name}}</option>
                                @endforeach
                            </select>
                    </div>

                    <div class="flex-grow relative search-input">
                      <input type="text" id="desktop-search-input" class="w-full border-t border-b border-gray-300 px-4 py-2 outline-none" placeholder="{{ __('Search...') }}" aria-label="Search" list="products" name="search_product" id="product">
                      <datalist id="products">
                          @foreach ($search_products as $pro_id => $pros)
                              <option value="{{ $pros }}"></option>
                          @endforeach
                      </datalist>
                      </div>
                      <button type="submit" class="btn-primary px-4 py-2 rounded-l-none rtl:rounded-l-lg rtl:rounded-r-none">
                        <i class="fas fa-search"></i>
                      </button>
                </form>
              </div>
            @endif
          <!-- Icons (Wishlist, Compare, Cart) -->


          <div class="flex items-center gap-3">

            @if(isset($themeSettings['header_search_status']) && $themeSettings['header_search_status'] == 1)
              <!-- Search Button for Mobile -->
              <button id="search-toggle" class="lg:hidden text-gray-700 hover:text-orange-500">
                  <svg class="w-5 h-5" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <path d="M9.58366 17.5003C13.9559 17.5003 17.5003 13.9559 17.5003 9.58366C17.5003 5.21141 13.9559 1.66699 9.58366 1.66699C5.21141 1.66699 1.66699 5.21141 1.66699 9.58366C1.66699 13.9559 5.21141 17.5003 9.58366 17.5003Z" stroke="#111827" stroke-width="1.23" stroke-linecap="round" stroke-linejoin="round"></path>
                  <path d="M18.3337 18.3337L16.667 16.667" stroke="#111827" stroke-width="1.23" stroke-linecap="round" stroke-linejoin="round"></path>
                  </svg>
              </button>
            @endif



            @if(isset($themeSettings['header_login_status']) && $themeSettings['header_login_status'] == 1)
                  @auth('customers')
                        <a href="{{ route('my-account.index', $slug) }}" class="text-gray-600 hover:text-primary-dark transition" aria-label="Account">
                          <svg class="w-5 h-5" viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg">
                              <path d="M10.068 10.8998C12.3847 10.8998 14.2629 9.02167 14.2629 6.70488C14.2629 4.38809 12.3847 2.50996 10.068 2.50996C7.75117 2.50996 5.87305 4.38809 5.87305 6.70488C5.87305 9.02167 7.75117 10.8998 10.068 10.8998Z" stroke="#1A1616" stroke-width="1.23051" stroke-linecap="round" stroke-linejoin="round"></path>
                              <path d="M17.2746 19.2896C17.2746 16.0428 14.0445 13.4167 10.0677 13.4167C6.09092 13.4167 2.86084 16.0428 2.86084 19.2896" stroke="#1A1616" stroke-width="1.23051" stroke-linecap="round" stroke-linejoin="round"></path>
                          </svg>
                        </a>
                  @endauth
                  @guest('customers')
                  <a href="{{ route('customer.login',$slug) }}" class="relative text-gray-700 hover:text-orange-500">
                    <svg class="w-5 h-5" viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M10.068 10.8998C12.3847 10.8998 14.2629 9.02167 14.2629 6.70488C14.2629 4.38809 12.3847 2.50996 10.068 2.50996C7.75117 2.50996 5.87305 4.38809 5.87305 6.70488C5.87305 9.02167 7.75117 10.8998 10.068 10.8998Z" stroke="#1A1616" stroke-width="1.23051" stroke-linecap="round" stroke-linejoin="round"></path>
                        <path d="M17.2746 19.2896C17.2746 16.0428 14.0445 13.4167 10.0677 13.4167C6.09092 13.4167 2.86084 16.0428 2.86084 19.2896" stroke="#1A1616" stroke-width="1.23051" stroke-linecap="round" stroke-linejoin="round"></path>
                    </svg>
                  </a>
                  @endguest
            @endif
            @stack('marketPlaceListButton')
            @if(isset($themeSettings['header_wishlist_status']) && $themeSettings['header_wishlist_status'] == 1) 
                @auth('customers')
                <a href="{{ route('wishlist', $slug) }}" class="relative text-gray-700 hover:text-orange-500 wishlist-header wish-header">
                    <svg class="w-5 h-5" viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M10.7235 18.291C10.4382 18.3917 9.96839 18.3917 9.68314 18.291C7.25009 17.4604 1.81348 13.9954 1.81348 8.12256C1.81348 5.53011 3.90254 3.43265 6.47822 3.43265C8.00517 3.43265 9.35593 4.17095 10.2033 5.31197C11.0507 4.17095 12.4098 3.43265 13.9284 3.43265C16.5041 3.43265 18.5931 5.53011 18.5931 8.12256C18.5931 13.9954 13.1565 17.4604 10.7235 18.291Z" stroke="#1A1616" stroke-width="1.23051" stroke-linecap="round" stroke-linejoin="round"></path>
                    </svg>
                    <span class="cart-badge">{!! \App\Models\Wishlist::WishCount() !!}</span>
                </a>
                <!-- @include('front_end.hooks.header_button') -->
                @endauth

            @endif
 
            @if(isset($themeSettings['header_cart_status']) && $themeSettings['header_cart_status'] == 1)
              <a href="javascript:void(0);" class="cart-header relative text-gray-700 hover:text-orange-500" id="cart-toggle">
                
                  <svg class="w-5 h-5" viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg">
                      <path d="M7.03809 7.26663V6.45282C7.03809 4.56511 8.55665 2.71095 10.4444 2.53477C12.6928 2.31663 14.5889 4.08689 14.5889 6.29341V7.45121" stroke="#1A1616" stroke-width="1.2305" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"></path>
                      <path d="M8.29748 19.2897H13.3314C16.7041 19.2897 17.3082 17.9389 17.4844 16.2945L18.1136 11.2606C18.3401 9.21348 17.7528 7.5439 14.1704 7.5439H7.4585C3.87604 7.5439 3.28875 9.21348 3.51528 11.2606L4.14452 16.2945C4.3207 17.9389 4.92477 19.2897 8.29748 19.2897Z" stroke="#1A1616" stroke-width="1.2305" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"></path>
                      <path d="M13.7459 10.8998H13.7535" stroke="#1A1616" stroke-width="1.78982" stroke-linecap="round" stroke-linejoin="round"></path>
                      <path d="M7.87289 10.8998H7.88043" stroke="#1A1616" stroke-width="1.78982" stroke-linecap="round" stroke-linejoin="round"></path>
                  </svg>
                <span id="cart-count"
              class="cart-count cart-badge absolute -top-1 -right-1 bg-accent-dark text-white text-xs rounded-full w-4 h-4 flex items-center justify-center bg-secondary">0</span>
              </a>
            @endif
            @auth('customers')
          <form method="POST" action="{{ route('customer.logout',$slug) }}" id="form_logout" class="relative text-gray-700 hover:text-orange-500">
                  <a href="#" onclick="event.preventDefault(); this.closest('form').submit();" class="text-gray-600 hover:text-primary-dark transition" aria-label="Account">
                    @csrf
                    <svg class="w-5 h-5" width="25" height="25" viewBox="0 0 25 25" fill="none" xmlns="http://www.w3.org/2000/svg">
                      <path d="M10.8394 3.46729C11.2536 3.46729 11.5894 3.13149 11.5894 2.71729C11.5894 2.30307 11.2536 1.96729 10.8394 1.96729H5.09327C3.29832 1.96729 1.84326 3.42236 1.84326 5.21729V19.7831C1.84326 21.578 3.29832 23.0331 5.09325 23.0331H10.8394C11.2536 23.0331 11.5894 22.6973 11.5894 22.2831C11.5894 21.8689 11.2536 21.5331 10.8394 21.5331H5.09325C4.12677 21.5331 3.34325 20.7496 3.34325 19.7831L3.34327 5.21729C3.34327 4.25079 4.12677 3.46729 5.09327 3.46729H10.8394Z" fill="black"></path>
                      <path d="M18.0054 6.71089C17.7125 6.41799 17.2377 6.41799 16.9448 6.71089C16.6519 7.00379 16.6519 7.47864 16.9448 7.77154L20.6574 11.4841H6.99927C6.58507 11.4841 6.24927 11.8199 6.24927 12.2341C6.24927 12.6483 6.58507 12.9841 6.99927 12.9841H20.6056L16.9449 16.6448C16.652 16.9377 16.652 17.4126 16.9449 17.7055C17.2378 17.9984 17.7127 17.9984 18.0056 17.7055L22.9368 12.7742C23.0833 12.6277 23.1566 12.4357 23.1565 12.2437L23.1566 12.2341C23.1566 12.223 23.1563 12.2118 23.1558 12.2008C23.1634 11.9995 23.0903 11.7958 22.9367 11.6421L18.0054 6.71089Z" fill="black"></path>
                    </svg>
                  </a>
            </form>
            @endauth
 
            <!-- Mobile Menu Button (Hidden on desktop) -->
            <div class="lg:hidden flex">
              <button id="mobile-menu-toggle">
                <svg class="w-5 h-5" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <path d="M2 4H18" stroke="#111827" stroke-width="1.25" stroke-linecap="round"/>
                  <path d="M2 10H18" stroke="#111827" stroke-width="1.25" stroke-linecap="round"/>
                  <path d="M2 16H18" stroke="#111827" stroke-width="1.25" stroke-linecap="round" />
                </svg>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
 
    <!-- Desktop Navigation -->
    <nav class="bg-white hidden lg:block">
      @if(isset($themeSettings['header_search_status']) && $themeSettings['header_search_status'] == 1)
        <div class="md:container mx-auto px-4 w-full">
          <div class="flex justify-between items-center">
            <div class="flex">

              <!-- Categories Dropdown -->
              <div class="relative categories-wrap me-6">
                <button class="btn btn-primary px-6 py-3 rounded-none flex items-center">
                  <svg xmlns="http://www.w3.org/2000/svg" width="18" height="14" viewBox="0 0 14 10" fill="none" class="ltr:mr-2 rtl:ml-2">
                    <path d="M9.66667 1.5H1C0.867392 1.5 0.740215 1.44732 0.646447 1.35355C0.552678 1.25979 0.5 1.13261 0.5 1C0.5 0.867392 0.552678 0.740215 0.646447 0.646447C0.740215 0.552678 0.867392 0.5 1 0.5H9.66667C9.79928 0.5 9.92645 0.552678 10.0202 0.646447C10.114 0.740215 10.1667 0.867392 10.1667 1C10.1667 1.13261 10.114 1.25979 10.0202 1.35355C9.92645 1.44732 9.79928 1.5 9.66667 1.5ZM13.5 5C13.5 4.86739 13.4473 4.74022 13.3536 4.64645C13.2598 4.55268 13.1326 4.5 13 4.5H1C0.867392 4.5 0.740215 4.55268 0.646447 4.64645C0.552678 4.74022 0.5 4.86739 0.5 5C0.5 5.13261 0.552678 5.25979 0.646447 5.35355C0.740215 5.44732 0.867392 5.5 1 5.5H13C13.1326 5.5 13.2598 5.44732 13.3536 5.35355C13.4473 5.25979 13.5 5.13261 13.5 5ZM7.5 9C7.5 8.86739 7.44732 8.74021 7.35355 8.64645C7.25979 8.55268 7.13261 8.5 7 8.5H1C0.867392 8.5 0.740215 8.55268 0.646447 8.64645C0.552678 8.74021 0.5 8.86739 0.5 9C0.5 9.13261 0.552678 9.25979 0.646447 9.35355C0.740215 9.44732 0.867392 9.5 1 9.5H7C7.13261 9.5 7.25979 9.44732 7.35355 9.35355C7.44732 9.25979 7.5 9.13261 7.5 9Z" fill="white" />
                  </svg>
                  <span class="font-medium">{{__("Browse All Categories")}}</span>
                  <i class="fas fa-chevron-down ltr:ml-2 rtl:mr-2 text-sm"></i>
                </button>
                <ul class="category-dropdown">
                  @foreach ($MainCategoryList as $category)
                    <li class="relative">
                      <a href="{{ url($slug . '/' . $category->slug) }}" class="flex no-wrap" id="MainCategory_preview" tabindex="0">
                        <img src="{{ get_file($category->icon_path) }}" width="18" height="18" alt="category-img" loading="lazy" />
                        {{ $category->name }}
                      </a>
                    </li>
                  @endforeach
                </ul>
              </div>

              <!-- Main Navigation -->
              <ul class="main-nav flex gap-6">
                @if(isset($themeSettings['menu_bar_status']) && $themeSettings['menu_bar_status'] == 1)
                  @include('front_end.hooks.header_button') 
                <!-- Desktop Navigation -->
                      @php
                        $menuItems = getNavMenu($themeSettings['menu_bar_menu'] ?? '');
                      @endphp
                      @if (!empty($menuItems))
                      <ul class="main-nav flex gap-6">
                      @foreach($menuItems as $key => $item)
                          @include('front_end.common.menu', ['item' => $item, 'key' => $key])
                      @endforeach
                  </ul>

                      @endif        
                @endif
              </ul>
            </div>

            <div class="flex items-center gap-4">
              <div class="relative inline-block text-left text-sm">
                  <button data-dropdown-toggle="language" type="button" class="flex items-center gap-2 py-2 outline-none">
                      <span>{{ ucfirst($languages[$currantLang] ?? 'English') }}</span>
                      <i class="fas fa-chevron-down text-sm"></i>
                  </button>
                  <div data-dropdown-menu="language" class="absolute right-0 py-2 min-w-28 bg-white border border-gray-200 rounded-lg shadow-lg hidden max-h-[200px] overflow-y-auto">
                      @foreach ($languages as $code => $language)
                          <a href="{{ route('change.languagestore', [$code]) }}"
                            class="block px-4 py-2 text-gray-700 hover:bg-gray-100 @if ($language == $currantLang) text-primary font-semibold @endif">
                            {{ ucfirst($language) }}
                          </a>
                      @endforeach
                  </div>
              </div>
            </div>


          </div>
        </div>
      @endif
    </nav>

</header>