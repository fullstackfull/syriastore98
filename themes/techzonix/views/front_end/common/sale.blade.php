@if (isset($themeSettings['more_offer_status']) && $themeSettings['more_offer_status'] == 1)
<section class="lg:pb-20 pb-10">
    <div class="container mx-auto px-4">
        <div class="relative overflow-hidden rounded-2xl lg:py-20 lg:px-10 sm:p-6 px-4 py-8">
            <img
                src="{{ get_file($themeSettings['more_offer_image'] ?? 'themes/techzonix/assets/images/offer-banner.png') }}"
                alt="Offer Banner"
                class="absolute inset-0 w-full h-full object-cover sm:object-center object-left rtl:scale-x-[-1]"
            >
            <div class="relative z-[2] lg:max-w-lg md:max-w-md max-w-sm">
                <h2 class="text-2xl sm:text-3xl lg:text-4xl font-bold md:mb-4 mb-2">
                    {!! $themeSettings['more_offer_big_text'] ?? __('Summer Sale is Live!') !!}
                </h2>
                <p class="lg:text-lg xl:text-xl md:mb-6 mb-3">
                    {!! $themeSettings['more_offer_content'] ?? __('Get up to 40% off on selected electronics and free shipping on all orders over $100.') !!}
                </p>
                <button class="btn-primary">
                    {!! $themeSettings['more_offer_button'] ?? __('Shop the Sale') !!}
                </button>
            </div>
        </div>
    </div>
</section>
@endif
