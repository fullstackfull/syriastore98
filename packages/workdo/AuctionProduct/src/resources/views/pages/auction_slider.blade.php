@if (!empty($auction_product) && count($auction_product) > 0)
<section class="lg:pb-16 pb-5 bg-gray-50">
    <div class="md:container w-full mx-auto px-4">
        <div class="flex flex-col md:flex-row items-center justify-between gap-2 lg:mb-8 mb-4">
            <h2 class="text-2xl lg:text-3xl font-bold text-gray-900">
                {{ __('Auction Products') }}
            </h2>
            <a href="{{ route('page.product-list', ['storeSlug' => $slug]) }}"
               class="text-primary font-medium flex items-center gap-2">
                {{ __('View All Auctions') }}
                <i class="fas fa-arrow-right"></i>
            </a>
        </div>

        <div class="swiper auction-swiper">
            <div class="swiper-wrapper pb-5">
                @foreach ($auction_product as $item)
                    @php
                        $maxBidAmount = \Workdo\AuctionProduct\app\Models\AuctionProductBid::where('product_id', $item->id)->max('bid_amount');
                        $maxprice = $item->sale_price;
                        $bid_amount = $maxBidAmount ? $maxBidAmount : $maxprice;
                    @endphp
                    <div class="swiper-slide">
                        <div class="bg-white rounded-lg overflow-hidden shadow-md hover:shadow-lg transition-shadow duration-300 h-full flex flex-col">
                            <div class="relative">
                                <a href="{{ url($slug . '/auction-product/' . $item->slug) }}"
                                   class="block pt-[66.2%] relative overflow-hidden">
                                    <img src="{{ asset($item->cover_image_path) }}"
                                         class="absolute top-0 left-0 w-full h-full object-cover transition-opacity duration-500 default-img" 
                                         alt="{{ $item->name }}" />
                                    @if ($item->Sub_image($item->id)['status'] == true)
                                        <img src="{{ asset($item->Sub_image($item->id)['data'][0]->image_path ?? '') }}"
                                             class="absolute top-0 left-0 w-full h-full object-cover opacity-0 hover:opacity-100 transition-opacity duration-500 hover-img" 
                                             alt="{{ $item->name }}" />
                                    @endif
                                </a>

                                <button class="wishlist-btn wishbtn-globaly absolute top-3 right-3 w-9 h-9 bg-white rounded-lg flex items-center justify-center border border-gray-200 hover:border-red-400 transition-colors z-10"
                                        product_id="{{ $item->id }}"
                                        in_wishlist="{{ $item->in_whishlist ? 'remove' : 'add' }}">
                                    <i class="{{ $item->in_whishlist ? 'fas fa-heart text-red-500' : 'far fa-heart text-gray-600 hover:text-red-500' }}"></i>
                                </button>

                                {!! \App\Models\Product::actionLinks($slug, $item) !!}
                            </div>

                            <div class="p-4 flex-1 flex flex-col">
                                <h3 class="font-semibold mb-2 text-base leading-tight flex-1">
                                    <a href="{{ url($slug . '/auction-product/' . $item->slug) }}" class="hover:text-primary transition-colors">
                                        {{ $item->name }}
                                    </a>
                                </h3>

                                <div class="mb-4">
                                    <p class="text-xs text-gray-500 mb-1">{{ __('Current Highest Bid') }}</p>
                                    <div class="text-lg font-bold text-primary">
                                        {!! currency_format_with_sym($bid_amount) !!}
                                        <span class="text-sm font-medium text-gray-500">{{ $tax_option['price_suffix'] ?? '' }}</span>
                                    </div>
                                </div>

                                @if (auth('customers')->user())
                                    <a href="javascript:void(0)"
                                       class="w-full bg-primary hover:bg-primary-dark text-white font-semibold py-2 px-4 rounded-lg transition-colors duration-300 text-center place-bid"
                                       data-ajax-popup="true"
                                       data-size="md"
                                       data-title="{{ __('Place Your Bid') }}"
                                       data-url="{{ route('place_bid.create', [$slug, $item->slug]) }}"
                                       title="{{ __('Place Bid Amount') }}">
                                        {{ __('Place Bid') }}
                                    </a>
                                @else
                                    <a href="{{ route('login') }}"
                                       class="w-full bg-primary hover:bg-primary-dark text-white font-semibold py-2 px-4 rounded-lg transition-colors duration-300 text-center"
                                       title="{{ __('Login to Place Bid') }}">
                                        {{ __('Login to Bid') }}
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="flex justify-center">
                <div class="swiper-button-prev"></div>
                <div class="swiper-button-next"></div>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const auctionSwiper = new Swiper('.auction-swiper', {
        slidesPerView: 1,
        spaceBetween: 20,
        loop: false,
        navigation: {
            nextEl: '.swiper-button-next',
            prevEl: '.swiper-button-prev',
        },
        breakpoints: {
            640: {
                slidesPerView: 2,
                spaceBetween: 20,
            },
            768: {
                slidesPerView: 2,
                spaceBetween: 24,
            },
            1024: {
                slidesPerView: 3,
                spaceBetween: 24,
            },
            1280: {
                slidesPerView: 4,
                spaceBetween: 24,
            }
        }
    });
});
</script>
@endif