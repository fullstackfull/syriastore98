@foreach ($orders as $order)
    <div class="space-y-6">
        <!-- Order loop -->
        <div class="border rounded-lg overflow-hidden">
        <!-- Order Header -->
        <div
            class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-4 md:px-6 bg-primary/10 border-b">
            <div>
            <p class="text-sm text-gray-500 mb-1">{{ __('Order Placed') }}: <span class="font-medium text-gray-700">{{ \Carbon\Carbon::parse($order->order_date)->format('F j, Y') }}</span></p>
            <p class="text-sm text-gray-500">{{ __('Order Number:') }} <span
                class="font-medium text-gray-700">{{ '#' . $order->product_order_id }}</span></p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
            @if ($order->delivered_status == 0)
                <span class="inline-block bg-primary text-white px-3 py-1 rounded border border-primary text-xs font-medium">
                {{ __('Pending') }}
                </span>
            @elseif ($order->delivered_status == 1)
                <span class="inline-block bg-green-100 text-green-600 px-3 py-1 rounded border border-green-600 text-xs font-medium">
                {{ __('Delivered') }}
                </span>
            @elseif ($order->delivered_status == 2)
                <span class="inline-block bg-red-100 text-red-600 px-3 py-1 rounded border border-red-600 text-xs font-medium">
                {{ __('Cancel') }}
                </span>
            @elseif ($order->delivered_status == 3)
                <span class="inline-block bg-primary text-white px-3 py-1 rounded border border-primary text-xs font-medium">
                {{ __('Return') }}
                </span>
            @elseif ($order->delivered_status == 4)
                <span class="inline-block bg-primary text-white px-3 py-1 rounded border border-primary text-xs font-medium">
                {{ __('Confirmed') }}
                </span>
            @elseif ($order->delivered_status == 5)
                <span class="inline-block bg-primary text-white px-3 py-1 rounded border border-primary text-xs font-medium">
                {{ __('Picked Up') }}
                </span>
            @elseif ($order->delivered_status == 6)
                <span class="inline-block bg-primary text-white px-3 py-1 rounded border border-primary text-xs font-medium">
                {{ __('Shipped') }}
                </span>
            @elseif ($order->delivered_status == 7)
                <span class="inline-block bg-primary text-white px-3 py-1 rounded border border-primary text-xs font-medium">
                {{ __('Partially Paid') }}
                </span>
            @elseif ($order->delivered_status == 8)
                <span class="inline-block bg-primary text-white px-3 py-1 rounded border border-primary text-xs font-medium">
                {{ __('Pre Order') }}
                </span>
            @endif
            <a href="{{ route('order.details', [$store->slug, encrypt($order->id ?? '')]) }}"
                class="inline-flex items-center text-primary hover:text-primary-dark text-sm font-medium gap-1 details-btn">
                <span>{{ $themeSettings['order_list_button'] ?? '' }}</span>
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                class="h-4 w-4">
                <path d="M5 12h14" />
                <path d="M12 5l7 7-7 7" />
                </svg>
            </a>
            </div>
        </div>
        
        @php
            $products = json_decode($order->product_json, true);
        @endphp
        <!-- Order Content -->
        <div class="p-4 md:p-6 space-y-4">
            <!-- Products -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <!-- Product loop -->
            @if (!empty($products))
            @foreach ($products as $key => $product)
                <div class="flex items-start gap-3">
                <div class="h-20 w-20 flex-shrink-0 border bg-gray-100 rounded-lg">
                    <a href="{{ route('page.product', [$slug, getProductSlug($product['product_id'])]) }}">
                        <img src="{{ get_file($product['image']) }}" alt="cart-image" class="h-full w-full object-contain rounded-lg">
                    </a>
                </div>
                <div>
                    <h3 class="font-medium text-sm mb-1"><a href="{{ route('page.product', [$slug, getProductSlug($product['product_id'])]) }}">{{ $product["name"] }}</a></h3> 
                    <p class="font-medium text-sm text-gray-500">
                        @if ($product['variant_id'] != 0)
                            {!! \App\Models\ProductVariant::variantlist($product['variant_id']) !!}
                        @endif
                    </p>
                    @if ($order->payment_type == 'POS')
                    
                    
                    
                    
