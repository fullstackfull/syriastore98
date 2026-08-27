
   
    <link rel="stylesheet" href="<?php echo e(asset('css/loader.css')); ?><?php echo e('?v=' . time()); ?>" >
     <link rel="stylesheet" href="<?php echo e(asset('assets/css/plugins/notifier.css')); ?><?php echo e('?v=' . time()); ?>" >
     
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if((isset($loader_show) && $loader_show != 'no') || !isset($loader_show)): ?>
        <div id="loader" class="loader-wrapper" style="display: none;">
        <span class="site-loader"> </span>
        <h3 class="loader-content"> <?php echo e(__('Loading . . .')); ?> </h3>
        </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

         <!-- Cart Overlay and Panel -->
        <div id="cart-overlay" class="cart-overlay">
            <div id="cart-panel" class="cart-panel">
            </div>
        </div>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($themeSettings['header_status'] && $themeSettings['header_status'] == '1'): ?>
        <div id="mobile-menu" class="mobile-menu fixed inset-y-0 right-0 z-50 bg-white shadow-xl transform translate-x-full transition-transform duration-300 ease-in-out overflow-y-auto">
            <div class="flex justify-between items-center p-4 border-b">
            <a href="<?php echo e(route('landing_page', $slug)); ?>" class="block max-w-[110px] w-full">
                <img src="<?php echo e(get_file($themeSettings['header_logo'] ?? '')); ?>" alt="logo" class="logo-img">
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
                <span><?php echo e($languages[$currantLang] ?? 'English'); ?></span>
                <i class="fas fa-chevron-down text-sm"></i>
                </button>
                <div data-dropdown-menu="mob-language"
                class="absolute right-0 mt-2 py-2 min-w-28 bg-white border border-gray-200 rounded-lg shadow-lg hidden w-full max-h-[200px] overflow-y-auto">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $languages; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $value): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <a href="<?php echo e(route('change.languagestore', $key)); ?>" class="block px-4 py-2 text-gray-700 hover:bg-gray-100 <?php if($currantLang == $key): ?> text-primary <?php endif; ?>"><?php echo e($value); ?></a>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </div>
            </div>
            <nav class="p-4">
            <ul class="space-y-1">
                <?php
                    $menuItems = getNavMenu($themeSettings['menu_bar_menu'] ?? '');
                ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($menuItems)): ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $menuItems; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $menu): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <?php echo $__env->make('front_end.common.mobile-menu', [
                            'item' => $menu,
                            'key' => $key,
                            'class' => $key === 0 
                                ? 'text-primary font-medium bg-primary/10 transition-colors' 
                                : 'text-gray-700 font-medium hover:bg-gray-100'
                        ], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </ul>
            </nav>
        </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>


    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($store->enable_pwa_store == 'on'): ?>
        <!-- PWA Install Modal -->
        <div id="pwa-install-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center">
            <div class="bg-white rounded-lg p-6 m-4 max-w-sm w-full">
                <div class="text-center">
                    <div class="mb-4">
                        <img src="<?php echo e($faviconUrl ?? get_file($themeSettings['header_favicon'] ?? '')); ?>" alt="App Icon" class="w-16 h-16 mx-auto rounded-lg">
                    </div>
                    <h3 class="text-lg font-semibold mb-2"><?php echo e(__('Install App')); ?></h3>
                    <p class="text-gray-600 mb-4"><?php echo e(__('Install our app for a better experience')); ?></p>
                    <div class="flex gap-3">
                        <button id="pwa-install-btn" class="flex-1 bg-primary text-white px-4 py-2 rounded-lg hover:bg-primary/90">
                            <?php echo e(__('Install')); ?>

                        </button>
                        <button id="pwa-cancel-btn" class="flex-1 bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-300">
                            <?php echo e(__('Cancel')); ?>

                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
  <!-- Search Popup -->
  <?php if (isset($component)) { $__componentOriginalfd600640245a773333aee3791f2e3929 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalfd600640245a773333aee3791f2e3929 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.search-popup','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('search-popup'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalfd600640245a773333aee3791f2e3929)): ?>
<?php $attributes = $__attributesOriginalfd600640245a773333aee3791f2e3929; ?>
<?php unset($__attributesOriginalfd600640245a773333aee3791f2e3929); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalfd600640245a773333aee3791f2e3929)): ?>
<?php $component = $__componentOriginalfd600640245a773333aee3791f2e3929; ?>
<?php unset($__componentOriginalfd600640245a773333aee3791f2e3929); ?>
<?php endif; ?>


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
                    
                        <button type="button" class="close-modal text-lg font-medium text-gray-700 hover:bg-gray-50  sm:w-auto">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    
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
   <script src="<?php echo e(asset('assets/js/jquery.validate.min.js')); ?>"></script>
   <script src="<?php echo e(asset('js/jquery-cookie.min.js')); ?>"></script>
   <script src="<?php echo e(asset('js/loader.js')); ?>"></script>
