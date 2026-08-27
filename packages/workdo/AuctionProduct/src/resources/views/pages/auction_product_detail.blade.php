@extends('front_end.layouts.app')
@section('page-title')
    {{ $product->name }} - {{ __('Auction Product') }}
@endsection

@section('content')

@php
  $maxBidAmount = Workdo\AuctionProduct\app\Models\AuctionProductBid::where('product_id', $product->id)
            ->max('bid_amount');
    $latestSales = \Workdo\AuctionProduct\app\Models\AuctionProductBid::productAuctionTime(
        $store->theme_id,
        $slug,
        $product->id,
    );
    $customer_id = auth('customers')->user()->id ?? 0;
    $DeliveryAddress = \App\Models\DeliveryAddress::where('customer_id', $customer_id)->first();
    $city_id = isset($DeliveryAddress->city_id) ? $DeliveryAddress->city_id : '';
    $state_id = isset($DeliveryAddress->state_id) ? $DeliveryAddress->state_id : '';
    $country = isset($DeliveryAddress->country_id) ? $DeliveryAddress->country_id : '';
    
    if ($product->tax_id !== null) {
        $tax_price = \App\Models\Cart::getProductTaxAmount(
            $product->tax_id,
            $bid_amount,
            $store->id,
            $city_id,
            $state_id,
            $country,
            true,
        );

    } else {
        $tax_price = 0;
    }
    
    $amount = $bid_amount + $tax_price;
    $shipping_price = \Workdo\AuctionProduct\app\Models\AuctionProductOrder::calculateFlateShipping(
        $product->shipping_id,
        $amount,
        $city_id,
        $state_id,
        $country,
        $product,
    );
