@extends('front_end.layouts.app')
@section('page-title')
{{ __('Products') }}
@endsection

@section('content')
@if ($themeSettings['product_list_banner_status'] && $themeSettings['product_list_banner_status'] == '1')
<!-- Common Banner Section -->
<section class="banner-section relative lg:py-16 py-10 bg-cover bg-center" style="background-image: url('{{ get_file($themeSettings['product_list_banner_image'] ?? '') }}');">
    <div class="md:container w-full mx-auto px-4">
    <div class="text-center relative z-[2]">
        <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">{{ $themeSettings['product_list_banner_title'] ?? __('Product List') }}</h2>
    </div>
    </div>
</section>
@endif
<section class="py-10 lg:py-20">
    <div class="md:container w-full mx-auto px-4">
        <div class="flex flex-col lg:flex-row gap-5 xl:gap-8">
            <!-- Sidebar Filters -->
            <div id="mobile-filter"
                class="fixed top-0 left-0 w-80 max-w-full h-full lg:z-0 z-[31] transform -translate-x-full transition-transform duration-300 ease-in-out lg:relative lg:translate-x-0 lg:w-1/4 lg:h-auto lg:sticky lg:top-10">

                <div class="bg-white p-4 md:p-6 lg:rounded-lg border lg:h-auto h-full overflow-y-auto">
                    <div class="flex justify-between items-center mb-4 md:mb-6 border-b pb-3">
                        <h2 class="font-bold text-lg md:text-xl">{{ __('Filters')}}</h2>
                        <button id="filter-close" class="text-gray-500 hover:text-black text-2xl lg:hidden block">
                            &times;
                        </button>
                    </div>

                    <!-- Categories -->
                    <div class="mb-4 md:mb-6">
                        <h3 class="font-semibold mb-2 md:mb-3">{{ __('Categories')}}</h3>
                        <div class="space-y-1 md:space-y-2">
                            @foreach ($filter_tag as $category)
                            <div class="checkbox flex items-center">
                                <input type="checkbox" id="category-{{ $category->id }}" name="categories[]"
                                    value="{{ $category->id }}"
                                    class="rounded text-primary focus:ring-primary ltr:mr-2 rtl:ml-2 product_tag"
                                    {{ in_array($category->id, $category_ids ?? []) ? 'checked' : '' }} />
                                <label for="category-{{ $category->id }}">{{ $category->name }}</label>
                            </div>
                            @endforeach
                        </div>
                    </div>

                    <!-- Price Range -->
                    @php
                    $price_step = ($max_price - $min_price) / 5;
                    @endphp
                    <div class="mb-4 md:mb-6">
                        <h3 class="font-semibold mb-2 md:mb-3">{{ __('Price Range') }}</h3>
                        <input type="range" min="{{ $min_price }}" max="{{ $max_price }}"
                            value="{{ $selected_max_price ?? $max_price }}" step="1" class="w-full accent-primary"
                            id="priceRangeSlider" />
                        <div class="flex justify-between mt-1 md:mt-2 text-sm text-gray-600">
                            <span id="min_price_select"
                                class="min_price_select">{{ currency_format_with_sym($min_price, $store->id) }}</span>
                            <span id="max_price_select" class="max_price_select"><span
                                    id="sliderValue">{{ currency_format_with_sym($selected_max_price ?? $max_price, $store->id) }}</span></span>
                        </div>
                    </div>
                    <input type="hidden" name="max_price" max="{{ $selected_max_price ?? $max_price }}" id="maxPriceInput"
                        value="{{ $selected_max_price ?? $max_price }}">


                    <!-- Dietary Preferences -->
                    <div class="mb-4 md:mb-6">
                        <h3 class="font-semibold mb-2 md:mb-3">{{ __('Brands') }}</h3>
                        <div class="space-y-1 md:space-y-2">
                            @foreach ($brands as $preference)
                            <div class="checkbox flex items-center">
                                <input type="checkbox" id="diet_{{ $preference->id }}" name="dietary_preferences[]"
                                    value="{{ $preference->id }}"
                                    class="rounded text-primary focus:ring-primary ltr:mr-2 rtl:ml-2 dietary_filter product_brand"
                                    {{ in_array($preference->id, $brand_select ?? []) ? 'checked' : '' }} />
                                <label for="diet_{{ $preference->id }}">{{ $preference->name }}</label>
                            </div>
                            @endforeach
                        </div>
                    </div>

                    <button class="btn-primary w-full btn checkout-btn" id="product_filter_btn">
                        {{ __('Apply Filters')}}
                    </button>
                </div>
            </div>

            <!-- Products Content -->
            <div class="w-full lg:w-3/4">
                <!-- Products Header -->
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4 border-b pb-5">
                    <div class="flex items-center gap-3">
                        <button id="filter-icon"
                            class="p-2 px-3 bg-primary/10 text-primary border border-primary rounded lg:hidden block">
                            <i class="fa-solid fa-filter"></i>
                        </button>

                        <h2 class="font-bold text-xl md:text-2xl">{{ __('All Products')}} (<span class="product_count">0</span>)</h2>
                    </div>

                    <div class="flex flex-1 gap-3 sm:justify-end w-full">
                        <!-- View Toggle -->
                        <div class="flex border rounded-md overflow-hidden">
                            <button id="grid-view-btn" class="view-toggle p-1 px-3 active">
                                <i class="fas fa-th-large"></i>
                            </button>
                            <button id="list-view-btn" class="view-toggle p-1 px-3 sm:block hidden">
                                <i class="fas fa-list"></i>
                            </button>
                        </div>

                        <div class="relative max-w-[190px] w-full">
                            <select class="block w-full border rounded-md focus:outline-none focus:ring-primary focus:border-primary filter_product select2"
                                id="filter_product">
                                <option value="all"  {{ empty($filter_product) || (!empty($filter_product) && $filter_product == 'all') ? 'selected="selected"' : '' }}>{{ __('All') }}</option>
                                <option value="manual">{{ __('Featured') }}</option>
                                <option value="best-selling"
                                    {{ !empty($filter_product) && $filter_product == 'best-selling' ? 'selected="selected"' : '' }}>
                                    {{ __('Best selling') }}
                                </option>
                                <option value="title-ascending"> {{ __('Alphabetically, A-Z')}} </option>
                                <option value="title-descending"> {{ __('Alphabetically, Z-A')}} </option>
                                <option value="price-ascending"> {{ __('Price, low to high')}} </option>
                                <option value="price-descending"> {{ __('Price, high to low')}} </option>
                                <option value="created-ascending"> {{ __('Date, old to new')}} </option>
                                <option value="created-descending"> {{ __('Date, new to old')}} </option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Products List - Grid View by default -->
                <div class="product_filter">
                
                </div>
                
            </div>
        </div>
    </div>