<?php echo $__env->yieldPushContent('recentViewModelPopup'); ?>
<?php echo $__env->yieldPushContent('subscribeStorePopup'); ?>
<?php echo $__env->yieldPushContent('countDownPopup'); ?>
<?php echo $__env->yieldPushContent('tawktoModelPopup'); ?>
<?php echo $__env->yieldPushContent('purchaseNotificationPopup'); ?>
<?php echo $__env->yieldPushContent('wizzchatModelPopup'); ?>
<!--scripts end here-->
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($storejs)): ?>
<script>
<?php echo $storejs; ?>

</script>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<!--scripts start here-->
<script>
    var guest = '<?php echo e(Auth::guest()); ?>';
    var filterBlog = "<?php echo e(route('blogs.filter.view',$store->slug)); ?>";
    var cartlistSidebar = "<?php echo e(route('cart.list.sidebar',$store->slug)); ?>";
    var ProductCart = "<?php echo e(route('product.cart',$store->slug)); ?>";
    var addressbook_data = "#";
    var shippings_data = "#";
    var get_shippings_data = "<?php echo e(route('get.shipping.data', $store->slug)); ?>";
    var shippings_methods = "<?php echo e(route('shipping.method', $store->slug)); ?>";
    var apply_coupon = "<?php echo e(route('applycoupon', $store->slug)); ?>";
    var paymentlist = "<?php echo e(route('paymentlist', $store->slug)); ?>";
    var additionalnote = "<?php echo e(route('additionalnote', $store->slug)); ?>";
    var state_list = "<?php echo e(route('states.list', $store->slug)); ?>";
    var city_list = "<?php echo e(route('city.list', $store->slug)); ?>";
    var changeCart = "<?php echo e(route('change.cart', $store->slug)); ?>";
    var wishListCount = "<?php echo e(route('wish.list.count', $store->slug)); ?>";
    var removeWishlist = "<?php echo e(route('delete.wishlist', $store->slug)); ?>";
    var addProductWishlist = "<?php echo e(route('product.wishlist', $store->slug)); ?>";
    var isAuthenticated = "<?php echo e(auth('customers')->check() ? 'true' : 'false'); ?>";
    var removeCart = "<?php echo e(route('cart.remove', $store->slug)); ?>";
    var clearCart = "<?php echo e(route('cart.clear', $store->slug)); ?>";
    var productPrice = "<?php echo e(route('product.price', $store->slug)); ?>";
    var wishList = "<?php echo e(route('wish.list',$store->slug)); ?>";
    var whatsappNumber = "<?php echo e($adminSetting['whatsapp_contact_number'] ?? ''); ?>";
    var taxes_data = "<?php echo e(route('get.tax.data', $store->slug)); ?>";
    var searchProductGlobaly = "<?php echo e(route('search.product', $store->slug)); ?>";
    var loginUrl = "<?php echo e(route('customer.login', $store->slug)); ?>";
    var payfast_payment = "<?php echo e(route('payment.process', $store->slug)); ?>";
    var site_url = $('meta[name="base-url"]').attr('content');
    var size_module_active = <?php echo e(module_is_active('SizeGuideline') ? 'true' : 'false'); ?>;
    var site_url = $('meta[name="base-url"]').attr('content');
    var theme404Page = "<?php echo e(route('theme.404', $store->slug)); ?>";
