<div class="product-list-row row no-gutters">
    {{-- filter --}}
    <div class="product-filter-column col-lg-3 col-md-4 col-12">
        <div class="product-filter-body">
            <div class="mobile-only close-filter">
                <svg xmlns="http://www.w3.org/2000/svg" width="50" height="50" viewBox="0 0 50 50"
                    fill="none">
                    <path
                        d="M27.7618 25.0008L49.4275 3.33503C50.1903 2.57224 50.1903 1.33552 49.4275 0.572826C48.6647 -0.189868 47.428 -0.189965 46.6653 0.572826L24.9995 22.2386L3.33381 0.572826C2.57102 -0.189965 1.3343 -0.189965 0.571605 0.572826C-0.191089 1.33562 -0.191186 2.57233 0.571605 3.33503L22.2373 25.0007L0.571605 46.6665C-0.191186 47.4293 -0.191186 48.666 0.571605 49.4287C0.952952 49.81 1.45285 50.0007 1.95275 50.0007C2.45266 50.0007 2.95246 49.81 3.3339 49.4287L24.9995 27.763L46.6652 49.4287C47.0465 49.81 47.5464 50.0007 48.0463 50.0007C48.5462 50.0007 49.046 49.81 49.4275 49.4287C50.1903 48.6659 50.1903 47.4292 49.4275 46.6665L27.7618 25.0008Z"
                        fill="white"></path>
                </svg>
            </div>

            {{-- Product Tag --}}
            <div class="product-widget product-tag-widget">
                <div class="pro-itm has-children {{count($sub_category_select ?? []) > 0 ? 'is-open' : ''}}">
                    <a href="javascript:;" class="acnav-label">{{ __('tags') }} </a>
                    <div class="pro-itm-inner acnav-list " style="{{count($sub_category_select ?? []) > 0 ? 'display: block;  ' : ''}}">
                        <div class="d-flex flex-wrap text-checkbox">
                            @foreach ($filter_tag as $sub_category)
                                <div class="checkbox">
                                    <input id="{{ $sub_category->id }}" name="product_tag" type="checkbox"
                                            class="product_tag" value="{{ $sub_category->id }}"
                                            {{ in_array($sub_category->id, $sub_category_select ?? []) ? 'checked' : ''}} >
                                    <label for="{{ $sub_category->id }}"
                                        class="checkbox-label">{{ $sub_category->name }}</label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            {{-- Product Price --}}
            <div class="product-widget product-price-widget">
                <div class="pro-itm has-children">
                    <a href="javascript:;" class="acnav-label"> {{ __('price') }} </a>
                    <div class="pro-itm-inner acnav-list">
                        <div class="price-select d-flex">
                            @php $price_step = $max_price/5; @endphp
                            <div class="select-col">
                                <p>
                                    {{ __('min price') }} : <span class="min_price_select" price="0">{{ currency_format_with_sym(0, $store->id) }}</span>
                                </p>
                            </div>
                            <div class="select-col">
                                <p>{{ __('max price') }} : <span class="max_price_select" price="{{ $max_price }}">{{ currency_format_with_sym($max_price, $store->id) }}</span> </p>
                            </div>
                        </div>
                        <div id="range-slider">
                            <div id="slider-range" class="slider-range" min_price="{{ $min_price }}" max_price="{{ $max_price }}" price_step="1" currency="{{ $currency_icon ?? '' }}" ></div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Product rating --}}
            <div class="product-widget product-colors-widget">
                <div class="pro-itm has-children">
                    <a href="javascript:;" class="acnav-label"> {{ __('Rating') }} </a>
                    <div class="pro-itm-inner acnav-list">
                        <div class="radio-group">
                            <input type="radio" id="star5" name="rating" class="rating" value="5">
                            <label for="star5">
                                <span>
                                    <i class="text-warning ti ti-star"></i>
                                    <i class="text-warning ti ti-star"></i>
                                    <i class="text-warning ti ti-star"></i>
                                    <i class="text-warning ti ti-star"></i>
                                    <i class="text-warning ti ti-star"></i>
                                </span>
                            </label>
                        </div>
                        <div class="radio-group">
                            <input type="radio" id="star4" name="rating" class="rating" value="4">
                            <label for="star4">
                                <span>
                                    <i class="text-warning ti ti-star"></i>
                                    <i class="text-warning ti ti-star"></i>
                                    <i class="text-warning ti ti-star"></i>
                                    <i class="text-warning ti ti-star"></i>
                                    <i class=" ti ti-star"></i>
                                </span>
                            </label>
                        </div>
                        <div class="radio-group">
                            <input type="radio" id="star3" name="rating" class="rating" value="3">
                            <label for="star3">
                                <span>
                                    <i class="text-warning ti ti-star"></i>
                                    <i class="text-warning ti ti-star"></i>
                                    <i class="text-warning ti ti-star"></i>
                                    <i class=" ti ti-star"></i>
                                    <i class=" ti ti-star"></i>
                                </span>
                            </label>
                        </div>
                        <div class="radio-group">
                            <input type="radio" id="star2" name="rating" class="rating" value="2">
                            <label for="star2">
                                <span>
                                    <i class="text-warning ti ti-star"></i>
                                    <i class="text-warning ti ti-star"></i>
                                    <i class=" ti ti-star"></i>
                                    <i class=" ti ti-star"></i>
                                    <i class=" ti ti-star"></i>
                                </span>
                            </label>
                        </div>
                        <div class="radio-group">
                            <input type="radio" id="star1" name="rating" class="rating" value="1">
                            <label for="star1">
                                <span>
                                    <i class="text-warning ti ti-star"></i>
                                    <i class=" ti ti-star"></i>
                                    <i class=" ti ti-star"></i>
                                    <i class=" ti ti-star"></i>
                                    <i class=" ti ti-star"></i>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Product Brand --}}
            <div class="product-widget product-tag-widget">
                <div class="pro-itm has-children {{count($brand_select) > 0 ? 'is-open' : ''}}">
                    <a href="javascript:;" class="acnav-label">{{ __('Brands') }} </a>
                    <div class="pro-itm-inner acnav-list " style="{{count($brand_select) > 0 ? 'display: block;  ' : ''}}">
                        <div class="d-flex flex-wrap text-checkbox">
                            @foreach ($brands as $brand)
                                <div class="checkbox">
                                    <input id="brand_{{ $brand->id }}" name="brand_id" type="checkbox"
                                            class="product_brand" value="{{ $brand->id }}"
                                            {{ in_array($brand->id, $brand_select) ? 'checked' : ''}} >
                                    <label for="brand_{{ $brand->id }}"
                                        class="checkbox-label">{{ $brand->name }}</label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="product-widget product-filter-widget text-center">
                <button class="btn checkout-btn" id="product_filter_btn">
                    {{ __('Filter') }}
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <path d="M9.99991 11.2133C9.077 11.2145 8.18225 10.8948 7.46802 10.3089C6.75378 9.72305 6.26423 8.90709 6.08273 8.00001C6.06826 7.90447 6.07469 7.80691 6.10157 7.7141C6.12845 7.62129 6.17514 7.53544 6.2384 7.46252C6.30166 7.38959 6.37998 7.33132 6.46794 7.29175C6.55589 7.25218 6.65138 7.23225 6.74778 7.23335C6.90622 7.23104 7.06027 7.28551 7.1822 7.38696C7.30413 7.4884 7.38592 7.63015 7.41284 7.78668C7.53809 8.38596 7.86526 8.92378 8.33939 9.3098C8.81351 9.69582 9.40572 9.90653 10.0165 9.90653C10.6273 9.90653 11.2196 9.69582 11.6937 9.3098C12.1678 8.92378 12.495 8.38596 12.6202 7.78668C12.6472 7.63015 12.7289 7.4884 12.8509 7.38696C12.9728 7.28551 13.1268 7.23104 13.2853 7.23335C13.3817 7.23225 13.4772 7.25218 13.5651 7.29175C13.6531 7.33132 13.7314 7.38959 13.7947 7.46252C13.8579 7.53544 13.9046 7.62129 13.9315 7.7141C13.9584 7.80691 13.9648 7.90447 13.9503 8.00001C13.7678 8.91273 13.2733 9.73303 12.5522 10.3196C11.8311 10.9061 10.9285 11.2222 9.99991 11.2133Z" fill="#2C2C2C"></path>
                        <path d="M15.9189 20H4.08092C3.8103 20.0003 3.54244 19.9455 3.29363 19.8388C3.04483 19.7321 2.82028 19.5758 2.63364 19.3793C2.44701 19.1829 2.30219 18.9504 2.208 18.6961C2.11381 18.4418 2.07222 18.1709 2.08575 17.9L2.62444 6.40663C2.64674 5.89136 2.86675 5.40464 3.23852 5.04811C3.6103 4.69158 4.10511 4.4928 4.61961 4.49329H15.3802C15.8947 4.4928 16.3895 4.69158 16.7613 5.04811C17.1331 5.40464 17.3531 5.89136 17.3754 6.40663L17.9141 17.9C17.9276 18.1709 17.886 18.4418 17.7918 18.6961C17.6976 18.9504 17.5528 19.1829 17.3662 19.3793C17.1796 19.5758 16.955 19.7321 16.7062 19.8388C16.4574 19.9455 16.1895 20.0003 15.9189 20ZM4.61961 5.83329C4.44323 5.83329 4.27407 5.90353 4.14935 6.02855C4.02462 6.15358 3.95456 6.32315 3.95456 6.49996L3.41586 17.9667C3.41135 18.057 3.42522 18.1473 3.45661 18.232C3.48801 18.3168 3.53628 18.3943 3.59849 18.4598C3.6607 18.5252 3.73555 18.5774 3.81849 18.6129C3.90142 18.6485 3.99071 18.6668 4.08092 18.6667H15.9189C16.0091 18.6668 16.0984 18.6485 16.1813 18.6129C16.2643 18.5774 16.3391 18.5252 16.4013 18.4598C16.4636 18.3943 16.5118 18.3168 16.5432 18.232C16.5746 18.1473 16.5885 18.057 16.584 17.9667L16.0453 6.47329C16.0453 6.29648 15.9752 6.12691 15.8505 6.00189C15.7258 5.87686 15.5566 5.80662 15.3802 5.80662L4.61961 5.83329Z" fill="#2C2C2C"></path>
                        <path d="M13.9902 5.16668H12.6601V4.00001C12.6601 3.29276 12.3798 2.61449 11.8809 2.11439C11.382 1.61429 10.7054 1.33334 9.99986 1.33334C9.29432 1.33334 8.61768 1.61429 8.11879 2.11439C7.61991 2.61449 7.33963 3.29276 7.33963 4.00001V5.16668H6.00952V4.00001C6.00952 2.93914 6.42993 1.92172 7.17826 1.17158C7.9266 0.421428 8.94155 0 9.99986 0C11.0582 0 12.0731 0.421428 12.8215 1.17158C13.5698 1.92172 13.9902 2.93914 13.9902 4.00001V5.16668Z" fill="#2C2C2C"></path>
                    </svg>
                </button>
                <button class="btn checkout-btn product_delete_filter_btn"  onclick="window.location='{{ route("page.product-list",$slug) }}'">
                    {{ __('Reset filter') }}
                    <svg xmlns="http://www.w3.org/2000/svg" width="8" height="8" viewBox="0 0 8 8" fill="none">
                        <path fill-rule="evenodd" clip-rule="evenodd"
                            d="M6.43285 1.01177C6.59919 0.845434 6.86887 0.845434 7.03521 1.01177C7.20154 1.1781 7.20154 1.44779 7.03521 1.61412L4.62579 4.02354L7.03521 6.43295C7.20154 6.59929 7.20154 6.86897 7.03521 7.03531C6.86887 7.20164 6.59919 7.20164 6.43285 7.03531L4.02344 4.62589L1.61402 7.03531C1.44769 7.20164 1.178 7.20164 1.01167 7.03531C0.845333 6.86897 0.845333 6.59929 1.01167 6.43295L3.42108 4.02354L1.01167 1.61412C0.845333 1.44779 0.845332 1.17811 1.01167 1.01177C1.178 0.845434 1.44769 0.845434 1.61402 1.01177L4.02344 3.42119L6.43285 1.01177Z"
                            fill="#183A40" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    {{-- product listing --}}
    <div class="product-filter-right-column col-lg-9 col-md-12 col-12">
        <div class="row product_filter flex-slider">

        </div>
    </div>