</section>

@endsection
@push('page-script')
<script>
var isPreOrderModuleActive = {{ module_is_active('PreOrder') ? 'true' : 'false' }};
var storeSlug = '{{ $slug ?? '' }}';

$(document).ready(function() {
    let urlParams = new URLSearchParams(window.location.search);

    $('#min_price_select').val(urlParams.get('min_price'));
    $('#max_price_select').val(urlParams.get('max_price'));
    $('#filter_product').val(urlParams.get('filter_product'));

    let product_tag = urlParams.get('product_tag');
    let product_brand = urlParams.get('product_brand');

    if (product_tag) {
        product_tag.split(',').forEach(tag => {
            $('#tag_' + tag).prop('checked', true);
        });
    }

    if (product_brand) {
        product_brand.split(',').forEach(brand => {
            $('#brand_' + brand).prop('checked', true);
        });
    }

    let initialPage = urlParams.get('page') || 1;
    product_page_filter(initialPage);

    // Initialize price range slider
    const priceRangeSlider = document.getElementById('priceRangeSlider');
    const sliderValue = document.getElementById('sliderValue');
    const maxPriceInput = document.getElementById('maxPriceInput');
    
    // Update the displayed price when slider changes
    priceRangeSlider.addEventListener('input', function() {
        const value = this.value;
        // Make an AJAX call to get formatted price
        $.ajax({
            url: '{{ route("format.price") }}',
            type: 'POST',
            data: {
                _token: '{{ csrf_token() }}',
                price: value,
                store_id: '{{ $store->id }}'
            },
            success: function(response) {
                sliderValue.textContent = response.formatted_price;
                maxPriceInput.value = value;
            }
        });
    });

  
    // Add change event listener to the slider
    priceRangeSlider.addEventListener('change', function () {
        const currentPage = new URLSearchParams(window.location.search).get('page') || 1;
        product_page_filter(currentPage);
    });
});

