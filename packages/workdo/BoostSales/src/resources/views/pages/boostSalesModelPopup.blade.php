@php
    $lang = app()->getLocale();
    $currentTheme = $currentTheme ?? 'greentic';
    $themeColors = [
        'greentic' => [
            'primary' => '#4caf50',
            'primary_dark' => '#388e3c',
            'primary_light' => '#4CAF501A',
            'accent' => '#ff9800',
            'accent_dark' => '#f57c00',
            'white' => '#ffffff',
            'black' => '#060606'
        ],
        'stylique' => [
            'primary' => '#f8796c',
            'primary_dark' => '#e55a4a',
            'primary_light' => '#f8796c1A',
            'accent' => '#6c63ff',
            'accent_dark' => '#5a52e6',
            'white' => '#ffffff',
            'black' => '#333333'
        ],
        'techzonix' => [
            'primary' => '#007bff',
            'primary_dark' => '#0056b3',
            'primary_light' => '#007bff1A',
            'accent' => '#28a745',
            'accent_dark' => '#1e7e34',
            'white' => '#ffffff',
            'black' => '#212529'
        ]
    ];
    $colors = $themeColors[$currentTheme] ?? $themeColors['greentic'];
@endphp

<style>
.boost-sales-btn {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, {{ $colors['accent'] }} 0%, {{ $colors['accent_dark'] }} 100%);
    border: none !important;
    border-radius: 50% !important;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 8px 20px rgba(255, 107, 53, 0.3);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    border: 2px solid rgba(255, 255, 255, 0.2) !important;
    text-decoration: none;
    margin-bottom: 8px;
}

.boost-sales-btn::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: conic-gradient(from 0deg, transparent, rgba(255,255,255,0.3), transparent);
    border-radius: 50%;
    opacity: 0;
    animation: boost-rotate 3s linear infinite;
    transition: opacity 0.3s ease;
}

.boost-sales-btn:hover::before {
    opacity: 1;
}

.boost-sales-btn:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 12px 25px rgba(255, 107, 53, 0.4);
    text-decoration: none;
}

.boost-sales-btn i {
    color: white;
    font-size: 18px;
    transition: transform 0.3s ease;
    filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.3));
}

.boost-sales-btn:hover i {
    transform: scale(1.1) rotate(15deg);
}

@keyframes boost-rotate {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.boost-sales-popup {
    position: fixed !important;
    bottom: 80px !important;
    right: 20px !important;
    width: 380px !important;
    max-height: 600px !important;
    background: {{ $colors['white'] }} !important;
    border-radius: 20px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.25) !important;
    z-index: 999999 !important;
    opacity: 0;
    visibility: hidden;
    transform: translateX(100%) scale(0.8) !important;
    transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1) !important;
    overflow: hidden;
    border: 3px solid {{ $colors['primary'] }} !important;
    animation: pulse-border 2s infinite;
}

@keyframes pulse-border {
    0%, 100% { border-color: {{ $colors['primary'] }}; box-shadow: 0 20px 60px rgba(0,0,0,0.25), 0 0 0 0 {{ $colors['primary_light'] }}; }
    50% { border-color: {{ $colors['primary_dark'] }}; box-shadow: 0 25px 70px rgba(0,0,0,0.3), 0 0 0 8px {{ $colors['primary_light'] }}; }
}

.boost-sales-popup.auto-show {
    animation: auto-popup 0.8s ease-out 2s forwards, pulse-border 2s infinite 3s;
}

@keyframes auto-popup {
    0% { opacity: 0; visibility: hidden; transform: translateX(100%) scale(0.8); }
    100% { opacity: 1; visibility: visible; transform: translateX(0) scale(1); }
}

.boost-sales-popup.active {
    opacity: 1 !important;
    visibility: visible !important;
    transform: translateX(0) scale(1) !important;
    animation: none !important;
}

.boost-sales-popup.closing {
    opacity: 0 !important;
    visibility: hidden !important;
    transform: translateX(100%) scale(0.8) !important;
    transition: all 0.4s ease !important;
}

.boost-sales-body {
    height: 100%;
    display: flex;
    flex-direction: column;
}

.boost-sales-head {
    background: linear-gradient(135deg, {{ $colors['primary'] }} 0%, {{ $colors['primary_dark'] }} 100%);
    color: {{ $colors['white'] }};
    padding: 1.2rem 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
    position: relative;
    overflow: hidden;
}

.boost-sales-head::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
    animation: shimmer 3s infinite;
}

