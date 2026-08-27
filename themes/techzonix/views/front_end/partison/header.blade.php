<header class="site-header bg-white shadow sticky top-0 z-10 py-3">

    <div class="container mx-auto px-4">
        <div class="flex items-center justify-between gap-5">
            <!-- Logo -->
            @if(isset($themeSettings['header_logo']) && !empty($themeSettings['header_logo']))
                <div class="logo-col">
                    <h1>
                        <a href="{{ route('landing_page', $slug) }}">
                             <img src="{{ get_file($themeSettings['header_logo'] ?? 'themes/techzonix/assets/images/logo.png') }}" alt="logo" class="logo-img" loading="lazy">
                        </a>
                    </h1>
                </div>
            @endif

            <!-- Desktop Navigation -->
            <ul class="main-nav hidden lg:flex xl:gap-8 gap-4">
                @if (isset($themeSettings['menu_bar_status']) && $themeSettings['menu_bar_status'] == 1)
                    @php
                        $menuItems = getNavMenu($themeSettings['menu_bar_menu'] ?? '');
                    @endphp
                    @if (!empty($menuItems))
                        @foreach ($menuItems as $key => $menu)
                            @include('front_end.common.menu', ['item' => $menu, 'key' => $key])
                        @endforeach
                    @endif
                @endif
            </ul>

            <!-- Search Bar -->
            @if(isset($themeSettings['header_search_status']) && $themeSettings['header_search_status'] == 1)
                <div class="hidden lg:flex flex-1 xl:max-w-sm max-w-[250px]" id="desktop-search-form">
                    <div class="relative w-full">
                        <input type="search" placeholder="Search products..." class="form-input pe-10 search_input"
                            list="products" name="search_product" id="product">

                        <datalist id="products">
                            @foreach ($search_products as $pro_id => $pros)
                                <option value="{{ $pros }}"></option>
                            @endforeach
                        </datalist>

                        <button type="submit" class="absolute ltr:right-3 rtl:left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
            @endif

            <!-- Cart and Mobile Menu Button -->
            <ul class="flex items-center sm:gap-3 gap-2">
                @if(isset($themeSettings['header_search_status']) && $themeSettings['header_search_status'] == 1)
                    <!-- Search Button for Mobile -->
                    <button id="search-toggle" class="lg:hidden text-gray-700 hover:text-orange-500">
                        <svg class="w-5 h-5" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M9.58366 17.5003C13.9559 17.5003 17.5003 13.9559 17.5003 9.58366C17.5003 5.21141 13.9559 1.66699 9.58366 1.66699C5.21141 1.66699 1.66699 5.21141 1.66699 9.58366C1.66699 13.9559 5.21141 17.5003 9.58366 17.5003Z"
                                stroke="#111827" stroke-width="1.23" stroke-linecap="round" stroke-linejoin="round"></path>
                            <path d="M18.3337 18.3337L16.667 16.667" stroke="#111827" stroke-width="1.23"
                                stroke-linecap="round" stroke-linejoin="round"></path>
                        </svg>
                    </button>
                @endif
                @stack('marketPlaceListButton')
                @if(isset($themeSettings['header_wishlist_status']) && $themeSettings['header_wishlist_status'] == 1)
                    <li>
                        <a href="{{ route('wishlist', $slug) }}"
                            class="text-gray-600 hover:text-primary-dark transition relative" aria-label="Wishlist">
                            <svg class="w-5 h-5" viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path
                                    d="M10.7235 18.291C10.4382 18.3917 9.96839 18.3917 9.68314 18.291C7.25009 17.4604 1.81348 13.9954 1.81348 8.12256C1.81348 5.53011 3.90254 3.43265 6.47822 3.43265C8.00517 3.43265 9.35593 4.17095 10.2033 5.31197C11.0507 4.17095 12.4098 3.43265 13.9284 3.43265C16.5041 3.43265 18.5931 5.53011 18.5931 8.12256C18.5931 13.9954 13.1565 17.4604 10.7235 18.291Z"
                                    stroke="#1A1616" stroke-width="1.23051" stroke-linecap="round"
                                    stroke-linejoin="round" />
                            </svg>
                            <span class="absolute -top-1 -right-1 bg-accent-dark text-white text-xs rounded-full w-4 h-4 flex items-center justify-center bg-red-500 wishlist-count">0</span>
                        </a>
                    </li>
                @endif
                @if(isset($themeSettings['header_cart_status']) && $themeSettings['header_cart_status'] == 1)
                    <li>
                        <a href="javascript:void(0);" id="cart-toggle"
                            class="text-gray-600 hover:text-primary-dark transition relative">
                            <svg class="w-5 h-5" viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path
                                    d="M7.03809 7.26663V6.45282C7.03809 4.56511 8.55665 2.71095 10.4444 2.53477C12.6928 2.31663 14.5889 4.08689 14.5889 6.29341V7.45121"
                                    stroke="#1A1616" stroke-width="1.2305" stroke-miterlimit="10" stroke-linecap="round"
                                    stroke-linejoin="round" />
                                <path
                                    d="M8.29748 19.2897H13.3314C16.7041 19.2897 17.3082 17.9389 17.4844 16.2945L18.1136 11.2606C18.3401 9.21348 17.7528 7.5439 14.1704 7.5439H7.4585C3.87604 7.5439 3.28875 9.21348 3.51528 11.2606L4.14452 16.2945C4.3207 17.9389 4.92477 19.2897 8.29748 19.2897Z"
                                    stroke="#1A1616" stroke-width="1.2305" stroke-miterlimit="10" stroke-linecap="round"
                                    stroke-linejoin="round" />
                                <path d="M13.7459 10.8998H13.7535" stroke="#1A1616" stroke-width="1.78982"
                                    stroke-linecap="round" stroke-linejoin="round" />
                                <path d="M7.87289 10.8998H7.88043" stroke="#1A1616" stroke-width="1.78982"
                                    stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            <span id="cart-count"
                                class="absolute -top-1.5 -right-1.5 bg-primary text-white text-xs rounded-full h-3.5 w-3.5 flex items-center justify-center">0</span>
                        </a>

                    </li>
                @endif
                @auth('customers')
                    <li>
                        <a href="{{ route('my-account.index', $slug) }}">
                            <svg class="w-5 h-5" viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path
                                    d="M10.068 10.8998C12.3847 10.8998 14.2629 9.02167 14.2629 6.70488C14.2629 4.38809 12.3847 2.50996 10.068 2.50996C7.75117 2.50996 5.87305 4.38809 5.87305 6.70488C5.87305 9.02167 7.75117 10.8998 10.068 10.8998Z"
                                    stroke="#1A1616" stroke-width="1.23051" stroke-linecap="round"
                                    stroke-linejoin="round" />
                                <path
                                    d="M17.2746 19.2896C17.2746 16.0428 14.0445 13.4167 10.0677 13.4167C6.09092 13.4167 2.86084 16.0428 2.86084 19.2896"
                                    stroke="#1A1616" stroke-width="1.23051" stroke-linecap="round"
                                    stroke-linejoin="round" />
                            </svg>
                        </a>

                    </li>
                    <li>
                        <form method="POST" action="{{ route('customer.logout', $slug) }}" id="form_logout">
                            <a href="#" onclick="event.preventDefault(); this.closest('form').submit();"
                                class="text-gray-600 hover:text-primary-dark transition" aria-label="Account">
                                @csrf
                                <svg class="w-5 h-5" width="25" height="25" viewBox="0 0 25 25" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M10.8394 3.46729C11.2536 3.46729 11.5894 3.13149 11.5894 2.71729C11.5894 2.30307 11.2536 1.96729 10.8394 1.96729H5.09327C3.29832 1.96729 1.84326 3.42236 1.84326 5.21729V19.7831C1.84326 21.578 3.29832 23.0331 5.09325 23.0331H10.8394C11.2536 23.0331 11.5894 22.6973 11.5894 22.2831C11.5894 21.8689 11.2536 21.5331 10.8394 21.5331H5.09325C4.12677 21.5331 3.34325 20.7496 3.34325 19.7831L3.34327 5.21729C3.34327 4.25079 4.12677 3.46729 5.09327 3.46729H10.8394Z" fill="#1A1616"></path>
                                    <path d="M18.0054 6.71089C17.7125 6.41799 17.2377 6.41799 16.9448 6.71089C16.6519 7.00379 16.6519 7.47864 16.9448 7.77154L20.6574 11.4841H6.99927C6.58507 11.4841 6.24927 11.8199 6.24927 12.2341C6.24927 12.6483 6.58507 12.9841 6.99927 12.9841H20.6056L16.9449 16.6448C16.652 16.9377 16.652 17.4126 16.9449 17.7055C17.2378 17.9984 17.7127 17.9984 18.0056 17.7055L22.9368 12.7742C23.0833 12.6277 23.1566 12.4357 23.1565 12.2437L23.1566 12.2341C23.1566 12.223 23.1563 12.2118 23.1558 12.2008C23.1634 11.9995 23.0903 11.7958 22.9367 11.6421L18.0054 6.71089Z" fill="black"></path>
                                </svg>
                            </a>
                        </form>
                    </li>
                @endauth
                @guest('customers')
                    <li>
                        <a href="{{ route('customer.login', $slug) }}">
                            <svg class="w-5 h-5" viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path
                                    d="M10.068 10.8998C12.3847 10.8998 14.2629 9.02167 14.2629 6.70488C14.2629 4.38809 12.3847 2.50996 10.068 2.50996C7.75117 2.50996 5.87305 4.38809 5.87305 6.70488C5.87305 9.02167 7.75117 10.8998 10.068 10.8998Z"
                                    stroke="#1A1616" stroke-width="1.23051" stroke-linecap="round"
                                    stroke-linejoin="round" />
                                <path
                                    d="M17.2746 19.2896C17.2746 16.0428 14.0445 13.4167 10.0677 13.4167C6.09092 13.4167 2.86084 16.0428 2.86084 19.2896"
                                    stroke="#1A1616" stroke-width="1.23051" stroke-linecap="round"
                                    stroke-linejoin="round" />
                            </svg>
                        </a>
                    </li>
                @endguest
                <li class="lg:hidden ms-1">
                    <a href="javascript:void(0);" id="mobile-menu-toggle">
                        <svg class="w-5 h-5" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M2 4H18" stroke="#111827" stroke-width="1.25" stroke-linecap="round" />
                            <path d="M2 10H18" stroke="#111827" stroke-width="1.25" stroke-linecap="round" />
                            <path d="M2 16H18" stroke="#111827" stroke-width="1.25" stroke-linecap="round" />
                        </svg>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</header>
@push('page-script')
@endpush