$(document).on('click', '#product_filter_btn', function() {
    let page = $('.page-item.active .page-link').text() || 1;
    product_page_filter(page);
});



$(".filter_product").change(function() {
    product_page_filter(1);
});

function product_page_filter(page) {
    let product_tag = [];
    let product_brand = [];

    $('.product_tag:checked').each(function() {
        product_tag.push($(this).val());
    });

    $('.product_brand:checked').each(function() {
        product_brand.push($(this).val());
    });

    let min_price = $('#priceRangeSlider').attr('min') || '';
    let max_price = $('#priceRangeSlider').val() || '';
    let filter_product = $('#filter_product').val() || 'all';

    let queryParams = new URLSearchParams({
        page,
        product_tag: product_tag.join(','),
        product_brand: product_brand.join(','),
        min_price,
        max_price,
        filter_product: filter_product,
    });

    let queryString = queryParams.toString();

    history.replaceState(null, null, '?' + queryString);

    $.ajax({
        url: '{{ route("product.page.filter", $slug) }}?' + queryString,
        type: 'GET',
        success: function(response) {
            $('.product_filter').html(response.html);
            $('.product_count').html(response.product_count);

            if (isPreOrderModuleActive === true) {
                handlePreOrderOutOfStock();
            }
            
            @if(module_is_active('SkipCart'))
                @php
                    $enable_skip_cart = \App\Models\Utility::GetValueByName('enable_skip_cart', $store->id);
                    $checkout_setting = \App\Models\Utility::GetValueByName('skip_cart_checkout_setting', $store->id);
                @endphp
                @if(isset($enable_skip_cart) && $enable_skip_cart == 'on')
                    setTimeout(() => {
                        @if($checkout_setting != 'sticky_cart')
                            $('.addcart-btn-globaly').remove();
                        @else
                            $('.cartDrawer').hide();
                            $('div').removeClass('.overlay .cart-overlay');
                        @endif
                    }, 100);
                    
                    // Reinitialize SkipCart event handlers for AJAX content
                    initSkipCartHandlers();
                @endif
            @endif
        },
        error: function(xhr, status, error) {
            $('.product_filter').html(
                '<div class="alert alert-danger">Error loading products. Please try again.</div>');
            console.error('AJAX error:', error, xhr.responseText);
        }
    });
}

function handlePreOrderOutOfStock() {
    $.ajax({
        url: `{{ url('/${storeSlug}/preordersetting/out-of-stock-products') }}`,
        method: 'GET',
        success: function(stockResponse) {
            const {
                outOfStockProductIds,
                buttonName,
                message: preOrderNote
            } = stockResponse;

            $('.addtocart-btn').each(function() {
                const button = $(this);
                const productId = parseInt(button.attr('product_id'));

                if (outOfStockProductIds.includes(productId)) {
                    button.attr('order_type', 'pre_order');
                    button.find('span').text(buttonName);

                    if (!button.prev('.note-after-button').length) {
                        $('<span class="note-after-button" style="color:red;font-size:12px;margin-top:5px;display:block;">' +
                                preOrderNote + '</span>')
                            .insertBefore(button);
                    }

                    if (button.hasClass('quick-checkout-button') || button.hasClass(
                            'checkout-button')) {
                        button.remove();
                    }
                }
            });
        },
        error: function(xhr, status, error) {
            console.error('PreOrder stock check failed:', error);
        }
    });
}

