
   
    <link rel="stylesheet" href="{{ asset('css/loader.css') }}{{ '?v=' . time() }}" >
     <link rel="stylesheet" href="{{ asset('assets/css/plugins/notifier.css') }}{{ '?v=' . time() }}" >
     
        @if((isset($loader_show) && $loader_show != 'no') || !isset($loader_show))
        <div id="loader" class="loader-wrapper" style="display: none;">
        <span class="site-loader"> </span>
        <h3 class="loader-content"> {{ __('Loading . . .') }} </h3>
        </div>
        @endif

         <!-- Cart Overlay and Panel -->
        <div id="cart-overlay" class="cart-overlay">
            <div id="cart-panel" class="cart-panel">
            </div>
        </div>
        @if ($themeSettings['header_status'] && $themeSettings['header_status'] == '1')
        <div id="mobile-menu" class="mobile-menu fixed inset-y-0 right-0 z-50 bg-white shadow-xl transform translate-x-full transition-transform duration-300 ease-in-out overflow-y-auto">
            <div class="flex justify-between items-center p-4 border-b">
            <a href="{{ route('landing_page', $slug) }}" class="block max-w-[110px] w-full">
                <img src="{{ get_file($themeSettings['header_logo'] ?? '') }}" alt="logo" class="logo-img">
            </a>
            <button id="close-mobile-menu" class="text-gray-500 hover:text-green-800 transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
            </div>
            <!-- Language and Currency Dropdowns -->
            <div class="language-currency-settings p-4 flex items-center justify-between gap-4 border-b">
            <!-- Language Select -->
            <div class="flex-1 relative inline-block ltr:text-left rtl:text-right text-sm">
                <button data-dropdown-toggle="mob-language" type="button"
                class="flex items-center justify-between gap-2 px-4 py-2 border rounded-md w-full">
                <span>{{ $languages[$currantLang] ?? 'English'}}</span>
                <i class="fas fa-chevron-down text-sm"></i>
                </button>
                <div data-dropdown-menu="mob-language"
                class="absolute right-0 mt-2 py-2 min-w-28 bg-white border border-gray-200 rounded-lg shadow-lg hidden w-full max-h-[200px] overflow-y-auto">
                @foreach($languages as $key => $value)
                <a href="{{ route('change.languagestore', $key) }}" class="block px-4 py-2 text-gray-700 hover:bg-gray-100 @if ($currantLang == $key) text-primary @endif">{{ $value }}</a>
                @endforeach
                </div>
            </div>
            </div>
            <nav class="p-4">
            <ul class="space-y-1">
                @php
                    $menuItems = getNavMenu($themeSettings['menu_bar_menu'] ?? '');
                @endphp
                @if (!empty($menuItems))
                    @foreach ($menuItems as $key => $menu)
                        @include('front_end.common.mobile-menu', [
                            'item' => $menu,
                            'key' => $key,
                            'class' => $key === 0 
                                ? 'text-primary font-medium bg-primary/10 transition-colors' 
                                : 'text-gray-700 font-medium hover:bg-gray-100'
                        ])
                    @endforeach
                @endif
            </ul>
            </nav>
        </div>
        @endif


    @if ($store->enable_pwa_store == 'on')
        <!-- PWA Install Modal -->
        <div id="pwa-install-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center">
            <div class="bg-white rounded-lg p-6 m-4 max-w-sm w-full">
                <div class="text-center">
                    <div class="mb-4">
                        <img src="{{ $faviconUrl ?? get_file($themeSettings['header_favicon'] ?? '') }}" alt="App Icon" class="w-16 h-16 mx-auto rounded-lg">
                    </div>
                    <h3 class="text-lg font-semibold mb-2">{{ __('Install App') }}</h3>
                    <p class="text-gray-600 mb-4">{{ __('Install our app for a better experience') }}</p>
                    <div class="flex gap-3">
                        <button id="pwa-install-btn" class="flex-1 bg-primary text-white px-4 py-2 rounded-lg hover:bg-primary/90">
                            {{ __('Install') }}
                        </button>
                        <button id="pwa-cancel-btn" class="flex-1 bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-300">
                            {{ __('Cancel') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
  <!-- Search Popup -->
  <x-search-popup />


  <!-- Modal -->
  <div id="commonModal" class="fixed inset-0 z-50 hidden sm:ml-4 sm:mr-2" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
      <!-- Background overlay -->
      <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>

      <!-- Modal panel -->
      <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle w-full modal-content">
        <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4 max-h-[90vh] overflow-y-auto">
          <div class="sm:flex sm:items-start">
            <div class="sm:mt-0 sm:text-left w-full">
                <div class="flex gap-3 justify-between">
                    <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title"></h3>
                    {{-- <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse"> --}}
                        <button type="button" class="close-modal text-lg font-medium text-gray-700 hover:bg-gray-50  sm:w-auto">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    {{-- </div> --}}
                </div>
              <div class="mt-2" id="modal-body">
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

        <div class="overlay wish-overlay"></div>
        <div class="wishajaxDrawer"></div>

    <!-- [ Main Content ] end -->
   <!--  jQuery Validation  -->
   <script src="{{ asset('assets/js/jquery.validate.min.js') }}"></script>
   <script src="{{ asset('js/jquery-cookie.min.js') }}"></script>
   <script src="{{ asset('js/loader.js') }}"></script>
@stack('recentViewModelPopup')
@stack('subscribeStorePopup')
@stack('countDownPopup')
@stack('tawktoModelPopup')
@stack('purchaseNotificationPopup')
@stack('wizzchatModelPopup')
<!--scripts end here-->
@if(isset($storejs))
<script>
{!! $storejs !!}
</script>
@endif
<!--scripts start here-->
<script>
    var guest = '{{ Auth::guest() }}';
    var filterBlog = "{{ route('blogs.filter.view',$store->slug) }}";
    var cartlistSidebar = "{{ route('cart.list.sidebar',$store->slug) }}";
    var ProductCart = "{{ route('product.cart',$store->slug) }}";
    var addressbook_data = "#";
    var shippings_data = "#";
    var get_shippings_data = "{{ route('get.shipping.data', $store->slug) }}";
    var shippings_methods = "{{ route('shipping.method', $store->slug) }}";
    var apply_coupon = "{{ route('applycoupon', $store->slug) }}";
    var paymentlist = "{{ route('paymentlist', $store->slug) }}";
    var additionalnote = "{{ route('additionalnote', $store->slug) }}";
    var state_list = "{{ route('states.list', $store->slug) }}";
    var city_list = "{{ route('city.list', $store->slug) }}";
    var changeCart = "{{ route('change.cart', $store->slug) }}";
    var wishListCount = "{{ route('wish.list.count', $store->slug) }}";
    var removeWishlist = "{{ route('delete.wishlist', $store->slug) }}";
    var addProductWishlist = "{{ route('product.wishlist', $store->slug) }}";
    var isAuthenticated = "{{ auth('customers')->check() ? 'true' : 'false' }}";
    var removeCart = "{{  route('cart.remove', $store->slug)  }}";
    var clearCart = "{{  route('cart.clear', $store->slug)  }}";
    var productPrice = "{{ route('product.price', $store->slug) }}";
    var wishList = "{{ route('wish.list',$store->slug) }}";
    var whatsappNumber = "{{ $adminSetting['whatsapp_contact_number'] ?? '' }}";
    var taxes_data = "{{ route('get.tax.data', $store->slug) }}";
    var searchProductGlobaly = "{{ route('search.product', $store->slug) }}";
    var loginUrl = "{{ route('customer.login', $store->slug) }}";
    var payfast_payment = "{{ route('payment.process', $store->slug) }}";
    var site_url = $('meta[name="base-url"]').attr('content');
    var size_module_active = {{ module_is_active('SizeGuideline') ? 'true' : 'false' }};
    var site_url = $('meta[name="base-url"]').attr('content');
    var theme404Page = "{{ route('theme.404', $store->slug) }}";
</script>
<script src="{{ asset('assets/js/floating-wpp.min.js') }}"></script>
<script src="{{ asset('assets/js/plugins/notifier.js') }}"></script>

<script src="{{ asset('public/js/flipdown.js') }}"></script>
<script src="{{ asset('assets/js/front-theme.js') }}" defer="defer"></script>
    @if (isset($store->enable_pwa_store) && $store->enable_pwa_store == 'on')
        <script type="text/javascript">
            const container = document.querySelector("body")

            const coffees = [];

            if ("serviceWorker" in navigator) {
                window.addEventListener("load", function() {
                    navigator.serviceWorker
                        .register("{{ asset('serviceWorker.js') }}")
                        .then(res => console.log("service worker registered"))
                        .catch(err => console.log("service worker not registered", err))

                })
            }
        </script>
    @endif


    @if ($store->enable_pwa_store == 'on')
        <script type="text/javascript">
            // PWA Install functionality
            let deferredPrompt;
            const pwaModal = document.getElementById('pwa-install-modal');
            const installBtn = document.getElementById('pwa-install-btn');
            const cancelBtn = document.getElementById('pwa-cancel-btn');

            // Listen for the beforeinstallprompt event
            window.addEventListener('beforeinstallprompt', (e) => {
                e.preventDefault();
                deferredPrompt = e;
                
                // Check if user hasn't dismissed the modal before
                if (!localStorage.getItem('pwa-install-dismissed')) {
                    setTimeout(() => {
                        pwaModal.classList.remove('hidden');
                    }, 3000); // Show after 3 seconds
                }
            });

            // Install button click
            installBtn.addEventListener('click', async () => {
                if (deferredPrompt) {
                    deferredPrompt.prompt();
                    const { outcome } = await deferredPrompt.userChoice;
                    
                    if (outcome === 'accepted') {
                        show_toastr('Success', '{{ __('App installed successfully!') }}', 'success');
                    } else {
                        show_toastr('Info', '{{ __('App installation cancelled') }}', 'error');
                    }
                    
                    deferredPrompt = null;
                    pwaModal.classList.add('hidden');
                }
            });

            // Cancel button click
            cancelBtn.addEventListener('click', () => {
                pwaModal.classList.add('hidden');
                localStorage.setItem('pwa-install-dismissed', 'true');
            });

            // Close modal when clicking outside
            pwaModal.addEventListener('click', (e) => {
                if (e.target === pwaModal) {
                    pwaModal.classList.add('hidden');
                    localStorage.setItem('pwa-install-dismissed', 'true');
                }
            });
        </script>
    @endif
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $google_analytic ?? '' }}"></script>

    <script>
        window.dataLayer = window.dataLayer || [];

        function gtag() {
            dataLayer.push(arguments);
        }

        gtag('js', new Date());

        gtag('config', '{{ $google_analytic ?? '' }}');
        $(document).on("click", "#cart-close",function(e) {
            $("#cart-panel").removeClass("open");
            $("#cart-overlay").removeClass("open");
            $("body").css("overflow", "");
        });

        $(document).on('click', '.quantity-increment', function () {
            const $input = $(this).siblings('.quantity');
            let quantity = parseInt($input.val()) || 0;
            $input.val(quantity + 1);
        });

        $(document).on('click', '.quantity-decrement', function () {
            const $input = $(this).siblings('.quantity');
            let quantity = parseInt($input.val()) || 0;
            if (quantity > 1) {
                $input.val(quantity - 1);
            }
        });

        if ($('.select2').length > 0) {
            $('.select2').each(function () {
                var $this = $(this);

                $this.select2({
                    width: '100%',
                    tags: true,  // Enable tagging feature
                    createTag: function (params) {
                        var term = $.trim(params.term);
                        if (term === '') {
                            return null;
                        }
                        return {
                            id: term,
                            text: term,
                            newTag: true
                        };
                    }
                });
            });
        }
    </script>

{{-- facebook pixel code --}}
    <script>
        ! function(f, b, e, v, n, t, s) {
            if (f.fbq) return;
            n = f.fbq = function() {
                n.callMethod ?
                    n.callMethod.apply(n, arguments) : n.queue.push(arguments)
            };
            if (!f._fbq) f._fbq = n;
            n.push = n;
            n.loaded = !0;
            n.version = '2.0';
            n.queue = [];
            t = b.createElement(e);
            t.async = !0;
            t.src = v;
            s = b.getElementsByTagName(e)[0];
            s.parentNode.insertBefore(t, s)
        }(window, document, 'script',
            'https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', '{{ $fbpixel_code ?? '' }}');
        fbq('track', 'PageView');
    </script>
    <noscript><img height="1" width="1" style="display:none"
        src="https://www.facebook.com/tr?id=0000&ev=PageView&noscript={{ $fbpixel_code ?? '' }}" /></noscript>

@if(\Request::route()->getName() == 'page.contact_us')
    <script>
        $(document).ready(function() {
            // Assuming $slug is defined somewhere in your Blade template
            var slug = "{{ $slug }}"; // Replace with actual value
            var mobile = "{{ $store->user->mobile ?? '+123 456-78-90'}}";
            var tel = "tel:{{ $store->user->mobile ?? '+1234567890'}}";
            var email = "{{ $store->user->email ?? 'shop@company.com'}}";
            var mailto = "mailto:{{ $store->user->email ?? 'shop@company.com'}}";
            var storeAddress = "{{ \App\Models\Utility::GetValueByName('store_address', $store->id) ?? '123 New Street, New City, NY, 10001'}}";
            // Update form action attribute
            var newAction = "{{ route('contacts.store') }}/" + slug;
            $('.contact-form').attr('action', newAction);

            $('ul.col-sm-6.col-12 li:eq(0) p a').text(mobile); // New phone number
            $('ul.col-sm-6.col-12 li:eq(0) p a').attr('href', tel); // New tel link

            // Change "Email"
            $('ul.col-sm-6.col-12 li:eq(1) p a').text(email); // New email address
            $('ul.col-sm-6.col-12 li:eq(1) p a').attr('href', mailto); // New mailto link

            // Change "Address"
            $('ul.col-sm-6.col-12:eq(1) li p.address').text(storeAddress); // New address
        });
    </script>
@endif

@push('page-script')
<script>
$(document).ready(function() {
    function handleSearch(formId, inputId) {
        $(formId).on('submit', function(e) {
            e.preventDefault();
            
            const searchTerm = $(inputId).val().trim();
            if (!searchTerm) return;
            
            $.ajax({
                url: '{{ route("search.product", $store->slug) }}',
                type: 'GET',
                data: { product: searchTerm },
                success: function(response) {
                    if (response && response.length > 0) {
                        // If we get a URL, redirect to the product page
                        window.location.href = response[0].url;
                    } else {
                        // If no results, redirect to product list with search term
                        window.location.href = '{{ route("theme.404", $slug) }}?search=' + searchTerm;
                    }
                },
                error: function() {
                    // On error, redirect to product list with search term
                    window.location.href = '{{ route("theme.404", $slug) }}?search=' + searchTerm;
                }
            });
        });
    }

    handleSearch('#desktop-search-form', '#desktop-search-input');
    handleSearch('#mobile-search-form', '#search-input');
});
</script>

<script>
    $(document).on('click', 'a[data-ajax-popup="true"], button[data-ajax-popup="true"], div[data-ajax-popup="true"]', function (e) {
        e.preventDefault();
        var url = $(this).data('url');
        var title = $(this).data('title');
        var size = $(this).data('size');
        
        // Show modal with form
        showModal(url, title, size);
    });
    // Function to show modal
    function showModal(url, title, size) {
        // Set modal size based on data-size attribute
        var modalContent = $('#commonModal .modal-content');
        modalContent.removeClass('sm:max-w-lg sm:max-w-xl sm:max-w-2xl sm:max-w-3xl sm:max-w-4xl sm:max-w-5xl sm:max-w-6xl sm:max-w-7xl');
        
        switch(size) {
            case 'sm':
                modalContent.addClass('sm:max-w-lg');
                break;
            case 'md':
                modalContent.addClass('sm:max-w-2xl');
                break;
            case 'lg':
                modalContent.addClass('sm:max-w-4xl');
                break;
            case 'xl':
                modalContent.addClass('sm:max-w-6xl');
                break;
            default:
                modalContent.addClass('sm:max-w-lg');
        }

        $.ajax({
            url: url,
            type: 'GET',
            success: function(response) {
                $('#commonModal #modal-title').html(title);
                $('#commonModal #modal-body').html(response.html);
                $('#commonModal').removeClass('hidden');
            },
            error: function(error) {
                console.error('Error loading address form:', error);
            }
        });
    }

    // Close modal when clicking the close button
    $(document).on('click', '.close-modal', function(e) {
        e.preventDefault();
        $('#commonModal').addClass('hidden');
    });

    // Close modal when clicking outside
    $(document).on('click', '#commonModal .bg-gray-500', function(e) {
        if (e.target === this) {
            $('#commonModal').addClass('hidden');
        }
    });
</script>

<script>
    function wcqib_refresh_quantity_increments() {
    jQuery("div.quantity:not(.buttons_added), td.quantity:not(.buttons_added)").each(function (a, b) {
        var c = jQuery(b);
        c.addClass("buttons_added"),
            c.children().first().before('<input type="button" value="-" class="minus" />'),
            c.children().last().after('<input type="button" value="+" class="plus" />')
    })
}

String.prototype.getDecimals || (String.prototype.getDecimals = function () {
    var a = this,
        b = ("" + a).match(/(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/);
    return b ? Math.max(0, (b[1] ? b[1].length : 0) - (b[2] ? +b[2] : 0)) : 0
}), jQuery(document).ready(function () {
    wcqib_refresh_quantity_increments()
}), jQuery(document).on("updated_wc_div", function () {
    wcqib_refresh_quantity_increments()
}), jQuery(document).on("click", ".plus, .minus", function () {
    var a = jQuery(this).closest(".quantity").find('input[name="quantity"], input[name="quantity[]"]'),
        b = parseFloat(a.val()),
        c = parseFloat(a.attr("max")),
        d = parseFloat(a.attr("min")),
        e = a.attr("step");
    b && "" !== b && "NaN" !== b || (b = 0), "" !== c && "NaN" !== c || (c = ""), "" !== d && "NaN" !== d || (d = 0), "any" !== e && "" !== e && void 0 !== e && "NaN" !== parseFloat(e) || (e = 1), jQuery(this).is(".plus") ? c && b >= c ? a.val(c) : a.val((b + parseFloat(e)).toFixed(e.getDecimals())) : d && b <= d ? a.val(d) : b > 0 && a.val((b - parseFloat(e)).toFixed(e.getDecimals())), a.trigger("change")
});
</script>
@endpush