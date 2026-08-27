<table class="lg:w-full min-w-[850px] border-collapse">
    <thead>
        <tr>
            <th class="py-3 px-4 text-left rtl:text-right font-semibold text-white bg-primary rounded-st-md">
                {{ __('Product') }}</th>
            <th class="py-3 px-4 text-center font-semibold text-white bg-primary">{{ __('Price') }}</th>
            <th class="py-3 px-4 text-center font-semibold text-white bg-primary">{{ __('Stock Status') }}</th>
            <th class="py-3 px-4 text-center font-semibold text-white bg-primary rounded-et-md">
                {{ __('Actions') }}</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-gray-200">
        @if(count($wishlists) > 0)
            @foreach ($wishlists as $wishlist)
            <tr>
                <td class="py-4 px-4">
                    <div class="flex items-center gap-4">
                        <div class="h-20 w-20 flex-shrink-0 border bg-gray-100 rounded-lg">
                            <img src="{{ get_file($wishlist->ProductData->cover_image_path ?? '') }}" alt="wishlist-image"
                                class="h-full w-full object-contain rounded-md" />
                        </div>
                        <div>
                            <h3 class="font-medium mb-1">
                                <a href="{{ route('page.product', [$store->slug, $wishlist->ProductData->slug]) }}"
                                    class="hover:text-primary transition-all duration-300">{{ !empty($wishlist->ProductData) ? $wishlist->ProductData->name : '' }}</a>
                            </h3>
                            @if (!empty($wishlist->GetVariant))
                            <p class="text-sm text-gray-500">{{ $wishlist->GetVariant->variant }}</p>
                            @endif
                        </div>
                    </div>
                </td>
                <td class="py-4 px-4 text-center">
                    <span class="font-semibold">{{ $wishlist->ProductData->price }}</span>
                </td>
                <td class="py-4 px-4 text-center">
                    @if ($wishlist->ProductData->track_stock == 0) 
                        @if ($wishlist->ProductData->stock_status == 'out_of_stock') 
                        <span class="text-red-600 bg-red-100 px-2 py-1 rounded border border-red-600 text-xs font-medium">{{ __('Out of stock') }}</span>
                        @elseif ($wishlist->ProductData->stock_status == 'on_backorder')
                        <span class="text-yellow-600 bg-yellow-100 px-2 py-1 rounded border border-yellow-600 text-xs font-medium">{{ __('On Backorder') }}</span>
                        @else
                        <span class="text-green-600 bg-green-100 px-2 py-1 rounded border border-green-600 text-xs font-medium w-max inline-block">{{ __('In stock') }}</span>
                        @endif                   
                    @else
                        @if ($wishlist->ProductData->product_stock <= (isset($adminSetting['out_of_stock_threshold']) ? $adminSetting['out_of_stock_threshold'] : 0)) 
                        <span class="text-red-600 bg-red-100 px-2 py-1 rounded border border-red-600 text-xs font-medium">{{ __('Out of stock') }}</span>
                        @else
                        <span class="text-green-600 bg-green-100 px-2 py-1 rounded border border-green-600 text-xs font-medium w-max inline-block">{{ __('In stock') }}</span>
                        @endif
                    @endif
                </td>
                <td class="py-4 px-4 text-center">
                    <div class="flex flex-row justify-center gap-2">
                        @if ($wishlist->ProductData->variant_product == 1)
                        <button class="btn-primary px-3 py-2 font-medium text-sm addtocart-btn addcart-btn-globaly w-max" product_id="{{ $wishlist->ProductData->id }}" variant_id="0" qty="1">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                class="h-4 w-4">
                                <circle cx="8" cy="21" r="1" />
                                <circle cx="19" cy="21" r="1" />
                                <path
                                    d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12" />
                            </svg>
                            {{ __('Add to Cart') }}
                        </button>
                        @else
                        <a href="{{ route('page.product', [$store->slug, $wishlist->ProductData->slug]) }}" class="btn-primary px-3 py-2 font-medium text-sm addtocart-btn addcart-btn-globaly w-max" product_id="{{ $wishlist->ProductData->id }}" variant_id="0" qty="1">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                class="h-4 w-4">
                                <circle cx="8" cy="21" r="1" />
                                <circle cx="19" cy="21" r="1" />
                                <path
                                    d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12" />
                            </svg>
                            {{ __('Add to Cart') }}
                        </a>
                        @endif

                        <button
                            class="inline-flex gap-1 items-center justify-center text-red-500 hover:text-red-700 px-3 py-2 rounded-md transition-all duration-300 text-sm font-medium delete_wishlist" data-id="{{ $wishlist->id }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                class="h-4 w-4">
                                <path d="M3 6h18" />
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
                                <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                <line x1="10" x2="10" y1="11" y2="17" />
                                <line x1="14" x2="14" y1="11" y2="17" />
                            </svg>
                            {{ __('Remove') }}
                        </button>
                    </div>
                </td>
            </tr>
            @endforeach
        @else
            <tr>
                <td colspan="100%" class="text-center py-4 text-gray-500">{{ __('No records found')}}</td>
            </tr>
        @endif
    </tbody>
</table>