$(document).on("click", "#list-view-btn", function (e) {
  e.preventDefault();
   $('#products-container').removeClass('grid-view');
   $('#products-container').addClass('list-view');
   $('#products-container').removeClass('grid', 'grid-cols-1', 'sm:grid-cols-2', 'lg:grid-cols-3', 'gap-4', 'md:gap-6');
   $('#products-container').addClass('space-y-5', 'xl:space-y-6');
    $('#list-view-btn').addClass('active');
    $('#grid-view-btn').removeClass('active');
});

$(document).on("click", "#grid-view-btn", function (e) {
  e.preventDefault();
    $('#products-container').removeClass('list-view');
    $('#products-container').addClass('grid-view');
    $('#products-container').removeClass('space-y-5', 'xl:space-y-6');
    $('#products-container').addClass('grid', 'grid-cols-1', 'sm:grid-cols-2', 'lg:grid-cols-3', 'gap-4', 'md:gap-6');
    $('#grid-view-btn').addClass('active');
    $('#list-view-btn').removeClass('active');
});

@if(module_is_active('SkipCart'))
    @php
        $enable_skip_cart = \App\Models\Utility::GetValueByName('enable_skip_cart', $store->id);
        $button_link = \App\Models\Utility::GetValueByName('skip_cart_button_link', $store->id);
    @endphp
    @if(isset($enable_skip_cart) && $enable_skip_cart == 'on')
        function initSkipCartHandlers() {
            // Remove existing handlers to prevent duplicates
            $('.product_filter').off('click', '.one-click-checkout');
            $('.product_filter').off('click', '.on-page-checkout');
            
            // One-click checkout handler
            $('.product_filter').on('click', '.one-click-checkout', function(e) {
                var product_id = $(this).attr('product_id');
                var variant_id = $(this).attr('variant_id');
                var qty = 1;
                
                $.ajax({
                    url: ProductCart,
                    method: 'POST',
                    data: { product_id: product_id, variant_id: variant_id, qty: qty },
                    success: function(response) {
                        $('.cart-header .count').html(response.data.count);
                        if (response.status == 0) {
                            show_toastr('Error', response.data.message, 'error');
                        } else {
                            @if($button_link == 'cart_page')
                                window.location.href = '{{ route('page.cart', $slug) }}';
                            @else
                                window.location.href = '{{ route('checkout', $slug) }}';
                            @endif
                        }
                        get_cartlist();
                    }
                });
            });
            
            // On-page checkout handler
            $('.product_filter').on('click', '.on-page-checkout', function(e) {
                e.preventDefault();
                var product_id = $(this).attr('product_id');
                var variant_id = $(this).attr('variant_id');
                
                $.ajax({
                    url: "{{ route('skip.cart.empty') }}",
                    method: 'POST',
                    data: { _token: '{{ csrf_token() }}', customer_id: '{{ Auth::guard('customers')->id() }}' },
                    success: function(response) {
                        if (response.status == 'success') {
                            $.ajax({
                                url: ProductCart,
                                method: 'POST',
                                data: { product_id: product_id, variant_id: variant_id, qty: 1 },
                                success: function(response) {
                                    if (response.status != 0) {
                                        var modalUrl = $('.on-page-checkout').attr('data-url');
                                        $.ajax({
                                            url: modalUrl,
                                            method: 'GET',
                                            success: function(modalContent) {
                                                $('#commanModel .modal-title').html('{{ __('Checkout View') }}');
                                                $('#commanModel .modal-body').html(modalContent);
                                                $("#commanModel").modal('show');
                                            }
                                        });
                                    }
                                    get_cartlist();
                                }
                            });
                        }
                    }
                });
            });
        }
    @endif
@endif
</script>
@endpush