@endphp

    <section class="lg:pt-20 pt-10 bg-gray-50">
        <div class="md:container w-full mx-auto px-4">
            <div class="bg-white rounded-lg shadow-sm overflow-hidden">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 p-4 md:p-8">
                    <!-- Product Images -->
                     <div>
                        <!-- Main Image -->
                        <div class="swiper main-image-slider mb-4">
                            <div class="swiper-wrapper">
                                @if (!empty($product->Sub_image($product->id)['data']))
                                    @foreach ($product->Sub_image($product->id)['data'] as $item)
                                    <div class="swiper-slide">
                                        <div class="relative pt-[65%] border bg-gray-100 rounded-lg overflow-hidden">
                                        <img src="{{ get_file($item->image_path) }}" alt="Organic Vegetable Box"
                                            class="w-full h-full absolute top-0 left-0 object-contain" />
                                        </div>
                                    </div>
                                    @endforeach
                                @else
                                    <div class="swiper-slide">
                                        <div class="relative pt-[65%] border bg-gray-100 rounded-lg overflow-hidden">
                                        <img src="{{ get_file($product->cover_image_path) }}" alt="Organic Vegetable Box"
                                            class="w-full h-full absolute top-0 left-0 object-contain" />
                                        </div>
                                    </div>
                                @endif
                            </div>
                            <!-- Add Navigation -->
                            <div class="arrow-wrapper">
                            <div class="swiper-button-next pdp-arrow">
                            </div>
                            <div class="swiper-button-prev pdp-arrow">
                            </div>
                            </div>
                        </div>
                        <!-- Thumbnail Images -->
                        <div class="swiper thumbnail-slider">
                            <div class="swiper-wrapper">
                                @if (!empty($product->Sub_image($product->id)['data']))
                                    @foreach ($product->Sub_image($product->id)['data'] as $item)
                                    <div class="swiper-slide">
                                        <div
                                        class="relative pt-[90%] thumb-image rounded-lg overflow-hidden border bg-gray-100 cursor-pointer">
                                        <img src="{{ get_file($item->image_path) }}" alt="Organic Vegetable Box - View 1"
                                            class="absolute top-0 left-0 w-full h-full object-contain" />
                                        </div>
                                    </div>
                                    @endforeach
                                @else
                                <div class="swiper-slide">
                                    <div class="relative pt-[90%] thumb-image rounded-lg overflow-hidden border bg-gray-100 cursor-pointer">
                                    <img src="{{ get_file($product->cover_image_path) }}" alt="Organic Vegetable Box - View 2"
                                        class="absolute top-0 left-0 w-full h-full object-contain" />
                                    </div>
                                </div>
                                @endif
                            
                            
                            </div>
                        </div>
                    </div>
                    <!-- Product Info -->
                    <div>
                        @if(isset($product->label) && !empty($product->label))
                            <div class="flex items-center mb-3">
                                <span class="bg-primary/10 text-primary text-xs font-bold px-2 py-1 rounded-md mr-2">
                                    {{ ucfirst(optional($product->label)->name ?? '') }}
                                </span>
                            </div>
                        @endif
                        <h2 class="font-bold text-2xl md:text-3xl mb-2">{{ $product->name }}</h2>

                        {{-- <div class="flex flex-wrap items-center gap-2 mb-4">
                            <div class="flex text-yellow-400">
                                @for ($i = 0; $i < 5; $i++)
                                    <i class="fas fa-star {{ $i < $product->average_rating ? 'text-warning' : '' }}"></i>
                                @endfor
                            </div>
                            <span class="text-gray-600 text-sm">{{ $product->average_rating }}.0 /<span>{{ __('5.0') }}</span></span>
                        </div> --}}

                        <div class="md:mb-6 mb-4">
                            <div class="flex items-baseline md:mb-4 mb-2">
                                <div class="inline-flex items-center justify-center px-5 py-2 rounded-full bg-gradient-to-r from-primary to-purple-600 text-white shadow-md text-xl font-semibold">
                                {{ __("   Curret Bid is :- ") }} {{ $maxBidAmount }}
                                </div>

                            </div>
                           
                                <p class="stock_status_color text-green-600 flex items-center" product_id="{{ $product->id }}"
                                    variant_id="{{ $product->default_variant_id }}" qty="1">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round" class="h-4 w-4 mr-1">
                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                                        <path d="m9 11 3 3L22 4" />
                                    </svg>
                                    <span class="stock_status">{{ __('Current Auction Running Bid Now') }}</span>
                                </p>
                            
                        </div>

                        <div class="border-t border-b md:py-6 py-4 md:mb-6 mb-4">
                            {!! $product->description !!}
                        </div>

                       <a href="javascript:void(0)"
                        class="group relative inline-flex items-center justify-center w-full px-6 py-3 bg-gradient-to-r from-primary to-purple-600 hover:from-primary-dark hover:to-purple-700 text-white font-bold text-base rounded-xl shadow-md transition-all duration-300 hover:scale-[1.02] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-dark place-bid"
                        data-ajax-popup="true"
                        data-size="md"
                        data-title="{{ __('Place Your Bid') }}"
                        data-url="{{ route('place_bid.create', ['storeSlug' => $slug, 'product_slug' => $product->slug]) }}"
                        title="{{ __('Place Bid Amount') }}">
                            <span class="flex items-center space-x-2">
                                <i class="fas fa-gavel text-white text-lg animate-bounce-slow group-hover:scale-110 transition-transform duration-300"></i>
                                <span>{{ __('Place Bid') }}</span>
                            </span>
                        </a>


                        <div class="product-hook mt-5">
                            @include('front_end.hooks.product_detail_info_button')
                        </div>
                        <div class="flex flex-col sm:flex-row items-start sm:items-center gap-4 text-sm text-gray-600">
                            @include('front_end.common.product.sale_counter')
                        </div>
                    </div>
                </div>

                <!-- Product Tabs -->
                @include('front_end.theme_common_table')
                
            </div>
        </div>
    </section>
      @include('front_end.hooks.product_detail_slider')
@endsection