</div>

@push('page-script')
<script>
    var isPreOrderModuleActive = {{ module_is_active('PreOrder') ? 'true' : 'false' }};
    $(document).ready(function() {
        var urlParams = new URLSearchParams(window.location.search);
        var page = urlParams.get('page') || 1;
        var product_tag = urlParams.get('product_tag');
        if (!product_tag) {
            product_tag = '{{ $product_tag }}';
        }

        var min_price = urlParams.get('min_price');
        var max_price = urlParams.get('max_price');
        var rating = urlParams.get('rating');
        var filter_product = urlParams.get('filter_product');
        var product_brand =  urlParams.get('product_brand');

        $('#min_price_select').val(min_price);
        $('#max_price_select').val(max_price);
        $('#filter_product').val(filter_product);

        if (product_tag) {
            var productTags = product_tag.split(',');
            productTags.forEach(function(tag) {
                $('#' + tag).prop('checked', true);
            });
        }

        if (product_brand) {
            var productBrands = product_brand.split(',');
            productBrands.forEach(function(brand) {
                $('#brand_' + brand).prop('checked', true);
            });
        }

        $('input[name="rating"][value="' + rating + '"]').prop('checked', true);

        product_page_filter(page);
    });


    $(document).on('click', '#product_filter_btn', function() {
        var page = $('.page-item.active .page-link').text();
        product_page_filter(page);
    });

    $(document).on('click', '.page-item .page-link', function(event) {
        event.preventDefault();
        var page = $(this).attr('href').split('page=')[1];
        product_page_filter(page);
    });


    $(".filter_product").change(function (){
        var page = $('.page-item.active .page-link').text();
        product_page_filter(page);
    });

    function product_page_filter(page) {

        var product_tag = [];
        var product_brand = [];
        $('.product_tag').each(function() {
            if ($(this).prop("checked") == true) {
                product_tag.push($(this).val());
            }
        });

        $('.product_brand').each(function() {
            if ($(this).prop("checked") == true) {
                product_brand.push($(this).val());
            }
        });

        var min_price = $('.min_price_select').attr('price');
        var max_price = $('.max_price_select').attr('price');
        var rating = $('input[name="rating"]:checked').val();
        var filter_product = $('.filter_product').val();

        var queryParams = new URLSearchParams({
            page: page,
            product_tag: product_tag,
            product_brand: product_brand,
            min_price: min_price,
            max_price: max_price,
            filter_product: filter_product,
            rating:rating
        });


        var queryString = queryParams.toString();
        history.replaceState(null, null, '?' + queryString);

        $.ajax({
            url: '{{ route('product.page.filter', $slug) }}?page='+ page + '&' + queryString,
            context: this,

            success: function(responce) {
                $('.product_filter').html(responce);

                if(isPreOrderModuleActive === 'true'){
                    $.ajax({
                        url: "{{ url($slug.'/preordersetting/out-of-stock-products') }}", // This hits the Laravel route defined above
                        method: 'GET',
                        success: function(stockResponse) {
                            const outOfStockProductIds = stockResponse.outOfStockProductIds;
                            const buttonName = stockResponse.buttonName;
                            const preOrderNote = stockResponse.message;

                            var noteElement = document.createElement('span');
                                noteElement.className = 'note-after-button';
                                noteElement.style.color = "red";
                                noteElement.style.fontSize = "12px";
                                noteElement.style.marginTop = "5px";
                                noteElement.style.display = "block";    //inline-flex
                                noteElement.style.width = "auto";
                                noteElement.textContent = preOrderNote;
                            
                            var $noteElement = $(noteElement);
                            
                            $('.addtocart-btn').each(function() {   //.addcart-btn-globaly
                                const button = $(this);
                                const productId = button.attr('product_id');

                                if (outOfStockProductIds.includes(parseInt(productId))) {

                                    button.attr('order_type', 'pre_order');

                                    const span = button.find('span');
                                    if (span.length > 0) {
                                        span.text(buttonName);
                                    }
                                    
                                    if (button.hasClass('quick-checkout-button')) {
                                        button.remove();
                                    }
                                    if (button.hasClass('checkout-button')) {
                                        button.remove();
                                    }
                                }
                            });
                        },
                        error: function(xhr, status, error) {
                            console.error('Error fetching out-of-stock product IDs:', error);
                        }
                    });
                }
            }
        });
    }
</script>
@endpush
