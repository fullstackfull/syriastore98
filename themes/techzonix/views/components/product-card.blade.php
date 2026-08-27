@props(['product', 'store'])

<div class="card product-card flex flex-col h-full">
                {!! \App\Models\Product::productSalesPage( $store->slug, $product->id, false) !!}
        
                <div class="relative h-48 bg-gray-100">
                    <a href="{{ url($store->slug . '/product/' . $product->slug) }}">
                        <img src="{{ get_file($product->cover_image_path) }}" alt="Ultra HD Smart Watch Pro"
                            class="w-full h-full object-contain p-4">
                        {!! \App\Models\Product::actionLinks( $store, $product) !!}
                    </a>
                    <div class="pro-btn-wrapper absolute top-4 right-4 flex flex-col gap-3 transition-all duration-300">
                        <a href="javascript:void(0)" class="wishlist-btn wishbtn-globaly wishlist-btn h-8 w-8 rounded flex items-center justify-center bg-primary hover:bg-primary/80 transition-all duration-300" tabindex="0"
                            product_id="{{ $product->id }}"
                            in_wishlist="{{ $product->in_whishlist ? 'remove' : 'add' }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="17" height="15" viewBox="0 0 17 15"
                                fill="none" class="{{ $product->in_whishlist ? 'fa fa-heart' : 'ti ti-heart' }}">
                                <path
                                    d="M8.50033 14.3354C8.28074 14.3354 8.06824 14.3071 7.89116 14.2433C5.18533 13.3154 0.885742 10.0216 0.885742 5.15538C0.885742 2.67622 2.89033 0.664551 5.35533 0.664551C6.55241 0.664551 7.67158 1.13205 8.50033 1.96788C9.32908 1.13205 10.4482 0.664551 11.6453 0.664551C14.1103 0.664551 16.1149 2.6833 16.1149 5.15538C16.1149 10.0287 11.8153 13.3154 9.10949 14.2433C8.93241 14.3071 8.71991 14.3354 8.50033 14.3354ZM5.35533 1.72705C3.47824 1.72705 1.94824 3.26413 1.94824 5.15538C1.94824 9.9933 6.60199 12.685 8.23824 13.2446C8.36574 13.2871 8.64199 13.2871 8.76949 13.2446C10.3987 12.685 15.0595 10.0004 15.0595 5.15538C15.0595 3.26413 13.5295 1.72705 11.6524 1.72705C10.5757 1.72705 9.57699 2.22997 8.93241 3.10122C8.73408 3.37038 8.28074 3.37038 8.08241 3.10122C7.42366 2.22288 6.43199 1.72705 5.35533 1.72705Z"
                                    fill="#111111">
                                </path>
                            </svg>
                        </a>
                    </div>
                </div>
                <div class="h-full flex flex-col">
                    <div class="p-4 flex-1">
                        <div class="text-xs text-gray-500 mb-1">{{ optional($product->ProductData)->name ?? '' }}</div>
                        <h3 class="font-medium text-gray-900 mb-1 truncate text-lg">
                            <a href="{{ url($store->slug . '/product/' . $product->slug) }}">{{ $product->name }}</a>
                        </h3>
                        <div class="flex items-center mt-2">
                            @if ($product->variant_product == 0)
                                <span class="text-lg font-semibold">
                                    {!! \App\Models\Product::getProductPrice ($product, $store) !!}
                                </span>
                            @else
                                <span class="text-sm text-gray-500 line-through ml-2">{{ __('In Variant') }}</span>
                            @endif
                        </div>
                        @include('front_end.hooks.product_rating')
                    </div>
                    <div class="border-t p-4">
                        <button class="w-full btn-primary addtocart-btn btn addcart-btn-globaly" product_id="{{ $product->id }}">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z">
                                </path>
                            </svg>
                            <span>{{ __('Add to cart') }}</span>
                        </button>
                        {!! \App\Models\Product::ProductcardButton($store->slug, $product) !!}
                    </div>
                </div>
            </div>