@keyframes shimmer {
    0% { left: -100%; }
    100% { left: 100%; }
}



.boost-sales-head h4 {
    font-size: 1rem;
    font-weight: 600;
    margin: 0;
}

.boost-sales-close {
    width: 24px;
    height: 24px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 16px;
    transition: all 0.3s ease;
}

.boost-sales-close:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: scale(1.1);
}

.boost-sales-inner {
    padding: 1rem;
    overflow-y: auto;
    flex: 1;
    max-height: 350px;
}

.boost-product-card {
    display: flex;
    align-items: center;
    padding: 0.75rem;
    border: 1px solid #e0e0e0;
    border-radius: 12px;
    margin-bottom: 0.75rem;
    transition: all 0.3s ease;
    background: #fafafa;
}

.boost-product-card:hover {
    border-color: {{ $colors['primary'] }};
    background: {{ $colors['white'] }};
    box-shadow: 0 4px 12px {{ $colors['primary_light'] }};
}

.boost-product-checkbox {
    margin-right: 0.75rem;
    width: 18px;
    height: 18px;
    accent-color: {{ $colors['primary'] }};
}

.boost-product-image {
    width: 50px;
    height: 50px;
    border-radius: 8px;
    overflow: hidden;
    margin-right: 0.75rem;
    flex-shrink: 0;
}

.boost-product-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.boost-product-info {
    flex: 1;
    min-width: 0;
}

.boost-product-name {
    font-size: 0.85rem;
    font-weight: 600;
    color: #333;
    margin-bottom: 0.25rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.boost-product-price {
    font-size: 0.8rem;
    color: {{ $colors['primary'] }};
    font-weight: 700;
}

.boost-total-section {
    padding: 1rem 1.5rem;
    background: #f8f9fa;
    border-top: 1px solid #e0e0e0;
    flex-shrink: 0;
}

.boost-total-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
}

.boost-total-label {
    font-size: 0.9rem;
    color: #666;
}

.boost-total-amount {
    font-size: 1.1rem;
    font-weight: 700;
    color: #333;
}

.boost-buy-btn {
    width: 100%;
    background: linear-gradient(135deg, {{ $colors['primary'] }} 0%, {{ $colors['primary_dark'] }} 100%);
    color: {{ $colors['white'] }};
    border: none;
    border-radius: 12px;
    padding: 0.75rem;
    font-weight: 700;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.3s ease;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.boost-buy-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px {{ $colors['primary_light'] }};
    background: linear-gradient(135deg, {{ $colors['primary_dark'] }} 0%, {{ $colors['accent'] }} 100%);
}

.boost-buy-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

@media (max-width: 768px) {
    .boost-sales-btn {
        width: 42px;
        height: 42px;
    }
    
    .boost-sales-btn i {
        font-size: 16px;
    }
}

@media (max-width: 480px) {
    .boost-sales-popup {
        left: 10px !important;
        width: calc(100vw - 20px) !important;
        max-width: 350px !important;
        bottom: 70px !important;
    }
    
    .boost-product-name {
        font-size: 0.8rem;
    }
    
    .boost-product-price {
        font-size: 0.75rem;
    }
}
</style>

