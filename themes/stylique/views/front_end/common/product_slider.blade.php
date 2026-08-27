<div class="swiper-wrapper">
    @foreach(collect($all_products)->reverse()->take(10) as $product)
        <div class="swiper-slide">
            <x-product-card :store="$store" :product="$product" />
        </div>
    @endforeach
</div>
<div class="arrow-wrapper">
    <div class="swiper-button-next"></div>
    <div class="swiper-button-prev"></div>
</div>