<style>
    .product-view-left-inner .price {
        margin-bottom: 15px
    }

    .product-view-left-inner .section-title {
        margin-bottom: 20px;
    }

    .product-view-right-inner .product-view-inner {
        background-color: #ffe4aa;
        border-radius: 15px;
        position: relative;
        padding-top: 60%;
        height: 100%;
        overflow: hidden;
        border: none;
    }

    .product-view-right-inner .product-view-inner img {
        position: absolute;
        top: 0;
        left: 0;
        bottom: 0;
        right: 0;
        height: 100%;
        width: 100%;
        object-fit: cover;
    }

    .product-view-slider .slick-list {
        height: 100%;
    }

    .product-view-left-inner .qnty-btn-wrp .quantity-select {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-top: 15px;
    }

    .quick-product-dis:not(:last-child) {
        margin-bottom: 5px;
    }

    .quick-product-dis-col {
        gap: 15px;
        justify-content: space-between;
    }

    .quick-product-dis-content {
        margin: 0 0 0 15px;
    }

    .quick-product-dis-radio {
        max-width: 48%;
        width: 100%;
        border: 1px solid #d1d1d1;
        padding: 10px 15px;
        border-radius: 5px;
        -webkit-border-radius: 5px;
        -moz-border-radius: 5px;
    }

    .quick-product-dis-radio label {
        display: inline-flex !important;
    }

    .quick-product-dis-radio .quick-chechbox input {
        width: 15px;
    }

    .quick-product-dis-radio .quick-chechbox {
        margin: 0;
        align-items: center;
    }

    .quick-product-dis input[type="radio"] {
        margin-right: 8px;
        vertical-align: middle;
        cursor: pointer;
    }

    .quick-product-dis label:hover {
        color: #007bff;
    }

    .modal-footer .btn {
        margin: 10px 0 0 0;
    }

    .quick-product-dis-radio input[type="radio"] {
        padding: 5px;
        vertical-align: middle;
        cursor: pointer;
        -webkit-appearance: none;
        -moz-appearance: none;
        appearance: none;
        width: 7px;
        height: 7px;
        border: 1px solid var(--border-color);
        border-radius: 50%;
        transition: all 0.3s ease;
    }

    .quick-product-dis-radio input[type="radio"]:checked {
        background-color: var(--second-color);
        border-color: var(--black);
    }

    @media only screen and (max-width: 991px) {
        .quick-product-dis-radio {
            max-width: 80%;
        }
    }

    @media only screen and (max-width: 767px) {
        .quick-product-dis-radio {
            max-width: 100%;
        }

        .product-view-left-inner {
            margin: 15px 0 0 0;
        }
    }

    @media only screen and (max-width: 767px) {
        .quick-product-dis-radio {
            padding: 7px;
        }

        .quick-product-dis-content {
            margin: 0 0 0 8px;
        }
    }
</style>
{{ Form::open(['route' => array('refund-lottery-form.data.store', $store->slug), 'method' => 'post', 'enctype' => 'multipart/form-data', 'id'=> 'lottery-form']) }}
<div class="row">
    <div class="col-md-4 col-12 ">
        <div class="pdp-center-inner-sliders">
            <div class="product-view-right-inner">
                <div class="product-view-slider">
                    @foreach ($product->Sub_image($product->id)['data'] as $item)
                    <div class="quick-view-itm">
                        <div class="product-view-inner">
                            <img src="{{ asset($item->image_path) }}" alt="product">
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-8 col-12">
        <div class="product-view-left-inner">
            <div class="section-title">
                <h4>
                    <a href="{{ url($store->slug.'/product/'.$product->slug) }}" tabindex="0">{{ $product->name ?? '' }}</a>
                </h4>
            </div>
            <div class="price">
                <span>{{__('Ticket Price')}} : </span>
                <ins>{{ currency_format_with_sym(($lottery_setting->ticket_price ?? 0), ($store->id ?? null) ?? 0) }}</ins>
            </div>
            <div class="quick-product-dis-col d-flex">
                @foreach ($tickets as $ticket)
                <div class="quick-product-dis-radio">
                    <label class="quick-chechbox">
                        <input type="radio" name="ticket_code" value="{{ $ticket->lottery_code }}">
                        <div class="quick-product-dis-content">
                            <div class="quick-product-dis">
                                <p><b>{{__('Purchase Date')}}</b> : {{ $ticket->created_at }}</p>
                            </div>
                            <div class="quick-product-dis">
                                <p><b>{{__('Ticket Code')}}</b> : {{ $ticket->lottery_code }}</p>
                            </div>
                        </div>
                    </label>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
<div class="row">
    <div class="modal-footer col-12">
        <div class="d-flex acc-back-btn-wrp align-items-center justify-content-end">
            <button class="btn continue-btn" type="submit">
                {{__('Refund Ticket')}}
            </button>
        </div>
    </div>
</div>
{!! Form::close() !!}
<script>
    $('.product-view-slider').slick({
        dots: false,
        infinite: true,
        speed: 1000,
        slidesToShow: 1,
        arrows: true, // Enable arrows
        prevArrow: '<button class="slide-arrow slick-prev"><svg xmlns="http://www.w3.org/2000/svg" width="4" height="6" viewBox="0 0 4 6" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M0.65976 0.662719C0.446746 0.879677 0.446746 1.23143 0.65976 1.44839L2.18316 3L0.65976 4.55161C0.446747 4.76856 0.446747 5.12032 0.65976 5.33728C0.872773 5.55424 1.21814 5.55424 1.43115 5.33728L3.34024 3.39284C3.55325 3.17588 3.55325 2.82412 3.34024 2.60716L1.43115 0.662719C1.21814 0.445761 0.872773 0.445761 0.65976 0.662719Z" fill="white"></path></svg></button>',
        nextArrow: '<button class="slide-arrow slick-next"><svg xmlns="http://www.w3.org/2000/svg" width="4" height="6" viewBox="0 0 4 6" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M0.65976 0.662719C0.446746 0.879677 0.446746 1.23143 0.65976 1.44839L2.18316 3L0.65976 4.55161C0.446747 4.76856 0.446747 5.12032 0.65976 5.33728C0.872773 5.55424 1.21814 5.55424 1.43115 5.33728L3.34024 3.39284C3.55325 3.17588 3.55325 2.82412 3.34024 2.60716L1.43115 0.662719C1.21814 0.445761 0.872773 0.445761 0.65976 0.662719Z" fill="white"></path></svg></button>',
    });
</script>