<style>
    .lottery-product-slider {
        padding: 40px 0;
    }

    .lottery-product {
        margin: 0 -10px;
    }

    .lottery-product .slick-track {
        display: flex;
    }

    .lottery-product .product-card {
        padding: 0px;
        margin: 0 10px;
        position: relative;
        max-width: 100%;
    }

    .lottery-product .product-card .product-card-inner {
        padding: 0;
        width: 100%;
        border: 1px solid var(--border-color);
        display: block;
    }

    .lottery-product .product-card .product-card-inner .product-content {
        padding: 15px;
        height: auto;
    }

    .lottery-product .product-card .product-card-inner .product-card-image .pro-img {
        position: relative;
        display: block;
        padding-top: 66.2%;
        overflow: hidden;
        transition: all ease-in-out 0.6s;
        -webkit-transition: all ease-in-out 0.6s;
        -moz-transition: all ease-in-out 0.6s;
        -ms-transition: all ease-in-out 0.6s;
        -o-transition: all ease-in-out 0.6s;
    }

    .lottery-product .product-card .product-card-inner .product-card-image {
        margin: 0;
    }

    .lottery-product .product-card .product-card-inner .product-card-image .pro-img img {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 100%;
        width: 100%;
        object-fit: scale-down;
        transition: all ease-in-out 0.6s;
        -webkit-transition: all ease-in-out 0.6s;
        -moz-transition: all ease-in-out 0.6s;
        -ms-transition: all ease-in-out 0.6s;
        -o-transition: all ease-in-out 0.6s;
    }

    .lottery-product .product-card .product-card-inner .product-card-image:hover img.default-img {
        opacity: 0;
        visibility: hidden;
    }

    .lottery-product .product-card .product-card-inner .product-card-image a img.hover-img {
        opacity: 0;
        visibility: hidden;
    }

    .lottery-product .product-card .product-card-inner .product-card-image:hover img.hover-img {
        opacity: 1;
        visibility: visible;
    }

    .lottery-product .product-card .product-card-inner .product-content-top .product-title {
        margin-bottom: 15px;
        font: var(--h4);
    }

    .lottery-product .product-card .product-card-inner .product-content-top .product-title a {
        display: -webkit-box;
        -webkit-line-clamp: 1;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .lottery-product .product-card .product-card-inner .product-content-bottom {
        margin: 0;
    }

    .lottery-product .product-card .product-card-inner .product-content-bottom .pro-text {
        margin-bottom: 12px;
    }

    .lottery-product .product-card .product-card-inner .wishlist-btn {
        padding: 0 !important;
        position: absolute;
        top: 15px;
        right: 15px;
        height: 30px;
        width: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: var(--second-color);
        border-radius: 5px;
    }

    .lottery-product .product-card .product-content .product-content-top .reviews-stars-wrap {
        display: flex;
        align-items: center;
        gap: 5px;
        margin: 0;
    }

    .lottery-product .product-content .product-content-top .reviews-stars-wrap .reviews-stars-outer {
        margin: 0;
    }

    .lottery-product .product-content .product-card-inner .reviews-stars-wrap span {
        margin: 0;
    }

    .lottery-product .product-card .product-card-inner .product-btn-wrp {
        top: 50px;
        right: 15px;
    }

    .lottery-product .product-card .product-card-inner .product-content-top {
        margin-bottom: 15px;
    }

    .lottery-product .product-card .product-card-inner .product-card-image .product-btn-wrp a {
        padding: 0;
        display: flex;
    }

    .lottery-product .product-card .product-content-bottom .price {
        margin: 0;
        margin-bottom: 12px;
    }

    .lottery-product .section-title.d-flex {
        gap: 15px;
    }

    [lang="ar"] .lottery-product .product-card .wishlist-btn,
    [lang="he"] .lottery-product .product-card .wishlist-btn {
        left: 15px;
        right: auto;
    }

    [lang="ar"] .lottery-product .product-card .product-card-inner .product-btn-wrp,
    [lang="he"] .lottery-product .product-card .product-card-inner .product-btn-wrp {
        left: 15px;
        right: auto;
    }
    .product-content-bottom .price {
        justify-content: start;
    }
</style>

@if (\Route::current()->getName() == 'landing_page' && count($products) > 0)
    <section class="lottery-product-slider" style="position: relative;">
        <div class="container">
            <div class="section-title d-flex align-items-center justify-content-between">
                <h2 class="title">{{ __('Lottery Products') }}</h2>
                <a href="{{ route('page.product-list', ['storeSlug' => $slug]) }}" class="btn">
                    {{ __('Show more products') }}
                    <svg xmlns="http://www.w3.org/2000/svg" width="4" height="6" viewBox="0 0 4 6"
                        fill="none">
                        <path fill-rule="evenodd" clip-rule="evenodd"
                            d="M0.65976 0.662719C0.446746 0.879677 0.446746 1.23143 0.65976 1.44839L2.18316 3L0.65976 4.55161C0.446747 4.76856 0.446747 5.12032 0.65976 5.33728C0.872773 5.55424 1.21814 5.55424 1.43115 5.33728L3.34024 3.39284C3.55325 3.17588 3.55325 2.82412 3.34024 2.60716L1.43115 0.662719C1.21814 0.445761 0.872773 0.445761 0.65976 0.662719Z"
                            fill="white"></path>
                    </svg>
                </a>
            </div>
            @php
                $tax_price = 0;
                $shipping_price = 0;
            @endphp
            <div class="product-row lottery-product frequent-card">
                @foreach ($products as $item)
                    <div class="product-card">
                        <div class="product-card-inner">
                            <div class="product-card-image">
                                <a href="{{ url($slug . '/lottery-product/' . $item->slug) }}" class="pro-img">
                                    <img src="{{ asset($item->cover_image_path) }}" class="default-img">
                                    @if ($item->Sub_image($item->id)['status'] == true)
                                        <img src="{{ asset($item->Sub_image($item->id)['data'][0]->image_path ?? '') }}" class="hover-img">
                                    @else
                                        <img src="{{ asset($item->Sub_image($item->id)) }}" class="hover-img">
                                    @endif
                                </a>
                                <a href="javascript:void(0)" class="wishlist-btn" tabindex="0">
                                    <i class="ti ti-ticket"></i>
                                </a>
                            </div>
                            <div class="product-content">
                                <div class="product-content-top">
                                    <div class="product-type"></div>
                                    <h3 class="product-title">
                                        <a href="{{ url($slug . '/lottery-product/' . $item->slug) }}">
                                            {{ $item->name }}
                                        </a>
                                    </h3>
                                    <div class="reviews-stars-wrap">
                                        <div class="reviews-stars-outer">
                                            @for ($i = 0; $i < 5; $i++)
                                                <i class="ti ti-star review-stars {{ $i < $item->average_rating ? 'text-warning' : '' }} ">
                                                </i>
                                            @endfor
                                        </div>
                                        <div class="point-wrap">
                                            <span class="review-point">{{ $item->average_rating }}.0 /
                                                <span>5.0</span></span>
                                        </div>
                                    </div>
                                </div>
                                @php
                                    $lottery = Workdo\LotteryPrizes\app\Models\LotterySetting::where('lottery_products',$item->id)->where('store_id',$store->id)->first();
                                @endphp
                                <div class="product-content-bottom">
                                    <p class="pro-text">{{ __('Ticket Price') }}</p>
                                    <div class="price">
                                        <ins>{!! currency_format_with_sym($lottery->ticket_price ?? 0) !!}
                                            <span class="currency-type">{{ $tax_option['price_suffix'] ?? '' }}</span>
                                        </ins>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endif
@push('page-script')
    <script>
        $(document).ready(function() {
            $('.lottery-product').slick({
                autoplay: false,
                slidesToShow: 4,
                speed: 1000,
                slidesToScroll: 1,
                prevArrow: '<button class="slide-arrow slick-prev"><svg width="14" height="6" viewBox="0 0 14 6" fill="none" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M3.48504e-08 3.00044C-4.9681e-06 3.32261 0.261158 3.58378 0.583324 3.58378L11.9791 3.58396L10.6083 4.91557C10.3773 5.14006 10.3719 5.50937 10.5964 5.74045C10.8209 5.97153 11.1902 5.97688 11.4213 5.75239L13.8232 3.41906C13.9362 3.30922 14 3.15829 14 3.00065C14 2.84301 13.9362 2.69208 13.8232 2.58224L11.4213 0.24891C11.1902 0.0244262 10.8209 0.0297744 10.5964 0.260855C10.3719 0.491935 10.3773 0.861243 10.6083 1.08573L11.979 2.41729L0.583343 2.41712C0.261176 2.41711 5.07257e-06 2.67827 3.48504e-08 3.00044Z" fill="#DDE7DE"/></svg></button>',
                nextArrow: '<button class="slide-arrow slick-next"><svg width="14" height="6" viewBox="0 0 14 6" fill="none" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M3.48504e-08 3.00044C-4.9681e-06 3.32261 0.261158 3.58378 0.583324 3.58378L11.9791 3.58396L10.6083 4.91557C10.3773 5.14006 10.3719 5.50937 10.5964 5.74045C10.8209 5.97153 11.1902 5.97688 11.4213 5.75239L13.8232 3.41906C13.9362 3.30922 14 3.15829 14 3.00065C14 2.84301 13.9362 2.69208 13.8232 2.58224L11.4213 0.24891C11.1902 0.0244262 10.8209 0.0297744 10.5964 0.260855C10.3719 0.491935 10.3773 0.861243 10.6083 1.08573L11.979 2.41729L0.583343 2.41712C0.261176 2.41711 5.07257e-06 2.67827 3.48504e-08 3.00044Z" fill="#DDE7DE"/></svg></button>',
                dots: false,
                buttons: false,
                responsive: [{
                        breakpoint: 992,
                        settings: {
                            slidesToShow: 3,
                            slidesToScroll: 1
                        }
                    },
                    {
                        breakpoint: 768,
                        settings: {
                            slidesToShow: 2,
                            slidesToScroll: 1
                        }
                    },
                    {
                        breakpoint: 576,
                        settings: {
                            slidesToShow: 1,
                            slidesToScroll: 1
                        }
                    }
                ]
            });
        });
    </script>
@endpush
