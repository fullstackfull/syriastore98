@php
    $currantLang = \Cookie::get('LANGUAGE') ?? $store->default_language;
    $defaultPro = $products[0] ?? null;
@endphp

@if (isset($products) && $products->count() > 0)
<style>
.recently-viewed-wrapper .recently-viewed-popup {
    position: fixed;
    bottom: 20px;
    left: 20px;
    max-width: 360px;
    width: calc(100% - 40px);
    background: rgba(255, 255, 255, 0.95);
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12), 0 2px 8px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(0, 0, 0, 0.08);
    backdrop-filter: blur(20px);
    z-index: 9999;
    opacity: 0;
    visibility: hidden;
    transform: translateY(30px) scale(0.9);
    transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
    overflow: hidden;
}
.recently-viewed-wrapper .recently-viewed-popup.active {
    opacity: 1;
    visibility: visible;
    transform: translateY(0) scale(1);
    animation: recentlyViewedBounceIn 0.6s ease-out;
}

@keyframes recentlyViewedBounceIn {
    0% { transform: translateY(30px) scale(0.9); opacity: 0; }
    50% { transform: translateY(-5px) scale(1.02); opacity: 0.8; }
    100% { transform: translateY(0) scale(1); opacity: 1; }
}
.recently-viewed-wrapper .recently-viewed-popup::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--primary-color);
    box-shadow: 0 0 10px rgba(var(--primary-color-rgb, 59, 130, 246), 0.3);
}
@keyframes recently-viewed-gradient-flow {
    0%, 100% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
}
.recently-viewed-wrapper .popup-header {
    padding: 16px 20px 12px;
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    position: relative;
}
.recently-viewed-wrapper .popup-title {
    font-size: 13px;
    font-weight: 700;
    color: var(--primary-color);
    margin: 0;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.recently-viewed-wrapper .popup-title::before {
    content: '👁️';
    font-size: 16px;
}
.recently-viewed-wrapper .close-btn {
    position: absolute;
    top: 12px;
    right: 16px;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.05);
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s ease;
}
.recently-viewed-wrapper .close-btn:hover {
    background: rgba(239, 68, 68, 0.1);
    transform: scale(1.1);
}
.recently-viewed-wrapper .close-btn svg {
    width: 14px;
    height: 14px;
    stroke: #6b7280;
    transition: stroke 0.3s ease;
}
.recently-viewed-wrapper .close-btn:hover svg {
    stroke: #ef4444;
}
.recently-viewed-wrapper .popup-content {
    padding: 16px 20px 20px;
}
.recently-viewed-wrapper .product-item {
    display: flex;
    align-items: center;
    gap: 16px;
    text-decoration: none;
    color: inherit;
    transition: all 0.3s ease;
    border-radius: 12px;
    padding: 8px;
    margin: -8px;
}
.recently-viewed-wrapper .product-item:hover {
    background: rgba(var(--primary-color-rgb, 59, 130, 246), 0.03);
    transform: translateX(4px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
}
.recently-viewed-wrapper .product-image {
    width: 56px;
    height: 56px;
    border-radius: 10px;
    overflow: hidden;
    flex-shrink: 0;
    background: #f8fafc;
    position: relative;
    border: 2px solid rgba(var(--primary-color-rgb, 59, 130, 246), 0.1);
    transition: all 0.3s ease;
}

.recently-viewed-wrapper .product-item:hover .product-image {
    border-color: rgba(var(--primary-color-rgb, 59, 130, 246), 0.2);
    box-shadow: 0 4px 8px rgba(var(--primary-color-rgb, 59, 130, 246), 0.1);
}
.recently-viewed-wrapper .product-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
}
.recently-viewed-wrapper .product-item:hover .product-image img {
    transform: scale(1.05);
}
.recently-viewed-wrapper .product-info {
    flex: 1;
    min-width: 0;
}
.recently-viewed-wrapper .product-category {
    font-size: 11px;
    color: #9ca3af;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
    font-weight: 500;
}
.recently-viewed-wrapper .product-name {
    font-size: 15px;
    font-weight: 600;
    color: #111827;
    margin-bottom: 6px;
    line-height: 1.3;
    display: -webkit-box;
    -webkit-line-clamp: 1;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.recently-viewed-wrapper .product-description {
    font-size: 12px;
    color: #6b7280;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.recently-viewed-wrapper .view-indicator {
    position: absolute;
    bottom: 6px;
    right: 6px;
    background: var(--primary-color);
    color: white;
    font-size: 9px;
    padding: 3px 6px;
    border-radius: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

@if ($currantLang == 'ar' || $currantLang == 'he')
.recently-viewed-wrapper .recently-viewed-popup {
    left: auto;
    right: 30px;
}
.recently-viewed-wrapper .close-btn {
    right: auto;
    left: 16px;
}
.recently-viewed-wrapper .product-item:hover {
    transform: translateX(-4px);
}
@endif

@media (max-width: 768px) {
    .recently-viewed-wrapper .recently-viewed-popup {
        left: 15px;
        right: 15px;
        width: calc(100% - 30px);
        max-width: none;
        bottom: 20px;
    }

    @if ($currantLang == 'ar' || $currantLang == 'he')
    .recently-viewed-wrapper .recently-viewed-popup {
        left: 15px;
        right: 15px;
    }
    @endif

    .recently-viewed-wrapper .popup-content {
        padding: 12px 16px 16px;
    }

    .recently-viewed-wrapper .product-image {
        width: 50px;
        height: 50px;
    }

    .recently-viewed-wrapper .product-name {
        font-size: 14px;
    }
}

.recently-viewed-wrapper .product-item.switching {
    animation: recently-viewed-switch 0.5s ease;
}

@keyframes recently-viewed-switch {
    0% { opacity: 1; transform: translateX(0); }
    50% { opacity: 0.7; transform: translateX(10px); }
    100% { opacity: 1; transform: translateX(0); }
}
</style>

<div class="recently-viewed-wrapper">
    <div class="recently-viewed-popup" data-time="{{ ($setting['recently_viewed_time'] ?? 3) * 1000 }}">
        <div class="popup-header">
            <h4 class="popup-title">{{ __('Recently Viewed') }}</h4>
            <button class="close-btn" type="button">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <div class="popup-content">
            <a href="{{ url($slug . '/product/' . ($defaultPro->product->slug ?? '')) }}" class="product-item">
                <div class="product-image">
                    <img src="{{ get_file($defaultPro->product->cover_image_path ?? '') }}"
                         alt="{{ $defaultPro->product->name ?? '' }}">
                    <div class="view-indicator">{{ __('Viewed') }}</div>
                </div>
                <div class="product-info">
                    <div class="product-category">{{ $defaultPro->product->ProductData->name ?? '' }}</div>
                    <div class="product-name">{{ $defaultPro->product->name ?? '' }}</div>
                    <div class="product-description">{{ strip_tags($defaultPro->product->description ?? '') }}</div>
                </div>
            </a>
        </div>

        <div style="display: none;">
            @foreach ($products as $product)
                @if (isset($product->product))
                    <div class="data-product"
                         data-id="{{ $product->product->id }}"
                         data-image="{{ get_file($product->product->cover_image_path ?? '') }}"
                         data-url="{{ url($slug . '/product/' . ($product->product->slug ?? '')) }}"
                         data-title="{{ $product->product->name ?? '' }}"
                         data-category="{{ $product->product->ProductData->name ?? '' }}"
                         data-description="{{ strip_tags($product->product->description ?? '') }}">
                    </div>
                @endif
            @endforeach
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function initRecentlyViewedPopup() {
        const popup = document.querySelector('.recently-viewed-popup');
        if (!popup) return;

        if (getCookie('recently_viewed_closed') === 'true') {
            popup.remove();
            return;
        }

        const closeBtn = popup.querySelector('.close-btn');
        closeBtn.addEventListener('click', function() {
            popup.classList.remove('active');
            setTimeout(() => popup.remove(), 400);
            setCookie('recently_viewed_closed', 'true', 1);
        });

        function rotateProducts() {
            const productItems = popup.querySelectorAll('.data-product');
            if (productItems.length <= 1) return;

            const randomIndex = Math.floor(Math.random() * productItems.length);
            const selectedProduct = productItems[randomIndex];

            const productItem = popup.querySelector('.product-item');
            const productImage = productItem.querySelector('.product-image img');
            const productCategory = productItem.querySelector('.product-category');
            const productName = productItem.querySelector('.product-name');
            const productDescription = productItem.querySelector('.product-description');

            productItem.classList.add('switching');

            setTimeout(() => {
                productImage.src = selectedProduct.dataset.image;
                productImage.alt = selectedProduct.dataset.title;
                productCategory.textContent = selectedProduct.dataset.category;
                productName.textContent = selectedProduct.dataset.title;
                productDescription.textContent = selectedProduct.dataset.description;
                productItem.href = selectedProduct.dataset.url;

                productItem.classList.remove('switching');
            }, 250);
        }

        setTimeout(() => {
            popup.classList.add('active');
        }, 1000);

        const rotationTime = parseInt(popup.dataset.time) || 3000;
        let rotationInterval = setInterval(rotateProducts, rotationTime);

        popup.addEventListener('mouseenter', () => {
            clearInterval(rotationInterval);
        });

        popup.addEventListener('mouseleave', () => {
            rotationInterval = setInterval(rotateProducts, rotationTime);
        });

        if (window.innerWidth <= 480) {
            popup.style.display = 'none';
        }
    }

    function setCookie(name, value, days) {
        const expires = new Date();
        expires.setTime(expires.getTime() + (days * 24 * 60 * 60 * 1000));
        document.cookie = name + '=' + value + ';expires=' + expires.toUTCString() + ';path=/';
    }

    function getCookie(name) {
        const nameEQ = name + "=";
        const ca = document.cookie.split(';');
        for(let i = 0; i < ca.length; i++) {
            let c = ca[i];
            while (c.charAt(0) === ' ') c = c.substring(1, c.length);
            if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
        }
        return null;
    }

    initRecentlyViewedPopup();
});
</script>
@endif
