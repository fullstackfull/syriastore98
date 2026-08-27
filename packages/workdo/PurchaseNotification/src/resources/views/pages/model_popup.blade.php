@php
    $currantLang = \Cookie::get('LANGUAGE') ?? $store->default_language;
@endphp

<style>
    .product-purchase-notification {
        background: linear-gradient(135deg, var(--primary-color) 0%, color-mix(in srgb, var(--primary-color) 80%, #000) 100%);
        backdrop-filter: blur(10px);
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15), 0 4px 10px rgba(0, 0, 0, 0.1), inset 0 1px 0 rgba(255, 255, 255, 0.1);
        max-width: 380px;
        width: 100%;
        position: fixed;
        top: 20px;
        left: 20px;
        padding: 16px;
        z-index: 9999;
        border-radius: 12px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        color: white;
        transform: translateX(-100%);
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        opacity: 0;
        visibility: hidden;
    }

    .product-purchase-notification.active {
        opacity: 1;
        visibility: visible;
        transform: translateX(0);
        animation: slideInPulse 0.6s ease-out;
    }

    @keyframes slideInPulse {
        0% { transform: translateX(-100%) scale(0.9); }
        60% { transform: translateX(5px) scale(1.02); }
        100% { transform: translateX(0) scale(1); }
    }

    .product-purchase-notification .product-purchase-suggest {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .product-purchase-notification .closeNotifyPurchaseNotifiaction {
        position: absolute;
        top: 8px;
        right: 8px;
        cursor: pointer;
        z-index: 2;
        padding: 4px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.1);
        transition: all 0.2s ease;
    }

    .product-purchase-notification .closeNotifyPurchaseNotifiaction:hover {
        background: rgba(255, 255, 255, 0.2);
        transform: scale(1.1);
    }

    .product-purchase-notification .closeNotifyPurchaseNotifiaction svg {
        width: 14px;
        height: 14px;
    }

    .product-purchase-notification .closeNotifyPurchaseNotifiaction svg path {
        fill: white;
    }

    .product-purchase-suggest .product-purchase-image {
        flex-shrink: 0;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        border: 2px solid rgba(255, 255, 255, 0.2);
    }

    .product-purchase-suggest .product-purchase-image img {
        height: 60px;
        width: 60px;
        object-fit: cover;
        transition: transform 0.3s ease;
    }

    .product-purchase-suggest .product-purchase-image:hover img {
        transform: scale(1.05);
    }

    .product-purchase-notification .column-right {
        flex: 1;
        min-width: 0;
    }

    .product-purchase-label {
        font-size: 12px;
        opacity: 0.9;
        margin-bottom: 4px;
        display: -webkit-box;
        -webkit-line-clamp: 1;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .product-purchase-name {
        font-size: 14px;
        font-weight: 600;
        color: white;
        text-decoration: none;
        margin-bottom: 4px;
        display: -webkit-box;
        -webkit-line-clamp: 1;
        -webkit-box-orient: vertical;
        overflow: hidden;
        transition: opacity 0.2s ease;
    }

    .product-purchase-name:hover {
        opacity: 0.8;
        color: white;
        text-decoration: none;
    }

    .from-ago {
        font-size: 11px;
        opacity: 0.7;
        display: -webkit-box;
        -webkit-line-clamp: 1;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    @media screen and (max-width: 767px) {
        .product-purchase-notification {
            top: auto;
            bottom: 20px;
            left: auto;
            right: 10px;
            max-width: 320px;
            transform: translateY(100%);
        }
        
        .product-purchase-notification.active {
            transform: translateY(0);
            animation: slideUpPulse 0.6s ease-out;
        }
        
        @keyframes slideUpPulse {
            0% { transform: translateY(100%) scale(0.9); }
            60% { transform: translateY(-5px) scale(1.02); }
            100% { transform: translateY(0) scale(1); }
        }
    }

    [lang="ar"] .product-purchase-notification,
    [lang="he"] .product-purchase-notification {
        left: auto;
        right: 20px;
        transform: translateX(100%);
    }

    [lang="ar"] .product-purchase-notification.active,
    [lang="he"] .product-purchase-notification.active {
        transform: translateX(0);
    }

    [lang="ar"] .product-purchase-notification .closeNotifyPurchaseNotifiaction,
    [lang="he"] .product-purchase-notification .closeNotifyPurchaseNotifiaction {
        right: auto;
        left: 8px;
    }
</style>

@php
    $defaultPro = $products[0] ?? null;
    $defaultOrder = $defaultPro
        ? \App\Models\Order::whereRaw('FIND_IN_SET(?, product_id)', [$defaultPro->id])
            ->orderBy('order_date', 'desc')
            ->first()
        : null;
    $defaultCustomer = $defaultOrder ? \App\Models\Customer::find($defaultOrder->customer_id) : null;
    $defaultTime = $defaultOrder ? \Carbon\Carbon::parse($defaultOrder->order_date)->diffForHumans() : '1 hour ago';
@endphp

@if (isset($products) && count($products) > 0)
    <div class="product-purchase-notification" data-time="{{ $setting['display_time'] ?? 20000 }}">
        <a class="closeNotifyPurchaseNotifiaction">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="11" viewBox="0 0 12 11" fill="none">
                <path
                    d="M5.94141 0.155762C3.0178 0.155762 0.639648 2.5344 0.639648 5.45752C0.639648 8.38064 3.0178 10.7593 5.94141 10.7593C8.86502 10.7593 11.2432 8.38064 11.2432 5.45752C11.2432 2.5344 8.86502 0.155762 5.94141 0.155762ZM5.94141 10.0195C3.42566 10.0195 1.37943 7.97327 1.37943 5.45752C1.37943 2.94177 3.42566 0.895542 5.94141 0.895542C8.45715 0.895542 10.5034 2.94177 10.5034 5.45752C10.5034 7.97327 8.45715 10.0195 5.94141 10.0195ZM7.68235 4.23936L6.46418 5.45752L7.68235 6.67568C7.82686 6.82018 7.82686 7.05446 7.68235 7.19897C7.61035 7.27097 7.51566 7.30745 7.42097 7.30745C7.32627 7.30745 7.23158 7.27146 7.15958 7.19897L5.94141 5.98078L4.72323 7.19897C4.65123 7.27097 4.55654 7.30745 4.46185 7.30745C4.36715 7.30745 4.27246 7.27146 4.20046 7.19897C4.05595 7.05446 4.05595 6.82018 4.20046 6.67568L5.41863 5.45752L4.20046 4.23936C4.05595 4.09486 4.05595 3.86058 4.20046 3.71607C4.34496 3.57157 4.57923 3.57157 4.72373 3.71607L5.9419 4.93426L7.16007 3.71607C7.30458 3.57157 7.53884 3.57157 7.68335 3.71607C7.82687 3.86058 7.82686 4.09535 7.68235 4.23936Z"
                    fill="#ffffff" />
            </svg>
        </a>
        <div class="product-purchase-suggest">
            <a class="product-purchase-image"
                href="{{ isset($setting['open_product_page']) && $setting['open_product_page'] == 'on' ? url($slug . '/product/' . ($defaultPro->slug ?? '')) : '#' }}">
                <img src="{{ get_file($defaultPro->cover_image_path ?? '') }}" alt="img01">
            </a>
            <div class="column-right">
                <div class="product-purchase-label">
                    {{ $defaultCustomer ? $defaultCustomer->first_name . ' ' . $defaultCustomer->last_name : 'Guest' }}
                    {{ __('purchased a') }}
                </div>
                <a class="product-purchase-name"
                    href="{{ isset($setting['open_product_page']) && $setting['open_product_page'] == 'on' ? url($slug . '/product/' . ($defaultPro->slug ?? '')) : '#' }}">
                    {{ $defaultPro->name ?? '' }}
                </a>
                <div class="from-ago">{{ $defaultTime }}</div>
            </div>
        </div>
        <div class="hide">
            @foreach ($products as $product)
                @php
                    $order = \App\Models\Order::whereRaw('FIND_IN_SET(?, product_id)', [$product->id])->latest()->first();
                    $customer = $order ? \App\Models\Customer::find($order->customer_id) : null;
                    $time = $order ? \Carbon\Carbon::parse($order->order_date)->diffForHumans() : '1 hour ago';
                @endphp
                <div class="data-product"
                    data-id="{{ $product->id }}"
                    data-image="{{ get_file($product->cover_image_path) }}"
                    data-url="{{ isset($setting['open_product_page']) && $setting['open_product_page'] == 'on' ? url($slug . '/product/' . $product->slug) : '#' }}"
                    data-title="{{ $product->name }}"
                    data-alt="{{ $product->name }}"
                    data-local="{{ $time }}"
                    data-label="{{ $customer ? $customer->first_name . ' ' . $customer->last_name : 'Guest' }} {{ __('purchased a') }}">
                </div>
            @endforeach
        </div>
    </div>
@endif

@push('page-script')
    <script>
        function SomeonePurchasedNotification() {
            if (window.innerWidth > 767) {
                if (jQuery.cookie('purchase_notification') === 'closed') {
                    jQuery('.product-purchase-notification').remove();
                }

                jQuery('.closeNotifyPurchaseNotifiaction').on('click', function () {
                    jQuery('.product-purchase-notification').remove();
                    jQuery.cookie('purchase_notification', 'closed', { expires: 1, path: '/' });
                });

                var displayTime = {{ $setting['display_time'] ?? 20000 }};
                var nextTimeDisplay = {{ $setting['next_time_display'] ?? 5000 }};
                var hideTimeout;

                function showNotification() {
                    var number = $('.data-product').length;
                    if (number === 0) return;

                    var i = Math.floor(Math.random() * number),
                        data = $('.data-product').eq(i),
                        image = data.data('image'),
                        title = data.data('title'),
                        url = data.data('url'),
                        local = data.data('local'),
                        label = data.data('label');

                    $('.product-purchase-notification').addClass('active');
                    $('.product-purchase-notification .product-purchase-image img').attr('src', image);
                    $('.product-purchase-notification .product-purchase-name').text(title).attr('href', url);
                    $('.product-purchase-notification .product-purchase-image').attr('href', url);
                    $('.product-purchase-notification .from-ago').text(local);
                    $('.product-purchase-notification .product-purchase-label').text(label);

                    hideTimeout = setTimeout(() => {
                        $('.product-purchase-notification').removeClass('active');
                    }, displayTime);
                }

                function toggleNotification() {
                    if ($('.product-purchase-notification').hasClass('active')) {
                        $('.product-purchase-notification').removeClass('active');
                    } else {
                        showNotification();
                    }
                }

                showNotification();
                let timer = setInterval(toggleNotification, displayTime + nextTimeDisplay);

                $('.product-purchase-notification').hover(
                    () => {
                        clearInterval(timer);
                        clearTimeout(hideTimeout);
                    },
                    () => {
                        timer = setInterval(toggleNotification, displayTime + nextTimeDisplay);
                        hideTimeout = setTimeout(() => {
                            $('.product-purchase-notification').removeClass('active');
                        }, displayTime);
                    }
                );
            }
        }

        SomeonePurchasedNotification();
    </script>
@endpush
