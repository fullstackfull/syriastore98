@extends('front_end.layouts.app')
@section('page-title')
    {{ __('Special Offers & Coupons') }}
@endsection
@php
    $lang = app()->getLocale();
@endphp

@push('page-style')
<style>
.coupon-page-wrapper {
    min-height: 60vh;
    padding: 2rem 1rem;
    background: var(--bs-light, #f8f9fa);
}

.coupon-container {
    max-width: 1200px;
    margin: 0 auto;
}

.coupon-header {
    text-align: center;
    margin-bottom: 3rem;
}

.coupon-title {
    font-size: 2.5rem;
    font-weight: 700;
    color: var(--bs-dark, #212529);
    margin-bottom: 0.5rem;
}

.coupon-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 1.5rem;
}

.coupon-item {
    background: white;
    border-radius: 0;
    padding: 0;
    box-shadow: 0 15px 35px rgba(0,0,0,0.1);
    transition: all 0.4s ease;
    position: relative;
    overflow: hidden;
    border: 3px dashed transparent;
    background-image: 
        radial-gradient(circle at 20px 50%, transparent 20px, white 21px),
        radial-gradient(circle at calc(100% - 20px) 50%, transparent 20px, white 21px),
        var(--gradient);
    background-size: 100% 100%, 100% 100%, 100% 100%;
    background-position: 0 0, 0 0, 0 0;
    clip-path: polygon(0 20px, 20px 20px, 20px 0, calc(100% - 20px) 0, calc(100% - 20px) 20px, 100% 20px, 100% calc(100% - 20px), calc(100% - 20px) calc(100% - 20px), calc(100% - 20px) 100%, 20px 100%, 20px calc(100% - 20px), 0 calc(100% - 20px));
}

.coupon-item:hover {
    transform: translateY(-10px) rotate(1deg);
    box-shadow: 0 25px 50px rgba(0,0,0,0.15);
}

.coupon-left {
    background: var(--gradient);
    width: 120px;
    height: 100%;
    position: absolute;
    left: 0;
    top: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: white;
    text-align: center;
}

.coupon-right {
    margin-left: 120px;
    padding: 1.5rem;
    background: white;
    position: relative;
}

.coupon-divider {
    position: absolute;
    left: 120px;
    top: 0;
    bottom: 0;
    width: 3px;
    background: repeating-linear-gradient(
        to bottom,
        #ddd 0px,
        #ddd 8px,
        transparent 8px,
        transparent 16px
    );
}

.coupon-item::before {
    content: '';
    position: absolute;
    left: 120px;
    top: -15px;
    width: 30px;
    height: 30px;
    background: var(--bs-light, #f8f9fa);
    border-radius: 50%;
    transform: translateX(-50%);
    box-shadow: 0 0 0 3px white;
}

.coupon-item::after {
    content: '';
    position: absolute;
    left: 120px;
    bottom: -15px;
    width: 30px;
    height: 30px;
    background: var(--bs-light, #f8f9fa);
    border-radius: 50%;
    transform: translateX(-50%);
    box-shadow: 0 0 0 3px white;
}

.coupon-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.coupon-discount {
    display: flex;
    align-items: baseline;
    gap: 0.25rem;
}

.discount-value {
    font-size: 2rem;
    font-weight: 900;
    color: white;
    line-height: 1;
    text-shadow: 0 2px 4px rgba(0,0,0,0.3);
}

.discount-text {
    font-size: 0.9rem;
    font-weight: 600;
    color: rgba(255,255,255,0.9);
    text-transform: uppercase;
    letter-spacing: 2px;
    margin-top: 0.25rem;
}

.discount-section {
    text-align: center;
    margin-bottom: 1rem;
}

.coupon-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1rem;
}

.coupon-icon {
    font-size: 1.5rem;
    color: rgba(255,255,255,0.8);
    margin-top: 0.5rem;
}

.coupon-name {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--bs-dark, #212529);
    margin-bottom: 0.75rem;
}

.coupon-desc {
    font-size: 0.9rem;
    color: var(--bs-secondary, #6c757d);
    margin-bottom: 1rem;
}

.coupon-products {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.product-thumb {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    overflow: hidden;
    border: 2px solid var(--bs-border-color, #dee2e6);
}

.product-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.coupon-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem;
    background: var(--bs-light, #f8f9fa);
    border-radius: 8px;
    margin-bottom: 0.75rem;
}

.code-label {
    font-size: 0.8rem;
    color: var(--bs-secondary, #6c757d);
    margin-right: 0.5rem;
}

.coupon-code {
    font-family: 'Courier New', monospace;
    font-weight: 700;
    color: var(--bs-dark, #212529);
    font-size: 1rem;
}

.copy-button {
    background: var(--gradient);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 0.5rem 1rem;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 0.8rem;
    font-weight: 600;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}

.copy-button:hover {
    background: var(--bs-primary-dark, #0b5ed7);
    transform: scale(1.05);
}

.copy-button:active {
    transform: scale(0.95);
}

.copy-button.copied {
    background: var(--bs-success, #198754) !important;
    animation: pulse 0.6s ease-in-out;
}

@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.1); }
    100% { transform: scale(1); }
}

.coupon-expiry {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.85rem;
}

.expiry-text {
    color: var(--bs-secondary, #6c757d);
}

.expiry-date.valid {
    color: var(--bs-success, #198754);
    font-weight: 600;
}

.expiry-date.expired {
    color: var(--bs-danger, #dc3545);
    font-weight: 600;
}

.no-coupons {
    text-align: center;
    padding: 4rem 2rem;
    color: var(--bs-secondary, #6c757d);
}

.no-coupons i {
    font-size: 4rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}

.no-coupons h3 {
    font-size: 1.5rem;
    margin-bottom: 0.5rem;
    color: var(--bs-dark, #212529);
}

.gradient-1 { --gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
.gradient-2 { --gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
.gradient-3 { --gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
.gradient-4 { --gradient: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
.gradient-5 { --gradient: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
.gradient-6 { --gradient: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); }

@media (max-width: 768px) {
    .coupon-title {
        font-size: 2rem;
    }
    
    .coupon-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .coupon-item {
        padding: 1.25rem;
    }
    
    .discount-value {
        font-size: 1.75rem;
    }
}
</style>
@endpush

@section('content')
<section class="banner-section relative lg:py-16 py-10 bg-cover bg-center" style="background-image: url('{{ get_file($themeSettings['product_list_banner_image'] ?? '') }}');">
    <div class="md:container w-full mx-auto px-4">
    <div class="text-center relative z-[2]">
        <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">{{ __('Available Coupons') }}</h2>
         <p class="coupon-subtitle">{{ __('Save more with exclusive discount codes') }}</p>
    </div>
    </div>
</section>
<div class="coupon-page-wrapper">
    <div class="coupon-container">
        <div class="coupon-grid">
            @foreach ($coupons as $key => $coupon)
                <div class="coupon-item gradient-{{ ($key % 6) + 1 }}">
                    <div class="coupon-left">
                        <div class="discount-section">
                            @if ($coupon->coupon_type == 'flat' || $coupon->coupon_type == 'fixed product discount')
                                <div class="discount-value">{{ $currency_icon }}{{ $coupon->discount_amount }}</div>
                                <div class="discount-text">{{ __('OFF') }}</div>
                            @else
                                <div class="discount-value">{{ $coupon->discount_amount }}%</div>
                                <div class="discount-text">{{ __('OFF') }}</div>
                            @endif
                        </div>
                        <div class="coupon-icon">
                            <i class="fas fa-gift"></i>
                        </div>
                    </div>
                    
                    <div class="coupon-divider"></div>
                    
                    <div class="coupon-right">
                        <div class="coupon-header">
                            <h3 class="coupon-name">{{ $coupon->coupon_name }}</h3>
                            <div class="coupon-expiry">
                                <span class="expiry-date {{ ($coupon->coupon_expiry_date > date('Y-m-d')) ? 'valid' : 'expired' }}">
                                    {{ ($coupon->coupon_expiry_date > date('Y-m-d')) ? \App\Models\Utility::dateFormat($coupon->coupon_expiry_date) : __('Expired') }}
                                </span>
                            </div>
                        </div>

                        @if ($coupon->coupon_type == 'fixed product discount')
                            @php
                                $appliedProductIds = explode(',', $coupon->applied_product);
                                $products = App\Models\Product::whereIn('id', $appliedProductIds)->take(3)->get();
                            @endphp
                            <div class="coupon-products">
                                @foreach ($products as $product)
                                    <div class="product-thumb">
                                        <img src="{{ get_file($product->cover_image_path) }}" alt="{{ $product->name }}">
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="coupon-desc">{{ __('Apply this coupon on your next order and save money') }}</p>
                        @endif

                        <div class="coupon-footer">
                            <div class="coupon-code-section">
                                <span class="code-label">{{ __('Use Code:') }}</span>
                                <span class="coupon-code">{{ $coupon->coupon_code }}</span>
                            </div>
                            <button class="copy-button cp_link" data-link="{{ $coupon->coupon_code }}">
                                <i class="fas fa-copy"></i> {{ __('Copy') }}
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if(count($coupons) == 0)
            <div class="no-coupons">
                <i class="fas fa-gift"></i>
                <h3>{{ __('No Coupons Available') }}</h3>
                <p>{{ __('Check back later for amazing deals!') }}</p>
            </div>
        @endif
    </div>
</div>
@endsection
@push('page-script')
<script>
$(document).ready(function() {
    // Copy coupon code with animation
    $('.cp_link').on('click', function(e) {
        e.preventDefault();
        
        const code = $(this).data('link');
        const button = $(this);
        const originalText = button.html();
        
        // Copy to clipboard
        navigator.clipboard.writeText(code).then(function() {
            // Success animation
            button.html('<i class="fas fa-check"></i> {{ __('Copied!') }}');
            button.addClass('copied');
            
            // Show success message
            if (typeof show_toastr === 'function') {
                show_toastr('Success', '{{ __('Coupon code copied!') }}', 'success');
            }
            
            // Reset button after 2 seconds
            setTimeout(function() {
                button.html(originalText);
                button.removeClass('copied');
            }, 2000);
            
        }).catch(function() {
            // Fallback for older browsers
            const tempInput = document.createElement('input');
            document.body.appendChild(tempInput);
            tempInput.value = code;
            tempInput.select();
            document.execCommand('copy');
            tempInput.remove();
            
            button.html('<i class="fas fa-check"></i> {{ __('Copied!') }}');
            setTimeout(function() {
                button.html(originalText);
            }, 2000);
        });
    });
    
    // Add hover effects to coupon cards
    $('.coupon-card').hover(
        function() {
            $(this).find('.coupon-badge').addClass('animate-pulse');
        },
        function() {
            $(this).find('.coupon-badge').removeClass('animate-pulse');
        }
    );
    
    // Animate cards on scroll
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };
    
    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);
    
    // Observe all coupon cards
    $('.coupon-card').each(function(index) {
        $(this).css({
            'opacity': '0',
            'transform': 'translateY(30px)',
            'transition': 'all 0.6s ease ' + (index * 0.1) + 's'
        });
        observer.observe(this);
    });
});
</script>
@endpush