<p class="text-xs text-gray-500">
    {{ ($product["quantity"] ?? ($product["qty"] ?? 1)) . ' x ' . order_currency_format_with_sym($product["orignal_price"], $order->id) }}
</p>                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    @else
                    
                    
<p class="text-xs text-gray-500">
    {{ ($product["quantity"] ?? ($product["qty"] ?? 1)) . ' x ' . order_currency_format_with_sym($product["orignal_price"], $order->id) }}
</p>                    
                    
                    
                    
                    @endif
                </div>
                </div>
            @endforeach
            @endif
            </div>
            <!-- Order Summary -->
            <div class="flex flex-wrap items-end justify-between pt-4 border-t gap-y-4">
            <div>
                
                
<p class="text-sm text-gray-500 mb-1">
    {{ __('Total') }}: 
    <span class="font-bold text-primary-dark">
        {{ order_currency_format_with_sym($order->final_price, $order->id) }}
    </span>
</p>                    
                    
                <p class="text-sm text-gray-500">
                    
                    
                    
                    
                @if ($order->delivered_status == 0)
                    {{ __('Ordered on') }}: <span class="font-medium text-gray-700">{{ \Carbon\Carbon::parse($order->order_date)->format('F j, Y') }}</span>
                @elseif ($order->delivered_status == 1)
                    {{ __('Delivered on') }}: <span class="font-medium text-gray-700">{{ \Carbon\Carbon::parse($order->delivery_date)->format('F j, Y') }}</span>
                @elseif ($order->delivered_status == 2)
                    {{ __('Cancelled on') }}: <span class="font-medium text-gray-700">{{ \Carbon\Carbon::parse($order->cancel_date)->format('F j, Y') }}</span>
                @elseif ($order->delivered_status == 3)
                    {{ __('Returned on') }}: <span class="font-medium text-gray-700">{{ \Carbon\Carbon::parse($order->return_date)->format('F j, Y') }}</span>
                @elseif ($order->delivered_status == 4)
                    {{ __('Confirmed on') }}: <span class="font-medium text-gray-700">{{ \Carbon\Carbon::parse($order->confirmed_date)->format('F j, Y') }}</span>
                @elseif ($order->delivered_status == 5)
                    {{ __('Picked Up on') }}: <span class="font-medium text-gray-700">{{ \Carbon\Carbon::parse($order->picked_date)->format('F j, Y') }}</span>
                @elseif ($order->delivered_status == 6)
                    {{ __('Shipped on') }}: <span class="font-medium text-gray-700">{{ \Carbon\Carbon::parse($order->shipped_date)->format('F j, Y') }}</span>
                @elseif ($order->delivered_status == 7)
                    {{ __('Ordered on') }}: <span class="font-medium text-gray-700">{{ \Carbon\Carbon::parse($order->order_date)->format('F j, Y') }}</span>
                @elseif ($order->delivered_status == 8)
                    {{ __('Ordered on') }}: <span class="font-medium text-gray-700">{{ \Carbon\Carbon::parse($order->order_date)->format('F j, Y') }}</span>
                @endif
                </p>
            </div>
            <div class="flex gap-3 flex-wrap">
                <button class="inline-flex items-center gap-1 justify-center bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 py-2 px-4 rounded-md transition-all duration-300 text-sm font-medium"
                    data-url="{{ route('add.support.ticket', ['storeSlug' => $slug, 'order_id' => $order->id]) }}" data-size="lg"
                    data-ajax-popup="true" data-title="{{ __('Add Ticket') }}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        class="h-4 w-4">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                        <circle cx="9" cy="7" r="4" />
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                        <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                    </svg>
                    {{ __('Contact Support') }}
                </button>
                @if ($order->delivered_status == 1)
                    @if (isset($order->refund) && !empty($order->refund))
                        @if ($order->refund->refund_status == 'Cancel')
                            <p class="text-red-600 flex items-center"> {{ __('Refund request was cancelled.') }}</p>
                        @elseif ($order->refund->refund_status == 'Accept')
                            <p class="text-green-600 flex items-center"> {{ __('Refund request has been accepted.') }}</p>
                        @elseif ($order->refund->refund_status == 'Refunded')
                            <p class="text-yellow-600 flex items-center"> {{ __('Refund has been processed.') }}</p>
                        @else
                            <p class="text-yellow-600 flex items-center"> {{ __('Refund request has already sent.') }}</p>
                        @endif
                    @else
                        <button class="btn-primary py-2 px-4  text-sm font-medium"
                            data-url="{{ route('order.refund', [$store->slug, $order->id, 'refund' => true]) }}"
                            data-size="lg" data-ajax-popup="true" data-title="{{ __('Order Refund') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                class="h-4 w-4 mr-1">
                                <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2" />
                                <rect width="8" height="4" x="8" y="2" rx="1" ry="1" />
                                <path d="M17 17H7" />
                                <path d="M17 12H7" />
                                <path d="M7 7h7" />
                            </svg>
                            {{ __('Order Refund') }}
                        </button>
                    @endif
                @endif
            </div>
            </div>
        </div>
    </div>