</script>
<script src="<?php echo e(asset('assets/js/floating-wpp.min.js')); ?>"></script>
<script src="<?php echo e(asset('assets/js/plugins/notifier.js')); ?>"></script>

<script src="<?php echo e(asset('public/js/flipdown.js')); ?>"></script>
<script src="<?php echo e(asset('assets/js/front-theme.js')); ?>" defer="defer"></script>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($store->enable_pwa_store) && $store->enable_pwa_store == 'on'): ?>
        <script type="text/javascript">
            const container = document.querySelector("body")

            const coffees = [];

            if ("serviceWorker" in navigator) {
                window.addEventListener("load", function() {
                    navigator.serviceWorker
                        .register("<?php echo e(asset('serviceWorker.js')); ?>")
                        .then(res => console.log("service worker registered"))
                        .catch(err => console.log("service worker not registered", err))

                })
            }
        </script>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>


    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($store->enable_pwa_store == 'on'): ?>
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
                        show_toastr('Success', '<?php echo e(__('App installed successfully!')); ?>', 'success');
                    } else {
                        show_toastr('Info', '<?php echo e(__('App installation cancelled')); ?>', 'error');
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
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo e($google_analytic ?? ''); ?>"></script>

    <script>
        window.dataLayer = window.dataLayer || [];

        function gtag() {
            dataLayer.push(arguments);
        }

        gtag('js', new Date());

        gtag('config', '<?php echo e($google_analytic ?? ''); ?>');
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
        fbq('init', '<?php echo e($fbpixel_code ?? ''); ?>');
        fbq('track', 'PageView');
    </script>
    <noscript><img height="1" width="1" style="display:none"
        src="https://www.facebook.com/tr?id=0000&ev=PageView&noscript=<?php echo e($fbpixel_code ?? ''); ?>" /></noscript>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(\Request::route()->getName() == 'page.contact_us'): ?>
    <script>
        $(document).ready(function() {
            // Assuming $slug is defined somewhere in your Blade template
            var slug = "<?php echo e($slug); ?>"; // Replace with actual value
            var mobile = "<?php echo e($store->user->mobile ?? '+123 456-78-90'); ?>";
            var tel = "tel:<?php echo e($store->user->mobile ?? '+1234567890'); ?>";
            var email = "<?php echo e($store->user->email ?? 'shop@company.com'); ?>";
            var mailto = "mailto:<?php echo e($store->user->email ?? 'shop@company.com'); ?>";
            var storeAddress = "<?php echo e(\App\Models\Utility::GetValueByName('store_address', $store->id) ?? '123 New Street, New City, NY, 10001'); ?>";
            // Update form action attribute
            var newAction = "<?php echo e(route('contacts.store')); ?>/" + slug;
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
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

<?php $__env->startPush('page-script'); ?>
<script>
$(document).ready(function() {
    function handleSearch(formId, inputId) {
        $(formId).on('submit', function(e) {
            e.preventDefault();
            
            const searchTerm = $(inputId).val().trim();
            if (!searchTerm) return;
            
            $.ajax({
                url: '<?php echo e(route("search.product", $store->slug)); ?>',
                type: 'GET',
                data: { product: searchTerm },
                success: function(response) {
                    if (response && response.length > 0) {
                        // If we get a URL, redirect to the product page
                        window.location.href = response[0].url;
                    } else {
                        // If no results, redirect to product list with search term
                        window.location.href = '<?php echo e(route("theme.404", $slug)); ?>?search=' + searchTerm;
                    }
                },
                error: function() {
                    // On error, redirect to product list with search term
                    window.location.href = '<?php echo e(route("theme.404", $slug)); ?>?search=' + searchTerm;
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
<?php $__env->stopPush(); ?><?php /**PATH /home/storesyria/public_html/resources/views/front_end/jQueryRoute.blade.php ENDPATH**/ ?>