<div class="boost-sales">
    <div class="boost-sales-border">
        <a href="javascript:void()" class="btn boost-sales-btn" id="boost-sales-btn" data-toggle="tooltip"
            title="{{ __('Best Seller Products') }}">
            <i class="fas fa-rocket free-shipping-icon"></i>
        </a>
    </div>
    <div class="boost-sales-popup d-none">
        <div class="boost-sales-body">
            <div class="boost-sales-head">
                <h4>{{ __('Best Seller Products') }}</h4>
                <div class="boost-sales-close">×</div>
            </div>
            
            <div class="boost-sales-inner">
                @foreach ($products as $item)
                    <div class="boost-product-card">
                        <input type="checkbox" 
                               class="boost-product-checkbox boost-sales-value" 
                               id="boost_checkbox_{{ $item->id }}"
                               data-product-id="{{ $item->id }}"
                               data-price="{{ $item->price }}"
                               checked>
                        
                        <div class="boost-product-image">
                            <img src="{{ get_file($item->cover_image_path) }}" alt="{{ $item->name }}">
                        </div>
                        
                        <div class="boost-product-info">
                            <div class="boost-product-name">{{ $item->name }}</div>
                            <div class="boost-product-price">
                                @if ($item->variant_product == 0)
                                    {!! \App\Models\Product::getProductPrice($item, $store ?? null) !!}
                                @else
                                    {{ __('In Variant') }}
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            
            <div class="boost-total-section">
                <div class="boost-total-row">
                    <span class="boost-total-label">{{ __('Selected Items:') }}</span>
                    <span class="boost-total-amount" id="selected-count">{{ count($products) }}</span>
                </div>
                <div class="boost-total-row">
                    <span class="boost-total-label">{{ __('Total Amount:') }}</span>
                    <span class="boost-total-amount" id="total-amount">$0.00</span>
                </div>
                <button class="boost-buy-btn" id="boost-buy-now">
                    <i class="fas fa-shopping-cart"></i> {{ __('Add Selected to Cart') }}
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const boostSalesBtn = document.getElementById('boost-sales-btn');
    const boostSalesPopup = document.querySelector('.boost-sales-popup');
    const boostSalesClose = document.querySelector('.boost-sales-close');
    const checkboxes = document.querySelectorAll('.boost-product-checkbox');
    const selectedCount = document.getElementById('selected-count');
    const totalAmount = document.getElementById('total-amount');
    const buyBtn = document.getElementById('boost-buy-now');
    
    let isPopupVisible = false;
    
    // Auto-show popup on page load
    function autoShowPopup() {
        document.body.appendChild(boostSalesPopup);
        boostSalesPopup.classList.remove('d-none');
        boostSalesPopup.classList.add('auto-show');
        isPopupVisible = true;
        updateTotal();
        
        // Auto-hide after 10 seconds if not interacted
        setTimeout(() => {
            if (isPopupVisible && !boostSalesPopup.matches(':hover')) {
                hidePopup();
            }
        }, 10000);
    }
    
    // Show popup manually
    function showPopup() {
        document.body.appendChild(boostSalesPopup);
        boostSalesPopup.classList.remove('d-none', 'closing');
        boostSalesPopup.classList.add('active');
        isPopupVisible = true;
        updateTotal();
    }
    
    // Hide popup
    function hidePopup() {
        boostSalesPopup.classList.add('closing');
        boostSalesPopup.classList.remove('active', 'auto-show');
        isPopupVisible = false;
        setTimeout(() => {
            boostSalesPopup.classList.add('d-none');
        }, 400);
    }
    
    // Toggle popup with rocket button
    boostSalesBtn.addEventListener('click', function(e) {
        e.preventDefault();
        if (isPopupVisible) {
            hidePopup();
        } else {
            showPopup();
        }
    });
    
    // Auto-show popup after page fully loads
    window.addEventListener('load', function() {
        setTimeout(autoShowPopup, 3000);
    });
    
    // Close modal
    boostSalesClose.addEventListener('click', function() {
        hidePopup();
    });
    
    // Update total calculation
    function updateTotal() {
        let count = 0;
        let total = 0;
        
        checkboxes.forEach(checkbox => {
            if (checkbox.checked) {
                count++;
                const price = parseFloat(checkbox.dataset.price) || 0;
                total += price;
            }
        });
        
        selectedCount.textContent = count;
        totalAmount.textContent = '$' + total.toFixed(2);
        buyBtn.disabled = count === 0;
    }
    
    // Checkbox change events
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateTotal);
    });
    
    // Buy button click
    buyBtn.addEventListener('click', function() {
        const selectedProducts = [];
        checkboxes.forEach(checkbox => {
            if (checkbox.checked) {
                selectedProducts.push({
                    id: checkbox.dataset.productId,
                    qty: 1
                });
            }
        });
        
        if (selectedProducts.length > 0) {
            // Add products to cart using existing functionality
            selectedProducts.forEach(product => {
                // Trigger existing add to cart functionality
                const addToCartBtn = document.querySelector(`[product_id="${product.id}"][variant_id="0"]`);
                if (addToCartBtn) {
                    addToCartBtn.click();
                } else {
                    // Fallback: use global cart function if available
                    if (typeof addToCart === 'function') {
                        addToCart(product.id, 0, 1);
                    }
                }
            });
            
            // Show success message
            if (typeof show_toastr === 'function') {
                show_toastr('Success', 'Products added to cart successfully!', 'success');
            }
            
            // Hide popup after adding to cart
            hidePopup();
        }
    });
    
    // Close on backdrop click
    window.addEventListener('click', function(event) {
        if (event.target === boostSalesPopup) {
            boostSalesPopup.classList.remove('active');
            setTimeout(() => {
                boostSalesPopup.classList.add('d-none');
            }, 300);
        }
    });
    
    // Initialize total
    updateTotal();
});
</script>