@endforeach

<!-- Pagination -->
<div class="flex justify-center md:mt-8 mt-5 pagination-wrapper">
    <div class="flex items-center gap-2 pagination">
        @if($orders->onFirstPage())
            <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-500 cursor-not-allowed">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round" class="h-4 w-4">
                    <path d="m15 18-6-6 6-6" />
                </svg>
            </span>
        @else
            <a href="{{ $orders->previousPageUrl() }}"
                class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-500 hover:bg-gray-50">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round" class="h-4 w-4">
                    <path d="m15 18-6-6 6-6" />
                </svg>
            </a>
        @endif

        @php
            $current = $orders->currentPage();
            $last = $orders->lastPage();
        @endphp

        @if($current <= 2)
            @for($i = 1; $i <= min(3, $last); $i++)
                @if($i == $current)
                    <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-primary bg-primary text-white">{{ $i }}</span>
                @else
                    <a href="{{ $orders->url($i) }}" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">{{ $i }}</a>
                @endif
            @endfor
            @if($last > 3)
                <span class="p-1 h-8 w-8 flex items-center justify-center text-gray-500">...</span>
                <a href="{{ $orders->url($last) }}" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">{{ $last }}</a>
            @endif
        @elseif($current >= $last - 1)
            <a href="{{ $orders->url(1) }}" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">1</a>
            @if($last > 3)
                <span class="p-1 h-8 w-8 flex items-center justify-center text-gray-500">...</span>
            @endif
            @for($i = max(1, $last - 2); $i <= $last; $i++)
                @if($i == $current)
                    <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-primary bg-primary text-white">{{ $i }}</span>
                @else
                    <a href="{{ $orders->url($i) }}" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">{{ $i }}</a>
                @endif
            @endfor
        @else
            <a href="{{ $orders->url(1) }}" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">1</a>
            <span class="p-1 h-8 w-8 flex items-center justify-center text-gray-500">...</span>
            @for($i = $current; $i <= $current + 1; $i++)
                @if($i == $current)
                    <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-primary bg-primary text-white">{{ $i }}</span>
                @else
                    <a href="{{ $orders->url($i) }}" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">{{ $i }}</a>
                @endif
            @endfor
            <span class="p-1 h-8 w-8 flex items-center justify-center text-gray-500">...</span>
            <a href="{{ $orders->url($last) }}" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">{{ $last }}</a>
        @endif

        @if($orders->hasMorePages())
            <a href="{{ $orders->nextPageUrl() }}"
                class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-500 hover:bg-gray-50">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round" class="h-4 w-4">
                    <path d="m9 18 6-6-6-6" />
                </svg>
            </a>
        @else
            <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-500 cursor-not-allowed">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round" class="h-4 w-4">
                    <path d="m9 18 6-6-6-6" />
                </svg>
            </span>
        @endif
    </div>
</div>